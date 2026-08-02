<?php

declare(strict_types=1);

namespace Tests\Deployment;

final class WordPressWorkerWakeupCron {

	/** @var list<object> */
	public static array $events            = array();
	public static bool $scheduleSucceeds   = true;
	public static bool $unscheduleSucceeds = true;
	public static bool $clearSucceeds      = true;

	public static function reset(): void {
		self::$events             = array();
		self::$scheduleSucceeds   = true;
		self::$unscheduleSucceeds = true;
		self::$clearSucceeds      = true;
	}

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

	public static function schedule( int $timestamp, string $hook, array $arguments ): bool {
		if ( ! self::$scheduleSucceeds ) {
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

	public static function unschedule( int $timestamp, string $hook, array $arguments ): bool {
		if ( ! self::$unscheduleSucceeds ) {
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
		if ( ! self::$clearSucceeds ) {
			return false;
		}
		$before       = count( self::$events );
		self::$events = array_values( array_filter( self::$events, static fn ( object $event ): bool => $event->hook !== $hook ) );

		return $before - count( self::$events );
	}
}
