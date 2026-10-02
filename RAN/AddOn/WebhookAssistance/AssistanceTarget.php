<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use RAN\RepositoryProvider\ProviderCode;

/** Immutable, non-secret description of one assisted webhook target. */
final readonly class AssistanceTarget {

	private string $provider_code;

	/**
	 * @param list<string> $package_references
	 * @param array{automatic: int, manual: int, disabled: int} $deployment_policies
	 */
	public function __construct(
		string $provider_code,
		private string $repository_id,
		private string $repository,
		private string $label,
		private array $package_references,
		private array $deployment_policies,
		private string $endpoint
	) {
		$this->provider_code = ProviderCode::parse( $provider_code )->value;
	}

	public function provider_code(): string {
		return $this->provider_code;
	}

	public function repository_id(): string {
		return $this->repository_id;
	}

	public function repository(): string {
		return $this->repository;
	}

	public function endpoint(): string {
		return $this->endpoint;
	}

	/**
	 * @return array{provider_code: string, repository_id: string, repository: string, label: string, package_references: list<string>, deployment_policies: array{automatic: int, manual: int, disabled: int}, endpoint: string, eligible: true}
	 */
	public function to_array(): array {
		return array(
			'provider_code'       => $this->provider_code,
			'repository_id'       => $this->repository_id,
			'repository'          => $this->repository,
			'label'               => $this->label,
			'package_references'  => $this->package_references,
			'deployment_policies' => $this->deployment_policies,
			'endpoint'            => $this->endpoint,
			'eligible'            => true,
		);
	}
}
