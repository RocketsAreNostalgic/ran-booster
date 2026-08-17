<?php

declare(strict_types=1);

namespace Tests\Support;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Closely related capability fixtures share one focused support file.

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RuntimeException;

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
	public function assessSetup( string $repositoryId, string $repository, ?string $credentialProfileId, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		return $this->unexpectedFitnessOperation();
	}

	public function assessCheck( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		return $this->unexpectedFitnessOperation();
	}

	public function assessReconfigure( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		return $this->unexpectedFitnessOperation();
	}

	public function assessRemove( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId, ?string $requestCredential = null ): RepositoryWebhookFitnessResult {
		return $this->unexpectedFitnessOperation();
	}

	private function unexpectedFitnessOperation(): RepositoryWebhookFitnessResult {
		++$this->providerOperationCalls;
		throw new RuntimeException( 'Capability presence checks must not assess provider credentials.' );
	}
}

trait SuppliesWebhookManagement {
	public function setup( string $repositoryId, string $repository, string $callbackUrl, ?string $credentialProfileId, ?string $requestCredential, string $signingSecret ): RepositoryWebhookOperationResult {
		return $this->unexpectedManagementOperation();
	}

	public function check( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, ?string $requestCredential ): RepositoryWebhookOperationResult {
		return $this->unexpectedManagementOperation();
	}

	public function reconfigure( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, ?string $requestCredential, string $signingSecret ): RepositoryWebhookOperationResult {
		return $this->unexpectedManagementOperation();
	}

	public function remove( string $repositoryId, string $repository, string $hookId, string $callbackUrl, ?string $credentialProfileId, ?string $requestCredential ): RepositoryWebhookOperationResult {
		return $this->unexpectedManagementOperation();
	}

	private function unexpectedManagementOperation(): RepositoryWebhookOperationResult {
		++$this->providerOperationCalls;
		throw new RuntimeException( 'Capability presence checks must not mutate a remote provider.' );
	}
}

final class CompleteWebhookManagementCapabilityProvider extends WebhookManagementCapabilityProvider implements RepositoryWebhookFitness, RepositoryWebhookManagement {
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
