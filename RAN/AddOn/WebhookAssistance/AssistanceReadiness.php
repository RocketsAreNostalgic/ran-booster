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
	 * @param list<string>        $siteReasonCodes
	 * @param list<array<string, mixed>> $repositories
	 */
	public function __construct(
		private array $siteReasonCodes,
		private string $callbackUrl,
		private array $repositories
	) {
	}

	/**
	 * @return array{
	 *     site: array{status: string, reason_codes: list<string>, callback_url: string},
	 *     repositories: list<array<string, mixed>>
	 * }
	 */
	public function toArray(): array {
		return array(
			'site'         => array(
				'status'       => array() === $this->siteReasonCodes ? self::READY : self::BLOCKED,
				'reason_codes' => $this->siteReasonCodes,
				'callback_url' => $this->callbackUrl,
			),
			'repositories' => $this->repositories,
		);
	}
}
