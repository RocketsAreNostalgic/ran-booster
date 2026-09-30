<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use Closure;
use RuntimeException;
use Throwable;
use WeakReference;

/** Provider API one-request archive authentication and cleanup lifecycle. */
final class AuthenticatedPreparedArchive implements PreparedArchive {

	public const REDIRECT_HOOK = 'requests-requests.before_redirect';

	/** @var array<string, WeakReference> */
	private static array $reserved_urls = array();

	private bool $authentication_filter_registered = false;
	private bool $redirect_scrubber_registered     = false;
	private bool $cleaned                          = false;
	private ?Closure $authorizer;

	public function __construct(
		private readonly string $url,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private readonly string $resolvedRef,
		?Closure $authorizer = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private readonly ?Closure $headVerifier = null
	) {
		if ( isset( self::$reserved_urls[ $url ] ) && null !== self::$reserved_urls[ $url ]->get() ) {
			throw new RuntimeException( 'The provider archive URL is already prepared for this request.' );
		}

		self::$reserved_urls[ $url ] = WeakReference::create( $this );
		$this->authorizer            = $authorizer;

		if ( null === $authorizer ) {
			return;
		}

		add_filter( 'http_request_args', array( $this, 'authenticateRequest' ), 10, 2 );
		$this->authentication_filter_registered = true;
		add_action( self::REDIRECT_HOOK, array( $this, 'stripAuthenticationFromRedirect' ), 10, 5 );
		$this->redirect_scrubber_registered = true;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function getUrl(): string {
		return $this->url;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function getResolvedRef(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		return $this->resolvedRef;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function verifyCurrentHead(): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		if ( null !== $this->headVerifier ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
			( $this->headVerifier )();
		}
	}

	/**
	 * @param array<string, mixed> $arguments WordPress HTTP request arguments.
	 * @return array<string, mixed>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function authenticateRequest( array $arguments, mixed $url ): array {
		if ( ! is_string( $url ) || $url !== $this->url ) {
			return $arguments;
		}

		$authorizer = $this->authorizer;
		$this->consume_authentication_filter();

		if ( null === $authorizer ) {
			throw new RuntimeException( 'Provider archive authentication is no longer available.' );
		}

		$headers = $arguments['headers'] ?? array();
		if ( ! is_array( $headers ) || $this->has_authorization( $headers ) ) {
			throw new RuntimeException( 'Provider archive authentication could not be applied safely.' );
		}
		$arguments['headers'] = $headers;

		try {
			$authenticated = $authorizer( $arguments );
		} catch ( Throwable ) {
			throw new RuntimeException( 'Provider archive authentication could not be applied safely.' );
		}

		if ( ! is_array( $authenticated ) || ! $this->has_valid_authorization( $authenticated['headers'] ?? null ) ) {
			throw new RuntimeException( 'Provider archive authentication could not be applied safely.' );
		}

		return $authenticated;
	}

	/**
	 * @param mixed                $location Redirect target, passed by reference by Requests.
	 * @param array<string, mixed> $headers  Headers Requests would reuse for the redirect.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function stripAuthenticationFromRedirect( mixed &$location, array &$headers, mixed $data, mixed $options, mixed $original ): void {
		if ( ! is_object( $original ) || ! isset( $original->url ) || $original->url !== $this->url ) {
			return;
		}

		foreach ( array_keys( $headers ) as $name ) {
			if ( 'authorization' === strtolower( (string) $name ) ) {
				unset( $headers[ $name ] );
			}
		}
	}

	public function cleanup(): void {
		if ( $this->cleaned ) {
			return;
		}

		if ( $this->authentication_filter_registered ) {
			remove_filter( 'http_request_args', array( $this, 'authenticateRequest' ), 10 );
		}
		if ( $this->redirect_scrubber_registered ) {
			remove_action( self::REDIRECT_HOOK, array( $this, 'stripAuthenticationFromRedirect' ), 10 );
		}

		$this->authentication_filter_registered = false;
		$this->redirect_scrubber_registered     = false;
		$this->authorizer                       = null;
		$this->cleaned                          = true;
		if ( ( self::$reserved_urls[ $this->url ] ?? null )?->get() === $this ) {
			unset( self::$reserved_urls[ $this->url ] );
		}
	}

	private function __clone(): void {
	}

	/** @param array<string, mixed> $headers */
	private function has_authorization( array $headers ): bool {
		foreach ( array_keys( $headers ) as $name ) {
			if ( 'authorization' === strtolower( (string) $name ) ) {
				return true;
			}
		}

		return false;
	}

	private function has_valid_authorization( mixed $headers ): bool {
		if ( ! is_array( $headers ) ) {
			return false;
		}

		$authorization = array_filter(
			$headers,
			static fn ( mixed $value, mixed $name ): bool => 'authorization' === strtolower( (string) $name ),
			ARRAY_FILTER_USE_BOTH
		);
		$value         = array_values( $authorization )[0] ?? null;

		return 1 === count( $authorization ) && is_string( $value ) && '' !== trim( $value );
	}

	private function consume_authentication_filter(): void {
		if ( $this->authentication_filter_registered ) {
			remove_filter( 'http_request_args', array( $this, 'authenticateRequest' ), 10 );
		}

		$this->authentication_filter_registered = false;
		$this->authorizer                       = null;
	}
}
