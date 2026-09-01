<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use RAN\RepositoryProvider\ProviderCredentialStore;

final class WorkflowCredentialStore implements ProviderCredentialStore {
	public int $profileReads = 0;

	/** @var array<string,array{id:string,label:string,kind:string,source:string,immutable:bool,configured:bool}>|null */
	public ?array $profiles = null;

	/** @var list<string|null> */
	public array $materialReads = array();

	/** @var array{secret:string}|null */
	public ?array $eligibleMaterial = array( 'secret' => 'test-token' );

	public function credentialProfiles(): array {
		++$this->profileReads;
		return $this->profiles ?? array(
			'eligible' => array(
				'id'         => 'eligible',
				'label'      => 'Repository access',
				'kind'       => 'classic',
				'source'     => 'file',
				'immutable'  => false,
				'configured' => true,
			),
			'constant' => array(
				'id'         => 'constant',
				'label'      => 'Constant',
				'kind'       => 'classic',
				'source'     => 'constant',
				'immutable'  => true,
				'configured' => true,
			),
		);
	}

	public function credentialMaterial( ?string $id = null ): ?array {
		$this->materialReads[] = $id;
		return 'eligible' === $id ? $this->eligibleMaterial : null;
	}

	public function hasWebhookProfile(): bool {
		return false;
	}
}
