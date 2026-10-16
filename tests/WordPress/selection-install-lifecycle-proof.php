<?php

use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\Storage\Database;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\GitHubSelectionStore;

// Caller has verified the disposable root, marker and exact current schema.
return static function ( wpdb $database ): void {
	$store       = new GitHubSelectionStore( $database, $database->options );
	$saved_state = $store->read( true );
	// Snapshot authority directly: option filters and caches can report a missing row.
	$query = $database->prepare( 'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE', $database->options, GitHubSelectionStore::OPTION );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Both table and option name are bound immediately above.
	$saved = $database->get_var( $query );
	if ( ! is_string( $saved ) || '' !== $database->last_error ) {
		throw new RuntimeException( 'Could not snapshot selection authority.' );
	}
	$declaration      = new GitHubReplacementDeclaration( array_merge( array( 'plugin_basename' => 'ran-booster/ran-booster.php' ), GitHubProvider::compatibility_contract() ) );
	$version_observed = false;
	$observe          = static function ( mixed $value ) use ( $store, &$version_observed ): mixed {
		$state = $store->read( true );
		if ( 'bundled' !== $state['selected'] || 0 !== $state['selection_generation'] || 'approved' !== $state['approval'] ) {
			throw new RuntimeException( 'Version advanced before authoritative selection initialization.' );
		}
		$version_observed = true;
		return $value;
	};
	$writes           = array();
	$record           = static function ( string $query ) use ( &$writes ): string {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $query ) ) {
			$writes[] = $query;
		}
		return $query;
	};
	try {
		delete_option( Database::VERSION_OPTION );
		delete_option( GitHubSelectionStore::OPTION );
		add_filter( 'pre_update_option_' . Database::VERSION_OPTION, $observe );
		( new Database( null, $declaration ) )->install();
		remove_filter( 'pre_update_option_' . Database::VERSION_OPTION, $observe );
		if ( ! $version_observed || '15.0' !== get_option( Database::VERSION_OPTION ) ) {
			throw new RuntimeException( 'Fresh installation did not publish selection before its version.' );
		}
		delete_option( GitHubSelectionStore::OPTION );
		add_filter( 'query', $record );
		$rejected = false;
		try {
			( new Database( null, $declaration ) )->install();
		} catch ( DatabaseLifecycleFailure ) {
			$rejected = true;
		} finally {
			remove_filter( 'query', $record );
		}
		if ( ! $rejected || array() !== $writes || false !== get_option( GitHubSelectionStore::OPTION ) ) {
			throw new RuntimeException( 'Current installation repaired missing selection authority.' );
		}
	} finally {
		remove_filter( 'pre_update_option_' . Database::VERSION_OPTION, $observe );
		remove_filter( 'query', $record );
		// Restore the exact SQL contract; update_option() can no-op on cached absence
		// and WordPress's false autoload value need not persist the literal 'no'.
		$restored = $database->replace(
			$database->options,
			array(
				'option_name'  => GitHubSelectionStore::OPTION,
				'option_value' => $saved,
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);
		$store->invalidate_cache();
		if ( false === $restored || $saved_state !== $store->read( true ) ) {
			throw new RuntimeException( 'Could not restore selection authority.' );
		}
		update_option( Database::VERSION_OPTION, Database::$booster_db_version, false );
	}
};
