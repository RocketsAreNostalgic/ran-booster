<?php

declare(strict_types=1);

namespace Tests\Troubleshooting;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests exercise native exclusive-file safety seams.

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Troubleshooting\LocalTroubleshootingService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass( LocalTroubleshootingService::class )]
final class LocalTroubleshootingServiceTest extends TestCase {

	private string $directory;
	private string $temporaryDirectory;
	private string $secretsDirectory;
	private string $pluginDirectory;
	private string $themeDirectory;

	protected function setUp(): void {
		$temporaryRoot = realpath( sys_get_temp_dir() );
		self::assertIsString( $temporaryRoot );
		$this->directory          = $temporaryRoot . '/ran-booster-local-diagnostics-' . bin2hex( random_bytes( 8 ) );
		$this->temporaryDirectory = $this->directory . '/temporary';
		$this->secretsDirectory   = $this->directory . '/credentials';
		$this->pluginDirectory    = $this->directory . '/plugins';
		$this->themeDirectory     = $this->directory . '/themes';

		foreach ( $this->directories() as $directory ) {
			mkdir( $directory, 0700, true );
		}
	}

	protected function tearDown(): void {
		if ( ! is_dir( $this->directory ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isLink() || $item->isFile() ) {
				unlink( $item->getPathname() );
			} else {
				rmdir( $item->getPathname() );
			}
		}

		rmdir( $this->directory );
	}

	public function test_returns_exactly_five_ordered_results_with_real_operational_state(): void {
		$service = $this->service();
		$payload = $service->diagnose();

		self::assertFalse( $payload['partial'] );
		self::assertCount( 5, $payload['results'] );
		self::assertSame(
			array(
				'local.runtime.ready',
				'local.filesystem.ready',
				'local.destinations.ready',
				'local.deployment_attempts.ready',
				'local.deployment_worker.ready',
			),
			$this->codes( $payload['results'] )
		);
		self::assertSame(
			array(
				ProviderDiagnosticResult::PASSED,
				ProviderDiagnosticResult::PASSED,
				ProviderDiagnosticResult::PASSED,
				ProviderDiagnosticResult::PASSED,
				ProviderDiagnosticResult::PASSED,
			),
			array_map( static fn( ProviderDiagnosticResult $result ): string => $result->status, $payload['results'] )
		);
		self::assertSame( array(), $this->markerFiles() );
		self::assertSame( 1, $service->deploymentSnapshotReads );
		self::assertSame( 1, $service->workerInspectionReads );
	}

