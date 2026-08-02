<?php

declare(strict_types=1);

namespace RAN\Logging;

// Direct local filesystem operations provide the atomic, permission and private-location guarantees required by this bounded sidecar.
// phpcs:disable WordPress.WP.AlternativeFunctions

use Closure;
use RuntimeException;
use Throwable;

/**
 * A short-lived, bounded capture of Booster's already-sanitized log lines.
 *
 * This is intentionally not a general logging subsystem. It owns one guarded
 * sidecar beside the credential file and never intercepts PHP or WordPress logs.
 */
final class TemporaryDebugCapture {

	private const FILE_NAME         = 'ran-booster-debug.php';
	private const LOCK_SUFFIX       = '.lock';
	private const HEADER            = "<?php exit; ?>\n";
	private const OWNER             = 'ran-booster';
	private const FORMAT_VERSION    = 1;
	private const ACTIVE_SECONDS    = 3600;
	private const RETENTION_SECONDS = 86400;
	private const MAX_ENTRIES       = 400;
	private const MAX_ENTRY_BYTES   = 4096;
	private const MAX_FILE_BYTES    = 262144;

	private ?string $path;
	private Closure $clock;

	public function __construct( ?string $secretsPath, ?callable $clock = null ) {
		$this->path  = is_string( $secretsPath ) && '' !== trim( $secretsPath )
			? dirname( $secretsPath ) . DIRECTORY_SEPARATOR . self::FILE_NAME
			: null;
		$this->clock = null === $clock
			? static fn(): int => time()
			: Closure::fromCallable( $clock );
	}

	/**
	 * Reset any owned capture and begin a fresh sixty-minute window.
	 *
	 * @return array<string, mixed>
	 */
	public function start(): array {
		return $this->withExclusiveLock(
			function (): array {
				$hadFile = $this->captureExists();
				if ( $hadFile ) {
					$this->readDocument();
				}

				$now      = $this->now();
				$document = array(
					'owner'        => self::OWNER,
					'format'       => self::FORMAT_VERSION,
					'active_until' => $this->timestamp( $now + self::ACTIVE_SECONDS ),
					'expires_at'   => $this->timestamp( $now + self::ACTIVE_SECONDS + self::RETENTION_SECONDS ),
					'entries'      => array(),
				);

				$this->writeDocument( $document, $hadFile );

				return $this->snapshotFromDocument( $document, 'active' );
			}
		);
	}

	/**
	 * Stop an active capture while retaining its current excerpt.
	 *
	 * @return array<string, mixed>
	 */
	public function stop(): array {
		if ( ! $this->captureExists() ) {
			return $this->emptySnapshot( 'inactive' );
		}

		return $this->withExclusiveLock(
			function (): array {
				if ( ! $this->captureExists() ) {
					return $this->emptySnapshot( 'inactive' );
				}

				$document = $this->readDocument();
				if ( $this->expired( $document ) ) {
					$this->deleteOwnedFile();

					return $this->emptySnapshot( 'inactive' );
				}

				if ( 'active' === $this->state( $document ) ) {
					$now                      = $this->now();
					$document['active_until'] = $this->timestamp( $now );
					$document['expires_at']   = $this->timestamp( $now + self::RETENTION_SECONDS );
					$this->writeDocument( $document, true );
				}

				return $this->snapshotFromDocument( $document, 'retained' );
			}
		);
	}

	/**
	 * Delete only a valid capture owned by Booster.
	 */
	public function delete(): bool {
		if ( ! $this->captureExists() ) {
			return false;
		}

		return $this->withExclusiveLock(
			function (): bool {
				if ( ! $this->captureExists() ) {
					return false;
				}

				$this->readDocument();
				$this->deleteOwnedFile();

				return true;
			}
		);
	}

	/**
	 * Verify exact managed capture ownership without changing the filesystem.
	 */
	public function assertManagedStorageDeletable(): void {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			return;
		}

