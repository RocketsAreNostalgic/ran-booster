<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class PushEvent {

	public function __construct(
		public ProviderCode $provider,
		public string $repository,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $provider_repository_id,
		public string $branch,
		public string $commit,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		public string $delivery_id
	) {
		$this->require_value( $repository );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		$this->require_value( $provider_repository_id );
		$this->require_value( $branch );
		$this->require_value( $commit );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		$this->require_value( $delivery_id );
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
	public function to_array(): array {
		return array(
			'provider'               => $this->provider->value,
			'repository'             => $this->repository,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			'provider_repository_id' => $this->provider_repository_id,
			'branch'                 => $this->branch,
			'commit'                 => $this->commit,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			'delivery_id'            => $this->delivery_id,
		);
	}

	private function require_value( string $value ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Required push event data cannot be empty.' );
		}
	}
}
