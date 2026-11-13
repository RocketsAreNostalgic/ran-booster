<?php

declare(strict_types=1);

namespace RAN\Logging;

use RAN\UpdaterSupport\V1\FailureText;
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

	/** @param array<array-key, mixed> $context Untrusted context filtered to safe keys and scalar values. */
	public static function log( string $message, array $context = array() ): bool {
		$wordpress_logging = self::enabled();
		if ( ! $wordpress_logging && null === self::$capture ) {
			return false;
		}

		$line            = self::PREFIX . ' ' . self::screened_message( $message );
		$sanitized       = self::sanitize_context( $context );
		$encoded_context = wp_json_encode(
			$sanitized,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);

		if ( is_string( $encoded_context ) && '{}' !== $encoded_context ) {
			$line .= ' ' . $encoded_context;
		}

		$wordpress_logged = false;
		if ( $wordpress_logging ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- This is the single gated WordPress debug-log boundary for Booster.
			$wordpress_logged = error_log( $line );
		}

		$capture_logged = self::$capture?->append( $line ) ?? false;

		return $wordpress_logged || $capture_logged;
	}


	/** @param array<array-key, mixed> $context Untrusted context filtered to safe keys and scalar values. */
	public static function log_exception( string $message, Throwable $exception, array $context = array() ): void {
		$context['exception_class'] = $exception::class;
		$code                       = $exception->getCode();
		// A string code (for example a database state) is provider-shaped text, so only an integer code is recorded.
		if ( is_int( $code ) ) {
			$context['exception_code'] = (string) $code;
		}

		self::log( $message, $context );
	}


	public static function configure_capture( ?TemporaryDebugCapture $capture ): void {
		self::$capture = $capture;
	}

	private static function enabled(): bool {
		return defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG;
	}

	/**
	 * @param array<array-key, mixed> $context
	 * @return array<string, string|bool|int|float|null>
	 */
	private static function sanitize_context( array $context ): array {
		$safe = array();

		foreach ( $context as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( self::SAFE_CONTEXT_KEYS[ $key ] ) ) {
				continue;
			}

			if ( is_string( $value ) ) {
				$line = self::screened_value( $key, self::one_line( $value ) );
				if ( null === $line ) {
					continue;
				}
				$safe[ $key ] = $line;
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$safe[ $key ] = $value;
			}
		}

		return $safe;
	}

	/** The log message is Core's own fixed text: shape-screened and never truncated; a rejected message is replaced, not redacted. */
	private static function screened_message( string $message ): string {
		$line = self::one_line( $message );

		return FailureText::display( $line, 512 ) === $line ? $line : 'message withheld';
	}

	/**
	 * A string context value, or null to drop the field. Ids and commit ids take the closed shared shapes; the
	 * exact correlation id, class names and numeric codes are matched locally; any other value is untrusted text.
	 */
	private static function screened_value( string $key, string $line ): ?string {
		if ( '' === $line ) {
			return '';
		}

		return match ( $key ) {
			'correlation_id' => self::exact( '/\A[0-9a-f]{32}\z/', $line ),
			'resolved_ref' => FailureText::commit_id( $line ) ?? FailureText::identifier( $line ),
			'exception_class' => self::exact( '/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $line ),
			'exception_code' => self::exact( '/\A-?[0-9]{1,10}\z/', $line ),
			'transition' => self::transition( $line ),
			'diagnostic_id', 'event', 'operation', 'outcome_code', 'package_slug', 'provider', 'resolved_by', 'source', 'state', 'step' => FailureText::identifier( $line ),
			default => FailureText::strict( $line, 191 ) === $line ? $line : null,
		};
	}

	/** The value when it matches one closed local shape and the 191 byte bound, otherwise null. */
	private static function exact( string $pattern, string $line ): ?string {
		return 1 === preg_match( $pattern, $line ) && strlen( $line ) <= 191 ? $line : null;
	}

	/** A state transition such as `queued->running`: exactly two closed identifiers. */
	private static function transition( string $line ): ?string {
		$parts = explode( '->', $line );

		return 2 === count( $parts ) && null !== FailureText::identifier( $parts[0] ) && null !== FailureText::identifier( $parts[1] ) ? $line : null;
	}

	private static function one_line( string $value ): string {
		$value      = trim( $value );
		$normalized = preg_replace( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value );
		if ( ! is_string( $normalized ) ) {
			$normalized = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		}

		return is_string( $normalized ) ? trim( $normalized ) : '';
	}
}
