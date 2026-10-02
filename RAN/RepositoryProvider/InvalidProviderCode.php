<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final class InvalidProviderCode extends InvalidArgumentException {

	public static function for_value(): self {
		return new self( 'Unsupported repository provider code.' );
	}
}
