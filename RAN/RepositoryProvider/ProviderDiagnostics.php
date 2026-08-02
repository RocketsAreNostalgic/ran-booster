<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface ProviderDiagnostics {

	/**
	 * Run this provider's fixed, bounded checks.
	 *
	 * @return list<ProviderDiagnosticResult>
	 */
	public function diagnose( ProviderDiagnosticRequest $request ): array;
}
