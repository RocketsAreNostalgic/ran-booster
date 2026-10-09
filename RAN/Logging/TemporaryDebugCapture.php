<?php

declare(strict_types=1);

namespace RAN\Logging;

// Direct local filesystem operations provide the atomic, permission and private-location guarantees required by this bounded sidecar.

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


	public function __construct( ?string $secrets_path, ?callable $clock = null ) {

		$this->path = is_string( $secrets_path ) && '' !== trim( $secrets_path )

			? dirname( $secrets_path ) . DIRECTORY_SEPARATOR . self::FILE_NAME
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
		return $this->with_exclusive_lock(
			function (): array {
				$had_file = $this->capture_exists();
				if ( $had_file ) {
					$this->read_document();
				}

				$now      = $this->now();
				$document = array(
					'owner'        => self::OWNER,
					'format'       => self::FORMAT_VERSION,
					'active_until' => $this->timestamp( $now + self::ACTIVE_SECONDS ),
					'expires_at'   => $this->timestamp( $now + self::ACTIVE_SECONDS + self::RETENTION_SECONDS ),
					'entries'      => array(),
				);

				$this->write_document( $document, $had_file );

				return $this->snapshot_from_document( $document, 'active' );
			}
		);
	}

	/**
	 * Stop an active capture while retaining its current excerpt.
	 *
	 * @return array<string, mixed>
	 */
	public function stop(): array {
		if ( ! $this->capture_exists() ) {
			return $this->empty_snapshot( 'inactive' );
		}

		return $this->with_exclusive_lock(
			function (): array {
				if ( ! $this->capture_exists() ) {
					return $this->empty_snapshot( 'inactive' );
				}

				$document = $this->read_document();
				if ( $this->expired( $document ) ) {
					$this->delete_owned_file();

					return $this->empty_snapshot( 'inactive' );
				}

				if ( 'active' === $this->state( $document ) ) {
					$now                      = $this->now();
					$document['active_until'] = $this->timestamp( $now );
					$document['expires_at']   = $this->timestamp( $now + self::RETENTION_SECONDS );
					$this->write_document( $document, true );
				}

				return $this->snapshot_from_document( $document, 'retained' );
			}
		);
	}

	/**
	 * Delete only a valid capture owned by Booster.
	 */
	public function delete(): bool {
		if ( ! $this->capture_exists() ) {
			return false;
		}

		return $this->with_exclusive_lock(
			function (): bool {
				if ( ! $this->capture_exists() ) {
					return false;
				}

				$this->read_document();
				$this->delete_owned_file();

				return true;
			}
		);
	}

	/**
	 * Verify exact managed capture ownership without changing the filesystem.
	 */
	public function assert_managed_storage_deletable(): void {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			return;
		}

		$lock_path = $this->path . self::LOCK_SUFFIX;
		$has_file  = $this->capture_exists();
		$has_lock  = file_exists( $lock_path ) || is_link( $lock_path );
		if ( ! $has_file && ! $has_lock ) {
			$directory = dirname( $this->path );
			if ( file_exists( $directory ) || is_link( $directory ) ) {
				$this->assert_writable_location();
			}

			return;
		}
		if ( ! $has_lock ) {
			throw new RuntimeException( 'The Booster debug capture is missing its lock.' );
		}

		$this->assert_writable_location();
		$this->with_exclusive_lock(
			function (): void {
				if ( $this->capture_exists() ) {
					$this->read_document();
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
	public function delete_managed_storage(): void {
		if ( ! is_string( $this->path ) || '' === $this->path ) {
			return;
		}

		$lock_path = $this->path . self::LOCK_SUFFIX;
		$has_file  = $this->capture_exists();
		$has_lock  = file_exists( $lock_path ) || is_link( $lock_path );
		if ( ! $has_file && ! $has_lock ) {
			$directory = dirname( $this->path );
			if ( file_exists( $directory ) || is_link( $directory ) ) {
				$this->assert_writable_location();
			}

			return;
		}

		$this->assert_writable_location();
		$this->with_exclusive_lock(
			function (): void {
				if ( $this->capture_exists() ) {
					$this->read_document();
					$this->delete_owned_file();
				}

				$lock_path = $this->path . self::LOCK_SUFFIX;
				if ( is_link( $lock_path ) ) {
					throw new RuntimeException( 'Refusing to delete an invalid Booster debug capture lock.' );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the exact local storage entry after the surrounding native ownership, type and identity checks.
				if ( ! unlink( $lock_path ) ) {
					throw new RuntimeException( 'Could not delete the Booster debug capture lock safely.' );
				}
				clearstatcache( true, $lock_path );
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
		if ( ! $this->location_available() ) {
			return $this->empty_snapshot( 'unavailable' );
		}

		if ( ! $this->capture_exists() ) {
			return $this->empty_snapshot( 'inactive' );
		}

		try {
			return $this->with_exclusive_lock(
				function (): array {
					if ( ! $this->capture_exists() ) {
						return $this->empty_snapshot( 'inactive' );
					}

					try {
						$document = $this->read_document();
					} catch ( RuntimeException ) {
						return $this->empty_snapshot( 'malformed' );
					}

					if ( $this->expired( $document ) ) {
						$this->delete_owned_file();

						return $this->empty_snapshot( 'inactive' );
					}

					return $this->snapshot_from_document( $document, $this->state( $document ) );
				}
			);
		} catch ( RuntimeException ) {
			return $this->empty_snapshot( 'unavailable' );
		}
	}

	/**
	 * Append an already-sanitized line without ever disrupting runtime work.
	 */
	public function append( string $line ): bool {
		try {
			if ( ! $this->capture_exists() ) {
				return false;
			}

			return $this->with_exclusive_lock(
				function () use ( $line ): bool {
					if ( ! $this->capture_exists() ) {
						return false;
					}

					$document = $this->read_document();
					if ( $this->expired( $document ) ) {
						$this->delete_owned_file();

						return false;
					}
					if ( 'active' !== $this->state( $document ) ) {
						return false;
					}

					$document['entries'][] = array(
						'at'   => $this->timestamp( $this->now() ),
						'line' => $this->one_line( $line ),
					);
					$document['entries']   = array_slice( $document['entries'], -self::MAX_ENTRIES );
					$this->write_document( $document, true );

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
	private function with_exclusive_lock( callable $operation, bool $repair_existing_permissions = true ): mixed {
		$this->assert_writable_location();
		$lock_path = $this->path . self::LOCK_SUFFIX;

		if ( is_link( $lock_path ) ) {
			throw new RuntimeException( 'Refusing to use an invalid Booster debug capture lock.' );
		}

		$had_lock = file_exists( $lock_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The lock requires a native handle for flock and fstat without truncating existing bytes.
		$lock = fopen( $lock_path, 'c+b' );
		if ( false === $lock ) {
			throw new RuntimeException( 'Could not open the Booster debug capture lock.' );
		}

		try {
			$lock_stat = fstat( $lock );
			if ( false === $lock_stat
				|| 0100000 !== ( $lock_stat['mode'] & 0170000 )
				|| ! $this->owned_by_process( $lock_stat )
			) {
				throw new RuntimeException( 'Could not inspect the Booster debug capture lock.' );
			}
			if ( 0600 !== ( $lock_stat['mode'] & 0777 )
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set and verify native POSIX permission bits on the private file before it can be trusted or installed.
				&& ( ( $had_lock && ! $repair_existing_permissions ) || ! chmod( $lock_path, 0600 ) )
			) {
				throw new RuntimeException( 'Could not secure the Booster debug capture lock.' );
			}
			if ( ! flock( $lock, LOCK_EX ) ) {
				throw new RuntimeException( 'Could not lock the Booster debug capture.' );
			}

			return $operation( $lock );
		} finally {
			flock( $lock, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native handle acquired for bounded reads, exclusive locking or atomic local replacement.
			fclose( $lock );
		}
	}

	private function assert_writable_location(): void {
		if ( ! $this->location_available() ) {
			throw new RuntimeException( 'The Booster debug capture location is not available.' );
		}
	}

	private function location_available(): bool {
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
			&& $this->owned_by_process( $stat )
			&& is_readable( $directory )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- The private local storage boundary checks actual PHP-process writability alongside canonical path and inode checks.
			&& is_writable( $directory );
	}

	private function capture_exists(): bool {
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
	private function read_document(): array {
		if ( ! is_string( $this->path ) || '' === $this->path || is_link( $this->path ) ) {
			throw new RuntimeException( 'Refusing to read an invalid Booster debug capture.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The native read handle supplies fstat type/mode/size checks and a bounded stream read.
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native handle acquired for bounded reads, exclusive locking or atomic local replacement.
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

		$metadata = $this->decode_line( array_shift( $lines ) );
		if ( self::OWNER !== ( $metadata['owner'] ?? null ) || self::FORMAT_VERSION !== ( $metadata['format'] ?? null ) ) {
			throw new RuntimeException( 'The Booster debug capture ownership marker is invalid.' );
		}

		if ( array( 'owner', 'format', 'active_until', 'expires_at' ) !== array_keys( $metadata ) ) {
			throw new RuntimeException( 'The Booster debug capture metadata is invalid.' );
		}

		foreach ( array( 'active_until', 'expires_at' ) as $field ) {
			if ( ! is_string( $metadata[ $field ] ?? null ) || false === strtotime( $metadata[ $field ] ) ) {
				throw new RuntimeException( 'The Booster debug capture timestamps are invalid.' );
			}
		}

		$entries = array();
		foreach ( $lines as $line ) {
			if ( strlen( $line ) > self::MAX_ENTRY_BYTES ) {
				throw new RuntimeException( 'A Booster debug capture entry exceeds its size limit.' );
			}
			$entry = $this->decode_line( $line );
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
	private function decode_line( string $line ): array {
		$decoded = json_decode( $line, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( 'The Booster debug capture contains invalid JSON.' );
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function write_document( array $document, bool $expected_existing ): void {
		$path = $this->path;
		if ( null === $path ) {
			throw new RuntimeException( 'The Booster debug capture location is not available.' );
		}

		$contents = $this->encode_document( $document );
		$size     = strlen( $contents );
		while ( $size > self::MAX_FILE_BYTES && array() !== $document['entries'] ) {
			array_shift( $document['entries'] );
			$contents = $this->encode_document( $document );
			$size     = strlen( $contents );
		}
		if ( $size > self::MAX_FILE_BYTES ) {
			throw new RuntimeException( 'The Booster debug capture metadata exceeds its size limit.' );
		}

		$directory = dirname( $path );
		$temporary = tempnam( $directory, '.ran-booster-debug-' );
		if ( false === $temporary ) {
			throw new RuntimeException( 'Could not create a temporary Booster debug capture.' );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set and verify native POSIX permission bits on the private file before it can be trusted or installed.
			if ( is_link( $temporary ) || ! chmod( $temporary, 0600 ) ) {
				throw new RuntimeException( 'Could not secure the temporary Booster debug capture.' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The same-directory replacement requires a native stream for checked writes and flushes.
			$temporary_handle = fopen( $temporary, 'wb' );
			if ( false === $temporary_handle ) {
				throw new RuntimeException( 'Could not open the temporary Booster debug capture.' );
			}
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write through the verified native handle so short writes and flush failures preserve the atomic replacement boundary.
				$written = fwrite( $temporary_handle, $contents );
				if ( false === $written || strlen( $contents ) !== $written || ! fflush( $temporary_handle ) ) {
					throw new RuntimeException( 'Could not write the temporary Booster debug capture.' );
				}
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native handle acquired for bounded reads, exclusive locking or atomic local replacement.
				fclose( $temporary_handle );
			}

			if ( $expected_existing ) {
				$this->read_document();
			} elseif ( $this->capture_exists() ) {
				throw new RuntimeException( 'Refusing to replace an unexpected Booster debug capture.' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory native rename supplies the atomic replacement required by the verified local-file transaction.
			if ( ! rename( $temporary, $path ) ) {
				throw new RuntimeException( 'Could not replace the Booster debug capture.' );
			}

			$temporary = '';
		} finally {
			if ( '' !== $temporary && ( is_file( $temporary ) || is_link( $temporary ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Clean up only the same-directory temporary entry owned by this failed native replacement transaction.
				unlink( $temporary );
			}
		}
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function encode_document( array $document ): string {
		$metadata = $document;
		$entries  = $metadata['entries'];
		unset( $metadata['entries'] );

		$contents = self::HEADER . $this->encode_line( $metadata ) . "\n";
		foreach ( $entries as $entry ) {
			$encoded   = $this->encode_entry( $entry );
			$contents .= $encoded . "\n";
		}

		return $contents;
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private function encode_entry( array $entry ): string {
		$entry['line'] = $this->one_line( (string) $entry['line'] );
		$encoded       = $this->encode_line( $entry );
		$encoded_size  = strlen( $encoded );

		while ( $encoded_size > self::MAX_ENTRY_BYTES && '' !== $entry['line'] ) {
			$overflow      = $encoded_size - self::MAX_ENTRY_BYTES;
			$entry['line'] = $this->truncate_utf8( $entry['line'], max( 0, strlen( $entry['line'] ) - $overflow - 3 ) ) . '...';
			$encoded       = $this->encode_line( $entry );
			$encoded_size  = strlen( $encoded );
		}

		if ( $encoded_size > self::MAX_ENTRY_BYTES ) {
			throw new RuntimeException( 'A Booster debug capture entry could not be bounded.' );
		}

		return $encoded;
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private function encode_line( array $value ): string {
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
	private function delete_owned_file(): void {
		$path = $this->path;
		if ( null === $path ) {
			throw new RuntimeException( 'The Booster debug capture location is not available.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the exact local storage entry after the surrounding native ownership, type and identity checks.
		if ( is_link( $path ) || ! unlink( $path ) ) {
			throw new RuntimeException( 'Could not delete the Booster debug capture.' );
		}
	}

	/** @param array<string|int, int> $stat */
	private function owned_by_process( array $stat ): bool {
		$effective_user_id = function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;

		return null !== $effective_user_id
			&& isset( $stat['uid'] )
			&& $stat['uid'] === $effective_user_id;
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function state( array $document ): string {
		$active_until = strtotime( $document['active_until'] );
		if ( false === $active_until || $this->now() >= $active_until ) {
			return 'retained';
		}

		return 'active';
	}

	/** @param array<string, mixed> $document */
	private function expired( array $document ): bool {
		$expires_at = strtotime( $document['expires_at'] );

		return false === $expires_at || $this->now() >= $expires_at;
	}

	/**
	 * @param array<string, mixed> $document
	 * @return array<string, mixed>
	 */
	private function snapshot_from_document( array $document, string $state ): array {
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
	private function empty_snapshot( string $state ): array {
		return array(
			'state'        => $state,
			'filename'     => self::FILE_NAME,
			'active_until' => null,
			'expires_at'   => null,
			'entries'      => array(),
		);
	}

	private function one_line( string $value ): string {
		$value      = trim( $value );
		$normalized = preg_replace( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value );
		if ( ! is_string( $normalized ) ) {
			$normalized = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		}

		return is_string( $normalized ) ? trim( $normalized ) : '';
	}

	private function truncate_utf8( string $value, int $bytes ): string {
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
