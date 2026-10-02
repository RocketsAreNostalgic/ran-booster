<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnostics;

trait SuppliesProviderDiagnostics {
	use SuppliesProviderManualCapabilities;

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				return array();
			}
		};
	}
}
