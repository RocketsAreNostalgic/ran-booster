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
		$storage   = new SettingsPageStorageProvisioner( SecretsStorageProvisioningResult::setup_available( '/private/secrets.json' ) );
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

	private function dispatcher( Dashboard $dashboard ): Dispatcher {
		$providers = new ProviderRegistry();
		$plugins   = new class() extends PluginRepository { public function __construct() {} };
		$themes    = new class() extends ThemeRepository { public function __construct() {} };
		return new Dispatcher(
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
