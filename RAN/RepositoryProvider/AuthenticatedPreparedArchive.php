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
	private static array $reservedUrls = array();

	private bool $authenticationFilterRegistered = false;
	private bool $redirectScrubberRegistered     = false;
	private bool $cleaned                        = false;
	private ?Closure $authorizer;

	public function __construct(
		private readonly string $url,
		private readonly string $resolvedRef,
		?Closure $authorizer = null,
		private readonly ?Closure $headVerifier = null
	) {
		if ( isset( self::$reservedUrls[ $url ] ) && null !== self::$reservedUrls[ $url ]->get() ) {
			throw new RuntimeException( 'The provider archive URL is already prepared for this request.' );
		}

		self::$reservedUrls[ $url ] = WeakReference::create( $this );
		$this->authorizer           = $authorizer;

		if ( null === $authorizer ) {
			return;
		}

		add_filter( 'http_request_args', array( $this, 'authenticateRequest' ), 10, 2 );
		$this->authenticationFilterRegistered = true;
		add_action( self::REDIRECT_HOOK, array( $this, 'stripAuthenticationFromRedirect' ), 10, 5 );
		$this->redirectScrubberRegistered = true;
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function getResolvedRef(): string {
		return $this->resolvedRef;
	}

	public function verifyCurrentHead(): void {
		if ( null !== $this->headVerifier ) {
			( $this->headVerifier )();
		}
	}

	/**
	 * @param array<string, mixed> $arguments WordPress HTTP request arguments.
	 * @return array<string, mixed>
	 */
	public function authenticateRequest( array $arguments, mixed $url ): array {
		if ( ! is_string( $url ) || $url !== $this->url ) {
			return $arguments;
		}

		$authorizer = $this->authorizer;
		$this->consumeAuthenticationFilter();

		if ( null === $authorizer ) {
			throw new RuntimeException( 'Provider archive authentication is no longer available.' );
		}

		$headers = $arguments['headers'] ?? array();
		if ( ! is_array( $headers ) || $this->hasAuthorization( $headers ) ) {
			throw new RuntimeException( 'Provider archive authentication could not be applied safely.' );
		}
		$arguments['headers'] = $headers;

		try {
			$authenticated = $authorizer( $arguments );
		} catch ( Throwable ) {
			throw new RuntimeException( 'Provider archive authentication could not be applied safely.' );
		}

		if ( ! is_array( $authenticated ) || ! $this->hasValidAuthorization( $authenticated['headers'] ?? null ) ) {
			throw new RuntimeException( 'Provider archive authentication could not be applied safely.' );
		}

		return $authenticated;
	}

	/**
	 * @param mixed                $location Redirect target, passed by reference by Requests.
	 * @param array<string, mixed> $headers  Headers Requests would reuse for the redirect.
	 */
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

		if ( $this->authenticationFilterRegistered ) {
			remove_filter( 'http_request_args', array( $this, 'authenticateRequest' ), 10 );
		}
		if ( $this->redirectScrubberRegistered ) {
			remove_action( self::REDIRECT_HOOK, array( $this, 'stripAuthenticationFromRedirect' ), 10 );
		}

		$this->authenticationFilterRegistered = false;
		$this->redirectScrubberRegistered     = false;
		$this->authorizer                     = null;
		$this->cleaned                        = true;
		if ( ( self::$reservedUrls[ $this->url ] ?? null )?->get() === $this ) {
			unset( self::$reservedUrls[ $this->url ] );
		}
	}

	private function __clone(): void {
	}

	/** @param array<string, mixed> $headers */
	private function hasAuthorization( array $headers ): bool {
		foreach ( array_keys( $headers ) as $name ) {
			if ( 'authorization' === strtolower( (string) $name ) ) {
				return true;
			}
		}

		return false;
	}

	private function hasValidAuthorization( mixed $headers ): bool {
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

	private function consumeAuthenticationFilter(): void {
		if ( $this->authenticationFilterRegistered ) {
			remove_filter( 'http_request_args', array( $this, 'authenticateRequest' ), 10 );
		}

		$this->authenticationFilterRegistered = false;
		$this->authorizer                     = null;
	}
}
