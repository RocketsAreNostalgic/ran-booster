<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\PreparedArchive;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Download and inspect one provider-prepared ZIP before package mutation.
 */
class DeploymentArchivePreflight {

	public const MAX_COMPRESSED_BYTES = 52428800;
	public const MAX_EXPANDED_BYTES   = 209715200;
	public const MAX_ENTRIES          = 10000;
	public const MAX_DEPTH            = 16;
	public const DOWNLOAD_TIMEOUT     = 120;
	public const MIN_CONFIGURED_BYTES = 1048576;
	public const MAX_CONFIGURED_BYTES = 536870912;

	private const EXPANDED_RATIO    = 4;
	private const DOWNLOAD_ATTEMPTS = 2;

	public function __construct( private ?int $configuredMaxBytes = null ) {
	}

	/** @return array{compressed:int,expanded:int,source:string} */
	public function effectiveLimits(): array {
		$compressed = $this->compressedLimit();

		return array(
			'compressed' => $compressed,
			'expanded'   => $compressed * self::EXPANDED_RATIO,
			'source'     => null === $this->configuredMaxBytes && ! defined( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' )
				? 'default'
				: 'configured',
		);
	}

	/** @return array{valid:bool,compressed:?int,expanded:?int,source:string} */
	public function configurationStatus(): array {
		try {
			return array( 'valid' => true ) + $this->effectiveLimits();
		} catch ( DeploymentArchiveLimitFailure ) {
			return array(
				'valid'      => false,
				'compressed' => null,
				'expanded'   => null,
				'source'     => 'configured',
			);
		}
	}

	public function prepare( DeploymentAttempt $attempt, PreparedArchive $archive, ?string $installedIdentifier = null ): PreparedArtifact {
		$artifact = null;
		$failure  = null;
		try {
			$artifact = $this->prepareArtifact( $attempt, $archive, $installedIdentifier );
		} catch ( Throwable $caught ) {
			$failure = $caught;
			BoosterLogger::logException( 'archive preflight failed', $caught, $attempt->logContext() + array( 'step' => 'preflight_failed' ) );
		}

		try {
			$archive->cleanup();
			BoosterLogger::log( 'provider archive cleanup completed', $attempt->logContext() + array( 'step' => 'provider_archive_cleanup_completed' ) );
		} catch ( Throwable $exception ) {
			$failure = $this->checkFailure( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED, 'Provider archive authentication could not be cleaned up safely.' );
			BoosterLogger::logException( 'provider archive cleanup failed', $exception, $attempt->logContext() + array( 'step' => 'provider_archive_cleanup_failed' ) );
		}

		if ( null !== $failure ) {
			if ( null !== $artifact ) {
				try {
					$artifact->cleanup();
				} catch ( Throwable ) {
					$this->fail( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED, 'The failed deployment archive could not be removed safely.' );
				}
			}
			throw $failure;
		}

		if ( null === $artifact ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED, 'The deployment archive could not be prepared safely.' );
		}

		return $artifact;
	}

