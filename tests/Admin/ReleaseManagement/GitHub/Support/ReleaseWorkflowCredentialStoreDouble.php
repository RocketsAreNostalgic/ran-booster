<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\GitHub\Support;

use RAN\RepositoryProvider\ProviderCredentialStore;

final class ReleaseWorkflowCredentialStoreDouble implements ProviderCredentialStore {
	public int $profile_reads  = 0;
	public int $material_reads = 0;

	/** @param array<string,array<string,mixed>>|null $profiles */
	public function __construct( private readonly ?array $profiles = null ) {
	}

	public function credential_profiles(): array {
		++$this->profile_reads;

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

	public function credential_material( ?string $id = null ): ?array {
		++$this->material_reads;

		return 'credential_1' === $id ? array( 'secret' => 'saved-secret' ) : null;
	}

	public function has_webhook_profile(): bool {
		return false;
	}
}
