<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final class ProviderDiagnosticBudgetExceeded extends RuntimeException {
	public const REMOTE_CALLS = 'remote_calls';
	public const DEADLINE     = 'deadline';

	private function __construct( string $message, private readonly string $reason ) {
		parent::__construct( $message );
	}

	public static function remoteCalls(): self {
		return new self( 'The provider diagnostic remote-call budget is exhausted.', self::REMOTE_CALLS );
	}

	public static function deadline(): self {
		return new self( 'The provider diagnostic deadline is exhausted.', self::DEADLINE );
	}

	public function getReason(): string {
		return $this->reason;
	}
}
