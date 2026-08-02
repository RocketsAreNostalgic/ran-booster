<?php

// Test-only WordPress filesystem transport loaded by restoration-hard-stop-child.php.
// phpcs:disable

if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
}

final class WP_Filesystem_ran_booster_test extends WP_Filesystem_Direct {

	public function move( $source, $destination, $overwrite = false ) {
		if ( defined( 'RAN_BOOSTER_HARD_STOP_SCENARIO' )
			&& 'during_restore' === RAN_BOOSTER_HARD_STOP_SCENARIO
			&& defined( 'RAN_BOOSTER_HARD_STOP_SLUG' )
			&& defined( 'RAN_BOOSTER_HARD_STOP_BARRIER' )
		) {
			$source      = untrailingslashit( wp_normalize_path( (string) $source ) );
			$destination = untrailingslashit( wp_normalize_path( (string) $destination ) );
			$backup      = untrailingslashit(
				wp_normalize_path(
					WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . RAN_BOOSTER_HARD_STOP_SLUG
				)
			);
			$installed   = untrailingslashit(
				wp_normalize_path( WP_PLUGIN_DIR . '/' . RAN_BOOSTER_HARD_STOP_SLUG )
			);

			if ( hash_equals( $backup, $source ) && hash_equals( $installed, $destination ) ) {
				$this->stopAtBarrier();
			}
		}

		return parent::move( $source, $destination, $overwrite );
	}

	private function stopAtBarrier(): void {
		$barrier   = RAN_BOOSTER_HARD_STOP_BARRIER;
		$temp_root = realpath( sys_get_temp_dir() );
		$parent    = is_string( $barrier ) ? realpath( dirname( $barrier ) ) : false;
		if ( ! is_string( $barrier )
			|| '' === $barrier
			|| false === $temp_root
			|| false === $parent
			|| preg_match( '/[[:cntrl:]]/', $barrier ) === 1
			|| in_array( '..', preg_split( '#[\\\\/]#', $barrier ), true )
			|| ! str_starts_with( wp_normalize_path( $parent ), trailingslashit( wp_normalize_path( $temp_root ) ) )
			|| ! hash_equals(
				wp_normalize_path( $parent . DIRECTORY_SEPARATOR . basename( $barrier ) ),
				wp_normalize_path( $barrier )
			)
			|| file_exists( $barrier )
			|| is_link( $barrier )
		) {
			throw new RuntimeException( 'The restoration filesystem barrier is invalid.' );
		}

		$handle = fopen( $barrier, 'x+b' );
		if ( false === $handle ) {
			throw new RuntimeException( 'The restoration filesystem barrier could not be created exclusively.' );
		}
		try {
			$contents = "during_restore\n";
			if ( strlen( $contents ) !== fwrite( $handle, $contents ) || ! fflush( $handle ) ) {
				throw new RuntimeException( 'The restoration filesystem barrier could not be persisted.' );
			}
		} finally {
			fclose( $handle );
		}

		for ( $wait = 0; $wait < 300; ++$wait ) {
			usleep( 100000 );
		}

		throw new RuntimeException( 'The restoration filesystem barrier timed out.' );
	}
}
