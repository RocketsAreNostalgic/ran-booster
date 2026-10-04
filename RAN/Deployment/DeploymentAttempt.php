<?php

declare(strict_types=1);

namespace RAN\Deployment;

use InvalidArgumentException;

/** A validated projection of the 22-column deployment-attempt row. */
final readonly class DeploymentAttempt {

	private function __construct(
		private int $id,
		private string $correlation_id,
		private string $source,
		private string $operation,
		private string $package_type,
		private string $package_slug,
		private string $package_source,
		private int $package_source_revision,
		private string $provider,
		private string $provider_repository_id,
		private string $requested_ref,
		private ?string $resolved_ref,
		private ?string $delivery_id,
		private DeploymentState $state,
		private ?string $mutation_started_at,
		private ?DeploymentOutcome $outcome,
		private DeploymentRequest $request,
		private string $created_at,
		private ?string $finished_at,
		private ?string $resolved_at,
		private ?int $resolved_by
	) {
	}

	/** @param array<string, mixed>|object $record */
	public static function from_database( array|object $record ): self {
		$row = is_object( $record ) ? get_object_vars( $record ) : $record;

		try {
			$id                      = self::positive_int( $row['id'] ?? null );
			$correlation_id          = self::hex( $row['correlation_id'] ?? null, 32 );
			$source                  = self::one_of( $row['source'] ?? null, array( 'manual', 'webhook' ) );
			$operation               = self::one_of( $row['operation'] ?? null, array( 'install', 'update' ) );
			$package_type            = self::one_of( $row['package_type'] ?? null, array( 'plugin', 'theme' ) );
			$package_slug            = self::identifier( $row['package_slug'] ?? null, 191 );
			$package_source          = self::one_of( $row['package_source'] ?? null, array( 'branch' ) );
			$package_source_revision = self::non_negative_int( $row['package_source_revision'] ?? null );
			$provider                = self::provider( $row['provider'] ?? null );
			$provider_repository_id  = self::safe_text( $row['provider_repository_id'] ?? null, 191 );
			$requested_ref           = self::safe_text( $row['requested_ref'] ?? null, 255 );
			$resolved_ref            = self::nullable_safe_text( $row['resolved_ref'] ?? null, 191 );
			$delivery_id             = self::nullable_safe_text( $row['delivery_id'] ?? null, 191 );
			$delivery_digest         = self::nullable_hex( $row['delivery_digest'] ?? null, 64 );
			$state                   = DeploymentState::from_database( $row['state'] ?? null );
			$mutation_started_at     = self::nullable_date( $row['mutation_started_at'] ?? null );
			$outcome_code            = self::nullable_identifier( $row['outcome_code'] ?? null, 64 );
			$outcome                 = null === $outcome_code ? null : DeploymentOutcome::from_code( $outcome_code );
			$request                 = DeploymentRequest::from_json( self::safe_text( $row['request_json'] ?? null, 4096 ) );
			$created_at              = self::date( $row['created_at'] ?? null );
			$finished_at             = self::nullable_date( $row['finished_at'] ?? null );
			$resolved_at             = self::nullable_date( $row['resolved_at'] ?? null );
			$resolved_by             = self::nullable_positive_int( $row['resolved_by'] ?? null );

			if ( ( null === $delivery_id ) !== ( null === $delivery_digest ) || ( 'webhook' === $source ) !== ( null !== $delivery_id ) ) {
				throw new InvalidArgumentException( 'The stored delivery identity is incomplete.' );
			}
			if ( $request->package_slug !== $package_slug ) {
				throw new InvalidArgumentException( 'The stored request identity is inconsistent.' );
			}
			if ( $state->is_terminal() ) {
				if ( null === $outcome || null === $finished_at ) {
					throw new InvalidArgumentException( 'The stored terminal state is incomplete.' );
				}
			} elseif ( null !== $outcome || null !== $finished_at ) {
				throw new InvalidArgumentException( 'A non-terminal attempt contains terminal data.' );
			}
			if ( DeploymentState::QUEUED === $state && null !== $mutation_started_at ) {
				throw new InvalidArgumentException( 'A queued attempt cannot contain a mutation fence.' );
			}
			if ( null !== $outcome && $outcome->get_state() !== $state ) {
				throw new InvalidArgumentException( 'The stored outcome does not match the attempt state.' );
			}
			if ( ( null === $resolved_at ) !== ( null === $resolved_by )
				|| ( null !== $resolved_at && ! $state->requires_operator_resolution() ) ) {
				throw new InvalidArgumentException( 'The stored operator resolution is invalid.' );
			}
		} catch ( InvalidArgumentException ) {
			throw DeploymentStorageFailure::invalid_record();
		}

		return new self(
			$id,
			$correlation_id,
			$source,
			$operation,
			$package_type,
			$package_slug,
			$package_source,
			$package_source_revision,
			$provider,
			$provider_repository_id,
			$requested_ref,
			$resolved_ref,
			$delivery_id,
			$state,
			$mutation_started_at,
			$outcome,
			$request,
			$created_at,
			$finished_at,
			$resolved_at,
			$resolved_by
		);
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_correlation_id(): string {
		return $this->correlation_id;
	}

	public function get_state(): DeploymentState {
		return $this->state;
	}

	public function get_request(): DeploymentRequest {
		return $this->request;
	}

	public function get_outcome(): ?DeploymentOutcome {
		return $this->outcome;
	}

	public function requires_operator_resolution(): bool {
		return $this->state->requires_operator_resolution() && null === $this->resolved_at;
	}

	/** @return array<string, int|string|null> */
	public function log_context(): array {
		return array(
			'attempt_id'              => $this->id,
			'operation'               => $this->operation,
			'package_slug'            => $this->package_slug,
			'package_source'          => $this->package_source,
			'package_source_revision' => $this->package_source_revision,
			'provider'                => $this->provider,
			'source'                  => $this->source,
			'state'                   => $this->state->value,
		);
	}

	/** @return array<string, bool|int|string|null> */
	public function safe_data(): array {
		return array(
			'id'                      => $this->id,
			'correlation_id'          => $this->correlation_id,
			'source'                  => $this->source,
			'operation'               => $this->operation,
			'package_type'            => $this->package_type,
			'package_slug'            => $this->package_slug,
			'package_source'          => $this->package_source,
			'package_source_revision' => $this->package_source_revision,
			'provider'                => $this->provider,
			'provider_repository_id'  => $this->provider_repository_id,
			'requested_ref'           => $this->requested_ref,
			'resolved_ref'            => $this->resolved_ref,
			'delivery_id'             => $this->delivery_id,
			'state'                   => $this->state->value,
			'mutation_started_at'     => $this->mutation_started_at,
			'outcome_code'            => $this->outcome?->get_code(),
			'created_at'              => $this->created_at,
			'finished_at'             => $this->finished_at,
			'resolved_at'             => $this->resolved_at,
			'resolved_by'             => $this->resolved_by,
		);
	}

	private static function positive_int( mixed $value ): int {
		if ( ! is_numeric( $value ) || (int) $value < 1 || (string) (int) $value !== (string) $value ) {
			throw new InvalidArgumentException( 'The deployment attempt ID is invalid.' );
		}

		return (int) $value;
	}

	private static function non_negative_int( mixed $value ): int {
		if ( ! is_numeric( $value ) || (int) $value < 0 || (string) (int) $value !== (string) $value ) {
			throw new InvalidArgumentException( 'The package source revision is invalid.' );
		}

		return (int) $value;
	}

	private static function nullable_positive_int( mixed $value ): ?int {
		return null === $value ? null : self::positive_int( $value );
	}

	private static function one_of( mixed $value, array $allowed ): string {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( 'A deployment attempt field is not recognised.' );
		}

		return $value;
	}

	private static function provider( mixed $value ): string {
		if ( ! is_string( $value ) || preg_match( '/^[a-z][a-z0-9-]{0,31}$/D', $value ) !== 1 ) {
			throw new InvalidArgumentException( 'The stored provider is invalid.' );
		}

		return $value;
	}

	private static function identifier( mixed $value, int $limit ): string {
		if ( ! is_string( $value ) || strlen( $value ) > $limit || preg_match( '/^[a-z0-9][a-z0-9._-]*$/D', $value ) !== 1 ) {
			throw new InvalidArgumentException( 'A stored deployment identifier is invalid.' );
		}

		return $value;
	}

	private static function nullable_identifier( mixed $value, int $limit ): ?string {
		return null === $value ? null : self::identifier( $value, $limit );
	}

	private static function safe_text( mixed $value, int $limit ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $limit || preg_match( '//u', $value ) !== 1
			|| preg_match( '/[[:cntrl:]]/', $value ) === 1
			|| preg_match( '/(?:https?:\/\/|[A-Za-z][A-Za-z0-9+.-]*:\/\/)[^\s]*@/i', $value ) === 1
			|| preg_match( '/\b(?:authorization|bearer|token|secret|password|signature)\b\s*[:=]/i', $value ) === 1 ) {
			throw new InvalidArgumentException( 'A stored deployment field is invalid.' );
		}

		return $value;
	}

	private static function nullable_safe_text( mixed $value, int $limit ): ?string {
		return null === $value ? null : self::safe_text( $value, $limit );
	}

	private static function hex( mixed $value, int $length ): string {
		if ( ! is_string( $value ) || preg_match( sprintf( '/^[a-f0-9]{%d}$/D', $length ), $value ) !== 1 ) {
			throw new InvalidArgumentException( 'A stored deployment digest is invalid.' );
		}

		return $value;
	}

	private static function nullable_hex( mixed $value, int $length ): ?string {
		return null === $value ? null : self::hex( $value, $length );
	}

	private static function date( mixed $value ): string {
		if ( ! is_string( $value ) || preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) !== 1 ) {
			throw new InvalidArgumentException( 'A stored deployment time is invalid.' );
		}

		return $value;
	}

	private static function nullable_date( mixed $value ): ?string {
		return null === $value ? null : self::date( $value );
	}
}
