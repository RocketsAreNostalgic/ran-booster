<?php

declare(strict_types=1);

// Standalone CLI verifier: WordPress runtime APIs are intentionally unavailable here.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

$arguments = array_slice( $argv, 1 );
$packaging = false;
if ( '--packaging' === ( $arguments[0] ?? null ) ) {
	$packaging = true;
	array_shift( $arguments );
}

if ( PHP_SAPI !== 'cli' || 2 !== count( $arguments ) ) {
	fwrite(
		STDERR,
		"Usage: php scripts/verify-runtime-dependencies.php [--packaging] <composer.lock> <runtime-packaging-policy.json>\n"
	);
	exit( 2 );
}

$lockPath   = $arguments[0];
$policyPath = $arguments[1];

try {
	$lock = json_decode( file_get_contents( $lockPath ), true, 512, JSON_THROW_ON_ERROR );
} catch ( Throwable $exception ) {
	fwrite( STDERR, "Runtime dependency lock is unreadable: {$exception->getMessage()}\n" );
	exit( 1 );
}

try {
	$policy = json_decode( file_get_contents( $policyPath ), true, 512, JSON_THROW_ON_ERROR );
} catch ( Throwable $exception ) {
	fwrite( STDERR, "Runtime packaging policy is unreadable: {$exception->getMessage()}\n" );
	exit( 1 );
}

if ( ! is_array( $policy ) ) {
	fwrite( STDERR, "Runtime packaging policy must decode to an object.\n" );
	exit( 1 );
}

$policyKeys = array_keys( $policy );
sort( $policyKeys );
if ( array( 'packages', 'schema', 'schema_version' ) !== $policyKeys ) {
	fwrite( STDERR, "Runtime packaging policy contains an unsupported top-level field.\n" );
	exit( 1 );
}

if (
	'ran-booster-runtime-packaging' !== ( $policy['schema'] ?? null )
	|| 1 !== ( $policy['schema_version'] ?? null )
	|| ! is_array( $policy['packages'] ?? null )
	|| array() === $policy['packages']
) {
	fwrite( STDERR, "Runtime packaging policy schema is invalid.\n" );
	exit( 1 );
}

$expected        = array();
$archiveRoots    = array();
$neutralUpdaters = 0;

foreach ( $policy['packages'] as $record ) {
	if ( ! is_array( $record ) ) {
		fwrite( STDERR, "Runtime packaging policy contains an invalid package record.\n" );
		exit( 1 );
	}

	$recordKeys = array_keys( $record );
	sort( $recordKeys );
	if ( array( 'archive_root', 'build_role', 'name', 'repository', 'surfaces' ) !== $recordKeys ) {
		fwrite( STDERR, "Runtime packaging policy package record contains an unsupported field.\n" );
		exit( 1 );
	}

	$name        = $record['name'] ?? null;
	$repository  = $record['repository'] ?? null;
	$archiveRoot = $record['archive_root'] ?? null;
	$surfaces    = $record['surfaces'] ?? null;
	$buildRole   = $record['build_role'] ?? null;

	if (
		! is_string( $name )
		|| 1 !== preg_match( '/^ran\/[a-z0-9_.-]+$/D', $name )
		|| ! is_string( $repository )
		|| 1 !== preg_match( '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository )
		|| ! is_string( $archiveRoot )
		|| 'vendor/' . $name !== $archiveRoot
		|| ! is_array( $surfaces )
		|| array() === $surfaces
		|| ( null !== $buildRole && 'neutral-updater' !== $buildRole )
	) {
		fwrite( STDERR, "Runtime packaging policy package record is invalid.\n" );
		exit( 1 );
	}

	if ( isset( $expected[ $name ] ) || isset( $archiveRoots[ $archiveRoot ] ) ) {
		fwrite( STDERR, "Runtime packaging policy contains a duplicate package or archive root.\n" );
		exit( 1 );
	}

	$validatedSurfaces = array();
	foreach ( $surfaces as $surface ) {
		if (
			! is_string( $surface )
			|| 1 !== preg_match( '/^[A-Za-z0-9._\/-]+$/D', $surface )
			|| str_starts_with( $surface, '/' )
			|| str_starts_with( $surface, './' )
			|| str_contains( $surface, '//' )
		) {
			fwrite( STDERR, "Runtime packaging policy contains an unsafe surface path.\n" );
			exit( 1 );
		}

		$segments = explode( '/', $surface );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				fwrite( STDERR, "Runtime packaging policy contains an unsafe surface path.\n" );
				exit( 1 );
			}
		}

		foreach ( $validatedSurfaces as $existingSurface ) {
			if (
				$surface === $existingSurface
				|| str_starts_with( $surface, $existingSurface . '/' )
				|| str_starts_with( $existingSurface, $surface . '/' )
			) {
				fwrite( STDERR, "Runtime packaging policy contains a duplicate or overlapping surface path.\n" );
				exit( 1 );
			}
		}
		$validatedSurfaces[] = $surface;
	}

	if ( 'neutral-updater' === $buildRole ) {
		++$neutralUpdaters;
	}

	$expected[ $name ] = array(
		'repository'   => $repository,
		'archive_root' => $archiveRoot,
		'surfaces'     => $validatedSurfaces,
		'build_role'   => $buildRole,
	);
	$archiveRoots[ $archiveRoot ] = true;
}

