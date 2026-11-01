<?php

declare(strict_types=1);

namespace RAN\Admin;

use DateTimeImmutable;
use DateTimeZone;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;

/**
 * Derives display-only credential expiry state without contacting providers.
 */
final class CredentialExpiryReminder {

	private const DAY_SECONDS     = 86400;
	private const WARNING_SECONDS = 30 * self::DAY_SECONDS;
	private const URGENT_SECONDS  = 7 * self::DAY_SECONDS;

	/** @var \Closure(): DateTimeImmutable */
	private \Closure $clock;

	/**
	 * @param callable(): DateTimeImmutable|null $clock Testable current UTC time.
	 */
	public function __construct(
		private ProviderRegistry $providers,
		private SecretsFile $secrets,
		private CredentialExpiryObservationStore $observations,
		?callable $clock = null
	) {
		$this->clock = null === $clock
			? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			: \Closure::fromCallable( $clock );
	}

	/**
	 * @param array<string, mixed> $profile Display-safe credential profile.
	 * @return array{provider:string,provider_label:string,id:string,label:string,editable:bool,effective_expires_at:?string,source:?string,observed_at:?string,stage:string,days:?int,badge_class:string,badge_label:string,event_marker:string}
	 */
	public function status( string $provider, array $profile ): array {
		$id          = is_string( $profile['id'] ?? null ) ? $profile['id'] : '';
		$observation = $this->observations->get( $provider, $id );
		$effective   = null;
		$source      = null;

		if ( is_string( $observation['provider_expires_at'] ?? null ) ) {
			$effective = $observation['provider_expires_at'];
			$source    = 'provider';
		} elseif ( is_string( $observation['manual_expires_on'] ?? null ) ) {
			$effective = $observation['manual_expires_on'] . 'T00:00:00Z';
			$source    = 'manual';
		}

		$metadata       = $this->providers->get( $provider )->get_metadata();
		$provider_label = $metadata->label;
		$label          = is_string( $profile['label'] ?? null ) && '' !== trim( $profile['label'] )
			? $profile['label']
			: $id;
		$editable       = empty( $profile['immutable'] ) && 'constant' !== ( $profile['source'] ?? null );

		if ( null === $effective ) {
			return array(
				'provider'             => $provider,
				'provider_label'       => $provider_label,
				'id'                   => $id,
				'label'                => $label,
				'editable'             => $editable,
				'effective_expires_at' => null,
				'source'               => null,
				'observed_at'          => null,
				'stage'                => 'unknown',
				'days'                 => null,
				'badge_class'          => 'ran-booster-badge--neutral',
				'badge_label'          => __( 'Expiry unknown', 'ran-booster' ),
				'event_marker'         => (string) ( $observation['event_generation'] ?? '' ),
			);
		}

		$now       = ( $this->clock )()->setTimezone( new DateTimeZone( 'UTC' ) );
		$expires   = new DateTimeImmutable( $effective, new DateTimeZone( 'UTC' ) );
		$remaining = $expires->getTimestamp() - $now->getTimestamp();
		$days      = $remaining <= 0 ? 0 : (int) ceil( $remaining / self::DAY_SECONDS );
		$date      = $expires->format( 'Y-m-d' );

		if ( $remaining <= 0 ) {
			$stage       = 'expired';
			$badge_class = 'ran-booster-badge--error';
			/* translators: %s is the credential expiry date in YYYY-MM-DD format. */
			$badge_label = sprintf( __( 'Expired %s', 'ran-booster' ), $date );
		} elseif ( $remaining <= self::URGENT_SECONDS ) {
			$stage       = 'urgent';
			$badge_class = 'ran-booster-badge--error';
			/* translators: %d is the number of days until the credential expires. */
			$badge_label = sprintf( _n( 'Expires in %d day', 'Expires in %d days', $days, 'ran-booster' ), $days );
		} elseif ( $remaining <= self::WARNING_SECONDS ) {
			$stage       = 'warning';
			$badge_class = 'ran-booster-badge--warning';
			/* translators: %d is the number of days until the credential expires. */
			$badge_label = sprintf( _n( 'Expires in %d day', 'Expires in %d days', $days, 'ran-booster' ), $days );
		} else {
			$stage       = 'future';
			$badge_class = 'ran-booster-badge--neutral';
			/* translators: %s is the credential expiry date in YYYY-MM-DD format. */
			$badge_label = sprintf( __( 'Expires %s', 'ran-booster' ), $date );
		}

		return array(
			'provider'             => $provider,
			'provider_label'       => $provider_label,
			'id'                   => $id,
			'label'                => $label,
			'editable'             => $editable,
			'effective_expires_at' => $effective,
			'source'               => $source,
			'observed_at'          => 'provider' === $source ? ( $observation['provider_checked_at'] ?? null ) : null,
			'stage'                => $stage,
			'days'                 => $days,
			'badge_class'          => $badge_class,
			'badge_label'          => $badge_label,
			'event_marker'         => (string) ( $observation['event_generation'] ?? '' ),
		);
	}

