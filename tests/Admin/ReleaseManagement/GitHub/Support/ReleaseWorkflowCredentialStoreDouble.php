<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\GitHub\Support;

use RAN\RepositoryProvider\ProviderCredentialStore;

final class ReleaseWorkflowCredentialStoreDouble implements ProviderCredentialStore {
	public int $profileReads  = 0;
	public int $materialReads = 0;

	/** @param array<string,array<string,mixed>>|null $profiles */
	public function __construct( private readonly ?array $profiles = null ) {
	}

	public function credentialProfiles(): array {
		++$this->profileReads;

		return $this->profiles ?? array(
			'credential_1' => array(
				'id'         => 'credential_1',
				'label'      => 'Release automation',
				'kind'       => 'fine-grained',
				'source'     => 'file',
				'configured' => true,
				'immutable'  => false,
			),
		);
	}

	public function credentialMaterial( ?string $id = null ): ?array {
		++$this->materialReads;

		return 'credential_1' === $id ? array( 'secret' => 'saved-secret' ) : null;
	}

	public function hasWebhookProfile(): bool {
		return false;
	}
}
