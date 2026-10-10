<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement\Support;

final readonly class PackageProjection implements \RAN\Admin\PackageDisplayProjection {
	public function __construct(
		private string $source_value = 'branch',
		private string $type_value = 'plugin',
		private int $revision_value = 3,
		private string $subdirectory_value = ''
	) {
	}

	public function type(): string {
		return $this->type_value;
	}

	public function provider_code(): string {
		return 'gh';
	}

	public function identifier(): string {
		return 'theme' === $this->type_value ? 'example-theme' : 'example/example.php';
	}

	public function display_name(): string {
		return 'theme' === $this->type_value ? 'Example Theme' : 'Example Plugin';
	}

	public function source(): string {
		return $this->source_value;
	}

	public function source_revision(): int {
		return $this->revision_value;
	}

	public function subdirectory(): string {
		return $this->subdirectory_value;
	}

	public function settings_url(): string {
		return 'https://example.test/wp-admin/admin.php?page='
			. ( 'theme' === $this->type_value ? 'ran-booster-themes' : 'ran-booster-plugins' )
			. '&package=' . rawurlencode( $this->identifier() );
	}
}
