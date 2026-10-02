<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;

/** Fixed ordinary-add-on surface for repository-webhook-management/3. */
interface WebhookAssistanceFacade {

	public function readiness( string $provider_code ): AssistanceReadiness;
	public function target( string $provider_code, string $repository_id ): ?AssistanceTarget;
	/** @return list<array{id:string,label:string,kind:string,destroy_on:?string}> */
	public function credential_choices( string $provider_code ): array;
	/** @return list<array{id:string,label:string,scope:string}> */
	public function webhook_profile_choices( string $provider_code, string $repository_id ): array;
	public function profile( string $provider_code, string $repository_id, string $profile_id ): ?WebhookProfileMetadata;
	public function assess_setup( AssistanceTarget $target, ?string $credential_profile_id, string $nonce ): RepositoryWebhookFitnessResult;

	public function assess_check( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult;

	public function assess_reconfigure( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult;

	public function assess_remove( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult;

	public function assess_test( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookFitnessResult;

	public function setup( AssistanceTarget $target, ?string $credential_profile_id, string $nonce, ?string $webhook_profile_id = null ): RepositoryWebhookOperationResult;

	public function check( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult;

	public function reconfigure( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult;

	public function remove( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult;

	public function test( AssistanceTarget $target, ?string $credential_profile_id, string $hook_id, string $profile_id, int $profile_revision, string $nonce ): RepositoryWebhookOperationResult;
}
