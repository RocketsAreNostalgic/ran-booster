<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.
// phpcs:disable

use RANBoosterP4Phase0Fixture as Fixture;
use WP\MCP\Core\McpAdapter;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI
	|| '1' !== getenv( 'RAN_BOOSTER_P4_PHASE_0_DISPOSABLE' )
	|| ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'P4 Phase 0 requires an administrator in the disposable fixture site.' );
}

$expectedRoot   = getenv( 'RAN_BOOSTER_P4_WORDPRESS_PATH' );
$wordpressRoot  = realpath( ABSPATH );
$disposableMark = ABSPATH . '.ran-booster-p4-disposable-site';
$subscriberId   = (int) getenv( 'RAN_BOOSTER_P4_SUBSCRIBER_ID' );
$adminId        = get_current_user_id();
$assertions     = 0;

$assert = static function ( bool $condition, string $message ) use ( &$assertions ): void {
	++$assertions;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert(
	is_string( $expectedRoot )
		&& false !== $wordpressRoot
		&& $wordpressRoot === realpath( $expectedRoot )
		&& ! is_link( $disposableMark )
		&& is_file( $disposableMark )
		&& "RAN Booster P4 disposable test site\n" === file_get_contents( $disposableMark ),
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

$defaultResult = $ability->execute();
$assert( is_array( $defaultResult ) && 'default-target' === ( $defaultResult['target'] ?? null ), 'Top-level input normalization failed.' );
$assert( $adminId === ( $defaultResult['actor'] ?? null ), 'Direct PHP execution lost the explicit WordPress user.' );
$invalidInput = $ability->execute( array( 'target' => 'valid', 'secret' => 'must-not-pass' ) );
$assert( is_wp_error( $invalidInput ) && 'ability_invalid_input' === $invalidInput->get_error_code(), 'Closed input validation failed.' );
$invalidOutput = wp_get_ability( Fixture\BAD_ABILITY )->execute( array( 'target' => 'valid' ) );
$assert( is_wp_error( $invalidOutput ) && 'ability_invalid_output' === $invalidOutput->get_error_code(), 'Output validation failed.' );

wp_set_current_user( $subscriberId );
$denied = $ability->execute( array( 'target' => 'permission-check' ) );
$assert( is_wp_error( $denied ), 'The explicit low-privilege WordPress user was permitted.' );
wp_set_current_user( $adminId );

$addonAbility = wp_get_ability( \RANBoosterP4Phase0AddonFixture\ABILITY );
$addonResult  = $addonAbility instanceof WP_Ability ? $addonAbility->execute( array() ) : null;
$assert( is_array( $addonResult ) && 'addon' === ( $addonResult['owner'] ?? null ), 'The add-on did not own and execute its declaration.' );
$assert( ! wp_has_ability( 'p4-incompatible-fixture/read-status' ), 'The incompatible component contributed an executable declaration.' );

$adapter    = McpAdapter::instance();
$dedicated  = $adapter->get_server( Fixture\MCP_SERVER );
$defaultMcp = $adapter->get_server( 'mcp-adapter-default-server' );
$assert( null !== $dedicated, 'The dedicated Booster fixture MCP server is unavailable.' );
$assert( null !== $defaultMcp, 'The MCP Adapter default server is unavailable for negative proof.' );
$assert( 1 === count( $dedicated->get_tools() ), 'The dedicated server does not expose exactly one direct read tool.' );
$assert( null === $dedicated->get_mcp_tool( 'mcp-adapter-execute-ability' ), 'The dedicated server exposes a generic executor.' );
$assert( null !== $dedicated->get_mcp_tool( 'p4-fixture-read-status' ), 'The dedicated server lacks its explicit read tool.' );

do_action( 'rest_api_init' );
$routes = rest_get_server()->get_routes();
$assert( ! array_key_exists( '/ran-booster-p4/v1/fixture', $routes ), 'The dedicated Booster fixture server registered an HTTP route.' );
$restRequest = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . Fixture\READ_ABILITY );
$restRequest->set_param( 'name', Fixture\READ_ABILITY );
$restResult = ( new WP_REST_Abilities_V1_List_Controller() )->get_item( $restRequest );
$assert( is_wp_error( $restResult ) && 'rest_ability_not_found' === $restResult->get_error_code(), 'REST-hidden state was not enforced.' );

$assert( $subscriberId > 0 && $subscriberId !== $adminId, 'The explicit low-privilege fixture user is invalid.' );
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
