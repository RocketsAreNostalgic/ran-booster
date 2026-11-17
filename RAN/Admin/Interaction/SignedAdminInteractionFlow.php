<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

use Closure;
use InvalidArgumentException;
use RAN\Admin\Component\NoticeIcon;
use RAN\Admin\Feedback\FeedbackFlash;
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
	private const QUERY_RETURN       = 'ran_booster_interaction_return';
	private const QUERY_ERROR_REGION = 'ran_booster_interaction_error_region';
	private const QUERY_NONCE        = '_ran_booster_interaction_nonce';

	/** The one-shot per-user row that carries an outcome's display text, so the text never enters a URL (R16). */
	public const MESSAGE_META_KEY = '_ran_booster_interaction_outcome';

	private const MESSAGE_TTL = 60;

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
			echo '<div id="' . esc_attr( $request->error_region_id ) . '" class="notice notice-error inline ran-wp-ui-notice ran-wp-ui-box-sizing is-error" data-ran-booster-admin-mutation-error data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error" data-ran-booster-ui-notice-scope="global" role="alert" tabindex="-1"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p data-ran-booster-ui-notice-primary>' . esc_html( $message ) . '</p></div>' . NoticeIcon::markup( 'error' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- NoticeIcon::markup() escapes its path data; every other value is escaped here.
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
		$this->reap_expired_message();
		$outcome = $this->pending_outcome();
		if ( null === $outcome ) {
			return;
		}

		$request = $outcome['request'];
		if ( $this->is_htmx_target( $request ) ) {
			// The neutral fallback is a persistent notice, never a timed success event.
			if ( ! $outcome['fallback'] && $this->has_success_feedback( $outcome['kind'] ) ) {
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
				$class = $outcome['fallback'] ? 'notice notice-info' : match ( $outcome['kind'] ) {
					AdminInteractionOutcome::SUCCESS            => 'notice notice-success',
					AdminInteractionOutcome::ACCEPTED           => 'notice notice-info',
					AdminInteractionOutcome::VALIDATION_FAILURE,
					AdminInteractionOutcome::UNEXPECTED_FAILURE => 'notice notice-error',
				};
				$role = $outcome['fallback'] || $this->has_success_feedback( $outcome['kind'] ) ? 'status' : 'alert';
				\RAN\Admin\BoosterNoticeScope::render(
					static function () use ( $class, $role, $outcome ): void {
						// Booster screens always load the static Notice CSS; elsewhere the notice keeps the mounted markup.
						$booster = \RAN\Admin\BoosterNoticeScope::is_booster_screen();
						$intent  = substr( $class, 14 );
						if ( $booster && ! $outcome['fallback'] && 'error' !== $intent ) {
							// The static form of the snackbar: the host converts it, and without JavaScript it stays a notice (#755 R14).
							echo FeedbackFlash::container( FeedbackFlash::item( 'interaction_' . $outcome['kind'], $intent, $outcome['message'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- item() escapes every value.
							return;
						}
						echo '<div class="' . esc_attr( $class . ( $booster ? ' inline ran-wp-ui-notice ran-wp-ui-box-sizing is-' . $intent : '' ) ) . '" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="' . esc_attr( $intent ) . '" data-ran-booster-ui-notice-scope="global" role="' . esc_attr( $role ) . '"><div' . ( $booster ? ' class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description"' : '' ) . ' data-ran-booster-ui-notice-content><p>' . esc_html( $outcome['message'] ) . '</p></div>' . ( $booster ? NoticeIcon::markup( $intent ) : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- NoticeIcon::markup() escapes its path data; every other value is escaped here.
					}
				);
			}
		);
	}

	private function signed_outcome_url(
		SignedAdminInteractionRequest $request,
		string $kind,
		string $message
	): string {
		$args = array(
			self::QUERY_OPERATION    => $request->operation,
			self::QUERY_TARGET       => $request->target_key,
			self::QUERY_OUTCOME      => $kind,
			self::QUERY_RETURN       => $request->canonical_url,
			self::QUERY_ERROR_REGION => $request->error_region_id,
		);
		self::hold_message( $this->nonce_action( $args ), $message );
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
	 * @return array{request: SignedAdminInteractionRequest, kind: AdminInteractionOutcome::SUCCESS|AdminInteractionOutcome::ACCEPTED|AdminInteractionOutcome::VALIDATION_FAILURE|AdminInteractionOutcome::UNEXPECTED_FAILURE, message: string, fallback: bool}|null
	 */
	private function pending_outcome(): ?array {
		$operation  = $this->query_string( self::QUERY_OPERATION );
		$target     = $this->query_string( self::QUERY_TARGET );
		$kind       = $this->query_string( self::QUERY_OUTCOME );
		$return_url = $this->query_string( self::QUERY_RETURN );
		$error_id   = $this->query_string( self::QUERY_ERROR_REGION );
		$nonce      = $this->query_string( self::QUERY_NONCE );
		if ( null === $operation
			|| null === $target
			|| null === $kind
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
			self::QUERY_RETURN       => $return_url,
			self::QUERY_ERROR_REGION => $error_id,
		);
		if ( 1 !== wp_verify_nonce( $nonce, $this->nonce_action( $args ) ) ) {
			return null;
		}
		$held     = self::claim_message( $this->nonce_action( $args ) );
		$fallback = null === $held;
		$message  = $held ?? __( 'The result details are not available. Check the page or Activity for the current state.', 'ran-booster' );

		try {
			$this->assert_outcome( $kind, $message );
		} catch ( \Throwable ) {
			return null;
		}

		return array(
			'request'  => $request,
			'kind'     => $kind,
			'message'  => $message,
			'fallback' => $fallback,
		);
	}

	/**
	 * Hold the outcome text for this user's next render on this site. A later outcome replaces an unread one.
	 * The row is presentation only: it is never an Activity, incident or recovery record.
	 */
	public static function hold_message( string $action, string $message ): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			// WordPress unslashes meta values on write, so the row is slashed to round-trip backslashes.
			update_user_meta(
				$user_id,
				self::MESSAGE_META_KEY,
				wp_slash(
					array(
						'at'      => time(),
						'site'    => get_current_blog_id(),
						'action'  => $action,
						'message' => $message,
					)
				)
			);
		}
	}

	/** Delete this user's expired or malformed row; an unexpired row waits for its own signed marker. */
	private function reap_expired_message(): void {
		$user_id = get_current_user_id();
		$held    = $user_id > 0 ? get_user_meta( $user_id, self::MESSAGE_META_KEY, true ) : '';
		if ( '' !== $held && ! self::is_live_row( $held ) ) {
			delete_user_meta( $user_id, self::MESSAGE_META_KEY );
		}
	}

	private static function is_live_row( mixed $held ): bool {
		return is_array( $held )
			&& is_int( $held['at'] ?? null ) && time() - $held['at'] <= self::MESSAGE_TTL
			&& is_int( $held['site'] ?? null ) && is_string( $held['action'] ?? null ) && is_string( $held['message'] ?? null );
	}

	/** Read the held text once, only for the signed marker and site it was written for. */
	public static function claim_message( string $action ): ?string {
		$user_id = get_current_user_id();
		$held    = $user_id > 0 ? get_user_meta( $user_id, self::MESSAGE_META_KEY, true ) : '';
		if ( '' === $held ) {
			return null;
		}
		if ( ! self::is_live_row( $held ) ) {
			delete_user_meta( $user_id, self::MESSAGE_META_KEY );
			return null;
		}
		// Another site's screen leaves the row for its own site, and it expires.
		if ( get_current_blog_id() !== $held['site'] || ! hash_equals( $action, $held['action'] ) ) {
			return null;
		}
		// WordPress selects matching metadata IDs before deleting them; replacement can race this claim.
		return delete_user_meta( $user_id, self::MESSAGE_META_KEY, wp_slash( $held ) ) ? $held['message'] : null;
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
