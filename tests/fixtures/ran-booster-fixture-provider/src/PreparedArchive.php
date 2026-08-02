<?php

declare(strict_types=1);

namespace RANBoosterFixtureProvider;

use Closure;
use RAN\RepositoryProvider\PreparedArchive as PreparedArchiveContract;

final readonly class PreparedArchive implements PreparedArchiveContract {

	public function __construct(
		private string $url,
		private string $resolvedRef,
		private ?Closure $headVerifier = null
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
