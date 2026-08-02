<?php

declare(strict_types=1);

namespace RAN\WordPress;

/**
 * The bounded result of asking WordPress core to mutate one package.
 */
final readonly class CorePackageExecutionResult {

	private function __construct( private ?CorePackageExecutionFailure $failure ) {}

	public static function succeeded(): self {
		return new self( null );
	}

	public static function failed( CorePackageExecutionFailure $failure ): self {
		return new self( $failure );
	}

	public function isSuccessful(): bool {
		return null === $this->failure;
	}

	public function wasRestoredByWordPress(): bool {
		return CorePackageExecutionFailure::WORDPRESS_RESTORED === $this->failure;
	}

	public function getFailure(): ?CorePackageExecutionFailure {
		return $this->failure;
	}
}
