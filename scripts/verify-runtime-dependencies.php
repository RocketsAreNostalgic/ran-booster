<?php

declare(strict_types=1);

// Standalone CLI verifier: WordPress runtime APIs are intentionally unavailable here.

$ran_booster_arguments      = array_slice( $argv ?? array(), 1 );
$ran_booster_packaging      = false;
$ran_booster_installed_root = null;
$ran_booster_usage          = 'Usage: php scripts/verify-runtime-dependencies.php '
	. '[--packaging | --verify-install <installed-root>] '
	. '<composer.lock> <runtime-packaging-policy.json>' . "\n";

if ( '--packaging' === ( $ran_booster_arguments[0] ?? null ) ) {
	$ran_booster_packaging = true;
	array_shift( $ran_booster_arguments );
} elseif ( '--verify-install' === ( $ran_booster_arguments[0] ?? null ) ) {
	array_shift( $ran_booster_arguments );
	$ran_booster_candidate_installed_root = array_shift( $ran_booster_arguments );
	if ( ! is_string( $ran_booster_candidate_installed_root ) || '' === $ran_booster_candidate_installed_root ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, $ran_booster_usage );
		exit( 2 );
	}
	$ran_booster_installed_root = $ran_booster_candidate_installed_root;
}

if ( PHP_SAPI !== 'cli' || 2 !== count( $ran_booster_arguments ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite( STDERR, $ran_booster_usage );
	exit( 2 );
}

$ran_booster_lock_path   = $ran_booster_arguments[0];
$ran_booster_policy_path = $ran_booster_arguments[1];

try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone CLI reads the exact lock/policy bytes before WordPress is loaded.
	$ran_booster_lock_bytes = file_get_contents( $ran_booster_lock_path );
	if ( false === $ran_booster_lock_bytes ) {
		throw new RuntimeException( 'Cannot read the lock file.' );
	}
	$ran_booster_lock = json_decode( $ran_booster_lock_bytes, true, 512, JSON_THROW_ON_ERROR );
} catch ( Throwable $ran_booster_exception ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite( STDERR, "Runtime dependency lock is unreadable: {$ran_booster_exception->getMessage()}\n" );
	exit( 1 );
}

try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone CLI reads the exact lock/policy bytes before WordPress is loaded.
	$ran_booster_policy_bytes = file_get_contents( $ran_booster_policy_path );
	if ( false === $ran_booster_policy_bytes ) {
		throw new RuntimeException( 'Cannot read the packaging policy file.' );
	}
	$ran_booster_policy = json_decode( $ran_booster_policy_bytes, true, 512, JSON_THROW_ON_ERROR );
} catch ( Throwable $ran_booster_exception ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite( STDERR, "Runtime packaging policy is unreadable: {$ran_booster_exception->getMessage()}\n" );
	exit( 1 );
}

if ( ! is_array( $ran_booster_policy ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite( STDERR, "Runtime packaging policy must decode to an object.\n" );
	exit( 1 );
}

$ran_booster_policy_keys = array_keys( $ran_booster_policy );
sort( $ran_booster_policy_keys );
if ( array( 'packages', 'schema', 'schema_version' ) !== $ran_booster_policy_keys ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite( STDERR, "Runtime packaging policy contains an unsupported top-level field.\n" );
	exit( 1 );
}

if (
	'ran-booster-runtime-packaging' !== ( $ran_booster_policy['schema'] ?? null )
	|| 1 !== ( $ran_booster_policy['schema_version'] ?? null )
	|| ! is_array( $ran_booster_policy['packages'] ?? null )
	|| array() === $ran_booster_policy['packages']
) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite( STDERR, "Runtime packaging policy schema is invalid.\n" );
	exit( 1 );
}

$ran_booster_expected         = array();
$ran_booster_archive_roots    = array();
$ran_booster_neutral_updaters = 0;

