<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

/** Display-safe site and repository readiness for assisted webhook setup. */
final readonly class AssistanceReadiness {

	public const READY   = 'ready';
	public const BLOCKED = 'blocked';

	public const SECRET_REPOSITORY = 'repository';
	public const SECRET_SHARED     = 'shared';
	public const SECRET_NONE       = 'none';
	public const SECRET_UNKNOWN    = 'unknown';

	/**
	 * @param list<string>        $site_reason_codes
	 * @param list<array<string, mixed>> $repositories
	 */
	public function __construct(
		private array $site_reason_codes,
		private string $callback_url,
		private array $repositories
	) {
	}

	/**
	 * @return array{
	 *     site: array{status: string, reason_codes: list<string>, callback_url: string},
	 *     repositories: list<array<string, mixed>>
	 * }
	 */
	public function to_array(): array {
		return array(
			'site'         => array(
				'status'       => array() === $this->site_reason_codes ? self::READY : self::BLOCKED,
				'reason_codes' => $this->site_reason_codes,
				'callback_url' => $this->callback_url,
			),
			'repositories' => $this->repositories,
		);
	}
}
