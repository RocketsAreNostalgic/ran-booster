<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use RAN\WordPress\CoreSelfUpdatePolicy;
use Throwable;

/**
 * Presents bounded passive Core updater state without initiating discovery.
 */
final class CoreSelfUpdateStatus {

	public function __construct(
		private readonly CoreSelfUpdatePolicy $policy,
		private readonly ?object $updater
	) {
	}

	/**
	 * @return array<string, int|string|null>
	 */
	public function diagnostics(): array {
		$status             = $this->policy->diagnostics();
		$updaterDiagnostics = $this->updaterDiagnostics();

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
	private function updaterDiagnostics(): array {
		if ( null === $this->updater || ! is_callable( array( $this->updater, 'diagnostics' ) ) ) {
			return array();
		}

		try {
			$diagnostics = $this->updater->diagnostics();
		} catch ( Throwable ) {
			return array(
				'state' => 'inactive',
				'code'  => 'diagnostics_unavailable',
			);
		}

		return is_array( $diagnostics ) ? $diagnostics : array();
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
