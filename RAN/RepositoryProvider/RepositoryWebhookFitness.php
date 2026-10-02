<?php
declare(strict_types=1);
namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;
/** Read-only fitness for repository-webhook-management/3. */
interface RepositoryWebhookFitness extends ProviderCapability {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 3;
	public function assess_setup( string $repositoryId, string $repository, ?string $credentialProfileId ): RepositoryWebhookFitnessResult;
	public function assess_check( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult;
	public function assess_reconfigure( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult;
	public function assess_remove( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult;
	public function assess_test( string $repositoryId, string $repository, ?string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult;
}
