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
	public int $provider_operation_calls = 0;

	public function __construct(
		private readonly string $code,
		private readonly string $label
	) {
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), $this->label, 'https://' . $this->code . '.example.test/', $this->label );
	}

	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics {
			public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );

				return array();
			}
		};
	}

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		unset( $request );
		++$this->provider_operation_calls;
		throw new RuntimeException( 'Repository resolution is outside this presentation test.' );
	}

	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );
		++$this->provider_operation_calls;
		throw new RuntimeException( 'Archive preparation is outside this presentation test.' );
	}
}

trait SuppliesWebhookFitness {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of assess_setup retains the production method contract; these inputs do not affect this controlled result.
	public function assess_setup( string $repository_id, string $repository, ?string $credential_profile_id ): RepositoryWebhookFitnessResult {
		return $this->unexpected_fitness_operation();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of assess_check retains the production method contract; these inputs do not affect this controlled result.
	public function assess_check( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->unexpected_fitness_operation();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of assess_reconfigure retains the production method contract; these inputs do not affect this controlled result.
	public function assess_reconfigure( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->unexpected_fitness_operation();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of assess_remove retains the production method contract; these inputs do not affect this controlled result.
	public function assess_remove( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->unexpected_fitness_operation();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of assess_test retains the production method contract; these inputs do not affect this controlled result.
	public function assess_test( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult {
		return $this->unexpected_fitness_operation();
	}

	private function unexpected_fitness_operation(): RepositoryWebhookFitnessResult {
		++$this->provider_operation_calls;
		throw new RuntimeException( 'Capability presence checks must not assess provider credentials.' );
	}
}

trait SuppliesWebhookManagement {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of setup retains the production method contract; these inputs do not affect this controlled result.
	public function setup( string $repository_id, string $repository, string $callback_url, ?string $credential_profile_id, string $signing_secret ): RepositoryWebhookOperationResult {
		return $this->unexpected_management_operation();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of check retains the production method contract; these inputs do not affect this controlled result.
	public function check( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		return $this->unexpected_management_operation();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of reconfigure retains the production method contract; these inputs do not affect this controlled result.
	public function reconfigure( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id, string $signing_secret ): RepositoryWebhookOperationResult {
		return $this->unexpected_management_operation();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of remove retains the production method contract; these inputs do not affect this controlled result.
	public function remove( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		return $this->unexpected_management_operation();
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of test retains the production method contract; these inputs do not affect this controlled result.
	public function test( string $repository_id, string $repository, string $hook_id, string $callback_url, ?string $credential_profile_id ): RepositoryWebhookOperationResult {
		return $this->unexpected_management_operation();
	}

	private function unexpected_management_operation(): RepositoryWebhookOperationResult {
		++$this->provider_operation_calls;
		throw new RuntimeException( 'Capability presence checks must not mutate a remote provider.' );
	}
}

final class CompleteWebhookManagementCapabilityProvider extends WebhookManagementCapabilityProvider implements RepositoryWebhookFitness, RepositoryWebhookManagement, RepositoryWebhookSettingsLink, WebhookNormalizer {
	use SuppliesWebhookFitness;
	use SuppliesWebhookManagement;

	public const OPERATION = RepositoryWebhookFitness::OPERATION;
	public const VERSION   = RepositoryWebhookFitness::VERSION;

	public function get_webhook_policy(): ProviderWebhookPolicy {
		return new InertWebhookPolicy( $this->get_metadata()->code );
	}

	public function diagnose_webhook_readiness(): ProviderDiagnosticResult {
		return new ProviderDiagnosticResult( ProviderDiagnosticResult::PASSED, 'fixture_webhook_ready', 'Fixture webhook policy is ready.', 'No fixture remediation is required.' );
	}

	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		unset( $request );

		return WebhookEnvelope::ignored();
	}

	public function repository_webhook_settings_url( string $locator ): string {
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