foreach ( $ran_booster_policy['packages'] as $ran_booster_record ) {
	if ( ! is_array( $ran_booster_record ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, "Runtime packaging policy contains an invalid package record.\n" );
		exit( 1 );
	}

	$ran_booster_record_keys = array_keys( $ran_booster_record );
	sort( $ran_booster_record_keys );
	if ( array( 'archive_root', 'build_role', 'name', 'repository', 'surfaces' ) !== $ran_booster_record_keys ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, "Runtime packaging policy package record contains an unsupported field.\n" );
		exit( 1 );
	}

	$ran_booster_name         = $ran_booster_record['name'] ?? null;
	$ran_booster_repository   = $ran_booster_record['repository'] ?? null;
	$ran_booster_archive_root = $ran_booster_record['archive_root'] ?? null;
	$ran_booster_surfaces     = $ran_booster_record['surfaces'] ?? null;
	$ran_booster_build_role   = $ran_booster_record['build_role'] ?? null;

	if (
		! is_string( $ran_booster_name )
		|| 1 !== preg_match( '/^ran\/(?!\.{1,2}$)[a-z0-9_.-]+$/D', $ran_booster_name )
		|| ! is_string( $ran_booster_repository )
		|| 1 !== preg_match( '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $ran_booster_repository )
		|| ! is_string( $ran_booster_archive_root )
		|| 'vendor/' . $ran_booster_name !== $ran_booster_archive_root
		|| ! is_array( $ran_booster_surfaces )
		|| array() === $ran_booster_surfaces
		|| ( null !== $ran_booster_build_role && 'neutral-updater' !== $ran_booster_build_role )
	) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, "Runtime packaging policy package record is invalid.\n" );
		exit( 1 );
	}

	if ( isset( $ran_booster_expected[ $ran_booster_name ] ) || isset( $ran_booster_archive_roots[ $ran_booster_archive_root ] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, "Runtime packaging policy contains a duplicate package or archive root.\n" );
		exit( 1 );
	}

	$ran_booster_validated_surfaces = array();
	$ran_booster_surface_paths      = array();
	foreach ( $ran_booster_surfaces as $ran_booster_surface ) {
		if ( ! is_array( $ran_booster_surface ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
			fwrite( STDERR, "Runtime packaging policy contains an invalid surface record.\n" );
			exit( 1 );
		}

		$ran_booster_surface_keys = array_keys( $ran_booster_surface );
		sort( $ran_booster_surface_keys );
		if ( array( 'kind', 'path' ) !== $ran_booster_surface_keys ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
			fwrite( STDERR, "Runtime packaging policy surface record contains an unsupported field.\n" );
			exit( 1 );
		}

		$ran_booster_surface_path = $ran_booster_surface['path'] ?? null;
		$ran_booster_surface_kind = $ran_booster_surface['kind'] ?? null;
		if (
			! is_string( $ran_booster_surface_path )
			|| 1 !== preg_match( '/^[A-Za-z0-9._-]+$/D', $ran_booster_surface_path )
			|| '.' === $ran_booster_surface_path
			|| '..' === $ran_booster_surface_path
			|| ! is_string( $ran_booster_surface_kind )
			|| ! in_array( $ran_booster_surface_kind, array( 'file', 'directory' ), true )
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
			fwrite( STDERR, "Runtime packaging policy contains an invalid top-level surface.\n" );
			exit( 1 );
		}

		if ( isset( $ran_booster_surface_paths[ $ran_booster_surface_path ] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
			fwrite( STDERR, "Runtime packaging policy contains a duplicate surface path.\n" );
			exit( 1 );
		}
		$ran_booster_surface_paths[ $ran_booster_surface_path ] = true;
		$ran_booster_validated_surfaces[]                       = array(
			'path' => $ran_booster_surface_path,
			'kind' => $ran_booster_surface_kind,
		);
	}

	if ( 'neutral-updater' === $ran_booster_build_role ) {
		++$ran_booster_neutral_updaters;
	}

	$ran_booster_expected[ $ran_booster_name ]              = array(
		'repository'   => $ran_booster_repository,
		'archive_root' => $ran_booster_archive_root,
		'surfaces'     => $ran_booster_validated_surfaces,
		'build_role'   => $ran_booster_build_role,
	);
	$ran_booster_archive_roots[ $ran_booster_archive_root ] = true;
}

if ( 1 !== $ran_booster_neutral_updaters ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite( STDERR, "Runtime packaging policy must identify exactly one neutral updater package.\n" );
	exit( 1 );
}

$ran_booster_packages = $ran_booster_lock['packages'] ?? null;
if ( ! is_array( $ran_booster_packages ) || count( $ran_booster_expected ) !== count( $ran_booster_packages ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite(
		STDERR,
		"Runtime dependency lock must contain exactly the policy-approved production package set.\n"
	);
	exit( 1 );
}

$ran_booster_actual = array();
foreach ( $ran_booster_packages as $ran_booster_package ) {
	if ( ! is_array( $ran_booster_package ) || ! is_string( $ran_booster_package['name'] ?? null ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, "Runtime dependency lock contains an invalid production package record.\n" );
		exit( 1 );
	}

	$ran_booster_name = $ran_booster_package['name'];
	if ( isset( $ran_booster_actual[ $ran_booster_name ] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, "Runtime dependency lock contains a duplicate production package.\n" );
		exit( 1 );
	}
	$ran_booster_actual[ $ran_booster_name ] = $ran_booster_package;
}

if ( count( $ran_booster_actual ) !== count( array_intersect_key( $ran_booster_actual, $ran_booster_expected ) ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite( STDERR, "Runtime dependency lock contains an unexpected production package.\n" );
	exit( 1 );
}

$ran_booster_version_pattern = '/^v?(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)'
	. '(?:-(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)'
	. '(?:\.(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?'
	. '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

foreach ( $ran_booster_expected as $ran_booster_name => $ran_booster_identity ) {
	$ran_booster_package = $ran_booster_actual[ $ran_booster_name ];
	$ran_booster_source  = $ran_booster_package['source'] ?? null;
	$ran_booster_dist    = $ran_booster_package['dist'] ?? null;
	$ran_booster_version = $ran_booster_package['version'] ?? null;

	if (
		! is_string( $ran_booster_version )
		|| 1 !== preg_match( $ran_booster_version_pattern, $ran_booster_version )
		|| ! is_array( $ran_booster_source )
		|| 'git' !== ( $ran_booster_source['type'] ?? null )
		|| ! is_string( $ran_booster_source['reference'] ?? null )
		|| 1 !== preg_match( '/^[0-9a-f]{40}$/D', $ran_booster_source['reference'] )
		|| ! is_array( $ran_booster_dist )
		|| 'zip' !== ( $ran_booster_dist['type'] ?? null )
		|| ! is_string( $ran_booster_dist['reference'] ?? null )
		|| ! hash_equals( $ran_booster_source['reference'], $ran_booster_dist['reference'] )
	) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, "Runtime dependency identity is malformed for {$ran_booster_name}.\n" );
		exit( 1 );
	}

	$ran_booster_reference  = $ran_booster_source['reference'];
	$ran_booster_source_url = 'https://github.com/' . $ran_booster_identity['repository'] . '.git';
	$ran_booster_dist_url   = 'https://api.github.com/repos/' . $ran_booster_identity['repository'] . '/zipball/' . $ran_booster_reference;
	if (
		( $ran_booster_source['url'] ?? null ) !== $ran_booster_source_url
		|| ( $ran_booster_dist['url'] ?? null ) !== $ran_booster_dist_url
	) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, "Runtime dependency repository identity mismatch for {$ran_booster_name}.\n" );
		exit( 1 );
	}
}

