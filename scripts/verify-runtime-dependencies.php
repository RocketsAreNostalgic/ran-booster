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
	$usage = 'Usage: php scripts/verify-runtime-dependencies.php '
		. '[--packaging] <composer.lock> <runtime-packaging-policy.json>' . "\n";
	fwrite( STDERR, $usage );
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
	$surfacePaths      = array();
	foreach ( $surfaces as $surface ) {
		if ( ! is_array( $surface ) ) {
			fwrite( STDERR, "Runtime packaging policy contains an invalid surface record.\n" );
			exit( 1 );
		}

		$surfaceKeys = array_keys( $surface );
		sort( $surfaceKeys );
		if ( array( 'kind', 'path' ) !== $surfaceKeys ) {
			fwrite( STDERR, "Runtime packaging policy surface record contains an unsupported field.\n" );
			exit( 1 );
		}

		$surfacePath = $surface['path'] ?? null;
		$surfaceKind = $surface['kind'] ?? null;
		if (
			! is_string( $surfacePath )
			|| 1 !== preg_match( '/^[A-Za-z0-9._-]+$/D', $surfacePath )
			|| '.' === $surfacePath
			|| '..' === $surfacePath
			|| ! is_string( $surfaceKind )
			|| ! in_array( $surfaceKind, array( 'file', 'directory' ), true )
		) {
			fwrite( STDERR, "Runtime packaging policy contains an invalid top-level surface.\n" );
			exit( 1 );
		}

		if ( isset( $surfacePaths[ $surfacePath ] ) ) {
			fwrite( STDERR, "Runtime packaging policy contains a duplicate surface path.\n" );
			exit( 1 );
		}
		$surfacePaths[ $surfacePath ] = true;
		$validatedSurfaces[]          = array(
			'path' => $surfacePath,
			'kind' => $surfaceKind,
		);
	}

	if ( 'neutral-updater' === $buildRole ) {
		++$neutralUpdaters;
	}

	$expected[ $name ]            = array(
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
	fwrite(
		STDERR,
		"Runtime dependency lock must contain exactly the policy-approved production package set.\n"
	);
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

if ( count( $actual ) !== count( array_intersect_key( $actual, $expected ) ) ) {
	fwrite( STDERR, "Runtime dependency lock contains an unexpected production package.\n" );
	exit( 1 );
}

$versionPattern = '/^v?[0-9]+\.[0-9]+\.[0-9]+'
	. '(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D';

foreach ( $expected as $name => $identity ) {
	$package = $actual[ $name ];
	$source  = $package['source'] ?? null;
	$dist    = $package['dist'] ?? null;
	$version = $package['version'] ?? null;

	if (
		! is_string( $version )
		|| 1 !== preg_match( $versionPattern, $version )
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
		$surfaceSpecs = array_map(
			static fn( array $surface ): string => $surface['kind'] . ':' . $surface['path'],
			$identity['surfaces']
		);
		printf(
			"%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
			$name,
			$version,
			$reference,
			$identity['repository'],
			$identity['archive_root'],
			implode( ',', $surfaceSpecs ),
			$identity['build_role'] ?? '-'
		);
		continue;
	}

	printf( "%s\t%s\t%s\n", $name, $version, $reference );
}
