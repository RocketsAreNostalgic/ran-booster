<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

use InvalidArgumentException;
use RAN\Admin\ProviderProfileAdminController;

/**
 * Core implementation of the bounded add-on interaction contract.
 */
final class CoreAdminInteractionFacade implements
	AdminInteractionFacade,
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
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		?callable $emitHeader = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		?callable $emitStatus = null,
		?callable $redirect = null,
		?callable $terminate = null
	) {
		$this->flow = new SignedAdminInteractionFlow(
			$this->resolve_pending_request( ... ),
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			$emitHeader,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			$emitStatus,
			$redirect,
			$terminate
		);
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'preparePendingFeedback' ) );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function renderFormAttributes( AdminInteractionRequest $request ): void {
		$this->assert_canonical_url( $request );
		$signed_request = $this->signed_request( $request );
		$values         = wp_json_encode(
			array(
				'ran_booster_interaction[operation]' => $signed_request->operation,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The signed-request DTO retains its separately owned public property contract.
				'ran_booster_interaction[target]'    => $signed_request->targetKey,
			)
		);
		if ( ! is_string( $values ) ) {
			throw new InvalidArgumentException( 'Administration interaction request values could not be encoded.' );
		}

		$attributes = array(
			'data-ran-booster-enhanced-mutation'     => '',
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The signed-request DTO retains its separately owned public property contract.
			'data-ran-booster-error-target'          => '#' . $signed_request->errorRegionId,
			'data-ran-booster-interaction-operation' => $signed_request->operation,
			'hx-post'                                => wp_make_link_relative( admin_url( 'admin-post.php' ) ),
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The signed-request DTO retains its separately owned public property contract.
			'hx-target'                              => $signed_request->targetSelector,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The signed-request DTO retains its separately owned public property contract.
			'hx-select'                              => $signed_request->targetSelector,
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function isEnhancedRequest( AdminInteractionRequest $request ): bool {
		$this->assert_canonical_url( $request );

		return $this->flow->isEnhancedRequest( $this->signed_request( $request ) );
	}

	public function respond( AdminInteractionOutcome $outcome ): never {
		$request = $outcome->request();
		$this->assert_canonical_url( $request );
		$this->flow->respond(
			$this->signed_request( $request ),
			$outcome->kind(),
			$outcome->message()
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function respondWithTransporterRowFragment(
		AdminInteractionOutcome $outcome,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		callable $renderFragment
	): never {
		$request = $outcome->request();
		if ( AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE !== $request->target() ) {
			throw new InvalidArgumentException( 'Direct row fragments are limited to Transporter migration source rows.' );
		}
		$this->assert_canonical_url( $request );
		$this->flow->respondWithFragment(
			$this->signed_request( $request ),
			$outcome->kind(),
			$outcome->message(),
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
			$renderFragment
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function preparePendingFeedback(): void {
		$this->flow->preparePendingFeedback();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
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
		$view = $this->query_value( 'view' );
		$view = in_array( $view, array( 'credentials', 'secrets' ), true ) ? $view : 'overview';
		if ( 'overview' === $view ) {
			$panel = $this->query_value( 'panel' );
			if ( in_array( $panel, array( 'setup', 'repositories' ), true ) ) {
				$args['panel'] = $panel;
				if ( 'repositories' === $panel ) {
					$repository = $this->query_text( 'repository', 191 );
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
			$args         = array_merge( $args, $this->provider_list_query( $view ) );
		}

		return new SignedAdminInteractionRequest(
			'core:' . $action,
			ProviderProfileAdminController::TARGET_KEY,
			ProviderProfileAdminController::TARGET_SELECTOR,
			add_query_arg( $args, admin_url( 'admin.php' ) ),
			$contract['error']
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function respondToProviderProfileSuccess( SignedAdminInteractionRequest $request, string $message ): never {
		$this->flow->respond(
			$request,
			AdminInteractionOutcome::SUCCESS,
			$message,
			in_array(
				$request->operation,
				array( 'core:save-access-profile', 'core:save-webhook-profile' ),
				true
			)
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function respondToProviderProfileValidationFailure( SignedAdminInteractionRequest $request, string $message ): never {
		$this->flow->respond(
			$request,
			AdminInteractionOutcome::VALIDATION_FAILURE,
			$message
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public interaction methods retain the existing connected caller and callback contracts.
	public function respondToProviderProfileUnexpectedFailure( SignedAdminInteractionRequest $request ): never {
		$this->flow->respond(
			$request,
			AdminInteractionOutcome::UNEXPECTED_FAILURE,
			'We could not complete that request. Please try again.'
		);
	}

	private function signed_request( AdminInteractionRequest $request ): SignedAdminInteractionRequest {
		return new SignedAdminInteractionRequest(
			$request->operation(),
			$request->targetKey(),
			$request->targetSelector(),
			$request->canonicalUrl(),
			$request->errorRegionId()
		);
	}

	private function resolve_pending_request(
		string $operation,
		string $target,
		string $return_url,
		string $error_id
	): ?SignedAdminInteractionRequest {
		if ( AdminInteractionTarget::PROVIDER_REPOSITORIES->value === $target ) {
			$request = AdminInteractionRequest::providerRepositories( $operation, $return_url, $error_id );
			$this->assert_canonical_url( $request );

			return $this->signed_request( $request );
		}

		$migration_target_pattern = '/^'
			. preg_quote( AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE->value, '/' )
			. '_([a-f0-9]{32})$/';
		if ( 1 === preg_match( $migration_target_pattern, $target, $matches ) ) {
			$this->assert_canonical_url_for_target(
				$return_url,
				AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE
			);

			return new SignedAdminInteractionRequest(
				$operation,
				$target,
				AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE->selector( $matches[1] ),
				$return_url,
				$error_id
			);
		}

		if ( ProviderProfileAdminController::TARGET_KEY === $target ) {
			if ( ! str_starts_with( $operation, 'core:' ) ) {
				return null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The reconstructed canonical route is compared exactly below.
			$url = parse_url( $return_url );
			if ( ! is_array( $url ) ) {
				return null;
			}
			parse_str( (string) ( $url['query'] ?? '' ), $query );
			$request = $this->providerProfileRequest(
				substr( $operation, strlen( 'core:' ) ),
				is_string( $query['tab'] ?? null ) ? $query['tab'] : ''
			);

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The signed-request DTO retains its separately owned public property contract.
			return hash_equals( $return_url, $request->canonicalUrl )
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The signed-request DTO retains its separately owned public property contract.
				&& hash_equals( $error_id, $request->errorRegionId )
					? $request
					: null;
		}

		return null;
	}

	private function assert_canonical_url( AdminInteractionRequest $request ): void {
		$this->assert_canonical_url_for_target( $request->canonicalUrl(), $request->target() );
	}

	private function assert_canonical_url_for_target(
		string $canonical_url,
		AdminInteractionTarget $target
	): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Validation happens before any redirect or response header.
		$url = parse_url( $canonical_url );
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

	private function query_value( string $key ): string {
		// Read-only provider presentation state does not authorize mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = $_GET[ $key ] ?? null;

		return is_string( $value ) ? sanitize_key( wp_unslash( $value ) ) : '';
	}

	private function query_text( string $key, int $maximum_length ): string {
		// Read-only provider presentation state does not authorize mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$value = $_GET[ $key ] ?? null;
		$value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';

		return '' !== $value
			&& strlen( $value ) <= $maximum_length
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $value )
				? $value
				: '';
	}

	/** @return array<string, int|string> */
	private function provider_list_query( string $view ): array {
		// Read-only provider list state does not authorize mutation.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search   = isset( $_GET['s'] ) && is_string( $_GET['s'] )
			? substr( trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ), 0, 100 )
			: '';
		$orderby  = $this->query_value( 'orderby' );
		$order    = $this->query_value( 'order' );
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = isset( $_GET['per_page'] ) && 50 === absint( $_GET['per_page'] ) ? 50 : 20;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$args = array(
			's'        => $search,
			'status'   => $this->query_value( 'status' ),
			'orderby'  => in_array( $orderby, array( 'name', 'kind', 'scope', 'usage', 'health' ), true ) ? $orderby : 'name',
			'order'    => 'desc' === $order ? 'desc' : 'asc',
			'paged'    => $paged,
			'per_page' => $per_page,
		);
		$args[ 'credentials' === $view ? 'kind' : 'scope' ] = $this->query_value(
			'credentials' === $view ? 'kind' : 'scope'
		);

		return array_filter(
			$args,
			static fn ( int|string $value ): bool => '' !== $value && 1 !== $value && 20 !== $value
		);
	}
}
