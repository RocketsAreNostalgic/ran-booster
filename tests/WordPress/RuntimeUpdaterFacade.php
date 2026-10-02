<?php

declare(strict_types=1);

namespace Tests\WordPress;

use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;

final class RuntimeUpdaterFacade implements RepositoryReleaseNativeTarget {

	private bool $registered            = false;
	private bool $diagnostics_fail      = false;
	private int $refreshes              = 0;
	private bool $refresh_succeeds      = true;
	private bool $registration_succeeds = true;
	private bool $refresh_fails         = false;

	/** @param array<string, mixed> $target @param array<string, mixed> $diagnostics */
	public function __construct(
		private array $target = array(),
		private array $diagnostics = array()
	) {
	}

	public function register(): bool {
		$this->registered = true;

		return $this->registration_succeeds;
	}

	public function refresh(): bool {
		if ( $this->refresh_fails ) {
			throw new \RuntimeException( 'refresh failed' );
		}
		++$this->refreshes;

		return $this->refresh_succeeds;
	}

	public function fail_registration(): void {
		$this->registration_succeeds = false;
	}

	public function reject_refresh(): void {
		$this->refresh_succeeds = false;
	}

	public function fail_refresh(): void {
		$this->refresh_fails = true;
	}

	public function refreshes(): int {
		return $this->refreshes;
	}

	/** @param array<string, mixed> $diagnostics */
	public function replace_diagnostics( array $diagnostics ): void {
		$this->diagnostics = $diagnostics;
	}

	public function fail_diagnostics(): void {
		$this->diagnostics_fail = true;
	}

	/** @return array<string, mixed> */
	public function diagnostics(): array {
		if ( $this->diagnostics_fail ) {
			throw new \RuntimeException( 'diagnostics failed' );
		}

		return array( 'registered' => $this->registered )
			+ $this->diagnostics
			+ array(
				'selection_fixed'  => true,
				'selected_version' => 'test-runtime',
				'state'            => 'idle',
			)
			+ $this->target;
	}

	public function status(): RepositoryReleaseNativeTargetStatus {
		$diagnostics = $this->diagnostics();
		$validation  = is_array( $diagnostics['candidate_validation'] ?? null )
			? $diagnostics['candidate_validation']
			: array();
		$state       = $diagnostics['state'] ?? null;

		return new RepositoryReleaseNativeTargetStatus(
			true === ( $diagnostics['registered'] ?? null )
				&& true === ( $diagnostics['selection_fixed'] ?? null )
				&& is_string( $diagnostics['selected_version'] ?? null )
				&& '' !== $diagnostics['selected_version']
				&& is_string( $state )
				&& '' !== $state
				&& 'inactive' !== $state,
			is_string( $diagnostics['offered_version'] ?? null ) ? $diagnostics['offered_version'] : '',
			is_string( $diagnostics['version_relationship'] ?? null ) ? $diagnostics['version_relationship'] : '',
			is_int( $diagnostics['last_check'] ?? null ) ? $diagnostics['last_check'] : null,
			is_int( $diagnostics['next_check'] ?? null ) ? $diagnostics['next_check'] : null,
			in_array( $state, array( 'error', 'failed' ), true ) && is_string( $diagnostics['code'] ?? null ) ? $diagnostics['code'] : '',
			is_string( $validation['code'] ?? null ) ? $validation['code'] : '',
			is_string( $validation['release_tag'] ?? null ) ? $validation['release_tag'] : '',
			is_string( $validation['release_version'] ?? null ) ? $validation['release_version'] : '',
			is_string( $validation['package_header_version'] ?? null ) ? $validation['package_header_version'] : ''
		);
	}
}
