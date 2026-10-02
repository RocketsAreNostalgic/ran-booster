<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final class InvalidProvider extends InvalidArgumentException {

	public static function empty_label(): self {
		return new self( 'Repository provider must have a label.' );
	}

	public static function empty_owner_label(): self {
		return new self( 'Repository provider must have an owner label.' );
	}

	public static function invalid_repository_url_base(): self {
		return new self( 'Repository provider must have an HTTPS repository URL base.' );
	}
}
