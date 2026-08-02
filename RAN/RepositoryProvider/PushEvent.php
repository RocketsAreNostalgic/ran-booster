<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class PushEvent {

	public function __construct(
		public ProviderCode $provider,
		public string $repository,
		public string $providerRepositoryId,
		public string $branch,
		public string $commit,
		public string $deliveryId
	) {
		$this->requireValue( $repository, 'Repository' );
		$this->requireValue( $providerRepositoryId, 'Provider repository ID' );
		$this->requireValue( $branch, 'Branch' );
		$this->requireValue( $commit, 'Commit' );
		$this->requireValue( $deliveryId, 'Delivery ID' );
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
	public function toArray(): array {
		return array(
			'provider'               => $this->provider->value,
			'repository'             => $this->repository,
			'provider_repository_id' => $this->providerRepositoryId,
			'branch'                 => $this->branch,
			'commit'                 => $this->commit,
			'delivery_id'            => $this->deliveryId,
		);
	}

	private function requireValue( string $value, string $label ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Required push event data cannot be empty.' );
		}
	}
}
