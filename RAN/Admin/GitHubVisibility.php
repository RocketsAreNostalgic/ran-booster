<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Settings\GeneralPreferenceStore;
use RuntimeException;

/**
 * Fixed local policy for suppressing ordinary GitHub suggestions.
 *
 * This class deliberately receives local facts from the Core composition root:
 * it never constructs a provider, reads credentials, or performs remote I/O.
 */
final class GitHubVisibility {

	private object $connection;

	public function __construct(
		private readonly GeneralPreferenceStore $preferences,
		private readonly ?ProviderRegistry $providers = null,
		private readonly ?GitHubProviderSelection $github_selection = null,
		?object $connection = null
	) {
		global $wpdb;
		$this->connection = $connection ?? $wpdb;
	}

	/**
	 * Return the single local decision used by ordinary navigation and Save.
	 *
	 * @return array{hidden:bool,eligible:bool,reason:string}
	 */
	public function projection(): array {
		$requested = $this->hide_requested();
		$reason    = $this->eligibility_reason();
		$eligible  = 'eligible' === $reason;

		return array(
			'hidden'   => $requested && $eligible,
			'eligible' => $eligible,
			'reason'   => $eligible ? ( $requested ? 'hidden' : 'not_requested' ) : $reason,
		);
	}

	/** Core-only navigation decision; provider authority and route resolution stay intact. */
	public function hidden_navigation_key(): ?string {
		return $this->projection()['hidden'] ? 'gh' : null;
	}

	public function github_hide_eligible(): bool {
		return $this->projection()['eligible'];
	}

	/**
	 * Serialize a General Save with every GitHub management admission. The
	 * existing provider_identity index supplies the locked gh range, including
	 * its insertion gap. The caller owns the surrounding transaction.
	 *
	 * @template T
	 * @param callable():T $operation
	 * @return T
	 */
	public function with_management_admission_lock( callable $operation ): mixed {
		if ( ! is_callable( array( $this->connection, 'prepare' ) )
			|| ! is_callable( array( $this->connection, 'get_results' ) ) ) {
			throw new RuntimeException( 'GitHub management admission is unavailable.' );
		}

		$prepare = \Closure::fromCallable( array( $this->connection, 'prepare' ) );
		$query   = $prepare(
			'SELECT id FROM %i WHERE provider = %s FOR UPDATE',
			ran_booster_table_name(),
			'gh'
		);
		if ( ! is_string( $query ) ) {
			throw new RuntimeException( 'GitHub management admission is unavailable.' );
		}
		$this->clear_last_error();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared provider-index range lock is the fixed serialization boundary.
		$rows       = $this->get_results( $query );
		$properties = get_object_vars( $this->connection );
		$error      = isset( $properties['last_error'] ) ? trim( (string) $properties['last_error'] ) : '';
		if ( '' !== $error || ! is_array( $rows ) || count( $rows ) !== count( array_filter( $rows, 'is_object' ) ) ) {
			throw new RuntimeException( 'GitHub management admission is unavailable.' );
		}

		return $operation();
	}

	/**
	 * Called only after a GitHub management row was verified, before its package
	 * transaction commits. A successful no-op returns true for an absent or false
	 * preference and must never seed a constant-only installation; false means the
	 * preference could not be safely read or cleared.
	 */
	public function confirmed_management(): bool {
		return $this->preferences->clear_confirmed_github_hide_request();
	}

	private function hide_requested(): bool {
		if ( defined( 'RAN_BOOSTER_HIDE_GITHUB_UI' ) ) {
			return true === constant( 'RAN_BOOSTER_HIDE_GITHUB_UI' );
		}
		try {
			$snapshot = $this->preferences->view_snapshot();
			$field    = $snapshot['fields']['hide_github'] ?? null;
			return is_array( $field ) && null === ( $field['error'] ?? null ) && true === ( $field['value'] ?? false );
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function eligibility_reason(): string {
		if ( null === $this->providers || ! $this->providers->is_sealed() ) {
			return 'provider_registry_unknown';
		}
		$other_provider = false;
		foreach ( $this->providers->metadata() as $metadata ) {
			if ( 'gh' !== $metadata->code->value ) {
				$other_provider = true;
				break;
			}
		}
		if ( ! $other_provider ) {
			return 'no_other_provider';
		}
		if ( null === $this->github_selection ) {
			return 'github_selection_unknown';
		}
		$status     = $this->github_selection->status();
		$state      = $status['state'] ?? null;
		$candidates = $status['candidates'] ?? null;
		if ( ! is_array( $candidates ) || 'available' !== $state ) {
			return 'github_selection_unknown';
		}
		if ( array() !== $candidates ) {
			return 'active_github_replacement';
		}
		$empty = $this->github_management_is_empty();
		if ( null === $empty ) {
			return 'github_management_unknown';
		}
		return $empty ? 'eligible' : 'github_management_present';
	}

	private function github_management_is_empty(): ?bool {
		if ( ! is_callable( array( $this->connection, 'prepare' ) ) || ! is_callable( array( $this->connection, 'get_results' ) ) ) {
			return null;
		}
		$prepare = \Closure::fromCallable( array( $this->connection, 'prepare' ) );
		$query   = $prepare(
			'SELECT id FROM %i WHERE provider = %s LIMIT 1',
			ran_booster_table_name(),
			'gh'
		);
		if ( ! is_string( $query ) ) {
			return null;
		}
		$this->clear_last_error();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixed provider-index inventory includes every package state without hydration or cleanup.
		$rows       = $this->get_results( $query );
		$properties = get_object_vars( $this->connection );
		$error      = isset( $properties['last_error'] ) ? trim( (string) $properties['last_error'] ) : '';
		if ( '' !== $error || ! is_array( $rows ) || count( $rows ) !== count( array_filter( $rows, 'is_object' ) ) ) {
			return null;
		}
		return array() === $rows;
	}

	private function clear_last_error(): void {
		if ( property_exists( $this->connection, 'last_error' ) ) {
			( new \ReflectionProperty( $this->connection, 'last_error' ) )->setValue( $this->connection, '' );
		}
	}

	private function get_results( string $query ): mixed {
		return ( new \ReflectionMethod( $this->connection, 'get_results' ) )->invoke( $this->connection, $query );
	}
}
