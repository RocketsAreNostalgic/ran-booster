<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final readonly class RepositoryBrowseResult {
	public const LIMIT         = 'limit';
	public const RATE_LIMIT    = 'rate_limit';
	public const AUTHORIZATION = 'authorization';
	public const PROVIDER      = 'provider';

	/**
	 * @param list<RepositoryDescriptor> $repositories
	 */
	public function __construct(
		public array $repositories,
		public ?string $partialReason = null
	) {
		if ( ! array_is_list( $repositories ) || RepositoryBrowseRequest::MAX_RESULTS < count( $repositories ) ) {
			throw new RuntimeException( 'Repository browse results must be a bounded list.', 502 );
		}
		foreach ( $repositories as $repository ) {
			if ( ! $repository instanceof RepositoryDescriptor ) {
				throw new RuntimeException( 'Repository browse results must contain repository descriptors.', 502 );
			}
		}

		if ( null !== $partialReason && ! in_array( $partialReason, array( self::LIMIT, self::RATE_LIMIT, self::AUTHORIZATION, self::PROVIDER ), true ) ) {
			throw new RuntimeException( 'Unknown repository browse result reason.', 502 );
		}
	}

	public function isPartial(): bool {
		return null !== $this->partialReason;
	}
}
