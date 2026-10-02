<?php

declare(strict_types=1);

// Standalone CLI verifier: WordPress runtime APIs are intentionally unavailable here.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

$arguments      = array_slice( $argv, 1 );
$packaging      = false;
$installed_root = null;
$usage          = 'Usage: php scripts/verify-runtime-dependencies.php '
	. '[--packaging | --verify-install <installed-root>] '
	. '<composer.lock> <runtime-packaging-policy.json>' . "\n";

if ( '--packaging' === ( $arguments[0] ?? null ) ) {
	$packaging = true;
	array_shift( $arguments );
} elseif ( '--verify-install' === ( $arguments[0] ?? null ) ) {
	array_shift( $arguments );
	$candidate_installed_root = array_shift( $arguments );
	if ( ! is_string( $candidate_installed_root ) || '' === $candidate_installed_root ) {
		fwrite( STDERR, $usage );
		exit( 2 );
	}
	$installed_root = $candidate_installed_root;
}

if ( PHP_SAPI !== 'cli' || 2 !== count( $arguments ) ) {
	fwrite( STDERR, $usage );
	exit( 2 );
}

$lock_path   = $arguments[0];
$policy_path = $arguments[1];

try {
	$lock = json_decode( file_get_contents( $lock_path ), true, 512, JSON_THROW_ON_ERROR );
} catch ( Throwable $exception ) {
	fwrite( STDERR, "Runtime dependency lock is unreadable: {$exception->getMessage()}\n" );
	exit( 1 );
}

try {
	$policy = json_decode( file_get_contents( $policy_path ), true, 512, JSON_THROW_ON_ERROR );
} catch ( Throwable $exception ) {
	fwrite( STDERR, "Runtime packaging policy is unreadable: {$exception->getMessage()}\n" );
	exit( 1 );
}

if ( ! is_array( $policy ) ) {
	fwrite( STDERR, "Runtime packaging policy must decode to an object.\n" );
	exit( 1 );
}

