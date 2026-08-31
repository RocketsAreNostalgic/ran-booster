<?php

declare(strict_types=1);

namespace Tests\Deployment;

use RAN\RepositoryProvider\PreparedArchive;

final class PreflightPreparedArchive implements PreparedArchive {

	public int $cleanupCalls  = 0;
	public bool $cleanupFails = false;

	public function __construct( private readonly string $resolvedRef = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ) {
	}

	public function getUrl(): string {
		return 'https://archives.example.test/package.zip';
	}

	public function getResolvedRef(): string {
		return $this->resolvedRef;
	}

	public function verifyCurrentHead(): void {
	}

	public function cleanup(): void {
		++$this->cleanupCalls;
		if ( $this->cleanupFails ) {
			throw new \RuntimeException( 'Provider cleanup failed.' );
		}
	}
}
