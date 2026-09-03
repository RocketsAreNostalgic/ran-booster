<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use RAN\WordPress\CoreSelfUpdatePolicy;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use Throwable;

/**
 * Presents bounded passive Core updater state without initiating discovery.
 */
final class CoreSelfUpdateStatus {

	public function __construct(
		private readonly CoreSelfUpdatePolicy $policy,
		private readonly ?RepositoryReleaseNativeTarget $target
	) {
	}

	/**
	 * @return array<string, int|string|null>
	 */
	public function diagnostics(): array {
		$status             = $this->policy->diagnostics();
		$updaterDiagnostics = $this->targetDiagnostics();

		return array_merge(
			$status,
			array(
				'updater_state'    => $this->safeKey( $updaterDiagnostics['state'] ?? null ),
				'updater_code'     => $this->safeKey( $updaterDiagnostics['code'] ?? null ),
				'selected_version' => $this->safeVersion( $updaterDiagnostics['selected_version'] ?? null ),
				'offered_version'  => $this->safeVersion( $updaterDiagnostics['offered_version'] ?? null ),
				'last_check'       => $this->safeTimestamp( $updaterDiagnostics['last_check'] ?? null ),
				'next_check'       => $this->safeTimestamp( $updaterDiagnostics['next_check'] ?? null ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function targetDiagnostics(): array {
		if ( null === $this->target ) {
			return $this->policy->allowsNativeDiscovery()
				? array()
				: array(
					'state' => 'inactive',
					'code'  => 'native_discovery_disabled',
				);
		}

		try {
			$status = $this->target->status();
		} catch ( Throwable ) {
			return array(
				'state' => 'inactive',
				'code'  => 'diagnostics_unavailable',
			);
		}

		return array(
			'state'           => $status->active ? 'active' : 'inactive',
			'code'            => $status->failureCode,
			'offered_version' => $status->offeredVersion,
			'last_check'      => $status->lastCheck,
			'next_check'      => $status->nextCheck,
		);
	}

	private function safeKey( mixed $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '/\A[a-z0-9_-]{1,80}\z/D', $value )
			? $value
			: null;
	}

	private function safeVersion( mixed $value ): ?string {
		return is_string( $value )
			&& 1 === preg_match( '/\A[0-9A-Za-z][0-9A-Za-z.+-]{0,79}\z/D', $value )
				? $value
				: null;
	}

	private function safeTimestamp( mixed $value ): ?int {
		return is_int( $value ) && 0 < $value ? $value : null;
	}
}