$ran_booster_content_hash = $ran_booster_lock['content-hash'] ?? null;
if ( ! is_string( $ran_booster_content_hash ) || 1 !== preg_match( '/^[0-9a-f]{32}$/D', $ran_booster_content_hash ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
	fwrite( STDERR, "Runtime dependency lock content hash is invalid.\n" );
	exit( 1 );
}

if ( null !== $ran_booster_installed_root ) {
	if ( ! is_dir( $ran_booster_installed_root ) || is_link( $ran_booster_installed_root ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
		fwrite( STDERR, "Installed runtime root is missing or symbolic.\n" );
		exit( 1 );
	}

	foreach ( $ran_booster_expected as $ran_booster_name => $ran_booster_identity ) {
		$ran_booster_package_root = rtrim( $ran_booster_installed_root, '/\\' ) . '/' . $ran_booster_identity['archive_root'];
		if ( ! is_dir( $ran_booster_package_root ) || is_link( $ran_booster_package_root ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
			fwrite( STDERR, "Installed runtime package root is missing or symbolic for {$ran_booster_name}.\n" );
			exit( 1 );
		}

		foreach ( $ran_booster_identity['surfaces'] as $ran_booster_surface ) {
			$ran_booster_surface_path = $ran_booster_package_root . '/' . $ran_booster_surface['path'];
			if ( 'file' === $ran_booster_surface['kind'] ) {
				if ( ! is_file( $ran_booster_surface_path ) || is_link( $ran_booster_surface_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
					fwrite( STDERR, "Installed runtime file surface is missing or changed kind for {$ran_booster_name}: {$ran_booster_surface['path']}.\n" );
					exit( 1 );
				}
				continue;
			}

			if ( ! is_dir( $ran_booster_surface_path ) || is_link( $ran_booster_surface_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
				fwrite( STDERR, "Installed runtime directory surface is missing or changed kind for {$ran_booster_name}: {$ran_booster_surface['path']}.\n" );
				exit( 1 );
			}

			$ran_booster_pending = array( $ran_booster_surface_path );
			while ( array() !== $ran_booster_pending ) {
				$ran_booster_directory = array_pop( $ran_booster_pending );
				$ran_booster_entries   = scandir( $ran_booster_directory );
				if ( false === $ran_booster_entries ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
					fwrite( STDERR, "Installed runtime directory surface is unreadable for {$ran_booster_name}: {$ran_booster_surface['path']}.\n" );
					exit( 1 );
				}
				$ran_booster_entries = array_values( array_diff( $ran_booster_entries, array( '.', '..' ) ) );
				if ( array() === $ran_booster_entries ) {
					$ran_booster_relative = substr( $ran_booster_directory, strlen( $ran_booster_package_root ) + 1 );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
					fwrite( STDERR, "Installed runtime directory surface contains an empty directory for {$ran_booster_name}: {$ran_booster_relative}.\n" );
					exit( 1 );
				}

				foreach ( $ran_booster_entries as $ran_booster_entry ) {
					$ran_booster_child = $ran_booster_directory . '/' . $ran_booster_entry;
					if ( is_link( $ran_booster_child ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
						fwrite( STDERR, "Installed runtime directory surface contains a symbolic link for {$ran_booster_name}: {$ran_booster_surface['path']}.\n" );
						exit( 1 );
					}
					if ( is_dir( $ran_booster_child ) ) {
						$ran_booster_pending[] = $ran_booster_child;
						continue;
					}
					if ( ! is_file( $ran_booster_child ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI reports diagnostics to STDERR before WordPress is loaded.
						fwrite( STDERR, "Installed runtime directory surface contains an unsupported filesystem entry for {$ran_booster_name}: {$ran_booster_surface['path']}.\n" );
						exit( 1 );
					}
				}
			}
		}
	}

	exit( 0 );
}

foreach ( $ran_booster_expected as $ran_booster_name => $ran_booster_identity ) {
	$ran_booster_package   = $ran_booster_actual[ $ran_booster_name ];
	$ran_booster_version   = $ran_booster_package['version'];
	$ran_booster_reference = $ran_booster_package['source']['reference'];

	if ( $ran_booster_packaging ) {
		$ran_booster_surface_specs = array_map(
			static fn( array $ran_booster_surface ): string => $ran_booster_surface['kind'] . ':' . $ran_booster_surface['path'],
			$ran_booster_identity['surfaces']
		);
		printf(
			"%s\t%s\t%s\t%s\t%s\t%s\t%s\n",
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI emits the machine-readable packaging protocol; HTML escaping changes its bytes.
			$ran_booster_name,
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI emits the machine-readable packaging protocol; HTML escaping changes its bytes.
			$ran_booster_version,
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI emits the machine-readable packaging protocol; HTML escaping changes its bytes.
			$ran_booster_reference,
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI emits the machine-readable packaging protocol; HTML escaping changes its bytes.
			$ran_booster_identity['repository'],
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI emits the machine-readable packaging protocol; HTML escaping changes its bytes.
			$ran_booster_identity['archive_root'],
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI emits the machine-readable packaging protocol; HTML escaping changes its bytes.
			implode( ',', $ran_booster_surface_specs ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI emits the machine-readable packaging protocol; HTML escaping changes its bytes.
			$ran_booster_identity['build_role'] ?? '-'
		);
		continue;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI emits the machine-readable packaging protocol; HTML escaping changes its bytes.
	printf( "%s\t%s\t%s\n", $ran_booster_name, $ran_booster_version, $ran_booster_reference );
}
