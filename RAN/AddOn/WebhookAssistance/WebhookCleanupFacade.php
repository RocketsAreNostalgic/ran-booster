<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

/**
 * Optional cleanup-only authority for webhook setup retained by release packages.
 *
 * This capability is deliberately separate from WebhookAssistanceFacade so the
 * stable add-on API can remain compatible with existing implementations.
 */
interface WebhookCleanupFacade {

	public function cleanupTarget( string $providerCode, string $repositoryId ): ?AssistanceTarget;

	public function cleanupProfile( AssistanceTarget $target, string $profileId ): ?WebhookProfileMetadata;

	public function releaseCleanupProfile( AssistanceTarget $target, string $profileId ): bool;
}
