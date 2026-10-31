<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

/** Metadata is captured during registration; passive facts may not rerun callbacks. */
final class SupportReportProvider implements RepositoryProvider {
	use SuppliesProviderManualCapabilities;

	public bool $registered = false;

	public function __construct( private readonly string $code = 'privateprovider', private readonly string $name = 'Private Component Canary' ) {}

	public function get_metadata(): ProviderMetadata {
		if ( $this->registered ) {
			throw new \RuntimeException( 'A passive report must use captured metadata.' );
		}
		return new ProviderMetadata( ProviderCode::parse( $this->code ), $this->name, 'https://private-provider-canary.test/', 'Private owner canary' );
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The active diagnostic contract is retained solely to fail if reporting calls it.
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				throw new \RuntimeException( 'A report must never request active diagnostic work.' );
			}
		};
	}
}
