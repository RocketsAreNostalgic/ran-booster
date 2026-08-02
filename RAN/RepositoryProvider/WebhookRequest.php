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
	private array $rawHeaders;

	private ?SignedWebhookVerification $verification = null;

	/**
	 * @param array<string, string|list<string>> $headers         Native WordPress REST request headers.
	 * @param list<string>                       $retainedHeaders Provider-owned canonical header names.
	 */
	public function __construct(
		private ProviderCode $provider,
		private string $body,
		array $headers,
		array $retainedHeaders
	) {
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			throw new WebhookRejected( 413, 'Webhook request is too large.' );
		}

		$retainedHeaders = $this->validateRetainedHeaders( $retainedHeaders );
		$normalized      = array();
		$retained        = array();
		$retainedBytes   = 0;

		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) ) {
				throw new InvalidArgumentException( 'Webhook header names must be strings.' );
			}

			$name = $this->normalizeHeaderName( $name );

			if ( ! in_array( $name, $retainedHeaders, true ) ) {
				continue;
			}

			$values = is_array( $value ) ? $value : array( $value );
			foreach ( $values as $rawValue ) {
				if ( ! is_string( $rawValue ) || strlen( $rawValue ) > self::MAX_HEADER_BYTES ) {
					throw new InvalidArgumentException( 'Webhook header values are too large.' );
				}

				$retainedBytes += strlen( $name ) + strlen( $rawValue );
				if ( $retainedBytes > self::MAX_RETAINED_BYTES ) {
					throw new InvalidArgumentException( 'Webhook retained headers are too large.' );
				}
			}
			$value = $this->normalizeHeaderValue( $values );

			$retained[ $name ] ??= array();
			array_push( $retained[ $name ], ...$values );

			if ( isset( $normalized[ $name ] ) && $normalized[ $name ] !== $value ) {
				throw new InvalidArgumentException( 'Webhook headers cannot contain ambiguous values.' );
			}

			$normalized[ $name ] = $value;
		}

		$this->headers    = $normalized;
		$this->rawHeaders = $retained;
	}

	public function getProvider(): ProviderCode {
		return $this->provider;
	}

	public function getBody(): string {
		return $this->body;
	}

	public function withVerification( SignedWebhookVerification $verification ): self {
		if ( ! $verification->getProvider()->equals( $this->provider ) ) {
			throw new InvalidArgumentException( 'Webhook verification provider does not match the request.' );
		}

		$verified               = clone $this;
		$verified->verification = $verification;

		return $verified;
	}

	public function requireVerification(): SignedWebhookVerification {
		if ( null === $this->verification ) {
			throw new WebhookRejected( 401, 'Webhook authentication failed.' );
		}

		return $this->verification;
	}

	public function getHeader( string $name ): ?string {
		return $this->headers[ $this->normalizeHeaderName( $name ) ] ?? null;
	}

	/**
	 * Return every untouched retained value for a canonical header name.
	 *
	 * @return list<string>
	 */
	public function getRawHeaderValues( string $name ): array {
		return $this->rawHeaders[ $this->normalizeHeaderName( $name ) ] ?? array();
	}

	private function normalizeHeaderName( string $name ): string {
		return str_replace( '_', '-', strtolower( trim( $name ) ) );
	}

	/**
	 * @param list<string> $headers Provider-owned canonical header names.
	 * @return list<string>
	 */
	private function validateRetainedHeaders( array $headers ): array {
		if ( count( $headers ) > self::MAX_RETAINED_HEADERS ) {
			throw new InvalidArgumentException( 'Webhook header policy is too large.' );
		}

		$normalized = array();
		foreach ( $headers as $header ) {
			if ( ! is_string( $header )
				|| $header !== $this->normalizeHeaderName( $header )
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
	private function normalizeHeaderValue( array $values ): string {
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
