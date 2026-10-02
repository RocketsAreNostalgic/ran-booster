<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use Closure;
use RAN\RepositoryProvider\PreparedArchive;

final class ExternalFixturePreparedArchive implements PreparedArchive {

	public function __construct(
		private readonly string $url,
		private readonly string $resolved_ref,
		private readonly ?Closure $head_verifier = null
	) {
	}

	public function get_url(): string {
		return $this->url;
	}

	public function get_resolved_ref(): string {
		return $this->resolved_ref;
	}

	public function verify_current_head(): void {
		if ( null !== $this->head_verifier ) {
			( $this->head_verifier )();
		}
	}

	public function cleanup(): void {
	}
}
