<?php

declare(strict_types=1);

namespace Tests\Runtime;

require_once __DIR__ . '/Support/GitHubWorkflowAssistanceWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;

final class ReleaseManagementCutoverBootstrapTest extends TestCase {
	public function test_release_updater_is_bound_before_every_bootstrap_capture(): void {
		$bootstrap = $this->source( 'ran-booster.php' );

		$registration = strpos( $bootstrap, 'ReleaseUpdaterBootstrap::register();' );
		$core_target  = strpos( $bootstrap, 'ManagedReleaseUpdaterRegistrar::class )->plugin(' );
		$target       = strpos( $bootstrap, 'ManagedReleaseTargetRegistrar::class )->register()' );

		self::assertIsInt( $registration );
		self::assertIsInt( $core_target );
		self::assertIsInt( $target );
		self::assertLessThan( $core_target, $registration );
		self::assertLessThan( $target, $registration );
		self::assertStringNotContainsString( 'ReleaseUpdaterBootstrap::activate()', $bootstrap );
	}

	public function test_core_self_target_uses_the_selected_updater_without_provider_capability(): void {
		$bootstrap = $this->source( 'ran-booster.php' );

		$seal         = strpos( $bootstrap, '$provider_registry->seal()' );
		$policy_guard = strpos( $bootstrap, 'if ( $ran_booster_self_update_policy->allows_native_discovery() )' );
		$core_updater = strpos( $bootstrap, 'ManagedReleaseUpdaterRegistrar::class )->plugin(' );
		$repository   = strpos( $bootstrap, "'RocketsAreNostalgic/ran-booster'," );
		$adapter      = strpos( $bootstrap, 'new CoreSelfUpdateNativeTarget( $core_updater )' );
		$status_bind  = strpos( $bootstrap, 'new CoreSelfUpdateStatus( $ran_booster_self_update_policy, $core_release_target )' );

		self::assertIsInt( $seal );
		self::assertIsInt( $policy_guard );
		self::assertIsInt( $core_updater );
		self::assertIsInt( $repository );
		self::assertIsInt( $adapter );
		self::assertIsInt( $status_bind );
		self::assertLessThan( $policy_guard, $seal );
		self::assertLessThan( $core_updater, $policy_guard );
		self::assertLessThan( $repository, $core_updater );
		self::assertLessThan( $adapter, $repository );
		self::assertLessThan( $status_bind, $adapter );
		self::assertStringContainsString( "\t\t\t\t\t\t'github'", $bootstrap );
		self::assertStringContainsString( "\t\t\t\t\t\t'manual'", $bootstrap );
		self::assertStringContainsString( 'PackageArtifactLimit::resolve()', $bootstrap );
		self::assertStringNotContainsString( "requireCapability( 'gh', RepositoryReleaseNativeTargets::class )", $bootstrap );
		self::assertStringNotContainsString( 'new RepositoryReference(', $bootstrap );
		self::assertStringNotContainsString( "? 'forced-off' : 'disabled'", $bootstrap );
		self::assertStringNotContainsString( 'new GitHubReleaseNativeTarget(', $bootstrap );
	}

	public function test_current_orchestration_documentation_names_no_removed_handoff(): void {
		$guide     = $this->source( 'docs/package-update-orchestration.md' );
		$decisions = $this->source( 'docs/package-update-orchestration-decision-register.md' );

		self::assertStringNotContainsString( 'ran_wp_release_updater_v1_core_artifact_handoff', $guide );
		self::assertStringNotContainsString( 'ran_wp_github_release_updater_v1_core_reinstall_handoff', $guide );
		self::assertStringContainsString( '## 2026-08-23 PU-007 owner decision — REPLACE IN PLACE', $decisions );
		self::assertStringContainsString( '## 2026-09-01 PU-007 security correction — REMOVE', $decisions );
	}

	public function test_bundled_successor_registers_once_after_provider_seal(): void {
		$bootstrap = $this->source( 'ran-booster.php' );

		$provider_registration = strpos( $bootstrap, "do_action( 'ran_booster_register_providers'" );
		$provider_seal         = strpos( $bootstrap, '$provider_registry->seal()' );
		$release_controls      = strpos( $bootstrap, '$ran_booster_container->make( ReleaseManagementControls::class )->register();' );
		$workflow_controls     = strpos( $bootstrap, '$ran_booster_container->make( ReleaseWorkflowControls::class )->register();' );
		$runtime_init          = strpos( $bootstrap, '$ran_booster_runtime->init()' );

		self::assertIsInt( $provider_registration );
		self::assertIsInt( $provider_seal );
		self::assertIsInt( $runtime_init );
		self::assertLessThan( $provider_seal, $provider_registration );
		self::assertIsInt( $release_controls );
		self::assertIsInt( $workflow_controls );
		self::assertLessThan( $release_controls, $provider_seal );
		self::assertLessThan( $workflow_controls, $provider_seal );
		self::assertLessThan( $runtime_init, $release_controls );
		self::assertLessThan( $runtime_init, $workflow_controls );
		self::assertSame(
			1,
			preg_match_all( '/\$ran_booster_container->make\( ReleaseManagementControls::class \)->register\(\);/', $bootstrap )
		);
		self::assertSame(
			1,
			preg_match_all( '/\$ran_booster_container->make\( ReleaseWorkflowControls::class \)->register\(\);/', $bootstrap )
		);
		self::assertStringNotContainsString( 'GitHubReleaseUpdaterBootstrap', $bootstrap );
		self::assertStringNotContainsString( 'prospectiveApiVersion', $bootstrap );
		self::assertMatchesRegularExpression(
			'/ReleaseWorkflowControls::class \)->register\(\);[\s\S]*?PHP_INT_MAX/',
			$bootstrap
		);
	}