if ( 1 !== $neutralUpdaters ) {
	fwrite( STDERR, "Runtime packaging policy must identify exactly one neutral updater package.\n" );
	exit( 1 );
}

$packages = $lock['packages'] ?? null;
if ( ! is_array( $packages ) || count( $expected ) !== count( $packages ) ) {
	fwrite( STDERR, "Runtime dependency lock must contain exactly the policy-approved production package set.\n" );
	exit( 1 );
}

$actual = array();
foreach ( $packages as $package ) {
	if ( ! is_array( $package ) || ! is_string( $package['name'] ?? null ) ) {
		fwrite( STDERR, "Runtime dependency lock contains an invalid production package record.\n" );
		exit( 1 );
	}

	$name = $package['name'];
	if ( isset( $actual[ $name ] ) ) {
		fwrite( STDERR, "Runtime dependency lock contains a duplicate production package.\n" );
		exit( 1 );
	}
	$actual[ $name ] = $package;
}

foreach ( $expected as $name => $identity ) {
	$package = $actual[ $name ] ?? null;
	$source  = is_array( $package ) ? ( $package['source'] ?? null ) : null;
	$dist    = is_array( $package ) ? ( $package['dist'] ?? null ) : null;
	$version = is_array( $package ) ? ( $package['version'] ?? null ) : null;

	if (
		! is_array( $package )
		|| ! is_string( $version )
		|| 1 !== preg_match( '/^v?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version )
		|| ! is_array( $source )
		|| 'git' !== ( $source['type'] ?? null )
		|| ! is_string( $source['reference'] ?? null )
		|| 1 !== preg_match( '/^[0-9a-f]{40}$/D', $source['reference'] )
		|| ! is_array( $dist )
		|| 'zip' !== ( $dist['type'] ?? null )
		|| ! is_string( $dist['reference'] ?? null )
		|| ! hash_equals( $source['reference'], $dist['reference'] )
	) {
		fwrite( STDERR, "Runtime dependency identity is malformed for {$name}.\n" );
		exit( 1 );
	}

	$reference = $source['reference'];
	$sourceUrl = 'https://github.com/' . $identity['repository'] . '.git';
	$distUrl   = 'https://api.github.com/repos/' . $identity['repository'] . '/zipball/' . $reference;
	if (
		$sourceUrl !== ( $source['url'] ?? null )
		|| $distUrl !== ( $dist['url'] ?? null )
	) {
		fwrite( STDERR, "Runtime dependency repository identity mismatch for {$name}.\n" );
		exit( 1 );
	}
}

if ( count( $actual ) !== count( array_intersect_key( $actual, $expected ) ) ) {
	fwrite( STDERR, "Runtime dependency lock contains an unexpected production package.\n" );
	exit( 1 );
}

$contentHash = $lock['content-hash'] ?? null;
if ( ! is_string( $contentHash ) || 1 !== preg_match( '/^[0-9a-f]{32}$/D', $contentHash ) ) {
	fwrite( STDERR, "Runtime dependency lock content hash is invalid.\n" );
	exit( 1 );
}

foreach ( $expected as $name => $identity ) {
	$package   = $actual[ $name ];
	$version   = $package['version'];
	$reference = $package['source']['reference'];

	if ( $packaging ) {
		printf(
			"%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
			$name,
			$version,
			$reference,
			$identity['repository'],
			$identity['archive_root'],
			implode( ',', $identity['surfaces'] ),
			$identity['build_role'] ?? '-'
		);
		continue;
	}

	printf( "%s\t%s\t%s\n", $name, $version, $reference );
}
