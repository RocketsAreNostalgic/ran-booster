<?php

use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Internal\CoreContainer;
use RAN\ManagedRepository;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;

/** @var list<string> $args Arguments supplied by WP-CLI eval-file. */
[$ran_booster_race_action, $ran_booster_race_run, $ran_booster_race_dir] = array_pad( $args, 3, '' );
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_RECOVERY_TEST_DISPOSABLE' )
	|| 1 !== preg_match( '/^[a-f0-9]{12}$/D', $ran_booster_race_run )
	|| realpath( ABSPATH ) !== realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) )
	|| realpath( WP_CONTENT_DIR ) !== realpath( ABSPATH ) . '/wp-content'
	|| realpath( WP_PLUGIN_DIR ) !== realpath( ABSPATH ) . '/wp-content/plugins'
	|| is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR )
	|| get_option( 'siteurl' ) !== getenv( 'RAN_BOOSTER_RECOVERY_TEST_URL' )
	|| is_link( ABSPATH . '.ran-booster-disposable-test-site' )
	|| "RAN Booster disposable test site\n" !== file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' )
	|| ! str_starts_with( $ran_booster_race_dir, sys_get_temp_dir() . '/ran-recovery-race.' )
	|| realpath( $ran_booster_race_dir ) !== $ran_booster_race_dir || is_link( $ran_booster_race_dir ) ) {
	throw new RuntimeException( 'The race proof requires exact disposable site and marker boundaries.' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
$ran_booster_race_container = require __DIR__ . '/core-container-fixture.php';
if ( ! $ran_booster_race_container instanceof CoreContainer ) {
	throw new RuntimeException( 'Core is unavailable.' );
}
$ran_booster_race_filesystem = new WP_Filesystem_Direct( null );
$ran_booster_race_slug       = 'ran-recovery-race-' . $ran_booster_race_run;
$ran_booster_race_identifier = $ran_booster_race_slug . '/entry.php';
$ran_booster_race_path       = WP_PLUGIN_DIR . '/' . $ran_booster_race_slug;
$ran_booster_race_key        = 'ran_recovery_race_' . $ran_booster_race_run;
$ran_booster_race_plugins    = $ran_booster_race_container->make( PluginRepository::class );
$ran_booster_race_removal    = $ran_booster_race_container->make( PackageRemovalService::class );
$ran_booster_race_attempts   = $ran_booster_race_container->make( DeploymentAttemptRepository::class );
global $wpdb;
$ran_booster_wait_for_peer_contention = static function ( string $dir, WP_Filesystem_Direct $filesystem, string $expected_info_fragment ): void {
	$attempting_value = $filesystem->get_contents( $dir . '/attempting' );
	if ( ! is_string( $attempting_value ) || 1 !== preg_match( '/\A[1-9]\d*\z/D', trim( $attempting_value ) ) ) {
		return;
	}
	$peer_id = (int) trim( $attempting_value );
	if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' )
		|| ! is_string( DB_USER ) || ! is_string( DB_PASSWORD ) || ! is_string( DB_NAME ) || ! is_string( DB_HOST ) ) {
		throw new RuntimeException( 'Race peer contention DB constants are unavailable.' );
	}
	global $wpdb;
	$host_parts = $wpdb->parse_db_host( DB_HOST );
	if ( ! is_array( $host_parts ) ) {
		throw new RuntimeException( 'Race peer contention DB host could not be parsed.' );
	}
	$port   = is_string( $host_parts[1] ) && '' !== $host_parts[1] ? (int) $host_parts[1] : null;
	$socket = is_string( $host_parts[2] ) && '' !== $host_parts[2] ? $host_parts[2] : null;
	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Observe peer PROCESSLIST lock contention on a raw mysqli session without invoking WordPress query hooks inside an active owner transaction.
	$control = new \mysqli( $host_parts[0], DB_USER, DB_PASSWORD, DB_NAME, $port, $socket );
	try {
		$peer_deadline = microtime( true ) + 10.0;
		$contending    = false;
		$escaped_like  = $control->real_escape_string( '%' . str_replace( array( '\\', '%', '_' ), array( '\\\\', '\\%', '\\_' ), $expected_info_fragment ) . '%' );
		while ( microtime( true ) < $peer_deadline ) {
			$alive_result = $control->query( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ' . $peer_id );
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Read single-scalar PROCESSLIST count from mysqli_result.
			if ( ! $alive_result instanceof \mysqli_result ) {
				throw new RuntimeException( 'Race peer PROCESSLIST query failed.' );
			}
			$alive_row = $alive_result->fetch_row();
			$alive_result->free();
			if ( 0 === (int) ( $alive_row[0] ?? 0 ) ) {
				throw new RuntimeException( 'Race peer exited before lock contention.' );
			}
			$waiting_result = $control->query(
				'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ' . $peer_id . " AND COMMAND = 'Query' AND INFO LIKE '" . $escaped_like . "'"
			);
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Read single-scalar PROCESSLIST contention count from mysqli_result.
			if ( ! $waiting_result instanceof \mysqli_result ) {
				throw new RuntimeException( 'Race peer contention query failed.' );
			}
			$waiting_row = $waiting_result->fetch_row();
			$waiting_result->free();
			if ( (int) ( $waiting_row[0] ?? 0 ) >= 1 ) {
				$contending = true;
				break;
			}
			usleep( 10000 );
		}
		if ( ! $contending || is_file( $dir . '/peer-result' ) ) {
			throw new RuntimeException( 'Race peer did not block on selection lock.' );
		}
	} finally {
		$control->close();
	}
};
// Complete real bootstrap before the owner can hold selection authority. Otherwise
// the peer blocks before this fixture can observe its intended transaction boundary.
if ( str_ends_with( $ran_booster_race_action, '-peer' ) ) {
	if ( ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . '/bootstrapped', 'ready', 0600 ) ) {
		throw new RuntimeException( 'Race peer bootstrap barrier unavailable.' );
	}
	$ran_booster_race_deadline = microtime( true ) + 20;
	while ( ! is_file( $ran_booster_race_dir . '/start-peer' ) ) {
		if ( microtime( true ) > $ran_booster_race_deadline ) {
			throw new RuntimeException( 'Race peer bootstrap barrier timed out.' );
		}
		usleep( 20000 );
	}
}
if ( in_array( $ran_booster_race_action, array( 'prepare', 'prepare-native' ), true ) ) {
	if ( file_exists( $ran_booster_race_path ) || is_link( $ran_booster_race_path ) || ! wp_mkdir_p( $ran_booster_race_path )
		|| ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_path . '/entry.php', "<?php\n/* Plugin Name: RAN recovery race\nVersion: 1.0\n*/\n", 0644 ) ) {
		throw new RuntimeException( 'Race fixture target is unavailable.' );
	}
	wp_clean_plugins_cache( false );
	$ran_booster_race_package = $ran_booster_race_plugins->installed_plugin_from_file( $ran_booster_race_identifier );
	$ran_booster_race_package->set_repository( new ManagedRepository( 'gh', 'example/' . $ran_booster_race_slug, $ran_booster_race_slug, 'main' ) );
	$ran_booster_race_plugins->adopt( $ran_booster_race_package )->require_success();
	update_option( $ran_booster_race_key, $ran_booster_race_removal->recovery_confirmation( 'plugin', $ran_booster_race_identifier ), false );
	if ( 'prepare-native' === $ran_booster_race_action ) {
		$ran_booster_native_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE package = %s AND package_type = 1', ran_booster_table_name(), $ran_booster_race_identifier ), ARRAY_A );
		if ( ! is_array( $ran_booster_native_row ) ) {
			throw new RuntimeException( 'Native sibling fixture record is unavailable.' );
		}
		unset( $ran_booster_native_row['id'] );
		$ran_booster_native_row['package'] = $ran_booster_race_slug . '/sibling.php';
		$ran_booster_native_selection      = $ran_booster_race_container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class );
		$ran_booster_native_store          = $ran_booster_race_container->make( \RAN\Storage\GitHubSelectionStore::class );
		$ran_booster_native_lock           = $ran_booster_race_container->make( \RAN\WordPress\WordPressUpdaterLock::class );
		$ran_booster_native_before         = $ran_booster_native_store->read();
		$ran_booster_native_token          = isset( $ran_booster_native_before['native_mutation'] ) ? null : $ran_booster_native_lock->try_acquire_without_reclaim();
		if ( null === $ran_booster_native_token || isset( $ran_booster_native_before['native_mutation'] ) ) {
			throw new RuntimeException( 'Native race requires idle, recoverable fixture authority.' );
		}
		try {
			$ran_booster_native_owner = $ran_booster_native_selection->begin_native_mutation( 'plugin', $ran_booster_race_identifier, static fn (): bool => $ran_booster_native_token === $ran_booster_native_lock->current_token(), $ran_booster_native_token );
			$ran_booster_native_selection->complete_native_mutation( $ran_booster_native_owner, 'plugin', $ran_booster_race_identifier, 'uncertain' );
		} finally {
			if ( ! $ran_booster_native_lock->release( $ran_booster_native_token ) ) {
				throw new RuntimeException( 'Native fixture lock cleanup failed.' );
			}
		}
		if ( 1 !== $wpdb->delete(
			ran_booster_table_name(),
			array(
				'package'      => $ran_booster_race_identifier,
				'package_type' => 1,
			)
		) || ! $ran_booster_race_filesystem->delete( $ran_booster_race_path, true ) ) {
			throw new RuntimeException( 'Native fixture could not establish absent unmanaged target.' );
		}
		update_option(
			$ran_booster_race_key,
			array(
				'row'       => $ran_booster_native_row,
				'before'    => $ran_booster_native_before,
				'uncertain' => $ran_booster_native_store->read(),
				'review'    => $ran_booster_race_removal->native_recovery_confirmation(),
			),
			false
		);
	}

	return;
}
if ( str_contains( $ran_booster_race_action, 'native' ) ) {
	$ran_booster_native_fixture = get_option( $ran_booster_race_key );
	if ( ! is_array( $ran_booster_native_fixture ) || ! is_array( $ran_booster_native_fixture['row'] ?? null ) || ! is_array( $ran_booster_native_fixture['before'] ?? null ) || ! is_array( $ran_booster_native_fixture['uncertain'] ?? null ) || ! is_array( $ran_booster_native_fixture['review'] ?? null ) || ! is_string( $ran_booster_native_fixture['review']['digest'] ?? null ) ) {
		throw new RuntimeException( 'Native race fixture is unavailable.' );
	}
	$ran_booster_native_selection = $ran_booster_race_container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class );
	$ran_booster_native_store     = $ran_booster_race_container->make( \RAN\Storage\GitHubSelectionStore::class );
	if ( 'cleanup-native' === $ran_booster_race_action ) {
		if ( ! in_array( $ran_booster_native_store->read(), array( $ran_booster_native_fixture['before'], $ran_booster_native_fixture['uncertain'] ), true ) ) {
			throw new RuntimeException( 'Native fixture cleanup refuses changed authority.' );
		}
		if ( is_file( $ran_booster_race_dir . '/killed-lock-token' ) ) {
			$ran_booster_cleanup_token = $ran_booster_race_filesystem->get_contents( $ran_booster_race_dir . '/killed-lock-token' );
			if ( is_string( $ran_booster_cleanup_token ) && 1 === preg_match( '/\A[1-9]\d*\z/D', trim( $ran_booster_cleanup_token ) ) ) {
				$ran_booster_cleanup_lock = $ran_booster_race_container->make( \RAN\WordPress\WordPressUpdaterLock::class );
				if ( $ran_booster_cleanup_lock->current_token() === trim( $ran_booster_cleanup_token ) && ! $ran_booster_cleanup_lock->release( trim( $ran_booster_cleanup_token ) ) ) {
					throw new RuntimeException( 'Native killed-lock cleanup failed.' );
				}
			}
		}
		$wpdb->delete(
			ran_booster_table_name(),
			array(
				'package'      => $ran_booster_race_slug . '/sibling.php',
				'package_type' => 1,
			)
		);
		if ( isset( $ran_booster_native_store->read()['native_mutation'] ) ) {
			$ran_booster_race_removal->recover_native_removal( $ran_booster_race_removal->native_recovery_confirmation()['digest'], true, true, static fn (): bool => true );
		}
		if ( $ran_booster_native_store->read() !== $ran_booster_native_fixture['before'] ) {
			throw new RuntimeException( 'Native race cleanup changed selection authority.' );
		}
		delete_option( $ran_booster_race_key );
		return;
	}
	if ( 'native-commit-lost' === $ran_booster_race_action || 'native-commit-killed' === $ran_booster_race_action ) {
		if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' )
			|| ! is_string( DB_HOST ) || ! is_string( DB_USER ) || ! is_string( DB_PASSWORD ) || ! is_string( DB_NAME ) ) {
			throw new RuntimeException( 'Native race DB constants are unavailable.' );
		}
		$ran_booster_native_host_parts = $wpdb->parse_db_host( DB_HOST );
		if ( ! is_array( $ran_booster_native_host_parts ) ) {
			throw new RuntimeException( 'Native race DB host could not be parsed.' );
		}
		$ran_booster_native_db_port      = is_string( $ran_booster_native_host_parts[1] ) && '' !== $ran_booster_native_host_parts[1] ? (int) $ran_booster_native_host_parts[1] : null;
		$ran_booster_native_db_socket    = is_string( $ran_booster_native_host_parts[2] ) && '' !== $ran_booster_native_host_parts[2] ? $ran_booster_native_host_parts[2] : null;
		$ran_booster_native_kill_mode    = 'native-commit-killed' === $ran_booster_race_action;
		$ran_booster_native_commit_state = (object) array(
			'committed' => false,
			'killed'    => false,
		);
		$ran_booster_native_fixture_dbh  = new class(
			$ran_booster_native_host_parts[0],
			DB_USER,
			DB_PASSWORD,
			DB_NAME,
			$ran_booster_native_db_port,
			$ran_booster_native_db_socket,
			$ran_booster_native_kill_mode,
			$ran_booster_native_commit_state
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Wrap the pinned mysqli connection handle so COMMIT can inject a lost response or pre-commit KILL on the real database session.
		) extends \mysqli {
			private bool $injected = false;

			public function __construct(
				string $host,
				string $user,
				string $password,
				string $database,
				?int $port,
				?string $socket,
				private bool $kill_mode,
				private \stdClass $state
			) {
				parent::__construct( $host, $user, $password, $database, $port, $socket );
			}

			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Preserve the mysqli::query() signature on this disposable commit-fault handle.
			public function query( string $query, int $result_mode = MYSQLI_STORE_RESULT ): \mysqli_result|bool {
				if ( ! $this->injected && 'COMMIT' === $query ) {
					$this->injected = true;
					if ( $this->kill_mode ) {
						$control  = new \wpdb( (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME, (string) DB_HOST );
						$kill_sql = $control->prepare( 'KILL %d', $this->thread_id );
						if ( ! is_string( $kill_sql ) || false === $control->query( $kill_sql ) ) {
							$control->close();
							throw new \RuntimeException( 'Native commit kill control query failed.' );
						}
						$deadline = microtime( true ) + 2.0;
						while ( microtime( true ) < $deadline ) {
							if ( 0 === (int) $control->get_var( $control->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $this->thread_id ) ) ) {
								break;
							}
							usleep( 10000 );
						}
						if ( 0 !== (int) $control->get_var( $control->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $this->thread_id ) ) ) {
							$control->close();
							throw new \RuntimeException( 'Killed native commit session did not exit.' );
						}
						$control->close();
						// Seed cache sentinels before the failed ROLLBACK exits so post-failure checks prove cache eviction and updater-lock release stay skipped.
						wp_cache_set( \RAN\Storage\GitHubSelectionStore::OPTION, 'sentinel-killed-selection', 'options' );
						wp_cache_set( 'auto_updater.lock', 'sentinel-killed-lock', 'options' );
						$this->state->killed = true;
						return parent::query( $query, $result_mode );
					}
					$result                 = parent::query( $query, $result_mode );
					$this->state->committed = ( false !== $result );
					throw new \RuntimeException( 'Fixture lost the successful COMMIT response.' );
				}
				return parent::query( $query, $result_mode );
			}
		};
		$wpdb->set_charset( $ran_booster_native_fixture_dbh );
		$ran_booster_native_dbh_property = new ReflectionProperty( wpdb::class, 'dbh' );
		$ran_booster_native_original_dbh = $ran_booster_native_dbh_property->getValue( $wpdb );
		$ran_booster_native_dbh_property->setValue( $wpdb, $ran_booster_native_fixture_dbh );
		$ran_booster_native_suppressed  = $wpdb->suppress_errors( true );
		$ran_booster_native_caught      = null;
		$ran_booster_native_reconnected = false;
		try {
			$ran_booster_race_removal->recover_native_removal( $ran_booster_native_fixture['review']['digest'], true, true, static fn (): bool => true );
		} catch ( Throwable $failure ) {
			$ran_booster_native_caught = $failure;
		} finally {
			$wpdb->suppress_errors( $ran_booster_native_suppressed );
			$ran_booster_native_current_dbh = $ran_booster_native_dbh_property->getValue( $wpdb );
			$ran_booster_native_reconnected = $ran_booster_native_current_dbh !== $ran_booster_native_fixture_dbh;
			$ran_booster_native_dbh_property->setValue( $wpdb, $ran_booster_native_original_dbh );
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Close disposable fixture mysqli handles restored off $wpdb.
			if ( $ran_booster_native_current_dbh instanceof \mysqli && $ran_booster_native_current_dbh !== $ran_booster_native_original_dbh && $ran_booster_native_current_dbh !== $ran_booster_native_fixture_dbh ) {
				try {
					$ran_booster_native_current_dbh->close();
				} catch ( Throwable ) {
					unset( $ran_booster_native_current_dbh );
				}
			}
			try {
				$ran_booster_native_fixture_dbh->close();
			} catch ( Throwable ) {
				unset( $ran_booster_native_fixture_dbh );
			}
		}
		if ( $ran_booster_native_kill_mode ) {
			if ( ! $ran_booster_native_commit_state->killed
				|| ! $ran_booster_native_caught instanceof \RAN\Storage\DatabaseLifecycleFailure
				|| 'selection_commit_uncertain' !== $ran_booster_native_caught->reason()
				|| ! $ran_booster_native_store->has_unclosed_transaction()
				|| $ran_booster_native_reconnected
				|| 'sentinel-killed-selection' !== wp_cache_get( \RAN\Storage\GitHubSelectionStore::OPTION, 'options' )
				|| 'sentinel-killed-lock' !== wp_cache_get( 'auto_updater.lock', 'options' ) ) {
				throw new RuntimeException( 'Killed hold-clear COMMIT was not isolated without cache or lock side effects.' );
			}
			wp_cache_delete( \RAN\Storage\GitHubSelectionStore::OPTION, 'options' );
			wp_cache_delete( 'auto_updater.lock', 'options' );
			$ran_booster_killed_token = $ran_booster_race_container->make( \RAN\WordPress\WordPressUpdaterLock::class )->current_token();
			if ( ! is_string( $ran_booster_killed_token ) || '' === $ran_booster_killed_token
				|| ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . '/killed-lock-token', $ran_booster_killed_token, 0600 ) ) {
				throw new RuntimeException( 'Killed hold-clear recovery did not preserve its updater lock token.' );
			}
			return;
		}
		if ( ! $ran_booster_native_commit_state->committed
			|| ! $ran_booster_native_caught instanceof RuntimeException
			|| $ran_booster_native_caught instanceof \RAN\PackageRemoval\NativeHoldLockReleaseFailure ) {
			throw new RuntimeException( 'Successful COMMIT with lost response was not reported as uncertain.' );
		}
		return;
	}
	if ( 'assert-native-commit-lost' === $ran_booster_race_action ) {
		if ( $ran_booster_native_store->read() !== $ran_booster_native_fixture['before'] || null !== $ran_booster_race_container->make( \RAN\WordPress\WordPressUpdaterLock::class )->current_token() ) {
			throw new RuntimeException( 'Fresh process did not observe committed recovery with released lock and unchanged selection.' );
		}
		echo 'Actual COMMIT followed by lost application response verified from a fresh process.' . PHP_EOL;
		return;
	}
	if ( 'assert-native-commit-killed' === $ran_booster_race_action ) {
		$ran_booster_killed_token = $ran_booster_race_filesystem->get_contents( $ran_booster_race_dir . '/killed-lock-token' );
		$ran_booster_killed_lock  = $ran_booster_race_container->make( \RAN\WordPress\WordPressUpdaterLock::class );
		if ( ! is_string( $ran_booster_killed_token ) || 1 !== preg_match( '/\A[1-9]\d*\z/D', trim( $ran_booster_killed_token ) ) ) {
			throw new RuntimeException( 'Killed hold-clear lock token fixture is missing.' );
		}
		$ran_booster_killed_token = trim( $ran_booster_killed_token );
		if ( $ran_booster_native_store->read() !== $ran_booster_native_fixture['uncertain']
			|| $ran_booster_killed_lock->current_token() !== $ran_booster_killed_token
			|| ! $ran_booster_killed_lock->release( $ran_booster_killed_token ) ) {
			throw new RuntimeException( 'Fresh process did not observe preserved native hold and intact updater lock after killed COMMIT.' );
		}
		echo 'Killed hold-clear COMMIT preserved native hold and updater lock without reconnect or lock_failed misclassification.' . PHP_EOL;
		return;
	}
	if ( str_starts_with( $ran_booster_race_action, 'assert-native-' ) ) {
		$ran_booster_native_recovery_first = 'assert-native-recovery' === $ran_booster_race_action;
		$ran_booster_native_owner_result   = $ran_booster_race_filesystem->get_contents( $ran_booster_race_dir . '/owner-result' );
		$ran_booster_native_peer_result    = $ran_booster_race_filesystem->get_contents( $ran_booster_race_dir . '/peer-result' );
		if ( 'success' !== $ran_booster_native_owner_result || ( $ran_booster_native_recovery_first ? 'success' : 'rejected' ) !== $ran_booster_native_peer_result || $ran_booster_native_store->read() !== ( $ran_booster_native_recovery_first ? $ran_booster_native_fixture['before'] : $ran_booster_native_fixture['uncertain'] ) || ! $ran_booster_race_plugins->has_management_record( $ran_booster_race_slug . '/sibling.php' ) ) {
			throw new RuntimeException( 'Native recovery and sibling insert did not serialize at selection authority.' );
		}
		echo 'Native recovery/sibling insert ordering passed.' . PHP_EOL;
		return;
	}
	$ran_booster_native_is_owner    = str_ends_with( $ran_booster_race_action, '-owner' );
	$ran_booster_native_is_recovery = str_starts_with( $ran_booster_race_action, 'native-recovery-' );
	$ran_booster_native_paused      = false;
	$ran_booster_native_tx_state    = (object) array(
		'in_pinned_tx'    => false,
		'management_seen' => false,
		'paused'          => false,
		'hook_hits'       => array(),
	);
	$ran_booster_native_result      = 'success';
	if ( $ran_booster_native_is_owner ) {
		if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' )
			|| ! is_string( DB_HOST ) || ! is_string( DB_USER ) || ! is_string( DB_PASSWORD ) || ! is_string( DB_NAME ) ) {
			throw new RuntimeException( 'Native race DB constants are unavailable.' );
		}
		$ran_booster_native_host_parts = $wpdb->parse_db_host( DB_HOST );
		if ( ! is_array( $ran_booster_native_host_parts ) ) {
			throw new RuntimeException( 'Native race DB host could not be parsed.' );
		}
		$ran_booster_native_db_port     = is_string( $ran_booster_native_host_parts[1] ) && '' !== $ran_booster_native_host_parts[1] ? (int) $ran_booster_native_host_parts[1] : null;
		$ran_booster_native_db_socket   = is_string( $ran_booster_native_host_parts[2] ) && '' !== $ran_booster_native_host_parts[2] ? $ran_booster_native_host_parts[2] : null;
		$ran_booster_native_fixture_dbh = new class(
			$ran_booster_native_host_parts[0],
			DB_USER,
			DB_PASSWORD,
			DB_NAME,
			$ran_booster_native_db_port,
			$ran_booster_native_db_socket,
			ran_booster_table_name(),
			Database::attempt_table_name(),
			$ran_booster_race_dir,
			$ran_booster_race_filesystem,
			$ran_booster_wait_for_peer_contention,
			$ran_booster_native_tx_state
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Pause the owner pinned mysqli session after management FOR UPDATE and immediately before attempt FOR UPDATE while verification stays hook-free.
		) extends \mysqli {
			public function __construct(
				string $host,
				string $user,
				string $password,
				string $database,
				?int $port,
				?string $socket,
				private string $management_table,
				private string $attempt_table,
				private string $dir,
				private WP_Filesystem_Direct $filesystem,
				private \Closure $wait_for_peer,
				private \stdClass $state
			) {
				parent::__construct( $host, $user, $password, $database, $port, $socket );
			}

			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- Preserve mysqli::query() signature on the disposable owner race handle.
			public function query( string $query, int $result_mode = MYSQLI_STORE_RESULT ): \mysqli_result|bool {
				if ( 'START TRANSACTION' === $query ) {
					$this->state->in_pinned_tx = true;
				} elseif ( 'COMMIT' === $query || 'ROLLBACK' === $query ) {
					$this->state->in_pinned_tx = false;
				}
				if ( $this->state->in_pinned_tx && str_contains( $query, $this->management_table ) && str_contains( $query, 'FOR UPDATE' ) ) {
					$this->state->management_seen = true;
				}
				if ( $this->state->in_pinned_tx && ! $this->state->paused && $this->state->management_seen
					&& str_contains( $query, $this->attempt_table ) && str_contains( $query, 'FOR UPDATE' ) ) {
					$this->state->paused = true;
					if ( ! $this->filesystem->put_contents( $this->dir . '/locked', 'ready', 0600 ) ) {
						throw new \RuntimeException( 'Native race barrier unavailable.' );
					}
					$deadline = microtime( true ) + 20;
					while ( ! is_file( $this->dir . '/release' ) ) {
						if ( microtime( true ) > $deadline ) {
							throw new \RuntimeException( 'Native race barrier timed out.' );
						}
						usleep( 20000 );
					}
					( $this->wait_for_peer )( $this->dir, $this->filesystem, \RAN\Storage\GitHubSelectionStore::OPTION );
				}
				return parent::query( $query, $result_mode );
			}
		};
		$wpdb->set_charset( $ran_booster_native_fixture_dbh );
		$ran_booster_native_dbh_property = new ReflectionProperty( wpdb::class, 'dbh' );
		$ran_booster_native_original_dbh = $ran_booster_native_dbh_property->getValue( $wpdb );
		$ran_booster_native_dbh_property->setValue( $wpdb, $ran_booster_native_fixture_dbh );
		$ran_booster_native_hook_guard = static function ( string $query ) use ( $ran_booster_native_tx_state ): string {
			if ( $ran_booster_native_tx_state->in_pinned_tx ) {
				$ran_booster_native_tx_state->hook_hits[] = 'query:' . $query;
			}
			return $query;
		};
		$ran_booster_native_opt_guard  = static function ( mixed $value = null ) use ( $ran_booster_native_tx_state ): mixed {
			if ( $ran_booster_native_tx_state->in_pinned_tx ) {
				$ran_booster_native_tx_state->hook_hits[] = 'option:' . current_filter();
			}
			return $value;
		};
		add_filter( 'query', $ran_booster_native_hook_guard );
		foreach ( array( 'pre_option_ran_booster_db_version', 'option_ran_booster_db_version', 'pre_option_auto_updater.lock', 'pre_option_ran_booster_github_selection' ) as $ran_booster_native_hook_name ) {
			add_filter( $ran_booster_native_hook_name, $ran_booster_native_opt_guard );
		}
		try {
			if ( $ran_booster_native_is_recovery ) {
				$ran_booster_race_removal->recover_native_removal( $ran_booster_native_fixture['review']['digest'], true, true, static fn (): bool => true );
			} else {
				$ran_booster_race_plugins->require_recovery_ready();
				$ran_booster_native_store->selection_transaction(
					static function ( callable $execute ) use ( $ran_booster_native_store, $ran_booster_native_selection, $ran_booster_race_plugins, $ran_booster_race_attempts, $ran_booster_race_identifier, $ran_booster_native_fixture, $wpdb ): void {
						$ran_booster_native_store->read( true );
						$ran_booster_race_plugins->native_recovery_record( $ran_booster_race_identifier, true, $execute );
						$ran_booster_race_attempts->assert_selection_transition_idle( $ran_booster_native_selection, $execute );
						// Deliberate fixture SQL writer, not a claim that normal adoption permits an absent target.
						if ( 1 !== $wpdb->insert( ran_booster_table_name(), $ran_booster_native_fixture['row'] ) ) {
							throw new RuntimeException( 'Native sibling insert failed.' );
						}
					}
				);
			}
		} catch ( Throwable ) {
			$ran_booster_native_result = 'rejected';
		} finally {
			foreach ( array( 'pre_option_ran_booster_db_version', 'option_ran_booster_db_version', 'pre_option_auto_updater.lock', 'pre_option_ran_booster_github_selection' ) as $ran_booster_native_hook_name ) {
				remove_filter( $ran_booster_native_hook_name, $ran_booster_native_opt_guard );
			}
			remove_filter( 'query', $ran_booster_native_hook_guard );
			$ran_booster_native_dbh_property->setValue( $wpdb, $ran_booster_native_original_dbh );
			try {
				$ran_booster_native_fixture_dbh->close();
			} catch ( Throwable ) {
				unset( $ran_booster_native_fixture_dbh );
			}
		}
		$ran_booster_native_paused = $ran_booster_native_tx_state->paused;
		if ( $ran_booster_native_is_recovery && array() !== $ran_booster_native_tx_state->hook_hits ) {
			throw new RuntimeException( 'Native recovery triggered WordPress hooks inside selection_transaction(): ' . implode( '; ', $ran_booster_native_tx_state->hook_hits ) );
		}
	} else {
		$ran_booster_native_peer_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		if ( $ran_booster_native_peer_id <= 0 || ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . '/attempting', (string) $ran_booster_native_peer_id, 0600 ) ) {
			throw new RuntimeException( 'Native peer race barrier unavailable.' );
		}
		$ran_booster_native_paused = true;
		try {
			if ( $ran_booster_native_is_recovery ) {
				$ran_booster_race_removal->recover_native_removal( $ran_booster_native_fixture['review']['digest'], true, true, static fn (): bool => true );
			} else {
				$ran_booster_race_plugins->require_recovery_ready();
				$ran_booster_native_store->selection_transaction(
					static function ( callable $execute ) use ( $ran_booster_native_store, $ran_booster_native_selection, $ran_booster_race_plugins, $ran_booster_race_attempts, $ran_booster_race_identifier, $ran_booster_native_fixture, $wpdb ): void {
						$ran_booster_native_store->read( true );
						$ran_booster_race_plugins->native_recovery_record( $ran_booster_race_identifier, true, $execute );
						$ran_booster_race_attempts->assert_selection_transition_idle( $ran_booster_native_selection, $execute );
						// Deliberate fixture SQL writer, not a claim that normal adoption permits an absent target.
						if ( 1 !== $wpdb->insert( ran_booster_table_name(), $ran_booster_native_fixture['row'] ) ) {
							throw new RuntimeException( 'Native sibling insert failed.' );
						}
					}
				);
			}
		} catch ( Throwable ) {
			$ran_booster_native_result = 'rejected';
		}
	}
	if ( ! $ran_booster_native_paused || ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . ( $ran_booster_native_is_owner ? '/owner-result' : '/peer-result' ), $ran_booster_native_result, 0600 ) ) {
		throw new RuntimeException( 'Native race process missed its required lock schedule.' );
	}
	return;
}
if ( 'cleanup' === $ran_booster_race_action ) {
	if ( realpath( $ran_booster_race_path ) !== $ran_booster_race_path || is_link( $ran_booster_race_path ) ) {
		throw new RuntimeException( 'Unsafe race cleanup target.' );
	}
	$wpdb->delete( Database::attempt_table_name(), array( 'package_slug' => $ran_booster_race_slug ) );
	$wpdb->delete(
		ran_booster_table_name(),
		array(
			'package'      => $ran_booster_race_identifier,
			'package_type' => 1,
		)
	);
	delete_option( $ran_booster_race_key );
	if ( true !== delete_plugins( array( $ran_booster_race_identifier ) ) ) {
		throw new RuntimeException( 'Race fixture cleanup failed.' );
	}
	return;
}
if ( 'assert-recovery' === $ran_booster_race_action || 'assert-admission' === $ran_booster_race_action ) {
	$ran_booster_race_owner   = $ran_booster_race_filesystem->get_contents( $ran_booster_race_dir . '/owner-result' );
	$ran_booster_race_peer    = $ran_booster_race_filesystem->get_contents( $ran_booster_race_dir . '/peer-result' );
	$ran_booster_race_present = $ran_booster_race_plugins->has_management_record( $ran_booster_race_identifier );
	$ran_booster_race_count   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE package_slug = %s', Database::attempt_table_name(), $ran_booster_race_slug ) );
	if ( 'success' !== $ran_booster_race_owner || 'rejected' !== $ran_booster_race_peer
		|| ( 'assert-recovery' === $ran_booster_race_action ? $ran_booster_race_present || 0 !== $ran_booster_race_count : ! $ran_booster_race_present || 1 !== $ran_booster_race_count ) ) {
		throw new RuntimeException( 'Admission/recovery transaction ordering failed.' );
	}
	echo esc_html( $ran_booster_race_action . ': exact management-row election passed.' ) . PHP_EOL;
	return;
}
$ran_booster_race_confirmation = get_option( $ran_booster_race_key );
if ( ! is_array( $ran_booster_race_confirmation )
	|| ! is_string( $ran_booster_race_confirmation['adoption_token'] ?? null )
	|| ! is_string( $ran_booster_race_confirmation['digest'] ?? null )
	|| ! is_string( $ran_booster_race_confirmation['installed_digest'] ?? null ) ) {
	throw new RuntimeException( 'Race confirmation is unavailable.' );
}
$ran_booster_race_owner  = str_ends_with( $ran_booster_race_action, '-owner' );
$ran_booster_race_paused = false;
$ran_booster_race_filter = static function ( string $query ) use ( &$ran_booster_race_paused, $ran_booster_race_owner, $ran_booster_race_dir, $ran_booster_race_filesystem, $ran_booster_wait_for_peer_contention ): string {
	$target = $ran_booster_race_owner ? Database::attempt_table_name() : ran_booster_table_name();
	if ( ! $ran_booster_race_paused && str_contains( $query, $target ) && str_contains( $query, 'FOR UPDATE' ) ) {
		$ran_booster_race_paused = true;
		if ( ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . ( $ran_booster_race_owner ? '/locked' : '/attempting' ), 'ready', 0600 ) ) {
			throw new RuntimeException( 'Race barrier unavailable.' );
		}
		if ( $ran_booster_race_owner ) {
			// Both owners reached attempt access only after their actual management SELECT FOR UPDATE returned.
			$deadline = microtime( true ) + 20;
			while ( ! is_file( $ran_booster_race_dir . '/release' ) ) {
				if ( microtime( true ) > $deadline ) {
					throw new RuntimeException( 'Race owner barrier timed out.' );
				}
				usleep( 20000 );
			}
			$ran_booster_wait_for_peer_contention( $ran_booster_race_dir, $ran_booster_race_filesystem, \RAN\Storage\GitHubSelectionStore::OPTION );
		}
	}
	return $query;
};
add_filter( 'query', $ran_booster_race_filter );
$ran_booster_race_result = 'success';
try {
	if ( str_starts_with( $ran_booster_race_action, 'recovery-' ) ) {
		if ( ! $ran_booster_race_owner ) {
			$ran_booster_race_peer_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			if ( $ran_booster_race_peer_id <= 0 || ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . '/attempting', (string) $ran_booster_race_peer_id, 0600 ) ) {
				throw new RuntimeException( 'Recovery peer race barrier unavailable.' );
			}
			$ran_booster_race_paused = true;
		}
		$ran_booster_race_removal->recover( 'plugin', $ran_booster_race_identifier, $ran_booster_race_confirmation['adoption_token'], $ran_booster_race_confirmation['digest'], $ran_booster_race_confirmation['installed_digest'] );
	} elseif ( str_starts_with( $ran_booster_race_action, 'admission-' ) ) {
		$ran_booster_race_request = new DeploymentRequest( 'example/' . $ran_booster_race_slug, null, false, 'main', $ran_booster_race_slug, null, DeploymentPolicy::MANUAL, null, installed_identifier: $ran_booster_race_identifier, adoption_token: $ran_booster_race_confirmation['adoption_token'] );
		$ran_booster_race_attempts->admit_and_claim_manual( 'update', 'plugin', 'gh', $ran_booster_race_slug, $ran_booster_race_request, 'main', 'branch', 1 );
	} else {
		throw new RuntimeException( 'Unknown race operation.' );
	}
} catch ( Throwable ) {
	$ran_booster_race_result = 'rejected';
} finally {
	remove_filter( 'query', $ran_booster_race_filter );
}
if ( ! $ran_booster_race_paused || ! $ran_booster_race_filesystem->put_contents( $ran_booster_race_dir . ( $ran_booster_race_owner ? '/owner-result' : '/peer-result' ), $ran_booster_race_result, 0600 ) ) {
	throw new RuntimeException( 'Race process did not reach the required management-row schedule.' );
}
