<?php
declare(strict_types=1);
namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;
/** Read-only fitness for repository-webhook-management/1. */
interface RepositoryWebhookFitness extends ProviderCapability {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 1;
	public function assessSetup( string $repositoryId, string $repository, string $credentialProfileId ): RepositoryWebhookFitnessResult;
	public function assessCheck( string $repositoryId, string $repository, string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult;
	public function assessReconfigure( string $repositoryId, string $repository, string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult;
	public function assessRemove( string $repositoryId, string $repository, string $credentialProfileId, string $hookId ): RepositoryWebhookFitnessResult;
}
