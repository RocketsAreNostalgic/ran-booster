<?php

declare(strict_types=1);

namespace RAN\Booster\Bitbucket;

use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;
use RAN\Tests\Troubleshooting\SupportReportProvider;

/** Different admitted implementation claiming the same provider code. */
final readonly class CustomBitbucketProvider implements RepositoryProvider, \RAN\RepositoryProvider\CredentialValidator {
	use SuppliesProviderManualCapabilities;

	public function get_metadata(): ProviderMetadata {
		return ( new SupportReportProvider( 'bb', 'Custom provider' ) )->get_metadata();
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return ( new SupportReportProvider() )->get_provider_diagnostics();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- Observation must not validate credentials.
	public function validate_credential( string $credential_id ): \RAN\RepositoryProvider\CredentialValidationResult {
		throw new \RuntimeException( 'Credential validation must not run.' );
	}
}
