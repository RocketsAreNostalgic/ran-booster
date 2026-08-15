<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

final readonly class ProviderAdminMetadata {

	/**
	 * @var list<CredentialKindMetadata>
	 */
	public array $credentialKinds;

	/**
	 * @var list<WebhookScopeMetadata>
	 */
	public array $webhookScopes;

	/**
	 * @param list<CredentialKindMetadata> $credentialKinds
	 * @param list<WebhookScopeMetadata>    $webhookScopes
	 */
	public function __construct(
		array $credentialKinds,
		array $webhookScopes,
		public ?ProviderSetupMetadata $setup = null,
		public ?ProviderNavigationPlacement $navigation = null,
		public string $repositoryLocatorHint = ''
	) {
		$this->credentialKinds = $this->validateCredentialKinds( $credentialKinds );
		$this->webhookScopes   = $this->validateWebhookScopes( $webhookScopes );
	}

	public function getCredentialKind( string $code ): ?CredentialKindMetadata {
		foreach ( $this->credentialKinds as $kind ) {
			if ( $kind->code === $code ) {
				return $kind;
			}
		}

		return null;
	}

	public function getWebhookScope( string $code ): ?WebhookScopeMetadata {
		foreach ( $this->webhookScopes as $scope ) {
			if ( $scope->code === $code ) {
				return $scope;
			}
		}

		return null;
	}

	/**
	 * @param list<CredentialKindMetadata> $kinds
	 *
	 * @return list<CredentialKindMetadata>
	 */
	private function validateCredentialKinds( array $kinds ): array {
		$indexed = array();

		foreach ( $kinds as $kind ) {
			if ( ! $kind instanceof CredentialKindMetadata ) {
				throw new InvalidArgumentException( 'Credential kinds must be credential kind metadata.' );
			}

			if ( isset( $indexed[ $kind->code ] ) ) {
				throw new InvalidArgumentException( 'Credential kind codes must be unique within a provider.' );
			}

			$indexed[ $kind->code ] = $kind;
		}

		return array_values( $indexed );
	}

	/**
	 * @param list<WebhookScopeMetadata> $scopes
	 *
	 * @return list<WebhookScopeMetadata>
	 */
	private function validateWebhookScopes( array $scopes ): array {
		$indexed = array();

		foreach ( $scopes as $scope ) {
			if ( ! $scope instanceof WebhookScopeMetadata ) {
				throw new InvalidArgumentException( 'Webhook scopes must be webhook scope metadata.' );
			}

			if ( isset( $indexed[ $scope->code ] ) ) {
				throw new InvalidArgumentException( 'Webhook scope codes must be unique within a provider.' );
			}

			$indexed[ $scope->code ] = $scope;
		}

		return array_values( $indexed );
	}
}
