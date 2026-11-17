<?php

declare(strict_types=1);

namespace RAN\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use RAN\Tests\WordPress\RecoveryHttpDiagnostic;

require_once __DIR__ . '/Support/WPError.php';

final class RecoveryHttpDiagnosticTest extends TestCase {

	public function test_actual_output_reports_safe_fixture_request_and_response(): void {
		$url      = 'http://localhost/wp-admin/admin.php?page=ran-booster-plugins&package=ran-recovery-0123456789-plugin-branch/entry.php';
		$response = array(
			'response' => array(
				'code'    => 503,
				'message' => 'Service Unavailable',
			),
			'headers'  => new \ArrayObject(
				array(
					'content-type' => 'text/plain; charset=utf-8',
					'set-cookie'   => 'secret',
				)
			),
			'cookies'  => array( 'secret' ),
			'body'     => 'the upstream service is temporarily unavailable.',
		);
		$output   = RecoveryHttpDiagnostic::format( $response, $url, 'plugin', 'branch', false, false );
		self::assertStringContainsString( $url, $output );
		self::assertStringContainsString( 'the upstream service is temporarily unavailable.', $output );
		self::assertStringContainsString( '"status":503', $output );
		self::assertStringNotContainsString( 'secret', $output );
	}


	public function test_safe_long_body_is_bounded_only_after_screening(): void {
		$url     = 'http://localhost/wp-admin/admin.php?page=ran-booster-themes&recovery=1&recovery_target=ran-recovery-0123456789-theme-release';
		$body    = str_repeat( 'service unavailable. ', 20 );
		$output  = RecoveryHttpDiagnostic::format(
			array(
				'response' => array(
					'code'    => 503,
					'message' => '',
				),
				'headers'  => array(),
				'body'     => $body,
			),
			$url,
			'theme',
			'release',
			false,
			true
		);
		$decoded = json_decode( substr( $output, strlen( '[RAN_RECOVERY_HTTP_DIAGNOSTIC] ' ) ), true );
		self::assertIsArray( $decoded );
		self::assertSame( substr( $body, 0, 160 ), $decoded['body'] );
		self::assertSame( $url, $decoded['url'] );
	}

	public function test_unsafe_suffix_after_a_complete_excerpt_omits_the_body(): void {
		$prefix = str_repeat( 'service ', 20 );
		self::assertSame( 160, strlen( $prefix ) );
		foreach ( array(
			$prefix                  => $prefix,
			$prefix . 'nonce=secret' => 'redacted',
		) as $body => $expected ) {
			$output  = RecoveryHttpDiagnostic::format(
				array(
					'response' => array(
						'code'    => 503,
						'message' => '',
					),
					'headers'  => array(),
					'body'     => $body,
				),
				'http://localhost/wp-admin/admin.php?page=ran-booster-themes&package=ran-recovery-0123456789-theme-release',
				'theme',
				'release',
				false,
				false
			);
			$decoded = json_decode( substr( $output, strlen( '[RAN_RECOVERY_HTTP_DIAGNOSTIC] ' ) ), true );
			self::assertIsArray( $decoded );
			self::assertSame( $expected, $decoded['body'] );
		}
	}

	public function test_hostile_values_are_omitted_before_bounding(): void {
		$url = 'http://localhost/wp-admin/admin.php?page=ran-booster-themes&package=ran-recovery-0123456789-theme-release';
		foreach ( array( 'cookie=secret', 'Authorization: Bearer secret', '_wpnonce=secret', '<html>secret</html>', "hello\nsecret", str_repeat( 'x', 300 ) . ' password=secret', 'password%3Dsecret', 'password&#61;secret', 'password\\u003dsecret', 'cGFzc3dvcmQ9c2VjcmV0', '70617373776f7264', 'the session token is hidden', 'the upstream service is unavailable. ' . str_repeat( 'safe ', 50 ) . 'nonce' ) as $body ) {
			$response = array(
				'response' => array(
					'code'    => 500,
					'message' => 'secret',
				),
				'headers'  => array(
					'location'      => 'https://secret.example/private',
					'authorization' => 'secret',
				),
				'body'     => $body,
			);
			$output   = RecoveryHttpDiagnostic::format( $response, $url, 'theme', 'release', true, false );
			self::assertStringNotContainsString( 'secret', $output );
			self::assertStringContainsString( '"body":"redacted"', $output );
			self::assertStringContainsString( '"reason":"present"', $output );
		}
		foreach ( array( $url . '&_wpnonce=secret', str_replace( 'localhost', 'secret.example', $url ), $url . '%0asecret', str_replace( 'package=', 'token=', $url ) ) as $unsafe_url ) {
			$output = RecoveryHttpDiagnostic::format(
				array(
					'response' => array(
						'code'    => 500,
						'message' => '',
					),
					'headers'  => array(),
					'body'     => '',
				),
				$unsafe_url,
				'theme',
				'release',
				false,
				false
			);
			self::assertStringContainsString( '"url":"redacted"', $output );
			self::assertStringNotContainsString( 'secret', $output );
		}
	}


	public function test_existing_status_and_header_classifications_are_preserved(): void {
		$url    = 'http://localhost/wp-admin/admin.php?page=ran-booster-themes&package=ran-recovery-0123456789-theme-release';
		$output = RecoveryHttpDiagnostic::format(
			array(
				'response' => array(
					'code'    => 302,
					'message' => 'Found',
				),
				'headers'  => array(
					'location'     => 'http://localhost/wp-login.php?secret=private',
					'content-type' => ';text/plain',
				),
				'body'     => '',
			),
			$url,
			'theme',
			'release',
			false,
			false
		);
		self::assertStringContainsString( '"status":302', $output );
		self::assertStringContainsString( '"location_path":"/wp-login.php"', $output );
		self::assertStringContainsString( '"content_type":"text/plain"', $output );
		self::assertStringContainsString( '"body":"absent"', $output );
		self::assertStringNotContainsString( 'private', $output );
	}

	public function test_error_has_classification_without_body_and_success_is_silent(): void {
		$url    = 'http://localhost/wp-admin/admin.php?page=ran-booster-themes&recovery=1&recovery_target=ran-recovery-0123456789-theme-release';
		$output = RecoveryHttpDiagnostic::format( new WP_Error( 'http_request_failed', 'cURL error 52: secret', 'secret' ), $url, 'theme', 'release', true, true );
		self::assertStringContainsString( '"error_code":"http_request_failed"', $output );
		self::assertStringContainsString( 'cURL error 52', $output );
		self::assertStringNotContainsString( 'secret', $output );
		self::assertStringNotContainsString( '"body"', $output );
		self::assertSame(
			'',
			RecoveryHttpDiagnostic::format(
				array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				),
				$url,
				'theme',
				'release',
				false,
				true
			)
		);
	}
}
