<?php

declare(strict_types=1);

namespace Tests\Runtime\Support;

/**
 * Exact compatibility and actionable-registration shape of standalone beta.8.
 *
 * The production add-on checks Add-on API 15 from its priority-five
 * plugins_loaded callback before registering any release facade receiver,
 * package contribution, route or AJAX endpoint.
 */
final class StaleReleaseDeploymentsBeta8Fixture {
	public const REQUIRED_ADDON_API = 15;

	public int $facades = 0;

	public int $targets = 0;

	public int $credentialReads = 0;

	public int $remoteCalls = 0;

	public int $mutations = 0;

	public function __construct( private readonly ReleaseManagementCutoverHookBus $hooks ) {
	}

	public function boot(): void {
		$this->hooks->addAction( 'plugins_loaded', array( $this, 'register' ), 5 );
		$this->hooks->addAction( 'admin_notices', static function (): void {} );
	}

	public function register(): void {
		$api = defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
			? constant( 'RAN_BOOSTER_ADDON_API_VERSION' )
			: null;
		if ( self::REQUIRED_ADDON_API !== $api ) {
			return;
		}

		$this->hooks->addAction(
			'ran_booster_release_tracking_ready',
			function (): void {
				++$this->facades;
				++$this->targets;
			}
		);
		$this->hooks->addAction(
			'ran_booster_prospective_release_ready',
			function (): void {
				++$this->facades;
			}
		);
		foreach ( array(
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
		) as $hook ) {
			$this->hooks->addAction(
				$hook,
				function (): void {
					++$this->credentialReads;
					++$this->remoteCalls;
					++$this->mutations;
				}
			);
		}

		foreach ( array(
			'ran_booster_admin_package_management_rows',
			'ran_booster_admin_package_management_actions',
			'ran_booster_admin_package_source_choices',
			'ran_booster_admin_package_advanced_source_summary',
			'ran_booster_documentation_sections_before_about',
		) as $hook ) {
			$this->hooks->addFilter( $hook, static fn ( mixed $value ): mixed => $value );
		}
	}
}
