<?php

declare(strict_types=1);

// The real package bootstrap must remain loadable before WordPress finishes
// loading plugins, without requiring a wider WordPress runtime.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

$GLOBALS['ran_booster_updater_bootstrap_actions'] = array();

function did_action( string $hook ): int {
	if ( 'plugins_loaded' !== $hook ) {
		throw new RuntimeException( 'The package queried an unexpected WordPress action.' );
	}

	return 0;
}

function add_action(
	string $hook,
	callable $callback,
	int $priority = 10,
	int $acceptedArgs = 1
): bool {
	$GLOBALS['ran_booster_updater_bootstrap_actions'][] = array(
		'hook'          => $hook,
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $acceptedArgs,
	);

	return true;
}

function add_filter(
	string $hook,
	callable $callback,
	int $priority = 10,
	int $acceptedArgs = 1
): bool {
	unset( $hook, $callback, $priority, $acceptedArgs );

	return true;
}

function do_action( string $hook, mixed ...$arguments ): void {
	unset( $arguments );
	if ( 'ran_wp_github_release_updater_v1_assurance_registration' !== $hook ) {
		throw new RuntimeException( 'The package fired an unexpected WordPress action.' );
	}
}

function plugin_basename( string $file ): string {
	$marker = '/wp-content/plugins/';
	$offset = strpos( str_replace( '\\', '/', $file ), $marker );

	return false === $offset ? basename( $file ) : substr( $file, $offset + strlen( $marker ) );
}

function get_file_data( string $file, array $headers, string $context = '' ): array {
	unset( $context );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Isolated local-file WordPress header spy.
	$contents = file_get_contents( $file, false, null, 0, 8192 );
	$data     = array();
	foreach ( $headers as $field => $header ) {
		$matched        = is_string( $contents )
			&& 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $contents, $matches );
		$data[ $field ] = $matched ? trim( $matches[1] ) : '';
	}

	return $data;
}

function sanitize_key( mixed $value ): string {
	return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ) ?? '';
}

define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );

require dirname( __DIR__ ) . '/Support/WPError.php';
require dirname( __DIR__, 2 ) . '/autoload.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only assertion messages.
		throw new RuntimeException( $message );
	}
};

$facade  = RAN\WordPress\GitHubReleaseUpdaterBootstrap::register(
	dirname( __DIR__, 2 ) . '/ran-booster.php',
	'0.1.0-alpha.10'
);
$actions = $GLOBALS['ran_booster_updater_bootstrap_actions'];

$assert( 1 === count( $actions ), 'The package must register exactly one deferred selection callback.' );
$assert( 'plugins_loaded' === $actions[0]['hook'], 'The package must defer selection to plugins_loaded.' );
$assert( PHP_INT_MAX - 1 === $actions[0]['priority'], 'The package must select after provider-ready targets.' );
$assert( 0 === $actions[0]['accepted_args'], 'The package callback must not accept action arguments.' );
$assert( is_callable( $actions[0]['callback'] ), 'The deferred package callback must be callable.' );

$diagnostics = $facade->diagnostics();

$assert( true === ( $diagnostics['registered'] ?? null ), 'The real facade must report registration.' );
$assert( 'registered' === ( $diagnostics['state'] ?? null ), 'The real facade must remain pending before selection.' );
$assert( 'awaiting_runtime' === ( $diagnostics['code'] ?? null ), 'The real facade must await runtime selection.' );
$assert( false === ( $diagnostics['selection_fixed'] ?? null ), 'Runtime selection must not occur during bootstrap.' );
$assert( null === ( $diagnostics['selected_version'] ?? null ), 'Bootstrap diagnostics must not claim a selected runtime.' );
$assert( 1 === ( $diagnostics['candidate_count'] ?? null ), 'The locked package must register one candidate.' );

( $actions[0]['callback'] )();

$diagnostics = $facade->diagnostics();
$assert( true === ( $diagnostics['selection_fixed'] ?? null ), 'Runtime selection must be fixed after plugins_loaded.' );
$assert(
	is_string( $diagnostics['selected_version'] ?? null ),
	'Runtime selection must report its package version.'
);
$assert(
	RAN\WordPress\GitHubReleaseUpdaterBootstrap::UPDATER_PROSPECTIVE_API_VERSION
		=== RAN\WordPress\GitHubReleaseUpdaterBootstrap::prospectiveApiVersion( $facade ),
	'Core updater adapter and the selected updater must agree on updater prospective API 4.'
);

printf( "GitHub release updater bootstrap smoke passed.\n" );
