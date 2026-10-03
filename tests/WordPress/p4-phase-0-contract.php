<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.

use RANBoosterP4Phase0Fixture as Fixture;
use WP\MCP\Core\McpAdapter;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| '1' !== getenv( 'RAN_BOOSTER_P4_PHASE_0_DISPOSABLE' )
	|| ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'P4 Phase 0 requires an administrator in the disposable fixture site.' );
}

$expected_root   = getenv( 'RAN_BOOSTER_P4_WORDPRESS_PATH' );
$wordpress_root  = realpath( ABSPATH );
$disposable_mark = ABSPATH . '.ran-booster-p4-disposable-site';
$subscriber_id   = (int) getenv( 'RAN_BOOSTER_P4_SUBSCRIBER_ID' );
$admin_id        = get_current_user_id();
$assertions      = 0;

$assert = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( $message );
	}
};

$assert(
	is_string( $expected_root )
		&& false !== $wordpress_root
		&& realpath( $expected_root ) === $wordpress_root
		&& ! is_link( $disposable_mark )
		&& is_file( $disposable_mark )
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		&& "RAN Booster P4 disposable test site\n" === file_get_contents( $disposable_mark ),
	'The P4 proof is not running in the exact disposable site.'
);
$assert( '7.0.4' === get_bloginfo( 'version' ), 'The exact WordPress fixture version changed.' );
$assert( defined( 'WP_MCP_VERSION' ) && '0.5.0' === WP_MCP_VERSION, 'The exact MCP Adapter fixture version changed.' );
$assert( defined( 'RANBoosterP4Phase0Fixture\\API_VERSION' ) && 1 === constant( 'RANBoosterP4Phase0Fixture\\API_VERSION' ), 'The Core fixture API is unavailable.' );
$assert( wp_has_ability_category( Fixture\CATEGORY ), 'The Core-owned category was not registered.' );
$assert( wp_has_ability_category( \RANBoosterP4Phase0AddonFixture\CATEGORY ), 'The add-on-owned category was not registered.' );

$probe = $GLOBALS['ran_booster_p4_phase_0_probe'] ?? null;
$assert( is_array( $probe ), 'The registration probe is unavailable.' );
$assert( array_key_exists( 'missing_category', $probe ) && null === $probe['missing_category'], 'Missing-category registration did not fail closed.' );
$assert( array_key_exists( 'malformed', $probe ) && null === $probe['malformed'], 'A malformed multi-slash ability was registered.' );
$assert( array_key_exists( 'duplicate', $probe ) && null === $probe['duplicate'], 'Duplicate ability registration did not fail closed.' );

$ability = wp_get_ability( Fixture\READ_ABILITY );
$assert( $ability instanceof WP_Ability, 'The one-slash Core fixture ability is unavailable.' );
$assert( Fixture\CATEGORY === $ability->get_category(), 'The Core fixture ability lost category ownership.' );
$assert( false === $ability->get_meta_item( 'show_in_rest' ), 'The Core fixture ability became REST-visible.' );
$assert(
	false === ( $ability->get_input_schema()['additionalProperties'] ?? null )
		&& false === ( $ability->get_output_schema()['additionalProperties'] ?? null ),
	'The Core fixture schemas are not closed.'
);

$default_result = $ability->execute();
$assert( is_array( $default_result ) && 'default-target' === ( $default_result['target'] ?? null ), 'Top-level input normalization failed.' );
$assert( ( $default_result['actor'] ?? null ) === $admin_id, 'Direct PHP execution lost the explicit WordPress user.' );
$invalid_input = $ability->execute(
	array(
		'target' => 'valid',
		'secret' => 'must-not-pass',
	)
);
$assert( is_wp_error( $invalid_input ) && 'ability_invalid_input' === $invalid_input->get_error_code(), 'Closed input validation failed.' );
$invalid_output = wp_get_ability( Fixture\BAD_ABILITY )->execute( array( 'target' => 'valid' ) );
$assert( is_wp_error( $invalid_output ) && 'ability_invalid_output' === $invalid_output->get_error_code(), 'Output validation failed.' );

wp_set_current_user( $subscriber_id );
$denied = $ability->execute( array( 'target' => 'permission-check' ) );
$assert( is_wp_error( $denied ), 'The explicit low-privilege WordPress user was permitted.' );
wp_set_current_user( $admin_id );

$addon_ability = wp_get_ability( \RANBoosterP4Phase0AddonFixture\ABILITY );
$addon_result  = $addon_ability instanceof WP_Ability ? $addon_ability->execute( array() ) : null;
$assert( is_array( $addon_result ) && 'addon' === ( $addon_result['owner'] ?? null ), 'The add-on did not own and execute its declaration.' );
$assert( ! wp_has_ability( 'p4-incompatible-fixture/read-status' ), 'The incompatible component contributed an executable declaration.' );

$adapter     = McpAdapter::instance();
$dedicated   = $adapter->get_server( Fixture\MCP_SERVER );
$default_mcp = $adapter->get_server( 'mcp-adapter-default-server' );
$assert( null !== $dedicated, 'The dedicated Booster fixture MCP server is unavailable.' );
$assert( null !== $default_mcp, 'The MCP Adapter default server is unavailable for negative proof.' );
$assert( 1 === count( $dedicated->get_tools() ), 'The dedicated server does not expose exactly one direct read tool.' );
$assert( null === $dedicated->get_mcp_tool( 'mcp-adapter-execute-ability' ), 'The dedicated server exposes a generic executor.' );
$assert( null !== $dedicated->get_mcp_tool( 'p4-fixture-read-status' ), 'The dedicated server lacks its explicit read tool.' );

do_action( 'rest_api_init' );
$routes = rest_get_server()->get_routes();
$assert( ! array_key_exists( '/ran-booster-p4/v1/fixture', $routes ), 'The dedicated Booster fixture server registered an HTTP route.' );
$rest_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . Fixture\READ_ABILITY );
$rest_request->set_param( 'name', Fixture\READ_ABILITY );
$rest_result = ( new WP_REST_Abilities_V1_List_Controller() )->get_item( $rest_request );
$assert( is_wp_error( $rest_result ) && 'rest_ability_not_found' === $rest_result->get_error_code(), 'REST-hidden state was not enforced.' );

$assert( $subscriber_id > 0 && $subscriber_id !== $admin_id, 'The explicit low-privilege fixture user is invalid.' );
$assert( 1 === Fixture\API_VERSION, 'The fixture changed its exact compatibility marker.' );

WP_CLI::line(
	(string) wp_json_encode(
		array(
			'assertions'  => $assertions,
			'wordpress'   => get_bloginfo( 'version' ),
			'wp_cli'      => WP_CLI_VERSION,
			'mcp_adapter' => WP_MCP_VERSION,
			'abilities'   => array( Fixture\READ_ABILITY, \RANBoosterP4Phase0AddonFixture\ABILITY ),
			'mcp_tools'   => array_keys( $dedicated->get_tools() ),
		),
		JSON_UNESCAPED_SLASHES
	)
);
