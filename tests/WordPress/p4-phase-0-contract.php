<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.

use RAN_Booster_P4Phase0Fixture as Fixture;
use WP\MCP\Core\McpAdapter;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| '1' !== getenv( 'RAN_BOOSTER_P4_PHASE_0_DISPOSABLE' )
	|| ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'P4 Phase 0 requires an administrator in the disposable fixture site.' );
}

$ran_booster_expected_root   = getenv( 'RAN_BOOSTER_P4_WORDPRESS_PATH' );
$ran_booster_wordpress_root  = realpath( ABSPATH );
$ran_booster_disposable_mark = ABSPATH . '.ran-booster-p4-disposable-site';
$ran_booster_subscriber_id   = (int) getenv( 'RAN_BOOSTER_P4_SUBSCRIBER_ID' );
$ran_booster_admin_id        = get_current_user_id();
$ran_booster_assertions      = 0;

$ran_booster_assert = static function ( bool $condition, string $message ) use ( &$ran_booster_assertions ): void {
	++$ran_booster_assertions;
	if ( ! $condition ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( $message );
	}
};

$ran_booster_assert(
	is_string( $ran_booster_expected_root )
		&& false !== $ran_booster_wordpress_root
		&& realpath( $ran_booster_expected_root ) === $ran_booster_wordpress_root
		&& ! is_link( $ran_booster_disposable_mark )
		&& is_file( $ran_booster_disposable_mark )
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		&& "RAN Booster P4 disposable test site\n" === file_get_contents( $ran_booster_disposable_mark ),
	'The P4 proof is not running in the exact disposable site.'
);
$ran_booster_assert( '7.0.4' === get_bloginfo( 'version' ), 'The exact WordPress fixture version changed.' );
$ran_booster_assert( defined( 'WP_MCP_VERSION' ) && '0.5.0' === WP_MCP_VERSION, 'The exact MCP Adapter fixture version changed.' );
$ran_booster_assert( defined( 'RAN_Booster_P4Phase0Fixture\\API_VERSION' ) && 1 === constant( 'RAN_Booster_P4Phase0Fixture\\API_VERSION' ), 'The Core fixture API is unavailable.' );
$ran_booster_assert( wp_has_ability_category( Fixture\CATEGORY ), 'The Core-owned category was not registered.' );
$ran_booster_assert( wp_has_ability_category( \RAN_Booster_P4Phase0AddonFixture\CATEGORY ), 'The add-on-owned category was not registered.' );

$ran_booster_probe = $GLOBALS['ran_booster_p4_phase_0_probe'] ?? null;
$ran_booster_assert( is_array( $ran_booster_probe ), 'The registration probe is unavailable.' );
$ran_booster_assert( array_key_exists( 'missing_category', $ran_booster_probe ) && null === $ran_booster_probe['missing_category'], 'Missing-category registration did not fail closed.' );
$ran_booster_assert( array_key_exists( 'malformed', $ran_booster_probe ) && null === $ran_booster_probe['malformed'], 'A malformed multi-slash ability was registered.' );
$ran_booster_assert( array_key_exists( 'duplicate', $ran_booster_probe ) && null === $ran_booster_probe['duplicate'], 'Duplicate ability registration did not fail closed.' );

$ran_booster_ability = wp_get_ability( Fixture\READ_ABILITY );
++$ran_booster_assertions;
if ( ! $ran_booster_ability instanceof WP_Ability ) {
	throw new RuntimeException( 'The one-slash Core fixture ability is unavailable.' );
}
$ran_booster_assert( Fixture\CATEGORY === $ran_booster_ability->get_category(), 'The Core fixture ability lost category ownership.' );
$ran_booster_assert( false === $ran_booster_ability->get_meta_item( 'show_in_rest' ), 'The Core fixture ability became REST-visible.' );
$ran_booster_assert(
	false === ( $ran_booster_ability->get_input_schema()['additionalProperties'] ?? null )
		&& false === ( $ran_booster_ability->get_output_schema()['additionalProperties'] ?? null ),
	'The Core fixture schemas are not closed.'
);

$ran_booster_default_result = $ran_booster_ability->execute();
$ran_booster_assert( is_array( $ran_booster_default_result ) && 'default-target' === ( $ran_booster_default_result['target'] ?? null ), 'Top-level input normalization failed.' );
$ran_booster_assert( ( $ran_booster_default_result['actor'] ?? null ) === $ran_booster_admin_id, 'Direct PHP execution lost the explicit WordPress user.' );
$ran_booster_invalid_input = $ran_booster_ability->execute(
	array(
		'target' => 'valid',
		'secret' => 'must-not-pass',
	)
);
$ran_booster_assert( is_wp_error( $ran_booster_invalid_input ) && 'ability_invalid_input' === $ran_booster_invalid_input->get_error_code(), 'Closed input validation failed.' );
$ran_booster_bad_ability = wp_get_ability( Fixture\BAD_ABILITY );
if ( ! $ran_booster_bad_ability instanceof WP_Ability ) {
	throw new RuntimeException( 'The invalid-output Core fixture ability is unavailable.' );
}
$ran_booster_invalid_output = $ran_booster_bad_ability->execute( array( 'target' => 'valid' ) );
$ran_booster_assert( is_wp_error( $ran_booster_invalid_output ) && 'ability_invalid_output' === $ran_booster_invalid_output->get_error_code(), 'Output validation failed.' );

