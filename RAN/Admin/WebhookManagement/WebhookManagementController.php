<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement;

use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\AdminInteractionOutcome;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Admin\WebhookManagement\Operation\WebhookOperationCoordinator;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\WebhookNormalizer;

/** @internal Owns the registered request boundary and response transport. */
final class WebhookManagementController {
	public const ADMIN_POST_ACTION        = 'ran_booster_repository_webhook_management_operation';
	public const CREATE_REPOSITORY_SECRET = 'create_repository_secret';

	private const NONCE_ACTION_PREFIX = 'ran_booster_repository_webhook_';

	/** @var \Closure(): bool */
	private \Closure $can_manage;

	/** @var \Closure(string, string): bool */
	private \Closure $verify_nonce;

	/** @var \Closure(string): string */
	private \Closure $create_nonce;

	private ?AdminInteractionFacade $admin_interaction = null;

	public function __construct(
		private readonly WebhookOperationCoordinator $operations,
		private readonly WebhookDisplayModel $display,
		private readonly ProviderRegistry $providers,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		private readonly ManagedPackageWebhookAuthorityResolver $packageAuthorities,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $canManage = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $verifyNonce = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $createNonce = null
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->can_manage = null === $canManage
			? static fn (): bool => current_user_can( 'manage_options' )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $canManage );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->verify_nonce = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => 1 === wp_verify_nonce( $nonce, $action )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $verifyNonce );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->create_nonce = null === $createNonce
			? static fn ( string $action ): string => wp_create_nonce( $action )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $createNonce );
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function use_admin_interaction_facade( AdminInteractionFacade $adminInteraction ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->admin_interaction = $adminInteraction;
	}

	/**
	 * Handle one Core-owned admin-post request and return its safe redirect.
	 *
	 * @param array<string, mixed> $request
	 */
	public function handle_admin_post( #[\SensitiveParameter] array $request, string $nonce ): string {
		$operation     = $this->string_value( $request, 'repository_webhook_management_operation' );
		$provider_code = $this->string_value( $request, 'provider_code' );
		$repository_id = $this->string_value( $request, 'repository_id' );
		$credential_id = $this->string_value( $request, 'booster_credential_id' );
		$profile_id    = $this->string_value( $request, 'webhook_profile_id' );
		$metadata      = $this->provider_metadata( $provider_code );
		$result        = array(
			'code'        => 'invalid_request',
			'recovery'    => null,
			'remediation' => null,
			'successful'  => false,
			'inline_safe' => true,
		);

		if ( ! ( $this->can_manage )() ) {
			$result['code'] = 'forbidden';
		} elseif ( 'setup' === $operation && '' === $profile_id ) {
			$result['code'] = 'invalid_request';
		} elseif ( $metadata instanceof ProviderMetadata
			&& in_array( $operation, array( 'setup', 'check', 'reconfigure', 'remove', 'test' ), true )
			&& ( $this->verify_nonce )( $nonce, $this->nonce_action( $operation, $provider_code, $repository_id ) ) ) {
			$result = $this->operations->execute( $operation, $provider_code, $repository_id, '' === $credential_id ? null : $credential_id, 'setup' === $operation ? ( self::CREATE_REPOSITORY_SECRET === $profile_id ? null : $profile_id ) : null, $nonce );
		}
		$result_code = $result['code'];

		$safe_repository_id  = strlen( $repository_id ) <= 191 && 0 === preg_match( '/[\x00-\x1F\x7F]/', $repository_id ) ? $repository_id : '';
		$return_url          = $this->safe_return_url( $this->string_value( $request, 'return_url' ), $provider_code, $safe_repository_id );
		$interaction_request = $this->interaction_request( $return_url );
		if ( null !== $this->admin_interaction
			&& null !== $interaction_request
			&& $metadata instanceof ProviderMetadata
			&& true === $result['inline_safe'] ) {
			$outcome = true === $result['successful']
				? AdminInteractionOutcome::success( $interaction_request, $this->display->notice( $result_code, $result['recovery'], $result['remediation'] ) )
				: AdminInteractionOutcome::validationFailure( $interaction_request, $this->display->notice( $result_code, $result['recovery'], $result['remediation'] ) );
			$this->admin_interaction->respond( $outcome );
		}

		$remediation = false === $result['inline_safe'] ? $this->safe_remediation( $result['remediation'] ) : null;
		$args        = array( 'webhook_management_result' => $result_code );
		if ( null !== $result['recovery'] ) {
			$args['recovery_hook']    = $result['recovery']['hook_id'];
			$args['recovery_profile'] = $result['recovery']['profile_id'];
		}
		if ( null !== $remediation ) {
			$args['webhook_management_remediation']    = $remediation;
			$args['webhook_management_provider']       = $provider_code;
			$args['webhook_management_repository']     = $safe_repository_id;
			$args['_ran_booster_webhook_result_nonce'] = ( $this->create_nonce )( $this->result_nonce_action( $provider_code, $safe_repository_id, $result_code, $remediation ) );
		}

		return add_query_arg( $args, $return_url );
	}

	/** @return list<ProviderMetadata> */
	public function provider_metadata_list(): array {
		$metadata = array();
		foreach ( $this->providers->orderedMetadata() as $candidate ) {
			$capable = $this->capable_provider_metadata( $candidate->code->value );
			if ( $capable instanceof ProviderMetadata ) {
				$metadata[] = $capable;
			}
		}

		return $metadata;
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
	public function provider_metadata( string $providerCode ): ?ProviderMetadata {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		return $this->capable_provider_metadata( $providerCode );
	}

	/** @return array{result:?string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string} */
	public function panel_context(): array {
		$query          = is_array( $_GET ) ? wp_unslash( $_GET ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bounded display-only context.
		$code           = $this->string_value( $query, 'webhook_management_result' );
		$safe_reference = static fn ( mixed $value ): ?string => is_string( $value )
			&& 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/', $value )
			? $value
			: null;
		$hook_id        = $safe_reference( $query['recovery_hook'] ?? null );
		$profile_id     = $safe_reference( $query['recovery_profile'] ?? null );
		$provider_code  = $this->string_value( $query, 'webhook_management_provider' );
		$repository_id  = $this->string_value( $query, 'webhook_management_repository' );
		if ( '' === $provider_code || '' === $repository_id ) {
			$provider_code = $this->string_value( $query, 'tab' );
			$repository_id = $this->string_value( $query, 'repository' );
		}
		$remediation  = $this->safe_remediation( $query['webhook_management_remediation'] ?? null );
		$result_nonce = $this->string_value( $query, '_ran_booster_webhook_result_nonce' );
		if ( null === $remediation
			|| '' === $code
			|| ! ( $this->verify_nonce )( $result_nonce, $this->result_nonce_action( $provider_code, $repository_id, $code, $remediation ) ) ) {
			$remediation = null;
		}

		return array(
			'result'      => '' === $code ? null : $this->safe_code( $code, 'request_completed' ),
			'recovery'    => null !== $hook_id && null !== $profile_id ? array(
				'hook_id'    => $hook_id,
				'profile_id' => $profile_id,
			) : null,
			'remediation' => $remediation,
		);
	}

	private function result_nonce_action( string $provider_code, string $repository_id, string $code, string $remediation ): string {
		return 'ran_booster_repository_webhook_result_' . hash( 'sha256', implode( "\0", array( $provider_code, $repository_id, $code, $remediation ) ) );
	}

	private function safe_remediation( mixed $remediation ): ?string {
		return is_string( $remediation )
			&& '' !== trim( $remediation )
			&& strlen( $remediation ) <= 255
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $remediation )
			? $remediation
			: null;
	}

	private function interaction_request( string $return_url ): ?AdminInteractionRequest {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The URL has already been reconstructed by safeReturnUrl().
		$query = parse_url( $return_url, PHP_URL_QUERY );
		parse_str( is_string( $query ) ? $query : '', $arguments );
		if ( 'ran-booster' !== ( $arguments['page'] ?? '' ) ) {
			return null;
		}

		return AdminInteractionRequest::providerRepositories(
			'repository-webhook-management:manage-webhook',
			$return_url,
			'repository-webhook-management-error'
		);
	}

	private function safe_return_url( string $candidate, string $provider_code, string $repository_id ): string {
		$fallback = WebhookManagementAdminUrl::for_path( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider_code ) ) . '&panel=repositories'
			. ( '' === $repository_id ? '' : '&repository=' . rawurlencode( $repository_id ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Reconstructs an allowlisted same-admin route; the candidate is never returned directly.
		$parts = parse_url( $candidate );
		if ( ! is_array( $parts ) ) {
			return $fallback;
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$page    = is_string( $query['page'] ?? null ) ? $query['page'] : '';
		$package = is_string( $query['package'] ?? null ) ? $query['package'] : '';
		$tab     = is_string( $query['tab'] ?? null ) ? $query['tab'] : '';
		$panel   = is_string( $query['panel'] ?? null ) ? $query['panel'] : '';
		$target  = is_string( $query['repository'] ?? null ) ? $query['repository'] : '';
		$view    = is_string( $query['repository_view'] ?? null ) ? $query['repository_view'] : '';
		if ( 'ran-booster' === $page
			&& hash_equals( $provider_code, $tab )
			&& 'repositories' === $panel
			&& '' !== $repository_id
			&& hash_equals( $repository_id, $target ) ) {
			return WebhookManagementAdminUrl::for_path( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider_code ) . '&panel=repositories&repository=' . rawurlencode( $repository_id ) . ( in_array( $view, array( 'status', 'branch', 'releases' ), true ) ? '&repository_view=' . rawurlencode( $view ) : '' ) );
		}
		if ( ! in_array( $page, array( 'ran-booster-plugins', 'ran-booster-themes' ), true )
			|| '' === $package || strlen( $package ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $package )
			|| ! $this->package_return_matches_operation( $page, $package, $provider_code, $repository_id ) ) {
			return $fallback;
		}

		return WebhookManagementAdminUrl::for_path( 'admin.php' ) . '?page=' . $page . '&package=' . rawurlencode( $package ) . '&source_view=branch&ran_booster_open_advanced=1';
	}

	/** Prove that a package-settings return URL belongs to this signed repository operation. */
	private function package_return_matches_operation( string $page, string $package, string $provider_code, string $repository_id ): bool {
		$type = 'ran-booster-plugins' === $page ? 'plugin' : 'theme';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		$authority = $this->packageAuthorities->forPackage( $type, $package );

		return null !== $authority
			&& hash_equals( $provider_code, $authority['provider_code'] )
			&& hash_equals( $repository_id, $authority['repository_id'] );
	}

	private function capable_provider_metadata( string $provider_code ): ?ProviderMetadata {
		try {
			$fitness    = $this->providers->requireCapability( $provider_code, RepositoryWebhookFitness::class );
			$management = $this->providers->requireCapability( $provider_code, RepositoryWebhookManagement::class );
			$normalizer = $this->providers->requireCapability( $provider_code, WebhookNormalizer::class );
			$metadata   = $this->providers->metadata()[ $provider_code ] ?? null;
		} catch ( \Throwable ) {
			return null;
		}

		return $fitness === $management && $management === $normalizer && $metadata instanceof ProviderMetadata
			&& hash_equals( $provider_code, $metadata->code->value )
			? $metadata
			: null;
	}

	private function nonce_action( string $operation, string $provider_code, string $repository_id ): string {
		return self::NONCE_ACTION_PREFIX . implode( '_', array( $operation, $provider_code, $repository_id ) );
	}

	private function safe_code( mixed $code, string $fallback ): string {
		return is_string( $code ) && 1 === preg_match( '/^[a-z0-9][a-z0-9._-]{0,95}$/', $code ) ? $code : $fallback;
	}

	/** @param array<string, mixed> $request */
	private function string_value( array $request, string $key ): string {
		return is_string( $request[ $key ] ?? null ) ? trim( $request[ $key ] ) : '';
	}
}
