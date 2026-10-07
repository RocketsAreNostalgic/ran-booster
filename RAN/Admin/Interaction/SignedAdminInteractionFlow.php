<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Shared Core-owned signed POST/redirect/GET transport.
 *
 * @internal
 */
final class SignedAdminInteractionFlow {

	private const QUERY_OPERATION    = 'ran_booster_interaction_operation';
	private const QUERY_TARGET       = 'ran_booster_interaction_target';
	private const QUERY_OUTCOME      = 'ran_booster_interaction_outcome';
	private const QUERY_MESSAGE      = 'ran_booster_interaction_message';
	private const QUERY_RETURN       = 'ran_booster_interaction_return';
	private const QUERY_ERROR_REGION = 'ran_booster_interaction_error_region';
	private const QUERY_NONCE        = '_ran_booster_interaction_nonce';

	/** @var Closure(string, string, string, string): ?SignedAdminInteractionRequest */
	private Closure $resolve_pending_request;

	/** @var Closure(string, string): void */
	private Closure $emit_header;

	/** @var Closure(int): void */
	private Closure $emit_status;

	/** @var Closure(string): void */
	private Closure $redirect;

	/** @var Closure(): never */
	private Closure $terminate;

	/**
	 * @param callable(string, string, string, string): ?SignedAdminInteractionRequest $resolve_pending_request
	 * @param (callable(): never)|null $terminate
	 */
	public function __construct(
		callable $resolve_pending_request,
		?callable $emit_header = null,
		?callable $emit_status = null,
		?callable $redirect = null,
		?callable $terminate = null
	) {
		$this->resolve_pending_request = Closure::fromCallable( $resolve_pending_request );
		$this->emit_header             = null === $emit_header
			? static function ( string $name, string $value ): void {
				header( $name . ': ' . $value );
			}
			: Closure::fromCallable( $emit_header );
		$this->emit_status             = null === $emit_status
			? static function ( int $status ): void {
				status_header( $status );
			}
			: Closure::fromCallable( $emit_status );
		$this->redirect                = null === $redirect
			? static function ( string $url ): void {
				wp_safe_redirect( $url );
			}
			: Closure::fromCallable( $redirect );
		$this->terminate               = null === $terminate
			? static function (): never {
				exit;
			}
			: Closure::fromCallable( $terminate );
	}
	public function is_enhanced_request( SignedAdminInteractionRequest $request ): bool {
		// Transport metadata never authorizes an operation.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Transport metadata selects the HTMX response shape against the signed request target; it never authorizes the operation.
		$payload = $_POST['ran_booster_interaction'] ?? null;

		return $this->is_htmx_target( $request )
			&& is_array( $payload )
			&& is_string( $payload['operation'] ?? null )
			&& is_string( $payload['target'] ?? null )
			&& hash_equals( $request->operation, $payload['operation'] )
			&& hash_equals( $request->target_key, $payload['target'] );
	}

	public function respond(
		SignedAdminInteractionRequest $request,
		string $kind,
		string $message,
		bool $full_page_enhanced_success = false
	): never {
		$this->assert_outcome( $kind, $message );
		if ( $this->is_enhanced_request( $request ) ) {
			if ( $this->has_success_feedback( $kind ) ) {
				$outcome_url = $this->signed_outcome_url( $request, $kind, $message );
				if ( $full_page_enhanced_success ) {
					( $this->emit_status )( $this->status( $kind ) );
					( $this->emit_header )( 'HX-Redirect', $outcome_url );
					( $this->terminate )();
				}

				$location = wp_json_encode(
					array(
						'path'   => wp_make_link_relative( $outcome_url ),
						'target' => $request->target_selector,
						'select' => $request->target_selector,
						'swap'   => 'outerHTML show:none',
					)
				);
				if ( ! is_string( $location ) ) {
					throw new InvalidArgumentException( 'Administration interaction location could not be encoded.' );
				}

				( $this->emit_status )( $this->status( $kind ) );
				( $this->emit_header )( 'HX-Location', $location );
				( $this->terminate )();
			}

			( $this->emit_status )( $this->status( $kind ) );
			( $this->emit_header )( 'HX-Retarget', '#' . $request->error_region_id );
			( $this->emit_header )( 'HX-Reselect', 'unset' );
			( $this->emit_header )( 'HX-Reswap', 'outerHTML' );
			echo '<div id="' . esc_attr( $request->error_region_id ) . '" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1"><p>' . esc_html( $message ) . '</p></div>';
			( $this->terminate )();
		}

		( $this->redirect )( $this->signed_outcome_url( $request, $kind, $message ) );
		( $this->terminate )();
	}

