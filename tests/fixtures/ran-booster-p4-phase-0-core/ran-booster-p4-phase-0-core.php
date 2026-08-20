<?php
/**
 * Plugin Name: RAN Booster P4 Phase 0 Core Fixture
 * Description: Disposable test-only fixture for the P4 machine-interface contract spike.
 * Version: 0.0.0-test-only
 * Requires at least: 7.0
 * Requires PHP: 8.2
 */

declare(strict_types=1);

namespace RANBoosterP4Phase0Fixture;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- A single-file installed plugin fixture keeps disposable ownership explicit.

use WP_CLI;
use WP_CLI_Command;
use WP_Error;
use WP\MCP\Core\McpAdapter;

const API_VERSION  = 1;
const CATEGORY     = 'p4-phase-0-fixture';
const READ_ABILITY = 'p4-fixture/read-status';
const BAD_ABILITY  = 'p4-fixture/invalid-output';
const MCP_SERVER   = 'ran-booster-p4-read-only';

/**
 * Register the disposable fixture category.
 */
function register_category(): void {
	wp_register_ability_category(
		CATEGORY,
		array(
			'label'       => 'P4 Phase 0 fixture',
			'description' => 'Disposable test-only P4 contract fixture.',
		)
	);
}

/**
 * Return the shared closed input schema.
 *
 * @return array<string, mixed>
 */
function input_schema(): array {
	return array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'target' ),
		'properties'           => array(
			'target' => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 32,
				'pattern'   => '^[a-z0-9-]+$',
			),
		),
		'default'              => array( 'target' => 'default-target' ),
	);
}

/**
 * Return the shared closed output schema.
 *
 * @return array<string, mixed>
 */
function output_schema(): array {
	return array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'owner', 'target', 'status', 'actor' ),
		'properties'           => array(
			'owner'  => array(
				'type' => 'string',
				'enum' => array( 'core' ),
			),
			'target' => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 32,
			),
			'status' => array(
				'type' => 'string',
				'enum' => array( 'ready' ),
			),
			'actor'  => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
	);
}

/**
 * Register valid and deliberately invalid abilities for executable contract proof.
 */
function register_abilities(): void {
	$GLOBALS['ran_booster_p4_phase_0_probe'] = array();

	$GLOBALS['ran_booster_p4_phase_0_probe']['missing_category'] = wp_register_ability(
		'p4-fixture/missing-category',
		array(
			'label'               => 'Missing category fixture',
			'description'         => 'Must be rejected.',
			'category'            => 'p4-fixture-missing',
			'execute_callback'    => '__return_true',
			'permission_callback' => '__return_true',
		)
	);

	$GLOBALS['ran_booster_p4_phase_0_probe']['malformed'] = wp_register_ability(
		'p4-fixture/too/many-segments',
		array(
			'label'               => 'Malformed fixture',
			'description'         => 'Must be rejected.',
			'category'            => CATEGORY,
			'execute_callback'    => '__return_true',
			'permission_callback' => '__return_true',
		)
	);

	wp_register_ability(
		READ_ABILITY,
		array(
			'label'               => 'Read fixture status',
			'description'         => 'Returns one bounded, path-free, secret-free fixture status.',
			'category'            => CATEGORY,
			'input_schema'        => input_schema(),
			'output_schema'       => output_schema(),
			'permission_callback' => static function ( array $input ): bool {
				unset( $input );
				return get_current_user_id() > 0 && current_user_can( 'manage_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
				return array(
					'owner'  => 'core',
					'target' => $input['target'],
					'status' => 'ready',
					'actor'  => get_current_user_id(),
				);
			},
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => false,
				'mcp'          => array(
					'public' => false,
					'type'   => 'tool',
				),
			),
		)
	);

	wp_register_ability(
		BAD_ABILITY,
		array(
			'label'               => 'Invalid output fixture',
			'description'         => 'Deliberately violates its output schema.',
			'category'            => CATEGORY,
			'input_schema'        => input_schema(),
			'output_schema'       => output_schema(),
			'permission_callback' => static function ( array $input ): bool {
				unset( $input );
				return current_user_can( 'manage_options' );
			},
			'execute_callback'    => static function ( array $input ): array {
				return array(
					'owner'  => 'core',
					'target' => $input['target'],
					'status' => 'not-in-schema',
					'actor'  => get_current_user_id(),
				);
			},
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

	$GLOBALS['ran_booster_p4_phase_0_probe']['duplicate'] = wp_register_ability(
		READ_ABILITY,
		array(
			'label'               => 'Duplicate fixture',
			'description'         => 'Must be rejected.',
			'category'            => CATEGORY,
			'execute_callback'    => '__return_true',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Register the dedicated local STDIO-only server with one explicit read tool.
 */
function register_mcp_server( McpAdapter $adapter ): void {
	$adapter->create_server(
		MCP_SERVER,
		'ran-booster-p4/v1',
		'fixture',
		'RAN Booster P4 read-only fixture',
		'Disposable local STDIO-only P4 Phase 0 server.',
		'0.0.0-test-only',
		array(),
		null,
		null,
		array( READ_ABILITY ),
		array(),
		array(),
		static fn (): bool => is_user_logged_in()
	);
}

final class RootCommand extends WP_CLI_Command {
}

final class VersionCommand {
	/**
	 * Print the test-only fixture version.
	 */
	public function __invoke(): void {
		WP_CLI::line( '0.0.0-test-only' );
	}
}

final class AbilityCommand extends WP_CLI_Command {
	/**
	 * Run one registered fixture ability.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Exact one-slash ability identifier.
	 *
	 * [--input=<json|->]
	 * : JSON input or - for stdin.
	 *
	 * [--format=<human|json>]
	 * : Output format.
	 *
	 * [--emit-warning]
	 * : Emit a redacted fixture warning to stderr.
	 */
	public function run( array $args, array $assocArgs ): void {
		$name   = $args[0] ?? '';
		$format = $assocArgs['format'] ?? 'human';
		$raw    = $assocArgs['input'] ?? 'null';

		if ( 0 === get_current_user_id() ) {
			WP_CLI::error( 'An explicit WordPress user is required.' );
		}
		if ( '-' === $raw ) {
			$raw = (string) file_get_contents( 'php://stdin' );
		}
		$input = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_CLI::error( 'Input must be valid JSON.' );
		}
		$ability = wp_get_ability( $name );
		if ( null === $ability ) {
			WP_CLI::error( 'The requested fixture ability is unavailable.' );
		}
		if ( array_key_exists( 'emit-warning', $assocArgs ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Deliberate warning-isolation fixture.
			trigger_error( 'P4 fixture warning with no request data.', E_USER_WARNING );
		}
		$result = $ability->execute( $input );
		if ( $result instanceof WP_Error ) {
			WP_CLI::error( 'The fixture ability rejected the request.' );
		}
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) );
			return;
		}
		WP_CLI::line( sprintf( 'P4 fixture %s: %s', $result['target'], $result['status'] ) );
	}
}

/**
 * Register the Core-owned WP-CLI root before component contributions.
 */
function register_cli(): void {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}
	WP_CLI::add_command( 'ran-booster', RootCommand::class );
	WP_CLI::add_command( 'ran-booster version', VersionCommand::class );
	WP_CLI::add_command( 'ran-booster ability', AbilityCommand::class );
}

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\\register_cli', 10 );
add_action( 'mcp_adapter_init', __NAMESPACE__ . '\\register_mcp_server' );
