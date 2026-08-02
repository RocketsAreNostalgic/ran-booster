<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

function authenticated_archive_hooks_reset(): void {
	$GLOBALS['ran_booster_authenticated_archive_filters'] = array();
	$GLOBALS['ran_booster_authenticated_archive_actions'] = array();
}

/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
function authenticated_archive_filters( string $hook ): array {
	return array_values(
		array_filter(
			$GLOBALS['ran_booster_authenticated_archive_filters'] ?? array(),
			static fn ( array $record ): bool => $hook === $record['hook']
		)
	);
}

/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
function authenticated_archive_actions( string $hook ): array {
	return array_values(
		array_filter(
			$GLOBALS['ran_booster_authenticated_archive_actions'] ?? array(),
			static fn ( array $record ): bool => $hook === $record['hook']
		)
	);
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_authenticated_archive_filters'][] = array(
		'hook'          => $hook,
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $acceptedArgs,
	);

	return true;
}

function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
	return authenticated_archive_remove_hook( 'ran_booster_authenticated_archive_filters', $hook, $callback, $priority );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1 ): bool {
	$GLOBALS['ran_booster_authenticated_archive_actions'][] = array(
		'hook'          => $hook,
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $acceptedArgs,
	);

	return true;
}

function remove_action( string $hook, callable $callback, int $priority = 10 ): bool {
	return authenticated_archive_remove_hook( 'ran_booster_authenticated_archive_actions', $hook, $callback, $priority );
}

function authenticated_archive_remove_hook( string $global, string $hook, callable $callback, int $priority ): bool {
	foreach ( $GLOBALS[ $global ] ?? array() as $index => $record ) {
		if ( $hook === $record['hook'] && $callback === $record['callback'] && $priority === $record['priority'] ) {
			unset( $GLOBALS[ $global ][ $index ] );

			return true;
		}
	}

	return false;
}
