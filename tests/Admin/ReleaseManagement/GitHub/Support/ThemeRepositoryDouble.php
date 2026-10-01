<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\GitHub\Support;

use RAN\Storage\ThemeRepository;
use RuntimeException;

final class ThemeRepositoryDouble extends ThemeRepository {
	public int $reads = 0;

	/** @var list<string> */
	public array $identifiers = array();

	public function __construct(
		private readonly string $providerCode = 'gh',
		private readonly int $sourceRevision = 3,
		private readonly bool $missing = false,
		private readonly string $repositoryId = '101',
		private readonly string $repository = 'example/example',
		private readonly bool $private = false
	) {
		parent::__construct();
	}

	public function booster_theme_from_stylesheet( $stylesheet ): object {
		++$this->reads;
		$this->identifiers[] = (string) $stylesheet;
		if ( $this->missing ) {
			throw new RuntimeException( 'missing-package' );
		}

		return new class( $this->providerCode, $this->sourceRevision, (string) $stylesheet, $this->repositoryId, $this->repository, $this->private ) {
			public function __construct( private readonly string $providerCode, private readonly int $sourceRevision, private readonly string $identifier, private readonly string $repositoryId, private readonly string $repository, private readonly bool $private ) {
			}
			public function get_identifier(): string {
				return $this->identifier;
			}
			public function get_provider_code(): string {
				return $this->providerCode;
			}
			public function get_provider_repository_id(): string {
				return $this->repositoryId;
			}
			public function get_repository(): string {
				return $this->repository;
			}
			public function get_source_revision(): int {
				return $this->sourceRevision;
			}
			public function is_private(): bool {
				return $this->private;
			}
		};
	}
}
