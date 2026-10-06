<?php

/** @var list<string> $args Arguments injected by WP-CLI eval-file. */

// Disposable two-connection proof for WordPress's native updater lock.

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
[$mode, $ran_booster_run_id, $ran_booster_label, $ran_booster_ready_path, $ran_booster_release_path, $ran_booster_result_path] = array_pad( $args, 6, '' );
if ( ! is_string( $mode ) || ! is_string( $ran_booster_run_id ) || preg_match( '/^[a-f0-9]{24}$/D', $ran_booster_run_id ) !== 1 ) {
	throw new RuntimeException( 'The native-lock race arguments are invalid.' );
}

global $wpdb;
$ran_booster_lock_name = 'auto_updater.lock';
$ran_booster_token_for = static function ( string $participant ) use ( $ran_booster_run_id ): string {
	return (string) ( 1000000000 + ( (int) sprintf( '%u', crc32( $ran_booster_run_id . ':' . $participant ) ) % 1000000000 ) );
};
$ran_booster_tokens    = array( $ran_booster_token_for( 'a' ), $ran_booster_token_for( 'b' ) );

if ( 'engine' === $mode ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->options ) );
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Engine is the native MySQL SHOW TABLE STATUS field.
	if ( ! is_object( $status ) || ! is_string( $status->Engine ?? null ) ) {
		throw new RuntimeException( 'The WordPress options-table engine is unavailable.' );
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Engine is the native MySQL SHOW TABLE STATUS field.
	WP_CLI::line( $status->Engine ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
	return;
}

if ( 'set-engine' === $mode ) {
	$ran_booster_engine = $ran_booster_label;
	if ( ! in_array( $ran_booster_engine, array( 'InnoDB', 'MyISAM' ), true ) ) {
		throw new RuntimeException( 'The requested options-table engine is invalid.' );
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Engine is allowlisted to InnoDB or MyISAM above; the table identifier uses the %i placeholder.
	if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=' . $ran_booster_engine, $wpdb->options ) ) ) {
		throw new RuntimeException( 'The WordPress options-table engine could not be changed.' );
	}
	return;
}

if ( 'cleanup' === $mode ) {
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name = %s AND option_value IN (%s, %s)',
			$wpdb->options,
			$ran_booster_lock_name,
			$ran_booster_tokens[0],
			$ran_booster_tokens[1]
		)
	);
	wp_cache_delete( $ran_booster_lock_name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return;
}

if ( 'prepare' === $mode ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->options ) );
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Engine is the native MySQL SHOW TABLE STATUS field.
	if ( ! is_object( $status ) || 0 !== strcasecmp( 'MyISAM', (string) ( $status->Engine ?? '' ) ) ) {
		throw new RuntimeException( 'The native-lock race requires MyISAM wp_options.' );
	}
	$ran_booster_existing = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $ran_booster_lock_name ) );
	if ( null !== $ran_booster_existing ) {
		throw new RuntimeException( 'The native-lock proof requires an idle updater lock.' );
	}
	$ran_booster_indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $wpdb->options, 'option_name' ) );
	if ( ! is_array( $ran_booster_indexes ) || 1 !== count( $ran_booster_indexes ) || 0 !== (int) $ran_booster_indexes[0]->Non_unique || 'option_name' !== (string) $ran_booster_indexes[0]->Column_name ) {
		throw new RuntimeException( 'The standard unique WordPress option-name index is unavailable.' );
	}
	return;
}

