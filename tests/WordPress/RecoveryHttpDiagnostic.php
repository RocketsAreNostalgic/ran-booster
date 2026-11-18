<?php

namespace RAN\Tests\WordPress;

use ArrayAccess;
use WP_Error;

final class RecoveryHttpDiagnostic {
	/** Format only the disposable recovery proof's bounded failure diagnostic. */
	public static function format( mixed $response, string $url, string $type, string $source, bool $damaged, bool $recovery ): string {
		$is_error = $response instanceof WP_Error;
		$status   = ! $is_error && is_array( $response ) ? ( $response['response']['code'] ?? '' ) : '';
		if ( ! $is_error && 200 === $status ) {
			return '';
		}

		// Accept the complete URL only when every byte belongs to the generated fixture grammar.
		$pattern    = '~\Ahttp://localhost(?::[0-9]{1,5})?/wp-admin/admin\.php\?page=ran-booster-(plugins|themes)&(?:package=ran-recovery-[a-f0-9]{10}-(?:plugin-(?:branch|release)/entry\.php|theme-(?:branch|release))|recovery=1&recovery_target=ran-recovery-[a-f0-9]{10}-(?:plugin-(?:branch|release)/entry\.php|theme-(?:branch|release)))\z~D';
		$diagnostic = array(
			'type'    => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'redacted',
			'source'  => in_array( $source, array( 'branch', 'release' ), true ) ? $source : 'redacted',
			'state'   => $damaged ? 'damaged' : 'healthy',
			'view'    => $recovery ? 'recovery' : 'package',
			'outcome' => $is_error ? 'wp_error' : 'http_response',
			'url'     => 1 === preg_match( $pattern, $url ) ? $url : 'redacted',
		);
		if ( $is_error ) {
			$code                        = $response->get_error_code();
			$message                     = $response->get_error_message();
			$diagnostic['error_code']    = in_array( $code, array( 'http_api_curl', 'http_api_failed', 'http_request_failed', 'http_request_not_executed' ), true ) ? $code : 'redacted';
			$diagnostic['error_message'] = is_string( $message ) && strlen( $message ) <= 256 && 1 === preg_match( '/\AcURL error ([0-9]{1,3}):/D', $message, $matches ) ? 'cURL error ' . $matches[1] : ( '' === $message ? 'absent' : 'redacted' );
		} else {
			$headers      = is_array( $response ) ? ( $response['headers'] ?? array() ) : array();
			$location     = is_array( $headers ) || $headers instanceof ArrayAccess ? ( $headers['location'] ?? '' ) : '';
			$content_type = is_array( $headers ) || $headers instanceof ArrayAccess ? ( $headers['content-type'] ?? '' ) : '';
			$reason       = is_array( $response ) ? ( $response['response']['message'] ?? '' ) : '';
			$body         = is_array( $response ) ? ( $response['body'] ?? '' ) : '';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Only an exact allowlisted path is emitted.
			$path                        = is_string( $location ) && strlen( $location ) <= 2048 ? parse_url( $location, PHP_URL_PATH ) : false;
			$media                       = is_string( $content_type ) && strlen( $content_type ) <= 256 ? strtok( $content_type, ';' ) : false;
			$media                       = is_string( $media ) ? strtolower( trim( $media ) ) : ( is_string( $content_type ) && strlen( $content_type ) <= 256 ? '' : false );
			$diagnostic['status']        = is_int( $status ) && 100 <= $status && 599 >= $status ? $status : 'redacted';
			$diagnostic['reason']        = '' === $reason ? 'absent' : 'present';
			$diagnostic['location_path'] = '' === $location ? 'absent' : ( is_string( $path ) && in_array( $path, array( '/wp-login.php', '/wp-admin/', '/wp-admin/admin.php' ), true ) ? $path : 'redacted' );
			$diagnostic['content_type']  = '' === $media ? 'absent' : ( in_array( $media, array( 'application/json', 'text/html', 'text/plain', 'application/xhtml+xml', 'application/xml' ), true ) ? $media : 'redacted' );
			// Screen the entire original body against generic diagnostic words before taking an excerpt.
			$words              = '(?:a|an|the|upstream|server|service|request|response|connection|network|gateway|proxy|is|was|has|been|could|not|be|temporarily|currently|unavailable|available|failed|failure|error|internal|bad|timeout|timed|out|found|forbidden|denied|please|try|again|later|busy|overloaded|maintenance)';
			$diagnostic['body'] = '' === $body ? 'absent' : ( is_string( $body ) && 1 === preg_match( '/\A' . $words . '(?:[ .,!?;:-]+' . $words . ')*[ .,!?;:-]*\z/iD', $body ) ? substr( $body, 0, 160 ) : 'redacted' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The fixed ASCII diagnostic is independent of WordPress encoding filters.
		return '[RAN_RECOVERY_HTTP_DIAGNOSTIC] ' . json_encode( $diagnostic, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	}
}
