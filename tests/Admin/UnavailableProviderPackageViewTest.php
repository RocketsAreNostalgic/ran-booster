<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused package fake stays beside its view tests.

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminActionRenderer.php';

use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\PackagePagePresenter;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageSource;

final class UnavailableProviderPackageViewTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$_GET                                       = array();
		$_POST                                      = array();
		$GLOBALS['ran_booster_bulk_active_plugins'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset( $GLOBALS['ran_booster_bulk_active_plugins'] );
	}

	public function test_edit_view_preserves_unknown_provider_identity_and_keeps_confirmed_removal_available(): void {
		$package                   = $this->package();
		$package_view              = PackagePagePresenter::plugin();
		$package_provider_settings = array(
			'default_provider' => 'temporarily-offline',
			'providers'        => array(
				$this->provider_option( 'gh', true ),
				$this->provider_option( 'temporarily-offline', false ),
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<strong>Provider unavailable.</strong>', $html );
		self::assertStringContainsString( '<code>temporarily-offline</code>', $html );
		self::assertStringContainsString( '<code>owner/exact-repository</code>', $html );
		self::assertStringContainsString( '<code>stable-provider-id</code>', $html );
		self::assertStringContainsString( '<fieldset  disabled="disabled"', $html );
		self::assertStringContainsString( 'temporarily-offline — Provider unavailable', $html );
		self::assertStringNotContainsString( 'href="https://github.com/owner/exact-repository"', $html );
		self::assertStringContainsString( 'id="ran-booster-package-edit-form"', $html );
		self::assertStringContainsString( 'form="ran-booster-package-edit-form"', $html );
		self::assertStringContainsString( 'ran-booster-settings-actions', $html );
		self::assertStringContainsString( 'ran-booster-package-danger-zone', $html );
		self::assertStringContainsString( 'value="unlink-plugin"', $html );
		self::assertStringContainsString( 'value="unlink-delete-plugin"', $html );
		self::assertSame( 2, substr_count( $html, 'name="ran_booster[confirm_package_removal]" value="1" required' ) );
		self::assertSame( 3, substr_count( $html, 'name="ran_booster[expected_source_revision]" value="1"' ) );
		self::assertStringContainsString( 'name="_ran_booster_reinstall_nonce"', $html );
	}

	public function test_list_view_labels_unavailable_provider_and_disables_only_deployment_action(): void {
		$GLOBALS['ran_booster_bulk_active_plugins'] = array( 'exact/exact.php' );
		$packages                                   = array( $this->package() );
		$package_view                               = PackagePagePresenter::plugin();
		$package_providers                          = array(
			array(
				'code'      => 'gh',
				'label'     => 'GitHub',
				'available' => true,
				'deploy'    => true,
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'owner/exact-repository', $html );
		self::assertStringContainsString( 'temporarily-offline', $html );
		self::assertStringContainsString( 'ran-booster-badge--error">Provider unavailable</span>', $html );
		self::assertStringContainsString( 'stable-provider-id', $html );
		self::assertStringContainsString( 'button-update-package"  disabled="disabled"', $html );
		foreach ( array(
			'expected_provider'               => 'temporarily-offline',
			'expected_provider_repository_id' => 'stable-provider-id',
			'expected_repository'             => 'owner/exact-repository',
			'expected_branch'                 => 'release',
			'expected_credential_id'          => '',
			'expected_subdirectory'           => '',
			'expected_private'                => '0',
			'expected_package_slug'           => 'exact',
			'expected_deployment_policy'      => 'automatic',
			'expected_source'                 => 'branch',
			'expected_source_revision'        => '1',
		) as $field => $value ) {
			self::assertStringContainsString(
				'name="ran_booster[' . $field . ']" value="' . $value . '"',
				$html
			);
		}
		self::assertStringContainsString( '>Edit settings</a>', $html );
		self::assertStringNotContainsString( '>Unlink plugin</button>', $html );
		self::assertStringNotContainsString( 'name="ran_booster[action]" value="unlink-plugin"', $html );
		self::assertStringContainsString( 'data-ran-booster-bulk-form', $html );
		self::assertStringContainsString( 'name="ran_booster[identifiers][]"', $html );
		self::assertStringContainsString( 'value="exact/exact.php"', $html );
		self::assertStringContainsString( 'form="ran-booster-plugin-bulk-form"', $html );
		self::assertStringContainsString( 'value="activate-plugins">Enable in WordPress</option>', $html );
		self::assertStringContainsString( 'value="deactivate-plugins">Disable in WordPress</option>', $html );
		self::assertStringContainsString( 'ran-booster-package-row__wordpress-state', $html );
		self::assertSame( 2, substr_count( $html, 'ran-booster-package-row--wordpress-active' ) );
		self::assertStringContainsString( '>WordPress</span>', $html );
		self::assertStringContainsString( '>Enabled</span>', $html );
		self::assertStringContainsString( 'ran-booster-package-row__update-state is-automatic', $html );
		self::assertStringContainsString( '>Automatic</span>', $html );
		self::assertStringContainsString( 'Branch · release', $html );
		self::assertStringNotContainsString( 'Branch · release · Push-to-Deploy', $html );
		self::assertStringContainsString( 'The saved provider is unavailable.', $html );
		self::assertSame( 1, substr_count( $html, '<span class="ran-booster-badge ' ) );
		self::assertStringContainsString( 'Review package health, deploy saved branches', $html );
		self::assertStringContainsString( 'Deployment activity', $html );
	}

	public function test_list_view_shows_configured_private_credential_and_bounded_activity_truth(): void {
		$package           = $this->package( true, 'agency_profile' );
		$packages          = array( $package );
		$package_view      = PackagePagePresenter::plugin();
		$package_providers = array( $this->package_list_provider() );
		$package_activity  = array(
			'items'       => array(
				(string) $package->get_identifier() => array(
					'latest'          => $this->attempt( 2, 'failed', null ),
					'last_successful' => $this->attempt( 1, 'succeeded', str_repeat( 'a', 40 ) ),
				),
			),
			'unavailable' => false,
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/ran-booster-badge--error"[^>]*>Failed<\/span>/', $html );
		self::assertSame( 2, substr_count( $html, '<span class="ran-booster-badge ' ) );
		self::assertStringContainsString( 'Last activity', $html );
		self::assertStringContainsString( '>Failed</span>', $html );
		self::assertStringNotContainsString( str_repeat( 'a', 40 ), $html );
		self::assertStringContainsString( '2026-07-19 00:00:00', $html );
		self::assertSame( 1, substr_count( $html, 'View details' ) );
		self::assertStringNotContainsString( 'button-update-package"  disabled="disabled"', $html );
	}

	public function test_list_view_disables_private_deployment_when_the_local_credential_identity_is_missing(): void {
		$packages          = array( $this->package( true, 'missing_profile' ) );
		$package_view      = PackagePagePresenter::plugin();
		$package_providers = array( $this->package_list_provider() );
		$package_activity  = array(
			'items'       => array(),
			'unavailable' => false,
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'ran-booster-badge--error">Credential unavailable</span>', $html );
		self::assertSame( 1, substr_count( $html, '<span class="ran-booster-badge ' ) );
		self::assertStringContainsString( 'The saved repository credential is unavailable.', $html );
		self::assertStringContainsString( 'data-ran-booster-update-label>Reinstall</span>', $html );
		self::assertStringContainsString( 'button-update-package"  disabled="disabled"', $html );
		self::assertStringContainsString( 'ran-booster-package-row__wordpress-state is-disabled', $html );
		self::assertStringContainsString( '>WordPress</span>', $html );
		self::assertStringContainsString( '>Disabled</span>', $html );
	}

	public function test_disabled_automation_suppresses_historical_deployment_status(): void {
		$package = $this->package();
		$package->set_deployment_policy( DeploymentPolicy::DISABLED );
		$GLOBALS['ran_booster_bulk_active_plugins'] = array( 'exact/exact.php' );

		$packages          = array( $package );
		$package_view      = PackagePagePresenter::plugin();
		$package_providers = array( $this->package_list_provider() );
		$package_activity  = array(
			'items'       => array(
				(string) $package->get_identifier() => array(
					'latest'          => $this->attempt( 2, 'failed', null ),
					'last_successful' => null,
				),
			),
			'unavailable' => false,
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Deployment: Failed', $html );
		self::assertSame( 2, substr_count( $html, 'ran-booster-package-row--wordpress-active' ) );
		self::assertStringContainsString( 'ran-booster-package-row__update-state is-disabled', $html );
		self::assertStringContainsString( '>Updates</span>', $html );
		self::assertStringContainsString( '>Disabled</span>', $html );
		self::assertStringContainsString( 'Branch · release', $html );
		self::assertStringNotContainsString( 'Branch · release · Disabled', $html );
		self::assertStringContainsString( 'Booster will not overwrite this package', $html );
		self::assertStringContainsString( 'Provider', $html );
		self::assertStringContainsString( 'Access', $html );
		self::assertStringContainsString( '>Version</dt>', $html );
		self::assertStringContainsString( 'Last activity', $html );
		self::assertStringContainsString( '>Failed</span>', $html );
		self::assertStringContainsString( 'View details', $html );
		self::assertStringNotContainsString( 'Last successful revision', $html );
		self::assertStringContainsString( 'Last succeeded', $html );
		self::assertStringContainsString( 'ran-booster-package-row__update-form', $html );
		self::assertStringContainsString( 'data-ran-booster-update-label>Reinstall</span>', $html );
		self::assertStringContainsString( 'button-update-package"  disabled="disabled"', $html );
		self::assertStringContainsString( 'data-update-can-run="0"', $html );
		self::assertStringContainsString( '>Edit settings</a>', $html );
		self::assertMatchesRegularExpression(
			'/data-ran-booster-package-checkbox(?![^>]*disabled="disabled")[^>]*>/',
			$html
		);
		self::assertStringContainsString( 'data-package-type-label="plugins"', $html );
		self::assertStringContainsString( '0 plugins selected', $html );
	}

	public function test_manual_automation_remains_independent_from_word_press_activation(): void {
		$package = $this->package();
		$package->set_deployment_policy( DeploymentPolicy::MANUAL );
		$packages          = array( $package );
		$package_view      = PackagePagePresenter::plugin();
		$package_providers = array( $this->package_list_provider() );
		$package_activity  = array(
			'items'       => array(),
			'unavailable' => false,
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'ran-booster-package-row--wordpress-active', $html );
		self::assertStringContainsString( 'ran-booster-package-row__wordpress-state is-disabled', $html );
		self::assertStringContainsString( 'ran-booster-package-row__update-state is-manual', $html );
		self::assertStringContainsString( '>Manual</span>', $html );
		self::assertStringContainsString( 'Branch · release', $html );
		self::assertStringNotContainsString( 'Branch · release · Manual', $html );
	}

	public function test_theme_list_does_not_offer_or_display_plugin_activation_controls(): void {
		$packages          = array( $this->package() );
		$package_view      = PackagePagePresenter::theme();
		$package_providers = array( $this->package_list_provider() );
		$package_activity  = array(
			'items'       => array(),
			'unavailable' => false,
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'value="activate-plugins"', $html );
		self::assertStringNotContainsString( 'value="deactivate-plugins"', $html );
		self::assertStringNotContainsString( 'ran-booster-package-row__wordpress-state', $html );
		self::assertStringNotContainsString( '>WordPress</span>', $html );
		self::assertStringContainsString( 'ran-booster-package-row__update-state is-automatic', $html );
		self::assertStringContainsString( '>Updates</span>', $html );
		self::assertStringContainsString( '>Automatic</span>', $html );
		self::assertStringNotContainsString( 'ran-booster-package-row--wordpress-active', $html );
		self::assertStringContainsString( 'Branch · release', $html );
	}

	public function test_release_managed_edit_view_keeps_core_and_add_on_forms_separate(): void {
		$package = $this->package();
		$package->set_source( PackageSource::RELEASE_ASSET, 2 );
		$package_view              = PackagePagePresenter::plugin();
		$package_provider_settings = array(
			'default_provider' => 'temporarily-offline',
			'providers'        => array( $this->provider_option( 'temporarily-offline', true ) ),
		);
		$package_extension_panels  = array(
			'<section><h3>Published releases</h3><form method="POST"><input name="ran_booster_release_deployments[action]" value="return_to_branch"><button>Release to branch deploys</button></form></section>',
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Update source unavailable', $html );
		self::assertStringNotContainsString( 'data-ran-booster-advanced-source-settings', $html );
		self::assertStringContainsString( 'Current source', $html );
		self::assertStringContainsString( 'Updates', $html );
		self::assertStringNotContainsString( 'id="ran-booster-package-edit-form"', $html );
		self::assertStringNotContainsString( 'id="ran-booster-provider"', $html );
		self::assertStringContainsString( 'Published releases', $html );
		self::assertStringNotContainsString( 'Release controls are unavailable', $html );
		self::assertStringContainsString( 'ran_booster_release_deployments[action]', $html );
		self::assertSame( 3, substr_count( $html, '<form' ) );
		self::assertStringContainsString( 'value="unlink-plugin"', $html );
		self::assertStringContainsString( 'value="unlink-delete-plugin"', $html );

		$package_extension_panels = array();
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$without_add_on = (string) ob_get_clean();

		self::assertStringContainsString(
			'Update source unavailable',
			$without_add_on
		);
		self::assertStringNotContainsString( 'release deployments add-on', $without_add_on );
		self::assertSame( 2, substr_count( $without_add_on, '<form' ) );
		self::assertStringContainsString( 'value="unlink-plugin"', $without_add_on );
		self::assertStringContainsString( 'value="unlink-delete-plugin"', $without_add_on );
	}

	public function test_release_managed_edit_routes_webhook_management_to_the_repository_page(): void {
		$package = $this->package();
		$package->set_source( PackageSource::RELEASE_ASSET, 2 );
		$package_view              = PackagePagePresenter::plugin();
		$package_provider_settings = array(
			'default_provider' => 'temporarily-offline',
			'providers'        => array( $this->provider_option( 'temporarily-offline', true ) ),
		);
		$package_source            = array(
			'current'     => PackageSource::RELEASE_ASSET->value,
			'selected'    => PackageSource::BRANCH->value,
			'unavailable' => false,
		);
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'panel=repositories&amp;repository=stable-provider-id&amp;repository_view=branch', $html );
		self::assertStringContainsString( '>Manage webhooks</a>', $html );

		$package_source['selected'] = PackageSource::RELEASE_ASSET->value;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$release_view = (string) ob_get_clean();
		self::assertStringNotContainsString( 'Webhook setup', $release_view );
		self::assertStringContainsString( '>Manage webhooks</a>', $release_view );
	}

	public function test_release_managed_list_uses_the_existing_deployment_position_and_read_only_summary(): void {
		$package = $this->package();
		$package->set_source( PackageSource::RELEASE_ASSET, 2 );
		$packages                  = array( $package );
		$package_view              = PackagePagePresenter::plugin();
		$package_providers         = array( $this->package_list_provider() );
		$package_activity          = array(
			'items'       => array(
				'exact/exact.php' => array(
					'latest'          => $this->attempt( 4, 'queued', null ),
					'last_successful' => null,
				),
			),
			'unavailable' => false,
		);
		$package_extension_rows    = array(
			'exact/exact.php' => array(
				'badges' => array(
					array(
						'label' => 'Release available',
						'tone'  => 'ok',
					),
				),
				'status' => 'Installed 1.0.0; latest <1.1.0>.',
			),
		);
		$package_extension_actions = array(
			'exact/exact.php' => array(
				'fixture:manage'  => array(
					'key'           => 'fixture:manage',
					'label'         => 'Manage releases',
					'type'          => 'link',
					'url'           => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=exact%2Fexact.php',
					'hidden'        => array(),
					'disabled'      => false,
					'external'      => false,
					'described_by'  => '',
					'screen_reader' => '',
				),
				'fixture:refresh' => array(
					'key'           => 'fixture:refresh',
					'label'         => 'Check published releases',
					'type'          => 'post',
					'url'           => 'https://example.test/wp-admin/admin-post.php',
					'hidden'        => array(
						'action'                   => 'fixture_refresh_release',
						'_wpnonce'                 => 'release-nonce',
						'expected_type'            => 'plugin',
						'expected_identifier'      => 'exact/exact.php',
						'expected_source_revision' => '2',
					),
					'disabled'      => false,
					'external'      => false,
					'described_by'  => '',
					'screen_reader' => '',
				),
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Releases', $html );
		self::assertStringContainsString( 'Filter by update source', $html );
		self::assertStringContainsString( 'All update sources', $html );
		self::assertMatchesRegularExpression( '/<option value="release_asset"\s*>Releases<\/option>/', $html );
		self::assertStringContainsString( 'ran-booster-package-row__update-state is-automatic', $html );
		self::assertStringContainsString( '>Automatic</span>', $html );
		self::assertStringNotContainsString( 'Published releases · WordPress automatic updates', $html );
		self::assertStringContainsString( 'Release available', $html );
		self::assertStringContainsString( 'Installed 1.0.0; latest &lt;1.1.0&gt;.', $html );
		self::assertStringContainsString( 'Manage releases', $html );
		self::assertStringContainsString( 'action="https://example.test/wp-admin/admin-post.php"', $html );
		self::assertStringContainsString( 'name="action" value="fixture_refresh_release"', $html );
		self::assertStringContainsString( 'name="expected_source_revision" value="2"', $html );
		self::assertMatchesRegularExpression( '/Check published releases(?:<\/span>)?\s*<\/button>/', $html );
		self::assertStringNotContainsString( 'data-ran-booster-update-label>Reinstall queued</span>', $html );
		self::assertStringNotContainsString( 'update-core.php', $html );
		self::assertStringNotContainsString( 'ran-booster-package-row__update-form', $html );
		self::assertStringNotContainsString( 'data-update-can-run', $html );
		self::assertStringNotContainsString( 'data-ran-booster-package-progress', $html );
		self::assertStringNotContainsString( 'Deployment activity', $html );
		self::assertStringContainsString( 'Release details', $html );
		self::assertSame( 0, substr_count( $html, 'button-update-package' ) );
		self::assertSame( 1, substr_count( $html, '<span class="ran-booster-badge ' ) );
	}

	public function test_list_view_makes_active_and_needs_attention_attempts_explicit(): void {
		foreach ( array(
			'queued'          => array( 'Reinstall queued', true ),
			'running'         => array( 'Reinstall in progress…', true ),
			'needs_attention' => array( 'Needs attention', false ),
		) as $state => $expectation ) {
			list( , $spins )   = $expectation;
			$package           = $this->package( true, 'agency_profile' );
			$packages          = array( $package );
			$package_view      = PackagePagePresenter::plugin();
			$package_providers = array( $this->package_list_provider() );
			$package_activity  = array(
				'items'       => array(
					(string) $package->get_identifier() => array(
						'latest'          => $this->attempt( 3, $state, null ),
						'last_successful' => null,
					),
				),
				'unavailable' => false,
			);

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/index.php';
			$html = (string) ob_get_clean();

			self::assertStringContainsString( 'disabled="disabled"', $html );
			self::assertStringContainsString( 'data-ran-booster-update-label>Reinstall</span>', $html );
			self::assertSame( $spins, str_contains( $html, 'ran-booster-update-is-active' ) );
		}
	}

	public function test_resolved_needs_attention_history_does_not_disable_abranch_retry(): void {
		$package           = $this->package( true, 'agency_profile' );
		$packages          = array( $package );
		$package_view      = PackagePagePresenter::plugin();
		$package_providers = array( $this->package_list_provider() );
		$package_activity  = array(
			'items'       => array(
				(string) $package->get_identifier() => array(
					'latest'          => $this->attempt( 5, 'needs_attention', null, true ),
					'last_successful' => null,
				),
			),
			'unavailable' => false,
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( '>Deployment: Needs attention</span>', $html );
		self::assertStringContainsString( '>Needs attention</span>', $html );
		self::assertStringContainsString( 'data-ran-booster-update-label>Reinstall</span>', $html );
		self::assertStringNotContainsString( 'button-update-package"  disabled="disabled"', $html );
	}

	public function test_repository_field_explains_provider_owned_nested_locators(): void {
		$package_view              = PackagePagePresenter::plugin();
		$repository_value          = 'group/subgroup/example-plugin';
		$provider_browse_available = false;
		$release_managed           = false;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/repository.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Repository locator supplied by the selected provider.', $html );
		self::assertStringNotContainsString( 'Repository account and name', $html );
		self::assertStringContainsString( 'name="ran_booster[repository]"', $html );
		self::assertMatchesRegularExpression( '/ran-booster-open-repository-picker"[^>]* hidden\s+disabled="disabled"/', $html );
	}

	public function test_repository_picker_remains_visible_for_browsing_providers(): void {
		$package_view              = PackagePagePresenter::plugin();
		$repository_value          = 'owner/example-plugin';
		$provider_browse_available = true;
		$release_managed           = false;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/repository.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="ran_booster[repository]"', $html );
		self::assertStringContainsString( '>Pick Plugin repository</button>', $html );
		self::assertStringNotContainsString( 'hidden disabled="disabled"', $html );
	}

	public function test_deployment_policy_disables_automatic_without_webhook_support(): void {
		$deployment_policy                = DeploymentPolicy::AUTOMATIC->value;
		$provider_webhook_available       = false;
		$provider_code                    = 'temporarily-offline';
		$development_environment_detected = false;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/deployment-policy.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="ran-booster-deployment-policy-input"', $html );
		self::assertMatchesRegularExpression( '/value="automatic"[^>]*disabled="disabled"/', $html );
		self::assertStringContainsString( 'Automatic deployment is unavailable', $html );
		self::assertStringNotContainsString( 'data-ran-booster-local-development-warning', $html );
		self::assertStringNotContainsString( 'Editing this package’s files on this site? Choose Disabled.', $html );
	}

	public function test_deployment_policy_enables_automatic_for_webhook_providers(): void {
		$deployment_policy                = DeploymentPolicy::AUTOMATIC->value;
		$provider_webhook_available       = true;
		$provider_code                    = 'gh';
		$development_environment_detected = true;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/deployment-policy.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/value="automatic"[^>]*selected="selected"/', $html );
		self::assertDoesNotMatchRegularExpression( '/value="automatic"[^>]*disabled="disabled"/', $html );
		self::assertStringContainsString( 'class="notice notice-warning inline"', $html );
		self::assertStringContainsString( 'data-ran-booster-local-development-warning', $html );
		self::assertStringContainsString( 'Editing this package’s files on this site? Choose Disabled.', $html );
		self::assertStringContainsString( 'Push-to-Deploy setting', $html );
		self::assertStringContainsString( 'Manual and Automatic can overwrite local changes when an update or reinstall runs.', $html );
		self::assertStringContainsString( 'tab=gh#ran-booster-webhook-secrets-heading', $html );
	}

	public function test_deployment_policy_hides_local_warning_when_automation_is_disabled(): void {
		$deployment_policy                = DeploymentPolicy::DISABLED->value;
		$provider_webhook_available       = true;
		$provider_code                    = 'gh';
		$development_environment_detected = true;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/deployment-policy.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-local-development-warning hidden', $html );
		self::assertStringContainsString( 'Editing this package’s files on this site? Choose Disabled.', $html );
	}

	public function test_deployment_policy_offers_automatic_word_press_updates_for_published_releases(): void {
		$deployment_policy                = DeploymentPolicy::AUTOMATIC->value;
		$provider_webhook_available       = false;
		$provider_code                    = 'gh';
		$package_automation_source        = 'release_asset';
		$development_environment_detected = false;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/deployment-policy.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/value="automatic"[^>]*selected="selected"/', $html );
		self::assertDoesNotMatchRegularExpression( '/value="automatic"[^>]*disabled="disabled"/', $html );
		self::assertStringContainsString( 'Automatic — install validated releases through WordPress Updates', $html );
		self::assertStringContainsString( 'Manual waits for an administrator', $html );
		self::assertStringNotContainsString( 'Push-to-Deploy setting', $html );
	}

	/** @return array<string, mixed> */
	private function provider_option( string $code, bool $available ): array {
		return array(
			'code'                  => $code,
			'label'                 => $code,
			'owner_label'           => 'Owner',
			'repository_url_base'   => $available ? 'https://github.com/' : '',
			'available'             => $available,
			'browse'                => $available,
			'deploy'                => $available,
			'webhooks'              => $available,
			'default_credential_id' => '',
			'credential_profiles'   => array(),
		);
	}

	/** @return array<string, mixed> */
	private function package_list_provider(): array {
		return array(
			'code'                  => 'temporarily-offline',
			'label'                 => 'Fixture provider',
			'available'             => true,
			'deploy'                => true,
			'default_credential_id' => '',
			'credentials'           => array(
				array(
					'id'     => 'agency_profile',
					'label'  => 'Agency deployment credential',
					'source' => 'file',
				),
			),
		);
	}

	private function attempt( int $id, string $state, ?string $resolved_ref, bool $resolved = false ): DeploymentAttempt {
		$terminal = in_array( $state, array( 'succeeded', 'failed', 'needs_attention' ), true );
		$outcome  = match ( $state ) {
			'succeeded' => 'deployed',
			'failed' => 'preflight_failed',
			'needs_attention' => 'interrupted',
			default => null,
		};

		return DeploymentAttempt::from_database(
			array(
				'id'                      => $id,
				'correlation_id'          => str_pad( dechex( $id ), 32, '0', STR_PAD_LEFT ),
				'source'                  => 'manual',
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'package_slug'            => 'exact',
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'release_identity'        => null,
				'provider'                => 'temporarily-offline',
				'provider_repository_id'  => 'stable-provider-id',
				'requested_ref'           => 'release',
				'resolved_ref'            => $resolved_ref,
				'delivery_id'             => null,
				'delivery_digest'         => null,
				'state'                   => $state,
				'mutation_started_at'     => null,
				'outcome_code'            => $outcome,
				'request_json'            => '{"repository":"owner/exact-repository","credential_id":"agency_profile","private":true,"configured_branch":"release","package_slug":"exact","subdirectory":null,"deployment_policy":"automatic","initiating_user_id":1,"maximum_artifact_bytes":52428800}',
				'created_at'              => '2026-07-19 00:00:00',
				'finished_at'             => $terminal ? '2026-07-19 00:00:00' : null,
				'resolved_at'             => $resolved ? '2026-07-19 00:05:00' : null,
				'resolved_by'             => $resolved ? 7 : null,
			)
		);
	}

	private function package( bool $private = false, string $credential_id = '' ): UnavailableProviderPackage {
		$repository = new ManagedRepository(
			'temporarily-offline',
			'owner/exact-repository',
			'stable-provider-id',
			'release',
			$private,
			'' === $credential_id ? null : $credential_id
		);

		$package = new UnavailableProviderPackage();
		$package->set_repository( $repository );
		$package->set_deployment_policy( DeploymentPolicy::AUTOMATIC );

		return $package;
	}
}

final class UnavailableProviderPackage extends AbstractPackage {
	public string $name = 'Exact package';

	public function get_identifier(): mixed {
		return 'exact/exact.php';
	}

	protected function runtime_slug(): string {
		return 'exact';
	}
}
