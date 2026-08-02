<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused dispatcher fixtures stay beside their tests.

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once dirname( __DIR__ ) . '/Deployment/PackageMutationGuardWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\PackageEditProviderGuard;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Dashboard;
use RAN\Dispatcher;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use Tests\RepositoryProvider\Support\ExternalFixtureProvider;
use WP_Error;

final class PackageEditProviderGuardDispatcherTest extends TestCase {

	protected function setUp(): void {
		$_POST = array();
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
		$GLOBALS['ran_booster_test_capability_checks']           = array();
		$GLOBALS['ran_booster_test_nonce_checks']                = array();
		$GLOBALS['ran_booster_test_capabilities']                = array();
		$GLOBALS['ran_booster_test_nonce_valid']                 = true;
	}

	protected function tearDown(): void {
		$_POST = array();
		unset(
			$GLOBALS['ran_booster_package_mutation_guard_multisite'],
			$GLOBALS['ran_booster_test_capability_checks'],
			$GLOBALS['ran_booster_test_nonce_checks'],
			$GLOBALS['ran_booster_test_capabilities'],
			$GLOBALS['ran_booster_test_nonce_valid']
		);
	}

	/**
	 * @return array<string, array{string, array<string, string>}>
	 */
	public static function unavailableProviderEdits(): array {
		return array(
			'plugin' => array(
				'edit-plugin',
				array(
					'file'       => 'fixture/fixture.php',
					'provider'   => 'gh',
					'repository' => 'owner/replacement',
				),
			),
			'theme'  => array(
				'edit-theme',
				array(
					'stylesheet' => 'fixture-theme',
					'provider'   => 'gh',
					'repository' => 'owner/replacement',
				),
			),
		);
	}

	/** @param array<string, string> $request */
	#[DataProvider( 'unavailableProviderEdits' )]
	public function testUnavailableStoredProviderRejectsEditBeforeResolvingTheSubmittedProvider(
		string $action,
		array $request
	): void {
		$package              = EditBoundaryPackage::make( reset( $request ), 'temporarily-offline' );
		$plugins              = new EditBoundaryPluginRepository( $package );
		$themes               = new EditBoundaryThemeRepository( $package );
		$submitted            = new ExternalFixtureProvider( 'gh' );
		$providers            = new ProviderRegistry( array( $submitted ) );
		$_POST['ran_booster'] = array_merge( array( 'action' => $action ), $request );

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::once() )
			->method( 'addFailureMessage' )
			->with(
				self::callback(
					static fn ( mixed $message ): bool => $message instanceof WP_Error
						&& 'ran_booster_unavailable_package_provider' === $message->get_error_code()
				)
			);
		$dashboard->expects( self::never() )->method( 'postPackageOperation' );

		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatchPostRequests();

		self::assertSame( 0, $submitted->getClient()->getRequests() );
		self::assertSame( 1, $plugins->lookups + $themes->lookups );
	}

	public function testUnlinkRemainsAvailableWhenTheStoredProviderIsUnavailable(): void {
		$package              = EditBoundaryPackage::make( 'fixture/fixture.php', 'temporarily-offline' );
		$plugins              = new EditBoundaryPluginRepository( $package );
		$themes               = new EditBoundaryThemeRepository( $package );
		$providers            = new ProviderRegistry();
		$unlinkInput          = array(
			'action' => 'unlink-plugin',
			'file'   => 'fixture/fixture.php',
		);
		$_POST['ran_booster'] = $unlinkInput;

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::once() )->method( 'postPackageOperation' )->with( 'unlink-plugin', $unlinkInput );

		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatchPostRequests();

		self::assertSame( 0, $plugins->lookups );
	}

	public function testThemeUnlinkRemainsAvailableWhenTheStoredProviderIsUnavailable(): void {
		$package              = EditBoundaryPackage::make( 'fixture-theme', 'temporarily-offline' );
		$plugins              = new EditBoundaryPluginRepository( $package );
		$themes               = new EditBoundaryThemeRepository( $package );
		$providers            = new ProviderRegistry();
		$unlinkInput          = array(
			'action'     => 'unlink-theme',
			'stylesheet' => 'fixture-theme',
		);
		$_POST['ran_booster'] = $unlinkInput;

		$dashboard = $this->createMock( Dashboard::class );
		$dashboard->expects( self::never() )->method( 'addMessage' );
		$dashboard->expects( self::once() )->method( 'postPackageOperation' )->with( 'unlink-theme', $unlinkInput );

		$this->dispatcher( $dashboard, $providers, $plugins, $themes )->dispatchPostRequests();

		self::assertSame( 0, $themes->lookups );
	}

	private function dispatcher(
		Dashboard $dashboard,
		ProviderRegistry $providers,
		PluginRepository $plugins,
		ThemeRepository $themes
	): Dispatcher {
		return new Dispatcher(
			$dashboard,
			$providers,
			new SecretsFile( null, array() ),
			new PackageRepositoryRequestResolver( $providers ),
			new ManagedPackageWebhookAuthorityResolver( $plugins, $themes ),
			new PackageEditProviderGuard( $plugins, $themes, $providers ),
			$this->createStub( WordPressUpdaterLock::class )
		);
	}
}

final class EditBoundaryPackage extends AbstractPackage {

	private function __construct( private readonly string $identifier ) {
	}

	public static function make( string $identifier, string $provider ): self {
		$package = new self( $identifier );
		$package->setRepository( new ManagedRepository( $provider, 'owner/original', 'repository-id', 'main' ) );

		return $package;
	}

	public function getIdentifier(): mixed {
		return $this->identifier;
	}
}

final class EditBoundaryPluginRepository extends PluginRepository {

	public int $lookups = 0;

	public function __construct( private readonly Package $package ) {
	}

	public function boosterPluginFromFile( $file ) {
		++$this->lookups;

		return $this->package;
	}
}

final class EditBoundaryThemeRepository extends ThemeRepository {

	public int $lookups = 0;

	public function __construct( private readonly Package $package ) {
	}

	public function boosterThemeFromStylesheet( $stylesheet ) {
		++$this->lookups;

		return $this->package;
	}
}
