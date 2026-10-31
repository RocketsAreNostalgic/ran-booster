<?php

declare(strict_types=1);

// Loaded only within GitHubVisibilityTest's isolated child process.
if ( ! function_exists( 'ran_booster_table_name' ) ) {
	/** @return string */
	function ran_booster_table_name() {
		return 'wp_ran_booster_packages';
	}
}
