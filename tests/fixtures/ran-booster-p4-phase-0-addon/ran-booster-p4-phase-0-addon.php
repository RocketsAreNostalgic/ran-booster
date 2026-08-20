<?php
/**
 * Plugin Name: RAN Booster P4 Phase 0 Add-on Fixture
 * Description: Disposable component-owned Ability and WP-CLI contribution fixture.
 * Version: 0.0.0-test-only
 */

declare(strict_types=1);

namespace RANBoosterP4Phase0AddonFixture;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- A single-file installed plugin fixture keeps disposable ownership explicit.

use WP_CLI;

const CATEGORY = 'p4-phase-0-addon-fixture';
const ABILITY  = 'p4-addon-fixture/read-status';

function compatible(): bool {
	return defined( 'RANBoosterP4Phase0Fixture\\API_VERSION' )
		&& 1 === constant( 'RANBoosterP4Phase0Fixture\\API_VERSION' );
}

function register_category(): void {
	if ( ! compatible() ) {
		return;
	}
	wp_register_ability_category(
		CATEGORY,
		array(
			'label'       => 'P4 add-on fixture',
			'description' => 'Disposable component-owned fixture category.',
		)
	);
}

function register_ability(): void {
	if ( ! compatible() ) {
		return;
	}
	wp_register_ability(
		ABILITY,
		array(
			'label'               => 'Read add-on fixture status',
			'description'         => 'Returns add-on-owned fixture status.',
			'category'            => CATEGORY,
			'input_schema'        => array(
				'type'                 => 'object',
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'owner', 'status' ),
				'properties'           => array(
					'owner'  => array(
						'type' => 'string',
						'enum' => array( 'addon' ),
					),
					'status' => array(
						'type' => 'string',
						'enum' => array( 'ready' ),
					),
				),
			),
			'permission_callback' => static fn ( array $input ): bool => empty( $input ) && current_user_can( 'manage_options' ),
			'execute_callback'    => static fn ( array $input ): array => array(
				'owner'  => empty( $input ) ? 'addon' : 'invalid',
				'status' => 'ready',
			),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => false,
			),
		)
	);
}

final class AddOnCommand {
	public function __invoke(): void {
		WP_CLI::line( 'addon-ready' );
	}
}

function register_cli(): void {
	if ( ! compatible() || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}
	WP_CLI::add_command( 'ran-booster fixture-addon', AddOnCommand::class );
}

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_ability' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\\register_cli', 20 );
