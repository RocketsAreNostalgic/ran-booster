<?php

declare(strict_types=1);

use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\Storage\GitHubSelectionStore;

// Storage-only proof; no runtime selection or replacement path is activated.
return static function ( wpdb $database ): void {
	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
		throw new RuntimeException( 'Selection SQL proof requires installed WordPress database configuration.' );
	}
	$table       = $database->prefix . 'ran_selection_smoke_' . bin2hex( random_bytes( 6 ) );
	$other       = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$store       = new GitHubSelectionStore( $database, $table );
	$second      = new GitHubSelectionStore( $other, $table );
	$expect      = static function ( bool $condition, string $reason ): void {
		if ( ! $condition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed developer-authored proof failure, not rendered HTML.
			throw new RuntimeException( 'Selection SQL proof: ' . $reason );
		}
	};
	$query       = static function ( ?string $sql ) use ( $database ): void {
		if ( null === $sql || false === $database->query( $sql ) ) {
			throw new RuntimeException( 'Selection SQL fixture mutation failed.' );
		}
	};
	$reject      = static function ( callable $operation, string $reason ) use ( $expect ): void {
		try {
			$operation();
		} catch ( DatabaseLifecycleFailure $failure ) {
			$expect( '' !== $failure->reason(), 'typed failure has a reason' );
			return;
		}
		$expect( false, $reason );
	};
	$declaration = new GitHubReplacementDeclaration(
		array(
			'plugin_basename'        => 'ran-booster/ran-booster.php',
			'implementation_id'      => 'bundled-storage-proof',
			'compatibility_revision' => '1',
			'provider_api_version'   => 15,
			'api_credential_formats' => array( 'github-classic-pat-v1' ),
			'webhook_signing_format' => null,
			'facets'                 => array(),
		)
	);
	$saved_cache = array();
	foreach ( array( GitHubSelectionStore::OPTION, 'alloptions', 'notoptions' ) as $key ) {
		$found               = false;
		$value               = wp_cache_get( $key, 'options', false, $found );
		$saved_cache[ $key ] = array( $found, $value );
	}
	try {
		// Clone the installed options shape, but never change the site's real selection row.
		$query( $database->prepare( 'CREATE TABLE %i LIKE %i', $table, $database->options ) );
		$reject( static fn () => $store->read(), 'missing selection must refuse' );
		wp_cache_set( GitHubSelectionStore::OPTION, 'stale-selection', 'options' );
		wp_cache_set( 'notoptions', array( GitHubSelectionStore::OPTION => true ), 'options' );
		wp_cache_set( 'alloptions', array( GitHubSelectionStore::OPTION => 'stale-selection' ), 'options' );
		$query( 'START TRANSACTION' );
		$original = $store->initialize_bundled( $declaration );
		$query( 'COMMIT' );
		$store->invalidate_cache();
		$expect( 0 === $original['selection_generation'] && 'bundled' === $original['selected'], 'fresh bundled selection' );
		$expect( $original === $second->read(), 'independent connection sees initialization' );
		$expect( 'no' === $database->get_var( $database->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $table, GitHubSelectionStore::OPTION ) ), 'selection never autoloads' );
		foreach ( array( GitHubSelectionStore::OPTION, 'alloptions', 'notoptions' ) as $key ) {
			$found = false;
			wp_cache_get( $key, 'options', false, $found );
			$expect( ! $found, 'initialization invalidates stale option caches' );
		}
		$next                         = $original;
		$next['selection_generation'] = 1;
		$next['approval']             = 'revoked';
		$query( 'START TRANSACTION' );
		$expect( $original === $store->read( true ), 'transaction reads locked selection' );
		$expect( $store->compare_and_swap( $original, $next ), 'transactional selection write' );
		$expect( $next === $store->read(), 'writer sees uncommitted replacement' );
		$expect( $original === $second->read(), 'another connection cannot see uncommitted selection' );
		$query( 'ROLLBACK' );
		$store->invalidate_cache();
		$expect( $original === $store->read() && $original === $second->read(), 'caller rollback restores selection; store does not commit' );

		// Simulate storage changing the written bytes before authoritative readback.
		$query( 'START TRANSACTION' );
		$expect( $original === $store->read( true ), 'readback fixture holds row lock' );
		$disturbed = false;
		$disturb   = static function ( string $sql ) use ( &$disturbed, $table, $database, $query ): string {
			if ( ! $disturbed && str_contains( $sql, 'SELECT option_name, option_value, autoload FROM' ) && str_contains( $sql, $table ) ) {
				$disturbed = true;
				$query( $database->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $table, '{changed-after-write', GitHubSelectionStore::OPTION ) );
			}
			return $sql;
		};
		add_filter( 'query', $disturb );
		try {
			$reject( static fn () => $store->compare_and_swap( $original, $next ), 'changed readback must not report successful write' );
		} finally {
			remove_filter( 'query', $disturb );
		}
		$expect( $disturbed, 'actual SQL readback was interleaved' );
		$query( 'ROLLBACK' );
		$store->invalidate_cache();
		$expect( $original === $second->read(), 'failed readback remains caller-rollbackable' );

		// B commits after A captures its expectation. A must not overwrite B or trust its cached row.
		$stale = $store->read();
		$expect( false !== $other->query( 'START TRANSACTION' ), 'second connection begins its transaction' );
		$expect( $original === $second->read( true ), 'second connection locks its selection' );
		$expect( $second->compare_and_swap( $original, $next ), 'second connection advances selection' );
		// A cache reader can repopulate the old committed value after CAS invalidation.
		// This exercises WordPress's cache API; it does not qualify an external Redis backend.
		$expect( $original === $store->read(), 'first connection still sees old committed row' );
		wp_cache_set( GitHubSelectionStore::OPTION, 'old-committed-selection', 'options' );
		wp_cache_set( 'alloptions', array( GitHubSelectionStore::OPTION => 'old-committed-selection' ), 'options' );
		wp_cache_set( 'notoptions', array( GitHubSelectionStore::OPTION => true ), 'options' );
		$expect( false !== $other->query( 'COMMIT' ), 'caller commits second connection' );
		$expect( 'old-committed-selection' === wp_cache_get( GitHubSelectionStore::OPTION, 'options' ), 'commit alone does not clear repopulated cache' );
		$second->invalidate_cache();
		foreach ( array( GitHubSelectionStore::OPTION, 'alloptions', 'notoptions' ) as $key ) {
			$found = false;
			wp_cache_get( $key, 'options', false, $found );
			$expect( ! $found, 'caller postcommit invalidation removes repopulated cache' );
		}
		$expect( $next === $store->read(), 'first connection sees committed selection' );
		wp_cache_set( GitHubSelectionStore::OPTION, 'stale-selection', 'options' );
		wp_cache_set( 'alloptions', array( GitHubSelectionStore::OPTION => 'stale-selection' ), 'options' );
		wp_cache_set( 'notoptions', array( GitHubSelectionStore::OPTION => true ), 'options' );
		$query( 'START TRANSACTION' );
		$expect( $next === $store->read( true ), 'stale writer locks current committed selection' );
		$expect( ! $store->compare_and_swap( $stale, $next ), 'stale cross-connection compare-and-swap refuses' );
		$expect( $next === $store->read(), 'direct read bypasses stale option cache' );
		foreach ( array( GitHubSelectionStore::OPTION, 'alloptions', 'notoptions' ) as $key ) {
			$found = false;
			wp_cache_get( $key, 'options', false, $found );
			$expect( ! $found, 'stale compare-and-swap invalidates option caches' );
		}
		$query( 'ROLLBACK' );
		$store->invalidate_cache();
		$query( 'START TRANSACTION' );
		$expect( $next === $store->initialize_bundled( $declaration ), 'repeat initialization preserves durable revoked selection' );
		$query( 'COMMIT' );
		$store->invalidate_cache();
		$expect( 1 === (int) $database->get_var( $database->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'initialization leaves exactly one row' );

		$query( $database->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $table, '{broken', GitHubSelectionStore::OPTION ) );
		$reject( static fn () => $store->read(), 'corrupt state refuses' );
		$query( 'START TRANSACTION' );
		$reject( static fn () => $store->initialize_bundled( $declaration ), 'initialization cannot repair corruption silently' );
		$query( 'ROLLBACK' );
		$store->invalidate_cache();
		$expect( '{broken' === $database->get_var( $database->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $table, GitHubSelectionStore::OPTION ) ), 'corrupt bytes preserved for diagnosis' );
		$query( $database->prepare( 'DELETE FROM %i', $table ) );
		$query( 'START TRANSACTION' );
		$store->initialize_bundled( $declaration );
		$query( 'COMMIT' );
		$store->invalidate_cache();
		$query( $database->prepare( "UPDATE %i SET autoload = 'yes'", $table ) );
		$reject( static fn () => $store->read(), 'autoloaded state refuses' );
		$query( $database->prepare( "UPDATE %i SET autoload = 'no'", $table ) );
		$query( $database->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) );
		$reject( static fn () => $store->read(), 'nontransactional options storage refuses' );
		$query( $database->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
		$query( $database->prepare( 'ALTER TABLE %i DROP INDEX option_name', $table ) );
		$reject( static fn () => $store->read(), 'missing unique option-name key refuses' );
		$query( $database->prepare( 'ALTER TABLE %i ADD UNIQUE KEY option_name (option_name(10))', $table ) );
		$reject( static fn () => $store->read(), 'prefix-only unique option-name key refuses' );
	} finally {
		$database->query( 'ROLLBACK' );
		$other->query( 'ROLLBACK' );
		$store->invalidate_cache();
		$query( $database->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		foreach ( $saved_cache as $key => $entry ) {
			wp_cache_delete( $key, 'options' );
			if ( $entry[0] ) {
				wp_cache_set( $key, $entry[1], 'options' );
			}
		}
		$other->close();
	}
};
