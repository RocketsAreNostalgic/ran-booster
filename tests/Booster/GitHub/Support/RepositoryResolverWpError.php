<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

final readonly class RepositoryResolverWpError {

	public function __construct( private string $code ) {
	}

	public function get_error_code(): string {
		return $this->code;
	}
}
