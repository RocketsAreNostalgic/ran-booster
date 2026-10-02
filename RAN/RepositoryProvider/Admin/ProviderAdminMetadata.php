<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

final readonly class ProviderAdminMetadata {

	/**
	 * @var list<CredentialKindMetadata>
	 */
	public array $credential_kinds;

	/**
	 * @var list<WebhookScopeMetadata>
	 */
	public array $webhook_scopes;

	/**
	 * @param list<CredentialKindMetadata> $credential_kinds
	 * @param list<WebhookScopeMetadata>    $webhook_scopes
	 */
	public function __construct(
		array $credential_kinds,
		array $webhook_scopes,
		public ?ProviderSetupMetadata $setup = null,
		public ?ProviderNavigationPlacement $navigation = null,
		public string $repository_locator_hint = ''
	) {
		$this->credential_kinds = $this->validate_credential_kinds( $credential_kinds );
		$this->webhook_scopes   = $this->validate_webhook_scopes( $webhook_scopes );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function get_credential_kind( string $code ): ?CredentialKindMetadata {
		foreach ( $this->credential_kinds as $kind ) {
			if ( $kind->code === $code ) {
				return $kind;
			}
		}

		return null;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function get_webhook_scope( string $code ): ?WebhookScopeMetadata {
		foreach ( $this->webhook_scopes as $scope ) {
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
