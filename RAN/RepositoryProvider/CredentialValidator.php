<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface CredentialValidator extends ProviderCapability {

	public function validate_credential( string $credential_id ): CredentialValidationResult;
}
