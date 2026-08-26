<?php

declare(strict_types=1);

namespace Tests\Support;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Closely related capability fixtures share one focused support file.

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RuntimeException;
use Tests\RepositoryProvider\Support\InertWebhookPolicy;

abstract class WebhookManagementCapabilityProvider implements RepositoryProvider {
	public int $providerOperationCalls = 0;

	public function __construct(
		private readonly string $code,
		private readonly string $label
	) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), $this->label, 'https://' . $this->code . '.example.test/', $this->label );
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );

				return array();
			}
		};
	}

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		unset( $request );
		++$this->providerOperationCalls;
		throw new RuntimeException( 'Repository resolution is outside this presentation test.' );
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );
		++$this->providerOperationCalls;
		throw new RuntimeException( 'Archive preparation is outside this presentation test.' );
	}
}

trait SuppliesWebhookFitness {
	public function assessSetup( string $repositoryId, string $repository, ?string $credentialProfileId ): RepositoryWebhookFitnessResult {
		return $this->unexpectedFitnessOperation();
	}

	public function assessCheck( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->unexpectedFitnessOperation();
	}

	public function assessReconfigure( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->unexpectedFitnessOperation();
	}

	public function assessRemove( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->unexpectedFitnessOperation();
	}

	public function assessTest( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult {
		return $this->unexpectedFitnessOperation();
	}

	private function unexpectedFitnessOperation(): RepositoryWebhookFitnessResult {
		++$this->providerOperationCalls;
		throw new RuntimeException( 'Capability presence checks must not assess provider credentials.' );
	}
}

trait SuppliesWebhookManagement {
	public function setup( string $repositoryId, string $repository, string $callbackUrl, ?string $credentialProfileId, string $signingSecret ): RepositoryWebhookOperationResult {
		return $this->unexpectedManagementOperation();
	}

	public function check( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		return $this->unexpectedManagementOperation();
	}

	public function reconfigure( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, string $signingSecret ): RepositoryWebhookOperationResult {
		return $this->unexpectedManagementOperation();
	}

	public function remove( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		return $this->unexpectedManagementOperation();
	}

	public function test( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId ): RepositoryWebhookOperationResult {
		return $this->unexpectedManagementOperation();
	}

	private function unexpectedManagementOperation(): RepositoryWebhookOperationResult {
		++$this->providerOperationCalls;
		throw new RuntimeException( 'Capability presence checks must not mutate a remote provider.' );
	}
}

final class CompleteWebhookManagementCapabilityProvider extends WebhookManagementCapabilityProvider implements RepositoryWebhookFitness, RepositoryWebhookManagement, RepositoryWebhookSettingsLink, WebhookNormalizer {
	use SuppliesWebhookFitness;
	use SuppliesWebhookManagement;

	public const OPERATION = RepositoryWebhookFitness::OPERATION;
	public const VERSION   = RepositoryWebhookFitness::VERSION;

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		return new InertWebhookPolicy( $this->getMetadata()->code );
	}

	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult( ProviderDiagnosticResult::PASSED, 'fixture_webhook_ready', 'Fixture webhook policy is ready.', 'No fixture remediation is required.' );
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		unset( $request );

		return WebhookEnvelope::ignored();
	}

	public function repositoryWebhookSettingsUrl( string $locator ): string {
		return 'https://fixture-provider.example.test/' . rawurlencode( $locator ) . '/settings/hooks';
	}
}

final class UnnormalizedWebhookManagementCapabilityProvider extends WebhookManagementCapabilityProvider implements RepositoryWebhookFitness, RepositoryWebhookManagement {
	use SuppliesWebhookFitness;
	use SuppliesWebhookManagement;

	public const OPERATION = RepositoryWebhookFitness::OPERATION;
	public const VERSION   = RepositoryWebhookFitness::VERSION;
}

final class FitnessOnlyWebhookManagementCapabilityProvider extends WebhookManagementCapabilityProvider implements RepositoryWebhookFitness {
	use SuppliesWebhookFitness;
}

final class ManagementOnlyWebhookManagementCapabilityProvider extends WebhookManagementCapabilityProvider implements RepositoryWebhookManagement {
	use SuppliesWebhookManagement;
}

final class AbsentWebhookManagementCapabilityProvider extends WebhookManagementCapabilityProvider {
}
