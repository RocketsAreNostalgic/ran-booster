<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use RAN\WordPress\CoreSelfUpdatePolicy;
use RAN\WordPress\CoreSelfUpdateCheck;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use Throwable;

/**
 * Presents bounded passive Core updater state without initiating discovery.
 */
final class CoreSelfUpdateStatus {

	public function __construct(
		private readonly CoreSelfUpdatePolicy $policy,
		private readonly ?RepositoryReleaseNativeTarget $target,
		private readonly ?CoreSelfUpdateCheck $check = null
	) {
	}

	public function can_check(): bool {
		return null !== $this->check && $this->policy->allows_native_discovery();
	}

	/** @return array{outcome:string,code:string} */
	public function check(): array {
		return null !== $this->check ? $this->check->check() : array(
			'outcome' => 'failed',
			'code'    => 'target_unavailable',
		);
	}

	/**
	 * @return array<string, int|string|null>
	 */
	public function diagnostics(): array {
		$status              = $this->policy->diagnostics();
		$updater_diagnostics = $this->target_diagnostics();
		$check_diagnostics   = $this->check_diagnostics();

		return array_merge(
			$status,
			array(
				'updater_state'         => $updater_diagnostics['state'] ?? null,
				'updater_code'          => '' === ( $updater_diagnostics['code'] ?? '' ) ? null : $updater_diagnostics['code'],
				'offered_version'       => $this->safe_version( $updater_diagnostics['offered_version'] ?? null ),
				'last_check'            => $updater_diagnostics['last_check'] ?? null,
				'next_check'            => $updater_diagnostics['next_check'] ?? null,
				'last_successful_check' => $check_diagnostics['last_successful_check'],
				'last_check_outcome'    => $check_diagnostics['last_outcome'],
				'last_check_code'       => $check_diagnostics['last_code'],
				'check_cooldown_until'  => $check_diagnostics['cooldown_until'],
			)
		);
	}

	/** @return array{last_successful_check:?int,last_outcome:?string,last_code:?string,cooldown_until:?int} */
	private function check_diagnostics(): array {
		if ( null === $this->check ) {
			return array(
				'last_successful_check' => null,
				'last_outcome'          => null,
				'last_code'             => null,
				'cooldown_until'        => null,
			);
		}
		try {
			return $this->check->diagnostics();
		} catch ( Throwable ) {
			return array(
				'last_successful_check' => null,
				'last_outcome'          => null,
				'last_code'             => null,
				'cooldown_until'        => null,
			);
		}
	}

	/** @return array{state?:string,code?:string,offered_version?:string,last_check?:?int,next_check?:?int} */
	private function target_diagnostics(): array {
		if ( null === $this->target ) {
			return $this->policy->allows_native_discovery()
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

			'code'            => $status->failure_code,

			'offered_version' => $status->offered_version,

			'last_check'      => $status->last_check,

			'next_check'      => $status->next_check,
		);
	}

	private function safe_version( mixed $value ): ?string {
		return is_string( $value )
			&& 1 === preg_match( '/\A[0-9A-Za-z][0-9A-Za-z.+-]{0,79}\z/D', $value )
				? $value
				: null;
	}
}
