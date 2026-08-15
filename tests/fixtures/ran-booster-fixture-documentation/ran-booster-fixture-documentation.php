<?php
/**
 * Plugin Name: RAN Booster Fixture Documentation Add-on
 * Description: Test-only WordPress-native Booster documentation fixture.
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
		if ( ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
			|| 15 !== RAN_BOOSTER_ADDON_API_VERSION ) {
			return;
		}

		add_filter(
			'ran_booster_documentation_sections_after_provider_gh',
			static function ( array $sections, string $documentationUrl, string $scope ): array {
				$sections[] = array(
					'id'      => 'ran-booster-fixture-documentation',
					'summary' => 'Fixture documentation',
					'content' => sprintf(
						'<p data-ran-booster-fixture-documentation-url="%s" data-ran-booster-fixture-documentation-scope="%s">Fixture documentation</p>',
						esc_attr( $documentationUrl ),
						esc_attr( $scope )
					),
				);

				return $sections;
			},
			10,
			3
		);
	}
);
