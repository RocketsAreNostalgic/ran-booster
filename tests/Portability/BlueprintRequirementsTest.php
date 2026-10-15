<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintRequirements;
use RAN\Portability\PackageBlueprint;

require_once __DIR__ . '/BlueprintRequirementsWordPressFunctions.php';

#[CoversClass( BlueprintRequirements::class )]
final class BlueprintRequirementsTest extends TestCase {

	#[\PHPUnit\Framework\Attributes\Before]
	protected function prepare_headers(): void {
		$GLOBALS['ran_booster_blueprint_headers'] = array();
		$GLOBALS['ran_booster_wp_pusher_plugins'] = array();
		$GLOBALS['ran_booster_blueprint_active']  = array();
		$GLOBALS['ran_booster_blueprint_themes']  = array();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The isolated fixture supplies the destination WordPress version.
		$GLOBALS['wp_version'] = '6.8';
	}

	#[\PHPUnit\Framework\Attributes\After]
	protected function remove_headers(): void {
		$directory = sys_get_temp_dir() . '/ran-blueprint-requirements/child';
		if ( is_file( $directory . '/style.css' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable test files use native filesystem operations without loading WordPress.
			unlink( $directory . '/style.css' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable test files use native filesystem operations without loading WordPress.
			rmdir( $directory );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable test files use native filesystem operations without loading WordPress.
			rmdir( dirname( $directory ) );
		}
		unset( $GLOBALS['ran_booster_blueprint_read_failure'], $GLOBALS['ran_booster_blueprint_headers'], $GLOBALS['ran_booster_wp_pusher_plugins'], $GLOBALS['ran_booster_blueprint_active'], $GLOBALS['ran_booster_blueprint_themes'], $GLOBALS['wp_version'] );
	}

	public function test_installed_observations_omit_bad_fields_without_losing_good_declarations(): void {
		$this->headers(
			array(
				'version'            => '1.2',
				'requires_php'       => '>=8.1',
				'requires_wordpress' => '6.5',
				'parent_theme'       => '',
			)
		);
		$result = ( new BlueprintRequirements() )->installed( $this->package( 'theme', 'child' ) );
		self::assertSame( array( 'requires_php' => 'malformed' ), $result['issues'] );
		self::assertSame(
			array(
				'basis'             => 'installed_headers',
				'observed_identity' => array( 'version' => '1.2' ),
				'declarations'      => array(
					'requires_wordpress' => '6.5',
					'parent_theme'       => null,
				),
			),
			$result['requirements']
		);
	}

	public function test_missing_header_read_is_not_exported_as_not_declared(): void {
		$this->headers(
			array(
				'version'      => '',
				'requires_php' => '',
				'parent_theme' => 'parent',
			)
		);
		$result = ( new BlueprintRequirements() )->installed( $this->package( 'theme', 'child' ) );
		self::assertSame( array( 'requires_wordpress' => 'unavailable' ), $result['issues'] );
		self::assertSame(
			array(
				'requires_php' => null,
				'parent_theme' => 'parent',
			),
			$result['requirements']['declarations'] ?? null
		);
		self::assertSame( array(), $result['requirements']['observed_identity'] ?? null );
	}

	public function test_read_failure_after_readability_check_remains_unavailable(): void {
		$this->headers( array( 'requires_php' => '' ) );
		$GLOBALS['ran_booster_blueprint_read_failure'] = true;
		$result                                        = ( new BlueprintRequirements() )->installed( $this->package( 'theme', 'child' ) );
		self::assertNull( $result['requirements'] );
		self::assertSame( array( 'headers' => 'unavailable' ), $result['issues'] );
	}

	public function test_unreadable_installed_files_do_not_create_an_observation(): void {
		$result = ( new BlueprintRequirements() )->installed( $this->package( 'theme', 'missing' ) );
		self::assertNull( $result['requirements'] );
		self::assertSame( array( 'headers' => 'unavailable' ), $result['issues'] );
	}

	public function test_moving_source_failure_is_advisory_and_no_headers_do_not_pass(): void {
		$blueprint = new PackageBlueprint(
			array(
				$this->package(
					'plugin',
					'example/example.php',
					array(
						'requires_php'       => '99',
						'requires_wordpress' => null,
						'requires_plugins'   => array(),
					)
				),
				$this->package( 'theme', 'child' ),
			)
		);
		$result    = ( new BlueprintRequirements() )->assess( $blueprint );
		self::assertSame( 'warning', $result['packages'][0]['recommendation'] );
		self::assertSame( 'failed', $result['packages'][0]['checks']['requires_php'] );
		self::assertSame( 'not_declared', $result['packages'][0]['checks']['requires_wordpress'] );
		self::assertSame( 'not_supplied', $result['packages'][1]['checks']['requires_php'] );
		self::assertSame( 'not_checked', $result['packages'][1]['activation_readiness'] );
		self::assertSame(
			array(
				'minimum'      => '99',
				'declared'     => 1,
				'not_declared' => 0,
				'missing'      => 1,
			),
			$result['summary']['requires_php']
		);
	}

	public function test_selected_package_failure_is_a_recommendation_not_an_installation_operation(): void {
		$record                                      = $this->package( 'plugin', 'example/example.php', array( 'requires_wordpress' => '99' ) )->to_array();
		$record['selection']                         = array(
			'commit' => str_repeat( 'a', 40 ),
		);
		$record['requirements']['basis']             = 'selected_package';
		$record['requirements']['observed_identity'] = array( 'commit' => str_repeat( 'a', 40 ) );
		$result                                      = ( new BlueprintRequirements() )->assess( new PackageBlueprint( array( BlueprintPackage::from_array( $record ) ) ) );
		self::assertSame( 'blocked_recommendation', $result['packages'][0]['recommendation'] );
		unset( $GLOBALS['wp_version'] );
		$result = ( new BlueprintRequirements() )->assess( new PackageBlueprint( array( BlueprintPackage::from_array( $record ) ) ) );
		self::assertSame( 'not_checked', $result['packages'][0]['checks']['requires_wordpress'] );
	}

	public function test_dependencies_distinguish_local_active_inactive_planned_omitted_ambiguous_and_missing(): void {
		$GLOBALS['ran_booster_wp_pusher_plugins'] = array(
			'active/main.php'   => array(),
			'inactive/main.php' => array(),
			'ambiguous/one.php' => array(),
			'ambiguous/two.php' => array(),
		);
		$GLOBALS['ran_booster_blueprint_active']  = array( 'active/main.php' );
		$blueprint                                = new PackageBlueprint(
			array(
				$this->package( 'plugin', 'consumer/main.php', array( 'requires_plugins' => array( 'active', 'inactive', 'planned', 'omitted', 'ambiguous', 'missing' ) ) ),
				$this->package( 'plugin', 'planned/main.php' ),
				$this->package( 'plugin', 'omitted/main.php' ),
			)
		);
		$result                                   = ( new BlueprintRequirements() )->assess(
			$blueprint,
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'consumer/main.php',
				),
				array(
					'type'       => 'plugin',
					'identifier' => 'planned/main.php',
				),
			)
		);
		self::assertSame(
			array(
				'active'    => 'active',
				'inactive'  => 'inactive',
				'planned'   => 'selected',
				'omitted'   => 'omitted',
				'ambiguous' => 'unresolved',
				'missing'   => 'missing',
			),
			$result['packages'][0]['dependencies']
		);
		self::assertSame( 'declared_dependencies_not_ready', $result['packages'][0]['activation_readiness'] );
		self::assertSame( 'not_verified', $result['packages'][0]['recommendation'] );
		self::assertCount( 2, $result['packages'] );
		self::assertSame( 2, $result['summary']['requires_php']['missing'] );
	}

