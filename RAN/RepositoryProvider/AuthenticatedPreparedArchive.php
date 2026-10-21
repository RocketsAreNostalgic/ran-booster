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

	/** @var array<string, WeakReference<self>> */
	private static array $reserved_urls = array();

	private bool $operation_filter_registered      = false;
	private bool $authentication_filter_registered = false;
	private bool $redirect_scrubber_registered     = false;
	private bool $cleaned                          = false;
	private ?Closure $authorizer;
	/** @var WeakReference<object>|null */
	private ?WeakReference $redirect_hooks = null;

	/** Optional operation guard is independent of authentication; only literal true grants access. */
	public function __construct(
		private readonly string $url,
		private readonly string $resolved_ref,
		?Closure $authorizer = null,
		private readonly ?Closure $head_verifier = null,
		private readonly ?Closure $operation_guard = null
	) {
		if ( isset( self::$reserved_urls[ $url ] ) && null !== self::$reserved_urls[ $url ]->get() ) {
			throw new RuntimeException( 'The provider archive URL is already prepared for this request.' );
		}

		self::$reserved_urls[ $url ] = WeakReference::create( $this );
		$this->authorizer            = $authorizer;

		if ( null !== $operation_guard ) {
			add_filter( 'http_request_args', array( $this, 'guard_request' ), PHP_INT_MAX, 2 );
			$this->operation_filter_registered = true;
		}
		if ( null !== $authorizer ) {
			add_filter( 'http_request_args', array( $this, 'authenticate_request' ), 10, 2 );
			$this->authentication_filter_registered = true;
		}
		if ( null === $authorizer && null === $operation_guard ) {
			return;
		}
		add_action( self::REDIRECT_HOOK, array( $this, 'strip_authentication_from_redirect' ), 10, 5 );
		$this->redirect_scrubber_registered = true;
	}


	public function get_url(): string {
		$this->assert_operation_allowed();
		return $this->url;
	}


	public function get_resolved_ref(): string {
		$this->assert_operation_allowed();
		return $this->resolved_ref;
	}


	public function verify_current_head(): void {
		$this->assert_head_verification_allowed();
		if ( null !== $this->head_verifier ) {
			( $this->head_verifier )();
		}
		$this->assert_head_verification_allowed();
	}

	/**
	 * @param array<string, mixed> $arguments WordPress HTTP request arguments.
	 * @return array<string, mixed>
	 */

	public function authenticate_request( array $arguments, mixed $url ): array {
		if ( ! is_string( $url ) || $url !== $this->url ) {
			return $arguments;
		}

		$this->assert_operation_allowed();
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

		$this->assert_operation_allowed();
		return $authenticated;
	}

	/**
	 * @param mixed                $location Redirect target, passed by reference by Requests.
	 * @param array<string, mixed> $headers  Headers Requests would reuse for the redirect.
	 */

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceBeforeLastUsed -- Requests before_redirect fixes all five positional arguments; retain the reference location and data/options slots before the original request.
	public function strip_authentication_from_redirect( mixed &$location, array &$headers, mixed $data, mixed $options, mixed $original ): void {
		$hooks       = is_array( $options ) ? ( $options['hooks'] ?? null ) : null;
		$owned_chain = null !== $this->operation_guard && is_object( $hooks ) && $hooks === $this->redirect_hooks?->get();
		if ( ! is_object( $original ) || ! isset( $original->url ) || ( $original->url !== $this->url && ! $owned_chain ) ) {
			return;
		}
		if ( null !== $this->operation_guard ) {
			// WordPress creates one Requests hook object per HTTP call; recursive redirects retain it.
			if ( ! is_object( $hooks ) || ( null !== $this->redirect_hooks?->get() && ! $owned_chain ) ) {
				throw new RuntimeException( 'Provider archive operation is unavailable.' );
			}
			$this->redirect_hooks = WeakReference::create( $hooks );
		}

		foreach ( array_keys( $headers ) as $name ) {
			if ( 'authorization' === strtolower( (string) $name ) ) {
				unset( $headers[ $name ] );
			}
		}
		$this->assert_operation_allowed();
	}

	/** @param array<string, mixed> $arguments
	 * @return array<string, mixed>
	 */
	public function guard_request( array $arguments, mixed $url ): array {
		if ( is_string( $url ) && $url === $this->url ) {
			$this->assert_operation_allowed();
		}
		return $arguments;
	}

	private function assert_operation_allowed(): void {
		if ( null !== $this->operation_guard ) {
			if ( $this->cleaned ) {
				throw new RuntimeException( 'Provider archive operation is unavailable.' );
			}
			$this->assert_head_verification_allowed();
		}
	}

	/** Current-head authority outlives cleanup of one-request archive resources. */
	private function assert_head_verification_allowed(): void {
		if ( null === $this->operation_guard ) {
			return;
		}
		try {
			$allowed = true === ( $this->operation_guard )();
		} catch ( Throwable ) {
			$allowed = false;
		}
		if ( ! $allowed ) {
			throw new RuntimeException( 'Provider archive operation is unavailable.' );
		}
	}

	public function cleanup(): void {
		if ( $this->cleaned ) {
			return;
		}

		if ( $this->operation_filter_registered ) {
			remove_filter( 'http_request_args', array( $this, 'guard_request' ), PHP_INT_MAX );
		}
		$this->operation_filter_registered = false;
		if ( $this->authentication_filter_registered ) {
			remove_filter( 'http_request_args', array( $this, 'authenticate_request' ), 10 );
		}
		if ( $this->redirect_scrubber_registered ) {
			remove_action( self::REDIRECT_HOOK, array( $this, 'strip_authentication_from_redirect' ), 10 );
		}

		$this->authentication_filter_registered = false;
		$this->redirect_scrubber_registered     = false;
		$this->authorizer                       = null;
		$this->cleaned                          = true;
		$this->redirect_hooks                   = null;
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
			static fn ( mixed $name ): bool => 'authorization' === strtolower( (string) $name ),
			ARRAY_FILTER_USE_KEY
		);
		$value         = array_values( $authorization )[0] ?? null;

		return 1 === count( $authorization ) && is_string( $value ) && '' !== trim( $value );
	}

	private function consume_authentication_filter(): void {
		if ( $this->authentication_filter_registered ) {
			remove_filter( 'http_request_args', array( $this, 'authenticate_request' ), 10 );
		}

		$this->authentication_filter_registered = false;
		$this->authorizer                       = null;
	}
}