	public function test_operational_rows_do_not_introduce_options_schedulers_or_logging(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/RAN/Troubleshooting/LocalTroubleshootingService.php' );

		self::assertIsString( $source );
		foreach ( array( 'get_option(', 'update_option(', 'add_option(', 'get_transient(', 'set_transient(', 'wp_schedule_', 'as_schedule_', 'Logger' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
	}

	public function test_unavailable_journal_and_worker_fail_explicitly(): void {
		$service                     = $this->service();
		$service->deploymentSnapshot = null;

		$payload = $service->diagnose();

		self::assertSame( 'local.deployment_attempts.unavailable', $payload['results'][3]->code );
		self::assertSame( 'local.deployment_worker.unavailable', $payload['results'][4]->code );
	}

	public function test_unresolved_attempts_and_missing_queued_wakeup_are_warnings(): void {
		$service                     = $this->service();
		$service->deploymentSnapshot = array(
			'queued'             => 2,
			'running'            => 0,
			'needs_attention'    => 1,
			'earliest_queued_at' => '2026-07-19 00:00:00',
			'latest_terminal_at' => '2026-07-19 00:01:00',
		);
		$service->workerInspection   = array(
			'status'       => 'missing',
			'scheduled_at' => null,
		);

		$payload = $service->diagnose();

		self::assertSame( 'local.deployment_attempts.attention_required', $payload['results'][3]->code );
		self::assertSame( ProviderDiagnosticResult::WARNING, $payload['results'][3]->status );
		self::assertSame( 'local.deployment_worker.wakeup_missing', $payload['results'][4]->code );
		self::assertSame( ProviderDiagnosticResult::WARNING, $payload['results'][4]->status );
	}

	public function test_invalid_attempt_retention_constant_is_reported_with_the_safe_fallback(): void {
		$service                         = $this->service();
		$service->retentionConfiguration = array(
			'valid'        => false,
			'maximum_rows' => 200,
			'source'       => 'configured',
		);

		$payload = $service->diagnose();

		self::assertSame( 'local.deployment_attempts.retention_configuration_invalid', $payload['results'][3]->code );
		self::assertSame( ProviderDiagnosticResult::WARNING, $payload['results'][3]->status );
		self::assertStringContainsString( '200-row default', $payload['results'][3]->message );
		self::assertStringContainsString( '200 through 100000', $payload['results'][3]->remediation );
	}

	public function test_unavailable_worker_inspection_fails_even_when_the_queue_is_empty(): void {
		$service                   = $this->service();
		$service->workerInspection = array(
			'status'       => 'unavailable',
			'scheduled_at' => null,
		);

		$payload = $service->diagnose();

		self::assertSame( 'local.deployment_worker.unavailable', $payload['results'][4]->code );
		self::assertSame( ProviderDiagnosticResult::FAILED, $payload['results'][4]->status );
	}

	public function test_missing_wakeup_is_healthy_while_the_worker_is_running(): void {
		$service                     = $this->service();
		$service->deploymentSnapshot = array(
			'queued'             => 2,
			'running'            => 1,
			'needs_attention'    => 0,
			'earliest_queued_at' => '2026-07-19 00:00:00',
			'latest_terminal_at' => null,
		);
		$service->workerInspection   = array(
			'status'       => 'missing',
			'scheduled_at' => null,
		);

		$payload = $service->diagnose();

		self::assertSame( 'local.deployment_worker.ready', $payload['results'][4]->code );
		self::assertSame( ProviderDiagnosticResult::PASSED, $payload['results'][4]->status );
	}

	public function test_deployment_snapshot_dependency_failure_is_isolated_to_operational_rows(): void {
		$database                         = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$arguments ): string {
				throw new \RuntimeException( 'token_canary' );
			}

			public function query( string $query ): int|false {
				return false;
			}

			public function get_results( string $query ): array|false {
				return false;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data ): int|false {
				return false;
			}
		};
		$service                          = new LocalTroubleshootingServiceFixture(
			new SecretsFile( $this->directory . '/secrets.php', array() ),
			$this->temporaryDirectory,
			$this->pluginDirectory,
			$this->themeDirectory,
			new DeploymentAttemptRepository( $database, 'wp_ran_booster_deployment_attempts' )
		);
		$service->useDeploymentDependency = true;

		$payload = $service->diagnose();

		self::assertSame( 'local.runtime.ready', $payload['results'][0]->code );
		self::assertSame( 'local.deployment_attempts.unavailable', $payload['results'][3]->code );
		self::assertSame( 'local.deployment_worker.unavailable', $payload['results'][4]->code );
		self::assertSame( 1, $service->deploymentSnapshotReads );
	}

	public function test_multisite_stops_before_any_filesystem_work(): void {
		$service            = $this->service();
		$service->multisite = true;

		$payload = $service->diagnose();

		self::assertTrue( $payload['partial'] );
		self::assertSame( array( 'local.runtime.multisite_unsupported' ), $this->codes( $payload['results'] ) );
		self::assertSame( 0, $service->filesystemReads );
		self::assertSame( 0, $service->markerOpens );
	}

	public function test_unsupported_database_is_reported_without_custom_table_or_filesystem_access(): void {
		$connection = new class() {
			public string $last_error = '';
			public function db_server_info(): string {
				return '5.7.44';
			}
			public function get_results( string $query ): array {
				throw new \LogicException( 'An old server must fail before engine or custom-table reads.' );
			}
		};
		$service    = new LocalTroubleshootingServiceFixture(
			new SecretsFile( $this->secretsDirectory . '/secrets.json', array() ),
			$this->temporaryDirectory,
			$this->pluginDirectory,
			$this->themeDirectory,
			null,
			null,
			new Database( $connection )
		);

		$payload = $service->diagnose();

		self::assertTrue( $payload['partial'] );
		self::assertSame( array( 'local.runtime.ready', 'local.database.unsupported' ), $this->codes( $payload['results'] ) );
		self::assertSame( 0, $service->filesystemReads );
		self::assertSame( 0, $service->deploymentSnapshotReads );
	}

	public function test_schema_lifecycle_failure_is_reported_without_custom_table_or_filesystem_access(): void {
		$database = $this->createStub( Database::class );
		$database->method( 'isSupported' )->willReturn( true );
		$database->method( 'isReady' )->willReturn( false );
		$service = new LocalTroubleshootingServiceFixture(
			new SecretsFile( $this->secretsDirectory . '/secrets.json', array() ),
			$this->temporaryDirectory,
			$this->pluginDirectory,
			$this->themeDirectory,
			database: $database
		);

		$payload = $service->diagnose();

		self::assertTrue( $payload['partial'] );
		self::assertSame( array( 'local.runtime.ready', 'local.database.schema_unavailable' ), $this->codes( $payload['results'] ) );
		self::assertSame( DatabaseLifecycleFailure::REQUIREMENT, $payload['results'][1]->remediation );
		self::assertSame( 0, $service->filesystemReads );
		self::assertSame( 0, $service->deploymentSnapshotReads );
	}

