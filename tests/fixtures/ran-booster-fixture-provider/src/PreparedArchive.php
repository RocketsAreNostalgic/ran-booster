<?php

declare(strict_types=1);

namespace RAN_Booster_FixtureProvider;

use Closure;
use RAN\RepositoryProvider\PreparedArchive as PreparedArchiveContract;

final readonly class PreparedArchive implements PreparedArchiveContract {

	public function __construct(
		private string $url,
		private string $resolved_ref,
		private ?Closure $head_verifier = null
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
