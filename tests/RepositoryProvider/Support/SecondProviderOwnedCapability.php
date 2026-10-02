<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\Provider\ProviderCapability;

interface SecondProviderOwnedCapability extends ProviderCapability {

	public function second_provider_owned_value(): string;
}
