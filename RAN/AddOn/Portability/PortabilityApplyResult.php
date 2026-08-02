<?php

declare(strict_types=1);

namespace RAN\AddOn\Portability;

use InvalidArgumentException;
use RAN\Portability\TargetPackageReason;

/** Immutable, display-safe result of one fresh Portability Apply request. */
final readonly class PortabilityApplyResult {

	public const ADOPTED   = 'adopted';
	public const UNCHANGED = 'unchanged';
	public const BLOCKED   = 'blocked';
	public const FAILED    = 'failed';

	private const PROCEDURAL_REASONS = array(
		'forbidden',
		'review_changed',
		'unexpected_failure',
		'unsupported_runtime',
	);

	public function __construct(
		public string $status,
		public string $reason,
		public string $message,
		public bool $targetVerified
	) {
		$verifiedStatus = in_array( $status, array( self::ADOPTED, self::UNCHANGED ), true );
		$reasons        = array_merge(
			array_map( static fn ( TargetPackageReason $reason ): string => $reason->value, TargetPackageReason::cases() ),
			self::PROCEDURAL_REASONS
		);
		if ( ! in_array( $status, array( self::ADOPTED, self::UNCHANGED, self::BLOCKED, self::FAILED ), true )
			|| ! in_array( $reason, $reasons, true )
			|| $targetVerified !== $verifiedStatus
			|| '' === trim( $message )
			|| strlen( $message ) > 255
			|| 1 !== preg_match( '//u', $message )
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $message ) ) {
			throw new InvalidArgumentException( 'The Portability Apply result is invalid.' );
		}
	}
}
