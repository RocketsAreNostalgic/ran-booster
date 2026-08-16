<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\Provider\ProviderCapability;

interface ProviderOwnedCapability extends ProviderCapability {

	public function providerOwnedValue(): string;
}
