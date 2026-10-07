<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\Support;

use RAN\BoosterGitHubProvider\V1\CredentialPolicy as GitHubCredentialPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryProvider;

final class ExpiryReminderProvider implements RepositoryProvider, ProviderCredentialPolicySupplier {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'gh' ),
			'GitHub',
			'https://github.com/',
			'Owner'
		);
	}

	public function get_credential_policy(): ProviderCredentialPolicy {
		return new GitHubCredentialPolicy();
	}
}
