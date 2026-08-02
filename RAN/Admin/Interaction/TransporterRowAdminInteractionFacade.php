<?php

declare(strict_types=1);

namespace RAN\Admin\Interaction;

/**
 * Additive Admin Interaction API 2 capability for one Transporter source row.
 *
 * Consumers must feature-detect this interface with instanceof. It deliberately
 * does not change the existing AdminInteractionFacade API 1 interface.
 */
interface TransporterRowAdminInteractionFacade {

	/**
	 * Return an authoritative row fragment for an exact enhanced request.
	 *
	 * The renderer receives the Core-derived target element ID and must emit one
	 * escaped <tr> with that ID. Ordinary requests retain the signed PRG path.
	 *
	 * @param callable(string): void $renderFragment
	 */
	public function respondWithTransporterRowFragment(
		AdminInteractionOutcome $outcome,
		callable $renderFragment
	): never;
}
