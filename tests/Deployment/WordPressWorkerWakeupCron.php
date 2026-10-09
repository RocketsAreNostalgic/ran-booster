<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

final class WordPressWorkerWakeupCron {

	/** @var list<object> */
	public static array $events             = array();
	public static bool $schedule_succeeds   = true;
	public static bool $unschedule_succeeds = true;
	public static bool $clear_succeeds      = true;

	public static function reset(): void {
		self::$events              = array();
		self::$schedule_succeeds   = true;
		self::$unschedule_succeeds = true;
		self::$clear_succeeds      = true;
	}

	/**
	 * @param array<array-key, mixed> $arguments
	 */
	public static function next( string $hook, array $arguments ): object|false {
		$events = array_values(
			array_filter(
				self::$events,
				static fn ( object $event ): bool => $event->hook === $hook && $event->args === $arguments
			)
		);
		if ( array() === $events ) {
			return false;
		}
		usort( $events, static fn ( object $left, object $right ): int => $left->timestamp <=> $right->timestamp );

		return $events[0];
	}

	/**
	 * @param array<array-key, mixed> $arguments
	 */
	public static function schedule( int $timestamp, string $hook, array $arguments ): bool {
		if ( ! self::$schedule_succeeds ) {
			return false;
		}
		self::$events[] = (object) array(
			'hook'      => $hook,
			'timestamp' => $timestamp,
			'args'      => $arguments,
			'schedule'  => false,
		);

		return true;
	}

	/**
	 * @param array<array-key, mixed> $arguments
	 */
	public static function unschedule( int $timestamp, string $hook, array $arguments ): bool {
		if ( ! self::$unschedule_succeeds ) {
			return false;
		}
		self::$events = array_values(
			array_filter(
				self::$events,
				static fn ( object $event ): bool => ! ( $event->timestamp === $timestamp && $event->hook === $hook && $event->args === $arguments )
			)
		);

		return true;
	}

	public static function clear( string $hook ): int|false {
		if ( ! self::$clear_succeeds ) {
			return false;
		}
		$before       = count( self::$events );
		self::$events = array_values( array_filter( self::$events, static fn ( object $event ): bool => $event->hook !== $hook ) );

		return $before - count( self::$events );
	}
}
