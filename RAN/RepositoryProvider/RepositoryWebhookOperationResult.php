<?php
declare(strict_types=1);
namespace RAN\RepositoryProvider;

use InvalidArgumentException;
use RAN\AddOn\WebhookAssistance\WebhookProfileMetadata;
/** Bounded, non-secret truth about one fixed webhook operation. */
final readonly class RepositoryWebhookOperationResult {
	/** @param array{endpoint:string,events:string,content_type:string,active:string} $configuration */
	public function __construct(
		private string $state,
		private string $code,
		private string $observedAt,
		private ?string $hookId,
		private array $configuration,
		private string $delivery,
		private string $remediation,
		private ?WebhookProfileMetadata $profile = null
	) {
		$this->assertValue( $state, array( 'succeeded', 'partial', 'ambiguous', 'failed' ) );
		$this->assertText( $code, 96, '/\A[a-z0-9][a-z0-9._-]*\z/D' );
		$this->assertText( $observedAt, 32, '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D' );
		if ( null !== $hookId ) {
			$this->assertText( $hookId, 191, '/\A[A-Za-z0-9._:-]+\z/D' );
		}
		if ( array( 'endpoint', 'events', 'content_type', 'active' ) !== array_keys( $configuration ) ) {
			throw new InvalidArgumentException( 'Webhook operation result is invalid.' );
		}
		foreach ( $configuration as $value ) {
			$this->assertValue( $value, array( 'matched', 'mismatched', 'unknown' ) );
		}
		$this->assertValue( $delivery, array( 'configured_pending_delivery', 'verified', 'unverified', 'unknown', 'absent' ) );
		$this->assertText( $remediation, 512 );
	}
	public function succeeded(): bool {
		return 'succeeded' === $this->state;
	}
	public function confirmsAbsence(): bool {
		return $this->succeeded() && 'absent' === $this->delivery;
	}
	public function state(): string {
		return $this->state;
	}
	public function code(): string {
		return $this->code;
	}
	public function hookId(): ?string {
		return $this->hookId;
	}
	public function profile(): ?WebhookProfileMetadata {
		return $this->profile;
	}
	public function withProfile( WebhookProfileMetadata $profile ): self {
		return new self( $this->state, $this->code, $this->observedAt, $this->hookId, $this->configuration, $this->delivery, $this->remediation, $profile );
	}
	public function asPartial( string $code, string $remediation ): self {
		return new self( 'partial', $code, $this->observedAt, $this->hookId, $this->configuration, $this->delivery, $remediation, $this->profile );
	}
	/** @return array{state:string,code:string,observed_at:string,hook_id:?string,configuration:array{endpoint:string,events:string,content_type:string,active:string},delivery:string,remediation:string,profile:?array<string,mixed>} */
	public function toArray(): array {
		return array(
			'state'         => $this->state,
			'code'          => $this->code,
			'observed_at'   => $this->observedAt,
			'hook_id'       => $this->hookId,
			'configuration' => $this->configuration,
			'delivery'      => $this->delivery,
			'remediation'   => $this->remediation,
			'profile'       => $this->profile?->toArray(),
		);
	}
	private function assertValue( string $value, array $allowed ): void {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Webhook operation result is invalid.' );
		}
	}
	private function assertText( string $value, int $limit, ?string $pattern = null ): void {
		if ( '' === $value || strlen( $value ) > $limit || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || ( null !== $pattern && 1 !== preg_match( $pattern, $value ) ) ) {
			throw new InvalidArgumentException( 'Webhook operation result is invalid.' );
		}
	}
}
