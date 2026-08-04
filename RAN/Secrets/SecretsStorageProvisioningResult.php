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
		private ?string $candidatePath,
		private ?string $pathSource = null
	) {
	}

	public static function pathConfigured( string $candidatePath, string $pathSource ): self {
		return new self(
			self::PATH_CONFIGURED,
			'path_configured',
			'The private storage path is configured. Booster will initialize it when you save the first credential.',
			$candidatePath,
			$pathSource
		);
	}

	public static function storageHealthy( string $candidatePath, string $pathSource ): self {
		return new self(
			self::STORAGE_HEALTHY,
			'storage_healthy',
			'Encrypted secrets storage is configured and authenticated.',
			$candidatePath,
			$pathSource
		);
	}

	public static function storageNeedsAttention(
		string $candidatePath,
		string $pathSource,
		string $code = 'storage_needs_attention',
		string $message = 'Encrypted secrets storage is incomplete, unreadable or could not be authenticated.'
	): self {
		return new self(
			self::STORAGE_NEEDS_ATTENTION,
			$code,
			$message,
			$candidatePath,
			$pathSource
		);
	}

	public static function setupAvailable( string $candidatePath ): self {
		return new self(
			self::SETUP_AVAILABLE,
			'setup_available',
			'Booster can create secure encrypted secrets storage.',
			$candidatePath,
			self::PATH_SOURCE_AUTOMATIC
		);
	}

	public static function manualRequired( string $code, string $message, ?string $candidatePath = null ): self {
		return new self( self::MANUAL_REQUIRED, $code, $message, $candidatePath );
	}

	public static function unsupported( string $code, string $message ): self {
		return new self( self::UNSUPPORTED, $code, $message, null );
	}

	public static function pendingVerification( string $candidatePath ): self {
		return new self(
			self::PENDING_VERIFICATION,
			'pending_verification',
			'WordPress must reload before the encrypted secrets path can be trusted.',
			$candidatePath,
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

	public function candidatePath(): ?string {
		return $this->candidatePath;
	}

	public function pathSource(): ?string {
		return $this->pathSource;
	}

	public function hasConfiguredPath(): bool {
		return in_array(
			$this->status,
			array( self::PATH_CONFIGURED, self::STORAGE_HEALTHY, self::STORAGE_NEEDS_ATTENTION ),
			true
		);
	}

	public function canProvisionAutomatically(): bool {
		return self::SETUP_AVAILABLE === $this->status;
	}

	public function requiresNextRequestVerification(): bool {
		return self::PENDING_VERIFICATION === $this->status;
	}
}
