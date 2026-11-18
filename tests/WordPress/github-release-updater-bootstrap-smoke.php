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

/**
 * @param array<string, string> $headers
 * @return array<string, string>
 */
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

$ran_booster_live_probe  = static function ( object $registrar, int $protocol ): object {
	$broker = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
	if ( ! is_object( $broker ) || ! is_callable( array( $broker, 'protocol_version' ) ) || $protocol !== $broker->protocol_version() ) {
		throw new RuntimeException( 'The first physical copy must advertise its expected protocol.' );
	}
	$probe    = ( new RAN\WordPress\ManagedReleaseUpdaterRegistrar( $registrar ) )->plugin( 'github', dirname( __DIR__, 2 ) . '/ran-booster.php', 'RocketsAreNostalgic/ran-booster', '1319710173' );
	$register = array( $probe, 'register' );
	$status   = array( $probe, 'status' );
	if ( ! is_callable( $register ) || ! is_callable( $status ) || ! $register() || 'queued' !== $status()['state'] ) {
		throw new RuntimeException( 'The first physical copy must accept a queued target before the second loads.' );
	}
	return $probe;
};
$ran_booster_copy        = $argv[1] ?? null;
$ran_booster_order       = $argv[2] ?? null;
$ran_booster_first_probe = null;
if ( is_string( $ran_booster_copy ) && 'before' === $ran_booster_order ) {
	$ran_booster_first_registrar = require $ran_booster_copy . '/bootstrap.php';
	if ( ! is_object( $ran_booster_first_registrar ) ) {
		throw new RuntimeException( 'The first physical copy must return its registrar.' );
	}
	$ran_booster_first_protocol = 'conflict' === ( $argv[3] ?? '' ) ? 8 : 11;
	$ran_booster_first_probe    = $ran_booster_live_probe( $ran_booster_first_registrar, $ran_booster_first_protocol );
}

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated runtime-selection fixture.
$wp_version            = '6.8.0';
$ran_booster_registrar = RAN\WordPress\ReleaseUpdaterBootstrap::register();
$ran_booster_broker    = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;

if ( is_string( $ran_booster_copy ) && 'after' === $ran_booster_order ) {
	$ran_booster_first_probe = $ran_booster_live_probe( $ran_booster_registrar, 11 );
	require $ran_booster_copy . '/bootstrap.php';
}
if ( is_string( $ran_booster_copy ) ) {
	$ran_booster_probe          = ( new RAN\WordPress\ManagedReleaseUpdaterRegistrar( $ran_booster_registrar ) )->plugin( 'github', dirname( __DIR__, 2 ) . '/ran-booster.php', 'RocketsAreNostalgic/ran-booster', '1319710173' );
	$ran_booster_probe_register = array( $ran_booster_probe, 'register' );
	$ran_booster_probe_status   = array( $ran_booster_probe, 'status' );
	if ( ! is_callable( $ran_booster_probe_register ) || ! is_callable( $ran_booster_probe_status ) ) {
		throw new RuntimeException( 'The public target handle must expose registration and status.' );
	}
	$ran_booster_probe_register();
	if ( 'conflict' === ( $argv[3] ?? '' ) ) {
		$ran_booster_assert( 'protocol_conflict_inactive' === $ran_booster_probe_status()['code'], 'Incompatible copies must refuse in either load order.' );
		$ran_booster_first_status = array( $ran_booster_first_probe, 'status' );
		$ran_booster_assert( is_callable( $ran_booster_first_status ) && 'protocol_conflict_inactive' === $ran_booster_first_status()['code'], 'The previously queued target must also refuse after the conflict.' );
	} else {
		$ran_booster_assert( 2 === $ran_booster_broker->diagnostics()['candidate_count'], 'Both same-protocol physical copies must participate.' );
		$ran_booster_assert( 'queued' === $ran_booster_probe_status()['state'], 'Same-protocol targets must remain admitted.' );
	}
	exit( 0 );
}

$ran_booster_assert( is_object( $ran_booster_broker ), 'The release updater broker must register before plugins_loaded.' );
$ran_booster_assert( is_object( $ran_booster_registrar ), 'The release updater must return its public registrar.' ); // @phpstan-ignore function.alreadyNarrowedType (Runtime acceptance proof retains the observed loaded value check rather than relying on analyzer assumptions.)
$ran_booster_assert( 11 === $ran_booster_broker->protocol_version(), 'The public registrar must use Protocol 11.' );

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

