<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

/**
 * Versioned presentation and transport boundary for add-on-owned mutations.
 *
 * Authorization, nonce validation, target reauthorization and operation truth
 * remain entirely add-on owned.
 */
interface AdminInteractionFacade {

	public const API_VERSION = 1;

	public function renderFormAttributes( AdminInteractionRequest $request ): void;

	public function isEnhancedRequest( AdminInteractionRequest $request ): bool;

	public function respond( AdminInteractionOutcome $outcome ): never;
}
