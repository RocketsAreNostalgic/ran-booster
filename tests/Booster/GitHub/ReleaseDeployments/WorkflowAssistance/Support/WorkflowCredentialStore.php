<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use RAN\RepositoryProvider\ProviderCredentialStore;

final class WorkflowCredentialStore implements ProviderCredentialStore {
	public int $profileReads = 0;

	/** @var list<string|null> */
	public array $materialReads = array();

	public function credentialProfiles(): array {
		++$this->profileReads;
		return array(
			'eligible' => array( 'id' => 'eligible', 'label' => 'Repository access', 'kind' => 'classic', 'source' => 'file', 'immutable' => false, 'configured' => true ),
			'constant' => array( 'id' => 'constant', 'label' => 'Constant', 'kind' => 'classic', 'source' => 'constant', 'immutable' => true, 'configured' => true ),
		);
	}

	public function credentialMaterial( ?string $id = null ): ?array {
		$this->materialReads[] = $id;
		return 'eligible' === $id ? array( 'secret' => 'test-token' ) : null;
	}

	public function hasWebhookProfile(): bool {
		return false;
	}
}