		$lockPath = $this->path . self::LOCK_SUFFIX;
		$hasFile  = $this->captureExists();
		$hasLock  = file_exists( $lockPath ) || is_link( $lockPath );
		if ( ! $hasFile && ! $hasLock ) {
			$directory = dirname( $this->path );
			if ( file_exists( $directory ) || is_link( $directory ) ) {
				$this->assertWritableLocation();
			}

			return;
		}
		if ( ! $hasLock ) {
			throw new RuntimeException( 'The Booster debug capture is missing its lock.' );
		}

		$this->assertWritableLocation();
		$this->withExclusiveLock(
			function (): void {
				if ( $this->captureExists() ) {
					$this->readDocument();
				}
			},
			false
		);
	}

	/**
	 * Permanently remove the verified Booster capture and its exact lock.
	 *
	 * This uninstall-only seam is idempotent, but it never deletes malformed,
	 * symlinked, insecure or foreign capture material.
	 */
	public function deleteManagedStorage(): void {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			return;
		}

		$lockPath = $this->path . self::LOCK_SUFFIX;
		$hasFile  = $this->captureExists();
		$hasLock  = file_exists( $lockPath ) || is_link( $lockPath );
		if ( ! $hasFile && ! $hasLock ) {
			$directory = dirname( $this->path );
			if ( file_exists( $directory ) || is_link( $directory ) ) {
				$this->assertWritableLocation();
			}

			return;
		}

		$this->assertWritableLocation();
		$this->withExclusiveLock(
			function (): void {
				if ( $this->captureExists() ) {
					$this->readDocument();
					$this->deleteOwnedFile();
				}

				$lockPath = $this->path . self::LOCK_SUFFIX;
				if ( is_link( $lockPath ) ) {
					throw new RuntimeException( 'Refusing to delete an invalid Booster debug capture lock.' );
				}
				if ( ! unlink( $lockPath ) ) {
					throw new RuntimeException( 'Could not delete the Booster debug capture lock safely.' );
				}
				clearstatcache( true, $lockPath );
			},
			false
		);
	}

	/**
	 * Return a display-safe view and lazily remove an expired owned capture.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		if ( ! $this->locationAvailable() ) {
			return $this->emptySnapshot( 'unavailable' );
		}

		if ( ! $this->captureExists() ) {
			return $this->emptySnapshot( 'inactive' );
		}

		try {
			return $this->withExclusiveLock(
				function (): array {
					if ( ! $this->captureExists() ) {
						return $this->emptySnapshot( 'inactive' );
					}

					try {
						$document = $this->readDocument();
					} catch ( RuntimeException ) {
						return $this->emptySnapshot( 'malformed' );
					}

					if ( $this->expired( $document ) ) {
						$this->deleteOwnedFile();

						return $this->emptySnapshot( 'inactive' );
					}

					return $this->snapshotFromDocument( $document, $this->state( $document ) );
				}
			);
		} catch ( RuntimeException ) {
			return $this->emptySnapshot( 'unavailable' );
		}
	}

	/**
	 * Append an already-sanitized line without ever disrupting runtime work.
	 */
	public function append( string $line ): bool {
		try {
			if ( ! $this->captureExists() ) {
				return false;
			}

			return $this->withExclusiveLock(
				function () use ( $line ): bool {
					if ( ! $this->captureExists() ) {
						return false;
					}

					$document = $this->readDocument();
					if ( $this->expired( $document ) ) {
						$this->deleteOwnedFile();

						return false;
					}
					if ( 'active' !== $this->state( $document ) ) {
						return false;
					}

					$document['entries'][] = array(
						'at'   => $this->timestamp( $this->now() ),
						'line' => $this->oneLine( $line ),
					);
					$document['entries']   = array_slice( $document['entries'], -self::MAX_ENTRIES );
					$this->writeDocument( $document, true );

					return true;
				}
			);
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * @template TResult
	 * @param callable(resource): TResult $operation
	 * @return TResult
	 */
	private function withExclusiveLock( callable $operation, bool $repairExistingPermissions = true ): mixed {
		$this->assertWritableLocation();
		$lockPath = $this->path . self::LOCK_SUFFIX;

		if ( is_link( $lockPath ) ) {
			throw new RuntimeException( 'Refusing to use an invalid Booster debug capture lock.' );
		}

		$hadLock = file_exists( $lockPath );
		$lock    = fopen( $lockPath, 'c+b' );
		if ( false === $lock ) {
			throw new RuntimeException( 'Could not open the Booster debug capture lock.' );
		}

		try {
			$lockStat = fstat( $lock );
			if ( false === $lockStat
				|| 0100000 !== ( $lockStat['mode'] & 0170000 )
				|| ! $this->ownedByProcess( $lockStat )
			) {
				throw new RuntimeException( 'Could not inspect the Booster debug capture lock.' );
			}
			if ( 0600 !== ( $lockStat['mode'] & 0777 )
				&& ( ( $hadLock && ! $repairExistingPermissions ) || ! chmod( $lockPath, 0600 ) )
			) {
				throw new RuntimeException( 'Could not secure the Booster debug capture lock.' );
			}
			if ( ! flock( $lock, LOCK_EX ) ) {
				throw new RuntimeException( 'Could not lock the Booster debug capture.' );
			}

			return $operation( $lock );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	private function assertWritableLocation(): void {
		if ( ! $this->locationAvailable() ) {
			throw new RuntimeException( 'The Booster debug capture location is not available.' );
		}
	}

	private function locationAvailable(): bool {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			return false;
		}

		$directory = dirname( $this->path );
		if ( ! file_exists( $directory ) && ! is_link( $directory ) ) {
			return false;
		}

		$stat = lstat( $directory );

		return false !== $stat
			&& 0040000 === ( $stat['mode'] & 0170000 )
			&& 0700 === ( $stat['mode'] & 0777 )
			&& $this->ownedByProcess( $stat )
			&& is_readable( $directory )
			&& is_writable( $directory );
	}

	private function captureExists(): bool {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			return false;
		}

		if ( is_link( $this->path ) ) {
			return true;
		}

		return file_exists( $this->path );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readDocument(): array {
		if ( ! is_string( $this->path ) || '' === $this->path || is_link( $this->path ) ) {
			throw new RuntimeException( 'Refusing to read an invalid Booster debug capture.' );
		}

		$handle = fopen( $this->path, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'The Booster debug capture is not readable.' );
		}

		try {
			$stat = fstat( $handle );
			if ( false === $stat
				|| 0100000 !== ( $stat['mode'] & 0170000 )
				|| 0600 !== ( $stat['mode'] & 0777 )
				|| $stat['size'] > self::MAX_FILE_BYTES
			) {
				throw new RuntimeException( 'The Booster debug capture is not a secure bounded file.' );
			}

			$contents = stream_get_contents( $handle, self::MAX_FILE_BYTES + 1 );
			if ( false === $contents || strlen( $contents ) > self::MAX_FILE_BYTES ) {
				throw new RuntimeException( 'The Booster debug capture exceeds its size limit.' );
			}
		} finally {
			fclose( $handle );
		}

		if ( ! str_starts_with( $contents, self::HEADER ) ) {
			throw new RuntimeException( 'The Booster debug capture guard is invalid.' );
		}

		$lines = explode( "\n", substr( $contents, strlen( self::HEADER ) ) );
		if ( '' === end( $lines ) ) {
			array_pop( $lines );
		}
		if ( array() === $lines || count( $lines ) > self::MAX_ENTRIES + 1 ) {
			throw new RuntimeException( 'The Booster debug capture structure is invalid.' );
		}

		$metadata = $this->decodeLine( array_shift( $lines ) );
		if ( self::OWNER !== ( $metadata['owner'] ?? null ) || self::FORMAT_VERSION !== ( $metadata['format'] ?? null ) ) {
			throw new RuntimeException( 'The Booster debug capture ownership marker is invalid.' );
		}

		$metadataKeys       = array_keys( $metadata );
		$allowedMetadata    = array( 'owner', 'format', 'active_until', 'expires_at' );
		$legacyMetadataKeys = array( 'owner', 'format', 'started_at', 'active_until', 'stopped_at', 'expires_at' );
		if ( $allowedMetadata !== $metadataKeys && $legacyMetadataKeys !== $metadataKeys ) {
			throw new RuntimeException( 'The Booster debug capture metadata is invalid.' );
		}

		if ( $legacyMetadataKeys === $metadataKeys ) {
			if ( ! is_string( $metadata['started_at'] ) || false === strtotime( $metadata['started_at'] ) ) {
				throw new RuntimeException( 'The Booster debug capture timestamps are invalid.' );
			}
			if ( null !== $metadata['stopped_at'] ) {
				if ( ! is_string( $metadata['stopped_at'] ) || false === strtotime( $metadata['stopped_at'] ) ) {
					throw new RuntimeException( 'The Booster debug capture timestamps are invalid.' );
				}
				$metadata['active_until'] = $metadata['stopped_at'];
			}
		}

		foreach ( array( 'active_until', 'expires_at' ) as $field ) {
			if ( ! is_string( $metadata[ $field ] ?? null ) || false === strtotime( $metadata[ $field ] ) ) {
				throw new RuntimeException( 'The Booster debug capture timestamps are invalid.' );
			}
		}
		unset( $metadata['started_at'], $metadata['stopped_at'] );

		$entries = array();
		foreach ( $lines as $line ) {
			if ( strlen( $line ) > self::MAX_ENTRY_BYTES ) {
				throw new RuntimeException( 'A Booster debug capture entry exceeds its size limit.' );
			}
			$entry = $this->decodeLine( $line );
			if ( array( 'at', 'line' ) !== array_keys( $entry )
				|| ! is_string( $entry['at'] )
				|| false === strtotime( $entry['at'] )
				|| ! is_string( $entry['line'] )
				|| str_contains( $entry['line'], "\n" )
				|| str_contains( $entry['line'], "\r" )
			) {
				throw new RuntimeException( 'A Booster debug capture entry is invalid.' );
			}
			$entries[] = $entry;
		}

		$metadata['entries'] = $entries;

		return $metadata;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeLine( string $line ): array {
		$decoded = json_decode( $line, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'The Booster debug capture contains invalid JSON.' );
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function writeDocument( array $document, bool $expectedExisting ): void {
		$contents = $this->encodeDocument( $document );
		$size     = strlen( $contents );
		while ( $size > self::MAX_FILE_BYTES && array() !== $document['entries'] ) {
			array_shift( $document['entries'] );
			$contents = $this->encodeDocument( $document );
			$size     = strlen( $contents );
		}
		if ( $size > self::MAX_FILE_BYTES ) {
			throw new RuntimeException( 'The Booster debug capture metadata exceeds its size limit.' );
		}

		$directory = dirname( $this->path );
		$temporary = tempnam( $directory, '.ran-booster-debug-' );
		if ( false === $temporary ) {
			throw new RuntimeException( 'Could not create a temporary Booster debug capture.' );
		}

		try {
			if ( is_link( $temporary ) || ! chmod( $temporary, 0600 ) ) {
				throw new RuntimeException( 'Could not secure the temporary Booster debug capture.' );
			}

			$temporaryHandle = fopen( $temporary, 'wb' );
			if ( false === $temporaryHandle ) {
				throw new RuntimeException( 'Could not open the temporary Booster debug capture.' );
			}
			try {
				$written = fwrite( $temporaryHandle, $contents );
				if ( false === $written || strlen( $contents ) !== $written || ! fflush( $temporaryHandle ) ) {
					throw new RuntimeException( 'Could not write the temporary Booster debug capture.' );
				}
			} finally {
				fclose( $temporaryHandle );
			}

			if ( $expectedExisting ) {
				$this->readDocument();
			} elseif ( $this->captureExists() ) {
				throw new RuntimeException( 'Refusing to replace an unexpected Booster debug capture.' );
			}

			if ( ! rename( $temporary, $this->path ) ) {
				throw new RuntimeException( 'Could not replace the Booster debug capture.' );
			}

			$temporary = '';
		} finally {
			if ( '' !== $temporary && ( is_file( $temporary ) || is_link( $temporary ) ) ) {
				unlink( $temporary );
			}
		}
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function encodeDocument( array $document ): string {
		$metadata = $document;
		$entries  = $metadata['entries'];
		unset( $metadata['entries'] );

		$contents = self::HEADER . $this->encodeLine( $metadata ) . "\n";
		foreach ( $entries as $entry ) {
			$encoded   = $this->encodeEntry( $entry );
			$contents .= $encoded . "\n";
		}

		return $contents;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private function encodeEntry( array $entry ): string {
		$entry['line'] = $this->oneLine( (string) $entry['line'] );
		$encoded       = $this->encodeLine( $entry );
		$encodedSize   = strlen( $encoded );

		while ( $encodedSize > self::MAX_ENTRY_BYTES && '' !== $entry['line'] ) {
			$overflow      = $encodedSize - self::MAX_ENTRY_BYTES;
			$entry['line'] = $this->truncateUtf8( $entry['line'], max( 0, strlen( $entry['line'] ) - $overflow - 3 ) ) . '...';
			$encoded       = $this->encodeLine( $entry );
			$encodedSize   = strlen( $encoded );
		}

		if ( $encodedSize > self::MAX_ENTRY_BYTES ) {
			throw new RuntimeException( 'A Booster debug capture entry could not be bounded.' );
		}

		return $encoded;
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private function encodeLine( array $value ): string {
		$encoded = wp_json_encode(
			$value,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
		if ( ! is_string( $encoded ) || str_contains( $encoded, "\n" ) || str_contains( $encoded, "\r" ) ) {
			throw new RuntimeException( 'The Booster debug capture could not be encoded.' );
		}

		return $encoded;
	}

	/**
	 * Delete the capture after its caller validates the ownership marker.
	 */
	private function deleteOwnedFile(): void {
		if ( is_link( $this->path ) || ! unlink( $this->path ) ) {
			throw new RuntimeException( 'Could not delete the Booster debug capture.' );
		}
	}

	/** @param array<string, int> $stat */
	private function ownedByProcess( array $stat ): bool {
		$effectiveUserId = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;

		return null !== $effectiveUserId
			&& isset( $stat['uid'] )
			&& $stat['uid'] === $effectiveUserId;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function state( array $document ): string {
		$activeUntil = strtotime( $document['active_until'] );
		if ( false === $activeUntil || $this->now() >= $activeUntil ) {
			return 'retained';
		}

		return 'active';
	}

	/** @param array<string, mixed> $document */
	private function expired( array $document ): bool {
		$expiresAt = strtotime( $document['expires_at'] );

		return false === $expiresAt || $this->now() >= $expiresAt;
	}

	/**
	 * @param array<string, mixed> $document
	 * @return array<string, mixed>
	 */
	private function snapshotFromDocument( array $document, string $state ): array {
		return array(
			'state'        => $state,
			'filename'     => self::FILE_NAME,
			'active_until' => $document['active_until'],
			'expires_at'   => $document['expires_at'],
			'entries'      => $document['entries'],
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function emptySnapshot( string $state ): array {
		return array(
			'state'        => $state,
			'filename'     => self::FILE_NAME,
			'active_until' => null,
			'expires_at'   => null,
			'entries'      => array(),
		);
	}

	private function oneLine( string $value ): string {
		$value      = trim( $value );
		$normalized = preg_replace( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value );
		if ( ! is_string( $normalized ) ) {
			$normalized = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		}

		return is_string( $normalized ) ? trim( $normalized ) : '';
	}

	private function truncateUtf8( string $value, int $bytes ): string {
		$value = substr( $value, 0, $bytes );
		while ( '' !== $value && 1 !== preg_match( '//u', $value ) ) {
			$value = substr( $value, 0, -1 );
		}

		return $value;
	}

	private function now(): int {
		return (int) ( $this->clock )();
	}

	private function timestamp( int $time ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}
}
