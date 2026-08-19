<?php

declare(strict_types=1);

if ( ! function_exists( 'wp_safe_remote_request' ) ) {
	/** @param array<string,mixed> $args @return array<string,mixed> */
	function wp_safe_remote_request( string $url, array $args ): array {
		$GLOBALS['ran_booster_github_release_workflow_test_remote'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		return array(
			'response' => array( 'code' => 500 ),
			'body'     => '',
		);
	}
}
