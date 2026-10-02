<?php
declare(strict_types=1);
namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;
/** Fixed execution surface for repository-webhook-management/3. */
interface RepositoryWebhookManagement extends ProviderCapability {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 3;
	public function setup(
		string $repository_id,
		string $repository,
		string $callback_url,
		?string $credential_profile_id,
		#[\SensitiveParameter] string $signing_secret
	): RepositoryWebhookOperationResult;
	public function check(
		string $repository_id,
		string $repository,
		string $hook_id,
		string $callback_url,
		?string $credential_profile_id
	): RepositoryWebhookOperationResult;
	public function reconfigure(
		string $repository_id,
		string $repository,
		string $hook_id,
		string $callback_url,
		?string $credential_profile_id,
		#[\SensitiveParameter] string $signing_secret
	): RepositoryWebhookOperationResult;
	public function remove(
		string $repository_id,
		string $repository,
		string $hook_id,
		string $callback_url,
		?string $credential_profile_id
	): RepositoryWebhookOperationResult;
	public function test(
		string $repository_id,
		string $repository,
		string $hook_id,
		string $callback_url,
		?string $credential_profile_id
	): RepositoryWebhookOperationResult;
}
