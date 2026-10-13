<?php

declare(strict_types=1);

namespace RAN\Activity;

use InvalidArgumentException;
use RAN\RepositoryProvider\RepositoryReference;

/** Identifies work; Core resolves its current managed lifetime before execution. */
final readonly class ActivityRequest {

	public function __construct(
		public string $operation,
		public ?RepositoryReference $repository = null,
		public ?string $target_type = null,
		public ?string $target_identity = null
	) {
		if ( ! self::valid_code( $operation )
			|| ( null !== $target_type && ! in_array( $target_type, array( 'plugin', 'theme' ), true ) )
			|| ( null === $target_type ) !== ( null === $target_identity )
			|| ( null !== $target_identity && ( '' === $target_identity || strlen( $target_identity ) > 255 || preg_match( '/[\x00-\x1F\x7F]/', $target_identity ) ) ) ) {
			throw new InvalidArgumentException( 'The activity target or operation is invalid.' );
		}
	}

	public static function valid_code( string $code ): bool {
		return 1 === preg_match( '/\A[a-z][a-z0-9_.-]{0,95}\z/', $code );
	}
}
