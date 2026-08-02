<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The bounded database fake belongs to its production-controller test.

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RAN\Admin\AdminAddOnRegistry;
use RAN\Admin\AdminAddOnTab;
use RAN\Admin\BulkPackageAction;
use RAN\Admin\BulkPackageResult;
use RAN\Admin\AdminTabRegistry;
use RAN\Admin\DevelopmentSafetyNoticeController;
use RAN\Admin\ProviderDocumentationPresenter;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Booster;
use RAN\Dashboard;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Logging\TemporaryDebugCapture;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use RAN\Storage\Database;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;
use RAN\Troubleshooting\LocalTroubleshootingService;
use RAN\Troubleshooting\TroubleshootingService;
use RuntimeException;
use Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;
use Tests\Support\CredentialUsageDatabase;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageOperationGlobalWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/WPError.php';
require_once dirname( __DIR__ ) . '/Logging/LoggingWordPressFunctions.php';
require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Dashboard.php';

final class DashboardIndexRoutingTest extends TestCase {

	protected function setUp(): void {
		$_GET = array();
		$GLOBALS['ran_booster_dashboard_test_multisite']         = false;
		$GLOBALS['ran_booster_dashboard_test_environment_type']  = 'production';
		$GLOBALS['ran_booster_dashboard_test_development_modes'] = array();
		$GLOBALS['ran_booster_dashboard_test_user_id']           = 7;
		$GLOBALS['ran_booster_dashboard_test_user_meta']         = array();
		$GLOBALS['ran_booster_dashboard_test_actions']           = array();
		$GLOBALS['ran_booster_dashboard_test_filters']           = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		unset(
			$GLOBALS['ran_booster_dashboard_test_multisite'],
			$GLOBALS['ran_booster_dashboard_test_environment_type'],
			$GLOBALS['ran_booster_dashboard_test_development_modes'],
			$GLOBALS['ran_booster_dashboard_test_user_id'],
			$GLOBALS['ran_booster_dashboard_test_user_meta'],
			$GLOBALS['ran_booster_dashboard_test_actions'],
			$GLOBALS['ran_booster_dashboard_test_filters']
		);
	}

	public function testDevelopmentSafetyNoticeDismissalIsScopedToTheCurrentAdministrator(): void {
		$GLOBALS['ran_booster_dashboard_test_environment_type'] = 'local';
		$GLOBALS['ran_booster_dashboard_test_user_meta'][7][ DevelopmentSafetyNoticeController::USER_META_KEY ] = '1';
		$predicate = new ReflectionMethod( Dashboard::class, 'shouldShowDevelopmentSafetyNotice' );
		$dashboard = $this->dashboard( $this->throwingSecrets() );

		self::assertFalse( $predicate->invoke( $dashboard, 'packages/index', array(), true ) );
		self::assertFalse( $predicate->invoke( $dashboard, 'packages/create', array(), true ) );

		$GLOBALS['ran_booster_dashboard_test_user_id'] = 8;

		self::assertTrue( $predicate->invoke( $dashboard, 'packages/index', array(), true ) );
		self::assertFalse( $predicate->invoke( $dashboard, 'packages/create', array(), true ) );
	}

	/** @return list<array{string, array<string, string>, bool, bool}> */
	public static function developmentSafetyNoticeProvider(): array {
		return array(
			array( 'packages/index', array(), true, true ),
			array( 'packages/create', array(), true, false ),
			array( 'packages/edit', array(), true, false ),
			array( 'index', array( 'tab' => 'portability' ), true, false ),
			array( 'index', array( 'tab' => 'documentation' ), true, false ),
			array( 'packages/index', array(), false, false ),
		);
	}

	/**
	 * @param array<string, string> $data             Selected view data.
	 */
	#[DataProvider( 'developmentSafetyNoticeProvider' )]
	public function testDevelopmentSafetyNoticeUsesDetectedEnvironmentOnlyOnThePackageIndex( string $view, array $data, bool $developmentEnvironmentDetected, bool $expected ): void {
		$predicate = new ReflectionMethod( Dashboard::class, 'shouldShowDevelopmentSafetyNotice' );

		self::assertSame( $expected, $predicate->invoke( $this->dashboard( $this->throwingSecrets() ), $view, $data, $developmentEnvironmentDetected ) );
	}

	public function testProviderTabBuildsOnlyTheSelectedProviderSettings(): void {
		$_GET['tab'] = 'bb';

		$data = $this->dashboard( new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ) )->getIndex()['data'];

