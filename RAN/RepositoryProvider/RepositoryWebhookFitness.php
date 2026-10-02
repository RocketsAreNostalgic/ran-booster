<?php
declare(strict_types=1);
namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;
/** Read-only fitness for repository-webhook-management/3. */
interface RepositoryWebhookFitness extends ProviderCapability {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 3;
	public function assess_setup( string $repository_id, string $repository, ?string $credential_profile_id ): RepositoryWebhookFitnessResult;
	public function assess_check( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult;
	public function assess_reconfigure( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult;
	public function assess_remove( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult;
	public function assess_test( string $repository_id, string $repository, ?string $credential_profile_id, string $hook_id ): RepositoryWebhookFitnessResult;
}