$ran_booster_parameter = ( new ReflectionMethod( $ran_booster_registrar, 'plugin' ) )->getParameters()[8];
$ran_booster_assert( 'allow_working_copy' === $ran_booster_parameter->getName() && false === $ran_booster_parameter->getDefaultValue(), 'Native working-copy refusal must remain the default.' );
$ran_booster_declaration = ( new ReflectionProperty( $ran_booster_core_updater, 'declaration' ) )->getValue( $ran_booster_core_updater );
$ran_booster_assert( is_array( $ran_booster_declaration ) && ! ( $ran_booster_declaration['allow_working_copy'] ?? false ), 'Core must not override the native working-copy guard.' );
$ran_booster_source  = dirname( __DIR__, 2 ) . '/vendor/ran/wp-release-updater';
$ran_booster_fixture = sys_get_temp_dir() . '/ran-booster-runtime-copies-' . bin2hex( random_bytes( 8 ) );
try {
	foreach ( array( 'same', 'conflict' ) as $ran_booster_kind ) {
		$ran_booster_copy_root = $ran_booster_fixture . '/' . $ran_booster_kind;
		$ran_booster_files     = array( 'bootstrap.php', 'runtime.php', 'runtime-copy.json' );
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ran_booster_source . '/src', FilesystemIterator::SKIP_DOTS ) ) as $ran_booster_file ) {
			if ( $ran_booster_file->isFile() ) {
				$ran_booster_files[] = substr( $ran_booster_file->getPathname(), strlen( $ran_booster_source ) + 1 );
			}
		}
		sort( $ran_booster_files, SORT_STRING );
		$ran_booster_payload = '';
		foreach ( $ran_booster_files as $ran_booster_relative ) {
			$ran_booster_destination = $ran_booster_copy_root . '/' . $ran_booster_relative;
			if ( ! is_dir( dirname( $ran_booster_destination ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated physical runtime-copy fixture.
				mkdir( dirname( $ran_booster_destination ), 0700, true );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Copy only the installed, locked runtime source.
			$ran_booster_bytes          = (string) file_get_contents( $ran_booster_source . '/' . $ran_booster_relative );
			$ran_booster_protocol_files = array(
				'bootstrap.php'                        => 4,
				'runtime.php'                          => 2,
				'src/Runtime/RequestBroker.php'        => 2,
				'src/Runtime/RuntimeCopySelector.php'  => 2,
				'src/Runtime/SelectedRuntimeState.php' => 4,
			);
			if ( 'conflict' === $ran_booster_kind && isset( $ran_booster_protocol_files[ $ran_booster_relative ] ) ) {
				// A coherent synthetic protocol-8 family, not qualification of a published older runtime.
				$ran_booster_mutated = preg_replace( '/\b11\b/', '8', $ran_booster_bytes, -1, $ran_booster_replacements );
				$ran_booster_assert( is_string( $ran_booster_mutated ) && $ran_booster_protocol_files[ $ran_booster_relative ] === $ran_booster_replacements, 'Every protocol literal must be accounted for in the synthetic family.' );
				$ran_booster_bytes = (string) $ran_booster_mutated;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fixture bytes are confined to the private temporary copy.
			file_put_contents( $ran_booster_destination, $ran_booster_bytes );
			if ( str_ends_with( $ran_booster_relative, '.php' ) ) {
				$ran_booster_payload .= $ran_booster_relative . "\0" . hash( 'sha256', $ran_booster_bytes ) . "\n";
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the locked runtime-copy facts for this fixture.
		$ran_booster_metadata                     = json_decode( (string) file_get_contents( $ran_booster_copy_root . '/runtime-copy.json' ), true, flags: JSON_THROW_ON_ERROR );
		$ran_booster_metadata['package_revision'] = hash( 'sha256', $ran_booster_payload );
		if ( 'conflict' === $ran_booster_kind ) {
			$ran_booster_metadata['package_version']  = '0.0.0-protocol-conflict-fixture';
			$ran_booster_metadata['runtime_protocol'] = 8;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON re-seals only this synthetic copy after its protocol mutation.
		file_put_contents( $ran_booster_copy_root . '/runtime-copy.json', json_encode( $ran_booster_metadata, JSON_THROW_ON_ERROR ) );
		foreach ( array( 'before', 'after' ) as $ran_booster_load_order ) {
			$ran_booster_command = array( PHP_BINARY, __FILE__, $ran_booster_copy_root, $ran_booster_load_order, $ran_booster_kind );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Separate PHP processes are required to exercise both runtime class load orders.
			$ran_booster_process = proc_open(
				$ran_booster_command,
				array(
					0 => STDIN,
					1 => STDOUT,
					2 => STDERR,
				),
				$ran_booster_pipes
			);
			$ran_booster_assert( is_resource( $ran_booster_process ) && 0 === proc_close( $ran_booster_process ), 'Physical runtime-copy load-order proof failed.' );
		}
	}
} finally {
	if ( is_dir( $ran_booster_fixture ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ran_booster_fixture, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $ran_booster_file ) {
			if ( $ran_booster_file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the owned runtime-copy fixture.
				rmdir( $ran_booster_file->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the owned runtime-copy fixture.
				unlink( $ran_booster_file->getPathname() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the owned runtime-copy root.
		rmdir( $ran_booster_fixture );
	}
}

printf( "Core release updater bootstrap smoke passed.\n" );