wp_set_current_user( $ran_booster_subscriber_id );
$ran_booster_denied = $ran_booster_ability->execute( array( 'target' => 'permission-check' ) );
$ran_booster_assert( is_wp_error( $ran_booster_denied ), 'The explicit low-privilege WordPress user was permitted.' );
wp_set_current_user( $ran_booster_admin_id );

$ran_booster_addon_ability = wp_get_ability( \RAN_Booster_P4Phase0AddonFixture\ABILITY );
$ran_booster_addon_result  = $ran_booster_addon_ability instanceof WP_Ability ? $ran_booster_addon_ability->execute( array() ) : null;
$ran_booster_assert( is_array( $ran_booster_addon_result ) && 'addon' === ( $ran_booster_addon_result['owner'] ?? null ), 'The add-on did not own and execute its declaration.' );
$ran_booster_assert( ! wp_has_ability( 'p4-incompatible-fixture/read-status' ), 'The incompatible component contributed an executable declaration.' );

$ran_booster_adapter     = McpAdapter::instance(); // @phpstan-ignore class.notFound (External MCP adapter contract supplied by the explicitly version-checked installed phase-zero proof.)
$ran_booster_dedicated   = $ran_booster_adapter->get_server( Fixture\MCP_SERVER );
$ran_booster_default_mcp = $ran_booster_adapter->get_server( 'mcp-adapter-default-server' );
$ran_booster_assert( null !== $ran_booster_dedicated, 'The dedicated Booster fixture MCP server is unavailable.' );
$ran_booster_assert( null !== $ran_booster_default_mcp, 'The MCP Adapter default server is unavailable for negative proof.' );
$ran_booster_assert( 1 === count( $ran_booster_dedicated->get_tools() ), 'The dedicated server does not expose exactly one direct read tool.' );
$ran_booster_assert( null === $ran_booster_dedicated->get_mcp_tool( 'mcp-adapter-execute-ability' ), 'The dedicated server exposes a generic executor.' );
$ran_booster_assert( null !== $ran_booster_dedicated->get_mcp_tool( 'p4-fixture-read-status' ), 'The dedicated server lacks its explicit read tool.' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Exercise the WordPress-owned lifecycle hook consumed by the runtime.
do_action( 'rest_api_init' );
$ran_booster_routes = rest_get_server()->get_routes();
$ran_booster_assert( ! array_key_exists( '/ran-booster-p4/v1/fixture', $ran_booster_routes ), 'The dedicated Booster fixture server registered an HTTP route.' );
$ran_booster_rest_request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . Fixture\READ_ABILITY );
$ran_booster_rest_request->set_param( 'name', Fixture\READ_ABILITY );
$ran_booster_rest_result = ( new WP_REST_Abilities_V1_List_Controller() )->get_item( $ran_booster_rest_request );
$ran_booster_assert( is_wp_error( $ran_booster_rest_result ) && 'rest_ability_not_found' === $ran_booster_rest_result->get_error_code(), 'REST-hidden state was not enforced.' );

$ran_booster_assert( $ran_booster_subscriber_id > 0 && $ran_booster_subscriber_id !== $ran_booster_admin_id, 'The explicit low-privilege fixture user is invalid.' );
$ran_booster_assert( 1 === Fixture\API_VERSION, 'The fixture changed its exact compatibility marker.' );

WP_CLI::line( // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
	(string) wp_json_encode(
		array(
			'assertions'  => $ran_booster_assertions,
			'wordpress'   => get_bloginfo( 'version' ),
			'wp_cli'      => WP_CLI_VERSION, // @phpstan-ignore constant.notFound (Version marker is supplied by the external installed proof dependency.)
			'mcp_adapter' => WP_MCP_VERSION, // @phpstan-ignore constant.notFound (Version marker is supplied by the external installed proof dependency.)
			'abilities'   => array( Fixture\READ_ABILITY, \RAN_Booster_P4Phase0AddonFixture\ABILITY ),
			'mcp_tools'   => array_keys( $ran_booster_dedicated->get_tools() ),
		),
		JSON_UNESCAPED_SLASHES
	)
);
