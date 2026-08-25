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
	private Closure $resolvePendingRequest;

	/** @var Closure(string, string): void */
	private Closure $emitHeader;

	/** @var Closure(int): void */
	private Closure $emitStatus;

	/** @var Closure(string): void */
	private Closure $redirect;

	/** @var Closure(): never */
	private Closure $terminate;

	/**
	 * @param callable(string, string, string, string): ?SignedAdminInteractionRequest $resolvePendingRequest
	 */
	public function __construct(
		callable $resolvePendingRequest,
		?callable $emitHeader = null,
		?callable $emitStatus = null,
		?callable $redirect = null,
		?callable $terminate = null
	) {
		$this->resolvePendingRequest = Closure::fromCallable( $resolvePendingRequest );
		$this->emitHeader            = null === $emitHeader
			? static function ( string $name, string $value ): void {
				header( $name . ': ' . $value );
			}
			: Closure::fromCallable( $emitHeader );
		$this->emitStatus            = null === $emitStatus
			? static function ( int $status ): void {
				status_header( $status );
			}
			: Closure::fromCallable( $emitStatus );
		$this->redirect              = null === $redirect
			? static function ( string $url ): void {
				wp_safe_redirect( $url );
			}
			: Closure::fromCallable( $redirect );
		$this->terminate             = null === $terminate
			? static function (): never {
				exit;
			}
			: Closure::fromCallable( $terminate );
	}

	public function isEnhancedRequest( SignedAdminInteractionRequest $request ): bool {
		// Transport metadata never authorizes an operation.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$payload = $_POST['ran_booster_interaction'] ?? null;

		return $this->isHtmxTarget( $request )
			&& is_array( $payload )
			&& is_string( $payload['operation'] ?? null )
			&& is_string( $payload['target'] ?? null )
			&& hash_equals( $request->operation, $payload['operation'] )
			&& hash_equals( $request->targetKey, $payload['target'] );
	}

	public function respond(
		SignedAdminInteractionRequest $request,
		string $kind,
		string $message,
		bool $fullPageEnhancedSuccess = false
	): never {
		$this->assertOutcome( $kind, $message );
		if ( $this->isEnhancedRequest( $request ) ) {
			if ( $this->hasSuccessFeedback( $kind ) ) {
				$outcomeUrl = $this->signedOutcomeUrl( $request, $kind, $message );
				if ( $fullPageEnhancedSuccess ) {
					( $this->emitStatus )( $this->status( $kind ) );
					( $this->emitHeader )( 'HX-Redirect', $outcomeUrl );
					( $this->terminate )();
				}

				$location = wp_json_encode(
					array(
						'path'   => wp_make_link_relative( $outcomeUrl ),
						'target' => $request->targetSelector,
						'select' => $request->targetSelector,
						'swap'   => 'outerHTML show:none',
					)
				);
				if ( ! is_string( $location ) ) {
					throw new InvalidArgumentException( 'Administration interaction location could not be encoded.' );
				}

				( $this->emitStatus )( $this->status( $kind ) );
				( $this->emitHeader )( 'HX-Location', $location );
				( $this->terminate )();
			}

			( $this->emitStatus )( $this->status( $kind ) );
			( $this->emitHeader )( 'HX-Retarget', '#' . $request->errorRegionId );
			( $this->emitHeader )( 'HX-Reselect', 'unset' );
			( $this->emitHeader )( 'HX-Reswap', 'outerHTML' );
			echo '<div id="' . esc_attr( $request->errorRegionId ) . '" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1"><p>' . esc_html( $message ) . '</p></div>';
			( $this->terminate )();
		}

		( $this->redirect )( $this->signedOutcomeUrl( $request, $kind, $message ) );
		( $this->terminate )();
	}

	/**
	 * @param callable(string): void $renderFragment
	 */
	public function respondWithFragment(
		SignedAdminInteractionRequest $request,
		string $kind,
		string $message,
		callable $renderFragment
	): never {
		$this->assertOutcome( $kind, $message );
		if ( ! $this->isEnhancedRequest( $request ) || ! $this->hasSuccessFeedback( $kind ) ) {
			$this->respond( $request, $kind, $message );
		}

		$bufferLevel = ob_get_level();
		try {
			ob_start();
			$renderFragment( $request->targetElementId() );
			$fragment = (string) ob_get_clean();
			$this->assertRowFragment( $request, $fragment );
		} catch ( Throwable ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}
			( $this->emitStatus )( $this->status( $kind ) );
			( $this->emitHeader )( 'HX-Trigger', $this->successTrigger( $request, $message ) );
			( $this->emitHeader )( 'HX-Refresh', 'true' );
			( $this->terminate )();
		}

		( $this->emitStatus )( $this->status( $kind ) );
		( $this->emitHeader )( 'HX-Replace-Url', wp_make_link_relative( $request->canonicalUrl ) );
		( $this->emitHeader )( 'HX-Trigger-After-Swap', $this->successTrigger( $request, $message ) );
		echo $fragment; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The add-on renderer owns escaping; the exact row wrapper is validated above.
		( $this->terminate )();
	}

	private function successTrigger( SignedAdminInteractionRequest $request, string $message ): string {
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

	public function preparePendingFeedback(): void {
		$outcome = $this->pendingOutcome();
		if ( null === $outcome ) {
			return;
		}

		$request = $outcome['request'];
		if ( $this->isHtmxTarget( $request ) ) {
			if ( $this->hasSuccessFeedback( $outcome['kind'] ) ) {
				$trigger = wp_json_encode(
					array(
						'ran-booster:admin-mutation-success' => array(
							'message'   => $outcome['message'],
							'operation' => $request->operation,
						),
					)
				);
				if ( is_string( $trigger ) ) {
					( $this->emitHeader )( 'HX-Trigger-After-Swap', $trigger );
				}
			}
			( $this->emitHeader )( 'HX-Replace-Url', wp_make_link_relative( $request->canonicalUrl ) );

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

	private function signedOutcomeUrl(
		SignedAdminInteractionRequest $request,
		string $kind,
		string $message
	): string {
		$args                      = array(
			self::QUERY_OPERATION    => $request->operation,
			self::QUERY_TARGET       => $request->targetKey,
			self::QUERY_OUTCOME      => $kind,
			self::QUERY_MESSAGE      => $message,
			self::QUERY_RETURN       => $request->canonicalUrl,
			self::QUERY_ERROR_REGION => $request->errorRegionId,
		);
		$args[ self::QUERY_NONCE ] = wp_create_nonce( $this->nonceAction( $args ) );

		return add_query_arg(
			array_map(
				static fn ( string $value ): string => rawurlencode( $value ),
				$args
			),
			$request->canonicalUrl
		);
	}

	/**
	 * @return array{request: SignedAdminInteractionRequest, kind: string, message: string}|null
	 */
	private function pendingOutcome(): ?array {
		$operation = $this->queryString( self::QUERY_OPERATION );
		$target    = $this->queryString( self::QUERY_TARGET );
		$kind      = $this->queryString( self::QUERY_OUTCOME );
		$message   = $this->queryString( self::QUERY_MESSAGE );
		$returnUrl = $this->queryString( self::QUERY_RETURN );
		$errorId   = $this->queryString( self::QUERY_ERROR_REGION );
		$nonce     = $this->queryString( self::QUERY_NONCE );
		if ( null === $operation
			|| null === $target
			|| null === $kind
			|| null === $message
			|| null === $returnUrl
			|| null === $errorId
			|| null === $nonce ) {
			return null;
		}

		try {
			$request = ( $this->resolvePendingRequest )( $operation, $target, $returnUrl, $errorId );
		} catch ( \Throwable ) {
			return null;
		}
		if ( null === $request || ! $this->currentRequestMatches( $request ) ) {
			return null;
		}

		$args = array(
			self::QUERY_OPERATION    => $operation,
			self::QUERY_TARGET       => $target,
			self::QUERY_OUTCOME      => $kind,
			self::QUERY_MESSAGE      => $message,
			self::QUERY_RETURN       => $returnUrl,
			self::QUERY_ERROR_REGION => $errorId,
		);
		if ( 1 !== wp_verify_nonce( $nonce, $this->nonceAction( $args ) ) ) {
			return null;
		}

		try {
			$this->assertOutcome( $kind, $message );
		} catch ( \Throwable ) {
			return null;
		}

		return array(
			'request' => $request,
			'kind'    => $kind,
			'message' => $message,
		);
	}

	private function assertOutcome( string $kind, string $message ): void {
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

	private function status( string $kind ): int {
		return match ( $kind ) {
			AdminInteractionOutcome::SUCCESS            => 200,
			AdminInteractionOutcome::ACCEPTED           => 202,
			AdminInteractionOutcome::VALIDATION_FAILURE => 422,
			AdminInteractionOutcome::UNEXPECTED_FAILURE => 500,
		};
	}

	private function hasSuccessFeedback( string $kind ): bool {
		return in_array( $kind, array( AdminInteractionOutcome::SUCCESS, AdminInteractionOutcome::ACCEPTED ), true );
	}

	private function assertRowFragment( SignedAdminInteractionRequest $request, string $fragment ): void {
		$elementId = preg_quote( $request->targetElementId(), '/' );
		$opening   = "/^\\s*<tr\\b(?=[^>]*\\bid=([\"'])" . $elementId . "\\1)[^>]*>/i";
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
	private function nonceAction( array $args ): string {
		$encoded = wp_json_encode( $args );

		return 'ran-booster-admin-interaction|' . hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	private function isHtmxTarget( SignedAdminInteractionRequest $request ): bool {
		$hxRequest = $_SERVER['HTTP_HX_REQUEST'] ?? null;
		$hxTarget  = $_SERVER['HTTP_HX_TARGET'] ?? null;

		return is_string( $hxRequest )
			&& 'true' === strtolower( trim( $hxRequest ) )
			&& is_string( $hxTarget )
			&& hash_equals( $request->targetElementId(), trim( $hxTarget ) );
	}

	private function currentRequestMatches( SignedAdminInteractionRequest $request ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The validated URL is projected into read-only route values.
		$url = parse_url( $request->canonicalUrl );
		if ( ! is_array( $url ) ) {
			return false;
		}
		parse_str( (string) ( $url['query'] ?? '' ), $expected );

		foreach ( $expected as $key => $expectedValue ) {
			if ( ! is_string( $key )
				|| ! is_string( $expectedValue )
				|| str_starts_with( $key, 'ran_booster_interaction_' )
				|| self::QUERY_NONCE === $key ) {
				return false;
			}
			// Read-only routing is compared with the signed canonical URL.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current = $_GET[ $key ] ?? null;
			if ( ! is_string( $current )
				|| ! hash_equals( $expectedValue, wp_unslash( $current ) ) ) {
				return false;
			}
		}

		return true;
	}

	private function queryString( string $key ): ?string {
		// Display-only values are used only after complete marker verification.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = $_GET[ $key ] ?? null;
		if ( ! is_string( $value ) || strlen( $value ) > 2048 ) {
			return null;
		}

		return trim( wp_unslash( $value ) );
	}
}
