<?php

declare(strict_types=1);

namespace RANBoosterFixtureProvider;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;

final class Client {

	private int $requests = 0;
	/** @var array<string, string> */
	private array $branchHeads = array();
	/** @var list<float> */
	private array $diagnosticTimeouts = array();

	public function checkPublicAccess( float $timeout ): void {
		$this->recordDiagnosticTimeout( $timeout );
		++$this->requests;
	}

	/**
	 * @return array{locator: string, package_slug: string, provider_repository_id: string, default_branch: string}
	 */
	public function repository( string $locator, ?float $timeout = null ): array {
		if ( null !== $timeout ) {
			$this->recordDiagnosticTimeout( $timeout );
		}
		++$this->requests;
		$segments = explode( '/', $locator );
		$slug     = (string) end( $segments );

		return array(
			'locator'                => $locator,
			'package_slug'           => $slug,
			'provider_repository_id' => 'fixture:' . hash( 'sha256', $locator ),
			'default_branch'         => 'main',
		);
	}

	/** @param array<string, mixed>|null $material */
	public function validateCredential( ?array $material, ?float $timeout = null ): bool {
		if ( null !== $timeout ) {
			$this->recordDiagnosticTimeout( $timeout );
		}
		++$this->requests;

		return is_array( $material )
			&& 'api-key' === ( $material['kind'] ?? null )
			&& is_string( $material['secret'] ?? null )
			&& '' !== trim( $material['secret'] );
	}

	public function getRequestCount(): int {
		return $this->requests;
	}

	public function resolveRef( string $locator, string $ref ): string {
		++$this->requests;
		if ( 1 === preg_match( '/^[0-9a-f]{40}$/i', $ref ) ) {
			return strtolower( $ref );
		}

		return sha1( $locator . "\0" . $ref );
	}

	public function branchHead( string $locator, string $branch ): string {
		++$this->requests;

		return $this->branchHeads[ $branch ] ?? sha1( $locator . "\0" . $branch );
	}

	public function setBranchHead( string $branch, string $commit ): void {
		$this->branchHeads[ $branch ] = strtolower( $commit );
	}

	/** @return list<float> */
	public function getDiagnosticTimeouts(): array {
		return $this->diagnosticTimeouts;
	}

	private function recordDiagnosticTimeout( float $timeout ): void {
		if ( $timeout <= 0.0 || $timeout > ProviderDiagnosticRequest::MAX_SECONDS ) {
			throw new InvalidArgumentException( 'The fixture diagnostic timeout is outside the provider contract.' );
		}

		$this->diagnosticTimeouts[] = $timeout;
	}
}
