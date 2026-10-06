<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement\GitHub\Support;

use RAN\Storage\ThemeRepository;
use RuntimeException;

final class ThemeRepositoryDouble extends ThemeRepository {
	public int $reads = 0;

	/** @var list<string> */
	public array $identifiers = array();

	public function __construct(
		private readonly string $provider_code = 'gh',
		private readonly int $source_revision = 3,
		private readonly bool $missing = false,
		private readonly string $repository_id = '101',
		private readonly string $repository = 'example/example',
		private readonly bool $is_private = false
	) {
		parent::__construct();
	}

	/** @return object */
	public function booster_theme_from_stylesheet( $stylesheet ): object {
		++$this->reads;
		$this->identifiers[] = (string) $stylesheet;
		if ( $this->missing ) {
			throw new RuntimeException( 'missing-package' );
		}

		return new class( $this->provider_code, $this->source_revision, (string) $stylesheet, $this->repository_id, $this->repository, $this->is_private ) {
			public function __construct( private readonly string $provider_code, private readonly int $source_revision, private readonly string $identifier, private readonly string $repository_id, private readonly string $repository, private readonly bool $is_private ) {
			}
			public function get_identifier(): string {
				return $this->identifier;
			}
			public function get_provider_code(): string {
				return $this->provider_code;
			}
			public function get_provider_repository_id(): string {
				return $this->repository_id;
			}
			public function get_repository(): string {
				return $this->repository;
			}
			public function get_source_revision(): int {
				return $this->source_revision;
			}
			public function is_private(): bool {
				return $this->is_private;
			}
		};
	}
}
