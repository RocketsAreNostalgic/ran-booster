<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

final readonly class WebhookEnvelope {

	private const PROBE   = 'probe';
	private const IGNORED = 'ignored';
	private const EVENTS  = 'events';

	/**
	 * @param list<PushEvent> $events Normalized push events.
	 */
	private function __construct(
		private string $type,
		private array $events = array(),
		private ?string $reason = null,
		private ?string $repository_id = null
	) {
	}

	/** @param string|null $repository_id The repository the provider's probe payload names, if any. */
	public static function probe( ?string $repository_id = null ): self {
		return new self( self::PROBE, array(), null, null === $repository_id || '' === trim( $repository_id ) ? null : $repository_id );
	}

	public static function ignored( ?string $reason = null ): self {
		return new self( self::IGNORED, array(), in_array( $reason, WebhookRejected::REASONS, true ) ? $reason : null );
	}

	public static function events( PushEvent ...$events ): self {
		if ( array() === $events ) {
			throw new InvalidArgumentException( 'An event envelope requires at least one push event.' );
		}

		return new self( self::EVENTS, array_values( $events ) );
	}

	public function get_reason(): ?string {
		return $this->reason;
	}

	public function get_repository_id(): ?string {
		return $this->repository_id;
	}

	public function is_probe(): bool {
		return self::PROBE === $this->type;
	}

	public function is_ignored(): bool {
		return self::IGNORED === $this->type;
	}

	public function has_events(): bool {
		return self::EVENTS === $this->type;
	}

	/**
	 * @return list<PushEvent>
	 */
	public function get_events(): array {
		return $this->events;
	}
}
