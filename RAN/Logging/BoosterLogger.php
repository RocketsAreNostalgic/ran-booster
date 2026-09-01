<?php

declare(strict_types=1);

namespace RAN\Logging;

use Throwable;

final class BoosterLogger {

	private const PREFIX = '[ran-booster]';

	private static ?TemporaryDebugCapture $capture = null;

	/** @var array<string, true> */
	private const SAFE_CONTEXT_KEYS = array(
		'attempt_id'      => true,
		'correlation_id'  => true,
		'diagnostic_id'   => true,
		'event'           => true,
		'exception_class' => true,
		'exception_code'  => true,
		'operation'       => true,
		'outcome_code'    => true,
		'package_slug'    => true,
		'provider'        => true,
		'resolved_by'     => true,
		'resolved_ref'    => true,
		'source'          => true,
		'state'           => true,
		'step'            => true,
		'transition'      => true,
	);

	public static function log( string $message, array $context = array() ): bool {
		$wordpressLogging = self::enabled();
		if ( ! $wordpressLogging && null === self::$capture ) {
			return false;
		}

		$line           = self::PREFIX . ' ' . self::oneLine( $message );
		$sanitized      = self::sanitizeContext( $context );
		$encodedContext = wp_json_encode(
			$sanitized,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);

		if ( is_string( $encodedContext ) && '{}' !== $encodedContext ) {
			$line .= ' ' . $encodedContext;
		}

		$wordpressLogged = false;
		if ( $wordpressLogging ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This is the single gated WordPress debug-log boundary for Booster.
			$wordpressLogged = error_log( $line );
		}

		$captureLogged = self::$capture?->append( $line ) ?? false;

		return $wordpressLogged || $captureLogged;
	}

	public static function logException( string $message, Throwable $exception, array $context = array() ): void {
		$context['exception_class'] = $exception::class;
		$code                       = $exception->getCode();
		if ( is_int( $code ) || is_string( $code ) ) {
			$context['exception_code'] = (string) $code;
		}

		self::log( $message, $context );
	}

	public static function configureCapture( ?TemporaryDebugCapture $capture ): void {
		self::$capture = $capture;
	}

	private static function enabled(): bool {
		return defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG;
	}

	private static function sanitizeContext( array $context ): array {
		$safe = array();

		foreach ( $context as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( self::SAFE_CONTEXT_KEYS[ $key ] ) ) {
				continue;
			}

			if ( is_string( $value ) ) {
				$safe[ $key ] = self::oneLine( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$safe[ $key ] = $value;
			}
		}

		return $safe;
	}

	private static function oneLine( string $value ): string {
		$value      = trim( $value );
		$normalized = preg_replace( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value );
		if ( ! is_string( $normalized ) ) {
			$normalized = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		}

		return is_string( $normalized ) ? trim( $normalized ) : '';
	}
}
