<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageAdminController;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageProvisioner;
use RAN\Secrets\SecretsStorageProvisioningResult;
use RAN\Settings\GeneralPreferenceStore;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use ReflectionClass;
use ReflectionProperty;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class SettingsPageTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$_GET                                     = array( 'page' => 'ran-booster-settings' );
		$_POST                                    = array();
		$_SERVER['REQUEST_METHOD']                = 'POST';
		$GLOBALS['ran_booster_test_capabilities'] = array(
			'manage_options' => true,
			'update_plugins' => true,
		);
		$GLOBALS['ran_booster_test_nonce_valid']  = true;
		$GLOBALS['ran_booster_test_nonce_checks'] = array();
		$GLOBALS['ran_booster_test_capability_checks'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'], $GLOBALS['ran_booster_test_capabilities'], $GLOBALS['ran_booster_test_nonce_valid'], $GLOBALS['ran_booster_test_nonce_checks'], $GLOBALS['ran_booster_test_capability_checks'] );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_general_response_refreshes_only_the_source_notice_from_fresh_policy(): void {
		require_once dirname( __DIR__ ) . '/Support/BoosterNoticeScopeWordPressFunctions.php';
		$GLOBALS['ran_booster_notice_scope_screen'] = null; // admin_init runs before WordPress sets the current screen.
		$GLOBALS['ran_booster_test_capabilities']   = array( 'manage_options' => true );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Controlled fresh-option boundary.
		$GLOBALS['wpdb'] = new class() {
			public string $options    = 'wp_options';
			public string $last_error = '';
			public bool $enabled      = false;
			public int $reads         = 0;
			public bool $fail_policy  = false;
			public function prepare( string $sql, mixed ...$arguments ): string {
				unset( $arguments );
				return $sql;
			}
			public function get_var( string $sql ): string {
				unset( $sql );
				++$this->reads;
				if ( $this->fail_policy && 2 === $this->reads ) {
					$this->last_error = 'controlled';
				}
				return serialize( array( 'self_update_discovery' => $this->enabled ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Controlled option fixture.
			}
		};
		$dashboard       = $this->settings_dashboard( new SettingsPageStorageProvisioner( null ) );
		( new \ReflectionProperty( Dashboard::class, 'admin_tabs' ) )->setValue( $dashboard, null );
		$off = $dashboard->render_general_settings_region( true );
		self::assertStringContainsString( 'id="ran-booster-general-region"', $off );
		$toggle = $dashboard->render_general_settings_region( true, 'self_update_discovery' );
		self::assertStringContainsString( 'id="ran-booster-general-self_update_discovery"', $toggle );
		self::assertStringNotContainsString( 'id="ran-booster-history-rows"', $toggle );
		self::assertStringNotContainsString( 'data-ran-booster-general-navigation', $toggle );
		self::assertStringNotContainsString( 'ran-booster-settings__preferences', $toggle );
		self::assertStringNotContainsString( 'ran-booster-settings__limits', $toggle );
		self::assertStringContainsString( 'hx-target="#ran-booster-general-self_update_discovery"', $toggle );
		$numeric = $dashboard->render_general_settings_region( true, 'numeric' );
		self::assertStringContainsString( 'id="ran-booster-general-numeric"', $numeric );
		self::assertStringNotContainsString( 'role="switch"', $numeric );
		self::assertStringNotContainsString( 'hx-swap-oob', $numeric );
		self::assertStringContainsString( 'data-ran-booster-unsaved role="status"', $numeric );
		self::assertStringNotContainsString( 'ran-booster-settings__limits', $numeric );
		self::assertStringContainsString( 'hx-target="#ran-booster-general-numeric"', $numeric );
		$hide = $dashboard->render_general_settings_region( true, 'hide_github' );
		self::assertStringContainsString( 'hx-target="#ran-booster-general-hide_github"', $hide );
		self::assertStringNotContainsString( 'ran-booster-settings__preferences', $hide );
		self::assertStringNotContainsString( 'ran-booster-settings__limits', $hide );
		self::assertStringNotContainsString( 'id="ran-booster-general-numeric"', $hide );
		self::assertSame( 1, substr_count( $off, 'id="ran-booster-core-development-notice-slot" hx-swap-oob="true"' ) );
		self::assertStringNotContainsString( 'data-ran-booster-core-development-notice', $off );
		$GLOBALS['wpdb']->enabled = true;
		$on                       = $dashboard->render_general_settings_region( true );
		self::assertSame( 1, substr_count( $on, 'data-ran-booster-core-development-notice' ) );
		self::assertSame( 1, substr_count( $on, 'id="ran-booster-core-development-notice-slot" hx-swap-oob="true"' ) );
		$GLOBALS['wpdb']->reads       = 0;
		$GLOBALS['wpdb']->fail_policy = true;
		$unknown                      = $dashboard->render_general_settings_region( true );
		self::assertStringContainsString( 'id="ran-booster-general-region"', $unknown );
		self::assertStringNotContainsString( 'hx-swap-oob', $unknown );
		self::assertStringContainsString( 'data-ran-booster-general-saved', $unknown );
		$GLOBALS['wpdb']->last_error  = '';
		$GLOBALS['wpdb']->fail_policy = false;
		$failure                      = $dashboard->render_general_settings_region( false );
		self::assertStringNotContainsString( 'data-ran-booster-general-saved', $failure );
		self::assertSame( 1, substr_count( $failure, 'data-ran-booster-core-development-notice' ) );
		$GLOBALS['ran_booster_test_capabilities'] = array(
			'manage_options'         => false,
			'manage_network_plugins' => false,
		);
		$unauthorized                             = $dashboard->render_general_settings_region( false );
		self::assertStringContainsString( 'id="ran-booster-core-development-notice-slot" hx-swap-oob="true"', $unauthorized );
		self::assertStringNotContainsString( 'data-ran-booster-core-development-notice', $unauthorized );
	}

	public function test_check_now_uses_its_own_nonce_and_never_calls_general_save(): void {
		$_POST['ran_booster'] = array( 'action' => 'check-core-updates' );
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )->method( 'post_check_core_updates' );
		$dashboard->expects( self::never() )->method( 'post_save_general_settings' );

		$this->dispatcher( $dashboard )->dispatch_post_requests();

		self::assertSame( array( 'ran-booster-check-core-updates' ), $GLOBALS['ran_booster_test_nonce_checks'] );
		self::assertSame( array( 'manage_options', 'update_plugins' ), $GLOBALS['ran_booster_test_capability_checks'] );
	}

	public function test_general_save_requires_the_exact_three_field_route_shape_before_calling_dashboard(): void {
		$_POST['ran_booster'] = array(
			'action'      => 'save-general-settings',
			'token'       => 'token',
			'preferences' => array(),
			'extra'       => 'forbidden',
		);
		$dashboard            = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'post_save_general_settings' );

		try {
			$this->dispatcher( $dashboard )->dispatch_post_requests();
			self::fail( 'The additional field must be rejected before General Save.' );
		} catch ( \RuntimeException $error ) {
			self::assertStringContainsString( 'Invalid Settings request', $error->getMessage() );
		}
		self::assertSame( array( 'ran-booster-save-general-settings' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_settings_caches_status_and_payload_until_an_explicit_result_override(): void {
		$storage   = new SettingsPageStorageProvisioner( SecretsStorageProvisioningResult::setup_available( '/private/ran-booster-store.json' ) );
		$dashboard = $this->settings_dashboard( $storage );

		$first  = $dashboard->get_settings();
		$second = $dashboard->get_settings();

		self::assertSame( 1, $storage->status_calls );
		self::assertSame( $first['data']['secrets_storage'], $second['data']['secrets_storage'] );
		self::assertSame( 'setup_available', $first['data']['secrets_storage']['status'] );

		$dashboard->set_secrets_storage_provisioning_result( SecretsStorageProvisioningResult::unsupported( 'test_override', 'Controlled override.' ) );
		$overridden = $dashboard->get_settings();

		self::assertSame( 1, $storage->status_calls, 'An explicit result override must not invoke status again.' );
		self::assertSame( 'unsupported', $overridden['data']['secrets_storage']['status'] );
		self::assertSame( 'test_override', $overridden['data']['secrets_storage']['reason_code'] );
	}

	public function test_settings_recovers_to_an_unavailable_status_when_status_throws(): void {
		$dashboard = $this->settings_dashboard( new SettingsPageStorageProvisioner( null, new \RuntimeException( 'Controlled status failure.' ) ) );

		$result = $dashboard->get_settings();

		self::assertSame( 'unsupported', $result['data']['secrets_storage']['status'] );
		self::assertSame( 'storage_status_unavailable', $result['data']['secrets_storage']['reason_code'] );
	}

	public function test_switches_keep_native_labels_descriptions_errors_and_policy_state(): void {
		$secrets_storage    = null;
		$general_settings   = array(
			'hide_github'           => array(
				'value'  => true,
				'source' => 'saved',
				'locked' => false,
				'error'  => null,
			),
			'self_update_discovery' => array(
				'value'  => false,
				'source' => 'code',
				'locked' => true,
				'error'  => null,
			),
			'max_download_bytes'    => array(
				'value'  => PHP_INT_MAX,
				'source' => 'default',
				'locked' => true,
				'error'  => null,
			),
		);
		$general_token      = 'token';
		$general_feedback   = null;
		$general_errors     = array( 'hide_github' => 'Invalid selection.' );
		$general_attempted  = array();
		$github_visibility  = array(
			'eligible' => true,
			'hidden'   => false,
			'reason'   => '',
		);
		$core_update_status = array();
		$check_available    = false;
		$native_update_url  = null;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/settings.php';
		$html     = (string) ob_get_clean();
		$view     = 'settings';
		$messages = array();
		$tabs     = array(
			array(
				'key'    => 'overview',
				'label'  => 'Overview',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
				'active' => false,
			),
			array(
				'key'    => 'portability',
				'label'  => 'Transporter',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster-transporter',
				'active' => false,
			),
			array(
				'key'      => 'github',
				'label'    => 'GitHub',
				'url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=github',
				'active'   => false,
				'provider' => true,
			),
			array(
				'key'      => 'troubleshooting',
				'label'    => 'Troubleshooting',
				'url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting',
				'active'   => false,
				'provider' => false,
			),
		);
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$shell_html         = (string) ob_get_clean();
		$general_navigation = $tabs;
		$general_scope      = 'hide_github';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/settings-general.php';
		$partial_html = (string) ob_get_clean();
		unset( $general_navigation, $general_scope );
		self::assertSame( 1, preg_match( '/<nav class="ran-admin-shell__navigation".*?<\/nav>/s', $shell_html, $shell_navigation ) );
		self::assertSame( 1, preg_match( '/<template data-ran-booster-general-navigation>.*?<\/template>/s', $partial_html, $partial_navigation ) );
		if ( ! isset( $shell_navigation[0], $partial_navigation[0] ) ) {
			self::fail( 'Both navigation fragments must have a complete match.' );
		}
		foreach ( array( $shell_navigation[0], $partial_navigation[0] ) as $navigation ) {
			foreach ( array( 'Overview', 'Plugins', 'Themes', 'GitHub' ) as $label ) {
				self::assertStringContainsString( '>' . $label . '</a>', $navigation );
			}
			self::assertStringNotContainsString( 'Troubleshooting', $navigation );
			self::assertStringContainsString( 'tab=github', $navigation );
		}

		self::assertSame( 1, preg_match_all( '/<h1\b/', $shell_html ) );
		self::assertStringContainsString( '>Booster</h1>', $shell_html );
		self::assertStringContainsString( '>Rockets Are Nostalgic</p>', $shell_html );
		self::assertStringContainsString( '?page=ran-booster-plugins">Plugins</a>', $shell_html );
		self::assertStringContainsString( '?page=ran-booster-themes">Themes</a>', $shell_html );
		self::assertStringNotContainsString( 'aria-current=', $shell_html );
		self::assertStringContainsString( '<h2 id="ran-booster-settings-heading"', $shell_html );
		$dom             = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		$xpath = new \DOMXPath( $dom );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//p[@data-ran-booster-autosave-description and @hidden and text()="Changes save automatically."])' ) );
		self::assertSame( 2.0, $xpath->evaluate( 'count(//*[@id="ran-booster-general-region"]/section[contains(concat(" ", normalize-space(@class), " "), " ran-booster-panel ")])' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@id="ran-booster-general-region"]/section[@data-ran-booster-card="settings" and contains(@class, "ran-booster-card")]/div[contains(@class, "ran-booster-card__content")])' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@id="ran-booster-general-region" and @data-ran-booster-card])' ) );
		self::assertSame( 3.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope]/div[@data-ran-booster-ui-notice and @data-ran-booster-admin-mutation-error]/div[@data-ran-booster-ui-notice-content][p[@data-ran-booster-ui-notice-primary] and p[@data-ran-booster-ui-notice-detail]])' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@data-ran-booster-ui-notice-mount])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="hide_github"]/div[not(@role) and not(@data-ran-booster-general-failed)]//p[@id="ran-booster-hide-github-error" and @data-ran-booster-ui-notice-field and @data-ran-booster-ui-notice-request-field and text()="Hide GitHub UI: Invalid selection."])' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="hide_github"]//*[@role="alert"])' ) );

		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-general-region"]/section[h3="General preferences" and p="Changes save automatically."]//*[@id="ran-booster-general-hide_github"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-general-region"]/section[h3="General preferences"]//*[@id="ran-booster-general-self_update_discovery"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-general-region"]/section[h3="History and download limits"]//*[@id="ran-booster-general-numeric"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-general-region"]/section[h3="History and download limits"]//button[@id="ran-booster-save-numeric-bottom"])' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@id="ran-booster-general-region"]/section[h3="General preferences"]//button[@type="submit" and not(@data-ran-booster-toggle-save) and not(@form)])' ) );

		self::assertSame( 1.0, $xpath->evaluate( 'count(//fieldset[legend="Appearance"]//label[@for="ran-booster-hide-github"]//input[@type="checkbox" and @role="switch" and @checked and @aria-invalid="true" and @aria-describedby="ran-booster-hide-github-help ran-booster-hide-github-error"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-hide-github-help"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-hide-github-error"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//fieldset[legend="Booster updates"]//label[@for="ran-booster-self-update-discovery"]//input[@role="switch" and @disabled and not(@checked) and @aria-describedby="ran-booster-self-update-discovery-help"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-self-update-discovery-help"])' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//input[@role="switch" and @aria-checked])' ) );
		self::assertSame( 2.0, $xpath->evaluate( 'count(//span[@data-ran-booster-toggle-status and @data-ran-booster-ui-badge and @role="status" and not(@data-ran-booster-ui-badge-label) and not(@data-ran-booster-ui-badge-intent)]/span[@data-ran-booster-ui-badge-fallback])' ) );
		self::assertSame( 2.0, $xpath->evaluate( 'count(//span[@data-ran-booster-toggle-status and @data-ran-booster-ui-badge]/span[@data-ran-booster-ui-badge-mount and not(node())])' ) );
		foreach ( array( 'ran-booster-hide-github', 'ran-booster-self-update-discovery' ) as $switch_id ) {
			self::assertSame( 1.0, $xpath->evaluate( 'count(//label[@id="' . $switch_id . '-label" and @for="' . $switch_id . '"]/following-sibling::div[contains(@class, "ran-booster-settings__switch-row")][1]/span[@data-ran-booster-ui-switch="general-preference"]/label[@data-ran-booster-ui-switch-fallback and not(@hidden)]/input[@id="' . $switch_id . '" and @aria-labelledby="' . $switch_id . '-label"])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//input[@id="' . $switch_id . '"]/parent::label/parent::span[@data-ran-booster-ui-switch="general-preference"]/following-sibling::span[@data-ran-booster-toggle-status and @role="status"])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//input[@id="' . $switch_id . '"]/parent::label/following-sibling::span[@data-ran-booster-ui-switch-mount and not(node())])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//input[@id="' . $switch_id . '"]/ancestor::form[1]//input[@type="checkbox" and @name])' ) );
		}
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="hide_github"]//span[@data-ran-booster-toggle-status]/*[@data-ran-booster-ui-badge-fallback and contains(@class, "ran-booster-badge--ok") and text()="Enabled"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="self_update_discovery"]//span[@data-ran-booster-toggle-status]/*[@data-ran-booster-ui-badge-fallback and contains(@class, "ran-booster-badge--neutral") and text()="Disabled"])' ) );
		$general_attempted = array(
			'hide_github'           => '0',
			'self_update_discovery' => '1',
		);
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/settings-general.php';
		$attempted_html  = (string) ob_get_clean();
		$attempted_dom   = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		$attempted_dom->loadHTML( $attempted_html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		$attempted_xpath = new \DOMXPath( $attempted_dom );
		self::assertSame( 1.0, $attempted_xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="hide_github"]//input[@id="ran-booster-hide-github" and not(@checked)])' ) );
		self::assertSame( 1.0, $attempted_xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="hide_github"]//span[@data-ran-booster-toggle-status]/*[@data-ran-booster-ui-badge-fallback and text()="Enabled"])' ) );
		self::assertSame( 1.0, $attempted_xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="self_update_discovery"]//input[@id="ran-booster-self-update-discovery" and @checked])' ) );
		self::assertSame( 1.0, $attempted_xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="self_update_discovery"]//span[@data-ran-booster-toggle-status]/*[@data-ran-booster-ui-badge-fallback and text()="Disabled"])' ) );
		$github_visibility['eligible'] = false;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/settings-general.php';
		$ineligible_html = (string) ob_get_clean();
		$previous_errors = libxml_use_internal_errors( true );
		$attempted_dom->loadHTML( $ineligible_html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		$attempted_xpath = new \DOMXPath( $attempted_dom );
		self::assertSame( 1.0, $attempted_xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="hide_github" and @data-ran-booster-confirmed="0"]//span[@data-ran-booster-toggle-status]/*[@data-ran-booster-ui-badge-fallback and contains(@class, "ran-booster-badge--neutral") and text()="Disabled"])' ) );
		$github_visibility['eligible'] = true;
		$general_attempted             = array();

		self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@data-ran-booster-core-toggle])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="hide_github"]//input[@type="checkbox" and @name="ran_booster[preferences][hide_github]" and @id="ran-booster-hide-github"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="self_update_discovery"]//input[@type="checkbox" and @name="ran_booster[preferences][self_update_discovery]" and @id="ran-booster-self-update-discovery"])' ) );
		foreach ( array(
			'Appearance'      => array( 'RAN_BOOSTER_HIDE_GITHUB_UI' ),
			'Booster updates' => array( 'RAN_BOOSTER_SELF_UPDATE_MODE' ),
			'History'         => array( 'RAN_BOOSTER_HISTORY_ROWS', 'RAN_BOOSTER_HISTORY_DAYS' ),
			'Download limits' => array( 'RAN_BOOSTER_MAX_DOWNLOAD_BYTES' ),
		) as $legend => $constants ) {
			self::assertSame( 1.0, $xpath->evaluate( 'count(//fieldset[legend="' . $legend . '"]/details[summary="Configuration constants"])' ) );
			foreach ( $constants as $constant ) {
				self::assertSame( 1.0, $xpath->evaluate( 'count(//fieldset[legend="' . $legend . '"]/details//code[text()="' . $constant . '"])' ) );
			}
		}
		self::assertSame( 4.0, $xpath->evaluate( 'count(//details[summary="Configuration constants"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//fieldset[legend="Booster updates"]//button[@form="ran-booster-check-core-updates" and @disabled and @aria-describedby="ran-booster-self-update-discovery-help"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@id="ran-booster-check-core-updates"]//input[@name="ran_booster[action]" and @value="check-core-updates"])' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@id="ran-booster-check-updates-unavailable"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//div[contains(@class, "ran-booster-settings__update-controls")][.//input[@id="ran-booster-self-update-discovery"] and ./button[text()="Check for updates"]])' ) );
		self::assertStringNotContainsString( 'ran-booster-check-updates-help', $html );
		self::assertSame( 1, substr_count( $html, 'No completed check recorded' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//input[@type="hidden" and @name="ran_booster[preferences][hide_github]" and @value="0"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="numeric"]//button[@type="submit" and @disabled])' ), 'All numeric controls are configuration-locked, so their empty form cannot be submitted.' );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="numeric"]//button[@id="ran-booster-save-numeric-bottom" and @type="submit"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="numeric"]//*[@data-ran-booster-unsaved and @role="status" and not(@aria-hidden)])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="numeric"]//fieldset[legend="Download limits"]/following-sibling::div//button[@id="ran-booster-save-numeric-bottom"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//form[@data-ran-booster-settings-scope="hide_github"]//button[@type="submit" and not(@disabled)])' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//input[@type="hidden" and @name="ran_booster[preferences][self_update_discovery]"])' ) );
		$general_errors                           = array();
		$general_settings['hide_github']['error'] = 'invalid_code';
		$general_scope                            = 'hide_github';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/settings-general.php';
		$config_html     = (string) ob_get_clean();
		$config_dom      = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		$config_dom->loadHTML( $config_html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		$config_xpath = new \DOMXPath( $config_dom );
		self::assertSame( 1.0, $config_xpath->evaluate( 'count(//div[@data-ran-booster-ui-notice and not(@role) and not(@hidden) and not(@data-ran-booster-general-failed)]/div[@data-ran-booster-ui-notice-content]/p[@id="ran-booster-hide-github-error" and @data-ran-booster-ui-notice-field and not(@data-ran-booster-ui-notice-request-field)])' ) );
		self::assertSame( 1.0, $config_xpath->evaluate( 'count(//div[@data-ran-booster-ui-notice]/div/p[@data-ran-booster-ui-notice-primary and not(text())])' ) );
		self::assertSame( 1.0, $config_xpath->evaluate( 'count(//div[@data-ran-booster-ui-notice]/div/p[@data-ran-booster-ui-notice-detail and @hidden])' ) );
		unset( $general_scope );
		$general_settings['hide_github']['error'] = null;
		$general_errors                           = array( 'hide_github' => 'Invalid selection.' );
		$general_feedback                         = 'Settings changed. Review the values.';
		$general_save_succeeded                   = false;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/settings-general.php';
		$failed = (string) ob_get_clean();
		self::assertStringContainsString( 'id="ran-booster-general-error" class="notice notice-info inline" data-ran-booster-admin-mutation-error data-ran-booster-ui-notice role="alert" tabindex="-1"', $failed );
		self::assertStringContainsString( 'Settings changed. Review the values.', $failed );
		$failed_dom      = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		$failed_dom->loadHTML( $failed );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		$failed_xpath = new \DOMXPath( $failed_dom );
		self::assertSame( 1.0, $failed_xpath->evaluate( 'count(//*[@role="alert"])' ) );
		self::assertSame( 1.0, $failed_xpath->evaluate( 'count(//*[@id="ran-booster-general-error" and @role="alert"])' ) );
		self::assertSame( 1.0, $failed_xpath->evaluate( 'count(//*[@id="ran-booster-hide-github-error" and @data-ran-booster-ui-notice-field])' ) );
		$general_scope = 'hide_github';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/settings-general.php';
		$scoped_failure  = (string) ob_get_clean();
		$previous_errors = libxml_use_internal_errors( true );
		$failed_dom->loadHTML( $scoped_failure );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		$failed_xpath = new \DOMXPath( $failed_dom );
		self::assertSame( 1.0, $failed_xpath->evaluate( 'count(//*[@role="alert"])' ) );
		self::assertSame( 1.0, $failed_xpath->evaluate( 'count(//*[@data-ran-booster-general-failed and @role="alert"]/div[p[@data-ran-booster-ui-notice-primary and text()="Settings changed. Review the values."] and p[@data-ran-booster-ui-notice-detail and not(@hidden)]])' ) );
		unset( $general_scope );
		unset( $general_save_succeeded );
		$general_feedback = 'Settings saved.';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/settings-general.php';
		$fallback = (string) ob_get_clean();
		self::assertStringContainsString( 'Settings saved.', $fallback );
		self::assertStringNotContainsString( 'data-ran-booster-admin-mutation-error role="alert" tabindex="-1"', $fallback );
	}

	public function test_enhanced_general_save_reports_only_full_success(): void {
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = array(
			'action'      => 'save-general-settings',
			'token'       => 'token',
			'preferences' => array(),
		);
		foreach ( array( true, false ) as $saved ) {
			$dashboard = $this->createMock( Dashboard::class );
			$dashboard->expects( self::once() )->method( 'post_save_general_settings' )->willReturn( $saved );
			try {
				$this->dispatcher( $dashboard, true )->dispatch_post_requests();
				self::fail( 'Enhanced response must terminate the request.' );
			} catch ( SettingsEnhancedResponse $response ) {
				self::assertSame( $saved, $response->saved );
			}
		}
		unset( $_SERVER['HTTP_HX_REQUEST'] );
		self::assertSame( array( 'ran-booster-save-general-settings', 'ran-booster-save-general-settings' ), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	private function settings_dashboard( SecretsStorageProvisioner $storage ): SettingsPageDashboard {
		$dashboard = ( new ReflectionClass( SettingsPageDashboard::class ) )->newInstanceWithoutConstructor();
		foreach ( array(
			'general_preferences' => new GeneralPreferenceStore(),
			'secrets_storage'     => $storage,
		) as $property => $value ) {
			( new ReflectionProperty( Dashboard::class, $property ) )->setValue( $dashboard, $value );
		}

		return $dashboard;
	}

	private function dispatcher( Dashboard $dashboard, bool $enhanced = false ): Dispatcher {
		$providers = new ProviderRegistry();
		$plugins   = new class() extends PluginRepository { public function __construct() {} };
		$themes    = new class() extends ThemeRepository { public function __construct() {} };
		$class     = $enhanced ? SettingsEnhancedDispatcher::class : Dispatcher::class;
		return new $class(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: $plugins, themes: $themes, providers: $providers ),
			$this->createStub( WordPressUpdaterLock::class )
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The renderer spy and status double keep the fixed Settings cache contract local to this route test.
final class SettingsPageDashboard extends Dashboard {

	/** @return array{view:string,data:array<string,mixed>} */
	protected function render( $view, $data = array() ): array {
		return array(
			'view' => $view,
			'data' => $data,
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- The status double needs only the controlled provisioning boundary used by the cache test.
final class SettingsPageStorageProvisioner extends SecretsStorageProvisioner {

	public int $status_calls = 0;

	public function __construct( private readonly ?SecretsStorageProvisioningResult $result, private readonly ?\Throwable $failure = null ) {
	}

	public function status(): SecretsStorageProvisioningResult {
		++$this->status_calls;
		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->result ?? throw new \LogicException( 'A controlled Settings status result is required.' );
	}

	/** @return array<string,mixed>|null */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- Preserve the provisioning contract while this status double deliberately suppresses recovery work.
	public function recovery_state( SecretsStorageProvisioningResult $status ): ?array {
		return null;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Local dispatcher response spy.
final class SettingsEnhancedDispatcher extends Dispatcher {
	protected function respond_to_htmx_general_settings( bool $saved ): never {
		throw new SettingsEnhancedResponse( $saved );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Local response sentinel.
final class SettingsEnhancedResponse extends \RuntimeException {
	public function __construct( public readonly bool $saved ) {
		parent::__construct();
	}
}