if ( 'child' === $mode ) {
	if ( ! in_array( $ran_booster_label, array( 'a', 'b' ), true ) ) {
		throw new RuntimeException( 'The native-lock participant is invalid.' );
	}
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( array( $ran_booster_ready_path, $ran_booster_release_path, $ran_booster_result_path ) as $path ) {
		if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'A native-lock race marker path is invalid.' );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	$ran_booster_ready = fopen( $ran_booster_ready_path, 'x' );
	if ( false === $ran_booster_ready ) {
		throw new RuntimeException( 'The native-lock participant could not reach the barrier.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	fwrite( $ran_booster_ready, "ready\n" );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	fclose( $ran_booster_ready );
	$ran_booster_deadline = microtime( true ) + 15.0;
	while ( ! file_exists( $ran_booster_release_path ) ) {
		if ( microtime( true ) >= $ran_booster_deadline ) {
			throw new RuntimeException( 'The native-lock race barrier timed out.' );
		}
		usleep( 50000 );
	}
	$ran_booster_result = $wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
			$wpdb->options,
			$ran_booster_lock_name,
			$ran_booster_token_for( $ran_booster_label ),
			'no'
		)
	);
	if ( false === $ran_booster_result ) {
		throw new RuntimeException( 'The native-lock election query failed.' );
	}
	$ran_booster_json = wp_json_encode(
		array(
			'label'  => $ran_booster_label,
			'token'  => $ran_booster_token_for( $ran_booster_label ),
			'result' => (int) $ran_booster_result,
		)
	);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	$ran_booster_file = fopen( $ran_booster_result_path, 'x' );
	if ( ! is_string( $ran_booster_json ) || false === $ran_booster_file ) {
		throw new RuntimeException( 'The native-lock race result could not be written.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	fwrite( $ran_booster_file, $ran_booster_json . "\n" );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	fclose( $ran_booster_file );
	return;
}

if ( 'assert' === $mode ) {
	$ran_booster_results = array();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
	foreach ( array( $ran_booster_ready_path, $ran_booster_release_path ) as $path ) {
		if ( ! is_string( $path ) || ! str_starts_with( $path, sys_get_temp_dir() . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'A native-lock result path is invalid.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		$ran_booster_result = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $ran_booster_result ) || ! isset( $ran_booster_result['token'], $ran_booster_result['result'] ) ) {
			throw new RuntimeException( 'A native-lock race result is invalid.' );
		}
		$ran_booster_results[] = $ran_booster_result;
	}
	$ran_booster_outcomes = array_map( static fn ( array $ran_booster_result ): int => (int) $ran_booster_result['result'], $ran_booster_results );
	sort( $ran_booster_outcomes );
	if ( array( 0, 1 ) !== $ran_booster_outcomes ) {
		throw new RuntimeException( 'The MyISAM native-lock election did not produce exactly one winner.' );
	}
	$ran_booster_winner = current( array_filter( $ran_booster_results, static fn ( array $ran_booster_result ): bool => 1 === (int) $ran_booster_result['result'] ) );
	$ran_booster_stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $ran_booster_lock_name ) );
	if ( ! is_array( $ran_booster_winner ) || ! is_string( $ran_booster_stored ) || ! hash_equals( (string) $ran_booster_winner['token'], $ran_booster_stored ) ) {
		throw new RuntimeException( 'The MyISAM native-lock winner was not preserved.' );
	}
	$ran_booster_wrong = '9999999999';
	if ( hash_equals( $ran_booster_stored, $ran_booster_wrong ) ) {
		$ran_booster_wrong = '9999999998';
	}
	$ran_booster_removed           = $wpdb->query(
		$wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $ran_booster_lock_name, $ran_booster_wrong )
	);
	$ran_booster_after_wrong_token = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $ran_booster_lock_name ) );
	if ( 0 !== $ran_booster_removed || ! is_string( $ran_booster_after_wrong_token ) || ! hash_equals( $ran_booster_stored, $ran_booster_after_wrong_token ) ) {
		throw new RuntimeException( 'A wrong-token delete changed the MyISAM native-lock winner.' );
	}
	$ran_booster_removed = $wpdb->query(
		$wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $ran_booster_lock_name, $ran_booster_stored )
	);
	if ( 1 !== $ran_booster_removed ) {
		throw new RuntimeException( 'The exact MyISAM native-lock winner could not be released.' );
	}
	wp_cache_delete( $ran_booster_lock_name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	WP_CLI::success( 'Two MyISAM connections elected one native-lock winner and wrong-token deletion preserved it.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
	return;
}

throw new RuntimeException( 'The native-lock race mode is invalid.' );
