<?php
/**
 * Plugin Name: RAN Booster P4 Phase 0 Incompatible Add-on Fixture
 * Description: Disposable exact-incompatibility negative fixture.
 * Version: 0.0.0-test-only
 */

declare(strict_types=1);

namespace RANBoosterP4Phase0IncompatibleAddonFixture;

function compatible(): bool {
	return defined( 'RANBoosterP4Phase0Fixture\\API_VERSION' )
		&& 2 === constant( 'RANBoosterP4Phase0Fixture\\API_VERSION' );
}

function register_category(): void {
	if ( compatible() ) {
		wp_register_ability_category(
			'p4-incompatible-fixture',
			array(
				'label'       => 'Incompatible fixture',
				'description' => 'Must never register against fixture API 1.',
			)
		);
	}
}

function register_ability(): void {
	if ( compatible() ) {
		wp_register_ability(
			'p4-incompatible-fixture/read-status',
			array(
				'label'               => 'Incompatible read',
				'description'         => 'Must never be executable.',
				'category'            => 'p4-incompatible-fixture',
				'execute_callback'    => '__return_true',
				'permission_callback' => '__return_true',
			)
		);
	}
}

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_ability' );
