<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;

/** Fixed ordinary-add-on surface for repository-webhook-management/1. */
interface WebhookAssistanceFacade {

	public function readiness( string $providerCode ): AssistanceReadiness;
	public function target( string $providerCode, string $repositoryId ): ?AssistanceTarget;
	/** @return list<array{id:string,label:string,kind:string,destroy_on:?string}> */
	public function credentialChoices( string $providerCode ): array;
	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata;
	public function assessSetup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult;

	public function assessCheck( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult;

	public function assessReconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult;

	public function assessRemove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookFitnessResult;

	public function setup( AssistanceTarget $target, ?string $credentialProfileId, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult;

	public function check( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult;

	public function reconfigure( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult;

	public function remove( AssistanceTarget $target, ?string $credentialProfileId, string $hookId, string $profileId, int $profileRevision, string $nonce, #[\SensitiveParameter] ?string $requestCredential = null ): RepositoryWebhookOperationResult;
}
