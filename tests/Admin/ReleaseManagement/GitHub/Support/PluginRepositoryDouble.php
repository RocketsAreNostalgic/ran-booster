<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\GitHub\Support;

use RAN\Storage\PluginRepository;
use RuntimeException;

final class PluginRepositoryDouble extends PluginRepository {
	public int $reads = 0;

	/** @var list<string> */
	public array $identifiers = array();

	public function __construct(
		private readonly string $providerCode = 'gh',
		private readonly int $sourceRevision = 3,
		private readonly bool $missing = false,
		private readonly string $repositoryId = '101',
		private readonly string $repository = 'example/example'
	) {
		parent::__construct();
	}

	public function boosterPluginFromFile( $file ): object {
		++$this->reads;
		$this->identifiers[] = (string) $file;
		if ( $this->missing ) {
			throw new RuntimeException( 'missing-package' );
		}

		return new class( $this->providerCode, $this->sourceRevision, (string) $file, $this->repositoryId, $this->repository ) {
			public function __construct( private readonly string $providerCode, private readonly int $sourceRevision, private readonly string $identifier, private readonly string $repositoryId, private readonly string $repository ) {
			}
			public function getIdentifier(): string {
				return $this->identifier;
			}
			public function getProviderCode(): string {
				return $this->providerCode;
			}
			public function getProviderRepositoryId(): string {
				return $this->repositoryId;
			}
			public function getRepository(): string {
				return $this->repository;
			}
			public function getSourceRevision(): int {
				return $this->sourceRevision;
			}
		};
	}
}
