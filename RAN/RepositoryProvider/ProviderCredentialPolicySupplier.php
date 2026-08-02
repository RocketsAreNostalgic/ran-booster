<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

interface ProviderCredentialPolicySupplier extends ProviderCapability {

	public function getCredentialPolicy(): ProviderCredentialPolicy;
}
