<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final class InvalidProvider extends InvalidArgumentException {

	public static function emptyLabel(): self {
		return new self( 'Repository provider must have a label.' );
	}

	public static function emptyOwnerLabel(): self {
		return new self( 'Repository provider must have an owner label.' );
	}

	public static function invalidRepositoryUrlBase(): self {
		return new self( 'Repository provider must have an HTTPS repository URL base.' );
	}
}
