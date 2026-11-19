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

	public function test_automatic_presentation_tracks_managed_inventory_and_explicit_choices_win(): void {
		$GLOBALS['ran_booster_storage_test_options'][ OnboardingPresenter::PRESENTATION_OPTION ] = 'automatic';
		self::assertSame( 'onboarding', $this->present( $this->plugins( array() ), $this->themes( array() ) )['view'] );
		self::assertSame( 'dashboard', $this->present( $this->plugins( array( new Plugin() ) ), $this->themes( array() ) )['view'] );
		self::assertSame( 'dashboard', $this->present( $this->plugins( array() ), $this->themes( array( new Theme() ) ) )['view'] );
		self::assertSame( 'dashboard', $this->present( $this->plugins( null ), $this->themes( array() ) )['view'] );
		self::assertSame( 'dashboard', $this->present( $this->plugins( null ), $this->themes( array( new Theme() ) ) )['view'] );

		$GLOBALS['ran_booster_storage_test_options'][ OnboardingPresenter::PRESENTATION_OPTION ] = 'onboarding';
		self::assertSame( 'onboarding', $this->present( $this->plugins( array( new Plugin() ) ), $this->themes( array() ) )['view'] );
		$GLOBALS['ran_booster_storage_test_options'][ OnboardingPresenter::PRESENTATION_OPTION ] = 'dashboard';
		self::assertSame( 'dashboard', $this->present( $this->plugins( array() ), $this->themes( array() ) )['view'] );
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
						'code'                 => 'gh',
						'label'                => 'GH',
						'url'                  => '/gh',
						'admitted'             => false,
						'selection'            => $state,
						'source_kind'          => null,
						'source_name'          => null,
						'replacement_detected' => false,
					),
					array(
						'code'                 => 'bb',
						'label'                => 'BB',
						'url'                  => '/bb',
						'admitted'             => true,
						'selection'            => null,
						'source_kind'          => null,
						'source_name'          => null,
						'replacement_detected' => false,
					),
					array(
						'code'                 => 'unknown',
						'label'                => 'UNKNOWN',
						'url'                  => '/unknown',
						'admitted'             => null,
						'selection'            => null,
						'source_kind'          => null,
						'source_name'          => null,
						'replacement_detected' => false,
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

	public function test_attention_lists_failed_and_uncertain_updates_critical_first_within_one_cap(): void {
		$connection                              = new ActivityConnection();
		$GLOBALS['ran_booster_activity_options'] = array( ActivityStore::VERSION_OPTION => '1' );
		$token                                   = 'gh:repository-1:11111111-1111-4111-8111-111111111111';
		$plugins                                 = array();
		foreach ( array( 'failed', 'review', 'clear', 'untracked' ) as $slug ) {
			$plugin = Plugin::from_wp_array(
				"$slug/$slug.php",
				array_fill_keys( array( 'PluginURI', 'Version', 'Description', 'Author', 'AuthorURI', 'TextDomain', 'DomainPath', 'Title', 'AuthorName' ), '' ) + array(
					'Name'    => ucfirst( $slug ),
					'Network' => false,
				)
			);
			if ( 'untracked' !== $slug ) {
				$plugin->set_adoption_token( $token );
			}
			$plugins[] = $plugin;
		}
		$row                      = static fn ( string $slug, string $status, string $facts ): object => (object) array(
			'context_key'      => hash( 'sha256', "plugin\0$slug/$slug.php\0$token\0" ),
			'provider'         => 'gh',
			'subject_type'     => 'plugin',
			'subject_identity' => "$slug/$slug.php",
			'adoption_token'   => $token,
			'correlation'      => "corr-$slug",
			'status'           => $status,
			'facts'            => $facts,
		);
		$connection->read_results = array(
			array(),
			array(
				$row( 'review', 'incomplete', '{"deployment_attempt_id":41,"deployment_resolved_at":null}' ),
				$row( 'failed', 'failed', '{"deployment_attempt_id":40}' ),
				$row( 'clear', 'succeeded', '{"deployment_attempt_id":39}' ),
			),
		);
		$credential               = static fn ( string $stage ): array => array(
			'provider_label' => 'GitHub',
			'label'          => $stage,
			'badge_label'    => "badge-$stage",
			'stage'          => $stage,
			'url'            => "/credentials-$stage",
		);
		$credentials              = array(
			'status' => 'captured',
			'total'  => 5,
			'items'  => array( $credential( 'warning' ), $credential( 'expired' ), $credential( 'urgent' ), $credential( 'expired' ), $credential( 'urgent' ) ),
		);
		$result                   = $this->present( $this->plugins( $plugins ), $this->themes( array() ), new ActivityStore( $connection ), $credentials );
		$attention                = $result['attention'];
		self::assertTrue( $attention['updates_available'] );
		self::assertSame( 7, $attention['total'] );
		self::assertSame( 1, $attention['hidden_critical'] );
		self::assertSame( array( 'Failed', 'Review', 'GitHub: expired', 'GitHub: urgent', 'GitHub: expired' ), array_column( $attention['items'], 'label' ) );
		self::assertSame( array( 'Update failed', 'Needs review', 'badge-expired', 'badge-urgent', 'badge-expired' ), array_column( $attention['items'], 'badge' ) );
		self::assertSame( array( true, true, true, true, true ), array_column( $attention['items'], 'critical' ) );
		self::assertSame( $result['activity_url'] . '&activity_package=' . rawurlencode( (string) wp_json_encode( array( 'gh', 'plugin', 'failed/failed.php' ) ) ), $attention['items'][0]['url'] );
		self::assertSame( $result['activity_url'] . '&attempt=41&reference=corr-review', $attention['items'][1]['url'] );
		self::assertSame( $credentials, $result['credential_expiry'] );
		$keys = array_map( static fn ( string $slug ): string => hash( 'sha256', "plugin\0$slug/$slug.php\0$token\0" ), array( 'failed', 'review', 'clear' ) );
		self::assertSame( $keys, $connection->prepared[ count( $connection->prepared ) - 1 ]['args'] ?? null );

		$result = $this->present( $this->plugins( $plugins ), $this->themes( array() ), null, $credentials );
		self::assertFalse( $result['attention']['updates_available'] );
		self::assertSame( array( 'badge-expired', 'badge-urgent', 'badge-expired', 'badge-urgent', 'badge-warning' ), array_column( $result['attention']['items'], 'badge' ) );
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

	/**
	 * @param array{status:string,total:?int,items:list<array{provider_label:string,label:string,badge_label:string,stage:string,url:string}>}|null $credentials
	 * @return array<string, mixed>
	 */
	private function present( PluginRepository $plugins, ThemeRepository $themes, ?ActivityStore $activity = null, ?array $credentials = null ): array {
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
		return ( new OnboardingPresenter() )->build_overview( $tabs, '/install-plugin', '/install-theme', $plugins, $themes, $activity, array( 'ready' => true ), array(), $credentials );
	}
}
