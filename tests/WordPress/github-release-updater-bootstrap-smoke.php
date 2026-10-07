<?php

declare(strict_types=1);

// Isolated WordPress hook and header readers for the real broker-to-Core-target path.
$GLOBALS['ran_booster_updater_smoke_hooks'] = array();

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This isolated bootstrap proof must supply the exact WordPress-owned function name consumed by the installed updater.
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ran_booster_updater_smoke_hooks'][] = array(
		'hook'         => $hook,
		'callback'     => $callback,
		'priority'     => $priority,
		'acceptedArgs' => $accepted_args,
	);

	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This isolated bootstrap proof must supply the exact WordPress-owned function name consumed by the installed updater.
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ran_booster_updater_smoke_hooks'][] = array(
		'hook'         => $hook,
		'callback'     => $callback,
		'priority'     => $priority,
		'acceptedArgs' => $accepted_args,
	);

	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- This isolated bootstrap proof must supply the exact WordPress-owned function name consumed by the installed updater.
function get_file_data( string $file, array $headers, string $context = '' ): array {
	unset( $context );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Isolated local-file header proof.
	$contents = file_get_contents( $file, false, null, 0, 8192 );
	$data     = array();
	foreach ( $headers as $field => $header ) {
		$matched        = is_string( $contents )
			&& 1 === preg_match( '/^[ 	\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $contents, $matches );
		$data[ $field ] = $matched ? trim( $matches[1] ) : '';
	}

	return $data;
}

require dirname( __DIR__, 2 ) . '/autoload.php';

$ran_booster_assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// Fixed CLI-only assertion messages.
		throw new RuntimeException( $message );
	}
};

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated runtime-selection fixture.
$wp_version            = '6.8.0';
$ran_booster_registrar = RAN\WordPress\ReleaseUpdaterBootstrap::register();
$ran_booster_broker    = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;

$ran_booster_assert( is_object( $ran_booster_broker ), 'The release updater broker must register before plugins_loaded.' );
$ran_booster_assert( is_object( $ran_booster_registrar ), 'The release updater must return its public registrar.' ); // @phpstan-ignore function.alreadyNarrowedType (Runtime acceptance proof retains the observed loaded value check rather than relying on analyzer assumptions.)
$ran_booster_assert( 5 === $ran_booster_broker->protocol_version(), 'The public registrar must use Protocol 5.' );

$ran_booster_core_updater = ( new RAN\WordPress\ManagedReleaseUpdaterRegistrar( $ran_booster_registrar ) )->plugin(
	'github',
	dirname( __DIR__, 2 ) . '/ran-booster.php',
	'RocketsAreNostalgic/ran-booster',
	'1319710173',
	'prerelease',
	'manual',
	null,
	RAN\PackageArtifactLimit::DEFAULT_MAXIMUM_ARTIFACT_BYTES
);
$ran_booster_target       = new RAN\WordPress\CoreSelfUpdateNativeTarget( $ran_booster_core_updater );

$ran_booster_assert( $ran_booster_target->register(), 'The Core target must register through the selected neutral runtime.' );
$ran_booster_assert( ! $ran_booster_target->status()->active, 'A queued public target must not claim native authority.' );

printf( "Core release updater bootstrap smoke passed.\n" );
