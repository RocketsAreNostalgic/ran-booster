<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;

/**
 * Minimal useful provider surface for tests that exercise unrelated behavior.
 */
trait SuppliesProviderManualCapabilities {

	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return new RepositoryDescriptor(
			$this->get_metadata()->code,
			$request->locator,
			basename( $request->locator ),
			'test:' . hash( 'sha256', $request->locator ),
			false,
			'main',
			$request->credential_id
		);
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The fixture implementation of prepare_archive retains the production method contract; these inputs do not affect this controlled result.
	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		return new class() implements PreparedArchive {
			public function get_url(): string {
				return 'https://example.test/archive.zip';
			}

			public function get_resolved_ref(): string {
				return '0123456789abcdef0123456789abcdef01234567';
			}

			public function verify_current_head(): void {
			}

			public function cleanup(): void {
			}
		};
	}
}
