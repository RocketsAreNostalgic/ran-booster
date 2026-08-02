<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

/** Display-safe result of a repository-scoped profile provisioning attempt. */
final readonly class ProvisioningResult {

	private function __construct( private string $code, private ?WebhookProfileMetadata $profile = null ) {
	}

	public static function success( WebhookProfileMetadata $profile ): self {
		return new self( 'succeeded', $profile );
	}

	public static function failed( string $code ): self {
		return new self( $code );
	}

	public function succeeded(): bool {
		return null !== $this->profile;
	}

	public function code(): string {
		return $this->code;
	}

	public function profile(): ?WebhookProfileMetadata {
		return $this->profile;
	}

	public function profileId(): ?string {
		return $this->profile?->id();
	}

	public function scope(): ?string {
		return $this->profile?->scope();
	}

	public function revision(): ?int {
		return $this->profile?->revision();
	}

	public function disposition(): ?string {
		return $this->profile?->disposition();
	}
}
