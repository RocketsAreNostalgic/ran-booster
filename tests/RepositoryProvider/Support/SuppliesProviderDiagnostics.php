<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnostics;

trait SuppliesProviderDiagnostics {
	use SuppliesProviderManualCapabilities;

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The fixture implementation of diagnose retains the production method contract; these inputs do not affect this controlled result.
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				return array();
			}
		};
	}
}
