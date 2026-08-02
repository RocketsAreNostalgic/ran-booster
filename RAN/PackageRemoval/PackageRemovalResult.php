<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

use InvalidArgumentException;

/**
 * Secret-free, bounded result for an administrator package-removal request.
 */
final readonly class PackageRemovalResult {

	private const STATUSES = array( 'unlinked', 'deleted', 'failed' );

	private const OUTCOME_CODES = array(
		'active_dependents',
		'deactivation_failed',
		'deletion_failed',
		'files_still_present',
		'management_state_uncertain',
		'operation_in_progress',
		'operation_lock_failed',
		'shared_plugin_directory',
		'stale',
		'theme_active',
		'theme_has_children',
		'theme_parent_in_use',
		'unsafe_path',
	);

	private function __construct(
		public string $status,
		public string $outcomeCode = ''
	) {
		if ( ! in_array( $status, self::STATUSES, true )
			|| ( 'failed' === $status && ! in_array( $outcomeCode, self::OUTCOME_CODES, true ) )
			|| ( 'failed' !== $status && '' !== $outcomeCode ) ) {
			throw new InvalidArgumentException( 'The package removal result is invalid.' );
		}
	}

	public static function unlinked(): self {
		return new self( 'unlinked' );
	}

	public static function deleted(): self {
		return new self( 'deleted' );
	}

	public static function failed( string $outcomeCode ): self {
		return new self( 'failed', $outcomeCode );
	}

	/** @return list<string> */
	public static function outcomeCodes(): array {
		return self::OUTCOME_CODES;
	}
}
