<?php

declare(strict_types=1);

namespace Tests\Admin\Support;

use RAN\RepositoryProvider\GitHubCredentialPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryProvider;

final class ExpiryReminderProvider implements RepositoryProvider, ProviderCredentialPolicySupplier {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://github.com/',
			'Owner'
		);
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		return new GitHubCredentialPolicy();
	}
}