		self::assertSame( 'bb', $data['tab'] );
		self::assertSame( 'provider.php', $data['tabView'] );
		self::assertSame( 'bb', $data['selected_provider'] );
		self::assertSame( 'Bitbucket', $data['provider']['label'] );
		self::assertSame(
			array( false, false, true, false, false, false ),
			array_column( $data['tabs'], 'active' )
		);
		self::assertArrayNotHasKey( 'onboarding', $data );
	}

	public function testProviderRouteSelectsFocusedViewsTasksAndBoundedListState(): void {
		$_GET = array(
			'tab'     => 'bb',
			'view'    => 'secrets',
			'panel'   => 'setup',
			's'       => ' workspace ',
			'scope'   => 'owner',
			'status'  => 'ready',
			'orderby' => 'usage',
			'order'   => 'desc',
		);

		$data = $this->dashboard( new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ) )->getIndex()['data'];

		self::assertSame( 'secrets', $data['providerView'] );
		self::assertSame( 'setup', $data['providerTask'] );
		self::assertSame(
			array(
				'search'   => 'workspace',
				'kind'     => '',
				'scope'    => 'owner',
				'status'   => 'ready',
				'orderby'  => 'usage',
				'order'    => 'desc',
				'paged'    => 1,
				'per_page' => 20,
			),
			$data['providerListState']
		);

		$_GET['view']    = 'unknown';
		$_GET['panel']   = 'unknown';
		$_GET['orderby'] = 'unknown';

		$fallback = $this->dashboard( new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ) )->getIndex()['data'];

		self::assertSame( 'overview', $fallback['providerView'] );
		self::assertSame( 'status', $fallback['providerTask'] );
		self::assertSame( 'name', $fallback['providerListState']['orderby'] );
	}

	public function testSelectedAddOnUsesTheRegisteredTabAndSafeContext(): void {
		$registry = new AdminAddOnRegistry( array(), 7, 7 );
		$registry->register(
			new AdminAddOnTab(
				'ran-booster-fixture',
				'fixture',
				'Fixture',
				static function (): void {},
				7,
				7,
				7,
				7
			)
		);
		$registry->seal();
		$_GET['tab'] = 'fixture';

		$data = $this->dashboard( $this->throwingSecrets(), adminAddOns: $registry )->getIndex()['data'];

		self::assertSame( 'fixture', $data['tab'] );
		self::assertArrayNotHasKey( 'tabView', $data );
		self::assertInstanceOf( AdminAddOnTab::class, $data['addOnTab'] );
		self::assertSame( 'fixture', $data['addOnContext']->tabKey() );
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture',
			$data['addOnContext']->boosterUrl()
		);
		self::assertSame( array( false, false, false, false, false, false, true ), array_column( $data['tabs'], 'active' ) );
	}

	public function testPortabilityBuildsDisplaySafeRowsFromTheNonCleaningInventory(): void {
		$_GET['tab'] = 'portability';
		$plugin      = $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );
		$theme       = $this->managedPackage( 'example-theme', 'Example Theme', 'theme-repository-id' );
		$plugins     = $this->createStub( PluginRepository::class );
		$themes      = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn( array( 'plugin/example.php' => $plugin ) );
		$themes->method( 'allDeploymentThemes' )->willReturn( array( 'example-theme' => $theme ) );

		$data = $this->dashboard( $this->throwingSecrets(), null, null, null, $plugins, $themes )->getIndex()['data'];

		self::assertFalse( $data['portabilityExportUnavailable'] );
		self::assertSame(
			array(
				array(
					'name'       => 'Example Plugin',
					'identifier' => 'plugin/example.php',
					'type'       => 'plugin',
				),
				array(
					'name'       => 'Example Theme',
					'identifier' => 'example-theme',
					'type'       => 'theme',
				),
			),
			$data['portabilityExportRows']
		);
	}

	/** @return list<array{string, string}> */
	public static function staticTabProvider(): array {
		return array(
			array( 'overview', 'onboarding.php' ),
			array( 'documentation', 'documentation.php' ),
			array( 'portability', 'portability.php' ),
			array( 'troubleshooting', 'troubleshooting.php' ),
		);
	}

	#[DataProvider( 'staticTabProvider' )]
	public function testStaticTabsDoNotReadCredentialOrWebhookProfiles( string $key, string $view ): void {
		$_GET['tab'] = $key;

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame( $key, $data['tab'] );
		self::assertSame( $view, $data['tabView'] );
		self::assertArrayNotHasKey( 'provider', $data );
		self::assertSame( 1, count( array_filter( $data['tabs'], static fn ( array $tab ): bool => $tab['active'] ) ) );
		self::assertSame( 'documentation' === $key, array_key_exists( 'providerDocumentation', $data ) );
		self::assertSame( 'overview' === $key, array_key_exists( 'onboarding', $data ) );

		if ( 'documentation' === $key ) {
			self::assertSame( array( 'gh', 'bb' ), array_column( $data['providerDocumentation'], 'code' ) );
		} elseif ( 'overview' === $key ) {
			self::assertSame( array( 'GitHub', 'Bitbucket' ), array_column( $data['onboarding']['provider_links'], 'label' ) );
		}
	}

	/** @return list<array{mixed}> */
	public static function fallbackTabProvider(): array {
		return array(
			array( null ),
			array( '' ),
			array( 'unknown' ),
			array( array( 'gh' ) ),
		);
	}

	#[DataProvider( 'fallbackTabProvider' )]
	public function testMissingUnknownAndArrayTabsFallBackWithoutDerivingAView( mixed $requested ): void {
		if ( null !== $requested ) {
			$_GET['tab'] = $requested;
		}

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame( 'overview', $data['tab'] );
		self::assertSame( 'onboarding.php', $data['tabView'] );
		self::assertArrayNotHasKey( 'selected_provider', $data );
		self::assertSame( array( 'GitHub', 'Bitbucket' ), array_column( $data['onboarding']['provider_links'], 'label' ) );
	}

	public function testNavigationUsesTheCorrectSingleAndNetworkAdminBases(): void {
		$_GET['tab'] = 'documentation';
		$singleSite  = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
			$singleSite['tabs'][0]['url']
		);

		$this->setMultisite( true );
		$networkSite = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame(
			'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=overview',
			$networkSite['tabs'][0]['url']
		);

		$_GET['tab']     = 'overview';
		$networkOverview = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];
		self::assertSame(
			'https://example.test/wp-admin/network/admin.php?page=ran-booster-plugins-create',
			$networkOverview['onboarding']['install_plugin_url']
		);
	}

	public function testLegacyAddOnTabRequestsFallBackToTheOverview(): void {
		$_GET['tab'] = 'assisted-hooks';

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame( 'overview', $data['tab'] );
		self::assertSame( 'onboarding.php', $data['tabView'] );
		self::assertArrayNotHasKey( 'addOnTab', $data );
	}

	public function testNativePackageHooksReceiveBoundedProjectionsForSettingsRowsAndActions(): void {
		$settingsReads   = array();
		$managementReads = array();
		$GLOBALS['ran_booster_dashboard_test_actions']['ran_booster_admin_package_settings_sections'][]  =
			static function ( \RAN\Admin\AdminPackageProjection $package, string $settingsUrl ) use ( &$settingsReads ): void {
				$settingsReads[] = array( $package->identifier(), $settingsUrl );
				echo '<section>Release settings</section>';
			};
		$GLOBALS['ran_booster_dashboard_test_filters']['ran_booster_admin_package_management_rows'][]    =
			static function ( array $rows, string $surface, array $packages ) use ( &$managementReads ): array {
				$managementReads[] = array( $surface, array_keys( $packages ) );

				$rows['plugin/example.php'] = array(
					'badges' => array(
						array(
							'label' => 'Release',
							'tone'  => 'ok',
						),
					),
					'status' => 'Latest release: 1.1.0.',
				);

				return $rows;
			};
		$GLOBALS['ran_booster_dashboard_test_filters']['ran_booster_admin_package_management_actions'][] =
			static function ( array $actions, string $surface, \RAN\Admin\AdminPackageProjection $package ): array {
				self::assertSame( 'plugin', $surface );

				return $actions + array(
					'fixture:manage' => array(
						'label' => 'Manage releases',
						'type'  => 'link',
						'url'   => $package->settingsUrl(),
					),
				);
			};
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$package   = $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );

		$panels  = ( new ReflectionMethod( Dashboard::class, 'packageExtensionPanels' ) )->invoke(
			$dashboard,
			$package,
			\RAN\Admin\PackageViewConfig::plugin()
		);
		$rows    = ( new ReflectionMethod( Dashboard::class, 'packageExtensionRows' ) )->invoke(
			$dashboard,
			array( $package ),
			\RAN\Admin\PackageViewConfig::plugin()
		);
		$actions = ( new ReflectionMethod( Dashboard::class, 'packageExtensionActions' ) )->invoke(
			$dashboard,
			array( $package ),
			\RAN\Admin\PackageViewConfig::plugin()
		);

		self::assertSame(
			array(
				array(
					'plugin/example.php',
					'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
				),
			),
			$settingsReads
		);
		self::assertSame( array( array( 'plugin', array( 'plugin/example.php' ) ) ), $managementReads );
		self::assertSame( array( '<section>Release settings</section>' ), $panels );
		self::assertSame( 'Latest release: 1.1.0.', $rows['plugin/example.php']['status'] );
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
			$actions['plugin/example.php']['fixture:manage']['url']
		);
	}

	public function testFailingNativePackageHooksDoNotBreakCorePackagePages(): void {
		$GLOBALS['ran_booster_dashboard_test_actions']['ran_booster_admin_package_settings_sections'][] =
			static function (): void {
				throw new RuntimeException( 'Settings unavailable.' );
			};
		$GLOBALS['ran_booster_dashboard_test_filters']['ran_booster_admin_package_management_rows'][]   =
			static function (): array {
				throw new RuntimeException( 'Management unavailable.' );
			};
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$package   = $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'plugin-repository-id' );

		$panels = ( new ReflectionMethod( Dashboard::class, 'packageExtensionPanels' ) )->invoke(
			$dashboard,
			$package,
			\RAN\Admin\PackageViewConfig::plugin()
		);
		$rows   = ( new ReflectionMethod( Dashboard::class, 'packageExtensionRows' ) )->invoke(
			$dashboard,
			array( $package ),
			\RAN\Admin\PackageViewConfig::plugin()
		);

		self::assertSame( array(), $panels );
		self::assertSame( array(), $rows );
	}

	public function testExplicitPackageSourceViewUsesOnlySharedAdvancedSectionsForPluginsAndThemes(): void {
		$_GET['source_view'] = 'branch';
		$GLOBALS['ran_booster_dashboard_test_actions']['ran_booster_admin_package_advanced_source_sections'][] =
			static function (): void {
				echo '<section>Advanced source settings</section>';
			};
		$dashboard   = $this->dashboard( $this->throwingSecrets() );
		$package     = $this->managedPackage( 'plugin/example.php', 'Example Package', 'repository-id' );
		$composition = new ReflectionMethod( Dashboard::class, 'packageSourceComposition' );

		foreach ( array( \RAN\Admin\PackageViewConfig::plugin(), \RAN\Admin\PackageViewConfig::theme() ) as $packageView ) {
			$data = $composition->invoke( $dashboard, 'edit', $packageView, $package );

			self::assertTrue( $data['advanced_open'], $packageView->getType() );
			self::assertSame( array( '<section>Advanced source settings</section>' ), $data['advanced_sections'], $packageView->getType() );
			self::assertArrayNotHasKey( 'sections', $data, $packageView->getType() );
		}
	}

	public function testTroubleshootingResultsRenderOnlyInTheSameDashboardRequest(): void {
		$_GET['tab'] = 'troubleshooting';
		$secrets     = new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() );
		$providers   = $this->providers();
		$local       = new class( $secrets ) extends LocalTroubleshootingService {
			public function diagnose(): array {
				$results = array();
				foreach ( array( 'one', 'two', 'three', 'four', 'five' ) as $code ) {
					$results[] = new ProviderDiagnosticResult(
						ProviderDiagnosticResult::PASSED,
						'local.' . $code,
						'The local check passed.',
						'No action is required.'
					);
				}

				return array(
					'results' => $results,
					'partial' => false,
				);
			}
		};
		$service     = new TroubleshootingService( $local, $providers );
		$dashboard   = $this->dashboard( $secrets, $service );

		self::assertFalse( $dashboard->getIndex()['data']['troubleshooting']['ran'] );
		$dashboard->postRunTroubleshooting( array( 'provider' => 'gh' ) );
		self::assertTrue( $dashboard->getIndex()['data']['troubleshooting']['ran'] );
		self::assertCount( 5, $dashboard->getIndex()['data']['troubleshooting']['results'] );
		self::assertFalse( $this->dashboard( $secrets, $service )->getIndex()['data']['troubleshooting']['ran'] );
	}

	public function testDeploymentActivityUsesItsOwnReadPayloadWithoutDiagnosticsResults(): void {
		$_GET = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data'];

		self::assertSame( 'activity', $data['troubleshootingPanel'] );
		self::assertSame( array(), $data['troubleshooting'] );
		self::assertTrue( $data['deploymentActivity']['unavailable'] );
		self::assertSame( 'list', $data['deploymentActivity']['mode'] );
	}

	public function testDebugCaptureUsesOnlyItsBoundedFilePayload(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-dashboard-capture-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new TemporaryDebugCapture( $directory . '/secrets.json' );

		try {
			$capture->start();
			self::assertTrue( $capture->append( '[ran-booster] safe dashboard event' ) );
			$_GET = array(
				'tab'   => 'troubleshooting',
				'panel' => 'debug-capture',
			);

			$data = $this->dashboard( $this->throwingSecrets(), null, null, null, null, null, $capture )->getIndex()['data'];

			self::assertSame( 'debug-capture', $data['troubleshootingPanel'] );
			self::assertSame( array(), $data['troubleshooting'] );
			self::assertSame( 'active', $data['debugCapture']['state'] );
			self::assertSame( 'ran-booster-debug.php', $data['debugCapture']['filename'] );
			self::assertStringContainsString( '[ran-booster] safe dashboard event', $data['debugCapture']['content'] );
			self::assertArrayNotHasKey( 'deploymentActivity', $data );
		} finally {
			$capture->delete();
			foreach ( array( $directory . '/ran-booster-debug.php.lock', $directory . '/ran-booster-debug.php' ) as $path ) {
				if ( is_file( $path ) || is_link( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture cleanup.
					unlink( $path );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
			rmdir( $directory );
		}
	}

	/** @return list<array{string, bool}> */
	public static function packageStorageReadProvider(): array {
		return array(
			array( 'plugin', false ),
			array( 'plugin', true ),
			array( 'theme', false ),
			array( 'theme', true ),
		);
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testSelectedPackageRoutesRenderTheSharedEditPayload( string $type ): void {
		$identifier = 'plugin' === $type ? 'example/example.php' : 'example-theme';
		$package    = $this->managedPackage( $identifier, 'Example Package', 'example-repository' );
		$plugins    = $this->createMock( PluginRepository::class );
		$themes     = $this->createMock( ThemeRepository::class );
		$_GET       = array( 'package' => $identifier );

		$plugins->expects( 'plugin' === $type ? self::once() : self::never() )
			->method( 'boosterPluginFromFile' )
			->with( $identifier )
			->willReturn( $package );
		$themes->expects( 'theme' === $type ? self::once() : self::never() )
			->method( 'boosterThemeFromStylesheet' )
			->with( $identifier )
			->willReturn( $package );

		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins, themes: $themes );
		$result    = 'plugin' === $type ? $dashboard->getPlugins() : $dashboard->getThemes();

		self::assertSame( 'packages/edit', $result['view'] );
		self::assertSame(
			array(
				'packageProviderSettings',
				'packageBranchReadiness',
				'packageWebhookCleanup',
				'package',
				'packageView',
				'packageExtensionPanels',
				'packageSource',
			),
			array_keys( $result['data'] )
		);
		self::assertSame( $package, $result['data']['package'] );
		self::assertSame( $type, $result['data']['packageView']->getType() );
		self::assertSame( array(), $result['data']['packageExtensionPanels'] );
		self::assertSame( 'branch', $result['data']['packageSource']['current'] );
		self::assertSame( 'branch', $result['data']['packageSource']['selected'] );
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testMissingSelectedPackagesFallBackToTheMatchingIndex( string $type ): void {
		$identifier = 'plugin' === $type ? 'missing/missing.php' : 'missing-theme';
		$fallback   = $this->managedPackage(
			'plugin' === $type ? 'available/available.php' : 'available-theme',
			'Available Package',
			'available-repository'
		);
		$plugins    = $this->createMock( PluginRepository::class );
		$themes     = $this->createMock( ThemeRepository::class );
		$_GET       = array( 'package' => $identifier );

		if ( 'plugin' === $type ) {
			$plugins->expects( self::once() )
				->method( 'boosterPluginFromFile' )
				->with( $identifier )
				->willThrowException( new PluginNotFound( 'Missing fixture plugin.' ) );
			$plugins->expects( self::once() )->method( 'allBoosterPlugins' )->willReturn( array( $fallback ) );
			$themes->expects( self::never() )->method( 'boosterThemeFromStylesheet' );
			$themes->expects( self::never() )->method( 'allBoosterThemes' );
		} else {
			$themes->expects( self::once() )
				->method( 'boosterThemeFromStylesheet' )
				->with( $identifier )
				->willThrowException( new ThemeNotFound( 'Missing fixture theme.' ) );
			$themes->expects( self::once() )->method( 'allBoosterThemes' )->willReturn( array( $fallback ) );
			$plugins->expects( self::never() )->method( 'boosterPluginFromFile' );
			$plugins->expects( self::never() )->method( 'allBoosterPlugins' );
		}

		$dashboard = $this->dashboard( $this->throwingSecrets(), plugins: $plugins, themes: $themes );
		$result    = 'plugin' === $type ? $dashboard->getPlugins() : $dashboard->getThemes();

		self::assertSame( 'packages/index', $result['view'] );
		self::assertSame( array( $fallback ), $result['data']['packages'] );
		self::assertSame( $type, $result['data']['packageView']->getType() );
		self::assertSame( 1, $result['data']['packageListTotal'] );
	}

	/** @return list<array{string, string}> */
	public static function packageListFilterProvider(): array {
		return array(
			array( 'plugin', 'Release Plugin' ),
			array( 'theme', 'Release Theme' ),
		);
	}

	#[DataProvider( 'packageListFilterProvider' )]
	public function testPackageIndexesApplyCombinedNormalizedFiltersForBothPackageTypes( string $type, string $releaseName ): void {
		$_GET    = array(
			's'        => ' release ',
			'provider' => 'BB',
			'source'   => 'release_asset',
			'policy'   => 'automatic',
		);
		$branch  = $this->managedPackage(
			'plugin' === $type ? 'alpha/alpha.php' : 'alpha-theme',
			'plugin' === $type ? 'Alpha Plugin' : 'Alpha Theme',
			'alpha-repository'
		);
		$release = $this->managedPackage(
			'plugin' === $type ? 'release/release.php' : 'release-theme',
			$releaseName,
			'release-repository',
			\RAN\PackageSource::RELEASE_ASSET,
			'bb',
			\RAN\Deployment\DeploymentPolicy::AUTOMATIC,
			'studio/release-package',
			'stable'
		);
		$other   = $this->managedPackage(
			'plugin' === $type ? 'other/other.php' : 'other-theme',
			'plugin' === $type ? 'Other Plugin' : 'Other Theme',
			'other-repository',
			\RAN\PackageSource::BRANCH,
			'gh',
			\RAN\Deployment\DeploymentPolicy::AUTOMATIC
		);
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allBoosterPlugins' )->willReturn( 'plugin' === $type ? array( $branch, $release, $other ) : array() );
		$themes->method( 'allBoosterThemes' )->willReturn( 'theme' === $type ? array( $branch, $release, $other ) : array() );

		$result = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			themes: $themes
		);
		$data   = 'plugin' === $type ? $result->getPlugins()['data'] : $result->getThemes()['data'];

		self::assertSame( 3, $data['packageListTotal'] );
		self::assertSame(
			array(
				'search'   => 'release',
				'provider' => 'bb',
				'source'   => 'release_asset',
				'policy'   => 'automatic',
			),
			$data['packageListState']
		);
		self::assertSame( array( 'Bitbucket', 'GitHub' ), array_column( $data['packageProviderOptions'], 'label' ) );
		self::assertSame( array( $release->getIdentifier() ), array_map( static fn ( Package $package ): mixed => $package->getIdentifier(), $data['packages'] ) );
	}

	#[DataProvider( 'packageListFilterProvider' )]
	public function testPackageIndexesDiscardMalformedAndUnsupportedFilterValues( string $type, string $releaseName ): void {
		unset( $releaseName );
		$_GET    = array(
			's'        => array( 'not-scalar' ),
			'provider' => 'missing-provider',
			'source'   => 'archive',
			'policy'   => 'sometimes',
		);
		$package = $this->managedPackage(
			'plugin' === $type ? 'example/example.php' : 'example-theme',
			'Example Package',
			'example-repository'
		);
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allBoosterPlugins' )->willReturn( 'plugin' === $type ? array( $package ) : array() );
		$themes->method( 'allBoosterThemes' )->willReturn( 'theme' === $type ? array( $package ) : array() );

		$dashboard = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			plugins: $plugins,
			themes: $themes
		);
		$data      = 'plugin' === $type ? $dashboard->getPlugins()['data'] : $dashboard->getThemes()['data'];

		self::assertSame(
			array(
				'search'   => '',
				'provider' => '',
				'source'   => '',
				'policy'   => '',
			),
			$data['packageListState']
		);
		self::assertSame( array( $package ), $data['packages'] );
	}

	#[DataProvider( 'packageStorageReadProvider' )]
	public function testInvalidPackageStorageRendersASafeEmptyIndex( string $type, bool $detail ): void {
		if ( $detail ) {
			$_GET['package'] = 'upstream-provider-canary';
		}
		$dashboard = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			null,
			null,
			null,
			new FailingDashboardPluginRepository(),
			new FailingDashboardThemeRepository()
		);

		$result = 'plugin' === $type ? $dashboard->getPlugins() : $dashboard->getThemes();

		self::assertSame( 'packages/index', $result['view'] );
		self::assertSame( array(), $result['data']['packages'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'error', $dashboard->messages[0]['type'] );
		self::assertSame( 'ran_booster_storage_invalid_provider_identity', $dashboard->messages[0]['code'] );
		self::assertSame( array( 'recovery_required' => false ), $dashboard->messages[0]['data'] );
		self::assertStringNotContainsString( 'upstream-provider-canary', $dashboard->messages[0]['message'] );
	}

	#[DataProvider( 'packageTypeProvider' )]
	public function testIncompatibleDatabaseRendersADisabledCreateScreen( string $type ): void {
		$connection = new class() {
			public string $last_error = '';

			public function db_server_info(): string {
				return 'PostgreSQL 17.5';
			}
		};
		$dashboard  = $this->dashboard(
			new SecretsFile( '/path/that/does/not/exist.php', array(), ShippedSecretPolicyCatalog::create() ),
			database: new Database( $connection )
		);

		$result = 'plugin' === $type ? $dashboard->getPluginsCreate() : $dashboard->getThemesCreate();

		self::assertSame( 'packages/create', $result['view'] );
		self::assertFalse( $result['data']['packageMutationAvailable'] );
		self::assertFalse( $result['data']['openRepositoryPicker'] );
		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'ran_booster_storage_database_unsupported', $dashboard->messages[0]['code'] );
	}

	/** @return list<array{string}> */
	public static function packageTypeProvider(): array {
		return array(
			array( 'plugin' ),
			array( 'theme' ),
		);
	}

	public function testBulkPackageRedirectPreservesOnlyNormalizedListFilters(): void {
		$_GET = array(
			's'        => ' release ',
			'provider' => 'BB',
			'source'   => 'release_asset',
			'policy'   => 'automatic',
			'unsafe'   => '<script>',
		);

		$url = $this->dashboard( $this->throwingSecrets() )->bulkPackageRedirect(
			'theme',
			BulkPackageResult::policy(
				BulkPackageAction::POLICY_AUTOMATIC,
				array(
					'selected'  => 1,
					'changed'   => 1,
					'unchanged' => 0,
				)
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Focused redirect-query assertion.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		self::assertSame( 'ran-booster-themes', $query['page'] );
		self::assertSame( 'release', $query['s'] );
		self::assertSame( 'bb', $query['provider'] );
		self::assertSame( 'release_asset', $query['source'] );
		self::assertSame( 'automatic', $query['policy'] );
		self::assertArrayNotHasKey( 'unsafe', $query );
		self::assertArrayHasKey( '_ran_booster_bulk_notice_nonce', $query );
	}

	public function testSignedBulkQueueNoticeReportsPartialSuccessAndUnavailableRunner(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::queue(
				4,
				2,
				array(
					'busy'     => 1,
					'disabled' => 1,
				),
				'unavailable'
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$dashboard->getPlugins();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'warning', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'Queued 2 plugins', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'branch reinstall', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'already queued, running, or needs attention: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'deployment disabled: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'could not schedule the deployment runner', $dashboard->messages[0]['message'] );
		self::assertSame( 'bulk_update_queue', $dashboard->messages[0]['code'] );
		self::assertSame( 2, $dashboard->messages[0]['queued_updates'] );
		self::assertSame( 2, $dashboard->messages[0]['skipped_updates'] );
	}

	public function testSignedBulkQueueNoticeReportsWhenEverySelectionWasSkipped(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::queue(
				2,
				0,
				array( 'disabled' => 2 ),
				'not_required'
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$dashboard->getPlugins();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'warning', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'Queued 0 plugins', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Skipped: 2', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'deployment disabled: 2', $dashboard->messages[0]['message'] );
		self::assertStringNotContainsString( 'could not schedule the deployment runner', $dashboard->messages[0]['message'] );
	}

	public function testSignedBulkPolicyNoticeReportsChangedAndUnchangedCounts(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'theme',
			BulkPackageResult::policy(
				BulkPackageAction::POLICY_MANUAL,
				array(
					'selected'  => 3,
					'changed'   => 2,
					'unchanged' => 1,
				)
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$dashboard->getThemes();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'success', $dashboard->messages[0]['type'] );
		self::assertSame(
			'Changed 2 themes to Manual. Already in that state: 1.',
			$dashboard->messages[0]['message']
		);
	}

	public function testSignedBulkActivationNoticeReportsPartialWordPressStateChange(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::pluginActivation(
				BulkPackageAction::DEACTIVATE_PLUGINS,
				4,
				1,
				1,
				array(
					'deactivation_failed' => 1,
					'self_deactivation'   => 1,
				)
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );

		$dashboard->getPlugins();

		self::assertCount( 1, $dashboard->messages );
		self::assertSame( 'warning', $dashboard->messages[0]['type'] );
		self::assertStringContainsString( 'Changed 1 plugins to Disabled in WordPress', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Already in that state: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'deactivation failed: 1', $dashboard->messages[0]['message'] );
		self::assertStringContainsString( 'Booster cannot disable itself: 1', $dashboard->messages[0]['message'] );
	}

	public function testTamperedBulkNoticeIsIgnored(): void {
		$dashboard = $this->dashboard( $this->throwingSecrets() );
		$url       = $dashboard->bulkPackageRedirect(
			'plugin',
			BulkPackageResult::queue( 1, 1, array(), 'scheduled' )
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url, WordPress.Security.NonceVerification.Recommended -- The test reconstructs the signed redirect query.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET );
		$_GET['ran_booster_bulk_queued'] = '20';

		$dashboard->getPlugins();

		self::assertSame( array(), $dashboard->messages );
	}

	public function testPackageOverviewReadsBranchActivityOnly(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'succeeded' ) );
		$dashboard      = $this->dashboard(
			$this->throwingSecrets(),
			null,
			null,
			$this->deploymentAttempts( $database )
		);
		$branch         = $this->managedPackage( 'plugin/branch.php', 'Branch Plugin', 'branch-repository' );
		$release        = $this->managedPackage(
			'plugin/release.php',
			'Release Plugin',
			'release-repository',
			\RAN\PackageSource::RELEASE_ASSET
		);

		$activity = ( new ReflectionMethod( Dashboard::class, 'packageActivity' ) )->invoke(
			$dashboard,
			array( $branch, $release ),
			'plugin'
		);

		self::assertFalse( $activity['unavailable'] );
		self::assertArrayHasKey( 'plugin/branch.php', $activity['items'] );
		self::assertArrayNotHasKey( 'plugin/release.php', $activity['items'] );
	}

	public function testShortDeploymentHistoryDoesNotOfferAnOlderPage(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array_map( static fn ( int $id ): array => DashboardActivityWpdb::attempt( $id, 'succeeded' ), range( 1, 9 ) );
		$_GET           = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard(
			$this->throwingSecrets(),
			null,
			null,
			$this->deploymentAttempts( $database )
		)->getIndex()['data']['deploymentActivity'];

		self::assertCount( 9, $data['items'] );
		self::assertSame( 9, $data['items'][0]->getId() );
		self::assertNull( $data['next_cursor'] );
		self::assertFalse( $data['has_cursor'] );
	}

	public function testDeploymentActivityProvidesExactManagedPackageSettingsUrls(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'failed' ) );
		$plugins        = $this->createStub( PluginRepository::class );
		$themes         = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn(
			array(
				'plugin/example.php' => $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'repository-1' ),
			)
		);
		$themes->method( 'allDeploymentThemes' )->willReturn(
			array(
				'example-theme' => $this->managedPackage( 'example-theme', 'Example Theme', 'repository-2' ),
			)
		);
		$_GET = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard(
			$this->throwingSecrets(),
			null,
			null,
			$this->deploymentAttempts( $database ),
			$plugins,
			$themes
		)->getIndex()['data']['deploymentActivity'];

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
			$data['package_settings_urls']['plugin']['example']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=example-theme',
			$data['package_settings_urls']['theme']['example']
		);
	}

	public function testDeploymentActivityLinksFailClosedForAmbiguousOrUnavailableInventory(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'failed' ) );
		$plugins        = $this->createStub( PluginRepository::class );
		$themes         = $this->createStub( ThemeRepository::class );
		$plugins->method( 'allDeploymentPlugins' )->willReturn(
			array(
				'plugin/example.php'       => $this->managedPackage( 'plugin/example.php', 'Example Plugin', 'repository-1' ),
				'other-example/plugin.php' => $this->managedPackage( 'other-example/plugin.php', 'Other Example Plugin', 'repository-2' ),
			)
		);
		$themes->method( 'allDeploymentThemes' )->willThrowException( PackageStorageFailure::invalidProviderIdentity() );
		$_GET = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$data = $this->dashboard(
			$this->throwingSecrets(),
			null,
			null,
			$this->deploymentAttempts( $database ),
			$plugins,
			$themes
		)->getIndex()['data']['deploymentActivity'];

		self::assertFalse( $data['unavailable'] );
		self::assertCount( 1, $data['items'] );
		self::assertArrayNotHasKey( 'example', $data['package_settings_urls']['plugin'] );
		self::assertSame( array(), $data['package_settings_urls']['theme'] );
	}

	public function testDeploymentHistoryUsesLookaheadWithoutOverlappingPages(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array_map( static fn ( int $id ): array => DashboardActivityWpdb::attempt( $id, 'succeeded' ), range( 1, 51 ) );
		$attempts       = $this->deploymentAttempts( $database );
		$_GET           = array(
			'tab'   => 'troubleshooting',
			'panel' => 'deployment-activity',
		);

		$firstPage = $this->dashboard( $this->throwingSecrets(), null, null, $attempts )->getIndex()['data']['deploymentActivity'];

		self::assertCount( 50, $firstPage['items'] );
		self::assertSame( 51, $firstPage['items'][0]->getId() );
		self::assertSame( 2, $firstPage['items'][49]->getId() );
		self::assertSame( 2, $firstPage['next_cursor'] );

		$_GET['before'] = '2';
		$lastPage       = $this->dashboard( $this->throwingSecrets(), null, null, $attempts )->getIndex()['data']['deploymentActivity'];

		self::assertCount( 1, $lastPage['items'] );
		self::assertSame( 1, $lastPage['items'][0]->getId() );
		self::assertNull( $lastPage['next_cursor'] );
		self::assertTrue( $lastPage['has_cursor'] );
	}

	public function testExhaustedDeploymentHistoryCursorRemainsAnOlderPage(): void {
		$database       = new DashboardActivityWpdb();
		$database->rows = array( DashboardActivityWpdb::attempt( 1, 'succeeded' ) );
		$_GET           = array(
			'tab'    => 'troubleshooting',
			'panel'  => 'deployment-activity',
			'before' => '1',
		);

		$data = $this->dashboard(
			$this->throwingSecrets(),
			null,
			null,
			$this->deploymentAttempts( $database )
		)->getIndex()['data']['deploymentActivity'];

		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['has_cursor'] );
		self::assertFalse( $data['unavailable'] );
	}

	public function testMalformedDeploymentHistoryCursorFailsClosed(): void {
		$_GET = array(
			'tab'    => 'troubleshooting',
			'panel'  => 'deployment-activity',
			'before' => '01',
		);

		$data = $this->dashboard( $this->throwingSecrets(), null, null, $this->deploymentAttempts( new DashboardActivityWpdb() ) )
			->getIndex()['data']['deploymentActivity'];

		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['has_cursor'] );
		self::assertTrue( $data['unavailable'] );
	}

	public function testMalformedActivityIdentityDoesNotFallBackToABroadList(): void {
		$_GET = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => '01',
			'reference' => str_repeat( 'a', 32 ),
		);

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data']['deploymentActivity'];

		self::assertSame( 'detail', $data['mode'] );
		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['unavailable'] );
	}

	public function testArrayActivityIdentityDoesNotFallBackToABroadList(): void {
		$_GET = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => array( '1' ),
			'reference' => array( str_repeat( 'a', 32 ) ),
		);

		$data = $this->dashboard( $this->throwingSecrets() )->getIndex()['data']['deploymentActivity'];

		self::assertSame( 'detail', $data['mode'] );
		self::assertSame( array(), $data['items'] );
		self::assertTrue( $data['unavailable'] );
	}

	public function testAttemptDetailLoadsOnlyTheRequestedAttempt(): void {
		$attempt        = DashboardActivityWpdb::attempt( 1, 'succeeded' );
		$database       = new DashboardActivityWpdb();
		$database->rows = array( $attempt, DashboardActivityWpdb::attempt( 2, 'failed' ) );
		$attempts       = $this->deploymentAttempts( $database );
		$_GET           = array(
			'tab'       => 'troubleshooting',
			'panel'     => 'deployment-activity',
			'attempt'   => '1',
			'reference' => $attempt['correlation_id'],
		);

		$data = $this->dashboard( $this->throwingSecrets(), null, null, $attempts )->getIndex()['data']['deploymentActivity'];

		self::assertFalse( $data['unavailable'] );
		self::assertSame( 1, $data['detail']->getId() );
		self::assertSame( 'deployed', $data['detail']->getOutcome()?->getCode() );
		self::assertArrayNotHasKey( 'actions', $data );
	}

	private function setMultisite( bool $multisite ): void {
		$GLOBALS['ran_booster_dashboard_test_multisite'] = $multisite;
	}

	private function deploymentAttempts( DashboardActivityWpdb $database ): DeploymentAttemptRepository {
		return new DeploymentAttemptRepository(
			$database,
			'wp_ran_booster_deployment_attempts',
			databaseLifecycle: new ReadyDashboardDatabase()
		);
	}

	private function dashboard(
		SecretsFile $secrets,
		?TroubleshootingService $troubleshooting = null,
		?\RAN\PackageOperationService $packageOperations = null,
		?DeploymentAttemptRepository $deploymentAttempts = null,
		?PluginRepository $plugins = null,
		?ThemeRepository $themes = null,
		?TemporaryDebugCapture $debugCapture = null,
		?Database $database = null,
		?AdminAddOnRegistry $adminAddOns = null
	): RoutingDashboard {
		$providers = $this->providers();

		return new RoutingDashboard(
			$database ?? new Database(),
			$plugins ?? new class() extends PluginRepository {

				public function __construct() {
				}

				public function allBoosterPlugins(): array {
					return array();
				}

				public function allDeploymentPlugins( ?\RAN\PackageSource $source = null ): array {
					return array();
				}
			},
			new Booster(),
			$themes ?? new class() extends ThemeRepository {

				public function __construct() {
				}

				public function allBoosterThemes(): array {
					return array();
				}

				public function allDeploymentThemes( ?\RAN\PackageSource $source = null ): array {
					return array();
				}
			},
			new ProviderSettingsPresenter( $providers, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ) ),
			$troubleshooting ?? new TroubleshootingService( new LocalTroubleshootingService( $secrets ), $providers ),
			new AdminTabRegistry( $providers ),
			new ProviderDocumentationPresenter( $providers ),
			$packageOperations,
			$deploymentAttempts,
			$debugCapture,
			null,
			$adminAddOns
		);
	}

	private function throwingSecrets(): SecretsFile {
		return new class() extends SecretsFile {

			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			public function credentialProfiles( ProviderCode|string $provider ): array {
				throw new RuntimeException( 'Static tabs must not read credential profiles.' );
			}

			public function webhookProfiles( ProviderCode|string $provider ): array {
				throw new RuntimeException( 'Static tabs must not read webhook profiles.' );
			}
		};
	}

	private function managedPackage(
		string $identifier,
		string $name,
		string $providerRepositoryId,
		\RAN\PackageSource $source = \RAN\PackageSource::BRANCH,
		string $provider = 'gh',
		\RAN\Deployment\DeploymentPolicy $policy = \RAN\Deployment\DeploymentPolicy::MANUAL,
		string $repository = 'owner/repository',
		string $branch = 'main'
	): Package {
		$package = $this->createStub( Package::class );
		$package->method( 'getIdentifier' )->willReturn( $identifier );
		$package->method( 'getDisplayName' )->willReturn( $name );
		$package->method( 'getSlug' )->willReturn( 'example' );
		$package->method( 'getProviderCode' )->willReturn( $provider );
		$package->method( 'getProviderRepositoryId' )->willReturn( $providerRepositoryId );
		$package->method( 'getRepository' )->willReturn( new ManagedRepository( $provider, $repository, $providerRepositoryId, $branch, false, null ) );
		$package->method( 'getBranch' )->willReturn( $branch );
		$package->method( 'getSubdirectory' )->willReturn( null );
		$package->method( 'getSource' )->willReturn( $source );
		$package->method( 'getSourceRevision' )->willReturn( 1 );
		$package->method( 'getDeploymentPolicy' )->willReturn( $policy );

		return $package;
	}

	private function providers(): ProviderRegistry {
		return new ProviderRegistry(
			array(
				$this->provider( ProviderCode::parse( 'gh' ), 'GitHub' ),
				$this->provider( ProviderCode::parse( 'bb' ), 'Bitbucket' ),
			)
		);
	}

	private function provider( ProviderCode $code, string $label ): RepositoryProvider {
		return new class( $code, $label ) implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct(
				private ProviderCode $code,
				private string $label
			) {
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					$this->code,
					$this->label,
					'https://example.test/',
					'Owner',
					new ProviderAdminMetadata( array(), array() )
				);
			}
		};
	}
}