	public function test_reports_an_unsupported_runtime_without_hiding_other_local_rows(): void {
		$service                   = $this->service();
		$service->wordpressVersion = '6.9.9';

		$payload = $service->diagnose();

		self::assertFalse( $payload['partial'] );
		self::assertSame( 'local.runtime.unsupported', $payload['results'][0]->code );
		self::assertCount( 5, $payload['results'] );
	}

	public function test_accepts_php_eight_two_and_rejects_older_php_versions(): void {
		$service = $this->service();

		self::assertSame( 'local.runtime.ready', $service->diagnose()['results'][0]->code );

		$service->phpVersion = '8.1.99';
		$result              = $service->diagnose()['results'][0];

		self::assertSame( 'local.runtime.unsupported', $result->code );
		self::assertSame( 'Upgrade to WordPress 7.0 or newer and PHP 8.2 or newer, then run diagnostics again.', $result->remediation );
	}

	public function test_reports_disabled_file_modifications_before_marker_writes(): void {
		$service                           = $this->service();
		$service->fileModificationsAllowed = false;

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.modifications_disabled', $payload['results'][1]->code );
		self::assertSame( 2, $service->markerOpens, 'Only the plugin and theme destination probes should run.' );
	}

	public function test_reports_a_non_direct_wordpress_filesystem_method(): void {
		$service                   = $this->service();
		$service->filesystemMethod = 'ftpext';

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.direct_unavailable', $payload['results'][1]->code );
		self::assertSame( 2, $service->markerOpens, 'Only the plugin and theme destination probes should run.' );
	}

	public function test_rejects_a_symbolic_link_at_the_credential_sidecar_path(): void {
		$target = $this->directory . '/credential-target';
		$link   = $this->secretsDirectory . '/secrets.json';
		file_put_contents( $target, 'credential target canary' );
		symlink( $target, $link );

		$payload = $this->service( $link )->diagnose();

		self::assertSame( 'local.filesystem.unavailable', $payload['results'][1]->code );
		self::assertSame( 'credential target canary', file_get_contents( $target ) );
	}

	public function test_resolves_an_ancestor_alias_and_uses_only_the_canonical_root(): void {
		$realParent = $this->directory . '/real-parent';
		$linkParent = $this->directory . '/linked-parent';
		mkdir( $realParent . '/nested', 0700, true );
		symlink( $realParent, $linkParent );

		$service                     = $this->service();
		$service->temporaryDirectory = $linkParent . '/nested';
		$payload                     = $service->diagnose();

		self::assertSame( 'local.filesystem.ready', $payload['results'][1]->code );
		self::assertNotSame( array(), $service->openedPaths );
		self::assertStringStartsWith( $realParent . '/nested/', $service->openedPaths[0] );
		self::assertStringNotContainsString( $linkParent, $service->openedPaths[0] );
		self::assertSame( array(), $this->markerFiles( $realParent ) );
	}

	public function test_resolves_dot_segments_before_writing(): void {
		$service                     = $this->service();
		$service->temporaryDirectory = $this->temporaryDirectory . '/../temporary';

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.ready', $payload['results'][1]->code );
		self::assertStringStartsWith( $this->temporaryDirectory . '/', $service->openedPaths[0] );
		self::assertStringNotContainsString( '..', $service->openedPaths[0] );
		self::assertSame( array(), $this->markerFiles( $this->temporaryDirectory ) );
	}

	public function test_fails_when_the_canonical_directory_is_replaced_before_open(): void {
		$service                         = $this->service();
		$service->replaceDirectoryOnOpen = $this->temporaryDirectory;

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.unavailable', $payload['results'][1]->code );
		self::assertSame( array(), $this->markerFiles( $this->temporaryDirectory ) );
	}

	public function test_exclusive_creation_rejects_a_raced_marker_name_without_removing_it(): void {
		$service = $this->service();
		$suffix  = $service->nextSuffix();
		$marker  = $this->temporaryDirectory . '/.ran-booster-diagnostic-' . $suffix . '.pending';
		file_put_contents( $marker, 'race canary' );

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.unavailable', $payload['results'][1]->code );
		self::assertSame( 'race canary', file_get_contents( $marker ) );
	}

	public function test_permission_failure_fails_and_cleans_the_marker(): void {
		$service                 = $this->service();
		$service->failPermission = true;

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.unavailable', $payload['results'][1]->code );
		self::assertSame( array(), $this->markerFiles( $this->temporaryDirectory ) );
	}

	public function test_promotion_failure_fails_and_cleans_the_marker(): void {
		$service                = $this->service();
		$service->failPromotion = $this->temporaryDirectory;

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.unavailable', $payload['results'][1]->code );
		self::assertSame( array(), $this->markerFiles( $this->temporaryDirectory ) );
	}

