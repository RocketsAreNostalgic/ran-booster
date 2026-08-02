<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use Closure;
use RAN\RepositoryProvider\PreparedArchive;

final class ExternalFixturePreparedArchive implements PreparedArchive {

	public function __construct(
		private readonly string $url,
		private readonly string $resolvedRef,
		private readonly ?Closure $headVerifier = null
	) {
	}

	public function getUrl(): string {
		return $this->url;
	}

	public function getResolvedRef(): string {
		return $this->resolvedRef;
	}

	public function verifyCurrentHead(): void {
		if ( null !== $this->headVerifier ) {
			( $this->headVerifier )();
		}
	}

	public function cleanup(): void {
	}
}
