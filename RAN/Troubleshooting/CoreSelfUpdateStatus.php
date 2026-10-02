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
		$status              = $this->policy->diagnostics();
		$updater_diagnostics = $this->target_diagnostics();

		return array_merge(
			$status,
			array(
				'updater_state'    => $this->safe_key( $updater_diagnostics['state'] ?? null ),
				'updater_code'     => $this->safe_key( $updater_diagnostics['code'] ?? null ),
				'selected_version' => $this->safe_version( $updater_diagnostics['selected_version'] ?? null ),
				'offered_version'  => $this->safe_version( $updater_diagnostics['offered_version'] ?? null ),
				'last_check'       => $this->safe_timestamp( $updater_diagnostics['last_check'] ?? null ),
				'next_check'       => $this->safe_timestamp( $updater_diagnostics['next_check'] ?? null ),
			)
		);
	}

	/** @return array<string, mixed> */
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
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Connected native-target status contract retains its property names.
			'code'            => $status->failure_code,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Connected native-target status contract retains its property names.
			'offered_version' => $status->offered_version,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Connected native-target status contract retains its property names.
			'last_check'      => $status->last_check,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Connected native-target status contract retains its property names.
			'next_check'      => $status->next_check,
		);
	}

	private function safe_key( mixed $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '/\A[a-z0-9_-]{1,80}\z/D', $value )
			? $value
			: null;
	}

	private function safe_version( mixed $value ): ?string {
		return is_string( $value )
			&& 1 === preg_match( '/\A[0-9A-Za-z][0-9A-Za-z.+-]{0,79}\z/D', $value )
				? $value
				: null;
	}

	private function safe_timestamp( mixed $value ): ?int {
		return is_int( $value ) && 0 < $value ? $value : null;
	}
}
