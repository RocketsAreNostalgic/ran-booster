<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RuntimeException;

/**
 * A provider request failed for a reason the provider can name.
 *
 * Thrown by repository resolution, browsing, archive preparation and head verification. `getCode()` stays valid
 * for hosts that predate the carrier: the failure's status, else the reason's default code.
 */
final class ProviderRequestFailed extends RuntimeException {

	public function __construct( public readonly ProviderFailure $failure ) {
		if ( ! in_array( $failure->reason, ProviderFailure::REQUEST_REASONS, true ) ) {
			throw new InvalidArgumentException( 'A request failure cannot carry a release reason.' );
		}
		parent::__construct( 'The repository provider request failed.', $failure->legacy_code() );
	}
}
