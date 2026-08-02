<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final class InvalidProviderCode extends InvalidArgumentException {

	public static function forValue(): self {
		return new self( 'Unsupported repository provider code.' );
	}
}
