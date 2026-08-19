<?php

declare(strict_types=1);

namespace Tests\Runtime;

require_once dirname( __DIR__ ) . '/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowAssistanceTestBootstrap.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;

final class ReleaseManagementCutoverBootstrapTest extends TestCase {
	public function testReleaseUpdaterIsBoundBeforeEveryBootstrapCapture(): void {
		$bootstrap = $this->source( 'ran-booster.php' );

		$localBinding = strpos( $bootstrap, '$ran_booster_release_updater            = GitHubReleaseUpdaterBootstrap::register(' );
		$globalMirror = strpos( $bootstrap, '$GLOBALS[\'ran_booster_release_updater\'] = $ran_booster_release_updater;' );
		$outerCapture = strpos( $bootstrap, 'static function () use ( $ran_booster_core_development_notice, $ran_booster_release_updater, $ran_booster_self_update_policy ): void {' );
		$innerCapture = strpos( $bootstrap, 'static function () use ( $ran_booster_container, $ran_booster_runtime, $ran_booster_release_updater ): void {' );
		$lateCapture  = strpos( $bootstrap, 'static function () use ( $ran_booster_container, $ran_booster_release_updater ): void {' );
		$apiGate      = strpos( $bootstrap, 'GitHubReleaseUpdaterBootstrap::prospectiveApiVersion( $ran_booster_release_updater )' );

		self::assertIsInt( $localBinding );
		self::assertIsInt( $globalMirror );
		self::assertIsInt( $outerCapture );
		self::assertIsInt( $innerCapture );
		self::assertIsInt( $lateCapture );
		self::assertIsInt( $apiGate );
		self::assertLessThan( $globalMirror, $localBinding );
		self::assertLessThan( $outerCapture, $globalMirror );
		self::assertLessThan( $innerCapture, $outerCapture );
		self::assertLessThan( $lateCapture, $innerCapture );
		self::assertLessThan( $apiGate, $lateCapture );
	}

	public function testBundledSuccessorRegistersOnceAfterProviderSeal(): void {
		$bootstrap = $this->source( 'ran-booster.php' );

		$providerRegistration = strpos( $bootstrap, "do_action( 'ran_booster_register_providers'" );
		$providerSeal         = strpos( $bootstrap, '$providerRegistry->seal()' );
		$releaseControls      = strpos( $bootstrap, '$ran_booster_container->make( ReleaseManagementControls::class )->register();' );
		$workflowControls     = strpos( $bootstrap, '$ran_booster_container->make( GitHubReleaseWorkflowControls::class )->register();' );
		$runtimeInit          = strpos( $bootstrap, '$ran_booster_runtime->init()' );

		self::assertIsInt( $providerRegistration );
		self::assertIsInt( $providerSeal );
		self::assertIsInt( $releaseControls );
		self::assertIsInt( $workflowControls );
		self::assertIsInt( $runtimeInit );
		self::assertLessThan( $providerSeal, $providerRegistration );
		self::assertLessThan( $releaseControls, $providerSeal );
		self::assertLessThan( $workflowControls, $providerSeal );
		self::assertLessThan( $runtimeInit, $releaseControls );
		self::assertLessThan( $runtimeInit, $workflowControls );
		self::assertSame(
			1,
			preg_match_all( '/\$ran_booster_container->make\( ReleaseManagementControls::class \)->register\(\);/', $bootstrap )
		);
		self::assertSame(
			1,
			preg_match_all( '/\$ran_booster_container->make\( GitHubReleaseWorkflowControls::class \)->register\(\);/', $bootstrap )
		);
		self::assertStringContainsString( 'GitHubReleaseUpdaterBootstrap::prospectiveApiVersion( $ran_booster_release_updater )', $bootstrap );
		self::assertMatchesRegularExpression(
			'/GitHubReleaseWorkflowControls::class \)->register\(\);[\s\S]*?PHP_INT_MAX/',
			$bootstrap
		);
	}

	public function testHardCutRemovesExternalReleasePublicationsAndProspectiveMarker(): void {
		$bootstrap = $this->source( 'ran-booster.php' );

		foreach ( array(
			'ran_booster_release_tracking_ready',
			'ran_booster_prospective_release_ready',
			'RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION',
			'RAN_BOOSTER_RELEASE_DEPLOYMENTS_RETIREMENT',
			'RAN_BOOSTER_RELEASE_MANAGEMENT_RETIREMENT',
		) as $retiredSeam ) {
			self::assertStringNotContainsString( $retiredSeam, $bootstrap );
		}

		self::assertMatchesRegularExpression(
			"/define\\(\\s*'RAN_BOOSTER_ADDON_API_VERSION'\\s*,\\s*16\\s*\\)/",
			$bootstrap
		);
		self::assertStringContainsString( 'RAN Booster Add-on API 16 conflicts with an existing API version marker.', $bootstrap );
		self::assertStringNotContainsString( "RAN_BOOSTER_ADDON_API_VERSION', 15", $bootstrap );
	}

	public function testCutoverPreservesExactSetupRecordBytesWithoutMigrationOrWrite(): void {
		$record  = $this->record();
		$records = array( '123456789' => $record );
		$GLOBALS['ran_booster_release_deployments_test_options']        = array(
			'ran_booster_release_deployments_setup_records' => $records,
		);
		$GLOBALS['ran_booster_release_deployments_test_option_updates'] = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact ordered scalar-array bytes are the compatibility subject.
		$before = serialize( $records );

		$readback = ( new SetupRecordStore() )->find( '123456789' );

		self::assertSame( $record, $readback );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_option_updates'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact ordered scalar-array bytes are the compatibility subject.
		self::assertSame( $before, serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] ) );

		$bootstrap = $this->source( 'ran-booster.php' );
		self::assertStringNotContainsString( 'ran_booster_release_deployments_setup_records', $bootstrap );
		self::assertStringNotContainsString( 'delete_option', $bootstrap );
	}

	public function testInstalledReleaseCapabilityProofIsAutomatedAndDisposable(): void {
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
		self::assertSame( 2, substr_count( $proof, '->requireSuccess()' ) );
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