	private function prepareArtifact( DeploymentAttempt $attempt, PreparedArchive $archive, ?string $installedIdentifier ): PreparedArtifact {
		$this->assertCurrentAttempt( $attempt, $installedIdentifier );
		BoosterLogger::log( 'preflight attempt snapshot verified', $attempt->logContext() + array( 'step' => 'preflight_attempt_verified' ) );
		$this->assertLocalReadiness( $attempt );
		BoosterLogger::log( 'preflight local readiness verified', $attempt->logContext() + array( 'step' => 'preflight_local_readiness_verified' ) );
		$this->assertInitialFreeSpace();
		BoosterLogger::log( 'preflight initial free space verified', $attempt->logContext() + array( 'step' => 'preflight_initial_free_space_verified' ) );
		$resolvedRef = $this->resolvedRef( $archive );
		$this->assertCurrentResolvedRef( $attempt, $resolvedRef );
		$created  = $this->createPrivateTemporaryFile();
		$path     = $created['path'];
		$identity = $created['identity'];

		try {
			$this->download( $archive, $path );
			$downloaded = PreparedArtifact::regularFileIdentity( $path );
			if ( null === $downloaded
				|| $identity['device'] !== $downloaded['device']
				|| $identity['inode'] !== $downloaded['inode'] ) {
				$this->fail( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED, 'The downloaded deployment archive identity changed unexpectedly.' );
			}
			$this->assertCompressedSize( $downloaded['size'] );
			BoosterLogger::log( 'preflight archive downloaded', $attempt->logContext() + array( 'step' => 'preflight_archive_downloaded' ) );

			$inspection = $this->inspectZip( $path, $attempt, $installedIdentifier );
			$this->assertFreeSpace( $attempt, $inspection['expanded'] );
			BoosterLogger::log( 'preflight zip structure verified', $attempt->logContext() + array( 'step' => 'preflight_zip_verified' ) );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- The digest binds the exact local archive to the durable attempt.
			$digest = hash_file( 'sha256', $path );
			if ( ! is_string( $digest ) || preg_match( '/^[a-f0-9]{64}$/D', $digest ) !== 1 ) {
				$this->fail( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED, 'RAN Booster could not fingerprint the deployment archive.' );
			}

			$artifact = new PreparedArtifact(
				$path,
				$resolvedRef,
				$inspection['expected_version'],
				$digest,
				$downloaded['device'],
				$downloaded['inode'],
				$downloaded['size'],
				$downloaded['permissions'],
				$downloaded['links']
			);
			$artifact->assertUnchanged();
			BoosterLogger::log(
				'preflight artifact digest verified',
				$attempt->logContext() + array(
					'step'         => 'preflight_artifact_digest_verified',
					'resolved_ref' => $resolvedRef,
				)
			);

			return $artifact;
		} catch ( Throwable $failure ) {
			$this->removeFailedTemporaryFile( $path, $identity );
			BoosterLogger::logException( 'preflight temporary file cleanup after failure', $failure, $attempt->logContext() + array( 'step' => 'preflight_temp_cleanup_after_failure' ) );
			throw $failure;
		}
	}

	private function assertCurrentResolvedRef( DeploymentAttempt $attempt, string $resolvedRef ): void {
		$data = $attempt->safeData();
		if ( 'webhook' === ( $data['source'] ?? null )
			&& ! hash_equals( (string) ( $data['requested_ref'] ?? '' ), $resolvedRef ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_REVISION_INVALID, 'The provider did not return the authenticated webhook revision.' );
		}
	}

