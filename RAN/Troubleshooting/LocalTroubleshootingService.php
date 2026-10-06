<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\Secrets\SecretsFile;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;
use Throwable;

/**
 * Runs local, same-request troubleshooting checks.
 */
class LocalTroubleshootingService {

	private const MINIMUM_PHP_VERSION       = '8.2';
	private const MINIMUM_WORDPRESS_VERSION = '7.0';
	private const MARKER_CONTENT            = "RAN Booster troubleshooting marker.\n";

	public function __construct(
		private readonly SecretsFile $secrets,
		private readonly ?DeploymentAttemptRepository $deployment_attempts = null,
		private readonly ?WordPressWorkerWakeup $worker_wakeup = null,
		private readonly ?Database $database = null
	) {
	}

	/**
	 * @return array{results: list<ProviderDiagnosticResult>, partial: bool}
	 */
	public function diagnose(): array {
		$runtime = $this->runtime_result();
		if ( $this->is_multisite() ) {
			return array(
				'results' => array( $runtime ),
				'partial' => true,
			);
		}
		if ( null !== $this->database && ! $this->database->is_supported() ) {
			return array(
				'results' => array(
					$runtime,
					new ProviderDiagnosticResult(
						ProviderDiagnosticResult::FAILED,
						'local.database.unsupported',
						'The database does not meet RAN Booster storage requirements.',
						DatabaseCompatibilityFailure::REQUIREMENT
					),
				),
				'partial' => true,
			);
		}
		if ( null !== $this->database && ! $this->database->is_ready() ) {
			return array(
				'results' => array(
					$runtime,
					new ProviderDiagnosticResult(
						ProviderDiagnosticResult::FAILED,
						'local.database.schema_unavailable',
						'RAN Booster database storage is not ready.',
						DatabaseLifecycleFailure::REQUIREMENT
					),
				),
				'partial' => true,
			);
		}

		$snapshot  = $this->deployment_snapshot();
		$retention = $this->retention_configuration();

		return array(
			'results' => array(
				$runtime,
				$this->filesystem_result(),
				$this->destination_result(),
				$this->deployment_attempts_result( $snapshot, $retention ),
				$this->deployment_worker_result( $snapshot ),
			),
			'partial' => false,
		);
	}

	private function runtime_result(): ProviderDiagnosticResult {
		if ( $this->is_multisite() ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'local.runtime.multisite_unsupported',
				'RAN Booster beta supports single-site WordPress installations only.',
				'Use RAN Booster on a single-site installation before running provider diagnostics.'
			);
		}

