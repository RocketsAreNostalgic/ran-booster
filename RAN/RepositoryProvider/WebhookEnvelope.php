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
		private array $events = array()
	) {
	}

	public static function probe(): self {
		return new self( self::PROBE );
	}

	public static function ignored(): self {
		return new self( self::IGNORED );
	}

	public static function events( PushEvent ...$events ): self {
		if ( array() === $events ) {
			throw new InvalidArgumentException( 'An event envelope requires at least one push event.' );
		}

		return new self( self::EVENTS, $events );
	}

	public function isProbe(): bool {
		return self::PROBE === $this->type;
	}

	public function isIgnored(): bool {
		return self::IGNORED === $this->type;
	}

	public function hasEvents(): bool {
		return self::EVENTS === $this->type;
	}

	/**
	 * @return list<PushEvent>
	 */
	public function getEvents(): array {
		return $this->events;
	}
}
