<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused action spies belong to this dispatcher test.

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
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
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;

final class SecretsStorageSetupDispatcherTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$_POST                     = array(
			'ran_booster' => array( 'action' => 'create-secure-storage' ),
		);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_test_capability_checks']  = array();
		$GLOBALS['ran_booster_test_nonce_checks']       = array();
		$GLOBALS['ran_booster_test_capabilities']       = array();
		$GLOBALS['ran_booster_test_nonce_valid']        = true;
		$GLOBALS['ran_booster_package_view_multisite']  = false;
		$GLOBALS['ran_booster_admin_test_translations'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_POST = array();
		unset(
			$_SERVER['REQUEST_METHOD'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid'],
			$GLOBALS['ran_booster_package_view_multisite'],
			$GLOBALS['ran_booster_admin_test_translations']
		);
	}

	#[DataProvider( 'unavailable_action_cases' )]
	public function test_unavailable_storage_actions_localize_fallbacks_without_changing_codes(
		string $action,
		string $code,
		string $source_message,
		string $translated_message
	): void {
		$_POST['ran_booster']['action'] = $action;
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster'][ $source_message ] = $translated_message;
		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'set_secrets_storage_provisioning_result' )
			->with(
				self::callback(
					static fn ( SecretsStorageProvisioningResult $result ): bool => $code === $result->code()
						&& $translated_message === $result->message()
						&& null === $result->candidate_path()
				)
			);

		$this->dispatcher( $dashboard, null )->dispatch_post_requests();
	}

	/** @return iterable<string, array{string, string, string, string}> */
	public static function unavailable_action_cases(): iterable {
		yield 'create' => array(
			'create-secure-storage',
			'provisioner_unavailable',
			'Automatic secure storage setup is unavailable.',
			'Configuration automatique indisponible.',
		);
		yield 'recover' => array(
			'adopt-secure-storage',
			'provisioner_unavailable',
			'Automatic storage recovery is unavailable.',
			'Récupération automatique indisponible.',
		);
		yield 'reset' => array(
			'reset-empty-storage',
			'provisioner_unavailable',
			'Empty credential storage reset is unavailable.',
			'Réinitialisation du stockage indisponible.',
		);
	}

	#[DataProvider( 'unexpected_failure_action_cases' )]
	public function test_unexpected_storage_action_failures_localize_fallbacks_without_leaking_the_throwable(
		string $action,
		string $code,
		string $source_message,
		string $translated_message
	): void {
		$_POST['ran_booster']['action'] = $action;
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster'][ $source_message ] = $translated_message;
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::setup_available( '/private/canary/secrets.json' ),
			true
		);
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'set_secrets_storage_provisioning_result' )
			->with(
				self::callback(
					static fn ( SecretsStorageProvisioningResult $result ): bool => $code === $result->code()
						&& $translated_message === $result->message()
						&& null === $result->candidate_path()
						&& ! str_contains( $result->message(), 'canary' )
				)
			);

		$this->dispatcher( $dashboard, $provisioner )->dispatch_post_requests();
	}

	/** @return iterable<string, array{string, string, string, string}> */
	public static function unexpected_failure_action_cases(): iterable {
		yield 'create' => array(
			'create-secure-storage',
			'provisioning_failed',
			'Automatic secure storage setup could not be completed.',
			'Configuration automatique interrompue.',
		);
		yield 'recover' => array(
			'adopt-secure-storage',
			'recovery_failed',
			'Automatic storage recovery could not be completed.',
			'Récupération automatique interrompue.',
		);
		yield 'reset' => array(
			'reset-empty-storage',
			'storage_reset_failed',
			'Empty credential storage could not be reset safely.',
			'Réinitialisation sécurisée interrompue.',
		);
	}

	public function test_protected_post_provisions_and_redirects_without_result_data_in_the_url(): void {
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::pending_verification( '/private/canary/secrets.json' )
		);

		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatch_post_requests();
			self::fail( 'Successful setup must redirect for a fresh configuration load.' );
		} catch ( SetupActionRedirect $redirect ) {
			self::assertSame(
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
				$redirect->url
			);
			self::assertStringNotContainsString( 'private', $redirect->url );
		}

		self::assertSame( 1, $provisioner->provision_calls );
		self::assertSame(
			array( 'manage_options', 'activate_plugins' ),
			$GLOBALS['ran_booster_test_capability_checks']
		);
		self::assertSame(
			array( 'ran-booster-create-secure-storage' ),
			$GLOBALS['ran_booster_test_nonce_checks']
		);
	}

	public function test_get_cannot_provision_or_check_anonce(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$provisioner               = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::pending_verification( '/private/secrets.json' )
		);

		$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatch_post_requests();

		self::assertSame( 0, $provisioner->provision_calls );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_both_capabilities_are_required_before_mutation(): void {
		$GLOBALS['ran_booster_test_capabilities']['activate_plugins'] = false;
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::pending_verification( '/private/secrets.json' )
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatch_post_requests();
		} finally {
			self::assertSame( 0, $provisioner->provision_calls );
			self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
		}
	}

	public function test_failure_stays_on_the_protected_response_without_redirect_or_global_notice(): void {
		$result      = SecretsStorageProvisioningResult::manual_required(
			'filesystem_probe_failed',
			'The private storage filesystem could not be verified.',
			'/private/canary/secrets.json'
		);
		$provisioner = new SetupActionProvisioner( $result );
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'set_secrets_storage_provisioning_result' )
			->with( $result );
		$dashboard->expects( self::never() )->method( 'add_message' );
		$dashboard->expects( self::never() )->method( 'add_failure_message' );

		$this->dispatcher( $dashboard, $provisioner )->dispatch_post_requests();

		self::assertSame( 1, $provisioner->provision_calls );
	}

	public function test_unexpected_failure_is_reduced_to_apathless_protected_result(): void {
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::setup_available( '/private/canary/secrets.json' ),
			true
		);
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'set_secrets_storage_provisioning_result' )
			->with(
				self::callback(
					static fn ( SecretsStorageProvisioningResult $result ): bool => 'provisioning_failed' === $result->code()
						&& null === $result->candidate_path()
						&& ! str_contains( $result->message(), 'canary' )
				)
			);
		$dashboard->expects( self::never() )->method( 'add_failure_message' );

		$this->dispatcher( $dashboard, $provisioner )->dispatch_post_requests();
	}

	public function test_protected_post_adopts_atoken_bound_candidate_and_redirects(): void {
		$token       = str_repeat( 'a', 64 );
		$_POST       = array(
			'ran_booster' => array(
				'action'         => 'adopt-secure-storage',
				'recovery_token' => $token,
			),
		);
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::pending_verification( '/private/previous/secrets.json' )
		);

		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatch_post_requests();
			self::fail( 'Successful recovery must redirect for fresh configuration verification.' );
		} catch ( SetupActionRedirect $redirect ) {
			self::assertSame(
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
				$redirect->url
			);
		}

		self::assertSame( array( $token ), $provisioner->adopt_tokens );
		self::assertSame(
			array( 'ran-booster-adopt-secure-storage' ),
			$GLOBALS['ran_booster_test_nonce_checks']
		);
	}

	public function test_get_cannot_adopt_storage(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array(
			'ran_booster' => array(
				'action'         => 'adopt-secure-storage',
				'recovery_token' => str_repeat( 'a', 64 ),
			),
		);
		$provisioner               = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::pending_verification( '/private/previous/secrets.json' )
		);

		$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatch_post_requests();

		self::assertSame( array(), $provisioner->adopt_tokens );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_protected_post_resets_only_through_the_typed_guard_and_redirects_pathlessly(): void {
		$_POST       = array(
			'ran_booster' => array(
				'action'             => 'reset-empty-storage',
				'reset_confirmation' => SecretsStorageProvisioner::RESET_CONFIRMATION,
			),
		);
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::storage_reset(
				'/private/current/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
			)
		);

		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatch_post_requests();
			self::fail( 'Successful reset must redirect to a fresh protected Overview response.' );
		} catch ( SetupActionRedirect $redirect ) {
			self::assertSame(
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
				$redirect->url
			);
			self::assertStringNotContainsString( 'private', $redirect->url );
		}

		self::assertSame( array( SecretsStorageProvisioner::RESET_CONFIRMATION ), $provisioner->reset_confirmations );
		self::assertSame(
			array( 'ran-booster-reset-empty-storage' ),
			$GLOBALS['ran_booster_test_nonce_checks']
		);
	}

	public function test_get_cannot_reset_storage(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array(
			'ran_booster' => array(
				'action'             => 'reset-empty-storage',
				'reset_confirmation' => SecretsStorageProvisioner::RESET_CONFIRMATION,
			),
		);
		$provisioner               = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::storage_reset(
				'/private/current/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
			)
		);

		$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatch_post_requests();

		self::assertSame( array(), $provisioner->reset_confirmations );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function test_reset_requires_both_capabilities_before_nonce_or_mutation(): void {
		$GLOBALS['ran_booster_test_capabilities']['activate_plugins'] = false;
		$_POST       = array(
			'ran_booster' => array(
				'action'             => 'reset-empty-storage',
				'reset_confirmation' => SecretsStorageProvisioner::RESET_CONFIRMATION,
			),
		);
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::storage_reset(
				'/private/current/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
			)
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatch_post_requests();
		} finally {
			self::assertSame( array(), $provisioner->reset_confirmations );
			self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
		}
	}

	public function test_reset_requires_its_dedicated_valid_nonce(): void {
		$GLOBALS['ran_booster_test_nonce_valid'] = false;
		$_POST                                   = array(
			'ran_booster' => array(
				'action'             => 'reset-empty-storage',
				'reset_confirmation' => SecretsStorageProvisioner::RESET_CONFIRMATION,
			),
		);
		$provisioner                             = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::storage_reset(
				'/private/current/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
			)
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatch_post_requests();
		} finally {
			self::assertSame( array(), $provisioner->reset_confirmations );
			self::assertSame( array( 'ran-booster-reset-empty-storage' ), $GLOBALS['ran_booster_test_nonce_checks'] );
		}
	}

	public function test_unexpected_reset_failure_is_reduced_to_apathless_protected_result(): void {
		$_POST       = array(
			'ran_booster' => array(
				'action'             => 'reset-empty-storage',
				'reset_confirmation' => SecretsStorageProvisioner::RESET_CONFIRMATION,
			),
		);
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::storage_needs_attention(
				'/private/current/secrets.json',
				SecretsStorageProvisioningResult::PATH_SOURCE_AUTOMATIC
			),
			true
		);
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'set_secrets_storage_provisioning_result' )
			->with(
				self::callback(
					static fn ( SecretsStorageProvisioningResult $result ): bool => 'storage_reset_failed' === $result->code()
						&& null === $result->candidate_path()
						&& ! str_contains( $result->message(), 'canary' )
				)
			);

		$this->dispatcher( $dashboard, $provisioner )->dispatch_post_requests();
	}

	private function dispatcher( Dashboard $dashboard, ?SecretsStorageProvisioner $provisioner ): Dispatcher {
		$providers = new ProviderRegistry();
		$plugins   = new class() extends PluginRepository { public function __construct() {} };
		$themes    = new class() extends ThemeRepository { public function __construct() {} };

		return new SetupActionDispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageAdminController( repositories: new PackageRepositoryRequestResolver( $providers ), plugins: $plugins, themes: $themes, providers: $providers ),
			$this->createStub( WordPressUpdaterLock::class ),
			null,
			null,
			null,
			null,
			null,
			$provisioner
		);
	}
}

