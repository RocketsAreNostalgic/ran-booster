<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class PushEvent {

	public function __construct(
		public ProviderCode $provider,
		public string $repository,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $providerRepositoryId,
		public string $branch,
		public string $commit,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $deliveryId
	) {
		$this->require_value( $repository, 'Repository' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		$this->require_value( $providerRepositoryId, 'Provider repository ID' );
		$this->require_value( $branch, 'Branch' );
		$this->require_value( $commit, 'Commit' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		$this->require_value( $deliveryId, 'Delivery ID' );
	}

	/**
	 * @return array{
	 *     provider: string,
	 *     repository: string,
	 *     provider_repository_id: string,
	 *     branch: string,
	 *     commit: string,
	 *     delivery_id: string
	 * }
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function toArray(): array {
		return array(
			'provider'               => $this->provider->value,
			'repository'             => $this->repository,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			'provider_repository_id' => $this->providerRepositoryId,
			'branch'                 => $this->branch,
			'commit'                 => $this->commit,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			'delivery_id'            => $this->deliveryId,
		);
	}

	private function require_value( string $value, string $label ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Required push event data cannot be empty.' );
		}
	}
}
