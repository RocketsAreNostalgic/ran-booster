<?php

declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || 2 !== $argc ) {
	fwrite( STDERR, "Usage: php scripts/verify-runtime-dependencies.php <composer.lock>\n" );
	exit( 2 );
}

$expected = array(
	'ran/updater-support' => array(
		'version' => 'dev-main',
		'repository' => 'RocketsAreNostalgic/ran-updater-support',
		'reference' => '4afba1191b81602741ada8948fcf78a6c7510659',
	),
	'ran/wp-branch-updater' => array(
		'version' => 'v1.0.0-beta.2',
		'repository' => 'RocketsAreNostalgic/ran-wp-branch-updater',
		'reference' => 'bc0f6608f591ee9c48b71de90e4d651462455b47',
	),
	'ran/wp-release-updater' => array(
		'version' => 'v0.1.0-beta.4',
		'repository' => 'RocketsAreNostalgic/ran-wp-release-updater',
		'reference' => 'dcd9ce2ca20769dc35d6b6bfd46042c17aa53bd3',
	),
);

try {
	$lock = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
} catch ( Throwable $exception ) {
	fwrite( STDERR, "Runtime dependency lock is unreadable: {$exception->getMessage()}\n" );
	exit( 1 );
}

$packages = $lock['packages'] ?? null;
if ( ! is_array( $packages ) || count( $expected ) !== count( $packages ) ) {
	fwrite( STDERR, "Runtime dependency lock must contain exactly three production packages.\n" );
	exit( 1 );
}

$actual = array();
foreach ( $packages as $package ) {
	if ( ! is_array( $package ) || ! is_string( $package['name'] ?? null ) ) {
		fwrite( STDERR, "Runtime dependency lock contains an invalid production package record.\n" );
		exit( 1 );
	}
	$actual[ $package['name'] ] = $package;
}

if ( array_keys( $expected ) !== array_keys( array_intersect_key( $expected, $actual ) ) || count( $actual ) !== count( $expected ) ) {
	fwrite( STDERR, "Runtime dependency lock contains an unexpected production package.\n" );
	exit( 1 );
}

foreach ( $expected as $name => $identity ) {
	$package = $actual[ $name ] ?? null;
	$source = is_array( $package ) ? ( $package['source'] ?? null ) : null;
	$dist = is_array( $package ) ? ( $package['dist'] ?? null ) : null;
	$sourceUrl = 'https://github.com/' . $identity['repository'] . '.git';
	$distUrl = 'https://api.github.com/repos/' . $identity['repository'] . '/zipball/' . $identity['reference'];

	if (
		! is_array( $package )
		|| $identity['version'] !== ( $package['version'] ?? null )
		|| ! is_array( $source )
		|| 'git' !== ( $source['type'] ?? null )
		|| $sourceUrl !== ( $source['url'] ?? null )
		|| ! hash_equals( $identity['reference'], (string) ( $source['reference'] ?? '' ) )
		|| ! is_array( $dist )
		|| 'zip' !== ( $dist['type'] ?? null )
		|| $distUrl !== ( $dist['url'] ?? null )
		|| ! hash_equals( $identity['reference'], (string) ( $dist['reference'] ?? '' ) )
	) {
		fwrite( STDERR, "Runtime dependency identity mismatch for {$name}.\n" );
		exit( 1 );
	}
}

$contentHash = $lock['content-hash'] ?? null;
if ( ! is_string( $contentHash ) || 1 !== preg_match( '/^[0-9a-f]{32}$/D', $contentHash ) ) {
	fwrite( STDERR, "Runtime dependency lock content hash is invalid.\n" );
	exit( 1 );
}

foreach ( $expected as $name => $identity ) {
	printf( "%s\t%s\t%s\n", $name, $identity['version'], $identity['reference'] );
}
