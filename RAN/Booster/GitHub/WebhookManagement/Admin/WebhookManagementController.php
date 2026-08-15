<?php

declare( strict_types = 1 );

namespace RAN\Booster\GitHub\WebhookManagement\Admin;

use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\AdminInteractionOutcome;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Booster\GitHub\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Booster\GitHub\WebhookManagement\Operation\WebhookOperationCoordinator;

/** Owns the registered request boundary and response transport. */
final class WebhookManagementController {
	public const ADMIN_POST_ACTION = 'ran_booster_github_webhook_management_operation';

	private const NONCE_ACTION_PREFIX = 'ran_booster_repository_webhook_';

	private const PROVIDER_CODE = 'gh';

	/** @var \Closure(): bool */
	private \Closure $canManage;

	/** @var \Closure(string, string): bool */
	private \Closure $verifyNonce;

	private ?AdminInteractionFacade $adminInteraction = null;

	public function __construct(
		private readonly WebhookOperationCoordinator $operations,
		private readonly WebhookDisplayModel $display,
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
	public function handleAdminPost( array $request, string $nonce ): string {
		$operation         = $this->stringValue( $request, 'github_webhook_management_operation' );
		$providerCode      = $this->stringValue( $request, 'provider_code' );
		$repositoryId      = $this->stringValue( $request, 'repository_id' );
		$credentialId      = $this->stringValue( $request, 'booster_credential_id' );
		$requestCredential = $this->stringValue( $request, 'github_pat' );
		$result            = array(
			'code'     => 'invalid_request',
			'recovery' => null,
		);

		if ( ! ( $this->canManage )() ) {
			$result['code'] = 'forbidden';
		} elseif ( in_array( $operation, array( 'setup', 'check', 'reconfigure', 'remove' ), true )
			&& hash_equals( self::PROVIDER_CODE, $providerCode )
			&& ( $this->verifyNonce )( $nonce, $this->nonceAction( $operation, $providerCode, $repositoryId ) ) ) {
			$savedCredential = '' === $credentialId ? null : $credentialId;
			$requestOnly     = '' === $requestCredential ? null : $requestCredential;
			unset( $request['github_pat'] );
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
			&& ( $this->display->isSuccessfulResult( $resultCode ) || $this->display->canRespondInlineToFailure( $resultCode ) ) ) {
			$request = $this->interactionRequest( self::PROVIDER_CODE, $safeRepositoryId );
			$outcome = $this->display->isSuccessfulResult( $resultCode )
				? AdminInteractionOutcome::success( $request, $this->display->notice( $resultCode, $result['recovery'] ) )
				: AdminInteractionOutcome::validationFailure( $request, $this->display->notice( $resultCode, $result['recovery'] ) );
			$this->adminInteraction->respond( $outcome );
		}

		$recoveryQuery = null === $result['recovery']
			? ''
			: '&recovery_hook=' . rawurlencode( $result['recovery']['hook_id'] ) . '&recovery_profile=' . rawurlencode( $result['recovery']['profile_id'] );

		return admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( self::PROVIDER_CODE ) )
			. '&panel=repositories'
			. ( '' === $safeRepositoryId ? '' : '&repository=' . rawurlencode( $safeRepositoryId ) )
			. '&webhook_management_result=' . rawurlencode( $resultCode )
			. $recoveryQuery
			. '#ran-booster-github-webhook-management-operation-heading';
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
			'github-webhook-management:manage-webhook',
			admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $providerCode ) ) . '&panel=repositories&repository=' . rawurlencode( $repositoryId ) . '#ran-booster-github-webhook-management-operation-heading',
			'github-webhook-management-error'
		);
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
