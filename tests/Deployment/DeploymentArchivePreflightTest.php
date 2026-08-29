<?php

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests deliberately create and corrupt local fixture files.

namespace Tests\Deployment;

require_once __DIR__ . '/DeploymentArchivePreflightWordPressState.php';
require_once __DIR__ . '/DeploymentArchivePreflightTestEnvironment.php';
require_once __DIR__ . '/PreflightPreparedArchive.php';
require_once __DIR__ . '/DeploymentArchivePreflightWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentArchivePreflight;
use RAN\Deployment\DeploymentArchiveLimitFailure;
use RAN\Deployment\DeploymentCheckFailure;
use RAN\Deployment\DeploymentArchivePreflightWordPressState;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\PreparedArtifact;
use ReflectionMethod;
use RuntimeException;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', DeploymentArchivePreflightTestEnvironment::pluginRoot() );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', DeploymentArchivePreflightTestEnvironment::upgradeRoot() );
}

final class DeploymentArchivePreflightTest extends TestCase {

	private const VALID_PLUGIN = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/";

	/** @var list<string> */
	private array $sources = array();

	protected function setUp(): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			self::markTestSkipped( 'ZipArchive is required for archive preflight tests.' );
		}
		DeploymentArchivePreflightWordPressState::reset();
		foreach ( array( DeploymentArchivePreflightTestEnvironment::temporaryRoot(), DeploymentArchivePreflightTestEnvironment::upgradeRoot(), DeploymentArchivePreflightTestEnvironment::pluginRoot(), DeploymentArchivePreflightTestEnvironment::themeRoot() ) as $directory ) {
			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0700, true );
			}
		}
	}

	protected function tearDown(): void {
		foreach ( $this->sources as $source ) {
			if ( file_exists( $source ) || is_link( $source ) ) {
				unlink( $source );
			}
		}
		$temporaryFiles = glob( DeploymentArchivePreflightTestEnvironment::temporaryRoot() . '/ran-booster-*' );
		foreach ( false === $temporaryFiles ? array() : $temporaryFiles as $temporary ) {
			if ( is_file( $temporary ) || is_link( $temporary ) ) {
				unlink( $temporary );
			}
		}
	}

	public function testStreamsOneIdentityBoundArtifactAndCleansAuthentication(): void {
		$source  = $this->zip( array( 'bundle/example.php' => self::VALID_PLUGIN ) );
		$archive = new PreflightPreparedArchive();

		$artifact = ( new DeploymentArchivePreflight() )->prepare( $this->attempt(), $archive, 'example/example.php' );

		self::assertSame( 1, DeploymentArchivePreflightWordPressState::$requests );
		self::assertSame( DeploymentArchivePreflight::DOWNLOAD_TIMEOUT, DeploymentArchivePreflightWordPressState::$arguments['timeout'] );
		self::assertSame( str_repeat( 'a', 40 ), $artifact->getResolvedRef() );
		self::assertSame( '1.2.3', $artifact->getExpectedVersion() );
		self::assertSame( 0600, fileperms( $artifact->getPath() ) & 0777 );
		self::assertSame( 1, $archive->cleanupCalls );
		$artifact->assertUnchanged();
		$artifactSize = (int) filesize( $artifact->getPath() );
		self::assertGreaterThan( 0, $artifactSize );
		self::assertSame( $artifactSize, file_put_contents( $artifact->getPath(), str_repeat( 'x', $artifactSize ) ) );
		$this->expectExceptionMessage( 'changed before use' );
		$artifact->assertUnchanged();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPreflightLoadsTheWordPressFilesystemApiOutsideAdmin(): void {
		$this->zip( array( 'bundle/example.php' => self::VALID_PLUGIN ) );
		self::assertArrayNotHasKey( 'ran_booster_wordpress_file_api_loads', $GLOBALS );
		$archive = new PreflightPreparedArchive();

		$artifact = ( new DeploymentArchivePreflight() )->prepare( $this->attempt(), $archive, 'example/example.php' );

		self::assertSame( 1, $GLOBALS['ran_booster_wordpress_file_api_loads'] ?? null );
		self::assertSame( 1, DeploymentArchivePreflightWordPressState::$requests );
		self::assertSame( 1, $archive->cleanupCalls );
		$artifact->cleanup();
	}

	#[DataProvider( 'transientArchiveStatuses' )]
	public function testTransientArchiveResponseReceivesOneBoundedReattempt( int $status ): void {
		$this->zip( array( 'bundle/example.php' => self::VALID_PLUGIN ) );
		DeploymentArchivePreflightWordPressState::$responses = array(
			array( 'status' => $status ),
			array( 'status' => 200 ),
		);
		$archive = new PreflightPreparedArchive();

		$artifact = ( new DeploymentArchivePreflight() )->prepare( $this->attempt(), $archive, 'example/example.php' );

		self::assertSame( 2, DeploymentArchivePreflightWordPressState::$requests );
		self::assertSame( 1, $archive->cleanupCalls );
		$artifact->assertUnchanged();
		$artifact->cleanup();
	}

	public static function transientArchiveStatuses(): iterable {
		yield 'rate limited' => array( 429 );
		yield 'bad gateway' => array( 502 );
		yield 'service unavailable' => array( 503 );
		yield 'gateway timeout' => array( 504 );
	}

	public function testSecondTransientArchiveResponseFailsWithoutRequeuing(): void {
		$this->zip( array( 'bundle/example.php' => self::VALID_PLUGIN ) );
		DeploymentArchivePreflightWordPressState::$responses = array(
			array( 'status' => 503 ),
			array( 'status' => 504 ),
		);
		$archive = new PreflightPreparedArchive();

		try {
			( new DeploymentArchivePreflight() )->prepare( $this->attempt(), $archive, 'example/example.php' );
			self::fail( 'A second transient archive response must fail the attempt.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The deployment archive is temporarily unavailable.', $failure->getMessage() );
		}
		self::assertSame( 2, DeploymentArchivePreflightWordPressState::$requests );
		self::assertSame( 1, $archive->cleanupCalls );
		$this->assertNoPreparedArtifactsRemain();
	}

	#[DataProvider( 'terminalArchiveFailures' )]
	public function testTerminalArchiveFailureIsNotRetried( bool $wpError, int $status, string $message, string $code ): void {
		$this->zip( array( 'bundle/example.php' => self::VALID_PLUGIN ) );
		DeploymentArchivePreflightWordPressState::$responses = array(
			array(
				'wp_error' => $wpError,
				'status'   => $status,
			),
			array( 'status' => 200 ),
		);
		$archive = new PreflightPreparedArchive();

		try {
			( new DeploymentArchivePreflight() )->prepare( $this->attempt(), $archive, 'example/example.php' );
			self::fail( 'A terminal archive failure must fail the attempt.' );
		} catch ( DeploymentCheckFailure $failure ) {
			self::assertSame( $message, $failure->getMessage() );
			self::assertSame( $code, $failure->outcomeCode );
		}
		self::assertSame( 1, DeploymentArchivePreflightWordPressState::$requests );
		self::assertSame( 1, $archive->cleanupCalls );
		$this->assertNoPreparedArtifactsRemain();
	}

	public static function terminalArchiveFailures(): iterable {
		yield 'stream or network failure' => array( true, 200, 'The deployment archive could not be downloaded.', DeploymentOutcome::CODE_ARCHIVE_DOWNLOAD_FAILED );
		yield 'not found' => array( false, 404, 'The provider returned an unsuccessful archive response.', DeploymentOutcome::CODE_PROVIDER_REPOSITORY_MISSING );
		yield 'internal server error' => array( false, 500, 'The provider returned an unsuccessful archive response.', DeploymentOutcome::CODE_PROVIDER_FAILED );
	}

	public function testDefaultAndConfiguredLimitsPreserveTheExpandedSafetyRatio(): void {
		self::assertSame(
			array(
				'compressed' => DeploymentArchivePreflight::MAX_COMPRESSED_BYTES,
				'expanded'   => DeploymentArchivePreflight::MAX_EXPANDED_BYTES,
				'source'     => 'default',
			),
			( new DeploymentArchivePreflight() )->effectiveLimits()
		);

		$configured = new DeploymentArchivePreflight( 100 * 1048576 );
		self::assertSame(
			array(
				'compressed' => 100 * 1048576,
				'expanded'   => 400 * 1048576,
				'source'     => 'configured',
			),
			$configured->effectiveLimits()
		);
		self::assertSame(
			array(
				'valid'      => true,
				'compressed' => 100 * 1048576,
				'expanded'   => 400 * 1048576,
				'source'     => 'configured',
			),
			$configured->configurationStatus()
		);
		self::assertSame(
			array(
				'valid'      => false,
				'compressed' => null,
				'expanded'   => null,
				'source'     => 'configured',
			),
			( new DeploymentArchivePreflight( 0 ) )->configurationStatus()
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testWpConfigConstantSetsTheSiteArchiveLimit(): void {
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 125 * 1048576 );

		self::assertSame(
			array(
				'compressed' => 125 * 1048576,
				'expanded'   => 500 * 1048576,
				'source'     => 'configured',
			),
			( new DeploymentArchivePreflight() )->effectiveLimits()
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testInvalidWpConfigConstantIsReportedWithoutBreakingDocumentation(): void {
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', '125M' );

		self::assertSame(
			array(
				'valid'      => false,
				'compressed' => null,
				'expanded'   => null,
				'source'     => 'configured',
			),
			( new DeploymentArchivePreflight() )->configurationStatus()
		);
	}

	public function testConfiguredLimitControlsTheBoundedStreamRequest(): void {
		$this->zip( array( 'bundle/example.php' => self::VALID_PLUGIN ) );
		$limit    = 2 * 1048576;
		$artifact = ( new DeploymentArchivePreflight( $limit ) )->prepare( $this->attempt(), new PreflightPreparedArchive(), 'example/example.php' );

		self::assertSame( $limit + 1, DeploymentArchivePreflightWordPressState::$arguments['limit_response_size'] );
		$artifact->cleanup();
	}

	public function testInvalidLimitConfigurationFailsBeforeDownload(): void {
		$archive = new PreflightPreparedArchive();

		try {
			( new DeploymentArchivePreflight( 0 ) )->prepare( $this->attempt(), $archive, 'example/example.php' );
			self::fail( 'Invalid archive limits must fail closed.' );
		} catch ( DeploymentArchiveLimitFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_ARCHIVE_LIMIT_INVALID, $failure->outcomeCode );
		}
		self::assertSame( 0, DeploymentArchivePreflightWordPressState::$requests );
		self::assertSame( 1, $archive->cleanupCalls );
	}

	public function testConfiguredCompressedAndExpandedLimitsHaveDistinctFailures(): void {
		$preflight = new DeploymentArchivePreflight( 1048576 );

		foreach ( array(
			array( 'assertCompressedSize', array( 1048577 ), DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE ),
			array( 'addExpandedBytes', array( 4 * 1048576, 1 ), DeploymentOutcome::CODE_ARCHIVE_EXPANDED_TOO_LARGE ),
		) as [$method, $arguments, $outcomeCode] ) {
			try {
				( new ReflectionMethod( $preflight, $method ) )->invoke( $preflight, ...$arguments );
				self::fail( 'Expected the configured archive limit to be enforced.' );
			} catch ( DeploymentArchiveLimitFailure $failure ) {
				self::assertSame( $outcomeCode, $failure->outcomeCode );
			}
		}
	}

	public function testWebhookResolvedRefMustEqualAuthenticatedCommit(): void {
		$this->zip( array( 'bundle/example.php' => self::VALID_PLUGIN ) );
		$archive = new PreflightPreparedArchive( str_repeat( 'b', 40 ) );

		try {
			( new DeploymentArchivePreflight() )->prepare( $this->attempt( source: 'webhook', requestedRef: str_repeat( 'a', 40 ) ), $archive, 'example/example.php' );
			self::fail( 'A webhook archive must match the authenticated commit.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The provider did not return the authenticated webhook revision.', $failure->getMessage() );
		}
		self::assertSame( 1, $archive->cleanupCalls );
		$this->assertNoPreparedArtifactsRemain();
	}

	public function testConfiguredSubdirectoryAndThemeIdentityAreValidated(): void {
		$this->zip( array( 'bundle/packages/example-theme/style.css' => "/*\nTheme Name: Example theme\nVersion: 4.5.6\n*/" ) );

		$artifact = ( new DeploymentArchivePreflight() )->prepare(
			$this->attempt( type: 'theme', slug: 'example-theme', subdirectory: 'packages/example-theme' ),
			new PreflightPreparedArchive(),
			'example-theme'
		);
		self::assertSame( '4.5.6', $artifact->getExpectedVersion() );
		$artifact->cleanup();
	}

	#[DataProvider( 'pluginCandidateFailures' )]
	public function testClassifiesZeroAndMultiplePluginCandidates( array $entries, string $code ): void {
		$this->zip( $entries );

		try {
			( new DeploymentArchivePreflight() )->prepare( $this->attempt(), new PreflightPreparedArchive(), 'example/example.php' );
			self::fail( 'Plugin candidates must be classified.' );
		} catch ( DeploymentCheckFailure $failure ) {
			self::assertSame( $code, $failure->outcomeCode );
		}
	}

	/** @return iterable<string, array{array<string,string>,string}> */
	public static function pluginCandidateFailures(): iterable {
		yield 'zero candidates' => array( array( 'bundle/example.php' => '<?php' ), DeploymentOutcome::CODE_PACKAGE_PLUGIN_MISSING );
		yield 'multiple candidates' => array(
			array(
				'bundle/one.php' => "<?php\n/*\nPlugin Name: One\nVersion: 1.0.0\n*/",
				'bundle/two.php' => "<?php\n/*\nPlugin Name: Two\nVersion: 1.0.0\n*/",
			),
			DeploymentOutcome::CODE_PACKAGE_MULTIPLE_PLUGINS,
		);
	}

	#[DataProvider( 'versionHeaderFailures' )]
	public function testClassifiesMissingAndInvalidPackageVersionHeaders( string $contents, string $code ): void {
		$this->zip( array( 'bundle/example.php' => $contents ) );

		try {
			( new DeploymentArchivePreflight() )->prepare( $this->attempt(), new PreflightPreparedArchive(), 'example/example.php' );
			self::fail( 'The Version header must be classified.' );
		} catch ( DeploymentCheckFailure $failure ) {
			self::assertSame( $code, $failure->outcomeCode );
		}
		$this->assertNoPreparedArtifactsRemain();
	}

	/** @return iterable<string, array{string,string}> */
	public static function versionHeaderFailures(): iterable {
		yield 'missing Version' => array( "<?php\n/*\nPlugin Name: Example\n*/", DeploymentOutcome::CODE_PACKAGE_VERSION_MISSING );
		yield 'invalid Version' => array( "<?php\n/*\nPlugin Name: Example\nVersion: not allowed!\n*/", DeploymentOutcome::CODE_PACKAGE_VERSION_INVALID );
		yield 'unsafe Version' => array( "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\x01\n*/", DeploymentOutcome::CODE_PACKAGE_VERSION_INVALID );
	}

	#[DataProvider( 'unsafeArchives' )]
	public function testRejectsHostilePathsAndPackageShapes( array $entries, string $message ): void {
		$this->zip( $entries );
		$archive = new PreflightPreparedArchive();

		try {
			( new DeploymentArchivePreflight() )->prepare( $this->attempt(), $archive, 'example/example.php' );
			self::fail( 'Expected unsafe archive rejection.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringContainsString( $message, $failure->getMessage() );
		}
		self::assertSame( 1, $archive->cleanupCalls );
		$this->assertNoPreparedArtifactsRemain();
	}

	public static function unsafeArchives(): iterable {
		yield 'traversal' => array(
			array(
				'bundle/example.php'   => self::VALID_PLUGIN,
				'bundle/../escape.php' => 'escape',
			),
			'unsafe path',
		);
		yield 'backslash' => array(
			array(
				'bundle/example.php' => self::VALID_PLUGIN,
				'bundle\\escape.php' => 'escape',
			),
			'unsafe path',
		);
		yield 'multiple roots' => array(
			array(
				'bundle/example.php' => self::VALID_PLUGIN,
				'other/file.txt'     => 'other',
			),
			'one package root',
		);
		yield 'case collision' => array(
			array(
				'bundle/example.php' => self::VALID_PLUGIN,
				'bundle/EXAMPLE.php' => self::VALID_PLUGIN,
			),
			'duplicate paths',
		);
		yield 'wrong identity' => array( array( 'bundle/wrong.php' => "<?php\n/*\nPlugin Name: Wrong\nVersion: 1.0.0\n*/" ), 'expected WordPress plugin' );
		yield 'no plugin header' => array( array( 'bundle/example.php' => '<?php' ), 'expected WordPress plugin' );
		yield 'multiple plugin headers' => array(
			array(
				'bundle/one.php' => "<?php\n/*\nPlugin Name: One\nVersion: 1.0.0\n*/",
				'bundle/two.php' => "<?php\n/*\nPlugin Name: Two\nVersion: 1.0.0\n*/",
			),
			'exactly one top-level WordPress plugin',
		);
	}

	public function testRejectsRootLevelPluginUpdateBeforeDownload(): void {
		$archive = new PreflightPreparedArchive();
		$this->expectExceptionMessage( 'single-file plugins cannot be updated safely' );
		try {
			( new DeploymentArchivePreflight() )->prepare( $this->attempt(), $archive, 'example.php' );
		} finally {
			self::assertSame( 0, DeploymentArchivePreflightWordPressState::$requests );
			self::assertSame( 1, $archive->cleanupCalls );
		}
	}

	public function testRejectsSymlinksAndCorruptPayloads(): void {
		$this->zip(
			array(
				'bundle/example.php' => self::VALID_PLUGIN,
				'bundle/link'        => 'target',
			),
			array( 'bundle/link' )
		);
		$this->expectExceptionMessage( 'link or device entry' );
		( new DeploymentArchivePreflight() )->prepare( $this->attempt(), new PreflightPreparedArchive(), 'example/example.php' );
	}

	public function testInvalidZipIsRemoved(): void {
		$source = tempnam( DeploymentArchivePreflightTestEnvironment::temporaryRoot(), 'source-' );
		self::assertIsString( $source );
		file_put_contents( $source, 'not a ZIP archive' );
		$this->sources[]                                  = $source;
		DeploymentArchivePreflightWordPressState::$source = $source;

		$this->expectExceptionMessage( 'not a readable ZIP file' );
		try {
			( new DeploymentArchivePreflight() )->prepare( $this->attempt(), new PreflightPreparedArchive(), 'example/example.php' );
		} finally {
			$this->assertNoPreparedArtifactsRemain();
		}
	}

	public function testZeroArchiveEntriesAreClassifiedAsAnInvalidLayout(): void {
		try {
			( new ReflectionMethod( new DeploymentArchivePreflight(), 'assertEntryCount' ) )->invoke( new DeploymentArchivePreflight(), 0 );
			self::fail( 'Zero-entry archives must be rejected.' );
		} catch ( DeploymentCheckFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_ARCHIVE_LAYOUT_INVALID, $failure->outcomeCode );
		}
	}

	public function testPreparedArtifactDetectsPermissionDrift(): void {
		$path = tempnam( DeploymentArchivePreflightTestEnvironment::temporaryRoot(), 'artifact-' );
		self::assertIsString( $path );
		file_put_contents( $path, 'original' );
		chmod( $path, 0600 );
		$identity = PreparedArtifact::regularFileIdentity( $path );
		self::assertIsArray( $identity );
		$artifact = new PreparedArtifact( $path, str_repeat( 'a', 40 ), '1.2.3', hash( 'sha256', 'original' ), $identity['device'], $identity['inode'], $identity['size'], $identity['permissions'], $identity['links'] );
		chmod( $path, 0644 );

		$this->expectExceptionMessage( 'changed before use' );
		try {
			$artifact->assertUnchanged();
		} finally {
			unlink( $path );
		}
	}

	public function testEntryAndDepthLimitsRejectTheFirstValueOverBudget(): void {
		$preflight = new DeploymentArchivePreflight();
		foreach ( array(
			array( 'assertEntryCount', array( DeploymentArchivePreflight::MAX_ENTRIES + 1 ) ),
			array( 'addExpandedBytes', array( DeploymentArchivePreflight::MAX_EXPANDED_BYTES, 1 ) ),
			array( 'validateEntryName', array( implode( '/', array_fill( 0, DeploymentArchivePreflight::MAX_DEPTH + 1, 'a' ) ) ) ),
		) as [$method, $arguments] ) {
			try {
				( new ReflectionMethod( $preflight, $method ) )->invoke( $preflight, ...$arguments );
				self::fail( 'Expected an over-budget archive to be rejected.' );
			} catch ( RuntimeException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	/** @param array<string, string|null> $entries @param list<string> $symlinks */
	private function zip( array $entries, array $symlinks = array() ): string {
		$path = tempnam( DeploymentArchivePreflightTestEnvironment::temporaryRoot(), 'source-' );
		self::assertIsString( $path );
		$zip = new ZipArchive();
		self::assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $contents ) {
			self::assertTrue( null === $contents ? $zip->addEmptyDir( rtrim( $name, '/' ) ) : $zip->addFromString( $name, $contents ) );
		}
		foreach ( $symlinks as $name ) {
			self::assertTrue( $zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, 0120777 << 16 ) );
		}
		self::assertTrue( $zip->close() );
		$this->sources[]                                  = $path;
		DeploymentArchivePreflightWordPressState::$source = $path;

		return $path;
	}

	private function attempt(
		string $type = 'plugin',
		string $slug = 'example',
		?string $subdirectory = null,
		string $source = 'manual',
		string $requestedRef = 'main'
	): DeploymentAttempt {
		$request = new DeploymentRequest( 'org/repo', null, false, 'main', $slug, $subdirectory, DeploymentPolicy::AUTOMATIC, 2 );

		return DeploymentAttempt::fromDatabase(
			array(
				'id'                      => 7,
				'correlation_id'          => str_repeat( 'a', 32 ),
				'source'                  => $source,
				'operation'               => 'update',
				'package_type'            => $type,
				'package_slug'            => $slug,
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'provider'                => 'gh',
				'provider_repository_id'  => '1',
				'requested_ref'           => $requestedRef,
				'resolved_ref'            => null,
				'delivery_id'             => 'webhook' === $source ? 'delivery-1' : null,
				'delivery_digest'         => 'webhook' === $source ? str_repeat( 'd', 64 ) : null,
				'state'                   => 'running',
				'mutation_started_at'     => null,
				'outcome_code'            => null,
				'request_json'            => $request->toJson(),
				'created_at'              => '2026-07-19 00:00:00',
				'finished_at'             => null,
			)
		);
	}

	private function assertNoPreparedArtifactsRemain(): void {
		$artifacts = glob( DeploymentArchivePreflightTestEnvironment::temporaryRoot() . '/ran-booster-*' );
		self::assertSame( array(), false === $artifacts ? array() : $artifacts );
	}
}
