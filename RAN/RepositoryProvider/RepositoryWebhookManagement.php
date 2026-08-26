<?php
declare(strict_types=1);
namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;
/** Fixed execution surface for repository-webhook-management/3. */
interface RepositoryWebhookManagement extends ProviderCapability {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 3;
	public function setup(
		string $repositoryId,
		string $repository,
		string $callbackUrl,
		?string $credentialProfileId,
		#[\SensitiveParameter] string $signingSecret
	): RepositoryWebhookOperationResult;
	public function check(
		string $repositoryId,
		string $repository,
		string $hookId,
		string $callbackUrl,
		?string $credentialProfileId
	): RepositoryWebhookOperationResult;
	public function reconfigure(
		string $repositoryId,
		string $repository,
		string $hookId,
		string $callbackUrl,
		?string $credentialProfileId,
		#[\SensitiveParameter] string $signingSecret
	): RepositoryWebhookOperationResult;
	public function remove(
		string $repositoryId,
		string $repository,
		string $hookId,
		string $callbackUrl,
		?string $credentialProfileId
	): RepositoryWebhookOperationResult;
	public function test(
		string $repositoryId,
		string $repository,
		string $hookId,
		string $callbackUrl,
		?string $credentialProfileId
	): RepositoryWebhookOperationResult;
}
