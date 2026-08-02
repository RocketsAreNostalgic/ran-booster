<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use RAN\RepositoryProvider\ProviderCode;

/** Immutable, non-secret description of one assisted webhook target. */
final readonly class AssistanceTarget {

	private string $providerCode;

	/**
	 * @param list<string> $packageReferences
	 * @param array{automatic: int, manual: int, disabled: int} $deploymentPolicies
	 */
	public function __construct(
		string $providerCode,
		private string $repositoryId,
		private string $repository,
		private string $label,
		private array $packageReferences,
		private array $deploymentPolicies,
		private string $endpoint
	) {
		$this->providerCode = ProviderCode::parse( $providerCode )->value;
	}

	public function providerCode(): string {
		return $this->providerCode;
	}

	public function repositoryId(): string {
		return $this->repositoryId;
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
	public function toArray(): array {
		return array(
			'provider_code'       => $this->providerCode,
			'repository_id'       => $this->repositoryId,
			'repository'          => $this->repository,
			'label'               => $this->label,
			'package_references'  => $this->packageReferences,
			'deployment_policies' => $this->deploymentPolicies,
			'endpoint'            => $this->endpoint,
			'eligible'            => true,
		);
	}
}
