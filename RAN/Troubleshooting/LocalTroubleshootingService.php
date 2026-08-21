<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Native handles are required for exclusive same-directory inode checks.

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
		private readonly ?DeploymentAttemptRepository $deploymentAttempts = null,
		private readonly ?WordPressWorkerWakeup $workerWakeup = null,
		private readonly ?Database $database = null
	) {
	}

	/**
	 * @return array{results: list<ProviderDiagnosticResult>, partial: bool}
	 */
	public function diagnose(): array {
		$runtime = $this->runtimeResult();
		if ( $this->isMultisite() ) {
			return array(
				'results' => array( $runtime ),
				'partial' => true,
			);
		}
		if ( null !== $this->database && ! $this->database->isSupported() ) {
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
		if ( null !== $this->database && ! $this->database->isReady() ) {
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

		$snapshot  = $this->deploymentSnapshot();
		$retention = $this->retentionConfiguration();

		return array(
			'results' => array(
				$runtime,
				$this->filesystemResult(),
				$this->destinationResult(),
				$this->deploymentAttemptsResult( $snapshot, $retention ),
				$this->deploymentWorkerResult( $snapshot ),
			),
			'partial' => false,
		);
	}

	private function runtimeResult(): ProviderDiagnosticResult {
		if ( $this->isMultisite() ) {
			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::FAILED,
				'local.runtime.multisite_unsupported',
				'RAN Booster beta supports single-site WordPress installations only.',
				'Use RAN Booster on a single-site installation before running provider diagnostics.'
			);
		}

		if ( version_compare( $this->phpVersion(), self::MINIMUM_PHP_VERSION, '<' )
			|| version_compare( $this->wordpressVersion(), self::MINIMUM_WORDPRESS_VERSION, '<' )
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

	private function filesystemResult(): ProviderDiagnosticResult {
		try {
			if ( ! $this->filesystemModificationAllowed() ) {
				return new ProviderDiagnosticResult(
					ProviderDiagnosticResult::FAILED,
					'local.filesystem.modifications_disabled',
					'WordPress file modifications are disabled for this site.',
					'Allow file modifications for RAN Booster deployments, then run diagnostics again.'
				);
			}

			if ( 'direct' !== $this->filesystemMethod() ) {
				return new ProviderDiagnosticResult(
					ProviderDiagnosticResult::FAILED,
					'local.filesystem.direct_unavailable',
					'WordPress cannot use the direct filesystem method required for unattended deployments.',
					'Configure direct WordPress filesystem access, then run diagnostics again.'
				);
			}

			$secretsPath = $this->secrets->path();
			if ( ! is_string( $secretsPath )
				|| '' === trim( $secretsPath )
				|| is_link( $secretsPath )
				|| ( false !== $this->pathStat( $secretsPath ) && ! is_file( $secretsPath ) )
			) {
				return $this->filesystemFailure();
			}

			$directories = array_unique(
				array(
					$this->temporaryDirectory(),
					dirname( $secretsPath ),
				)
			);

			foreach ( $directories as $directory ) {
				if ( ! $this->probeDirectory( $directory ) ) {
					return $this->filesystemFailure();
				}
			}

			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'local.filesystem.ready',
				'WordPress temporary storage and the credential-file location support secure local writes.',
				'No action is required.'
			);
		} catch ( Throwable ) {
			return $this->filesystemFailure();
		}
	}

	private function destinationResult(): ProviderDiagnosticResult {
		try {
			$directories = array_unique(
				array(
					$this->pluginDirectory(),
					$this->themeDirectory(),
				)
			);

			foreach ( $directories as $directory ) {
				if ( ! $this->probeDirectory( $directory ) ) {
					return $this->destinationFailure();
				}
			}

			return new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'local.destinations.ready',
				'The configured plugin and theme destinations support secure local writes.',
				'No action is required.'
			);
		} catch ( Throwable ) {
			return $this->destinationFailure();
		}
	}

	/** Report the identity-free operational state of the durable journal. */
	private function deploymentAttemptsResult( ?array $snapshot, ?array $retention ): ProviderDiagnosticResult {
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
	private function deploymentWorkerResult( ?array $snapshot ): ProviderDiagnosticResult {
		if ( null === $snapshot ) {
			return $this->workerUnavailable();
		}
		$queued  = $snapshot['queued'];
		$running = $snapshot['running'];
		$wakeup  = $this->workerInspection();
		if ( null === $wakeup ) {
			return $this->workerUnavailable();
		}
		if ( 'unavailable' === $wakeup['status'] ) {
			return $this->workerUnavailable();
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

	/** @return array{queued: int, running: int, needs_attention: int, earliest_queued_at: string|null, latest_terminal_at: string|null}|null */
	protected function deploymentSnapshot(): ?array {
		if ( null === $this->deploymentAttempts ) {
			return null;
		}
		try {
			return $this->deploymentAttempts->operationalSnapshot();
		} catch ( Throwable ) {
			return null;
		}
	}

	/** @return array{valid: bool, maximum_rows: int, source: 'configured'|'default'}|null */
	protected function retentionConfiguration(): ?array {
		if ( null === $this->deploymentAttempts ) {
			return null;
		}
		try {
			return $this->deploymentAttempts->retentionConfigurationStatus();
		} catch ( Throwable ) {
			return null;
		}
	}

	/** @return array{status: 'scheduled'|'missing'|'unavailable', scheduled_at: int|null}|null */
	protected function workerInspection(): ?array {
		if ( null === $this->workerWakeup ) {
			return null;
		}
		try {
			return $this->workerWakeup->inspect();
		} catch ( Throwable ) {
			return null;
		}
	}

	private function workerUnavailable(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::FAILED,
			'local.deployment_worker.unavailable',
			'Booster could not inspect the sequential deployment worker safely.',
			'Check the Booster deployment schema and WordPress cron support, then run diagnostics again.'
		);
	}

	private function filesystemFailure(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::FAILED,
			'local.filesystem.unavailable',
			'WordPress temporary storage or the credential-file location did not complete a secure write test.',
			'Check directory ownership, write permissions and symbolic links, then run diagnostics again.'
		);
	}

	private function destinationFailure(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult(
			ProviderDiagnosticResult::FAILED,
			'local.destinations.unavailable',
			'The configured plugin or theme destination did not complete a secure write test.',
			'Check both deployment destination directories and their write permissions, then run diagnostics again.'
		);
	}

	private function probeDirectory( string $directory ): bool {
		$directory = $this->canonicalDirectory( $directory );
		if ( null === $directory || ! is_writable( $directory ) ) {
			return false;
		}

		$directoryStat = $this->pathStat( $directory );
		if ( ! is_array( $directoryStat ) || 0040000 !== ( $directoryStat['mode'] & 0170000 ) ) {
			return false;
		}

		$handle      = null;
		$handleStat  = null;
		$successful  = false;
		$cleanupOkay = true;

		try {
			$suffix = $this->randomSuffix();
			if ( 1 !== preg_match( '/\A[a-f0-9]{32,64}\z/', $suffix ) ) {
				return false;
			}

			$source      = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . '.ran-booster-diagnostic-' . $suffix . '.pending';
			$destination = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . '.ran-booster-diagnostic-' . $suffix . '.verified';

			if ( false !== $this->pathStat( $source ) || false !== $this->pathStat( $destination ) ) {
				return false;
			}

			$handle = $this->openExclusive( $source );
			if ( false === $handle ) {
				return false;
			}

			$handleStat = fstat( $handle );
			if ( ! is_array( $handleStat )
				|| 0100000 !== ( $handleStat['mode'] & 0170000 )
				|| 0600 !== ( $handleStat['mode'] & 0777 )
				|| 1 !== $handleStat['nlink']
				|| ! $this->sameDirectory( $directory, $directoryStat )
				|| ! $this->handleMatchesPath( $handleStat, $source )
			) {
				return false;
			}

			$written = $this->writeMarker( $handle, self::MARKER_CONTENT );
			if ( strlen( self::MARKER_CONTENT ) !== $written
				|| ! $this->flushMarker( $handle )
				|| false !== $this->pathStat( $destination )
				|| ! $this->promoteMarker( $source, $destination )
				|| ! $this->sameDirectory( $directory, $directoryStat )
				|| ! $this->handleMatchesPromotedPaths( $handle, $source, $destination )
			) {
				return false;
			}

			$successful = true;
		} catch ( Throwable ) {
			$successful = false;
		} finally {
			if ( is_resource( $handle ) && is_array( $handleStat ) ) {
				$cleanupOkay = $this->cleanupMarker( $source ?? '', $handleStat ) && $cleanupOkay;
				$cleanupOkay = $this->cleanupMarker( $destination ?? '', $handleStat ) && $cleanupOkay;
			}

			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
		}

		return $successful && $cleanupOkay;
	}

	private function canonicalDirectory( string $directory ): ?string {
		if ( '' === trim( $directory ) || str_contains( $directory, "\0" ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		$canonical = @realpath( $directory );
		if ( false === $canonical
			|| ! is_dir( $canonical )
			|| $this->hasSymlinkComponent( $canonical )
		) {
			return null;
		}

		return $canonical;
	}

	private function normalizePath( string $path ): string {
		$path = str_replace( '\\', '/', $path );

		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	private function hasSymlinkComponent( string $path ): bool {
		$path = $this->normalizePath( $path );
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
			$stat    = $this->pathStat( $current );
			if ( ! is_array( $stat ) || 0120000 === ( $stat['mode'] & 0170000 ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param resource $handle */
	private function handleMatchesPromotedPaths( mixed $handle, string $source, string $destination ): bool {
		$sourceStat      = $this->pathStat( $source );
		$destinationStat = $this->pathStat( $destination );
		$handleStat      = fstat( $handle );

		return is_array( $sourceStat )
			&& is_array( $destinationStat )
			&& is_array( $handleStat )
			&& 0100000 === ( $handleStat['mode'] & 0170000 )
			&& 0600 === ( $handleStat['mode'] & 0777 )
			&& 2 === $handleStat['nlink']
			&& 2 === $sourceStat['nlink']
			&& 2 === $destinationStat['nlink']
			&& $this->sameFile( $sourceStat, $handleStat )
			&& $this->sameFile( $destinationStat, $handleStat );
	}

	/** @param array<string|int, int> $handleStat */
	private function handleMatchesPath( array $handleStat, string $path ): bool {
		$pathStat = $this->pathStat( $path );

		return is_array( $pathStat )
			&& 0100000 === ( $pathStat['mode'] & 0170000 )
			&& 1 === $pathStat['nlink']
			&& $pathStat['dev'] === $handleStat['dev']
			&& $pathStat['ino'] === $handleStat['ino'];
	}

	/** @param array<string|int, int> $handleStat */
	private function cleanupMarker( string $path, array $handleStat ): bool {
		if ( '' === $path ) {
			return true;
		}

		$pathStat = $this->pathStat( $path );
		if ( false === $pathStat ) {
			return true;
		}

		if ( ! $this->sameFile( $pathStat, $handleStat ) ) {
			return false;
		}

		return $this->removeMarker( $path ) && false === $this->pathStat( $path );
	}

	/**
	 * @param array<string|int, int> $left
	 * @param array<string|int, int> $right
	 */
	private function sameFile( array $left, array $right ): bool {
		return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
	}

	/** @param array<string|int, int> $expected */
	private function sameDirectory( string $path, array $expected ): bool {
		$current = $this->pathStat( $path );

		return is_array( $current )
			&& 0040000 === ( $current['mode'] & 0170000 )
			&& $this->sameFile( $current, $expected );
	}

	protected function isMultisite(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	protected function phpVersion(): string {
		return PHP_VERSION;
	}

	protected function wordpressVersion(): string {
		global $wp_version;

		return is_string( $wp_version ) ? $wp_version : '';
	}

	protected function filesystemModificationAllowed(): bool {
		if ( function_exists( 'wp_is_file_mod_allowed' ) ) {
			return wp_is_file_mod_allowed( 'ran_booster_diagnostics' );
		}

		return ! ( defined( 'DISALLOW_FILE_MODS' ) && constant( 'DISALLOW_FILE_MODS' ) );
	}

	protected function filesystemMethod(): ?string {
		if ( ! function_exists( 'get_filesystem_method' )
			&& defined( 'ABSPATH' )
			&& is_string( ABSPATH )
			&& is_file( ABSPATH . 'wp-admin/includes/file.php' )
		) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : null;
	}

	protected function temporaryDirectory(): string {
		return function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
	}

	protected function pluginDirectory(): string {
		return defined( 'WP_PLUGIN_DIR' ) && is_string( WP_PLUGIN_DIR ) ? WP_PLUGIN_DIR : '';
	}

	protected function themeDirectory(): string {
		return function_exists( 'get_theme_root' ) ? get_theme_root() : '';
	}

	protected function randomSuffix(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/** @return resource|false */
	protected function openExclusive( string $path ): mixed {
		$previousMask = umask( $this->creationMask() );
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
			return @fopen( $path, 'x+b' );
		} finally {
			umask( $previousMask );
		}
	}

	protected function creationMask(): int {
		return 0177;
	}

	/** @param resource $handle */
	protected function writeMarker( mixed $handle, string $contents ): int|false {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		return @fwrite( $handle, $contents );
	}

	/** @param resource $handle */
	protected function flushMarker( mixed $handle ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		return @fflush( $handle );
	}

	protected function promoteMarker( string $source, string $destination ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		return @link( $source, $destination );
	}

	protected function removeMarker( string $path ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		return @unlink( $path );
	}

	/** @return array<string|int, int>|false */
	protected function pathStat( string $path ): array|false {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe failures are returned as safe typed results.
		return @lstat( $path );
	}
}
