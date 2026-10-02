<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use OutOfBoundsException;

final class UnknownProvider extends OutOfBoundsException {

	public static function for_code(): self {
		return new self( 'Repository provider is not registered.' );
	}
}
