<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';
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

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_list_uses_presented_credentials_without_exposing_configuration_or_secrets(): void {
		$provider = new \RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider( 'temporarily-offline' );
		$registry = new \RAN\RepositoryProvider\ProviderRegistry( array( $provider ) );
		foreach ( array(
			array( array(), '', 'Private; credential missing' ),
			array( array( 'file' ), '', 'Private via Label &lt;0&gt;' ),
			array( array( 'file', 'file' ), '', 'Private; credential missing' ),
			array( array( 'file', 'file' ), 'profile_1', 'Private via Label &lt;1&gt;' ),
			array( array( 'file', 'constant' ), '', 'Private via Label &lt;1&gt;' ),
			array( array( 'constant' ), 'missing', 'Private; credential missing' ),
		) as list( $sources, $selected, $expected ) ) {
			$profiles = array();
			foreach ( $sources as $index => $source ) {
				$profiles[] = array(
					'id'            => 'profile_' . $index,
					'label'         => 'Label <' . $index . '>',
					'kind'          => 'api-key',
					'configuration' => array( 'tenant' => 'configuration-canary' ),
					'source'        => $source,
					'configured'    => true,
				);
			}
			$secrets = $this->createMock( \RAN\Secrets\SecretsFile::class );
			$secrets->expects( self::once() )->method( 'credential_profiles' )->with( 'temporarily-offline' )->willReturn( $profiles );
			$secrets->expects( self::never() )->method( 'credential_material' );
			$settings          = new \RAN\Admin\ProviderSettingsPresenter(
				$registry,
				$secrets,
				new \RAN\Storage\CredentialUsageReader( new \RAN\Tests\Support\CredentialUsageDatabase(), 'wp_ran_booster_packages' ),
				new \RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore(),
				new \RAN\Tests\Support\InMemoryCredentialExpiryObservationStore()
			);
			$package_providers = $settings->build_package_list();
			$packages          = array( $this->package( true, $selected ) );
			$package_view      = PackagePagePresenter::plugin();
			$captured          = array();
			foreach ( array( 'ran_booster_admin_package_management_rows', 'ran_booster_admin_package_management_actions' ) as $hook ) {
				$GLOBALS['ran_booster_admin_view_filters'][ $hook ] = array(
					static function ( array $value, mixed ...$arguments ) use ( &$captured ): array {
						$captured[] = array( $value, $arguments );
						return $value;
					},
				);
			}
			$package_view->index(
				$packages,
				$package_providers,
				array(
					'search'   => '',
					'provider' => '',
					'source'   => '',
					'policy'   => '',
				),
				new \RAN\Admin\DeploymentAdminPresenter()
			);
			self::assertCount( 2, $captured );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Inspect all callback object properties for leakage; this test never deserializes data.
			self::assertStringNotContainsString( 'configuration-canary', serialize( $captured ) );
			unset( $GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_package_management_rows'], $GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_package_management_actions'] );
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/index.php';
			$html = (string) ob_get_clean();
			self::assertStringContainsString( $expected, $html );
			self::assertStringNotContainsString( 'configuration-canary', $html );
			self::assertStringNotContainsString( '<0>', $html );
		}
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

		self::assertStringContainsString( '<strong>Git provider unavailable.</strong>', $html );
		self::assertStringContainsString( '<code>temporarily-offline</code>', $html );
		self::assertStringContainsString( '<code>owner/exact-repository</code>', $html );
		self::assertStringContainsString( '<code>stable-provider-id</code>', $html );
		self::assertStringContainsString( '<fieldset  disabled="disabled"', $html );
		self::assertStringContainsString( 'temporarily-offline — Git provider unavailable', $html );
		self::assertStringNotContainsString( 'href="https://github.com/owner/exact-repository"', $html );
		self::assertStringContainsString( 'id="ran-booster-package-edit-form"', $html );
		self::assertStringContainsString( 'form="ran-booster-package-edit-form"', $html );
		self::assertStringContainsString( 'ran-booster-package-settings__save-actions', $html );
		self::assertStringContainsString( 'ran-booster-package-danger-zone', $html );
		self::assertStringContainsString( 'value="unlink-plugin"', $html );
		self::assertStringContainsString( 'value="unlink-delete-plugin"', $html );
		self::assertSame( 2, substr_count( $html, 'name="ran_booster[confirm_package_removal]" value="1" required' ) );
		self::assertSame( 3, substr_count( $html, 'name="ran_booster[expected_source_revision]" value="1"' ) );
		self::assertStringContainsString( 'name="_ran_booster_reinstall_nonce"', $html );
	}

	public function test_unavailable_provider_notice_escapes_repository_identity(): void {
		$package = $this->package();
		$package->set_repository( new ManagedRepository( 'temporarily-offline', 'owner/<script>', 'id<unsafe>', 'release' ) );
		$package_view              = PackagePagePresenter::plugin();
		$package_provider_settings = array(
			'default_provider' => 'temporarily-offline',
			'providers'        => array( $this->provider_option( 'temporarily-offline', false ) ),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<code>owner/&lt;script&gt;</code>', $html );
		self::assertStringContainsString( '<code>id&lt;unsafe&gt;</code>', $html );
		self::assertStringNotContainsString( '<script>', $html );
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
		self::assertStringContainsString( 'data-ran-booster-badge-intent="high">Git provider unavailable</span>', $html );
		self::assertStringNotContainsString( 'Recover package management', $html );
		self::assertStringNotContainsString( 'recovery=1', $html );
		self::assertStringNotContainsString( 'Review management removal', $html );
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
		self::assertStringContainsString( '>Edit settings<span class="screen-reader-text">: ', $html );
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
		self::assertMatchesRegularExpression( '/ran-booster-package-row__wordpress-state">\\s*<span class="ran-booster-package-row__state-label">WordPress<\\/span>\\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-stable-intent" data-ran-booster-badge data-ran-booster-badge-intent="stable">Enabled<\\/span>/', $html );
		self::assertStringContainsString( '>WordPress</span>', $html );
		self::assertStringContainsString( '>Enabled</span>', $html );
		self::assertMatchesRegularExpression( '/ran-booster-package-row__update-state">\\s*<span class="ran-booster-package-row__state-label">Updates<\\/span>\\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-stable-intent" data-ran-booster-badge data-ran-booster-badge-intent="stable">Automatic<\\/span>/', $html );
		self::assertStringContainsString( '>Automatic</span>', $html );
		self::assertStringContainsString( 'Branch · release', $html );
		self::assertStringNotContainsString( 'Branch · release · Push-to-Deploy', $html );
		self::assertStringContainsString( 'The saved Git provider is unavailable.', $html );
		self::assertSame( 1, $this->status_badge_count( $html ) );
		self::assertSame( 0, substr_count( $html, 'data-ran-booster-ui-badge' ) );
		self::assertStringContainsString( 'Review package health, deploy saved branches', $html );
		self::assertSame( 1, substr_count( $html, 'Manual deployments run only when an administrator requests one.' ) );
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

		self::assertMatchesRegularExpression( '/data-ran-booster-badge-intent="high"[^>]*>Failed<\/span>/', $html );
		self::assertSame( 2, $this->status_badge_count( $html ) );
		self::assertSame( 0, substr_count( $html, 'data-ran-booster-ui-badge' ) );
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

		self::assertStringContainsString( 'data-ran-booster-badge-intent="high">Credential unavailable</span>', $html );
		self::assertSame( 1, $this->status_badge_count( $html ) );
		self::assertSame( 0, substr_count( $html, 'data-ran-booster-ui-badge' ) );
		self::assertStringContainsString( 'The saved repository credential is unavailable.', $html );
		self::assertStringContainsString( 'data-ran-booster-update-label>Reinstall</span>', $html );
		self::assertStringContainsString( 'button-update-package"  disabled="disabled"', $html );
		self::assertMatchesRegularExpression( '/ran-booster-package-row__wordpress-state">\\s*<span class="ran-booster-package-row__state-label">WordPress<\\/span>\\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-none-intent" data-ran-booster-badge data-ran-booster-badge-intent="none">Disabled<\\/span>/', $html );
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
		self::assertMatchesRegularExpression( '/ran-booster-package-row__update-state">\\s*<span class="ran-booster-package-row__state-label">Updates<\\/span>\\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-none-intent" data-ran-booster-badge data-ran-booster-badge-intent="none">Disabled<\\/span>/', $html );
		self::assertStringContainsString( '>Updates</span>', $html );
		self::assertStringContainsString( '>Disabled</span>', $html );
		self::assertStringContainsString( 'Branch · release', $html );
		self::assertStringNotContainsString( 'Branch · release · Disabled', $html );
		self::assertStringContainsString( 'Booster will not overwrite this package', $html );
		self::assertStringContainsString( 'Git provider', $html );
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
		self::assertStringContainsString( '>Edit settings<span class="screen-reader-text">: ', $html );
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
		self::assertMatchesRegularExpression( '/ran-booster-package-row__wordpress-state">\\s*<span class="ran-booster-package-row__state-label">WordPress<\\/span>\\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-none-intent" data-ran-booster-badge data-ran-booster-badge-intent="none">Disabled<\\/span>/', $html );
		self::assertMatchesRegularExpression( '/ran-booster-package-row__update-state">\\s*<span class="ran-booster-package-row__state-label">Updates<\\/span>\\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-informational-intent" data-ran-booster-badge data-ran-booster-badge-intent="informational">Manual<\\/span>/', $html );
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
		self::assertMatchesRegularExpression( '/ran-booster-package-row__update-state">\\s*<span class="ran-booster-package-row__state-label">Updates<\\/span>\\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-stable-intent" data-ran-booster-badge data-ran-booster-badge-intent="stable">Automatic<\\/span>/', $html );
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
		self::assertMatchesRegularExpression( '/ran-booster-package-row__update-state">\\s*<span class="ran-booster-package-row__state-label">Updates<\\/span>\\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-stable-intent" data-ran-booster-badge data-ran-booster-badge-intent="stable">Automatic<\\/span>/', $html );
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
		self::assertSame( 1, $this->status_badge_count( $html ) );
		self::assertSame( 0, substr_count( $html, 'data-ran-booster-ui-badge' ) );
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

	public function test_repository_field_omits_provider_locator_helper_text(): void {
		$package_view              = PackagePagePresenter::plugin();
		$repository_value          = 'group/subgroup/example-plugin';
		$provider_browse_available = false;
		$release_managed           = false;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/repository.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Repository locator supplied by', $html );
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

	public function test_development_warning_envelope_and_link_conditions(): void {
		foreach ( array(
			array( 'manual', true, true, 'branch', false, true ),
			array( 'automatic', true, true, 'branch', false, true ),
			array( 'disabled', true, true, 'branch', true, true ),
			array( 'manual', true, false, 'branch', false, false ),
			array( 'automatic', true, true, 'release_asset', false, false ),
			array( 'manual', false, true, 'branch', false, false ),
		) as list( $deployment_policy, $development_environment_detected, $provider_webhook_available, $package_automation_source, $hidden, $links ) ) {
			$provider_code = 'gh';
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/fields/deployment-policy.php';
			$html = (string) ob_get_clean();
			if ( ! $development_environment_detected ) {
				self::assertStringNotContainsString( 'data-ran-booster-local-development-warning', $html );
				continue;
			}
			self::assertMatchesRegularExpression( '/<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning" data-ran-booster-local-development-warning' . ( $hidden ? ' hidden' : '' ) . '>\s*<div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>\s*<p>.*?Manual and Automatic can overwrite local changes when an update or reinstall runs\..*?<\/p>\s*<\/div>\s*<svg class="ran-wp-ui-notice__icon"[^>]*>.*?<\/svg>\s*<\/div>/s', $html );
			if ( $links ) {
				self::assertStringContainsString( 'Push-to-Deploy setting</a> | <a', $html );
				self::assertStringContainsString( '>Docs</a>', $html );
			} else {
				self::assertStringNotContainsString( 'Push-to-Deploy setting</a>', $html );
			}
		}
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
		self::assertStringNotContainsString( 'Editing this package’s files here? Choose Disabled.', $html );
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
		self::assertStringContainsString( 'class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning"', $html );
		self::assertStringContainsString( 'data-ran-booster-local-development-warning', $html );
		self::assertStringContainsString( 'Editing this package’s files here? Choose Disabled.', $html );
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
		self::assertStringContainsString( 'Editing this package’s files here? Choose Disabled.', $html );
	}

	/**
	 * Renders the Updates field in the Package operation card layout.
	 *
	 * @return string The field HTML.
	 */
	private function render_operation_policy( bool $with_action, bool $with_notice, string $source, string $policy = DeploymentPolicy::MANUAL->value ): string {
		$deployment_policy                = $policy;
		$provider_webhook_available       = true;
		$provider_code                    = 'gh';
		$package_automation_source        = $source;
		$development_environment_detected = $with_notice;
		$package_operation_layout         = true;
		$package_operation_actions_html   = $with_action
			? '<div class="ran-booster-package-operation-settings__actions" role="group"><a class="ran-wp-ui-button" href="#activate">Activate plugin</a></div>'
			: '';
		$package_field_layout             = 'grid';
		$package_field_form               = 'ran-booster-package-edit-form';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/deployment-policy.php';
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,string> $needles
	 * @return array<string,int>
	 */
	private function positions_in_order( string $html, array $needles ): array {
		$positions = array();
		$previous  = -1;
		foreach ( $needles as $name => $needle ) {
			$position = strpos( $html, $needle, max( 0, $previous ) );
			if ( false === $position ) {
				self::fail( 'Missing or out of order: ' . $name );
			}
			self::assertGreaterThan( $previous, $position, $name );
			$positions[ $name ] = $position;
			$previous           = $position;
		}
		return $positions;
	}

	public function test_operation_layout_orders_label_select_description_notice_then_actions_for_releases(): void {
		$html = $this->render_operation_policy( true, true, 'release_asset' );

		$this->positions_in_order(
			$html,
			array(
				'label'       => '<label for="ran-booster-deployment-policy">',
				'select'      => 'name="ran_booster[deployment_policy]"',
				'description' => 'Manual waits for an administrator. Automatic installs validated releases.',
				'notice'      => 'data-ran-booster-local-development-warning',
				'actions'     => 'class="ran-booster-package-operation-settings__actions"',
			)
		);
		// The description stays in the label/select group; the notice and actions are siblings after it.
		self::assertMatchesRegularExpression( '/<\/select>\s*<\/div>\s*<p class="description">[^<]*<\/p>\s*<\/div>\s*<div class="notice notice-warning inline ran-wp-ui-notice/', $html );
		self::assertSame( 1, substr_count( $html, 'ran-booster-deployment-policy-group' ) );
		self::assertStringNotContainsString( 'deployment-policy-row--with-action', $html );
		self::assertMatchesRegularExpression( '/<\/div>\s*<div class="ran-booster-package-operation-settings__actions" role="group">.*<\/div>\s*<\/div>\s*$/s', $html );
	}

	public function test_operation_layout_shows_the_branch_description_under_the_select(): void {
		$html = $this->render_operation_policy( true, true, 'branch' );

		$this->positions_in_order(
			$html,
			array(
				'label'       => '<label for="ran-booster-deployment-policy">',
				'select'      => 'name="ran_booster[deployment_policy]"',
				'description' => 'Manual waits for an administrator. Automatic deploys on push.',
				'notice'      => 'data-ran-booster-local-development-warning',
				'actions'     => 'class="ran-booster-package-operation-settings__actions"',
			)
		);
		// With the notice present its links live in the notice, not the description.
		self::assertSame( 1, substr_count( $html, 'Push-to-Deploy setting' ) );
	}

	public function test_operation_layout_branch_description_keeps_push_to_deploy_links_without_the_notice(): void {
		$html = $this->render_operation_policy( true, false, 'branch' );

		self::assertStringNotContainsString( 'data-ran-booster-local-development-warning', $html );
		self::assertMatchesRegularExpression( '/Automatic deploys on push\.\s*<br\/><a [^>]*>Push-to-Deploy setting<\/a> \| <a [^>]*>Docs<\/a>\s*<\/p>/', $html );
	}

	public function test_operation_layout_hides_the_notice_when_disabled_and_keeps_the_description_first(): void {
		$html = $this->render_operation_policy( true, true, 'release_asset', DeploymentPolicy::DISABLED->value );

		self::assertMatchesRegularExpression( '/<div class="notice notice-warning inline[^>]* data-ran-booster-local-development-warning hidden>/', $html );
		$this->positions_in_order(
			$html,
			array(
				'select'      => 'name="ran_booster[deployment_policy]"',
				'description' => 'Manual waits for an administrator',
				'notice'      => 'data-ran-booster-local-development-warning',
				'actions'     => 'class="ran-booster-package-operation-settings__actions"',
			)
		);
	}

	public function test_operation_layout_without_action_group_renders_no_empty_wrapper(): void {
		$html = $this->render_operation_policy( false, true, 'release_asset' );

		self::assertStringNotContainsString( 'ran-booster-package-operation-settings__actions', $html );
		self::assertStringContainsString( 'class="ran-booster-deployment-policy-row"', $html );
		$this->positions_in_order(
			$html,
			array(
				'select'      => '<select',
				'description' => 'class="description"',
				'notice'      => 'data-ran-booster-local-development-warning',
			)
		);
	}

	public function test_operation_layout_without_notice_has_no_notice_markup(): void {
		$html = $this->render_operation_policy( true, false, 'release_asset' );

		self::assertStringNotContainsString( 'data-ran-booster-local-development-warning', $html );
		self::assertStringNotContainsString( 'class="notice', $html );
		$this->positions_in_order(
			$html,
			array(
				'description' => 'Manual waits for an administrator',
				'actions'     => 'class="ran-booster-package-operation-settings__actions"',
			)
		);
	}

	public function test_non_operation_policy_field_has_no_branch_description(): void {
		$deployment_policy                = DeploymentPolicy::MANUAL->value;
		$provider_webhook_available       = true;
		$provider_code                    = 'gh';
		$package_automation_source        = 'branch';
		$development_environment_detected = false;
		$package_field_layout             = 'grid';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/deployment-policy.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Automatic deploys on push.', $html );
		self::assertStringContainsString( 'Push-to-Deploy setting', $html );
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
		self::assertStringContainsString( 'Manual waits for an administrator. Automatic installs validated releases.', $html );
		self::assertStringNotContainsString( 'Automatic lets WordPress install validated published updates', $html );
		self::assertStringNotContainsString( 'NOTICE:', $html );
		self::assertStringNotContainsString( 'Push-to-Deploy setting', $html );
	}

	public function test_credential_field_escapes_display_values_and_preserves_provider_selection(): void {
		$provider_code          = 'fixture';
		$selected_credential_id = 'profile"selected';
		$provider_options       = array(
			array(
				'code'                => 'fixture',
				'credential_profiles' => array(
					array(
						'id'         => $selected_credential_id,
						'label'      => '<script>credential-label</script>',
						'kind'       => 'api-key',
						'kind_label' => 'API "key"',
						'detail'     => 'tenant: <img src=x>',
						'source'     => 'file',
						'configured' => true,
					),
				),
			),
			array(
				'code'                => 'other"provider',
				'credential_profiles' => array(
					array(
						'id'         => 'other-profile',
						'label'      => 'Other credential',
						'kind'       => 'api-key',
						'kind_label' => 'API key',
						'detail'     => '',
						'source'     => 'file',
						'configured' => true,
					),
				),
			),
			array(
				'code'                => 'unavailable',
				'credential_profiles' => array(),
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/fields/credential.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '&lt;script&gt;credential-label&lt;/script&gt; — API &quot;key&quot; · tenant: &lt;img src=x&gt;', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertMatchesRegularExpression( '/value="profile&quot;selected"[^>]*selected="selected"/', $html );
		self::assertMatchesRegularExpression( '/value="other-profile"[^>]*data-provider="other&quot;provider"[^>]*disabled="disabled"[^>]*hidden/', $html );
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
			'credential_profiles'   => array(
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

	private function package( bool $is_private = false, string $credential_id = '' ): UnavailableProviderPackage {
		$repository = new ManagedRepository(
			'temporarily-offline',
			'owner/exact-repository',
			'stable-provider-id',
			'release',
			$is_private,
			'' === $credential_id ? null : $credential_id
		);

		$package = new UnavailableProviderPackage();
		$package->set_repository( $repository );
		$package->set_deployment_policy( DeploymentPolicy::AUTOMATIC );

		return $package;
	}

	/**
	 * Counts the status Badges outside each row's state labels, which are Badges
	 * too and would otherwise inflate every count.
	 */
	private function status_badge_count( string $html ): int {
		$without_states = preg_replace( '/<div class="ran-booster-package-row__states">.*?<\/div>/s', '', $html );

		return substr_count( null === $without_states ? $html : $without_states, 'data-ran-booster-badge data-ran-booster-badge-intent=' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused package fake stays beside its view tests.
final class UnavailableProviderPackage extends AbstractPackage {
	public string $name = 'Exact package';

	public function get_identifier(): mixed {
		return 'exact/exact.php';
	}

	protected function runtime_slug(): string {
		return 'exact';
	}
}
