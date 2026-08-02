<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;

/**
 * Minimal useful provider surface for tests that exercise unrelated behavior.
 */
trait SuppliesProviderManualCapabilities {

	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return new RepositoryDescriptor(
			$this->getMetadata()->code,
			$request->locator,
			basename( $request->locator ),
			'test:' . hash( 'sha256', $request->locator ),
			false,
			'main',
			$request->credentialId
		);
	}

	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		return new class() implements PreparedArchive {
			public function getUrl(): string {
				return 'https://example.test/archive.zip';
			}

			public function getResolvedRef(): string {
				return '0123456789abcdef0123456789abcdef01234567';
			}

			public function verifyCurrentHead(): void {
			}

			public function cleanup(): void {
			}
		};
	}
}
