<?php

declare(strict_types=1);

namespace RAN\Internal;

use Throwable;

/**
 * Anonymous lookups share the server address's provider quota, and a provider may penalise requests
 * made while limited. After an anonymous rate limit with a trustworthy retry time, Core skips further
 * anonymous lookups to that provider until then. Only that time is kept, as one expiring site transient
 * per provider; a limit without a time, or one more than a day away, is never stored. Credentialed
 * lookups never read or write it.
 *
 * @internal
 */
final class AnonymousLookupCooldown {

	private const PREFIX = 'ran_booster_anonymous_cooldown_';

	/** The stored retry time while it is still in the future, otherwise null. */
	public static function until( string $provider, ?int $now = null ): ?int {
		$key = self::key( $provider );
		if ( null === $key ) {
			return null;
		}
		try {
			$retry_at = get_site_transient( $key );
		} catch ( Throwable ) {
			// An unreadable cooldown never blocks a lookup.
			return null;
		}
		$now ??= time();
		// Without a persistent object cache the options table returns the stored integer as a string.
		$retry_at = is_string( $retry_at ) && 1 === preg_match( '/\A[1-9][0-9]{0,11}\z/D', $retry_at ) ? (int) $retry_at : $retry_at;

		return is_int( $retry_at ) && $retry_at > $now && $retry_at <= $now + 86400 ? $retry_at : null;
	}

	/** @param array{provider?:mixed,retry_at?:mixed}|null $rate_limit Facts from ProviderFailure::rate_limit(). */
	public static function record( string $provider, ?array $rate_limit, ?int $now = null ): void {
		$key      = self::key( $provider );
		$retry_at = $rate_limit['retry_at'] ?? null;
		$now    ??= time();
		if ( null === $key || ! is_int( $retry_at ) || $retry_at <= $now || $retry_at > $now + 86400 ) {
			return;
		}
		try {
			set_site_transient( $key, $retry_at, $retry_at - $now );
		} catch ( Throwable ) {
			// Recording is best effort; the failed lookup has already been reported.
			return;
		}
	}

	private static function key( string $provider ): ?string {
		return 1 === preg_match( '/\A[a-z0-9][a-z0-9_-]{0,31}\z/D', $provider ) ? self::PREFIX . $provider : null;
	}
}