$policy_keys = array_keys( $policy );
sort( $policy_keys );
if ( array( 'packages', 'schema', 'schema_version' ) !== $policy_keys ) {
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

$expected         = array();
$archive_roots    = array();
$neutral_updaters = 0;

foreach ( $policy['packages'] as $record ) {
	if ( ! is_array( $record ) ) {
		fwrite( STDERR, "Runtime packaging policy contains an invalid package record.\n" );
		exit( 1 );
	}

	$record_keys = array_keys( $record );
	sort( $record_keys );
	if ( array( 'archive_root', 'build_role', 'name', 'repository', 'surfaces' ) !== $record_keys ) {
		fwrite( STDERR, "Runtime packaging policy package record contains an unsupported field.\n" );
		exit( 1 );
	}

	$name         = $record['name'] ?? null;
	$repository   = $record['repository'] ?? null;
	$archive_root = $record['archive_root'] ?? null;
	$surfaces     = $record['surfaces'] ?? null;
	$build_role   = $record['build_role'] ?? null;

	if (
		! is_string( $name )
		|| 1 !== preg_match( '/^ran\/(?!\.{1,2}$)[a-z0-9_.-]+$/D', $name )
		|| ! is_string( $repository )
		|| 1 !== preg_match( '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $repository )
		|| ! is_string( $archive_root )
		|| 'vendor/' . $name !== $archive_root
		|| ! is_array( $surfaces )
		|| array() === $surfaces
		|| ( null !== $build_role && 'neutral-updater' !== $build_role )
	) {
		fwrite( STDERR, "Runtime packaging policy package record is invalid.\n" );
		exit( 1 );
	}

	if ( isset( $expected[ $name ] ) || isset( $archive_roots[ $archive_root ] ) ) {
		fwrite( STDERR, "Runtime packaging policy contains a duplicate package or archive root.\n" );
		exit( 1 );
	}

	$validated_surfaces = array();
	$surface_paths      = array();
	foreach ( $surfaces as $surface ) {
		if ( ! is_array( $surface ) ) {
			fwrite( STDERR, "Runtime packaging policy contains an invalid surface record.\n" );
			exit( 1 );
		}

		$surface_keys = array_keys( $surface );
		sort( $surface_keys );
		if ( array( 'kind', 'path' ) !== $surface_keys ) {
			fwrite( STDERR, "Runtime packaging policy surface record contains an unsupported field.\n" );
			exit( 1 );
		}

		$surface_path = $surface['path'] ?? null;
		$surface_kind = $surface['kind'] ?? null;
		if (
			! is_string( $surface_path )
			|| 1 !== preg_match( '/^[A-Za-z0-9._-]+$/D', $surface_path )
			|| '.' === $surface_path
			|| '..' === $surface_path
			|| ! is_string( $surface_kind )
			|| ! in_array( $surface_kind, array( 'file', 'directory' ), true )
		) {
			fwrite( STDERR, "Runtime packaging policy contains an invalid top-level surface.\n" );
			exit( 1 );
		}

		if ( isset( $surface_paths[ $surface_path ] ) ) {
			fwrite( STDERR, "Runtime packaging policy contains a duplicate surface path.\n" );
			exit( 1 );
		}
		$surface_paths[ $surface_path ] = true;
		$validated_surfaces[]           = array(
			'path' => $surface_path,
			'kind' => $surface_kind,
		);
	}

	if ( 'neutral-updater' === $build_role ) {
		++$neutral_updaters;
	}

	$expected[ $name ]              = array(
		'repository'   => $repository,
		'archive_root' => $archive_root,
		'surfaces'     => $validated_surfaces,
		'build_role'   => $build_role,
	);
	$archive_roots[ $archive_root ] = true;
}

if ( 1 !== $neutral_updaters ) {
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

$version_pattern = '/^v?(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)'
	. '(?:-(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)'
	. '(?:\.(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?'
	. '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

foreach ( $expected as $name => $identity ) {
	$package = $actual[ $name ];
	$source  = $package['source'] ?? null;
	$dist    = $package['dist'] ?? null;
	$version = $package['version'] ?? null;

	if (
		! is_string( $version )
		|| 1 !== preg_match( $version_pattern, $version )
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

	$reference  = $source['reference'];
	$source_url = 'https://github.com/' . $identity['repository'] . '.git';
	$dist_url   = 'https://api.github.com/repos/' . $identity['repository'] . '/zipball/' . $reference;
	if (
		( $source['url'] ?? null ) !== $source_url
		|| ( $dist['url'] ?? null ) !== $dist_url
	) {
		fwrite( STDERR, "Runtime dependency repository identity mismatch for {$name}.\n" );
		exit( 1 );
	}
}

$content_hash = $lock['content-hash'] ?? null;
if ( ! is_string( $content_hash ) || 1 !== preg_match( '/^[0-9a-f]{32}$/D', $content_hash ) ) {
	fwrite( STDERR, "Runtime dependency lock content hash is invalid.\n" );
	exit( 1 );
}

if ( null !== $installed_root ) {
	if ( ! is_dir( $installed_root ) || is_link( $installed_root ) ) {
		fwrite( STDERR, "Installed runtime root is missing or symbolic.\n" );
		exit( 1 );
	}

	foreach ( $expected as $name => $identity ) {
		$package_root = rtrim( $installed_root, '/\\' ) . '/' . $identity['archive_root'];
		if ( ! is_dir( $package_root ) || is_link( $package_root ) ) {
			fwrite( STDERR, "Installed runtime package root is missing or symbolic for {$name}.\n" );
			exit( 1 );
		}

		foreach ( $identity['surfaces'] as $surface ) {
			$surface_path = $package_root . '/' . $surface['path'];
			if ( 'file' === $surface['kind'] ) {
				if ( ! is_file( $surface_path ) || is_link( $surface_path ) ) {
					fwrite( STDERR, "Installed runtime file surface is missing or changed kind for {$name}: {$surface['path']}.\n" );
					exit( 1 );
				}
				continue;
			}

			if ( ! is_dir( $surface_path ) || is_link( $surface_path ) ) {
				fwrite( STDERR, "Installed runtime directory surface is missing or changed kind for {$name}: {$surface['path']}.\n" );
				exit( 1 );
			}

			$pending = array( $surface_path );
			while ( array() !== $pending ) {
				$directory = array_pop( $pending );
				$entries   = scandir( $directory );
				if ( false === $entries ) {
					fwrite( STDERR, "Installed runtime directory surface is unreadable for {$name}: {$surface['path']}.\n" );
					exit( 1 );
				}
				$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
				if ( array() === $entries ) {
					$relative = substr( $directory, strlen( $package_root ) + 1 );
					fwrite( STDERR, "Installed runtime directory surface contains an empty directory for {$name}: {$relative}.\n" );
					exit( 1 );
				}

				foreach ( $entries as $entry ) {
					$child = $directory . '/' . $entry;
					if ( is_link( $child ) ) {
						fwrite( STDERR, "Installed runtime directory surface contains a symbolic link for {$name}: {$surface['path']}.\n" );
						exit( 1 );
					}
					if ( is_dir( $child ) ) {
						$pending[] = $child;
						continue;
					}
					if ( ! is_file( $child ) ) {
						fwrite( STDERR, "Installed runtime directory surface contains an unsupported filesystem entry for {$name}: {$surface['path']}.\n" );
						exit( 1 );
					}
				}
			}
		}
	}

	exit( 0 );
}

foreach ( $expected as $name => $identity ) {
	$package   = $actual[ $name ];
	$version   = $package['version'];
	$reference = $package['source']['reference'];

	if ( $packaging ) {
		$surface_specs = array_map(
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
			implode( ',', $surface_specs ),
			$identity['build_role'] ?? '-'
		);
		continue;
	}

	printf( "%s\t%s\t%s\n", $name, $version, $reference );
}