	public function test_hard_cut_removes_external_release_publications_and_prospective_marker(): void {
		$bootstrap = $this->source( 'ran-booster.php' );

		foreach ( array(
			'ran_booster_release_tracking_ready',
			'ran_booster_prospective_release_ready',
			'RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION',
			'RAN_BOOSTER_RELEASE_DEPLOYMENTS_RETIREMENT',
			'RAN_BOOSTER_RELEASE_MANAGEMENT_RETIREMENT',
		) as $retired_seam ) {
			self::assertStringNotContainsString( $retired_seam, $bootstrap );
		}

		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADDON_API_VERSION'\\s*,\\s*17\\s*\\)/",
			$bootstrap
		);
		self::assertStringContainsString( 'RAN Booster Add-on API 17 conflicts with an existing API version marker.', $bootstrap );
		self::assertStringNotContainsString( "RAN_BOOSTER_ADDON_API_VERSION', 15", $bootstrap );
	}

	public function test_cutover_ignores_obsolete_setup_record_without_migration_or_write(): void {
		$record  = $this->record();
		$records = array( '123456789' => $record );
		$GLOBALS['ran_booster_release_deployments_test_options']        = array(
			'ran_booster_release_deployments_setup_records' => $records,
		);
		$GLOBALS['ran_booster_release_deployments_test_option_updates'] = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact obsolete bytes are the clean-reset subject.
		$before = serialize( $records );

		self::assertNull( ( new SetupRecordStore() )->find( '123456789' ) );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_option_updates'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The provider must not migrate or rewrite obsolete state.
		self::assertSame( $before, serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] ) );
		self::assertCount( 1, $GLOBALS['ran_booster_release_deployments_test_options'] );

		$uninstall = $this->source( 'RAN/Uninstall/LocalDataRemover.php' );
		self::assertStringContainsString( 'WorkflowAssistanceState', $uninstall );
		self::assertStringNotContainsString( 'ran_booster_release_deployments_', $uninstall );
	}

	public function test_installed_release_capability_proof_is_automated_and_disposable(): void {
		$composer = json_decode( $this->source( 'composer.json' ), true );
		self::assertIsArray( $composer );
		self::assertSame(
			'bash tests/WordPress/release-capability-installed-smoke.sh',
			$composer['scripts']['test:release-capability-installed'] ?? null
		);

		$workflow = $this->source( '.github/workflows/quality.yml' );
		self::assertStringContainsString( 'Prove installed release capability lifecycle', $workflow );
		self::assertStringContainsString( 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_DISPOSABLE:', $workflow );
		self::assertStringContainsString( 'composer test:release-capability-installed', $workflow );

		$runner = $this->source( 'tests/WordPress/release-capability-installed-smoke.sh' );
		foreach ( array( '.ran-booster-disposable-test-site', 'RAN_BOOSTER_WORDPRESS_PATH', 'RAN_BOOSTER_RELEASE_CAPABILITY_TEST_URL', 'plugin_target', 'theme_target' ) as $guard ) {
			self::assertStringContainsString( $guard, $runner );
		}

		$proof = $this->source( 'tests/WordPress/release-capability-installed-smoke.php' );
		self::assertStringContainsString( "RAN Booster disposable test site\\n", $proof );
		self::assertSame( 2, substr_count( $proof, '->require_success()' ) );

		foreach ( array( 'native-lifecycle-installed-seed.php', 'native-lifecycle-installed-smoke.php', 'RAN_BOOSTER_NATIVE_LIFECYCLE_SCALE', 'native-lifecycle-installed-cleanup.php' ) as $native_contract ) {
			self::assertStringContainsString( $native_contract, $runner );
		}
		$native_proof = $this->source( 'tests/WordPress/native-lifecycle-installed-smoke.php' );
		foreach ( array( 'GitHubProvider', 'GitHubReleaseNativeTarget', 'ManagedReleaseTargetRegistrar', 'after_setup_theme', 'ran_booster_native_update_unsupported_context' ) as $native_contract ) {
			self::assertStringContainsString( $native_contract, $native_proof );
		}
	}

	private function source( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Direct local source-conformance read.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
		self::assertIsString( $source );

		return $source;
	}

	/** @return array<string,int|string> */
	private function record(): array {
		return array(
			'schema_version'        => 2,
			'operation'             => 'bootstrap',
			'repo_id'               => '123456789',
			'repository'            => 'RocketsAreNostalgic/example-plugin',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example-plugin/example-plugin.php',
			'source_revision'       => 3,
			'default_branch'        => 'main',
			'base_sha'              => str_repeat( 'a', 40 ),
			'setup_branch'          => 'ran-booster/release-setup-v2-aaaaaaaaaaaa-deadbeef',
			'head_sha'              => str_repeat( 'b', 40 ),
			'pr_number'             => 42,
			'profile_id'            => 'source-ready-wordpress-plugin/2',
			'template_repo_name'    => 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates',
			'template_repo_id'      => '1322743261',
			'template_release_id'   => 41,
			'template_tag'          => 'v1.2.3',
			'template_commit'       => str_repeat( 'c', 40 ),
			'template_asset_id'     => 73,
			'template_asset_name'   => 'ran-booster-release-bootstrap-templates.zip',
			'template_asset_size'   => 1000,
			'template_asset_digest' => str_repeat( 'd', 64 ),
			'manifest_digest'       => str_repeat( 'e', 64 ),
			'receipt_digest'        => str_repeat( 'f', 64 ),
			'consumer_api'          => 2,
			'pack_version'          => '1.2.3',
			'bundle_hash'           => str_repeat( '1', 64 ),
			'changed_path_hash'     => str_repeat( '2', 64 ),
		);
	}
}
