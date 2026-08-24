<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\Support;

final readonly class PackageProjection {
	public function __construct(
		private string $sourceValue = 'branch',
		private string $typeValue = 'plugin',
		private int $revisionValue = 3,
		private string $providerCodeValue = 'gh'
	) {
	}

	public function type(): string {
		return $this->typeValue;
	}

	public function identifier(): string {
		return 'theme' === $this->typeValue ? 'example-theme' : 'example/example.php';
	}

	public function displayName(): string {
		return 'theme' === $this->typeValue ? 'Example Theme' : 'Example Plugin';
	}

	public function providerCode(): string {
		return $this->providerCodeValue;
	}

	public function source(): string {
		return $this->sourceValue;
	}

	public function sourceRevision(): int {
		return $this->revisionValue;
	}

	public function settingsUrl(): string {
		return 'https://example.test/wp-admin/admin.php?page='
			. ( 'theme' === $this->typeValue ? 'ran-booster-themes' : 'ran-booster-plugins' )
			. '&package=' . rawurlencode( $this->identifier() );
	}
}
