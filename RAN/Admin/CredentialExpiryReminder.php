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
	 * @return array{provider:string,provider_label:string,id:string,label:string,editable:bool,effective_expires_at:?string,source:?string,stage:string,days:?int,badge_class:string,badge_label:string}
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

		$metadata      = $this->providers->get( $provider )->getMetadata();
		$providerLabel = $metadata->label;
		$label         = is_string( $profile['label'] ?? null ) && '' !== trim( $profile['label'] )
			? $profile['label']
			: $id;
		$editable      = empty( $profile['immutable'] ) && 'constant' !== ( $profile['source'] ?? null );

		if ( null === $effective ) {
			return array(
				'provider'             => $provider,
				'provider_label'       => $providerLabel,
				'id'                   => $id,
				'label'                => $label,
				'editable'             => $editable,
				'effective_expires_at' => null,
				'source'               => null,
				'stage'                => 'unknown',
				'days'                 => null,
				'badge_class'          => 'ran-booster-badge--neutral',
				'badge_label'          => 'Expiry unknown',
			);
		}

		$now       = ( $this->clock )()->setTimezone( new DateTimeZone( 'UTC' ) );
		$expires   = new DateTimeImmutable( $effective, new DateTimeZone( 'UTC' ) );
		$remaining = $expires->getTimestamp() - $now->getTimestamp();
		$days      = $remaining <= 0 ? 0 : (int) ceil( $remaining / self::DAY_SECONDS );
		$date      = $expires->format( 'Y-m-d' );

		if ( $remaining <= 0 ) {
			$stage      = 'expired';
			$badgeClass = 'ran-booster-badge--error';
			$badgeLabel = 'Expired ' . $date;
		} elseif ( $remaining <= self::URGENT_SECONDS ) {
			$stage      = 'urgent';
			$badgeClass = 'ran-booster-badge--error';
			$badgeLabel = sprintf( 'Expires in %d %s', $days, 1 === $days ? 'day' : 'days' );
		} elseif ( $remaining <= self::WARNING_SECONDS ) {
			$stage      = 'warning';
			$badgeClass = 'ran-booster-badge--warning';
			$badgeLabel = sprintf( 'Expires in %d %s', $days, 1 === $days ? 'day' : 'days' );
		} else {
			$stage      = 'future';
			$badgeClass = 'ran-booster-badge--neutral';
			$badgeLabel = 'Expires ' . $date;
		}

		return array(
			'provider'             => $provider,
			'provider_label'       => $providerLabel,
			'id'                   => $id,
			'label'                => $label,
			'editable'             => $editable,
			'effective_expires_at' => $effective,
			'source'               => $source,
			'stage'                => $stage,
			'days'                 => $days,
			'badge_class'          => $badgeClass,
			'badge_label'          => $badgeLabel,
		);
	}

	/**
	 * @return list<array{provider:string,provider_label:string,id:string,label:string,editable:bool,effective_expires_at:string,source:string,stage:string,days:int,badge_class:string,badge_label:string}>
	 */
	public function affected(): array {
		$affected = array();
		foreach ( $this->providers->all() as $provider => $repositoryProvider ) {
			unset( $repositoryProvider );
			foreach ( $this->secrets->credentialProfiles( $provider ) as $profile ) {
				$status = $this->status( $provider, $profile );
				if ( in_array( $status['stage'], array( 'warning', 'urgent', 'expired' ), true ) ) {
					/** @var array{provider:string,provider_label:string,id:string,label:string,editable:bool,effective_expires_at:string,source:string,stage:string,days:int,badge_class:string,badge_label:string} $status */
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
				(string) $status['stage'],
			),
			$affected
		);
		usort( $state, static fn ( array $left, array $right ): int => $left <=> $right );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Internal bounded hash input, not HTTP output.
		return hash( 'sha256', json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}
}
