<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

use InvalidArgumentException;

/**
 * Core implementation of the bounded add-on interaction contract.
 */
final class CoreAdminInteractionFacade implements
	AdminInteractionFacade,
	CoreProviderProfileInteraction,
	TransporterRowAdminInteractionFacade {

	private const PROVIDER_PROFILE_ACTIONS = array(
		'save-access-profile'    => array(
			'view'  => 'credentials',
			'error' => 'ran-booster-access-profile-error',
		),
		'delete-access-profile'  => array(
			'view'  => 'credentials',
			'error' => 'ran-booster-delete-access-profile-error',
		),
		'save-webhook-profile'   => array(
			'view'  => 'secrets',
			'error' => 'ran-booster-webhook-profile-error',
		),
		'delete-webhook-profile' => array(
			'view'  => 'secrets',
			'error' => 'ran-booster-delete-webhook-profile-error',
		),
	);

	private SignedAdminInteractionFlow $flow;

	public function __construct(
		?callable $emitHeader = null,
		?callable $emitStatus = null,
		?callable $redirect = null,
		?callable $terminate = null
	) {
		$this->flow = new SignedAdminInteractionFlow(
			$this->resolvePendingRequest( ... ),
			$emitHeader,
			$emitStatus,
			$redirect,
			$terminate
		);
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'preparePendingFeedback' ) );
	}

	public function renderFormAttributes( AdminInteractionRequest $request ): void {
		$this->assertCanonicalUrl( $request );
		$signedRequest = $this->signedRequest( $request );
		$values        = wp_json_encode(
			array(
				'ran_booster_interaction[operation]' => $signedRequest->operation,
				'ran_booster_interaction[target]'    => $signedRequest->targetKey,
			)
		);
		if ( ! is_string( $values ) ) {
			throw new InvalidArgumentException( 'Administration interaction request values could not be encoded.' );
		}

		$attributes = array(
			'data-ran-booster-enhanced-mutation'     => '',
			'data-ran-booster-error-target'          => '#' . $signedRequest->errorRegionId,
			'data-ran-booster-interaction-operation' => $signedRequest->operation,
			'hx-post'                                => admin_url( 'admin-post.php' ),
			'hx-target'                              => $signedRequest->targetSelector,
			'hx-select'                              => $signedRequest->targetSelector,
			'hx-swap'                                => 'outerHTML transition:true show:none',
			'hx-sync'                                => 'this:drop',
			'hx-vals'                                => $values,
		);

		foreach ( $attributes as $name => $value ) {
			echo ' ' . esc_attr( $name );
			if ( '' !== $value ) {
				echo '="' . esc_attr( $value ) . '"';
			}
		}
	}

	public function isEnhancedRequest( AdminInteractionRequest $request ): bool {
		$this->assertCanonicalUrl( $request );

		return $this->flow->isEnhancedRequest( $this->signedRequest( $request ) );
	}

	public function respond( AdminInteractionOutcome $outcome ): never {
		$request = $outcome->request();
		$this->assertCanonicalUrl( $request );
		$this->flow->respond(
			$this->signedRequest( $request ),
			$outcome->kind(),
			$outcome->message()
		);
	}

	public function respondWithTransporterRowFragment(
		AdminInteractionOutcome $outcome,
		callable $renderFragment
	): never {
		$request = $outcome->request();
		if ( AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE !== $request->target() ) {
			throw new InvalidArgumentException( 'Direct row fragments are limited to Transporter migration source rows.' );
		}
		$this->assertCanonicalUrl( $request );
		$this->flow->respondWithFragment(
			$this->signedRequest( $request ),
			$outcome->kind(),
			$outcome->message(),
			$renderFragment
		);
	}

	public function preparePendingFeedback(): void {
		$this->flow->preparePendingFeedback();
	}

	public function providerProfileRequest(
		string $action,
		string $provider
	): SignedAdminInteractionRequest {
		$contract = self::PROVIDER_PROFILE_ACTIONS[ $action ] ?? null;
		if ( ! is_array( $contract ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $provider ) ) {
			throw new InvalidArgumentException( 'Provider profile interaction route is invalid.' );
		}

		$args = array(
			'page' => 'ran-booster',
			'tab'  => $provider,
		);
		$view = $this->queryValue( 'view' );
		$view = in_array( $view, array( 'credentials', 'secrets' ), true ) ? $view : 'overview';
		if ( 'overview' === $view ) {
			$panel = $this->queryValue( 'panel' );
			if ( in_array( $panel, array( 'setup', 'repositories' ), true ) ) {
				$args['panel'] = $panel;
				if ( 'repositories' === $panel ) {
					$repository = $this->queryText( 'repository', 191 );
					if ( '' !== $repository ) {
						$args['repository'] = $repository;
					}
				}
			}
		} else {
			if ( $contract['view'] !== $view ) {
				throw new InvalidArgumentException( 'Provider profile interaction route does not match the operation.' );
			}
			$args['view'] = $view;
			$args         = array_merge( $args, $this->providerListQuery( $view ) );
		}

		return new SignedAdminInteractionRequest(
			'core:' . $action,
			CoreProviderProfileInteraction::TARGET_KEY,
			CoreProviderProfileInteraction::TARGET_SELECTOR,
			add_query_arg( $args, admin_url( 'admin.php' ) ),
			$contract['error']
		);
	}

	public function respondToProviderProfileSuccess( SignedAdminInteractionRequest $request, string $message ): never {
		$this->flow->respond(
			$request,
			AdminInteractionOutcome::SUCCESS,
			$message
		);
	}

	public function respondToProviderProfileValidationFailure( SignedAdminInteractionRequest $request, string $message ): never {
		$this->flow->respond(
			$request,
			AdminInteractionOutcome::VALIDATION_FAILURE,
			$message
		);
	}

	public function respondToProviderProfileUnexpectedFailure( SignedAdminInteractionRequest $request ): never {
		$this->flow->respond(
			$request,
			AdminInteractionOutcome::UNEXPECTED_FAILURE,
			'We could not complete that request. Please try again.'
		);
	}

	private function signedRequest( AdminInteractionRequest $request ): SignedAdminInteractionRequest {
		return new SignedAdminInteractionRequest(
			$request->operation(),
			$request->targetKey(),
			$request->targetSelector(),
			$request->canonicalUrl(),
			$request->errorRegionId()
		);
	}

	private function resolvePendingRequest(
		string $operation,
		string $target,
		string $returnUrl,
		string $errorId
	): ?SignedAdminInteractionRequest {
		if ( AdminInteractionTarget::PROVIDER_REPOSITORIES->value === $target ) {
			$request = AdminInteractionRequest::providerRepositories( $operation, $returnUrl, $errorId );
			$this->assertCanonicalUrl( $request );

			return $this->signedRequest( $request );
		}

		$migrationTargetPattern = '/^'
			. preg_quote( AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE->value, '/' )
			. '_([a-f0-9]{32})$/';
		if ( 1 === preg_match( $migrationTargetPattern, $target, $matches ) ) {
			$this->assertCanonicalUrlForTarget(
				$returnUrl,
				AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE
			);

			return new SignedAdminInteractionRequest(
				$operation,
				$target,
				AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE->selector( $matches[1] ),
				$returnUrl,
				$errorId
			);
		}

		if ( CoreProviderProfileInteraction::TARGET_KEY === $target ) {
			if ( ! str_starts_with( $operation, 'core:' ) ) {
				return null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The reconstructed canonical route is compared exactly below.
			$url = parse_url( $returnUrl );
			if ( ! is_array( $url ) ) {
				return null;
			}
			parse_str( (string) ( $url['query'] ?? '' ), $query );
			$request = $this->providerProfileRequest(
				substr( $operation, strlen( 'core:' ) ),
				is_string( $query['tab'] ?? null ) ? $query['tab'] : ''
			);

			return hash_equals( $returnUrl, $request->canonicalUrl )
				&& hash_equals( $errorId, $request->errorRegionId )
					? $request
					: null;
		}

		return null;
	}

	private function assertCanonicalUrl( AdminInteractionRequest $request ): void {
		$this->assertCanonicalUrlForTarget( $request->canonicalUrl(), $request->target() );
	}

	private function assertCanonicalUrlForTarget(
		string $canonicalUrl,
		AdminInteractionTarget $target
	): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Validation happens before any redirect or response header.
		$url = parse_url( $canonicalUrl );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The trusted admin origin is compared component by component.
		$admin = parse_url( admin_url( 'admin.php' ) );
		if ( ! is_array( $url )
			|| ! is_array( $admin )
			|| isset( $url['user'], $url['pass'] )
			|| strtolower( (string) ( $url['scheme'] ?? '' ) ) !== strtolower( (string) ( $admin['scheme'] ?? '' ) )
			|| strtolower( (string) ( $url['host'] ?? '' ) ) !== strtolower( (string) ( $admin['host'] ?? '' ) )
			|| (int) ( $url['port'] ?? 0 ) !== (int) ( $admin['port'] ?? 0 )
			|| (string) ( $url['path'] ?? '' ) !== (string) ( $admin['path'] ?? '' ) ) {
			throw new InvalidArgumentException( 'Administration interaction return URL must use the canonical WordPress administration route.' );
		}

		parse_str( (string) ( $url['query'] ?? '' ), $query );
		if ( AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE === $target ) {
			if ( array( 'page', 'tab' ) !== array_keys( $query )
				|| 'ran-booster' !== ( $query['page'] ?? null )
				|| 'portability' !== ( $query['tab'] ?? null ) ) {
				throw new InvalidArgumentException( 'Administration interaction return URL must identify the canonical Transporter tab.' );
			}

			return;
		}

		$tab = $query['tab'] ?? null;
		if ( 'ran-booster' !== ( $query['page'] ?? null )
			|| 'repositories' !== ( $query['panel'] ?? null )
			|| ! is_string( $tab )
			|| 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $tab ) ) {
			throw new InvalidArgumentException( 'Administration interaction return URL must identify the Core provider repositories panel.' );
		}
	}

	private function queryValue( string $key ): string {
		// Read-only provider presentation state does not authorize mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = $_GET[ $key ] ?? null;

		return is_string( $value ) ? sanitize_key( wp_unslash( $value ) ) : '';
	}

	private function queryText( string $key, int $maximumLength ): string {
		// Read-only provider presentation state does not authorize mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = $_GET[ $key ] ?? null;
		$value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';

		return '' !== $value
			&& strlen( $value ) <= $maximumLength
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value )
				? $value
				: '';
	}

	/** @return array<string, int|string> */
	private function providerListQuery( string $view ): array {
		// Read-only provider list state does not authorize mutation.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['s'] ) && is_string( $_GET['s'] )
			? substr( trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ), 0, 100 )
			: '';
		$orderby = $this->queryValue( 'orderby' );
		$order   = $this->queryValue( 'order' );
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$perPage = isset( $_GET['per_page'] ) && 50 === absint( $_GET['per_page'] ) ? 50 : 20;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$args = array(
			's'        => $search,
			'status'   => $this->queryValue( 'status' ),
			'orderby'  => in_array( $orderby, array( 'name', 'kind', 'scope', 'usage', 'health' ), true ) ? $orderby : 'name',
			'order'    => 'desc' === $order ? 'desc' : 'asc',
			'paged'    => $paged,
			'per_page' => $perPage,
		);
		$args[ 'credentials' === $view ? 'kind' : 'scope' ] = $this->queryValue(
			'credentials' === $view ? 'kind' : 'scope'
		);

		return array_filter(
			$args,
			static fn ( int|string $value ): bool => '' !== $value && 1 !== $value && 20 !== $value
		);
	}
}
