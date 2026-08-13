<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider\Admin;

use InvalidArgumentException;

final readonly class ProviderNavigationPlacement {

	public const GIT_HOST       = 'git-host';
	public const OTHER_PROVIDER = 'other-provider';

	public function __construct( public string $group, public int $slot ) {
		if ( ! in_array( $group, array( self::GIT_HOST, self::OTHER_PROVIDER ), true ) || 1 > $slot || 10000 < $slot ) {
			throw new InvalidArgumentException( 'Provider navigation placement is invalid.' );
		}
	}
}
