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

	public const API_VERSION = 3;

	public function render_form_attributes( AdminInteractionRequest $request ): void;

	public function is_enhanced_request( AdminInteractionRequest $request ): bool;

	public function respond( AdminInteractionOutcome $outcome ): never;
}
