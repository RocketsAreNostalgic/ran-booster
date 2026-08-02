<?php

// Disposable two-connection proof for WordPress's native updater lock.
// phpcs:disable

[$mode, $runId, $label, $readyPath, $releasePath, $resultPath] = array_pad( $args, 6, '' );
if ( ! is_string( $mode ) || ! is_string( $runId ) || preg_match( '/^[a-f0-9]{24}$/D', $runId ) !== 1 ) {
	throw new RuntimeException( 'The native-lock race arguments are invalid.' );
}

global $wpdb;
$lockName = 'auto_updater.lock';
$tokenFor = static function ( string $participant ) use ( $runId ): string {
	return (string) ( 1000000000 + ( (int) sprintf( '%u', crc32( $runId . ':' . $participant ) ) % 1000000000 ) );
};
$tokens = array( $tokenFor( 'a' ), $tokenFor( 'b' ) );

if ( 'engine' === $mode ) {
	$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->options ) );
	if ( ! is_object( $status ) || ! is_string( $status->Engine ?? null ) ) {
		throw new RuntimeException( 'The WordPress options-table engine is unavailable.' );
	}
	WP_CLI::line( $status->Engine );
	return;
}

if ( 'set-engine' === $mode ) {
	$engine = $label;
	if ( ! in_array( $engine, array( 'InnoDB', 'MyISAM' ), true ) ) {
		throw new RuntimeException( 'The requested options-table engine is invalid.' );
	}
	if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=' . $engine, $wpdb->options ) ) ) {
		throw new RuntimeException( 'The WordPress options-table engine could not be changed.' );
	}
	return;
}

if ( 'cleanup' === $mode ) {
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name = %s AND option_value IN (%s, %s)',
			$wpdb->options,
			$lockName,
			$tokens[0],
			$tokens[1]
		)
	);
	wp_cache_delete( $lockName, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return;
}

if ( 'prepare' === $mode ) {
	$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->options ) );
	if ( ! is_object( $status ) || 0 !== strcasecmp( 'MyISAM', (string) ( $status->Engine ?? '' ) ) ) {
		throw new RuntimeException( 'The native-lock race requires MyISAM wp_options.' );
	}
	$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $lockName ) );
	if ( null !== $existing ) {
		throw new RuntimeException( 'The native-lock proof requires an idle updater lock.' );
	}
	$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $wpdb->options, 'option_name' ) );
	if ( ! is_array( $indexes ) || 1 !== count( $indexes ) || 0 !== (int) $indexes[0]->Non_unique || 'option_name' !== (string) $indexes[0]->Column_name ) {
		throw new RuntimeException( 'The standard unique WordPress option-name index is unavailable.' );
	}
	return;
}

if ( 'child' === $mode ) {
	if ( ! in_array( $label, array( 'a', 'b' ), true ) ) {
		throw new RuntimeException( 'The native-lock participant is invalid.' );
	}
	foreach ( array( $readyPath, $releasePath, $resultPath ) as $path ) {
		if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'A native-lock race marker path is invalid.' );
		}
	}
	$ready = fopen( $readyPath, 'x' );
	if ( false === $ready ) {
		throw new RuntimeException( 'The native-lock participant could not reach the barrier.' );
	}
	fwrite( $ready, "ready\n" );
	fclose( $ready );
	$deadline = microtime( true ) + 15.0;
	while ( ! file_exists( $releasePath ) ) {
		if ( microtime( true ) >= $deadline ) {
			throw new RuntimeException( 'The native-lock race barrier timed out.' );
		}
		usleep( 50000 );
	}
	$result = $wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
			$wpdb->options,
			$lockName,
			$tokenFor( $label ),
			'no'
		)
	);
	if ( false === $result ) {
		throw new RuntimeException( 'The native-lock election query failed.' );
	}
	$json = wp_json_encode( array( 'label' => $label, 'token' => $tokenFor( $label ), 'result' => (int) $result ) );
	$file = fopen( $resultPath, 'x' );
	if ( ! is_string( $json ) || false === $file ) {
		throw new RuntimeException( 'The native-lock race result could not be written.' );
	}
	fwrite( $file, $json . "\n" );
	fclose( $file );
	return;
}

if ( 'assert' === $mode ) {
	$results = array();
	foreach ( array( $readyPath, $releasePath ) as $path ) {
		if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'A native-lock result path is invalid.' );
		}
		$result = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $result ) || ! isset( $result['token'], $result['result'] ) ) {
			throw new RuntimeException( 'A native-lock race result is invalid.' );
		}
		$results[] = $result;
	}
	$outcomes = array_map( static fn ( array $result ): int => (int) $result['result'], $results );
	sort( $outcomes );
	if ( array( 0, 1 ) !== $outcomes ) {
		throw new RuntimeException( 'The MyISAM native-lock election did not produce exactly one winner.' );
	}
	$winner = current( array_filter( $results, static fn ( array $result ): bool => 1 === (int) $result['result'] ) );
	$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $lockName ) );
	if ( ! is_array( $winner ) || ! is_string( $stored ) || ! hash_equals( (string) $winner['token'], $stored ) ) {
		throw new RuntimeException( 'The MyISAM native-lock winner was not preserved.' );
	}
	$wrong = '9999999999';
	if ( hash_equals( $stored, $wrong ) ) {
		$wrong = '9999999998';
	}
	$removed = $wpdb->query(
		$wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $lockName, $wrong )
	);
	$afterWrongToken = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $lockName ) );
	if ( 0 !== $removed || ! is_string( $afterWrongToken ) || ! hash_equals( $stored, $afterWrongToken ) ) {
		throw new RuntimeException( 'A wrong-token delete changed the MyISAM native-lock winner.' );
	}
	$removed = $wpdb->query(
		$wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $lockName, $stored )
	);
	if ( 1 !== $removed ) {
		throw new RuntimeException( 'The exact MyISAM native-lock winner could not be released.' );
	}
	wp_cache_delete( $lockName, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	WP_CLI::success( 'Two MyISAM connections elected one native-lock winner and wrong-token deletion preserved it.' );
	return;
}

throw new RuntimeException( 'The native-lock race mode is invalid.' );
