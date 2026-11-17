<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final class WebhookRejected extends RuntimeException {

	/** Core-owned observations; separate from the frozen ProviderFailure vocabulary. */
	public const REASONS = array(
		'rejected',
		'ignored',
		'probe_verified',
		'signature_missing',
		'signature_malformed',
		'signature_mismatch',
		'secret_store_unavailable',
		'profiles_missing',
		'profile_invalid',
		'secret_invalid',
		'scope_mismatch',
		'request_too_large',
		'payload_invalid',
		'ignored_type',
		'ignored_ref',
		'ignored_deletion',
		'ignored_zero_commits',
	);

	private readonly ?string $reason;

	public function __construct(
		private readonly int $status_code,
		string $safe_message,
		?string $reason = null
	) {
		parent::__construct( $safe_message );
		$this->reason = in_array( $reason, self::REASONS, true ) ? $reason : null;
	}

	public function get_reason(): ?string {
		return $this->reason;
	}

	public function get_status_code(): int {
		return $this->status_code;
	}
}
