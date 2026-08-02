<?php

declare(strict_types=1);

namespace Tests\AddOn;

require_once __DIR__ . '/../Support/ExternalFixtureAddOnWordPressFunctions.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\Portability\PortabilityApplyResult;
use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\AddOn\Portability\PortabilityFacade;
use RAN\AddOn\Portability\PortabilityReviewResult;
use Tests\Support\NullLoggingFacade;

final class ExternalFixturePortabilityPluginTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testFixtureLoadedBeforeCoreReceivesOnlyExactApiOneFacades(): void {
		$this->loadFixture();
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 1 );
		define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );

		$this->runHook( 'plugins_loaded' );
		$this->runHook( 'ran_booster_portability_ready', $this->facade(), new NullLoggingFacade() );

		$this->assertFixtureResults();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testFixtureLoadedAfterCoreUsesTheSameExactContract(): void {
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 1 );
		define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );
		$this->loadFixture();

		$this->runHook( 'plugins_loaded' );
		$this->runHook( 'ran_booster_portability_ready', $this->facade(), new NullLoggingFacade() );

		$this->assertFixtureResults();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testFixtureFailsSoftWithoutCoreOrWithAnExactVersionMismatch(): void {
		$this->loadFixture();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_portability_ready', $GLOBALS['ran_booster_external_fixture_addon_actions'] );

		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', 2 );
		define( 'RAN_BOOSTER_LOGGING_API_VERSION', 1 );
		$this->loadFixture();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_portability_ready', $GLOBALS['ran_booster_external_fixture_addon_actions'] );
	}

	private function loadFixture(): void {
		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		unset( $GLOBALS['ran_booster_fixture_portability_results'] );
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-portability-addon/ran-booster-fixture-portability-addon.php';
	}

	private function runHook( string $hook, mixed ...$arguments ): void {
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

			public function apply( PortabilityCandidate $candidate, string $expectedFingerprint, string $nonce ): PortabilityApplyResult {
				TestCase::assertSame( 'fixture/fixture.php', $candidate->identifier );
				TestCase::assertSame( 'v1:' . str_repeat( 'a', 64 ), $expectedFingerprint );
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

	private function assertFixtureResults(): void {
		$results = $GLOBALS['ran_booster_fixture_portability_results'];
		self::assertInstanceOf( PortabilityReviewResult::class, $results[0] );
		self::assertInstanceOf( PortabilityApplyResult::class, $results[1] );
		self::assertTrue( $results[1]->targetVerified );
	}
}
