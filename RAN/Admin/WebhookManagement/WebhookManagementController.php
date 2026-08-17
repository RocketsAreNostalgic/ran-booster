<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement;

use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\AdminInteractionOutcome;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Admin\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Admin\WebhookManagement\Operation\WebhookOperationCoordinator;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;

/** @internal Owns the registered request boundary and response transport. */
final class WebhookManagementController {
	public const ADMIN_POST_ACTION = 'ran_booster_repository_webhook_management_operation';

	private const NONCE_ACTION_PREFIX = 'ran_booster_repository_webhook_';

	/** @var \Closure(): bool */
	private \Closure $canManage;

	/** @var \Closure(string, string): bool */
	private \Closure $verifyNonce;

	private ?AdminInteractionFacade $adminInteraction = null;

	public function __construct(
		private readonly WebhookOperationCoordinator $operations,
		private readonly WebhookDisplayModel $display,
		private readonly ProviderRegistry $providers,
		?callable $canManage = null,
		?callable $verifyNonce = null
	) {
		$this->canManage   = null === $canManage
			? static fn (): bool => current_user_can( 'manage_options' )
			: \Closure::fromCallable( $canManage );
		$this->verifyNonce = null === $verifyNonce
			? static fn ( string $nonce, string $action ): bool => 1 === wp_verify_nonce( $nonce, $action )
			: \Closure::fromCallable( $verifyNonce );
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

		$safeRepositoryId = strlen( $repositoryId ) <= 191 && 0 === preg_match( '/[\x00-\x1F\x7F]/', $repositoryId ) ? $repositoryId : '';
		if ( null !== $this->adminInteraction
			&& $metadata instanceof ProviderMetadata
			&& true === $result['inline_safe'] ) {
			$request = $this->interactionRequest( $providerCode, $safeRepositoryId );
			$outcome = true === $result['successful']
				? AdminInteractionOutcome::success( $request, $this->display->notice( $resultCode, $result['recovery'], $result['remediation'] ) )
				: AdminInteractionOutcome::validationFailure( $request, $this->display->notice( $resultCode, $result['recovery'], $result['remediation'] ) );
			$this->adminInteraction->respond( $outcome );
		}

		$recoveryQuery = null === $result['recovery']
			? ''
			: '&recovery_hook=' . rawurlencode( $result['recovery']['hook_id'] ) . '&recovery_profile=' . rawurlencode( $result['recovery']['profile_id'] );

		$redirect = admin_url( 'admin.php?page=ran-booster' );
		if ( $metadata instanceof ProviderMetadata ) {
			$redirect .= '&tab=' . rawurlencode( $metadata->code->value );
		}

		return $redirect
			. '&panel=repositories'
			. ( '' === $safeRepositoryId ? '' : '&repository=' . rawurlencode( $safeRepositoryId ) )
			. '&webhook_management_result=' . rawurlencode( $resultCode )
			. $recoveryQuery
			. '#ran-booster-repository-webhook-management-operation-heading';
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

	/** @return array{result:?string,recovery:array{hook_id:string,profile_id:string}|null} */
	public function panelContext(): array {
		$query         = is_array( $_GET ) ? wp_unslash( $_GET ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bounded display-only context.
		$code          = $this->stringValue( $query, 'webhook_management_result' );
		$safeReference = static fn ( mixed $value ): ?string => is_string( $value )
			&& 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/', $value )
			? $value
			: null;
		$hookId        = $safeReference( $query['recovery_hook'] ?? null );
		$profileId     = $safeReference( $query['recovery_profile'] ?? null );

		return array(
			'result'   => '' === $code ? null : $this->safeCode( $code, 'request_completed' ),
			'recovery' => null !== $hookId && null !== $profileId ? array(
				'hook_id'    => $hookId,
				'profile_id' => $profileId,
			) : null,
		);
	}

	private function interactionRequest( string $providerCode, string $repositoryId ): AdminInteractionRequest {
		return AdminInteractionRequest::providerRepositories(
			'repository-webhook-management:manage-webhook',
			admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $providerCode ) ) . '&panel=repositories&repository=' . rawurlencode( $repositoryId ) . '#ran-booster-repository-webhook-management-operation-heading',
			'repository-webhook-management-error'
		);
	}

	private function capableProviderMetadata( string $providerCode ): ?ProviderMetadata {
		try {
			$fitness    = $this->providers->requireCapability( $providerCode, RepositoryWebhookFitness::class );
			$management = $this->providers->requireCapability( $providerCode, RepositoryWebhookManagement::class );
			$metadata   = $this->providers->metadata()[ $providerCode ] ?? null;
		} catch ( \Throwable ) {
			return null;
		}

		return $fitness === $management && $metadata instanceof ProviderMetadata
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