final class FailingDashboardPluginRepository extends PluginRepository {
	public function allBoosterPlugins(): array {
		throw PackageStorageFailure::invalidProviderIdentity();
	}

	public function boosterPluginFromFile( $file ) {
		throw PackageStorageFailure::invalidProviderIdentity();
	}
}

final class FailingDashboardThemeRepository extends ThemeRepository {
	public function allBoosterThemes(): array {
		throw PackageStorageFailure::invalidProviderIdentity();
	}

	public function boosterThemeFromStylesheet( $stylesheet ) {
		throw PackageStorageFailure::invalidProviderIdentity();
	}
}

final class DashboardActivityWpdb {

	/** @var list<array<string, mixed>> */
	public array $rows        = array();
	public string $prefix     = 'wp_';
	public string $last_error = '';

	public function db_server_info(): string {
		return '8.4.6';
	}

	public function prepare( string $query, mixed ...$arguments ): string {
		foreach ( $arguments as $argument ) {
			$query = (string) preg_replace_callback(
				'/%[dis]/',
				static fn ( array $match ): string => '%i' === $match[0]
					? '`' . (string) $argument . '`'
					: ( '%d' === $match[0] ? (string) (int) $argument : "'" . addslashes( (string) $argument ) . "'" ),
				$query,
				1
			);
		}

		return $query;
	}

