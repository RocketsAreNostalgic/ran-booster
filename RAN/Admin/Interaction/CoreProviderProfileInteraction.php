<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

/**
 * Core-internal provider profile mutation transport.
 *
 * @internal
 */
interface CoreProviderProfileInteraction {

	public const TARGET_KEY = 'core_provider_profiles';

	public const TARGET_SELECTOR = '#ran-booster-provider-profile-region';

	public function providerProfileRequest(
		string $action,
		string $provider
	): SignedAdminInteractionRequest;

	public function respondToProviderProfileSuccess( SignedAdminInteractionRequest $request, string $message ): void;

	public function respondToProviderProfileValidationFailure( SignedAdminInteractionRequest $request, string $message ): void;

	public function respondToProviderProfileUnexpectedFailure( SignedAdminInteractionRequest $request ): void;
}
