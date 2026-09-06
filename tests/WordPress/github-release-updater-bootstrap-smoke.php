<?php

declare(strict_types=1);

// Isolated WordPress hook and header readers for the real broker-to-target path.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
$GLOBALS['ran_booster_updater_smoke_hooks'] = array();

define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_updater_smoke_hooks'][] = compact( 'hook', 'callback', 'priority', 'acceptedArgs' );

	return true;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_updater_smoke_hooks'][] = compact( 'hook', 'callback', 'priority', 'acceptedArgs' );

	return true;
}

function get_file_data( string $file, array $headers, string $context = '' ): array {
	unset( $context );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Isolated local-file header proof.
	$contents = file_get_contents( $file, false, null, 0, 8192 );
	$data     = array();
	foreach ( $headers as $field => $header ) {
		$matched        = is_string( $contents )
			&& 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $contents, $matches );
		$data[ $field ] = $matched ? trim( $matches[1] ) : '';
	}

	return $data;
}

require dirname( __DIR__, 2 ) . '/autoload.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed CLI-only assertion messages.
		throw new RuntimeException( $message );
	}
};

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated runtime-selection fixture.
$wp_version = '6.8.0';
$registrar  = RAN\WordPress\ReleaseUpdaterBootstrap::register();
$broker     = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;

$assert( is_object( $broker ), 'The release updater broker must register before plugins_loaded.' );
$assert( is_object( $registrar ), 'The release updater must return its public registrar.' );
$assert( 3 === $broker->protocolVersion(), 'The public registrar must use Protocol 3.' );

$credentialReads = 0;
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated registration fixture.
$wpdb   = new stdClass();
$target = new RAN\Booster\GitHub\GitHubReleaseNativeTarget(
	$registrar,
	'plugin',
	dirname( __DIR__, 2 ) . '/ran-booster.php',
	'RocketsAreNostalgic/ran-booster',
	'1319710173',
	static function () use ( &$credentialReads ): string {
		++$credentialReads;

		return 'github_pat_smoke';
	},
	'prerelease',
	'manual'
);
$assert( $target->register(), 'The Core target must register through the selected neutral runtime.' );
$assert( ! $target->status()->active, 'A queued public target must not claim native authority.' );
$assert( 0 === $credentialReads, 'Target registration must not resolve GitHub credentials.' );

printf( "Release updater bootstrap smoke passed.\n" );
