<?php

declare(strict_types=1);

namespace RAN\WordPress;

use Closure;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use Throwable;

/** Owns the one current, operator-requested Core update-check observation. */
final class CoreSelfUpdateCheck {

	public const OPTION_NAME       = 'ran_booster_core_self_update_check';
	private const COOLDOWN_SECONDS = 300;
	private const CLAIM_SECONDS    = 120;

	/** @var Closure():bool */
	private Closure $allowed;
	/** @var Closure():void */
	private Closure $update_plugins;
	/** @var Closure():int */
	private Closure $clock;

	/**
	 * @param callable():bool $allowed Fresh effective policy, never a bootstrap snapshot.
	 * @param callable():void|null $update_plugins Test-only replacement for WordPress orchestration.
	 * @param callable():int|null $clock Test-only clock.
	 */
	public function __construct(
		private readonly RepositoryReleaseNativeTarget $target,
		callable $allowed,
		private readonly string $plugin_identifier,
		private readonly string $channel,
		?callable $update_plugins = null,
		?callable $clock = null
	) {
		$this->allowed        = Closure::fromCallable( $allowed );
		$this->update_plugins = Closure::fromCallable(
			$update_plugins ?? static function (): void {
				delete_site_transient( 'update_plugins' );
				wp_update_plugins();
			}
		);
		$this->clock          = Closure::fromCallable( $clock ?? static fn (): int => time() );
	}

	/** @return array{outcome:string,code:string} */
	public function check(): array {
		if ( ! $this->allowed() ) {
			return array(
				'outcome' => 'skipped',
				'code'    => 'native_discovery_disabled',
			);
		}
		$now          = ( $this->clock )();
		$claim        = bin2hex( random_bytes( 16 ) );
		$claim_status = $this->claim( $claim, $now );
		if ( 'claimed' !== $claim_status ) {
			return array(
				'outcome' => 'cooldown' === $claim_status ? 'skipped' : 'failed',
				'code'    => 'cooldown' === $claim_status ? 'cooldown_active' : 'claim_unavailable',
			);
		}
		if ( ! $this->allowed() ) {
			return $this->finish( $claim, 'skipped', 'native_discovery_disabled' );
		}

		$check_id    = null;
		$observation = null;
		$begin       = function ( array $payload ) use ( &$check_id ): void {
			if ( null === $check_id && $this->matches_core_start( $payload ) ) {
				$check_id = $payload['check_id'];
			}
		};
		$completed   = function ( array $payload ) use ( &$observation, &$check_id ): void {
			if ( is_string( $check_id ) && $this->matches_core_completion( $payload, $check_id ) ) {
				$observation = $payload;
			}
		};
		add_action( 'ran_wp_release_updater_check_started_v1', $begin, PHP_INT_MAX, 1 );
		add_action( 'ran_wp_release_updater_check_completed_v1', $completed, PHP_INT_MAX, 1 );
		try {
			if ( ! $this->target->refresh() ) {
				return $this->finish( $claim, 'failed', 'refresh_rejected' );
			}
			if ( ! $this->allowed() ) {
				return $this->finish( $claim, 'skipped', 'native_discovery_disabled' );
			}
			( $this->update_plugins )();
		} catch ( Throwable ) {
			return $this->finish( $claim, 'failed', 'orchestration_failed' );
		} finally {
			remove_action( 'ran_wp_release_updater_check_started_v1', $begin, PHP_INT_MAX );
			remove_action( 'ran_wp_release_updater_check_completed_v1', $completed, PHP_INT_MAX );
		}

		if ( ! is_array( $observation ) ) {
			return $this->finish( $claim, 'unknown', 'check_not_observed' );
		}
		$status = $observation['status'] ?? null;
		$reason = $observation['reason'] ?? null;
		if ( ! $this->allowed() ) {
			return $this->finish( $claim, 'skipped', 'native_discovery_disabled' );
		}
		if ( 'succeeded' === $status ) {
			return $this->finish( $claim, 'success', is_string( $reason ) ? $reason : 'check_succeeded' );
		}
		if ( 'rejected' === $status || 'skipped' === $status ) {
			return $this->finish( $claim, $status, is_string( $reason ) ? $reason : 'check_rejected' );
		}
		return $this->finish( $claim, 'failed', is_string( $reason ) ? $reason : 'check_failed' );
	}

	/** @return array{last_successful_check:?int,last_outcome:?string,last_code:?string,cooldown_until:?int} */
	public function diagnostics(): array {
		$state = $this->state();
		return array(
			'last_successful_check' => $this->integer( $state['last_successful_check'] ?? null ),
			'last_outcome'          => $this->text( $state['outcome'] ?? null ),
			'last_code'             => $this->text( $state['code'] ?? null ),
			'cooldown_until'        => $this->integer( $state['cooldown_until'] ?? null ),
		);
	}

