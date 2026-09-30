<?php

declare(strict_types=1);

namespace RAN\Secrets;

/**
 * Bounded setup state for the privileged Booster storage screen.
 *
 * The optional candidate path is intentionally separate from the stable code
 * and message so callers can keep it out of URLs, logs and global notices.
 */
final readonly class SecretsStorageProvisioningResult {

	public const PATH_CONFIGURED         = 'path_configured';
	public const STORAGE_HEALTHY         = 'storage_healthy';
	public const STORAGE_NEEDS_ATTENTION = 'storage_needs_attention';
	public const SETUP_AVAILABLE         = 'setup_available';
	public const MANUAL_REQUIRED         = 'manual_required';
	public const UNSUPPORTED             = 'unsupported';
	public const PENDING_VERIFICATION    = 'pending_verification';

	public const PATH_SOURCE_AUTOMATIC = 'automatic';
	public const PATH_SOURCE_MANUAL    = 'manual';

	private function __construct(
		private string $status,
		private string $code,
		private string $message,
		private ?string $candidate_path,
		private ?string $path_source = null,
		private array $discarded_candidates = array()
	) {
	}

	public static function path_configured( string $candidate_path, string $path_source ): self {
		return new self(
			self::PATH_CONFIGURED,
			'path_configured',
			__( 'The private storage path is configured. Booster will initialize it when you save the first credential.', 'ran-booster' ),
			$candidate_path,
			$path_source
		);
	}

	public static function storage_healthy( string $candidate_path, string $path_source ): self {
		return new self(
			self::STORAGE_HEALTHY,
			'storage_healthy',
			__( 'Encrypted secrets storage is configured and authenticated.', 'ran-booster' ),
			$candidate_path,
			$path_source
		);
	}

	public static function storage_reset( string $candidate_path, string $path_source ): self {
		return new self(
			self::PATH_CONFIGURED,
			'storage_reset',
			__( 'Incomplete credential storage was reset. Booster will initialize fresh encrypted storage when you next save or import a credential.', 'ran-booster' ),
			$candidate_path,
			$path_source
		);
	}

	public static function storage_needs_attention(
		string $candidate_path,
		string $path_source,
		string $code = 'storage_needs_attention',
		string $message = 'Encrypted secrets storage is incomplete, unreadable or could not be authenticated.'
	): self {
		$message = 'Encrypted secrets storage is incomplete, unreadable or could not be authenticated.' === $message
			? __( 'Encrypted secrets storage is incomplete, unreadable or could not be authenticated.', 'ran-booster' )
			: $message;

		return new self(
			self::STORAGE_NEEDS_ATTENTION,
			$code,
			$message,
			$candidate_path,
			$path_source
		);
	}

	public static function setup_available( string $candidate_path ): self {
		return new self(
			self::SETUP_AVAILABLE,
			'setup_available',
			__( 'Booster can create secure encrypted secrets storage.', 'ran-booster' ),
			$candidate_path,
			self::PATH_SOURCE_AUTOMATIC
		);
	}

	/** @param list<array{directory:string,code:string,reason:string,component:string|null}> $discarded_candidates */
	public static function manual_required(
		string $code,
		string $message,
		?string $candidate_path = null,
		array $discarded_candidates = array()
	): self {
		return new self( self::MANUAL_REQUIRED, $code, $message, $candidate_path, null, $discarded_candidates );
	}

	public static function unsupported( string $code, string $message ): self {
		return new self( self::UNSUPPORTED, $code, $message, null );
	}

	public static function pending_verification( string $candidate_path ): self {
		return new self(
			self::PENDING_VERIFICATION,
			'pending_verification',
			__( 'WordPress must reload before the encrypted secrets path can be trusted.', 'ran-booster' ),
			$candidate_path,
			self::PATH_SOURCE_AUTOMATIC
		);
	}

	public function status(): string {
		return $this->status;
	}

	public function code(): string {
		return $this->code;
	}

	public function message(): string {
		return $this->message;
	}

	public function candidate_path(): ?string {
		return $this->candidate_path;
	}

	public function path_source(): ?string {
		return $this->path_source;
	}

	/** @return list<array{directory:string,code:string,reason:string,component:string|null}> */
	public function discarded_candidates(): array {
		return $this->discarded_candidates;
	}

	public function has_configured_path(): bool {
		return in_array(
			$this->status,
			array( self::PATH_CONFIGURED, self::STORAGE_HEALTHY, self::STORAGE_NEEDS_ATTENTION ),
			true
		);
	}

	public function can_provision_automatically(): bool {
		return self::SETUP_AVAILABLE === $this->status;
	}

	public function requires_next_request_verification(): bool {
		return self::PENDING_VERIFICATION === $this->status;
	}
}
