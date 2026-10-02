<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class PushEvent {

	public function __construct(
		public ProviderCode $provider,
		public string $repository,
		public string $provider_repository_id,
		public string $branch,
		public string $commit,
		public string $delivery_id
	) {
		$this->require_value( $repository );
		$this->require_value( $provider_repository_id );
		$this->require_value( $branch );
		$this->require_value( $commit );
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

	public function to_array(): array {
		return array(
			'provider'               => $this->provider->value,
			'repository'             => $this->repository,
			'provider_repository_id' => $this->provider_repository_id,
			'branch'                 => $this->branch,
			'commit'                 => $this->commit,
			'delivery_id'            => $this->delivery_id,
		);
	}

	private function require_value( string $value ): void {
		if ( '' === trim( $value ) ) {
			throw new InvalidArgumentException( 'Required push event data cannot be empty.' );
		}
	}
}
