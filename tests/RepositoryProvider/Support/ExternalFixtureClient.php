<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryDescriptor;

final class ExternalFixtureClient {

	private int $requests = 0;
	/** @var array<string, string> */
	private array $branch_heads = array();

	public function __construct( private readonly ProviderCode $code ) {
	}

	public function check_public_access(): void {
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

	public function get_requests(): int {
		return $this->requests;
	}

	public function resolve_ref( string $locator, string $ref ): string {
		++$this->requests;

		return 1 === preg_match( '/^[0-9a-f]{40}$/i', $ref )
			? strtolower( $ref )
			: sha1( $locator . "\0" . $ref );
	}

	public function branch_head( string $locator, string $branch ): string {
		++$this->requests;

		return $this->branch_heads[ $branch ] ?? sha1( $locator . "\0" . $branch );
	}

	public function set_branch_head( string $branch, string $commit ): void {
		$this->branch_heads[ $branch ] = strtolower( $commit );
	}
}
