<?php

declare(strict_types=1);

namespace RAN\Admin;

use DateTimeImmutable;
use DateTimeZone;

/** @internal Bounded per-user acknowledgement and event-snooze storage. */
final class NoticePreference {

	public const MODE_ACKNOWLEDGE = 'acknowledge';
	public const MODE_DISMISS     = 'dismiss';
	public const MODE_SNOOZE      = 'snooze';

	private const SNOOZE_SECONDS = 432000;

	/** @var \Closure(): DateTimeImmutable */
	private \Closure $clock;

	/** @param callable(): DateTimeImmutable|null $clock */
	public function __construct( ?callable $clock = null ) {
		$this->clock = null === $clock
			? static fn (): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
			: \Closure::fromCallable( $clock );
	}

	public function is_suppressed( int $user_id, string $key, string $event ): bool {
		$value = get_user_meta( $user_id, $key, true );
		return is_string( $value ) && hash_equals( $event, $value );
	}

	public function save( int $user_id, string $key, string $event, string $mode ): bool {
		if ( ! in_array( $mode, array( self::MODE_ACKNOWLEDGE, self::MODE_DISMISS ), true ) ) {
			return false;
		}
		update_user_meta( $user_id, $key, $event );
		return $this->is_suppressed( $user_id, $key, $event );
	}

	/**
	 * @param array<string, string> $events Current event identities.
	 * @return list<string>
	 */
	public function suppressed_events( int $user_id, string $key, array $events, ?int $now = null ): array {
		$value = get_user_meta( $user_id, $key, true );
		if ( ! is_array( $value ) || ! is_array( $value['events'] ?? null ) ) {
			return array();
		}
		$now = $now ?? ( $this->clock )()->getTimestamp();
		$out = array();
		foreach ( $events as $id => $event ) {
			$record = $value['events'][ $id ] ?? null;
			if ( is_array( $record ) && is_string( $record['event'] ?? null )
				&& hash_equals( $event, $record['event'] )
				&& ( self::MODE_DISMISS === ( $record['mode'] ?? null )
					|| ( self::MODE_SNOOZE === ( $record['mode'] ?? null ) && is_int( $record['until'] ?? null ) && $now < $record['until'] ) )
			) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, string> $events Visible events to save.
	 * @param array<string, string>|null $known All current event identities.
	 */
	public function save_events( int $user_id, string $key, array $events, string $mode, ?array $known = null, ?int $now = null ): bool {
		if ( array() === $events || ! in_array( $mode, array( self::MODE_DISMISS, self::MODE_SNOOZE ), true ) ) {
			return false;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->usermeta ) || ! is_string( $wpdb->usermeta )
			|| ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$lock = 'ran-notice-' . substr( hash( 'sha256', $wpdb->usermeta . "\0" . $user_id . "\0" . $key ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes this user's event-map writes across connections, including initial creation.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return false;
		}
		try {
			wp_cache_delete( $user_id, 'user_meta' );
			$saved = $this->save_locked_events( $user_id, $key, $events, $mode, $known ?? $events, $now ?? ( $this->clock )()->getTimestamp() );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the connection-local advisory lock acquired above.
			$released = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return $saved && $released;
	}

	/**
	 * @param array<string, string> $events Visible event identities.
	 * @param array<string, string> $known Current event identities.
	 */
	private function save_locked_events( int $user_id, string $key, array $events, string $mode, array $known, int $now ): bool {
		$value  = get_user_meta( $user_id, $key, true );
		$stored = array();
		foreach ( $this->suppressed_events( $user_id, $key, $known, $now ) as $id ) {
			if ( is_array( $value ) && is_array( $value['events'] ?? null ) && is_array( $value['events'][ $id ] ?? null ) ) {
				$stored[ $id ] = $value['events'][ $id ];
			}
		}
		foreach ( $events as $id => $event ) {
			if ( ! isset( $known[ $id ] ) || ! hash_equals( $known[ $id ], $event ) ) {
				return false;
			}
			$stored[ $id ] = array(
				'event' => $event,
				'mode'  => $mode,
			);
			if ( self::MODE_SNOOZE === $mode ) {
				$stored[ $id ]['until'] = $now + self::SNOOZE_SECONDS;
			}
		}
		update_user_meta( $user_id, $key, array( 'events' => $stored ) );
		return count( $events ) === count( $this->suppressed_events( $user_id, $key, $events, $now ) );
	}
}
