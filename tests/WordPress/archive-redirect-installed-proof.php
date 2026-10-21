<?php

use RAN\RepositoryProvider\AuthenticatedPreparedArchive;

// Called only after the installed release-capability smoke validates its disposable root.
return static function ( string $directory ): void {
	$socket = stream_socket_server( 'tcp://127.0.0.1:0' );
	if ( false === $socket ) {
		throw new RuntimeException( 'Could not reserve a loopback fixture address.' );
	}
	$address = stream_socket_get_name( $socket, false );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native socket reservation, not a filesystem operation.
	fclose( $socket );
	if ( false === $address ) {
		throw new RuntimeException( 'Could not inspect the loopback fixture address.' );
	}
	$log = $directory . '/redirect-requests.jsonl';
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Real redirect proof requires an isolated loopback HTTP process.
	$server = proc_open(
		array( PHP_BINARY, '-S', $address, __DIR__ . '/archive-redirect-router.php' ),
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $directory . '/redirect-server.log', 'a' ),
			2 => array( 'file', $directory . '/redirect-server.log', 'a' ),
		),
		$pipes,
		null,
		array( 'RAN_BOOSTER_REDIRECT_LOG' => $log )
	);
	if ( ! is_resource( $server ) ) {
		throw new RuntimeException( 'Could not start the loopback fixture.' );
	}
	$base     = 'http://' . $address;
	$archives = array();
	$drift    = null;
	try {
		$ready = false;
		for ( $attempt = 0; $attempt < 30; ++$attempt ) {
			$response = wp_remote_get( $base . '/ready', array( 'timeout' => 1 ) );
			if ( ! is_wp_error( $response ) && 'ready' === wp_remote_retrieve_body( $response ) ) {
				$ready = true;
				break;
			}
			usleep( 100000 );
		}
		if ( ! $ready ) {
			throw new RuntimeException( 'Loopback fixture did not become ready.' );
		}
		foreach ( array( false, true ) as $private ) {
			foreach ( array( false, true ) as $lose_selection ) {
				$prefix     = '/' . ( $private ? 'private' : 'anonymous' ) . ( $lose_selection ? '-loss' : '-success' );
				$available  = true;
				$redirects  = 0;
				$guard      = static function () use ( &$available ): bool {
					return $available;
				};
				$authorize  = static function ( array $arguments ): array {
					$arguments['headers']['Authorization'] = 'Bearer fixture-only';
					return $arguments;
				};
				$archive    = new AuthenticatedPreparedArchive( $base . $prefix . '/start', 'fixture-ref', $private ? $authorize : null, operation_guard: $guard );
				$archives[] = $archive;
				$drift      = static function () use ( &$redirects, &$available, $lose_selection ): void {
					++$redirects;
					if ( $lose_selection && 2 === $redirects ) {
						$available = false;
					}
				};
				add_action( 'requests-requests.before_redirect', $drift, 9, 0 );
				$refused = false;
				try {
					$response = wp_remote_get( $archive->get_url(), array( 'timeout' => 5 ) );
				} catch ( RuntimeException $exception ) {
					if ( 'Provider archive operation is unavailable.' !== $exception->getMessage() ) {
						throw $exception;
					}
					$refused = true;
				}
				remove_action( 'requests-requests.before_redirect', $drift, 9 );
				$drift = null;
				if ( $refused !== $lose_selection || 2 !== $redirects || ( ! $lose_selection && ( is_wp_error( $response ) || 'archive' !== wp_remote_retrieve_body( $response ) ) ) ) {
					throw new RuntimeException( 'Real Requests redirect outcome did not match the guard.' );
				}
				// A foreign request uses a distinct real Requests hook object even while this archive is unavailable.
				$other      = new AuthenticatedPreparedArchive( $base . $prefix . '-other/start', 'other-ref', operation_guard: static fn (): bool => true );
				$archives[] = $other;
				$foreign    = wp_remote_get( $other->get_url() );
				$archive->cleanup();
				$after_cleanup = wp_remote_get( $other->get_url() );
				$other->cleanup();
				if ( is_wp_error( $foreign ) || is_wp_error( $after_cleanup ) || 'archive' !== wp_remote_retrieve_body( $foreign ) || 'archive' !== wp_remote_retrieve_body( $after_cleanup ) ) {
					throw new RuntimeException( 'Archive cleanup interfered with another archive.' );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact test-owned network journal.
				$journal = file_get_contents( $log );
				if ( false === $journal ) {
					throw new RuntimeException( 'Could not inspect actual loopback requests.' );
				}
				$actual = array();
				foreach ( explode( "\n", trim( $journal ) ) as $line ) {
					$row = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
					if ( is_array( $row ) && is_string( $row[0] ?? null ) && str_starts_with( $row[0], $prefix . '/' ) ) {
						$actual[] = $row;
					}
				}
				$expected = array( array( $prefix . '/start', $private ? 'Bearer fixture-only' : '' ), array( $prefix . '/hop', '' ) );
				if ( ! $lose_selection ) {
					$expected[] = array( $prefix . '/done', '' );
				}
				if ( $expected !== $actual ) {
					throw new RuntimeException( 'Unexpected network request or redirected credential disclosure.' );
				}
			}
		}
	} finally {
		if ( null !== $drift ) {
			remove_action( 'requests-requests.before_redirect', $drift, 9 );
		}
		foreach ( $archives as $archive ) {
			$archive->cleanup();
		}
		foreach ( $pipes as $pipe ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the owned process pipe.
			fclose( $pipe );
		}
		proc_terminate( $server );
		proc_close( $server );
	}
};
