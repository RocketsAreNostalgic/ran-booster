<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class ArchiveRequest {
	public ?string $expectedBranch;

	/**
	 * Describe one archive deployment.
	 *
	 * When $expectedBranch is present, $ref must be an immutable commit and the
	 * provider must verify that it is still the current head of that branch
	 * before preparing any archive or authentication state.
	 */
	public function __construct(
		public RepositoryReference $repository,
		public string $ref,
		?string $expectedBranch = null
	) {
		if ( '' === trim( $ref ) ) {
			throw new InvalidArgumentException( 'Archive ref cannot be empty.' );
		}

		if ( null !== $expectedBranch && '' === trim( $expectedBranch ) ) {
			throw new InvalidArgumentException( 'Expected archive branch cannot be empty.' );
		}

		$this->expectedBranch = $expectedBranch;
	}
}
