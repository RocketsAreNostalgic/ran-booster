<?php

declare(strict_types=1);

namespace RAN\Deployment;

use Throwable;

/** Keeps one args-free, non-recurring WP-Cron prompt for the durable queue. */
final readonly class WordPressWorkerWakeup {

	public const HOOK = 'ran_booster_run_deployment';

	public function __construct( private DeploymentAttemptRepository $attempts ) {
	}

	/** @return 'scheduled'|'already_scheduled'|'unavailable'|'not_required' */
	public function request(): string {
		try {
			$queuedAt = $this->attempts->earliestQueuedAt();
			if ( null === $queuedAt ) {
				return 'not_required';
			}
			if ( ! function_exists( 'wp_get_scheduled_event' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
				return 'unavailable';
			}
			$event = wp_get_scheduled_event( self::HOOK, array() );
			if ( false !== $event ) {
				return 'already_scheduled';
			}

			return true === wp_schedule_single_event( max( time() + 1, $queuedAt->getTimestamp() ), self::HOOK, array() )
				? 'scheduled'
				: 'unavailable';
		} catch ( Throwable ) {
			return 'unavailable';
		}
	}

	public function clear(): bool {
		return function_exists( 'wp_clear_scheduled_hook' )
			&& false !== wp_clear_scheduled_hook( self::HOOK, array() );
	}

	/** @return array{status: 'scheduled'|'missing'|'unavailable', scheduled_at: int|null} */
	public function inspect(): array {
		if ( ! function_exists( 'wp_get_scheduled_event' ) ) {
			return array(
				'status'       => 'unavailable',
				'scheduled_at' => null,
			);
		}
		try {
			$event = wp_get_scheduled_event( self::HOOK, array() );
			if ( false === $event ) {
				return array(
					'status'       => 'missing',
					'scheduled_at' => null,
				);
			}

			return array(
				'status'       => 'scheduled',
				'scheduled_at' => is_int( $event->timestamp ?? null ) ? $event->timestamp : null,
			);
		} catch ( Throwable ) {
			return array(
				'status'       => 'unavailable',
				'scheduled_at' => null,
			);
		}
	}
}
