<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RAN\Admin\OnboardingPresenter;
use RAN\Activity\ActivityStore;
use RAN\Tests\Activity\ActivityConnection;
use RAN\Deployment\DeploymentPolicy;
use RAN\Plugin;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Theme;
use RuntimeException;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once __DIR__ . '/../Activity/ActivityWordPressFunctions.php';
require_once __DIR__ . '/../Activity/ActivityConnection.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class OverviewPresenterTest extends TestCase {
	public function test_inventory_counts_managed_packages_including_disabled(): void {
		$plugin = new Plugin();
		$plugin->set_deployment_policy( DeploymentPolicy::DISABLED );
		$theme   = new Theme();
		$plugins = $this->plugins( array( $plugin ) );
		$themes  = $this->themes( array( $theme ) );
		$result  = $this->present( $plugins, $themes );
		self::assertSame(
			array(
				'plugins'  => 1,
				'themes'   => 1,
				'disabled' => 1,
				'complete' => true,
			),
			$result['inventory']
		);
		self::assertSame( 1, $GLOBALS['ran_booster_overview_plugin_calls'] );
		self::assertSame( 1, $GLOBALS['ran_booster_overview_theme_calls'] );
		self::assertSame(
			array(
				'available' => false,
				'records'   => array(),
			),
			$result['activity']
		);
		self::assertSame( array( 'ready' => true ), $result['storage_summary'] );
	}

	public function test_partial_failure_does_not_claim_zero_or_complete(): void {
		$plugins = $this->plugins( null );
		$themes  = $this->themes( array( new Theme() ) );
		$result  = $this->present( $plugins, $themes );
		self::assertSame(
			array(
				'plugins'  => null,
				'themes'   => 1,
				'disabled' => null,
				'complete' => false,
			),
			$result['inventory']
		);
		self::assertSame( 1, $GLOBALS['ran_booster_overview_plugin_calls'] );
		self::assertSame( 1, $GLOBALS['ran_booster_overview_theme_calls'] );
	}

	public function test_empty_and_explicit_choices_are_read_on_each_request(): void {
		$plugins = $this->plugins( array() );
		$themes  = $this->themes( array() );
		unset( $GLOBALS['ran_booster_storage_test_options'][ OnboardingPresenter::PRESENTATION_OPTION ] );
		self::assertSame( 'dashboard', $this->present( $plugins, $themes )['view'] );
		foreach ( array( '', 'invalid', array(), 'dashboard', 'onboarding', 'dashboard' ) as $choice ) {
			$GLOBALS['ran_booster_storage_test_options'][ OnboardingPresenter::PRESENTATION_OPTION ] = $choice;
			self::assertSame( 'onboarding' === $choice ? 'onboarding' : 'dashboard', $this->present( $plugins, $themes )['view'] );
		}
		unset( $GLOBALS['ran_booster_storage_test_options'][ OnboardingPresenter::PRESENTATION_OPTION ] );
	}

	public function test_provider_facts_are_scoped_to_provider_tabs_without_inventing_admission(): void {
		$tabs = array();
		foreach ( array( 'portability', 'documentation', 'troubleshooting', 'gh', 'bb', 'unknown' ) as $key ) {
			$tabs[] = array(
				'key'      => $key,
				'label'    => strtoupper( $key ),
				'url'      => '/' . $key,
				'active'   => false,
				'provider' => in_array( $key, array( 'gh', 'bb', 'unknown' ), true ),
			);
		}
		foreach ( array( 'conflict', 'missing', 'recovery_required', 'storage_unavailable', 'available' ) as $state ) {
			$result = ( new OnboardingPresenter() )->build_overview(
				$tabs,
				'/install',
				'/theme',
				$this->plugins( array() ),
				$this->themes( array() ),
				null,
				null,
				array(
					'gh'     => array(
						'admitted'  => false,
						'selection' => $state,
					),
					'bb'     => array(
						'admitted'  => true,
						'selection' => null,
					),
					'hidden' => array(
						'admitted'  => true,
						'selection' => null,
					),
				)
			);
			self::assertSame(
				array(
					array(
						'code'      => 'gh',
						'label'     => 'GH',
						'url'       => '/gh',
						'admitted'  => false,
						'selection' => $state,
					),
					array(
						'code'      => 'bb',
						'label'     => 'BB',
						'url'       => '/bb',
						'admitted'  => true,
						'selection' => null,
					),
					array(
						'code'      => 'unknown',
						'label'     => 'UNKNOWN',
						'url'       => '/unknown',
						'admitted'  => null,
						'selection' => null,
					),
				),
				$result['provider_summaries']
			);
			self::assertCount( 3, $result['provider_links'] );
		}
	}

	public function test_activity_projects_only_safe_fields_and_distinguishes_empty_from_unavailable(): void {
		$connection                              = new ActivityConnection();
		$activity                                = new ActivityStore( $connection );
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$connection->read_results                = array(
			array(
				(object) array(
					'operation' => 'release.check',
					'status'    => 'succeeded',
					'sort_at'   => '2026-10-30 12:00:00',
					'facts'     => 'secret',
					'message'   => 'secret',
				),
			),
		);
		$result                                  = $this->present( $this->plugins( array() ), $this->themes( array() ), $activity );
		self::assertSame(
			array(
				'available' => true,
				'records'   => array(
					array(
						'operation' => 'release.check',
						'status'    => 'succeeded',
						'sort_at'   => '2026-10-30 12:00:00',
					),
				),
			),
			$result['activity']
		);
		self::assertCount( 1, $connection->queries );
		self::assertStringContainsString( 'LIMIT 5', $connection->queries[0] );
		self::assertSame(
			array(
				'available' => true,
				'records'   => array(),
			),
			$this->present( $this->plugins( array() ), $this->themes( array() ), $activity )['activity']
		);
		$GLOBALS['ran_booster_activity_options'] = array();
		self::assertSame(
			array(
				'available' => false,
				'records'   => array(),
			),
			$this->present( $this->plugins( array() ), $this->themes( array() ), $activity )['activity']
		);
	}

	/** @param list<Plugin>|null $packages */
	private function plugins( ?array $packages ): PluginRepository {
		$GLOBALS['ran_booster_overview_plugin_calls'] = 0;
		return new class( $packages ) extends PluginRepository {

			/** @param list<Plugin>|null $packages */
			public function __construct( private ?array $packages ) {}
			public function all_booster_plugins(): array {
				++$GLOBALS['ran_booster_overview_plugin_calls'];
				if ( null === $this->packages ) {
					throw new RuntimeException( 'Unavailable' );
				}
				return $this->packages;
			}
		};
	}

	/** @param list<Theme>|null $packages */
	private function themes( ?array $packages ): ThemeRepository {
		$GLOBALS['ran_booster_overview_theme_calls'] = 0;
		return new class( $packages ) extends ThemeRepository {

			/** @param list<Theme>|null $packages */
			public function __construct( private ?array $packages ) {}
			public function all_booster_themes(): array {
				++$GLOBALS['ran_booster_overview_theme_calls'];
				if ( null === $this->packages ) {
					throw new RuntimeException( 'Unavailable' );
				}
				$result = array();
				foreach ( $this->packages as $index => $package ) {
					$result[ 'theme-' . $index ] = $package;
				}
				return $result;
			}
		};
	}

	/** @return array<string, mixed> */
	private function present( PluginRepository $plugins, ThemeRepository $themes, ?ActivityStore $activity = null ): array {
		$tabs = array();
		foreach ( array( 'portability', 'documentation', 'troubleshooting' ) as $key ) {
			$tabs[] = array(
				'key'      => $key,
				'label'    => $key,
				'url'      => '/' . $key,
				'active'   => false,
				'provider' => false,
			);
		}
		return ( new OnboardingPresenter() )->build_overview( $tabs, '/install-plugin', '/install-theme', $plugins, $themes, $activity, array( 'ready' => true ) );
	}
}
