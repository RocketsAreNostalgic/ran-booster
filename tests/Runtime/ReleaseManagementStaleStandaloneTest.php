<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Runtime\Support\ReleaseManagementCutoverHookBus;
use Tests\Runtime\Support\StaleReleaseDeploymentsBeta8Fixture;

final class ReleaseManagementStaleStandaloneTest extends TestCase {
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testStaleBetaEightLoadedBeforeCoreGetsNoActionableAuthority(): void {
		$this->assertStaleStandaloneIsInert( true );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testStaleBetaEightLoadedAfterCoreGetsNoActionableAuthority(): void {
		$this->assertStaleStandaloneIsInert( false );
	}

	private function assertStaleStandaloneIsInert( bool $staleFirst ): void {
		$hooks   = new ReleaseManagementCutoverHookBus();
		$stale   = new StaleReleaseDeploymentsBeta8Fixture( $hooks );
		$records = array(
			'123456789' => array(
				'schema_version' => 2,
				'opaque'         => "preserve\0exact\xffbytes",
			),
		);
		$GLOBALS['ran_booster_release_deployments_test_options']        = array(
			'ran_booster_release_deployments_setup_records' => $records,
		);
		$GLOBALS['ran_booster_release_deployments_test_option_updates'] = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Raw option bytes are the cutover invariant.
		$before = serialize( $records );

		if ( $staleFirst ) {
			$stale->boot();
			$this->publishCoreAddOnApi();
		} else {
			$this->publishCoreAddOnApi();
			$stale->boot();
		}
		$hooks->fire( 'plugins_loaded' );

		self::assertSame( 16, RAN_BOOSTER_ADDON_API_VERSION );
		self::assertSame( array( 'plugins_loaded', 'admin_notices' ), $hooks->actionHooks() );
		self::assertSame( array(), $hooks->filterHooks() );
		foreach ( array(
			'ran_booster_release_tracking_ready',
			'ran_booster_prospective_release_ready',
			'admin_post_ran_booster_release_deployments_enable',
			'admin_post_ran_booster_release_deployments_refresh',
			'admin_post_ran_booster_release_deployments_return_to_branch',
			'admin_post_ran_booster_release_deployments_change_channel',
			'admin_post_ran_booster_release_deployments_install',
			'admin_post_ran_booster_release_deployments_workflow_inspect',
			'admin_post_ran_booster_release_deployments_workflow_setup',
			'admin_post_ran_booster_release_deployments_workflow_outcome',
			'admin_post_ran_booster_release_deployments_workflow_update_inspect',
			'admin_post_ran_booster_release_deployments_workflow_update_setup',
			'wp_ajax_ran_booster_release_deployments_list_candidates',
			'wp_ajax_ran_booster_release_deployments_inspect',
		) as $legacyHook ) {
			$hooks->fire( $legacyHook, new \stdClass() );
		}

		self::assertSame( 0, $stale->facades );
		self::assertSame( 0, $stale->targets );
		self::assertSame( 0, $stale->credentialReads );
		self::assertSame( 0, $stale->remoteCalls );
		self::assertSame( 0, $stale->mutations );
		self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_option_updates'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Raw option bytes are the cutover invariant.
		self::assertSame( $before, serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] ) );
	}

	private function publishCoreAddOnApi(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Direct local bootstrap contract read.
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );
		self::assertIsString( $bootstrap );
		self::assertSame(
			1,
			preg_match( "/define\\(\\s*'RAN_BOOSTER_ADDON_API_VERSION'\\s*,\\s*([0-9]+)\\s*\\)/", $bootstrap, $match )
		);
		self::assertArrayHasKey( 1, $match );

		define( 'RAN_BOOSTER_ADDON_API_VERSION', (int) $match[1] );
	}
}