	/**
	 * @param callable(string): void $render_fragment
	 */
	public function respond_with_fragment(
		SignedAdminInteractionRequest $request,
		string $kind,
		string $message,
		callable $render_fragment
	): never {
		$this->assert_outcome( $kind, $message );
		if ( ! $this->is_enhanced_request( $request ) || ! $this->has_success_feedback( $kind ) ) {
			$this->respond( $request, $kind, $message );
		}

		$buffer_level = ob_get_level();
		try {
			ob_start();
			$render_fragment( $request->target_element_id() );
			$fragment = (string) ob_get_clean();
			$this->assert_row_fragment( $request, $fragment );
		} catch ( Throwable ) {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			( $this->emit_status )( $this->status( $kind ) );
			( $this->emit_header )( 'HX-Trigger', $this->success_trigger( $request, $message ) );
			( $this->emit_header )( 'HX-Refresh', 'true' );
			( $this->terminate )();
		}

		( $this->emit_status )( $this->status( $kind ) );
		( $this->emit_header )( 'HX-Replace-Url', wp_make_link_relative( $request->canonical_url ) );
		( $this->emit_header )( 'HX-Trigger-After-Swap', $this->success_trigger( $request, $message ) );
		echo $fragment; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The add-on renderer owns escaping; the exact row wrapper is validated above.
		( $this->terminate )();
	}