	/**
	 * Prove the compact attempt carries every identity required for preflight.
	 * Lock ownership is checked immediately before this call by the coordinator
	 * and again before the mutation fence.
	 */
	private function assertCurrentAttempt( DeploymentAttempt $attempt, ?string $installedIdentifier ): void {
		$data    = $attempt->safeData();
		$request = $attempt->getRequest();
		if ( DeploymentState::RUNNING !== $attempt->getState()
			|| ! in_array( $data['operation'] ?? null, array( 'install', 'update' ), true )
			|| ! in_array( $data['package_type'] ?? null, array( 'plugin', 'theme' ), true )
			|| '' === $request->packageSlug
			|| ( 'install' === $data['operation'] && null !== $installedIdentifier )
			|| ( 'update' === $data['operation'] && ( null === $installedIdentifier || '' === $installedIdentifier ) ) ) {
			$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_SNAPSHOT_CHANGED, 'The deployment attempt is not eligible for archive preflight.' );
		}
		if ( null !== $installedIdentifier
			&& ( str_starts_with( $installedIdentifier, '/' )
				|| str_contains( $installedIdentifier, '\\' )
				|| preg_match( '#(^|/)\.\.?(/|$)#', $installedIdentifier ) === 1 ) ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_IDENTITY_MISMATCH, 'The installed package identity is unsafe.' );
		}
		if ( 'plugin' === $data['package_type']
			&& null !== $installedIdentifier
			&& ! str_contains( $installedIdentifier, '/' ) ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_SINGLE_FILE_UNSUPPORTED, 'Root-level single-file plugins cannot be updated safely.' );
		}
	}

	private function assertLocalReadiness( DeploymentAttempt $attempt ): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_ZIP_EXTENSION_MISSING, 'The PHP ext-zip platform requirement is unavailable; deployment archives cannot be inspected.' );
		}
		if ( is_multisite() ) {
			$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_MULTISITE_UNSUPPORTED, 'Archive deployment is supported only on a single-site WordPress installation.' );
		}
		if ( ! wp_is_file_mod_allowed( 'ran-booster' ) ) {
			$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_FILE_MODS_DISABLED, 'WordPress file modifications are disabled for this site.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() ) {
			$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_FILESYSTEM_UNSUPPORTED, 'RAN Booster beta requires the direct WordPress filesystem method.' );
		}

		$tempRoot    = $this->canonicalWritableDirectory( $this->temporaryRoot() );
		$contentRoot = defined( 'WP_CONTENT_DIR' )
			? $this->canonicalWritableDirectory( WP_CONTENT_DIR )
			: null;
		$destination = $this->canonicalWritableDirectory( $this->destinationRoot( $attempt ) );
		if ( null === $tempRoot || null === $contentRoot || null === $destination ) {
			$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_DIRECTORY_UNWRITABLE, 'The deployment temporary, upgrade or destination directory is not writable.' );
		}
	}

	private function assertInitialFreeSpace(): void {
		$available = disk_free_space( $this->temporaryRoot() );
		if ( false === $available || $available < $this->compressedLimit() ) {
			$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_DISK_SPACE_LOW, 'The deployment temporary directory does not have enough free space.' );
		}
	}

	/** @return array{path: string, identity: array{device: int, inode: int, size: int, permissions: int, links: int}} */
	private function createPrivateTemporaryFile(): array {
		$root = $this->canonicalWritableDirectory( $this->temporaryRoot() );
		if ( null === $root ) {
			$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_DIRECTORY_UNWRITABLE, 'The deployment temporary directory is not writable.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- tempnam atomically creates the private local streaming target.
		$path = tempnam( $root, 'ran-booster-' );
		if ( false === $path ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_TEMPORARY_FILE_FAILED, 'RAN Booster could not create a private archive file.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrictive mode is mandatory before the provider response is streamed.
		if ( ! chmod( $path, 0600 ) ) {
			$this->removeNewTemporaryFile( $path );
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_TEMPORARY_FILE_FAILED, 'RAN Booster could not secure the private archive file.' );
		}
		$identity = PreparedArtifact::regularFileIdentity( $path );
		if ( null === $identity || 0600 !== $identity['permissions'] || 1 !== $identity['links'] ) {
			$this->removeNewTemporaryFile( $path );
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_TEMPORARY_FILE_FAILED, 'RAN Booster could not secure the private archive file.' );
		}

		return array(
			'path'     => $path,
			'identity' => $identity,
		);
	}

	private function download( PreparedArchive $archive, string $path ): void {
		$url = $archive->getUrl();
		$this->assertSafeHttpsUrl( $url );
		for ( $attempt = 1; $attempt <= self::DOWNLOAD_ATTEMPTS; ++$attempt ) {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => self::DOWNLOAD_TIMEOUT,
					'redirection'         => 3,
					'reject_unsafe_urls'  => true,
					'stream'              => true,
					'filename'            => $path,
					'limit_response_size' => $this->compressedLimit() + 1,
				)
			);
			if ( is_wp_error( $response ) ) {
				// WordPress reports both network failures and local streamed-file write
				// failures as http_request_failed. Retrying that undifferentiated error
				// could repeat a persistent local failure.
				$this->fail( DeploymentOutcome::CODE_ARCHIVE_DOWNLOAD_FAILED, 'The deployment archive could not be downloaded.' );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 429 === $status || in_array( $status, array( 502, 503, 504 ), true ) ) {
				if ( $attempt < self::DOWNLOAD_ATTEMPTS ) {
					continue;
				}
				$this->providerStatusFailure( $status, 'The deployment archive is temporarily unavailable.' );
			}
			if ( $status < 200 || $status >= 300 ) {
				$this->providerStatusFailure( $status, 'The provider returned an unsuccessful archive response.' );
			}

			return;
		}
	}

	private function assertSafeHttpsUrl( mixed $url ): void {
		if ( ! is_string( $url ) || '' === $url || trim( $url ) !== $url ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_URL_INVALID, 'The provider prepared an invalid archive URL.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This is the fixed provider-download trust boundary.
		$parts = parse_url( $url );
		if ( false === filter_var( $url, FILTER_VALIDATE_URL )
			|| ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| '' === (string) ( $parts['host'] ?? '' )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['fragment'] ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_URL_INVALID, 'The provider prepared an invalid archive URL.' );
		}
	}

	private function resolvedRef( PreparedArchive $archive ): string {
		if ( ! method_exists( $archive, 'getResolvedRef' ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_REVISION_INVALID, 'The provider did not prove an immutable archive ref.' );
		}
		$ref = $archive->getResolvedRef();
		if ( ! is_string( $ref )
			|| '' === $ref
			|| $ref !== trim( $ref )
			|| strlen( $ref ) > 191
			|| preg_match( '/[[:cntrl:]]/', $ref ) === 1 ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_REVISION_INVALID, 'The provider returned an invalid immutable archive ref.' );
		}

		return $ref;
	}

	/** @return array{expanded: int, expected_version: string} */
	private function inspectZip( string $path, DeploymentAttempt $attempt, ?string $installedIdentifier ): array {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_ZIP_INVALID, 'The deployment archive is not a readable ZIP file.' );
		}

		try {
			$this->assertEntryCount( $zip->numFiles );

			$entries  = array();
			$root     = null;
			$expanded = 0;
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
				if ( false === $stat || ! is_string( $stat['name'] ?? null ) || ! is_int( $stat['size'] ?? null ) || $stat['size'] < 0 || ! is_int( $stat['crc'] ?? null ) ) {
					$this->fail( DeploymentOutcome::CODE_ARCHIVE_ENTRY_INVALID, 'The deployment archive contains invalid entry metadata.' );
				}
				$name       = $stat['name'];
				$normalized = $this->validateEntryName( $name );
				if ( isset( $entries[ $normalized ] ) ) {
					$this->fail( DeploymentOutcome::CODE_ARCHIVE_PATH_COLLISION, 'The deployment archive contains duplicate paths.' );
				}
				$entries[ $normalized ] = array(
					'index'     => $index,
					'directory' => str_ends_with( $name, '/' ),
				);

				$entryRoot = explode( '/', $normalized, 2 )[0];
				$root    ??= $entryRoot;
				if ( ! hash_equals( $root, $entryRoot ) ) {
					$this->fail( DeploymentOutcome::CODE_ARCHIVE_LAYOUT_INVALID, 'The deployment archive must contain one package root.' );
				}

				$this->assertSafeEntryType( $zip, $index, $stat, str_ends_with( $name, '/' ) );
				if ( ! str_ends_with( $name, '/' ) ) {
					$this->verifyEntryContents( $zip, $index, $stat['size'], $stat['crc'] );
				}
				$expanded = $this->addExpandedBytes( $expanded, $stat['size'] );
			}

			if ( null === $root ) {
				$this->fail( DeploymentOutcome::CODE_ARCHIVE_LAYOUT_INVALID, 'The deployment archive does not contain a package.' );
			}
			$this->assertNoPathCollisions( $entries );
			$expectedVersion = $this->assertPackageIdentity( $zip, $attempt, $entries, $root, $installedIdentifier );

			return array(
				'expanded'         => $expanded,
				'expected_version' => $expectedVersion,
			);
		} finally {
			$zip->close();
		}
	}

	private function validateEntryName( string $name ): string {
		if ( '' === $name
			|| strlen( $name ) > 1024
			|| preg_match( '//u', $name ) !== 1
			|| preg_match( '/[[:cntrl:]]/', $name ) === 1
			|| str_contains( $name, '\\' )
			|| str_starts_with( $name, '/' )
			|| preg_match( '/^[A-Za-z]:/', $name ) === 1
			|| str_contains( $name, '//' ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_PATH_UNSAFE, 'The deployment archive contains an unsafe path.' );
		}

		$normalized = rtrim( $name, '/' );
		$segments   = explode( '/', $normalized );
		if ( '' === $normalized
			|| count( $segments ) > self::MAX_DEPTH
			|| in_array( '', $segments, true )
			|| in_array( '.', $segments, true )
			|| in_array( '..', $segments, true ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_PATH_UNSAFE, 'The deployment archive contains an unsafe path.' );
		}
		foreach ( $segments as $segment ) {
			if ( strlen( $segment ) > 255 || preg_match( '/[^\x20-\x7E]/', $segment ) === 1 ) {
				$this->fail( DeploymentOutcome::CODE_ARCHIVE_PATH_UNSAFE, 'The deployment archive contains an unsafe path.' );
			}
			$stem = strtoupper( explode( '.', $segment, 2 )[0] );
			if ( str_contains( $segment, ':' )
				|| preg_match( '/[ .]$/D', $segment ) === 1
				|| preg_match( '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/D', $stem ) === 1 ) {
				$this->fail( DeploymentOutcome::CODE_ARCHIVE_PATH_UNSAFE, 'The deployment archive contains an unsafe path.' );
			}
		}

		return implode( '/', $segments );
	}

	/** @param array<string, array{index: int, directory: bool}> $entries */
	private function assertNoPathCollisions( array $entries ): void {
		$caseFolded = array();
		foreach ( $entries as $path => $entry ) {
			$folded = strtolower( $path );
			if ( isset( $caseFolded[ $folded ] ) ) {
				$this->fail( DeploymentOutcome::CODE_ARCHIVE_PATH_COLLISION, 'The deployment archive contains duplicate paths.' );
			}
			$caseFolded[ $folded ] = $entry;
		}

		foreach ( $entries as $path => $entry ) {
			$segments = explode( '/', $path );
			array_pop( $segments );
			$ancestor = '';
			foreach ( $segments as $segment ) {
				$ancestor = '' === $ancestor ? $segment : $ancestor . '/' . $segment;
				$key      = strtolower( $ancestor );
				if ( isset( $caseFolded[ $key ] ) && ! $caseFolded[ $key ]['directory'] ) {
					$this->fail( DeploymentOutcome::CODE_ARCHIVE_PATH_COLLISION, 'The deployment archive contains a file-parent path collision.' );
				}
			}
		}
	}

	private function verifyEntryContents( ZipArchive $zip, int $index, int $expectedSize, int $expectedCrc ): void {
		$stream = $zip->getStreamIndex( $index, ZipArchive::FL_UNCHANGED );
		if ( false === $stream ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED, 'The deployment archive contains unreadable entry data.' );
		}
		$read = 0;
		$hash = hash_init( 'crc32b' );
		try {
			while ( ! feof( $stream ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming every ZIP entry is required for bounded CRC verification.
				$chunk = fread( $stream, 65536 );
				if ( false === $chunk ) {
					$this->fail( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED, 'The deployment archive contains unreadable entry data.' );
				}
				if ( '' === $chunk ) {
					if ( ! feof( $stream ) ) {
						$this->fail( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED, 'The deployment archive contains unreadable entry data.' );
					}
					break;
				}
				$read = $this->addExpandedBytes( $read, strlen( $chunk ) );
				hash_update( $hash, $chunk );
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the bounded ZipArchive entry stream.
			fclose( $stream );
		}
		$crc = hash_final( $hash );
		if ( $read !== $expectedSize || ! hash_equals( sprintf( '%08x', $expectedCrc ), $crc ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED, 'The deployment archive contains unreadable entry data.' );
		}
	}

	private function assertCompressedSize( int $size ): void {
		if ( $size <= 0 || $size > $this->compressedLimit() ) {
			throw DeploymentArchiveLimitFailure::compressed();
		}
	}

	private function assertEntryCount( int $entries ): void {
		if ( $entries < 1 || $entries > self::MAX_ENTRIES ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_ENTRY_LIMIT, 'The deployment archive exceeds the entry-count limit.' );
		}
	}

	private function addExpandedBytes( int $current, int $entry ): int {
		$limit = $this->expandedLimit();
		if ( $current < 0 || $entry < 0 || $current > $limit - $entry ) {
			throw DeploymentArchiveLimitFailure::expanded();
		}

		return $current + $entry;
	}

	private function compressedLimit(): int {
		$value = $this->configuredMaxBytes;
		if ( null === $value && defined( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' ) ) {
			$value = constant( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' );
		}
		$value ??= self::MAX_COMPRESSED_BYTES;

		if ( ! is_int( $value ) || $value < self::MIN_CONFIGURED_BYTES || $value > self::MAX_CONFIGURED_BYTES ) {
			throw DeploymentArchiveLimitFailure::configuration();
		}

		return $value;
	}

	private function expandedLimit(): int {
		return $this->compressedLimit() * self::EXPANDED_RATIO;
	}

	/** @param array<string, mixed> $stat */
	private function assertSafeEntryType( ZipArchive $zip, int $index, array $stat, bool $namedDirectory ): void {
		$encryption = (int) ( $stat['encryption_method'] ?? ZipArchive::EM_NONE );
		if ( ZipArchive::EM_NONE !== $encryption ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_ENCRYPTED, 'Encrypted deployment archive entries are not supported.' );
		}

		$operations = 0;
		$attributes = 0;
		if ( ! $zip->getExternalAttributesIndex( $index, $operations, $attributes, ZipArchive::FL_UNCHANGED ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_ENTRY_INVALID, 'The deployment archive contains invalid entry metadata.' );
		}
		$type = ( $attributes >> 16 ) & 0170000;
		if ( ! in_array( $type, array( 0, 0040000, 0100000 ), true ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_ENTRY_UNSUPPORTED, 'The deployment archive contains a link or device entry.' );
		}
		if ( ( 0040000 === $type && ! $namedDirectory ) || ( 0100000 === $type && $namedDirectory ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_ENTRY_INVALID, 'The deployment archive contains invalid entry metadata.' );
		}
	}

	/**
	 * @param array<string, array{index: int, directory: bool}> $entries
	 */
	private function assertPackageIdentity(
		ZipArchive $zip,
		DeploymentAttempt $attempt,
		array $entries,
		string $root,
		?string $installedIdentifier = null
	): string {
		$this->assertCurrentPackageIdentity( $attempt, $installedIdentifier );
		$data         = $attempt->safeData();
		$request      = $attempt->getRequest();
		$packageSlug  = $request->packageSlug;
		$subdirectory = $request->subdirectory;
		$prefix       = $root . ( is_string( $subdirectory ) && '' !== $subdirectory ? '/' . $subdirectory : '' );
		$prefix       = $this->validateEntryName( $prefix );
		$packageFiles = array_filter(
			$entries,
			static fn ( array $entry, string $name ): bool => ! $entry['directory'] && str_starts_with( $name, $prefix . '/' ),
			ARRAY_FILTER_USE_BOTH
		);
		if ( array() === $packageFiles ) {
			$this->fail(
				is_string( $subdirectory ) && '' !== $subdirectory
					? DeploymentOutcome::CODE_PACKAGE_SUBDIRECTORY_MISSING
					: DeploymentOutcome::CODE_ARCHIVE_LAYOUT_INVALID,
				'The configured package directory is missing from the archive.'
			);
		}

		$type = (string) $data['package_type'];
		if ( 'theme' === $type ) {
			$stylesheet = $packageFiles[ $prefix . '/style.css' ] ?? null;
			if ( null === $stylesheet ) {
				$this->fail( DeploymentOutcome::CODE_PACKAGE_THEME_MISSING, 'The archive does not contain the expected WordPress theme.' );
			}
			$headers = $this->readHeaders( $zip, $stylesheet['index'], 'Theme Name' );
			$this->assertCompatibility( $headers );

			return $this->expectedPackageVersion( $headers );
		}

		$mainFile   = null === $installedIdentifier ? null : basename( $installedIdentifier );
		$candidates = array();
		foreach ( $packageFiles as $name => $entry ) {
			$relative = substr( $name, strlen( $prefix ) + 1 );
			if ( ! str_contains( $relative, '/' ) && str_ends_with( strtolower( $relative ), '.php' ) ) {
				$headers = $this->readHeaders( $zip, $entry['index'], 'Plugin Name', false );
				if ( null !== $headers ) {
					$candidates[ $relative ] = $headers;
				}
			}
		}
		if ( 0 === count( $candidates ) ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_PLUGIN_MISSING, 'The archive does not contain the expected WordPress plugin.' );
		}
		if ( 1 < count( $candidates ) ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_MULTIPLE_PLUGINS, 'The archive must contain exactly one top-level WordPress plugin.' );
		}
		if ( null !== $mainFile && ! isset( $candidates[ $mainFile ] ) ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_PLUGIN_MISSING, 'The archive does not contain the expected WordPress plugin.' );
		}
		$headers = reset( $candidates );
		$this->assertCompatibility( $headers );

		return $this->expectedPackageVersion( $headers );
	}

	private function assertCurrentPackageIdentity( DeploymentAttempt $attempt, ?string $installedIdentifier ): void {
		$data    = $attempt->safeData();
		$request = $attempt->getRequest();
		if ( 'theme' === ( $data['package_type'] ?? null )
			&& null !== $installedIdentifier
			&& ! hash_equals( $request->packageSlug, $installedIdentifier ) ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_IDENTITY_MISMATCH, 'The archive package basename does not match the managed theme.' );
		}
		if ( 'plugin' === ( $data['package_type'] ?? null ) && null !== $installedIdentifier ) {
			$directory = basename( dirname( $installedIdentifier ) );
			if ( ! hash_equals( $request->packageSlug, $directory ) ) {
				$this->fail( DeploymentOutcome::CODE_PACKAGE_IDENTITY_MISMATCH, 'The archive package basename does not match the managed plugin.' );
			}
		}
	}

	/**
	 * @return array<string, string>|null
	 */
	private function readHeaders( ZipArchive $zip, int $index, string $required, bool $requiredFile = true ): ?array {
		$contents = $zip->getFromIndex( $index, 8192, ZipArchive::FL_UNCHANGED );
		if ( false === $contents ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_HEADER_UNREADABLE, 'The deployment package header could not be inspected.' );
		}
		$headers = array();
		foreach ( array( $required, 'Version', 'Requires at least', 'Requires PHP' ) as $header ) {
			$pattern = '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':[ \t]*(.+?)\s*$/mi';
			$value   = preg_match( $pattern, $contents, $match ) === 1 ? trim( $match[1] ) : '';
			if ( strlen( $value ) > 64 || preg_match( '/[[:cntrl:]]/', $value ) === 1 ) {
				if ( 'Version' === $header ) {
					$this->fail( DeploymentOutcome::CODE_PACKAGE_VERSION_INVALID, 'The deployment package contains an invalid Version header.' );
				}
				if ( $required === $header ) {
					$this->fail( DeploymentOutcome::CODE_PACKAGE_HEADER_UNREADABLE, 'The deployment package header could not be inspected.' );
				}
				$this->fail( DeploymentOutcome::CODE_PACKAGE_COMPATIBILITY_INVALID, 'The deployment package contains an invalid compatibility header.' );
			}
			$headers[ $header ] = $value;
		}
		if ( '' === $headers[ $required ] ) {
			if ( $requiredFile ) {
				$this->fail( DeploymentOutcome::CODE_PACKAGE_HEADER_MISSING, 'The deployment archive does not contain the expected package header.' );
			}

			return null;
		}

		return $headers;
	}

	/** @param array<string, string> $headers */
	private function expectedPackageVersion( array $headers ): string {
		$version = $headers['Version'] ?? '';
		if ( '' === $version ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_VERSION_MISSING, 'The deployment package does not contain a Version header.' );
		}
		if ( preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $version ) !== 1 ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_VERSION_INVALID, 'The deployment package contains an invalid Version header.' );
		}

		return $version;
	}

	/** @param array<string, string> $headers */
	private function assertCompatibility( array $headers ): void {
		$requiresPhp = $headers['Requires PHP'];
		$requiresWp  = $headers['Requires at least'];
		foreach ( array( $requiresPhp, $requiresWp ) as $version ) {
			if ( '' !== $version && preg_match( '/^[0-9]+(?:\.[0-9]+){0,3}(?:[-+._][A-Za-z0-9.-]+)?$/D', $version ) !== 1 ) {
				$this->fail( DeploymentOutcome::CODE_PACKAGE_COMPATIBILITY_INVALID, 'The deployment package contains an invalid compatibility header.' );
			}
		}
		if ( '' !== $requiresPhp && version_compare( PHP_VERSION, $requiresPhp, '<' ) ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_REQUIRES_NEWER_PHP, 'The deployment package requires a newer PHP version.' );
		}
		$wpVersion = (string) get_bloginfo( 'version' );
		if ( '' !== $requiresWp && ( '' === $wpVersion || version_compare( $wpVersion, $requiresWp, '<' ) ) ) {
			$this->fail( DeploymentOutcome::CODE_PACKAGE_REQUIRES_NEWER_WORDPRESS, 'The deployment package requires a newer WordPress version.' );
		}
	}

	private function assertFreeSpace( DeploymentAttempt $attempt, int $expanded ): void {
		$required             = intdiv( ( $expanded * 21 ) + 9, 10 );
		$upgradeAvailable     = defined( 'WP_CONTENT_DIR' ) ? disk_free_space( WP_CONTENT_DIR ) : false;
		$destinationAvailable = disk_free_space( $this->destinationRoot( $attempt ) );
		if ( false === $upgradeAvailable
			|| false === $destinationAvailable
			|| $upgradeAvailable < $required
			|| $destinationAvailable < $required ) {
			$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_DISK_SPACE_LOW, 'The deployment filesystem does not have enough free space.' );
		}
	}

	private function temporaryRoot(): string {
		return get_temp_dir();
	}

	private function destinationRoot( DeploymentAttempt $attempt ): string {
		$type = $attempt->safeData()['package_type'] ?? null;
		if ( 'plugin' === $type ) {
			if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
				$this->fail( DeploymentOutcome::CODE_DEPLOYMENT_DIRECTORY_UNWRITABLE, 'The WordPress plugin directory is unavailable.' );
			}

			return WP_PLUGIN_DIR;
		}

		return (string) get_theme_root();
	}

	private function canonicalWritableDirectory( string $path ): ?string {
		$canonical = realpath( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct filesystem readiness is the explicit beta boundary.
		return false !== $canonical && is_dir( $canonical ) && is_writable( $canonical ) ? $canonical : null;
	}

	private function removeNewTemporaryFile( string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The path was returned directly by tempnam in this call.
		if ( ! unlink( $path ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED, 'The failed deployment archive could not be removed safely.' );
		}
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED, 'The failed deployment archive could not be removed safely.' );
		}
	}

	/** @param array{device: int, inode: int, size: int, permissions: int, links: int} $created */
	private function removeFailedTemporaryFile( string $path, array $created ): void {
		$current = PreparedArtifact::regularFileIdentity( $path );
		if ( null === $current
			|| $current['device'] !== $created['device']
			|| $current['inode'] !== $created['inode'] ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED, 'The failed deployment archive could not be removed safely.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- This removes the exact exclusive temporary file created by this preflight.
		if ( ! unlink( $path ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED, 'The failed deployment archive could not be removed safely.' );
		}
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			$this->fail( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED, 'The failed deployment archive could not be removed safely.' );
		}
	}

	private function fail( string $code, string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A validated closed code and static internal message are not output.
		throw new DeploymentCheckFailure( $code, $message );
	}

	private function checkFailure( string $code, string $message ): DeploymentCheckFailure {
		return new DeploymentCheckFailure( $code, $message );
	}

	private function providerStatusFailure( int $status, string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The normalized provider status and static message are not output.
		throw DeploymentCheckFailure::providerStatus( $status, $message );
	}
}
