<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

interface WebhookAssistanceFacade {

	public function readiness( string $providerCode ): AssistanceReadiness;

	public function target( string $providerCode, string $repositoryId ): ?AssistanceTarget;

	/**
	 * Return display-safe file-backed credentials available to this add-on.
	 *
	 * @return list<array{id:string,label:string,kind:string,destroy_on:?string}>
	 */
	public function credentialChoices( string $providerCode ): array;

	/**
	 * Resolve one currently eligible saved credential only for the supplied
	 * request callback. The secret must not be retained after the callback.
	 *
	 * @template TResult
	 * @param callable(#[\SensitiveParameter] string): TResult $operation
	 * @return TResult|null
	 */
	public function withCredential( string $providerCode, string $credentialId, callable $operation ): mixed;

	/**
	 * @param callable(string, #[\SensitiveParameter] string, int): ProvisioningCallbackResult $createRemoteHook
	 */
	public function provision( AssistanceTarget $target, callable $createRemoteHook ): ProvisioningResult;

	public function profile( string $providerCode, string $repositoryId, string $profileId ): ?WebhookProfileMetadata;

	/**
	 * @param callable(string, #[\SensitiveParameter] string, int): ProvisioningCallbackResult $updateRemoteHook
	 */
	public function reconfigure( AssistanceTarget $target, string $recordedProfileId, callable $updateRemoteHook ): ProvisioningResult;

	public function releaseProfile( string $providerCode, string $repositoryId, string $profileId ): bool;
}