	private function success_trigger( SignedAdminInteractionRequest $request, string $message ): string {
		$trigger = wp_json_encode(
			array(
				'ran-booster:admin-mutation-success' => array(
					'message'   => $message,
					'operation' => $request->operation,
				),
			)
		);
		if ( ! is_string( $trigger ) ) {
			throw new InvalidArgumentException( 'Administration interaction feedback could not be encoded.' );
		}

		return $trigger;
	}
	public function prepare_pending_feedback(): void {
		$outcome = $this->pending_outcome();
		if ( null === $outcome ) {
			return;
		}

		$request = $outcome['request'];
		if ( $this->is_htmx_target( $request ) ) {
			if ( $this->has_success_feedback( $outcome['kind'] ) ) {
				$trigger = wp_json_encode(
					array(
						'ran-booster:admin-mutation-success' => array(
							'message'   => $outcome['message'],
							'operation' => $request->operation,
						),
					)
				);
				if ( is_string( $trigger ) ) {
					( $this->emit_header )( 'HX-Trigger-After-Swap', $trigger );
				}
			}
			( $this->emit_header )( 'HX-Replace-Url', wp_make_link_relative( $request->canonical_url ) );

			return;
		}

		add_action(
			'admin_notices',
			function () use ( $outcome ): void {
				$class = match ( $outcome['kind'] ) {
					AdminInteractionOutcome::SUCCESS            => 'notice notice-success',
					AdminInteractionOutcome::ACCEPTED           => 'notice notice-info',
					AdminInteractionOutcome::VALIDATION_FAILURE,
					AdminInteractionOutcome::UNEXPECTED_FAILURE => 'notice notice-error',
				};
				echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $outcome['message'] ) . '</p></div>';
			}
		);
	}

	private function signed_outcome_url(
		SignedAdminInteractionRequest $request,
		string $kind,
		string $message
	): string {
		$args                      = array(
			self::QUERY_OPERATION    => $request->operation,
			self::QUERY_TARGET       => $request->target_key,
			self::QUERY_OUTCOME      => $kind,
			self::QUERY_MESSAGE      => $message,
			self::QUERY_RETURN       => $request->canonical_url,
			self::QUERY_ERROR_REGION => $request->error_region_id,
		);
		$args[ self::QUERY_NONCE ] = wp_create_nonce( $this->nonce_action( $args ) );

		return add_query_arg(
			array_map(
				static fn ( string $value ): string => rawurlencode( $value ),
				$args
			),
			$request->canonical_url
		);
	}

	/**
	 * @return array{request: SignedAdminInteractionRequest, kind: AdminInteractionOutcome::SUCCESS|AdminInteractionOutcome::ACCEPTED|AdminInteractionOutcome::VALIDATION_FAILURE|AdminInteractionOutcome::UNEXPECTED_FAILURE, message: string}|null
	 */
	private function pending_outcome(): ?array {
		$operation  = $this->query_string( self::QUERY_OPERATION );
		$target     = $this->query_string( self::QUERY_TARGET );
		$kind       = $this->query_string( self::QUERY_OUTCOME );
		$message    = $this->query_string( self::QUERY_MESSAGE );
		$return_url = $this->query_string( self::QUERY_RETURN );
		$error_id   = $this->query_string( self::QUERY_ERROR_REGION );
		$nonce      = $this->query_string( self::QUERY_NONCE );
		if ( null === $operation
			|| null === $target
			|| null === $kind
			|| null === $message
			|| null === $return_url
			|| null === $error_id
			|| null === $nonce ) {
			return null;
		}

		try {
			$request = ( $this->resolve_pending_request )( $operation, $target, $return_url, $error_id );
		} catch ( \Throwable ) {
			return null;
		}
		if ( null === $request || ! $this->current_request_matches( $request ) ) {
			return null;
		}

		$args = array(
			self::QUERY_OPERATION    => $operation,
			self::QUERY_TARGET       => $target,
			self::QUERY_OUTCOME      => $kind,
			self::QUERY_MESSAGE      => $message,
			self::QUERY_RETURN       => $return_url,
			self::QUERY_ERROR_REGION => $error_id,
		);
		if ( 1 !== wp_verify_nonce( $nonce, $this->nonce_action( $args ) ) ) {
			return null;
		}

		try {
			$this->assert_outcome( $kind, $message );
		} catch ( \Throwable ) {
			return null;
		}

		return array(
			'request' => $request,
			'kind'    => $kind,
			'message' => $message,
		);
	}

	/** @phpstan-assert AdminInteractionOutcome::SUCCESS|AdminInteractionOutcome::ACCEPTED|AdminInteractionOutcome::VALIDATION_FAILURE|AdminInteractionOutcome::UNEXPECTED_FAILURE $kind */
	private function assert_outcome( string $kind, string $message ): void {
		if ( ! in_array(
			$kind,
			array(
				AdminInteractionOutcome::SUCCESS,
				AdminInteractionOutcome::ACCEPTED,
				AdminInteractionOutcome::VALIDATION_FAILURE,
				AdminInteractionOutcome::UNEXPECTED_FAILURE,
			),
			true
		)
			|| '' === trim( $message )
			|| strlen( $message ) > 255
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $message ) ) {
			throw new InvalidArgumentException( 'Administration interaction outcomes require bounded display-safe values.' );
		}
	}

	/** @param AdminInteractionOutcome::SUCCESS|AdminInteractionOutcome::ACCEPTED|AdminInteractionOutcome::VALIDATION_FAILURE|AdminInteractionOutcome::UNEXPECTED_FAILURE $kind */
	private function status( string $kind ): int {
		return match ( $kind ) {
			AdminInteractionOutcome::SUCCESS            => 200,
			AdminInteractionOutcome::ACCEPTED           => 202,
			AdminInteractionOutcome::VALIDATION_FAILURE => 422,
			AdminInteractionOutcome::UNEXPECTED_FAILURE => 500,
		};
	}

	private function has_success_feedback( string $kind ): bool {
		return in_array( $kind, array( AdminInteractionOutcome::SUCCESS, AdminInteractionOutcome::ACCEPTED ), true );
	}

	private function assert_row_fragment( SignedAdminInteractionRequest $request, string $fragment ): void {
		$element_id = preg_quote( $request->target_element_id(), '/' );
		$opening    = "/^\\s*<tr\\b(?=[^>]*\\bid=([\"'])" . $element_id . "\\1)[^>]*>/i";
		if ( '' === trim( $fragment )
			|| strlen( $fragment ) > 524288
			|| 1 !== preg_match( $opening, $fragment )
			|| 1 !== preg_match( '/<\\/tr>\\s*$/i', $fragment )
			|| 1 !== preg_match_all( '/<\\s*tr\\b/i', $fragment )
			|| 1 !== preg_match_all( '/<\\s*\\/\\s*tr\\s*>/i', $fragment )
			|| 1 === preg_match( '/<\\s*(?:script|iframe|object|embed)\\b/i', $fragment ) ) {
			throw new InvalidArgumentException( 'Transporter migration responses require one bounded exact row fragment.' );
		}
	}

	/** @param array<string, string> $args */
	private function nonce_action( array $args ): string {
		$encoded = wp_json_encode( $args );

		return 'ran-booster-admin-interaction|' . hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	private function is_htmx_target( SignedAdminInteractionRequest $request ): bool {
		$hx_request = $_SERVER['HTTP_HX_REQUEST'] ?? null;
		$hx_target  = $_SERVER['HTTP_HX_TARGET'] ?? null;

		return is_string( $hx_request )
			&& 'true' === strtolower( trim( $hx_request ) )
			&& is_string( $hx_target )
			&& hash_equals( $request->target_element_id(), trim( $hx_target ) );
	}

	private function current_request_matches( SignedAdminInteractionRequest $request ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Project the validated URL into read-only route values using the existing signed-request DTO contract.
		$url = parse_url( $request->canonical_url );
		if ( ! is_array( $url ) ) {
			return false;
		}
		parse_str( (string) ( $url['query'] ?? '' ), $expected );

		foreach ( $expected as $key => $expected_value ) {
			if ( ! is_string( $key )
				|| ! is_string( $expected_value )
				|| str_starts_with( $key, 'ran_booster_interaction_' )
				|| self::QUERY_NONCE === $key ) {
				return false;
			}
			// Read-only routing is compared with the signed canonical URL.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Compare read-only route values with the resolved canonical URL before pending_outcome() verifies the complete signed marker.
			$current = $_GET[ $key ] ?? null;
			if ( ! is_string( $current )
				|| ! hash_equals( $expected_value, wp_unslash( $current ) ) ) {
				return false;
			}
		}

		return true;
	}

	private function query_string( string $key ): ?string {
		// Display-only values are used only after complete marker verification.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read and bound marker components; pending_outcome() verifies their combined action nonce before returning display data.
		$value = $_GET[ $key ] ?? null;
		if ( ! is_string( $value ) || strlen( $value ) > 2048 ) {
			return null;
		}

		return trim( wp_unslash( $value ) );
	}
}
