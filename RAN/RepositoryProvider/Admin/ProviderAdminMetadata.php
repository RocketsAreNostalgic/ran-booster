<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

final readonly class ProviderAdminMetadata {

	/**
	 * @var list<CredentialKindMetadata>
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Retain the public DTO or promoted constructor contract.
	public array $credentialKinds;

	/**
	 * @var list<WebhookScopeMetadata>
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Retain the public DTO or promoted constructor contract.
	public array $webhookScopes;

	/**
	 * @param list<CredentialKindMetadata> $credentialKinds
	 * @param list<WebhookScopeMetadata>    $webhookScopes
	 */
	public function __construct(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		array $credentialKinds,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		array $webhookScopes,
		public ?ProviderSetupMetadata $setup = null,
		public ?ProviderNavigationPlacement $navigation = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		public string $repositoryLocatorHint = ''
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the promoted constructor or external DTO property contract. Retain the public named-parameter contract.
		$this->credentialKinds = $this->validate_credential_kinds( $credentialKinds );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the promoted constructor or external DTO property contract. Retain the public named-parameter contract.
		$this->webhookScopes = $this->validate_webhook_scopes( $webhookScopes );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function getCredentialKind( string $code ): ?CredentialKindMetadata {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
		foreach ( $this->credentialKinds as $kind ) {
			if ( $kind->code === $code ) {
				return $kind;
			}
		}

		return null;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function getWebhookScope( string $code ): ?WebhookScopeMetadata {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the promoted constructor or external DTO property contract.
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
	private function validate_credential_kinds( array $kinds ): array {
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
	private function validate_webhook_scopes( array $scopes ): array {
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
