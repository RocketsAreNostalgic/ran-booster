<?php
/**
 * Plugin Name: RAN Booster Fixture Portability Add-on
 * Description: Test-only Portability API 1 consumer fixture.
 * Version: 0.0.0
 * Requires PHP: 8.2
 * License: GPL-2.0-only
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! defined( 'RAN_BOOSTER_PORTABILITY_API_VERSION' )
			|| 1 !== RAN_BOOSTER_PORTABILITY_API_VERSION
			|| ! defined( 'RAN_BOOSTER_LOGGING_API_VERSION' )
			|| 1 !== RAN_BOOSTER_LOGGING_API_VERSION ) {
			return;
		}

		add_action(
			'ran_booster_portability_ready',
			static function ( object $portability, object $logging ): void {
				if ( $portability instanceof \RAN\AddOn\Portability\PortabilityFacade
					&& $logging instanceof \RAN\AddOn\Logging\LoggingFacade ) {
					$candidate = new \RAN\AddOn\Portability\PortabilityCandidate(
						'plugin',
						'fixture/fixture.php',
						'Fixture',
						'github',
						'RocketsAreNostalgic/booster-fixture-plugin',
						'main'
					);
					$review    = $portability->review( $candidate, 'fixture-review-nonce' );
					$apply     = $portability->apply( $candidate, $review->fingerprint, 'fixture-apply-nonce' );

					$GLOBALS['ran_booster_fixture_portability_results'] = array( $review, $apply );
				}
			},
			10,
			2
		);
	}
);
