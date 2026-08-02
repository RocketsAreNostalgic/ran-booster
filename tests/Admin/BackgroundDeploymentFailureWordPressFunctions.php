<?php

declare(strict_types=1);

namespace RAN\Admin;

function wp_json_encode( mixed $value ): string|false {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test shim implements WordPress JSON behavior.
	return json_encode( $value );
}

function get_site_option( string $name ): mixed {
	return $GLOBALS['ran_booster_background_failure_site_options'][ $name ] ?? null;
}

function get_option( string $name ): mixed {
	return $GLOBALS['ran_booster_background_failure_options'][ $name ] ?? null;
}

function is_email( string $email ): bool {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function wp_specialchars_decode( string $text, int $quoteStyle = ENT_NOQUOTES ): string {
	return htmlspecialchars_decode( $text, $quoteStyle );
}

function home_url( string $path = '' ): string {
	$baseUrl = (string) ( $GLOBALS['ran_booster_admin_test_home_url'] ?? 'https://example.test' );

	return rtrim( $baseUrl, '/' ) . '/' . ltrim( $path, '/' );
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test shim implements WordPress URL parsing.
	return parse_url( $url, $component );
}

function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
	if ( isset( $GLOBALS['ran_booster_documentation_test_filters'][ $hook ] ) ) {
		foreach ( $GLOBALS['ran_booster_documentation_test_filters'][ $hook ] as $callback ) {
			$value = $callback( $value, ...$arguments );
		}

		return $value;
	}

	$context = $arguments[0] ?? null;
	$GLOBALS['ran_booster_background_failure_filter_context'] = array( $hook, $context );
	$filter = $GLOBALS['ran_booster_background_failure_email_filter'] ?? null;

	return is_callable( $filter ) ? $filter( $value, $context ) : $value;
}

/** @param list<string> $headers */
function wp_mail( string $to, string $subject, string $message, array $headers = array() ): bool {
	$GLOBALS['ran_booster_background_failure_mail'][] = compact( 'to', 'subject', 'message', 'headers' );

	return (bool) ( $GLOBALS['ran_booster_background_failure_mail_result'] ?? true );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_background_failure_actions'][ $hook ][] = compact( 'callback', 'priority', 'acceptedArgs' );

	return true;
}
