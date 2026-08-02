<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryDescriptor;

final class ExternalFixtureClient {

	private int $requests = 0;
	/** @var array<string, string> */
	private array $branchHeads = array();

	public function __construct( private readonly ProviderCode $code ) {
	}

	public function checkPublicAccess(): void {
		++$this->requests;
	}

	public function repository( string $locator ): RepositoryDescriptor {
		++$this->requests;
		$parts = explode( '/', $locator );

		return new RepositoryDescriptor(
			$this->code,
			$locator,
			(string) end( $parts ),
			'fixture:' . $locator,
			false,
			'main',
			null
		);
	}

	public function getRequests(): int {
		return $this->requests;
	}

	public function resolveRef( string $locator, string $ref ): string {
		++$this->requests;

		return 1 === preg_match( '/^[0-9a-f]{40}$/i', $ref )
			? strtolower( $ref )
			: sha1( $locator . "\0" . $ref );
	}

	public function branchHead( string $locator, string $branch ): string {
		++$this->requests;

		return $this->branchHeads[ $branch ] ?? sha1( $locator . "\0" . $branch );
	}

	public function setBranchHead( string $branch, string $commit ): void {
		$this->branchHeads[ $branch ] = strtolower( $commit );
	}
}
