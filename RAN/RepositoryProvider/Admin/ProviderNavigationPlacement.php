<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderCode;

final readonly class ProviderNavigationPlacement {

	public const GIT_HOST       = 'git-host';
	public const OTHER_PROVIDER = 'other-provider';
	public const GITHUB_SLOT    = 100;
	public const BITBUCKET_SLOT = 200;

	public function __construct( public string $group, public int $slot ) {
		if ( ! in_array( $group, array( self::GIT_HOST, self::OTHER_PROVIDER ), true ) || 1 > $slot || 10000 < $slot ) {
			throw new InvalidArgumentException( 'Provider navigation placement is invalid.' );
		}
		if ( self::GIT_HOST === $group && ! in_array( $slot, array( self::GITHUB_SLOT, self::BITBUCKET_SLOT ), true ) ) {
			throw new InvalidArgumentException( 'Git-host navigation slots are reserved.' );
		}
		if ( self::OTHER_PROVIDER === $group && self::BITBUCKET_SLOT >= $slot ) {
			throw new InvalidArgumentException( 'Other-provider navigation slots follow the git-host band.' );
		}
	}

	public function assertProvider( ProviderCode $code ): void {
		$reserved = array(
			self::GITHUB_SLOT    => 'gh',
			self::BITBUCKET_SLOT => 'bb',
		);
		if ( self::GIT_HOST === $this->group && ( $reserved[ $this->slot ] ?? null ) !== $code->value ) {
			throw new InvalidArgumentException( 'Reserved git-host navigation slots have fixed providers.' );
		}
		if ( self::OTHER_PROVIDER === $this->group && in_array( $code->value, $reserved, true ) ) {
			throw new InvalidArgumentException( 'First-party git hosts require reserved navigation slots.' );
		}
	}
}
