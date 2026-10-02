<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final class WebhookRequest {

	private const MAX_RETAINED_HEADERS = 16;
	private const MAX_BODY_BYTES       = 262144;
	private const MAX_HEADER_BYTES     = 256;
	private const MAX_RETAINED_BYTES   = 2048;
	private const SENSITIVE_HEADERS    = array(
		'authorization',
		'proxy-authorization',
		'cookie',
		'set-cookie',
	);

	/**
	 * @var array<string, string>
	 */
	private array $headers;

	/**
	 * @var array<string, list<string>>
	 */
	private array $raw_headers;

	private ?SignedWebhookVerification $verification = null;

	/**
	 * @param array<string, string|list<string>> $headers         Native WordPress REST request headers.
	 * @param list<string>                       $retained_headers Provider-owned canonical header names.
	 */
	public function __construct(
		private ProviderCode $provider,
		private string $body,
		array $headers,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		array $retained_headers
	) {
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			throw new WebhookRejected( 413, 'Webhook request is too large.' );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		$retained_headers = $this->validate_retained_headers( $retained_headers );
		$normalized      = array();
		$retained        = array();
		$retained_bytes  = 0;

		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) ) {
				throw new InvalidArgumentException( 'Webhook header names must be strings.' );
			}

			$name = $this->normalize_header_name( $name );

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			if ( ! in_array( $name, $retained_headers, true ) ) {
				continue;
			}

			$values = is_array( $value ) ? $value : array( $value );
			foreach ( $values as $raw_value ) {
				if ( ! is_string( $raw_value ) || strlen( $raw_value ) > self::MAX_HEADER_BYTES ) {
					throw new InvalidArgumentException( 'Webhook header values are too large.' );
				}

				$retained_bytes += strlen( $name ) + strlen( $raw_value );
				if ( $retained_bytes > self::MAX_RETAINED_BYTES ) {
					throw new InvalidArgumentException( 'Webhook retained headers are too large.' );
				}
			}
			$value = $this->normalize_header_value( $values );

			$retained[ $name ] ??= array();
			array_push( $retained[ $name ], ...$values );

			if ( isset( $normalized[ $name ] ) && $normalized[ $name ] !== $value ) {
				throw new InvalidArgumentException( 'Webhook headers cannot contain ambiguous values.' );
			}

			$normalized[ $name ] = $value;
		}

		$this->headers     = $normalized;
		$this->raw_headers = $retained;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function get_provider(): ProviderCode {
		return $this->provider;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function get_body(): string {
		return $this->body;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function with_verification( SignedWebhookVerification $verification ): self {
		if ( ! $verification->get_provider()->equals( $this->provider ) ) {
			throw new InvalidArgumentException( 'Webhook verification provider does not match the request.' );
		}

		$verified               = clone $this;
		$verified->verification = $verification;

		return $verified;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function require_verification(): SignedWebhookVerification {
		if ( null === $this->verification ) {
			throw new WebhookRejected( 401, 'Webhook authentication failed.' );
		}

		return $this->verification;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function get_header( string $name ): ?string {
		return $this->headers[ $this->normalize_header_name( $name ) ] ?? null;
	}

	/**
	 * Return every untouched retained value for a canonical header name.
	 *
	 * @return list<string>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function get_raw_header_values( string $name ): array {
		return $this->raw_headers[ $this->normalize_header_name( $name ) ] ?? array();
	}

	private function normalize_header_name( string $name ): string {
		return str_replace( '_', '-', strtolower( trim( $name ) ) );
	}

	/**
	 * @param list<string> $headers Provider-owned canonical header names.
	 * @return list<string>
	 */
	private function validate_retained_headers( array $headers ): array {
		if ( count( $headers ) > self::MAX_RETAINED_HEADERS ) {
			throw new InvalidArgumentException( 'Webhook header policy is too large.' );
		}

		$normalized = array();
		foreach ( $headers as $header ) {
			if ( ! is_string( $header )
				|| $header !== $this->normalize_header_name( $header )
				|| 1 !== preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $header )
				|| in_array( $header, self::SENSITIVE_HEADERS, true )
				|| isset( $normalized[ $header ] )
			) {
				throw new InvalidArgumentException( 'Webhook header policy is invalid.' );
			}

			$normalized[ $header ] = true;
		}

		return array_keys( $normalized );
	}

	/**
	 * @param list<string> $values Header values from WordPress.
	 */
	private function normalize_header_value( array $values ): string {
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Webhook header values must be strings.' );
			}
		}

		$normalized = array_values( array_unique( array_map( 'trim', $values ) ) );
		if ( 1 !== count( $normalized ) || '' === $normalized[0] ) {
			throw new InvalidArgumentException( 'Webhook headers must contain one unambiguous value.' );
		}

		return $normalized[0];
	}
}