	public function test_single_file_plugin_slug_candidates_remain_unresolved(): void {
		$GLOBALS['ran_booster_wp_pusher_plugins'] = array( 'installed.php' => array() );
		$blueprint                                = new PackageBlueprint(
			array(
				$this->package( 'plugin', 'consumer/main.php', array( 'requires_plugins' => array( 'installed', 'planned' ) ) ),
				$this->package( 'plugin', 'planned.php' ),
			)
		);
		$result                                   = ( new BlueprintRequirements() )->assess( $blueprint );
		self::assertSame(
			array(
				'installed' => 'unresolved',
				'planned'   => 'unresolved',
			),
			$result['packages'][0]['dependencies']
		);
	}

	public function test_parent_theme_presence_does_not_require_parent_activation(): void {
		$GLOBALS['ran_booster_blueprint_themes'] = array( 'parent' => new \stdClass() );
		$result                                  = ( new BlueprintRequirements() )->assess( new PackageBlueprint( array( $this->package( 'theme', 'child', array( 'parent_theme' => 'parent' ) ) ) ) );
		self::assertSame( array( 'parent' => 'present' ), $result['packages'][0]['dependencies'] );
		self::assertSame( 'declared_dependencies_satisfied', $result['packages'][0]['activation_readiness'] );
	}

	/** @param array<string,string> $headers */
	private function headers( array $headers ): void {
		$directory = sys_get_temp_dir() . '/ran-blueprint-requirements/child';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable test files use native filesystem operations without loading WordPress.
		mkdir( $directory, 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable test files use native filesystem operations without loading WordPress.
		file_put_contents( $directory . '/style.css', '/* local test header */' );
		$GLOBALS['ran_booster_blueprint_headers'][ $directory . '/style.css' ] = $headers;
	}

	/** @param array<string,mixed>|null $declarations */
	private function package( string $type, string $identifier, ?array $declarations = null ): BlueprintPackage {
		return new BlueprintPackage(
			$type,
			$identifier,
			'Example',
			'gh',
			'id-' . $identifier,
			'owner/repo',
			'main',
			null,
			requirements: null === $declarations ? null : array(
				'basis'             => 'installed_headers',
				'observed_identity' => array(),
				'declarations'      => $declarations,
			)
		);
	}
}
