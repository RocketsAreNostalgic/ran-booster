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
	public const ADMIN_POST_ACTION = 'ran_booster_repository_webhook_management_operation';

	private const NONCE_ACTION_PREFIX = 'ran_booster_repository_webhook_';

	/** @var \Closure(): bool */
	private \Closure $canManage;

	/** @var \Closure(string, string): bool */
	private \Closure $verifyNonce;

	/** @var \Closure(string): string */
	private \Closure $createNonce;

	private ?AdminInteractionFacade $adminInteraction = null;

	public function __construct(
		private readonly WebhookOperationCoordinator $operations,
		private readonly WebhookDisplayModel $display,
		private readonly ProviderRegistry $providers,
		private readonly ManagedPackageWebhookAuthorityResolver $packageAuthorities,
		?callable $canManage = null,
		?callable $verifyNonce = null,
		?callable $createNonce = null
	) {
		$this->canManage   = null === $canManage
			? static fn (): bool => current_user_can( 'manage_options' )
			: \Closure::fromCallable( $canManage );
		$this->verifyNonce = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => 1 === wp_verify_nonce( $nonce, $action )
			: \Closure::fromCallable( $verifyNonce );
		$this->createNonce = null === $createNonce
			? static fn ( string $action ): string => wp_create_nonce( $action )
			: \Closure::fromCallable( $createNonce );
	}

	public function useAdminInteractionFacade( AdminInteractionFacade $adminInteraction ): void {
		$this->adminInteraction = $adminInteraction;
	}

	/**
	 * Handle one Core-owned admin-post request and return its safe redirect.
	 *
	 * @param array<string, mixed> $request
	 */
	public function handleAdminPost( #[\SensitiveParameter] array $request, string $nonce ): string {
		$operation    = $this->stringValue( $request, 'repository_webhook_management_operation' );
		$providerCode = $this->stringValue( $request, 'provider_code' );
		$repositoryId = $this->stringValue( $request, 'repository_id' );
		$credentialId = $this->stringValue( $request, 'booster_credential_id' );
		$metadata     = $this->providerMetadata( $providerCode );
		$result       = array(
			'code'        => 'invalid_request',
			'recovery'    => null,
			'remediation' => null,
			'successful'  => false,
			'inline_safe' => true,
		);

		if ( ! ( $this->canManage )() ) {
			$result['code'] = 'forbidden';
		} elseif ( $metadata instanceof ProviderMetadata
			&& in_array( $operation, array( 'setup', 'check', 'reconfigure', 'remove' ), true )
			&& ( $this->verifyNonce )( $nonce, $this->nonceAction( $operation, $providerCode, $repositoryId ) ) ) {
			$requestCredential = $this->stringValue( $request, 'request_credential' );
			$savedCredential   = '' === $credentialId ? null : $credentialId;
			$requestOnly       = '' === $requestCredential ? null : $requestCredential;
			unset( $request['request_credential'] );
			$requestCredential = '';
			try {
				$result = $this->operations->execute( $operation, $providerCode, $repositoryId, $savedCredential, $requestOnly, $nonce );
			} finally {
				$requestOnly = null;
			}
		}
		$resultCode = $result['code'];

		$safeRepositoryId   = strlen( $repositoryId ) <= 191 && 0 === preg_match( '/[\x00-\x1F\x7F]/', $repositoryId ) ? $repositoryId : '';
		$returnUrl          = $this->safeReturnUrl( $this->stringValue( $request, 'return_url' ), $providerCode, $safeRepositoryId );
		$interactionRequest = $this->interactionRequest( $returnUrl );
		if ( null !== $this->adminInteraction
			&& null !== $interactionRequest
			&& $metadata instanceof ProviderMetadata
			&& true === $result['inline_safe'] ) {
			$outcome = true === $result['successful']
				? AdminInteractionOutcome::success( $interactionRequest, $this->display->notice( $resultCode, $result['recovery'], $result['remediation'] ) )
				: AdminInteractionOutcome::validationFailure( $interactionRequest, $this->display->notice( $resultCode, $result['recovery'], $result['remediation'] ) );
			$this->adminInteraction->respond( $outcome );
		}

		$remediation = false === $result['inline_safe'] ? $this->safeRemediation( $result['remediation'] ) : null;
		$args        = array( 'webhook_management_result' => $resultCode );
		if ( null !== $result['recovery'] ) {
			$args['recovery_hook']    = $result['recovery']['hook_id'];
			$args['recovery_profile'] = $result['recovery']['profile_id'];
		}
		if ( null !== $remediation ) {
			$args['webhook_management_remediation']    = $remediation;
			$args['webhook_management_provider']       = $providerCode;
			$args['webhook_management_repository']     = $safeRepositoryId;
			$args['_ran_booster_webhook_result_nonce'] = ( $this->createNonce )( $this->resultNonceAction( $providerCode, $safeRepositoryId, $resultCode, $remediation ) );
		}

		return add_query_arg( $args, $returnUrl );
	}

	/** @return list<ProviderMetadata> */
	public function providerMetadataList(): array {
		$metadata = array();
		foreach ( $this->providers->orderedMetadata() as $candidate ) {
			$capable = $this->capableProviderMetadata( $candidate->code->value );
			if ( $capable instanceof ProviderMetadata ) {
				$metadata[] = $capable;
			}
		}

		return $metadata;
	}

	public function providerMetadata( string $providerCode ): ?ProviderMetadata {
		return $this->capableProviderMetadata( $providerCode );
	}

	/** @return array{result:?string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string} */
	public function panelContext(): array {
		$query         = is_array( $_GET ) ? wp_unslash( $_GET ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bounded display-only context.
		$code          = $this->stringValue( $query, 'webhook_management_result' );
		$safeReference = static fn ( mixed $value ): ?string => is_string( $value )
			&& 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/', $value )
			? $value
			: null;
		$hookId        = $safeReference( $query['recovery_hook'] ?? null );
		$profileId     = $safeReference( $query['recovery_profile'] ?? null );
		$providerCode  = $this->stringValue( $query, 'webhook_management_provider' );
		$repositoryId  = $this->stringValue( $query, 'webhook_management_repository' );
		if ( '' === $providerCode || '' === $repositoryId ) {
			$providerCode = $this->stringValue( $query, 'tab' );
			$repositoryId = $this->stringValue( $query, 'repository' );
		}
		$remediation = $this->safeRemediation( $query['webhook_management_remediation'] ?? null );
		$resultNonce = $this->stringValue( $query, '_ran_booster_webhook_result_nonce' );
		if ( null === $remediation
			|| '' === $code
			|| ! ( $this->verifyNonce )( $resultNonce, $this->resultNonceAction( $providerCode, $repositoryId, $code, $remediation ) ) ) {
			$remediation = null;
		}

		return array(
			'result'      => '' === $code ? null : $this->safeCode( $code, 'request_completed' ),
			'recovery'    => null !== $hookId && null !== $profileId ? array(
				'hook_id'    => $hookId,
				'profile_id' => $profileId,
			) : null,
			'remediation' => $remediation,
		);
	}

	private function resultNonceAction( string $providerCode, string $repositoryId, string $code, string $remediation ): string {
		return 'ran_booster_repository_webhook_result_' . hash( 'sha256', implode( "\0", array( $providerCode, $repositoryId, $code, $remediation ) ) );
	}

	private function safeRemediation( mixed $remediation ): ?string {
		return is_string( $remediation )
			&& '' !== trim( $remediation )
			&& strlen( $remediation ) <= 255
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $remediation )
			? $remediation
			: null;
	}

	private function interactionRequest( string $returnUrl ): ?AdminInteractionRequest {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The URL has already been reconstructed by safeReturnUrl().
		$query = parse_url( $returnUrl, PHP_URL_QUERY );
		parse_str( is_string( $query ) ? $query : '', $arguments );
		if ( 'ran-booster' !== ( $arguments['page'] ?? '' ) ) {
			return null;
		}

		return AdminInteractionRequest::providerRepositories(
			'repository-webhook-management:manage-webhook',
			$returnUrl,
			'repository-webhook-management-error'
		);
	}

	private function safeReturnUrl( string $candidate, string $providerCode, string $repositoryId ): string {
		$fallback = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $providerCode ) ) . '&panel=repositories'
			. ( '' === $repositoryId ? '' : '&repository=' . rawurlencode( $repositoryId ) );
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
		if ( 'ran-booster' === $page
			&& hash_equals( $providerCode, $tab )
			&& 'repositories' === $panel
			&& '' !== $repositoryId
			&& hash_equals( $repositoryId, $target ) ) {
			return admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $providerCode ) . '&panel=repositories&repository=' . rawurlencode( $repositoryId ) );
		}
		if ( ! in_array( $page, array( 'ran-booster-plugins', 'ran-booster-themes' ), true )
			|| '' === $package || strlen( $package ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $package )
			|| ! $this->packageReturnMatchesOperation( $page, $package, $providerCode, $repositoryId ) ) {
			return $fallback;
		}

		return ( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) ) . '?page=' . $page . '&package=' . rawurlencode( $package ) . '&source_view=branch&ran_booster_open_advanced=1';
	}

	/** Prove that a package-settings return URL belongs to this signed repository operation. */
	private function packageReturnMatchesOperation( string $page, string $package, string $providerCode, string $repositoryId ): bool {
		$type      = 'ran-booster-plugins' === $page ? 'plugin' : 'theme';
		$authority = $this->packageAuthorities->forPackage( $type, $package );

		return null !== $authority
			&& hash_equals( $providerCode, $authority['provider_code'] )
			&& hash_equals( $repositoryId, $authority['repository_id'] );
	}

	private function capableProviderMetadata( string $providerCode ): ?ProviderMetadata {
		try {
			$fitness    = $this->providers->requireCapability( $providerCode, RepositoryWebhookFitness::class );
			$management = $this->providers->requireCapability( $providerCode, RepositoryWebhookManagement::class );
			$normalizer = $this->providers->requireCapability( $providerCode, WebhookNormalizer::class );
			$metadata   = $this->providers->metadata()[ $providerCode ] ?? null;
		} catch ( \Throwable ) {
			return null;
		}

		return $fitness === $management && $management === $normalizer && $metadata instanceof ProviderMetadata
			&& hash_equals( $providerCode, $metadata->code->value )
			? $metadata
			: null;
	}

	private function nonceAction( string $operation, string $providerCode, string $repositoryId ): string {
		return self::NONCE_ACTION_PREFIX . implode( '_', array( $operation, $providerCode, $repositoryId ) );
	}

	private function safeCode( mixed $code, string $fallback ): string {
		return is_string( $code ) && 1 === preg_match( '/^[a-z0-9][a-z0-9._-]{0,95}$/', $code ) ? $code : $fallback;
	}

	/** @param array<string, mixed> $request */
	private function stringValue( array $request, string $key ): string {
		return is_string( $request[ $key ] ?? null ) ? trim( $request[ $key ] ) : '';
	}
}