	public function query( string $query ): int|false {
		return 0;
	}

	/** @param array<string, mixed> $data */
	public function insert( string $table, array $data ): int|false {
		return false;
	}

	/** @return list<object> */
	public function get_results( string $query ): array {
		if ( 'SHOW ENGINES' === $query ) {
			return array(
				(object) array(
					'Engine'  => 'InnoDB',
					'Support' => 'DEFAULT',
				),
			);
		}
		if ( preg_match( '/WHERE id = (\\d+)/', $query, $match ) === 1 ) {
			return array_values(
				array_map(
					static fn ( array $row ): object => (object) $row,
					array_filter( $this->rows, static fn ( array $row ): bool => (int) $row['id'] === (int) $match[1] )
				)
			);
		}
		if ( str_contains( $query, 'package_type IN' ) ) {
			$rows = $this->rows;
			if ( preg_match( '/AND id < (\\d+)/', $query, $match ) === 1 ) {
				$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (int) $row['id'] < (int) $match[1] ) );
			}
			usort( $rows, static fn ( array $left, array $right ): int => (int) $right['id'] <=> (int) $left['id'] );
			$limit = preg_match( '/LIMIT (\\d+)/', $query, $match ) === 1 ? (int) $match[1] : count( $rows );

			return array_map( static fn ( array $row ): object => (object) $row, array_slice( $rows, 0, $limit ) );
		}
		return array();
	}

	/** @return array<string, mixed> */
	public static function attempt( int $id, string $state ): array {
		$outcomeCode = 'succeeded' === $state ? 'deployed' : 'preflight_failed';

		return array(
			'id'                      => $id,
			'correlation_id'          => str_pad( dechex( $id ), 32, '0', STR_PAD_LEFT ),
			'source'                  => 'manual',
			'operation'               => 'update',
			'package_type'            => 'plugin',
			'package_slug'            => 'example',
			'package_source'          => 'branch',
			'package_source_revision' => 1,
			'release_identity'        => null,
			'provider'                => 'gh',
			'provider_repository_id'  => 'repository-1',
			'requested_ref'           => 'main',
			'resolved_ref'            => str_repeat( 'f', 40 ),
			'delivery_id'             => null,
			'delivery_digest'         => null,
			'state'                   => $state,
			'mutation_started_at'     => null,
			'outcome_code'            => $outcomeCode,
			'request_json'            => '{"repository":"org/example","credential_id":null,"private":false,"configured_branch":"main","package_slug":"example","subdirectory":null,"deployment_policy":"automatic","initiating_user_id":1}',
			'created_at'              => '2026-07-19 00:00:00',
			'finished_at'             => '2026-07-19 00:00:00',
		);
	}
}

final class ReadyDashboardDatabase extends Database {
	public function __construct() {
	}

	public function requireReady(): void {
	}
}