	public function test_cleanup_failure_is_a_failed_result(): void {
		$service              = $this->service();
		$service->failCleanup = $this->temporaryDirectory;

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.unavailable', $payload['results'][1]->code );
		self::assertNotSame( array(), $this->markerFiles( $this->temporaryDirectory ) );
	}

	public function test_path_substitution_is_not_unlinked_during_cleanup(): void {
		$service                  = $this->service();
		$service->raceOnPromotion = $this->temporaryDirectory;

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.unavailable', $payload['results'][1]->code );
		self::assertContains( 'attacker replacement canary', $this->markerContents( $this->temporaryDirectory ) );
	}

	public function test_destination_raced_into_place_is_not_overwritten_or_removed(): void {
		$service                  = $this->service();
		$service->raceDestination = $this->temporaryDirectory;

		$payload = $service->diagnose();

		self::assertSame( 'local.filesystem.unavailable', $payload['results'][1]->code );
		self::assertContains( 'destination race canary', $this->markerContents( $this->temporaryDirectory ) );
	}

	public function test_permission_path_substitution_does_not_change_the_symlink_target_mode(): void {
		$target = $this->directory . '/permission-target';
		file_put_contents( $target, 'permission target canary' );
		chmod( $target, 0644 );

		$service                             = $this->service();
		$service->substituteBeforePermission = $target;
		$payload                             = $service->diagnose();

		clearstatcache( true, $target );
		self::assertSame( 'local.filesystem.unavailable', $payload['results'][1]->code );
		self::assertSame( 0644, fileperms( $target ) & 0777 );
		self::assertSame( 'permission target canary', file_get_contents( $target ) );
	}

	public function test_destination_root_failure_is_reported_in_the_third_row(): void {
		rmdir( $this->pluginDirectory );

		$payload = $this->service()->diagnose();

		self::assertSame( 'local.filesystem.ready', $payload['results'][1]->code );
		self::assertSame( 'local.destinations.unavailable', $payload['results'][2]->code );
	}

	public function test_markers_are_restricted_before_no_clobber_promotion(): void {
		$service = $this->service();

		$service->diagnose();

		self::assertNotSame( array(), $service->permissionsBeforePromotion );
		self::assertSame( array_fill( 0, 4, 0600 ), $service->permissionsBeforePromotion );
		self::assertSame( array(), $this->markerFiles() );
	}

	public function test_result_payload_never_contains_secret_or_path_canaries(): void {
		$secretCanary = 'ghp_secret_diagnostic_canary';
		$pathCanary   = 'absolute-path-diagnostic-canary';
		$directory    = $this->directory . '/' . $pathCanary . '/' . $secretCanary;
		mkdir( $directory, 0700, true );

		$service                     = $this->service();
		$service->temporaryDirectory = $directory;
		$service->failPromotion      = $directory;

		$payload = array_map(
			static fn( ProviderDiagnosticResult $result ): array => $result->toArray(),
			$service->diagnose()['results']
		);
		$encoded = json_encode( $payload, JSON_THROW_ON_ERROR );

		self::assertStringNotContainsString( $secretCanary, $encoded );
		self::assertStringNotContainsString( $pathCanary, $encoded );
		self::assertStringNotContainsString( $this->directory, $encoded );
		self::assertStringNotContainsString( 'Authorization:', $encoded );
	}

	/** @return list<string> */
	private function directories(): array {
		return array(
			$this->temporaryDirectory,
			$this->secretsDirectory,
			$this->pluginDirectory,
			$this->themeDirectory,
		);
	}

	private function service( ?string $secretsPath = null ): LocalTroubleshootingServiceFixture {
		return new LocalTroubleshootingServiceFixture(
			new SecretsFile( $secretsPath ?? $this->secretsDirectory . '/secrets.json', array() ),
			$this->temporaryDirectory,
			$this->pluginDirectory,
			$this->themeDirectory
		);
	}

	/**
	 * @param list<ProviderDiagnosticResult> $results
	 * @return list<string>
	 */
	private function codes( array $results ): array {
		return array_map( static fn( ProviderDiagnosticResult $result ): string => $result->code, $results );
	}

	/** @return list<string> */
	private function markerFiles( ?string $directory = null ): array {
		$directory = $directory ?? $this->directory;
		$files     = array();
		$iterator  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( str_starts_with( $item->getFilename(), '.ran-booster-diagnostic-' ) ) {
				$files[] = $item->getPathname();
			}
		}

		return $files;
	}

	/** @return list<string> */
	private function markerContents( string $directory ): array {
		$contents = array();
		$markers  = glob( $directory . '/.ran-booster-diagnostic-*' );
		foreach ( false === $markers ? array() : $markers as $marker ) {
			$contents[] = (string) file_get_contents( $marker );
		}

		return $contents;
	}
}
