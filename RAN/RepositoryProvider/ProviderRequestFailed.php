<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

/**
 * A provider request failed for a reason the provider can name.
 *
 * Thrown by repository resolution, browsing, archive preparation and head verification. `getCode()` stays valid
 * for hosts that predate the carrier: the failure's status, else the reason's default code.
 */
final class ProviderRequestFailed extends RuntimeException {

	public function __construct( public readonly ProviderFailure $failure ) {
		parent::__construct( 'The repository provider request failed.', $failure->legacy_code() );
	}
}
