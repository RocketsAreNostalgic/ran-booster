<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused action spies belong to this dispatcher test.

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
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

	protected function setUp(): void {
		$_POST                     = array(
			'ran_booster' => array( 'action' => 'create-secure-storage' ),
		);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['ran_booster_test_capability_checks'] = array();
		$GLOBALS['ran_booster_test_nonce_checks']      = array();
		$GLOBALS['ran_booster_test_capabilities']      = array();
		$GLOBALS['ran_booster_test_nonce_valid']       = true;
		$GLOBALS['ran_booster_package_view_multisite'] = false;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset(
			$_SERVER['REQUEST_METHOD'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid'],
			$GLOBALS['ran_booster_package_view_multisite']
		);
	}

	public function testProtectedPostProvisionsAndRedirectsWithoutResultDataInTheUrl(): void {
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::pendingVerification( '/private/canary/secrets.json' )
		);

		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatchPostRequests();
			self::fail( 'Successful setup must redirect for a fresh configuration load.' );
		} catch ( SetupActionRedirect $redirect ) {
			self::assertSame(
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
				$redirect->url
			);
			self::assertStringNotContainsString( 'private', $redirect->url );
		}

		self::assertSame( 1, $provisioner->provisionCalls );
		self::assertSame(
			array( 'manage_options', 'activate_plugins' ),
			$GLOBALS['ran_booster_test_capability_checks']
		);
		self::assertSame(
			array( 'ran-booster-create-secure-storage' ),
			$GLOBALS['ran_booster_test_nonce_checks']
		);
	}

	public function testGetCannotProvisionOrCheckANonce(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$provisioner               = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::pendingVerification( '/private/secrets.json' )
		);

		$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatchPostRequests();

		self::assertSame( 0, $provisioner->provisionCalls );
		self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
	}

	public function testBothCapabilitiesAreRequiredBeforeMutation(): void {
		$GLOBALS['ran_booster_test_capabilities']['activate_plugins'] = false;
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::pendingVerification( '/private/secrets.json' )
		);

		$this->expectException( \RuntimeException::class );
		try {
			$this->dispatcher( $this->createStub( Dashboard::class ), $provisioner )->dispatchPostRequests();
		} finally {
			self::assertSame( 0, $provisioner->provisionCalls );
			self::assertSame( array(), $GLOBALS['ran_booster_test_nonce_checks'] );
		}
	}

	public function testFailureStaysOnTheProtectedResponseWithoutRedirectOrGlobalNotice(): void {
		$result      = SecretsStorageProvisioningResult::manualRequired(
			'filesystem_probe_failed',
			'The private storage filesystem could not be verified.',
			'/private/canary/secrets.json'
		);
		$provisioner = new SetupActionProvisioner( $result );
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'setSecretsStorageProvisioningResult' )
			->with( $result );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );

		$this->dispatcher( $dashboard, $provisioner )->dispatchPostRequests();

		self::assertSame( 1, $provisioner->provisionCalls );
	}

	public function testUnexpectedFailureIsReducedToAPathlessProtectedResult(): void {
		$provisioner = new SetupActionProvisioner(
			SecretsStorageProvisioningResult::setupAvailable( '/private/canary/secrets.json' ),
			true
		);
		$dashboard   = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'setSecretsStorageProvisioningResult' )
			->with(
				self::callback(
					static fn ( SecretsStorageProvisioningResult $result ): bool => 'provisioning_failed' === $result->code()
						&& null === $result->candidatePath()
						&& ! str_contains( $result->message(), 'canary' )
				)
			);
		$dashboard->expects( self::never() )->method( 'addFailureMessage' );

		$this->dispatcher( $dashboard, $provisioner )->dispatchPostRequests();
	}

	private function dispatcher( Dashboard $dashboard, SecretsStorageProvisioner $provisioner ): Dispatcher {
		$providers = new ProviderRegistry( new \Tests\Support\NullLoggingFacade() );
		$plugins   = new class() extends PluginRepository { public function __construct() {} };
		$themes    = new class() extends ThemeRepository { public function __construct() {} };

		return new SetupActionDispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new PackageRepositoryRequestResolver( $providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageEditProviderGuard( $plugins, $themes, $providers ),
			$this->createStub( WordPressUpdaterLock::class ),
			null,
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
	public int $provisionCalls = 0;

	public function __construct(
		private readonly SecretsStorageProvisioningResult $result,
		private readonly bool $throw = false
	) {
	}

	public function provision(): SecretsStorageProvisioningResult {
		++$this->provisionCalls;
		if ( $this->throw ) {
			throw new \RuntimeException( 'Leaked path: /private/canary/secrets.json' );
		}

		return $this->result;
	}
}

final class SetupActionDispatcher extends Dispatcher {
	protected function redirectTo( string $url ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test spy preserves the fixed redirect URL for assertions.
		throw new SetupActionRedirect( $url );
	}
}

final class SetupActionRedirect extends \RuntimeException {
	public function __construct( public readonly string $url ) {
		parent::__construct( 'Redirected.' );
	}
}
