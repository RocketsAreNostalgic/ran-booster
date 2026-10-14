<?php

declare(strict_types=1);

/** Test-only record cleanup; caller owns the already verified disposable site and exact target. */
function ran_booster_cleanup_fixture_management( \RAN\Storage\PluginRepository|\RAN\Storage\ThemeRepository $repository, string $identifier ): void {
	global $wpdb;

	if ( ! $repository->has_management_record( $identifier ) ) {
		return;
	}
	$record = $repository->recovery_record( $identifier );
	$query  = $wpdb->prepare( 'DELETE FROM %i WHERE id = %s AND package_type = %d AND BINARY package = BINARY %s AND BINARY adoption_token = BINARY %s', ran_booster_table_name(), $record['id'], $record['type'], $identifier, $record['adoption_token'] );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The test-owned exact-record delete is prepared above.
	if ( 1 !== $wpdb->query( $query ) || $repository->has_management_record( $identifier ) ) {
		throw new RuntimeException( 'Exact disposable fixture management cleanup was not verified.' );
	}
}
