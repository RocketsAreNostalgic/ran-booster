<?php

use RAN\Admin\Interaction\SignedAdminInteractionFlow as Flow;

( static function (): void {
	// This proof is allowed only on an explicitly marked disposable site.
	$ran_proof_site = getenv( 'RAN_BOOSTER_WORDPRESS_PATH' );
	$ran_proof_root = dirname( __DIR__, 2 );
	$ran_proof_url  = getenv( 'RAN_BOOSTER_WORDPRESS_URL' );
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_string( $ran_proof_site ) || '' === $ran_proof_site
	|| ! is_string( $ran_proof_url ) || '' === $ran_proof_url || get_site_url() !== $ran_proof_url
	|| is_link( $ran_proof_site ) || realpath( $ran_proof_site ) !== realpath( ABSPATH )
	|| realpath( WP_CONTENT_DIR ) !== realpath( ABSPATH ) . '/wp-content'
	|| is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR )
	|| realpath( WP_PLUGIN_DIR ) !== realpath( WP_CONTENT_DIR ) . '/plugins'
	|| ! is_file( ABSPATH . '.ran-booster-disposable-test-site' )
	|| is_link( ABSPATH . '.ran-booster-disposable-test-site' )
	|| trim( (string) file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' ) ) !== 'RAN Booster disposable test site' ) {
		throw new RuntimeException( 'An explicitly marked disposable WordPress site is required.' );
	}
	require_once $ran_proof_root . '/RAN/Admin/Interaction/SignedAdminInteractionFlow.php';
	global $wpdb;
	wp_set_current_user( 1 );
	$key = Flow::MESSAGE_META_KEY;
	$wpdb->suppress_errors( true );
	$credentials = array_map( 'constant', array( 'DB_USER', 'DB_PASSWORD', 'DB_NAME', 'DB_HOST' ) );
	foreach ( $credentials as $credential ) {
		if ( ! is_string( $credential ) ) {
			throw new RuntimeException( 'Database connection settings are unavailable.' );
		}
	}
	$second = new wpdb( ...$credentials );
	$second->set_prefix( $wpdb->base_prefix );
	$expect  = static function ( bool $condition, string $reason ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $reason );
		} };
	$barrier = static function ( callable $action ) use ( &$wpdb, $second ): void {
		$fired    = false;
		$callback = static function ( string $sql ) use ( &$fired, &$wpdb, $second, $action ): string {
			if ( ! $fired && str_starts_with( $sql, 'DELETE FROM ' ) && str_contains( $sql, '_ran_booster_interaction_outcome' ) ) {
				$fired = true;
				$first = $wpdb;
				$wpdb  = $second;
				try {
					$action();
				} finally {
					$wpdb = $first; }
			}
			return $sql;
		};
		add_filter( 'query', $callback );
	};
	try {
		delete_user_meta( 1, $key );
		Flow::hold_message( 'A', 'first' );
		$barrier( static fn () => Flow::hold_message( 'B', 'second' ) );
		$expect( null === Flow::claim_message( 'A' ), 'Old claim returned a message.' );
		$cache_found = false;
		wp_cache_get( 1, 'user_meta', false, $cache_found );
		$expect( ! $cache_found, 'A mismatched delete left the user metadata cache populated.' );
		$expect( 'second' === Flow::claim_message( 'B' ), 'Replacement did not survive.' );
		Flow::hold_message( 'expired', 'old' );
		$held       = get_user_meta( 1, $key, true );
		$held['at'] = time() - 100;
		update_user_meta( 1, $key, wp_slash( $held ) );
		$barrier( static fn () => Flow::hold_message( 'fresh', 'fresh text' ) );
		$flow = new Flow( static fn () => null );
		( new ReflectionMethod( $flow, 'reap_expired_message' ) )->invoke( $flow );
		$expect( 'fresh text' === Flow::claim_message( 'fresh' ), 'Fresh row lost during cleanup.' );
		update_user_meta( 1, $key, wp_slash( array( 'broken' => true ) ) );
		$barrier( static fn () => Flow::hold_message( 'fresh2', 'fresh two' ) );
		( new ReflectionMethod( $flow, 'reap_expired_message' ) )->invoke( $flow );
		$expect( 'fresh two' === Flow::claim_message( 'fresh2' ), 'Fresh row lost during malformed cleanup.' );
		update_user_meta( 1, $key, '' );
		$barrier( static fn () => Flow::hold_message( 'fresh3', 'fresh three' ) );
		( new ReflectionMethod( $flow, 'reap_expired_message' ) )->invoke( $flow );
		$expect( 'fresh three' === Flow::claim_message( 'fresh3' ), 'Fresh row lost during empty-value cleanup.' );

		Flow::hold_message( 'same', 'once' );
		$inner = null;
		$barrier(
			static function () use ( &$inner ): void {
				$inner = Flow::claim_message( 'same' );
			}
		);
		$outer = Flow::claim_message( 'same' );
		$expect( 'once' === $inner && null === $outer, 'Two claims succeeded.' );
		Flow::hold_message( 'error', 'retained' );
		$fired    = false;
		$callback = static function ( string $sql ) use ( &$fired ): string {
			if ( ! $fired && str_starts_with( $sql, 'DELETE FROM ' ) && str_contains( $sql, '_ran_booster_interaction_outcome' ) ) {
				$fired = true;
				return 'DELETE FROM `ran971_table_that_does_not_exist`'; }
			return $sql;
		};
		add_filter( 'query', $callback );
		$expect( null === Flow::claim_message( 'error' ), 'Failed delete claimed a message.' );
		$cache_found = false;
		wp_cache_get( 1, 'user_meta', false, $cache_found );
		$expect( ! $cache_found, 'A failed delete left the user metadata cache populated.' );
		$expect( 'retained' === Flow::claim_message( 'error' ), 'Failed delete did not retain the row.' );
		Flow::hold_message( 'dup', 'one claim' );
		$held = get_user_meta( 1, $key, true );
		add_user_meta( 1, $key, wp_slash( $held ) );
		$expect( 'one claim' === Flow::claim_message( 'dup' ), 'Duplicate claim failed.' );
		$cache_found = false;
		wp_cache_get( 1, 'user_meta', false, $cache_found );
		$expect( ! $cache_found, 'A successful delete left the user metadata cache populated.' );
		$expect( null === Flow::claim_message( 'dup' ), 'Duplicate yielded a second claim.' );
		Flow::hold_message( 'case', 'Exact' );
		$held            = get_user_meta( 1, $key, true );
		$held['message'] = 'exact';
		add_user_meta( 1, $key, wp_slash( $held ) );
		$expect( 'Exact' === Flow::claim_message( 'case' ), 'First case-sensitive value was not claimed.' );
		$expect( 'exact' === Flow::claim_message( 'case' ), 'Case-distinct value was deleted.' );
		if ( is_multisite() ) {
			$site_id = (int) getenv( 'RAN_BOOSTER_OTHER_SITE_ID' );
			$expect( 0 < $site_id && get_current_blog_id() !== $site_id && null !== get_site( $site_id ), 'A distinct network site is required.' );
			Flow::hold_message( 'site', 'site-bound' );
			switch_to_blog( $site_id );
			try {
				$expect( null === Flow::claim_message( 'site' ), 'Another site consumed the row.' );
			} finally {
				restore_current_blog();
			}
			$expect( 'site-bound' === Flow::claim_message( 'site' ), 'Original site lost the row.' );
		}
		echo "Real WordPress metadata barrier proof passed.\n";
	} finally {
		delete_user_meta( 1, $key ); }
} )();
