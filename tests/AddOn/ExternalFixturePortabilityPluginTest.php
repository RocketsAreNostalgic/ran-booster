<?php

declare(strict_types=1);

namespace RAN\Tests\AddOn;

require_once __DIR__ . '/../Support/ExternalFixtureAddOnWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\Portability\PortabilityApplyResult;
use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\AddOn\Portability\PortabilityFacade;
use RAN\AddOn\Portability\PortabilityReviewResult;

final class ExternalFixturePortabilityPluginTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_fixture_loaded_before_core_receives_only_exact_api_three_facade(): void {
		$this->load_fixture();
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 3 );

		$this->run_hook( 'plugins_loaded' );
		$this->run_hook( 'ran_booster_portability_ready', $this->facade() );

		$this->assert_fixture_results();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_fixture_loaded_after_core_uses_the_same_exact_contract(): void {
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 3 );
		$this->load_fixture();

		$this->run_hook( 'plugins_loaded' );
		$this->run_hook( 'ran_booster_portability_ready', $this->facade() );

		$this->assert_fixture_results();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[DataProvider( 'mismatched_core_apis' )]
	public function test_fixture_fails_soft_without_core_or_with_an_exact_version_mismatch( int $core_api ): void {
		$this->load_fixture();
		$this->run_hook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_portability_ready', $GLOBALS['ran_booster_external_fixture_addon_actions'] );

		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', $core_api );
		$this->load_fixture();
		$this->run_hook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_portability_ready', $GLOBALS['ran_booster_external_fixture_addon_actions'] );
	}

	/** @return array<string, array{int}> */
	public static function mismatched_core_apis(): array {
		return array(
			'API 1' => array( 1 ),
			'API 2' => array( 2 ),
			'API 4' => array( 4 ),
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[DataProvider( 'old_bridge_load_orders' )]
	public function test_api_two_bridge_registers_no_feature_hooks_or_facade_calls_on_api_three( bool $core_first ): void {
		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		unset( $GLOBALS['ran_booster_fixture_portability_results'] );
		if ( $core_first ) {
			define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 3 );
		}
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-portability-addon/old-api2-bridge.php';
		if ( ! $core_first ) {
			define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 3 );
		}

		$this->run_hook( 'plugins_loaded' );

		self::assertSame( array( 'plugins_loaded' ), array_keys( $GLOBALS['ran_booster_external_fixture_addon_actions'] ) );
		$facade = $this->createMock( PortabilityFacade::class );
		$facade->expects( self::never() )->method( 'review' );
		$facade->expects( self::never() )->method( 'apply' );
		foreach ( $GLOBALS['ran_booster_external_fixture_addon_actions']['ran_booster_portability_ready'] ?? array() as $callback ) {
			$callback( $facade );
		}
		self::assertArrayNotHasKey( 'ran_booster_fixture_portability_results', $GLOBALS );
	}

	/** @return array<string, array{bool}> */
	public static function old_bridge_load_orders(): array {
		return array(
			'Core before API 2 bridge' => array( true ),
			'API 2 bridge before Core' => array( false ),
		);
	}

	private function load_fixture(): void {
		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		unset( $GLOBALS['ran_booster_fixture_portability_results'] );
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-portability-addon/ran-booster-fixture-portability-addon.php';
	}

	private function run_hook( string $hook, mixed ...$arguments ): void {
		$callbacks = $GLOBALS['ran_booster_external_fixture_addon_actions'][ $hook ] ?? array();
		self::assertCount( 1, $callbacks, sprintf( 'The %s callback must be registered once.', $hook ) );
		$callbacks[0]( ...$arguments );
	}

	private function facade(): PortabilityFacade {
		return new class() extends PortabilityFacade {
			public function review( PortabilityCandidate $candidate, string $nonce ): PortabilityReviewResult {
				TestCase::assertSame( 'fixture/fixture.php', $candidate->identifier );
				TestCase::assertSame( 'fixture-review-nonce', $nonce );

				return new PortabilityReviewResult(
					$candidate,
					PortabilityReviewResult::ADOPT,
					'none',
					'Ready.',
					'v1:' . str_repeat( 'a', 64 )
				);
			}

			public function apply( PortabilityCandidate $candidate, string $expected_fingerprint, string $nonce ): PortabilityApplyResult {
				TestCase::assertSame( 'fixture/fixture.php', $candidate->identifier );
				TestCase::assertSame( 'v1:' . str_repeat( 'a', 64 ), $expected_fingerprint );
				TestCase::assertSame( 'fixture-apply-nonce', $nonce );

				return new PortabilityApplyResult(
					PortabilityApplyResult::ADOPTED,
					'none',
					'Adopted.',
					true
				);
			}
		};
	}

	private function assert_fixture_results(): void {
		$results = $GLOBALS['ran_booster_fixture_portability_results'];
		self::assertInstanceOf( PortabilityReviewResult::class, $results[0] );
		self::assertInstanceOf( PortabilityApplyResult::class, $results[1] );
		self::assertTrue( $results[1]->target_verified );
	}
}
