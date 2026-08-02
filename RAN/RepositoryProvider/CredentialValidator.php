<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface CredentialValidator extends ProviderCapability {

	public function validateCredential( string $credentialId ): CredentialValidationResult;
}
