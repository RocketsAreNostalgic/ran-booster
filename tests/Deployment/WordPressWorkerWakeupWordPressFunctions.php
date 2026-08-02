<?php

declare(strict_types=1);

function wp_timezone(): \DateTimeZone {
	return new \DateTimeZone( 'UTC' );
}

function wp_get_scheduled_event( string $hook, array $arguments = array() ): object|false {
	return \Tests\Deployment\WordPressWorkerWakeupCron::next( $hook, $arguments );
}

function wp_schedule_single_event( int $timestamp, string $hook, array $arguments = array() ): bool {
	return \Tests\Deployment\WordPressWorkerWakeupCron::schedule( $timestamp, $hook, $arguments );
}

function wp_unschedule_event( int $timestamp, string $hook, array $arguments = array() ): bool {
	return \Tests\Deployment\WordPressWorkerWakeupCron::unschedule( $timestamp, $hook, $arguments );
}

function wp_clear_scheduled_hook( string $hook ): int|false {
	return \Tests\Deployment\WordPressWorkerWakeupCron::clear( $hook );
}
