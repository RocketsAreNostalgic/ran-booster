<?php

declare(strict_types=1);

namespace RAN\Deployment;

use InvalidArgumentException;

/** A validated projection of the 22-column deployment-attempt row. */
final readonly class DeploymentAttempt {

	private function __construct(
		private int $id,
		private string $correlationId,
		private string $source,
		private string $operation,
		private string $packageType,
		private string $packageSlug,
		private string $packageSource,
		private int $packageSourceRevision,
		private string $provider,
		private string $providerRepositoryId,
		private string $requestedRef,
		private ?string $resolvedRef,
		private ?string $deliveryId,
		private ?string $deliveryDigest,
		private DeploymentState $state,
		private ?string $mutationStartedAt,
		private ?DeploymentOutcome $outcome,
		private DeploymentRequest $request,
		private string $createdAt,
		private ?string $finishedAt,
		private ?string $resolvedAt,
		private ?int $resolvedBy
	) {
	}

	/** @param array<string, mixed>|object $record */
	public static function fromDatabase( array|object $record ): self {
		$row = is_object( $record ) ? get_object_vars( $record ) : $record;

		try {
			$id                    = self::positiveInt( $row['id'] ?? null );
			$correlationId         = self::hex( $row['correlation_id'] ?? null, 32 );
			$source                = self::oneOf( $row['source'] ?? null, array( 'manual', 'webhook' ) );
			$operation             = self::oneOf( $row['operation'] ?? null, array( 'install', 'update' ) );
			$packageType           = self::oneOf( $row['package_type'] ?? null, array( 'plugin', 'theme' ) );
			$packageSlug           = self::identifier( $row['package_slug'] ?? null, 191 );
			$packageSource         = self::oneOf( $row['package_source'] ?? null, array( 'branch' ) );
			$packageSourceRevision = self::nonNegativeInt( $row['package_source_revision'] ?? null );
			$provider              = self::provider( $row['provider'] ?? null );
			$providerRepositoryId  = self::safeText( $row['provider_repository_id'] ?? null, 191 );
			$requestedRef          = self::safeText( $row['requested_ref'] ?? null, 255 );
			$resolvedRef           = self::nullableSafeText( $row['resolved_ref'] ?? null, 191 );
			$deliveryId            = self::nullableSafeText( $row['delivery_id'] ?? null, 191 );
			$deliveryDigest        = self::nullableHex( $row['delivery_digest'] ?? null, 64 );
			$state                 = DeploymentState::fromDatabase( $row['state'] ?? null );
			$mutationStartedAt     = self::nullableDate( $row['mutation_started_at'] ?? null );
			$outcomeCode           = self::nullableIdentifier( $row['outcome_code'] ?? null, 64 );
			$outcome               = null === $outcomeCode ? null : DeploymentOutcome::fromCode( $outcomeCode );
			$request               = DeploymentRequest::fromJson( self::safeText( $row['request_json'] ?? null, 4096 ) );
			$createdAt             = self::date( $row['created_at'] ?? null );
			$finishedAt            = self::nullableDate( $row['finished_at'] ?? null );
			$resolvedAt            = self::nullableDate( $row['resolved_at'] ?? null );
			$resolvedBy            = self::nullablePositiveInt( $row['resolved_by'] ?? null );

			if ( ( null === $deliveryId ) !== ( null === $deliveryDigest ) || ( 'webhook' === $source ) !== ( null !== $deliveryId ) ) {
				throw new InvalidArgumentException( 'The stored delivery identity is incomplete.' );
			}
			if ( $request->packageSlug !== $packageSlug ) {
				throw new InvalidArgumentException( 'The stored request identity is inconsistent.' );
			}
			if ( $state->isTerminal() ) {
				if ( null === $outcome || null === $finishedAt ) {
					throw new InvalidArgumentException( 'The stored terminal state is incomplete.' );
				}
			} elseif ( null !== $outcome || null !== $finishedAt ) {
				throw new InvalidArgumentException( 'A non-terminal attempt contains terminal data.' );
			}
			if ( DeploymentState::QUEUED === $state && null !== $mutationStartedAt ) {
				throw new InvalidArgumentException( 'A queued attempt cannot contain a mutation fence.' );
			}
			if ( null !== $outcome && $outcome->getState() !== $state ) {
				throw new InvalidArgumentException( 'The stored outcome does not match the attempt state.' );
			}
			if ( ( null === $resolvedAt ) !== ( null === $resolvedBy )
				|| ( null !== $resolvedAt && ! $state->requiresOperatorResolution() ) ) {
				throw new InvalidArgumentException( 'The stored operator resolution is invalid.' );
			}
		} catch ( InvalidArgumentException ) {
			throw DeploymentStorageFailure::invalidRecord();
		}

		return new self(
			$id,
			$correlationId,
			$source,
			$operation,
			$packageType,
			$packageSlug,
			$packageSource,
			$packageSourceRevision,
			$provider,
			$providerRepositoryId,
			$requestedRef,
			$resolvedRef,
			$deliveryId,
			$deliveryDigest,
			$state,
			$mutationStartedAt,
			$outcome,
			$request,
			$createdAt,
			$finishedAt,
			$resolvedAt,
			$resolvedBy
		);
	}

	public function getId(): int {
		return $this->id;
	}

	public function getCorrelationId(): string {
		return $this->correlationId;
	}

	public function getState(): DeploymentState {
		return $this->state;
	}

	public function getRequest(): DeploymentRequest {
		return $this->request;
	}

	public function getOutcome(): ?DeploymentOutcome {
		return $this->outcome;
	}

	public function requiresOperatorResolution(): bool {
		return $this->state->requiresOperatorResolution() && null === $this->resolvedAt;
	}

	/** @return array<string, int|string|null> */
	public function logContext(): array {
		return array(
			'attempt_id'              => $this->id,
			'operation'               => $this->operation,
			'package_slug'            => $this->packageSlug,
			'package_source'          => $this->packageSource,
			'package_source_revision' => $this->packageSourceRevision,
			'provider'                => $this->provider,
			'source'                  => $this->source,
			'state'                   => $this->state->value,
		);
	}

	/** @return array<string, bool|int|string|null> */
	public function safeData(): array {
		return array(
			'id'                      => $this->id,
			'correlation_id'          => $this->correlationId,
			'source'                  => $this->source,
			'operation'               => $this->operation,
			'package_type'            => $this->packageType,
			'package_slug'            => $this->packageSlug,
			'package_source'          => $this->packageSource,
			'package_source_revision' => $this->packageSourceRevision,
			'provider'                => $this->provider,
			'provider_repository_id'  => $this->providerRepositoryId,
			'requested_ref'           => $this->requestedRef,
			'resolved_ref'            => $this->resolvedRef,
			'delivery_id'             => $this->deliveryId,
			'state'                   => $this->state->value,
			'mutation_started_at'     => $this->mutationStartedAt,
			'outcome_code'            => $this->outcome?->getCode(),
			'created_at'              => $this->createdAt,
			'finished_at'             => $this->finishedAt,
			'resolved_at'             => $this->resolvedAt,
			'resolved_by'             => $this->resolvedBy,
		);
	}

	private static function positiveInt( mixed $value ): int {
		if ( ! is_numeric( $value ) || (int) $value < 1 || (string) (int) $value !== (string) $value ) {
			throw new InvalidArgumentException( 'The deployment attempt ID is invalid.' );
		}

		return (int) $value;
	}

	private static function nonNegativeInt( mixed $value ): int {
		if ( ! is_numeric( $value ) || (int) $value < 0 || (string) (int) $value !== (string) $value ) {
			throw new InvalidArgumentException( 'The package source revision is invalid.' );
		}

		return (int) $value;
	}

	private static function nullablePositiveInt( mixed $value ): ?int {
		return null === $value ? null : self::positiveInt( $value );
	}

	private static function oneOf( mixed $value, array $allowed ): string {
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

	private static function nullableIdentifier( mixed $value, int $limit ): ?string {
		return null === $value ? null : self::identifier( $value, $limit );
	}

	private static function safeText( mixed $value, int $limit ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $limit || preg_match( '//u', $value ) !== 1
			|| preg_match( '/[[:cntrl:]]/', $value ) === 1
			|| preg_match( '/(?:https?:\/\/|[A-Za-z][A-Za-z0-9+.-]*:\/\/)[^\s]*@/i', $value ) === 1
			|| preg_match( '/\b(?:authorization|bearer|token|secret|password|signature)\b\s*[:=]/i', $value ) === 1 ) {
			throw new InvalidArgumentException( 'A stored deployment field is invalid.' );
		}

		return $value;
	}

	private static function nullableSafeText( mixed $value, int $limit ): ?string {
		return null === $value ? null : self::safeText( $value, $limit );
	}

	private static function hex( mixed $value, int $length ): string {
		if ( ! is_string( $value ) || preg_match( sprintf( '/^[a-f0-9]{%d}$/D', $length ), $value ) !== 1 ) {
			throw new InvalidArgumentException( 'A stored deployment digest is invalid.' );
		}

		return $value;
	}

	private static function nullableHex( mixed $value, int $length ): ?string {
		return null === $value ? null : self::hex( $value, $length );
	}

	private static function date( mixed $value ): string {
		if ( ! is_string( $value ) || preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) !== 1 ) {
			throw new InvalidArgumentException( 'A stored deployment time is invalid.' );
		}

		return $value;
	}

	private static function nullableDate( mixed $value ): ?string {
		return null === $value ? null : self::date( $value );
	}
}
