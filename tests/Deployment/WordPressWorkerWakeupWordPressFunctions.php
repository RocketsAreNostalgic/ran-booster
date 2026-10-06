<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function wp_timezone(): \DateTimeZone {
	return new \DateTimeZone( 'UTC' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function wp_get_scheduled_event( string $hook, array $arguments = array() ): object|false {
	return \RAN\Tests\Deployment\WordPressWorkerWakeupCron::next( $hook, $arguments );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function wp_schedule_single_event( int $timestamp, string $hook, array $arguments = array() ): bool {
	return \RAN\Tests\Deployment\WordPressWorkerWakeupCron::schedule( $timestamp, $hook, $arguments );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function wp_unschedule_event( int $timestamp, string $hook, array $arguments = array() ): bool {
	return \RAN\Tests\Deployment\WordPressWorkerWakeupCron::unschedule( $timestamp, $hook, $arguments );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function wp_clear_scheduled_hook( string $hook ): int|false {
	return \RAN\Tests\Deployment\WordPressWorkerWakeupCron::clear( $hook );
}
