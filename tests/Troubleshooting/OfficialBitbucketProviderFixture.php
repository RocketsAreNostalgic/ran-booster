<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;
use RAN\Tests\Troubleshooting\SupportReportProvider;

/** Isolated identity fixture; does not load or execute the installed plugin. */
final readonly class BitbucketProvider implements RepositoryProvider {
	use SuppliesProviderManualCapabilities;

	public function get_metadata(): ProviderMetadata {
		return ( new SupportReportProvider( 'bb', 'Official Bitbucket' ) )->get_metadata();
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return ( new SupportReportProvider() )->get_provider_diagnostics();
	}
}