	/**
	 * @return list<array{provider:string,provider_label:string,id:string,label:string,editable:bool,effective_expires_at:string,source:string,observed_at:?string,stage:string,days:int,badge_class:string,badge_label:string,event_marker:string}>
	 */
	public function affected(): array {
		$affected = array();
		foreach ( $this->providers->all() as $provider => $repository_provider ) {
			unset( $repository_provider );
			foreach ( $this->secrets->credential_profiles( $provider ) as $profile ) {
				$status = $this->status( $provider, $profile );
				if ( in_array( $status['stage'], array( 'warning', 'urgent', 'expired' ), true ) ) {
					/** @var array{provider:string,provider_label:string,id:string,label:string,editable:bool,effective_expires_at:string,source:string,observed_at:?string,stage:string,days:int,badge_class:string,badge_label:string,event_marker:string} $status */
					$affected[] = $status;
				}
			}
		}

		$severity = array(
			'expired' => 3,
			'urgent'  => 2,
			'warning' => 1,
		);
		usort(
			$affected,
			static fn ( array $left, array $right ): int => array(
				- $severity[ $left['stage'] ],
				$left['effective_expires_at'],
				$left['provider'],
				$left['id'],
			) <=> array(
				- $severity[ $right['stage'] ],
				$right['effective_expires_at'],
				$right['provider'],
				$right['id'],
			)
		);

		return $affected;
	}

	/** @param list<array<string, mixed>> $affected */
	public function can_snooze( array $affected ): bool {
		$now = ( $this->clock )()->getTimestamp();
		foreach ( $affected as $status ) {
			if ( ! is_string( $status['effective_expires_at'] ?? null )
				|| ( new DateTimeImmutable( $status['effective_expires_at'], new DateTimeZone( 'UTC' ) ) )->getTimestamp() - $now <= 432000
			) {
				return false;
			}
		}

		return array() !== $affected;
	}

	/**
	 * @param list<array<string, mixed>> $affected Affected statuses.
	 * @return array<string, string>
	 */
	public function events( array $affected ): array {
		$events = array();
		foreach ( $affected as $status ) {
			$id            = (string) $status['provider'] . "\0" . (string) $status['id'];
			$events[ $id ] = hash( 'sha256', implode( "\0", array( $id, (string) $status['effective_expires_at'], (string) ( $status['event_marker'] ?? '' ) ) ) );
		}
		return $events;
	}

	public function now(): int {
		return ( $this->clock )()->getTimestamp();
	}

	/**
	 * @param list<array<string, mixed>> $affected Affected statuses.
	 * @return list<array<string, mixed>>
	 */
	public function visible( array $affected, int $user_id, string $scope, NoticePreference $preferences ): array {
		$suppressed = $preferences->suppressed_events( $user_id, CredentialExpiryNotice::USER_META_KEY, $this->events( $affected ), $this->now() );
		return array_values( array_filter( $affected, static fn ( array $status ): bool => ( 'booster' === $scope || 'warning' !== $status['stage'] ) && ! in_array( (string) $status['provider'] . "\0" . (string) $status['id'], $suppressed, true ) ) );
	}

	/** @param list<array<string, mixed>> $visible */
	public function state_action( array $visible, string $scope ): string {
		return CredentialExpiryNoticeController::NONCE_ACTION . ':' . $scope . ':' . ( $this->fingerprint( $visible ) ?? '' );
	}

	/**
	 * Fingerprint the current sorted reminder state without accepting client data.
	 *
	 * @param list<array<string, mixed>>|null $affected Precomputed request snapshot.
	 */
	public function fingerprint( ?array $affected = null ): ?string {
		$affected = $affected ?? $this->affected();
		if ( array() === $affected ) {
			return null;
		}

		$state = array_map(
			static fn ( array $status ): array => array(
				(string) $status['provider'],
				(string) $status['id'],
				(string) $status['effective_expires_at'],
				(string) ( $status['event_marker'] ?? '' ),
			),
			$affected
		);
		usort( $state, static fn ( array $left, array $right ): int => $left <=> $right );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Internal bounded hash input, not HTTP output.
		return hash( 'sha256', json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}
}
