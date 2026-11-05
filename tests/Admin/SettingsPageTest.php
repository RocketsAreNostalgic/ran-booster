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
		$GLOBALS['ran_booster_notice_scope_screen'] = (object) array( 'id' => 'ran-booster_page_ran-booster-settings' );
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
		$off             = $dashboard->render_general_settings_region( true );
		self::assertStringContainsString( 'id="ran-booster-general-region"', $off );
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
		$html            = (string) ob_get_clean();
		$dom             = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		$xpath = new \DOMXPath( $dom );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//fieldset[legend="Appearance"]//label[@for="ran-booster-hide-github"]//input[@type="checkbox" and @role="switch" and @checked and @aria-invalid="true" and @aria-describedby="ran-booster-hide-github-help ran-booster-hide-github-error"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-hide-github-help"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-hide-github-error"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//fieldset[legend="Booster updates"]//label[@for="ran-booster-self-update-discovery"]//input[@role="switch" and @disabled and not(@checked) and @aria-describedby="ran-booster-self-update-discovery-help"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-self-update-discovery-help"])' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//input[@role="switch" and @aria-checked])' ) );
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
		self::assertSame( 0.0, $xpath->evaluate( 'count(//input[@type="hidden" and @name="ran_booster[preferences][self_update_discovery]"])' ) );
		$general_feedback       = 'Settings changed. Review the values.';
		$general_save_succeeded = false;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/settings-general.php';
		$failed = (string) ob_get_clean();
		self::assertStringContainsString( 'id="ran-booster-general-error" class="notice notice-info inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1"', $failed );
		self::assertStringContainsString( 'Settings changed. Review the values.', $failed );
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