	/** @return array{outcome:string,code:string} */
	private function finish( string $claim, string $outcome, string $code ): array {
		$now   = ( $this->clock )();
		$state = $this->state();
		if ( ! hash_equals( $claim, $this->text( $state['claim'] ?? null ) ?? '' ) ) {
			return array(
				'outcome' => 'unknown',
				'code'    => 'claim_lost',
			);
		}
		$next = array(
			'claim'                 => '',
			'claimed_at'            => null,
			'cooldown_until'        => $now + self::COOLDOWN_SECONDS,
			'completed_at'          => $now,
			'outcome'               => $outcome,
			'code'                  => $code,
			'last_successful_check' => 'success' === $outcome ? $now : ( $state['last_successful_check'] ?? null ),
		);
		if ( ! $this->write( $state, $next ) ) {
			return array(
				'outcome' => 'unknown',
				'code'    => 'claim_lost',
			);
		}
		return array(
			'outcome' => $outcome,
			'code'    => $code,
		);
	}

	/** @return 'claimed'|'cooldown'|'failed' */
	private function claim( string $claim, int $now ): string {
		$state = $this->state();
		if ( $this->claim_is_active( $state, $now ) ) {
			return 'cooldown';
		}
		if ( $this->write(
			$state,
			array_merge(
				$state,
				array(
					'claim'          => $claim,
					'claimed_at'     => $now,
					'cooldown_until' => null,
					'completed_at'   => null,
					'outcome'        => null,
					'code'           => null,
				)
			)
		) ) {
			return 'claimed';
		}
		return $this->claim_is_active( $this->state(), $now ) ? 'cooldown' : 'failed';
	}

	/** @param array<string, int|string|null> $state */
	private function claim_is_active( array $state, int $now ): bool {
		return ( $state['cooldown_until'] ?? 0 ) > $now || ( $state['claimed_at'] ?? 0 ) > $now - self::CLAIM_SECONDS;
	}

	/** @return array<string, int|string|null> */
	private function state(): array {
		$value = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}
		$state = array();
		foreach ( array( 'claimed_at', 'cooldown_until', 'completed_at', 'last_successful_check' ) as $key ) {
			if ( is_int( $value[ $key ] ?? null ) && 0 < $value[ $key ] ) {
				$state[ $key ] = $value[ $key ]; }
		}
		foreach ( array(
			'claim'   => 32,
			'outcome' => 16,
			'code'    => 64,
		) as $key => $limit ) {
			if ( is_string( $value[ $key ] ?? null ) && strlen( $value[ $key ] ) <= $limit ) {
				$state[ $key ] = $value[ $key ]; }
		}
		return $state;
	}

	/**
	 * @param array<string, int|string|null> $expected
	 * @param array<string, int|string|null> $replacement
	 */
	private function write( array $expected, array $replacement ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! is_string( $wpdb->options ?? null ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'query' ) ) ) {
			return false; }
		$expected    = $this->canonical( $expected );
		$replacement = $this->canonical( $replacement );
		$old         = maybe_serialize( $expected );
		$new         = maybe_serialize( $replacement );
		if ( array() === $expected ) {
			$query = $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::OPTION_NAME, $new );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This exact option-row insertion was prepared immediately above and is the claim's atomic absent-row admission.
			$updated = $wpdb->query( $query );
			$this->clear_option_cache();
			return 1 === $updated && '' === trim( (string) ( $wpdb->last_error ?? '' ) );
		}
		$query = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'off' WHERE option_name = %s AND BINARY option_value = BINARY %s", $new, self::OPTION_NAME, $old );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This exact option snapshot compare-and-swap was prepared immediately above; update_option() cannot express claim ownership.
		$updated = $wpdb->query( $query );
		$this->clear_option_cache();
		return 1 === $updated && '' === trim( (string) ( $wpdb->last_error ?? '' ) );
	}

	private function clear_option_cache(): void {
		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * @param array<string, int|string|null> $state
	 * @return array<string, int|string>
	 */
	private function canonical( array $state ): array {
		$canonical = array();
		foreach ( $state as $key => $value ) {
			if ( null !== $value ) {
				$canonical[ $key ] = $value; }
		}
		ksort( $canonical );
		return $canonical;
	}

	/** @phpstan-impure The effective setting may change in another request. */
	private function allowed(): bool {
		return true === ( $this->allowed )(); }

	private function integer( mixed $value ): ?int {
		return is_int( $value ) ? $value : null; }

	private function text( mixed $value ): ?string {
		return is_string( $value ) ? $value : null; }

	/** @param array<string,mixed> $payload */
	private function matches_core_start( array $payload ): bool {
		return is_string( $payload['check_id'] ?? null )
			&& 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $payload['check_id'] )
			&& 'plugin' === ( $payload['target_type'] ?? null )
			&& 'github' === ( $payload['provider_code'] ?? null )
			&& ( $payload['installed_identity'] ?? null ) === $this->plugin_identifier
			&& ( $payload['channel'] ?? null ) === $this->channel
			&& '1319710173' === ( $payload['repository_identity'] ?? null );
	}

	/** @param array<string,mixed> $payload */
	private function matches_core_completion( array $payload, string $check_id ): bool {
		return ( $payload['check_id'] ?? null ) === $check_id
			&& is_string( $payload['status'] ?? null )
			&& is_string( $payload['reason'] ?? null );
	}
}
