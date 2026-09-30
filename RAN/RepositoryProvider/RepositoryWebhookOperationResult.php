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
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		private string $observedAt,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		private ?string $hookId,
		private array $configuration,
		private string $delivery,
		private string $remediation,
		private ?WebhookProfileMetadata $profile = null
	) {
		$this->assert_value( $state, array( 'succeeded', 'partial', 'ambiguous', 'failed' ) );
		$this->assert_text( $code, 96, '/\A[a-z0-9][a-z0-9._-]*\z/D' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		$this->assert_text( $observedAt, 32, '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		if ( null !== $hookId ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			$this->assert_text( $hookId, 191, '/\A[A-Za-z0-9._:-]+\z/D' );
		}
		if ( array( 'endpoint', 'events', 'content_type', 'active' ) !== array_keys( $configuration ) ) {
			throw new InvalidArgumentException( 'Webhook operation result is invalid.' );
		}
		foreach ( $configuration as $value ) {
			$this->assert_value( $value, array( 'matched', 'mismatched', 'unknown' ) );
		}
		$this->assert_value( $delivery, array( 'configured_pending_delivery', 'verified', 'unverified', 'unknown', 'absent' ) );
		$this->assert_text( $remediation, 512 );
	}
	public function succeeded(): bool {
		return 'succeeded' === $this->state;
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function confirmsAbsence(): bool {
		return $this->succeeded() && 'absent' === $this->delivery;
	}
	public function state(): string {
		return $this->state;
	}
	public function code(): string {
		return $this->code;
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function hookId(): ?string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		return $this->hookId;
	}
	public function profile(): ?WebhookProfileMetadata {
		return $this->profile;
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function withProfile( WebhookProfileMetadata $profile ): self {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		return new self( $this->state, $this->code, $this->observedAt, $this->hookId, $this->configuration, $this->delivery, $this->remediation, $profile );
	}
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function asPartial( string $code, string $remediation ): self {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
		return new self( 'partial', $code, $this->observedAt, $this->hookId, $this->configuration, $this->delivery, $remediation, $this->profile );
	}
	/** @return array{state:string,code:string,observed_at:string,hook_id:?string,configuration:array{endpoint:string,events:string,content_type:string,active:string},delivery:string,remediation:string,profile:?array<string,mixed>} */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function toArray(): array {
		return array(
			'state'         => $this->state,
			'code'          => $this->code,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			'observed_at'   => $this->observedAt,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public named parameters and promoted properties retain the existing caller contract.
			'hook_id'       => $this->hookId,
			'configuration' => $this->configuration,
			'delivery'      => $this->delivery,
			'remediation'   => $this->remediation,
			'profile'       => $this->profile?->toArray(),
		);
	}
	private function assert_value( string $value, array $allowed ): void {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Webhook operation result is invalid.' );
		}
	}
	private function assert_text( string $value, int $limit, ?string $pattern = null ): void {
		if ( '' === $value || strlen( $value ) > $limit || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || ( null !== $pattern && 1 !== preg_match( $pattern, $value ) ) ) {
			throw new InvalidArgumentException( 'Webhook operation result is invalid.' );
		}
	}
}