final class SetupActionProvisioner extends SecretsStorageProvisioner {
	public int $provision_calls = 0;
	/** @var list<string> */
	public array $adopt_tokens = array();
	/** @var list<string> */
	public array $reset_confirmations = array();

	public function __construct(
		private readonly SecretsStorageProvisioningResult $result,
		private readonly bool $should_throw = false
	) {
	}

	public function provision(): SecretsStorageProvisioningResult {
		++$this->provision_calls;
		if ( $this->should_throw ) {
			throw new \RuntimeException( 'Leaked path: /private/canary/secrets.json' );
		}

		return $this->result;
	}

	public function adopt_recovery( string $token ): SecretsStorageProvisioningResult {
		$this->adopt_tokens[] = $token;
		if ( $this->should_throw ) {
			throw new \RuntimeException( 'Leaked path: /private/canary/secrets.json' );
		}

		return $this->result;
	}

	public function reset_orphaned_storage( string $confirmation ): SecretsStorageProvisioningResult {
		$this->reset_confirmations[] = $confirmation;
		if ( $this->should_throw ) {
			throw new \RuntimeException( 'Leaked path: /private/canary/secrets.json' );
		}

		return $this->result;
	}
}

final class SetupActionDispatcher extends Dispatcher {
	protected function redirect_to( string $url ): never {
		// Test spy preserves the fixed redirect URL for assertions.
		throw new SetupActionRedirect( $url );
	}
}

final class SetupActionRedirect extends \RuntimeException {
	public function __construct( public readonly string $url ) {
		parent::__construct( 'Redirected.' );
	}
}