		if ( version_compare( $this->php_version(), self::MINIMUM_PHP_VERSION, '<' )
			|| version_compare( $this->wordpress_version(), self::MINIMUM_WORDPRESS_VERSION, '<' )
		) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'local.runtime.unsupported',
				'This site does not meet the supported WordPress and PHP runtime requirements.',
				'Upgrade to WordPress 7.0 or newer and PHP 8.2 or newer, then run diagnostics again.'
			);
		}

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'local.runtime.ready',
			'The WordPress and PHP runtime is supported for this single-site installation.',
			'No action is required.'
		);
	}

	private function filesystem_result(): ProviderDiagnosticResult {
		try {
			if ( ! $this->filesystem_modification_allowed() ) {
				return new ProviderDiagnosticResult(
					ProviderDiagnosticResult::FAILED,
					'local.filesystem.modifications_disabled',
					'WordPress file modifications are disabled for this site.',
					'Allow file modifications for RAN Booster deployments, then run diagnostics again.'
				);
			}

			if ( 'direct' !== $this->filesystem_method() ) {
				return new ProviderDiagnosticResult(
					ProviderDiagnosticResult::FAILED,
					'local.filesystem.direct_unavailable',
					'WordPress cannot use the direct filesystem method required for unattended deployments.',
					'Configure direct WordPress filesystem access, then run diagnostics again.'
				);
			}

			$secrets_path = $this->secrets->path();
			if ( ! is_string( $secrets_path )
				|| '' === trim( $secrets_path )
				|| is_link( $secrets_path )
				|| ( false !== $this->path_stat( $secrets_path ) && ! is_file( $secrets_path ) )
			) {
				return $this->filesystem_failure();
			}

			$directories = array_unique(
				array(
					$this->temporary_directory(),
					dirname( $secrets_path ),
				)
			);

			foreach ( $directories as $directory ) {
				if ( ! $this->probe_directory( $directory ) ) {
					return $this->filesystem_failure();
				}
			}

			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'local.filesystem.ready',
				'WordPress temporary storage and the credential-file location support secure local writes.',
				'No action is required.'
			);
		} catch ( Throwable ) {
			return $this->filesystem_failure();
		}
	}

	private function destination_result(): ProviderDiagnosticResult {
		try {
			$directories = array_unique(
				array(
					$this->plugin_directory(),
					$this->theme_directory(),
				)
			);

			foreach ( $directories as $directory ) {
				if ( ! $this->probe_directory( $directory ) ) {
					return $this->destination_failure();
				}
			}

			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'local.destinations.ready',
				'The configured plugin and theme destinations support secure local writes.',
				'No action is required.'
			);
		} catch ( Throwable ) {
			return $this->destination_failure();
		}
	}

	/** Report the identity-free operational state of the durable journal. */
	private function deployment_attempts_result( ?array $snapshot, ?array $retention ): ProviderDiagnosticResult {
		if ( null === $snapshot ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'local.deployment_attempts.unavailable',
				'Booster could not read the durable deployment journal safely.',
				'Check the Booster database schema and database connection, then run diagnostics again.'
			);
		}
		if ( null === $retention ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'local.deployment_attempts.retention_unavailable',
				'Booster could not validate the deployment-history row limit.',
				'Check RAN_BOOSTER_MAX_ATTEMPT_ROWS in wp-config.php, then run diagnostics again.'
			);
		}
		if ( ! $retention['valid'] ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'local.deployment_attempts.retention_configuration_invalid',
				'The deployment-history row limit is invalid, so Booster is using the safe 200-row default.',
				'Set RAN_BOOSTER_MAX_ATTEMPT_ROWS to an integer from 200 through 100000, then run diagnostics again.'
			);
		}

		$unresolved = $snapshot['needs_attention'];
		if ( $unresolved > 0 ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'local.deployment_attempts.attention_required',
				sprintf( '%d deployment attempt%s require operator attention.', $unresolved, 1 === $unresolved ? '' : 's' ),
				'Review Deployment activity and reconcile only after confirming the worker has stopped.'
			);
		}

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'local.deployment_attempts.ready',
			'The durable deployment journal is available and no attempts require operator attention.',
			'No action is required.'
		);
	}

	/** Report the read-only state of the sequential worker wake-up. */
	private function deployment_worker_result( ?array $snapshot ): ProviderDiagnosticResult {
		if ( null === $snapshot ) {
			return $this->worker_unavailable();
		}
		$queued  = $snapshot['queued'];
		$running = $snapshot['running'];
		$wakeup  = $this->worker_inspection();
		if ( null === $wakeup ) {
			return $this->worker_unavailable();
		}
		if ( 'unavailable' === $wakeup['status'] ) {
			return $this->worker_unavailable();
		}
		if ( $queued > 0 && 0 === $running && 'scheduled' !== $wakeup['status'] ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::WARNING,
				'local.deployment_worker.wakeup_missing',
				sprintf( '%d deployment attempt%s are queued without a verified WordPress wake-up.', $queued, 1 === $queued ? '' : 's' ),
				'Verify WordPress cron in Site Health, then refresh Deployment activity before resubmitting work.'
			);
		}

		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'local.deployment_worker.ready',
			sprintf( 'The sequential worker state is available (%d queued, %d running).', $queued, $running ),
			'No action is required.'
		);
	}

	/** @return array{queued: int, running: int, needs_attention: int}|null */
	protected function deployment_snapshot(): ?array {

		if ( null === $this->deployment_attempts ) {
			return null;
		}
		try {

			return $this->deployment_attempts->operational_snapshot();
		} catch ( Throwable ) {
			return null;
		}
	}

	/** @return array{valid: bool, maximum_rows: int, source: 'configured'|'default'}|null */
	protected function retention_configuration(): ?array {

		if ( null === $this->deployment_attempts ) {
			return null;
		}
		try {

			return $this->deployment_attempts->retention_configuration_status();
		} catch ( Throwable ) {
			return null;
		}
	}

	/** @return array{status: 'scheduled'|'missing'|'unavailable', scheduled_at: int|null}|null */
	protected function worker_inspection(): ?array {

		if ( null === $this->worker_wakeup ) {
			return null;
		}
		try {

			return $this->worker_wakeup->inspect();
		} catch ( Throwable ) {
			return null;
		}
	}

	private function worker_unavailable(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::FAILED,
			'local.deployment_worker.unavailable',
			'Booster could not inspect the sequential deployment worker safely.',
			'Check the Booster deployment schema and WordPress cron support, then run diagnostics again.'
		);
	}

	private function filesystem_failure(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::FAILED,
			'local.filesystem.unavailable',
			'WordPress temporary storage or the credential-file location did not complete a secure write test.',
			'Check directory ownership, write permissions and symbolic links, then run diagnostics again.'
		);
	}

	private function destination_failure(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::FAILED,
			'local.destinations.unavailable',
			'The configured plugin or theme destination did not complete a secure write test.',
			'Check both deployment destination directories and their write permissions, then run diagnostics again.'
		);
	}

	private function probe_directory( string $directory ): bool {
		$directory = $this->canonical_directory( $directory );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- The private local storage boundary checks actual PHP-process writability alongside canonical path and inode checks.
		if ( null === $directory || ! is_writable( $directory ) ) {
			return false;
		}

		$directory_stat = $this->path_stat( $directory );
		if ( ! is_array( $directory_stat ) || 0040000 !== ( $directory_stat['mode'] & 0170000 ) ) {
			return false;
		}

		$handle       = null;
		$handle_stat  = null;
		$successful   = false;
		$cleanup_okay = true;

		try {
			$suffix = $this->random_suffix();
			if ( 1 !== preg_match( '/\A[a-f0-9]{32,64}\z/', $suffix ) ) {
				return false;
			}

			$source      = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . '.ran-booster-diagnostic-' . $suffix . '.pending';
			$destination = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . '.ran-booster-diagnostic-' . $suffix . '.verified';

			if ( false !== $this->path_stat( $source ) || false !== $this->path_stat( $destination ) ) {
				return false;
			}

			$handle = $this->open_exclusive( $source );
			if ( false === $handle ) {
				return false;
			}

			$handle_stat = fstat( $handle );
			if ( ! is_array( $handle_stat )
				|| 0100000 !== ( $handle_stat['mode'] & 0170000 )
				|| 0600 !== ( $handle_stat['mode'] & 0777 )
				|| 1 !== $handle_stat['nlink']
				|| ! $this->same_directory( $directory, $directory_stat )
				|| ! $this->handle_matches_path( $handle_stat, $source )
			) {
				return false;
			}

			$written = $this->write_marker( $handle, self::MARKER_CONTENT );
			if ( strlen( self::MARKER_CONTENT ) !== $written
				|| ! $this->flush_marker( $handle )
				|| false !== $this->path_stat( $destination )
				|| ! $this->promote_marker( $source, $destination )
				|| ! $this->same_directory( $directory, $directory_stat )
				|| ! $this->handle_matches_promoted_paths( $handle, $source, $destination )
			) {
				return false;
			}

			$successful = true;
		} catch ( Throwable ) {
			$successful = false;
		} finally {
			if ( is_resource( $handle ) && is_array( $handle_stat ) ) {
				$cleanup_okay = $this->cleanup_marker( $source ?? '', $handle_stat );
				$cleanup_okay = $this->cleanup_marker( $destination ?? '', $handle_stat ) && $cleanup_okay;
			}

			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively created probe handle after its path/inode cleanup checks.
				fclose( $handle );
			}
		}

		return $successful && $cleanup_okay;
	}

	private function canonical_directory( string $directory ): ?string {
		if ( '' === trim( $directory ) || str_contains( $directory, "\0" ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		$canonical = @realpath( $directory );
		if ( false === $canonical
			|| ! is_dir( $canonical )
			|| $this->has_symlink_component( $canonical )
		) {
			return null;
		}

		return $canonical;
	}

	private function normalize_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );

		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	private function has_symlink_component( string $path ): bool {
		$path = $this->normalize_path( $path );
		if ( 1 === preg_match( '/\A[A-Za-z]:\//', $path ) ) {
			$current = substr( $path, 0, 3 );
			$parts   = explode( '/', substr( $path, 3 ) );
		} else {
			$current = str_starts_with( $path, '/' ) ? '/' : '';
			$parts   = explode( '/', ltrim( $path, '/' ) );
		}

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$current = rtrim( $current, '/' ) . '/' . $part;
			$stat    = $this->path_stat( $current );
			if ( ! is_array( $stat ) || 0120000 === ( $stat['mode'] & 0170000 ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param resource $handle */
	private function handle_matches_promoted_paths( mixed $handle, string $source, string $destination ): bool {
		$source_stat      = $this->path_stat( $source );
		$destination_stat = $this->path_stat( $destination );
		$handle_stat      = fstat( $handle );

		return is_array( $source_stat )
			&& is_array( $destination_stat )
			&& is_array( $handle_stat )
			&& 0100000 === ( $handle_stat['mode'] & 0170000 )
			&& 0600 === ( $handle_stat['mode'] & 0777 )
			&& 2 === $handle_stat['nlink']
			&& 2 === $source_stat['nlink']
			&& 2 === $destination_stat['nlink']
			&& $this->same_file( $source_stat, $handle_stat )
			&& $this->same_file( $destination_stat, $handle_stat );
	}

	/** @param array<string|int, int> $handle_stat */
	private function handle_matches_path( array $handle_stat, string $path ): bool {
		$path_stat = $this->path_stat( $path );

		return is_array( $path_stat )
			&& 0100000 === ( $path_stat['mode'] & 0170000 )
			&& 1 === $path_stat['nlink']
			&& $path_stat['dev'] === $handle_stat['dev']
			&& $path_stat['ino'] === $handle_stat['ino'];
	}

	/** @param array<string|int, int> $handle_stat */
	private function cleanup_marker( string $path, array $handle_stat ): bool {
		if ( '' === $path ) {
			return true;
		}

		$path_stat = $this->path_stat( $path );
		if ( false === $path_stat ) {
			return true;
		}

		if ( ! $this->same_file( $path_stat, $handle_stat ) ) {
			return false;
		}

		return $this->remove_marker( $path ) && false === $this->path_stat( $path );
	}

	/**
	 * @param array<string|int, int> $left
	 * @param array<string|int, int> $right
	 */
	private function same_file( array $left, array $right ): bool {
		return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
	}

	/**
	 * Reobserve the directory because another process can replace it.
	 *
	 * @param array<string|int, int> $expected
	 * @phpstan-impure
	 */
	private function same_directory( string $path, array $expected ): bool {
		$current = $this->path_stat( $path );

		return is_array( $current )
			&& 0040000 === ( $current['mode'] & 0170000 )
			&& $this->same_file( $current, $expected );
	}

	protected function is_multisite(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	protected function php_version(): string {
		return PHP_VERSION;
	}

	protected function wordpress_version(): string {
		global $wp_version;

		return is_string( $wp_version ) ? $wp_version : '';
	}

	protected function filesystem_modification_allowed(): bool {
		if ( function_exists( 'wp_is_file_mod_allowed' ) ) {
			return wp_is_file_mod_allowed( 'ran_booster_diagnostics' );
		}

		return ! ( defined( 'DISALLOW_FILE_MODS' ) && constant( 'DISALLOW_FILE_MODS' ) );
	}

	protected function filesystem_method(): ?string {
		if ( ! function_exists( 'get_filesystem_method' )
			&& defined( 'ABSPATH' )
			&& is_string( constant( 'ABSPATH' ) )
			&& is_file( ABSPATH . 'wp-admin/includes/file.php' )
		) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : null;
	}

	protected function temporary_directory(): string {
		return function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
	}

	protected function plugin_directory(): string {
		return defined( 'WP_PLUGIN_DIR' ) && is_string( constant( 'WP_PLUGIN_DIR' ) ) ? WP_PLUGIN_DIR : '';
	}

	protected function theme_directory(): string {
		return function_exists( 'get_theme_root' ) ? get_theme_root() : '';
	}

	protected function random_suffix(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/** @return resource|false */
	protected function open_exclusive( string $path ): mixed {
		$previous_mask = umask( $this->creation_mask() );
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Probe failures are returned as safe typed results. Exclusive native creation must fail on an existing path and return the handle used for inode checks.
			return @fopen( $path, 'x+b' );
		} finally {
			umask( $previous_mask );
		}
	}

	protected function creation_mask(): int {
		return 0177;
	}

	/** @param resource $handle */
	protected function write_marker( mixed $handle, string $contents ): int|false {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Probe failures are returned as safe typed results. Write through the exclusive marker handle so short writes fail before no-clobber hard-link promotion.
		return @fwrite( $handle, $contents );
	}

	/** @param resource $handle */
	protected function flush_marker( mixed $handle ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		return @fflush( $handle );
	}

	protected function promote_marker( string $source, string $destination ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		return @link( $source, $destination );
	}

	protected function remove_marker( string $path ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Probe failures are returned as safe typed results. Remove the exact marker after the caller verifies the current native path/handle identity.
		return @unlink( $path );
	}

	/**
	 * Read current filesystem state without reusing an earlier observation.
	 *
	 * @return array<string|int, int>|false
	 * @phpstan-impure
	 */
	protected function path_stat( string $path ): array|false {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		return @lstat( $path );
	}
}
