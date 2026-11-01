<?php

use RAN\Activity\ActivityStore;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Troubleshooting\DiagnosticRunStore;
use RAN\Troubleshooting\InstallationFacts;
use RAN\Troubleshooting\SupportReport;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This proof must run through WP-CLI.' );
}
$ran_booster_site = realpath( ABSPATH );
if ( ! is_string( $ran_booster_site ) || realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) ) !== $ran_booster_site
	|| is_link( rtrim( ABSPATH, '/' ) ) || is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR )
	|| realpath( WP_CONTENT_DIR ) !== $ran_booster_site . '/wp-content'
	|| realpath( WP_PLUGIN_DIR ) !== $ran_booster_site . '/wp-content/plugins'
	|| realpath( WP_PLUGIN_DIR . '/ran-booster' ) !== $ran_booster_site . '/wp-content/plugins/ran-booster'
	|| is_link( WP_PLUGIN_DIR . '/ran-booster' ) || 'http://localhost' !== get_option( 'siteurl' )
	|| is_link( ABSPATH . '.ran-booster-disposable-test-site' )
	|| "RAN Booster disposable test site\n" !== file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' ) ) {
	throw new RuntimeException( 'The support proof requires the exact marked disposable site and copied plugin.' );
}

$ran_booster_support_sql_proof = static function ( wpdb $database ): int {
	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
		throw new RuntimeException( 'The installed WordPress database configuration is unavailable.' );
	}
	$assertions     = 0;
	$expect         = static function ( bool $condition, string $boundary ) use ( &$assertions ): void {
		++$assertions;
		if ( ! $condition ) {
			throw new RuntimeException( 'Support snapshot SQL proof failed: ' . $boundary );
		}
	};
	$administrators = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$administrator  = (int) ( $administrators[0] ?? 0 );
	$expect( $administrator > 0, 'existing disposable administrator' );
	$previous_user = get_current_user_id();
	wp_set_current_user( $administrator );
	$missing     = new stdClass();
	$previous    = get_option( DiagnosticRunStore::OPTION_NAME, $missing );
	$second_user = null;
	$transaction = false;
	$race        = null;
	$observer    = null;
	$store       = new class() extends DiagnosticRunStore {
		public float $clock = 1000.25;
		protected function now(): float {
			return $this->clock;
		}
	};
	$run         = static fn ( float $completed ): array => array(
		'completed_at'        => $completed,
		'implementation'      => str_repeat( 'a', 64 ),
		'credential_selected' => true,
		'repository_selected' => true,
		'partial'             => false,
		'partial_reason'      => null,
		'results'             => array(
			array(
				'status'      => 'pass',
				'code'        => 'provider.check',
				'message'     => 'Provider result details omitted by the sharing policy.',
				'remediation' => 'Review the immediate diagnostic results locally.',
			),
		),
	);
	$query       = static function ( string $sql ) use ( $database ): int|bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded disposable fixtures supply fixed SQL or explicitly prepared table names below.
		return $database->query( $sql );
	};
	try {
		$store->clear();
		$expect( $store->save( 'gh', $run( 1000.25 ) ), 'SQL insert' );
		$expect( false !== get_option( DiagnosticRunStore::OPTION_NAME ), 'insert cache invalidation' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the actual persisted autoload value rather than infer it from service results.
		$expect( 'no' === $database->get_var( $database->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $database->options, DiagnosticRunStore::OPTION_NAME ) ), 'nonautoload option' );
		$expect( $store->save( 'bb', $run( 1000.0 ) ), 'second provider' );
		$expect( $store->save( 'gh', $run( 999.0 ) ), 'delayed old completion' );
		$expect( 1000.25 === $store->available()['runs'][0]['completed_at'], 'newest completion preserved' );
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->set_prefix( $database->prefix );
		$raced = false;
		$race  = static function ( string $sql ) use ( &$raced, $other, $administrator, $run ): string {
			if ( $raced || ! str_starts_with( $sql, 'UPDATE ' ) || ! str_contains( $sql, DiagnosticRunStore::OPTION_NAME ) ) {
				return $sql;
			}
			$raced   = true;
			$entries = array();
			foreach ( array(
				'gh' => 1000.25,
				'bb' => 1000.0,
				'cc' => 1000.0,
			) as $provider => $completed ) {
				$site = get_current_blog_id();
				$entries[ $site . ':' . $administrator . ':' . $provider ] = array(
					'site'     => $site,
					'user'     => $administrator,
					'provider' => $provider,
					'nonce'    => str_repeat( 'b', 32 ),
					'run'      => $run( $completed ),
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact persistence fixture preserves fractional completion timestamps.
			$replacement = json_encode(
				array(
					'schema'  => 1,
					'entries' => $entries,
				),
				JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent connection changes the exact disposable option before the original CAS executes.
			if ( 1 !== $other->query( $other->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $other->options, $replacement, DiagnosticRunStore::OPTION_NAME ) ?? throw new RuntimeException( 'The race SQL could not be prepared.' ) ) ) {
				throw new RuntimeException( 'The independent retention race fixture failed.' );
			}
			return $sql;
		};
		add_filter( 'query', $race );
		$store->clock = 1001.0;
		$expect( $store->save( 'gh', $run( 1000.50 ) ), 'CAS retries after competing write' );
		remove_filter( 'query', $race );
		$race = null;
		$expect( $raced && 3 === count( $store->available()['runs'] ), 'independent provider preserved' );
		$raw          = get_option( DiagnosticRunStore::OPTION_NAME );
		$store->clock = 4600.49;
		$expect( 1 === count( $store->available()['runs'] ), 'fractional expiry before boundary' );
		$expect( get_option( DiagnosticRunStore::OPTION_NAME ) === $raw, 'reads never slide expiry' );
		$store->clock = 4600.50;
		$expect( array() === $store->available()['runs'], 'exact one-hour boundary' );
		$store->cleanup();
		$store->clock = 5000.0;
		$expect( $store->save( 'gh', $run( 5000.0 ) ), 'expired slot reclamation' );
		$second_user = wp_insert_user(
			array(
				'user_login' => 'ran-support-' . bin2hex( random_bytes( 6 ) ),
				'user_pass'  => bin2hex( random_bytes( 24 ) ),
				'role'       => 'administrator',
			)
		);
		if ( ! is_int( $second_user ) ) {
			throw new RuntimeException( 'The disposable second administrator could not be created.' );
		}
		wp_set_current_user( $second_user );
		$expect( array() === $store->available()['runs'] && $store->save( 'gh', $run( 5000.0 ) ), 'administrator isolation' );
		wp_set_current_user( 0 );
		$expect( ! $store->available()['available'] && ! $store->save( 'gh', $run( 5000.0 ) ), 'denied administrator' );
		wp_set_current_user( $administrator );
		$expect( 1 === count( $store->available()['runs'] ), 'first administrator evidence intact' );
		$packages       = ran_booster_table_name();
		$activity_table = $database->prefix . ActivityStore::TABLE_SUFFIX;
		$expect( false !== $query( 'START TRANSACTION' ), 'start reversible fixture transaction' );
		$transaction = true;
		$expect( false !== $query( $database->prepare( 'DELETE FROM %i', $packages ) ?? throw new RuntimeException( 'The package fixture SQL could not be prepared.' ) ) && false !== $query( $database->prepare( 'DELETE FROM %i', $activity_table ) ?? throw new RuntimeException( 'The Activity fixture SQL could not be prepared.' ) ), 'isolate reversible fixture rows' );
		$sort_at = gmdate( 'Y-m-d H:i:s' );
		for ( $index = 0; $index < 105; ++$index ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Synthetic private fields exercise the positive export boundary; transaction rollback restores prior rows.
			$expect(
				1 === $database->insert(
					$packages,
					array(
						'package'                => 'private-canary-' . $index . '/entry.php',
						'repository'             => 'private-owner/private-repository-' . $index,
						'branch'                 => 'private-branch-canary',
						'package_type'           => 1,
						'deployment_policy'      => 'manual',
						'source'                 => 0 === $index ? 'release_asset' : 'branch',
						'provider'               => 'gh',
						'provider_repository_id' => (string) ( $index + 1 ),
						'private'                => 1,
						'credential_id'          => 'credential-canary',
						'adoption_token'         => 'adoption-canary',
						'release_configuration'  => 'raw-configuration-canary',
					)
				),
				'package fixture'
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Synthetic text, facts and actor fields must not enter the bounded SQL projection.
			$expect(
				1 === $database->insert(
					$activity_table,
					array(
						'correlation'      => bin2hex( random_bytes( 16 ) ),
						'sort_at'          => $sort_at,
						'started_at'       => gmdate( 'Y-m-d H:i:s' ),
						'completed_at'     => gmdate( 'Y-m-d H:i:s' ),
						'provider'         => 'gh',
						'subject_type'     => 'provider',
						'subject_identity' => 'private-canary-' . $index,
						'adoption_token'   => 'adoption-canary',
						'context'          => 'private-context-canary',
						'context_key'      => hash( 'sha256', 'fixture' . $index ),
						'operation'        => $index >= 103 ? 'deployment.manual' : 'diagnostics.run',
						'purpose'          => null,
						'provenance'       => 'core',
						'actor'            => $administrator,
						'status'           => $index >= 103 ? 'failed' : 'succeeded',
						'stage'            => match ( $index ) {
						103 => 'deployment.needs_attention', 104 => 'deployment.operator_reviewed', default => '' },
						'reason'           => 'diagnostics.passed',
						'message'          => str_repeat( 'raw-message-canary', 1000 ),
						'remediation'      => 'private-remediation-canary',
						'facts'            => '{"private":"raw-facts-canary"}',
						'effect'           => 'none',
					)
				),
				'Activity fixture'
			);
		}
		$queries  = array();
		$observer = static function ( string $sql ) use ( &$queries ): string {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $observer );
		$plugins           = new PluginRepository();
		$activity          = new ActivityStore();
		$package_snapshot  = $plugins->support_snapshot();
		$activity_snapshot = $activity->support_snapshot();
		remove_filter( 'query', $observer );
		$expect( $package_snapshot['available'] && 100 === count( $package_snapshot['records'] ) && $package_snapshot['truncated'], 'package SQL bounds' );
		$expect( $activity_snapshot['available'] && 100 === count( $activity_snapshot['records'] ) && $activity_snapshot['truncated'], 'Activity SQL bounds' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare the projection with authoritative native numeric ordering, including the 99/100 boundary.
		$expected_ids = $database->get_col( $database->prepare( 'SELECT id FROM %i ORDER BY sort_at DESC,id DESC LIMIT 100', $activity_table ) );
		$expect( array_map( 'strval', $expected_ids ) === array_map( 'strval', array_column( $activity_snapshot['records'], 'id' ) ), 'numeric Activity ordering parity' );
		foreach ( $package_snapshot['records'] as $record ) {
			$expect( array() === array_intersect_key( $record, array_flip( array( 'credential_id', 'adoption_token', 'release_configuration' ) ) ), 'package raw-field exclusion' );
		}
		foreach ( $activity_snapshot['records'] as $record ) {
			$expect( array() === array_intersect_key( $record, array_flip( array( 'actor', 'facts', 'message', 'remediation', 'context', 'adoption_token' ) ) ), 'Activity raw-field exclusion' );
		}
		add_filter( 'query', $observer );
		$report   = new SupportReport( new InstallationFacts( new ProviderRegistry() ), $store, $activity, $plugins, new ThemeRepository() );
		$captured = $report->generate( array() );
		remove_filter( 'query', $observer );
		$observer = null;
		foreach ( $queries as $sql ) {
			$expect( 1 !== preg_match( '/^\s*(CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $sql ), 'passive support inputs do not write or prepare schema' );
		}
		$decoded  = json_decode( $captured['text'], true, 512, JSON_THROW_ON_ERROR );
		$packages = $decoded['sections']['managed_packages'];
		$history  = $decoded['sections']['activity'];
		$expect( strlen( $captured['text'] ) <= SupportReport::MAX_BYTES && 'Truncated' === $packages['state'] && 'Truncated' === $history['state'], 'bounded output with explicit truncation' );
		$expect( 100 === count( $packages['data']['records'] ) && 100 === count( $history['data']['records'] ), 'report preserves bounded package and Activity rows' );
		$expect( ! str_contains( $captured['text'], 'canary' ) && ! str_contains( $captured['text'], 'private-owner' ), 'whole-report privacy' );
		$expect( in_array( 'release_asset', array_column( $packages['data']['records'], 'source' ), true ), 'release asset source remains explicit' );
		$failed_stages = array_column( array_filter( $history['data']['records'], static fn( array $record ): bool => 'failed' === $record['status'] ), 'stage' );
		$expect( 1 === count( array_keys( $failed_stages, 'deployment.needs_attention', true ) ) && 1 === count( array_keys( $failed_stages, 'deployment.operator_reviewed', true ) ), 'attention and operator review remain distinct failed records' );
		return $assertions;
	} finally {
		if ( null !== $race ) {
			remove_filter( 'query', $race ); }
		if ( null !== $observer ) {
			remove_filter( 'query', $observer ); }
		if ( $transaction ) {
			$query( 'ROLLBACK' ); }
		if ( is_int( $second_user ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $second_user );
		}
		if ( $previous === $missing ) {
			delete_option( DiagnosticRunStore::OPTION_NAME );
		} else {
			update_option( DiagnosticRunStore::OPTION_NAME, $previous, false ); }
		wp_set_current_user( $previous_user );
	}
};
global $wpdb;
$ran_booster_support_assertions = $ran_booster_support_sql_proof( $wpdb );
echo esc_html( 'Support snapshot SQL proof passed: ' . $ran_booster_support_assertions . ' assertions.' ) . "\n";
