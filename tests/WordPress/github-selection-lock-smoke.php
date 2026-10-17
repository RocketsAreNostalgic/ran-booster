<?php

declare(strict_types=1);

use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SiteKeyStore;
use RAN\Storage\GitHubSelectionStore;

// Both processes use an isolated dummy key, never the installed site's key option.
$ran_booster_fixture_secrets = static function ( string $path, ?callable $guard = null ): SecretsFile {
	$key = new class( $path ) extends SiteKeyStore {
		public function __construct( private string $fixture_path ) {
			parent::__construct();
		}
		public function load( bool $repair_autoload = true ): ?string {
			unset( $repair_autoload );
			return is_file( $this->fixture_path ) ? str_repeat( 'k', 32 ) : null;
		}
		public function load_or_create(): array {
			return array(
				'key'     => str_repeat( 'k', 32 ),
				'created' => true,
			);
		}
		public function delete_exact( #[SensitiveParameter] string $key ): bool {
			return str_repeat( 'k', 32 ) === $key;
		}
	};
	return new SecretsFile( $path, array(), key_store: $key, github_credential_access: $guard );
};

$ran_booster_arguments = $GLOBALS['argv'] ?? array();
if ( '--selection-lock-child' === ( $ran_booster_arguments[1] ?? null ) ) {
	if ( 8 !== count( $ran_booster_arguments ) || count( array_filter( $ran_booster_arguments, 'is_string' ) ) !== 8 ) {
		throw new RuntimeException( 'Invalid isolated lock proof arguments.' );
	}
	require $ran_booster_arguments[2]; // Installed disposable WordPress bootstrap; no copied database credentials.
	global $wpdb;
	$ran_booster_fixture_table = $ran_booster_arguments[3];
	if ( ! preg_match( '/\A' . preg_quote( $wpdb->prefix, '/' ) . 'ran_selection_smoke_[a-f0-9]{12}\z/D', $ran_booster_fixture_table ) ) {
		throw new RuntimeException( 'Lock proof refuses a non-fixture table.' );
	}
	$ran_booster_fixture_store = new GitHubSelectionStore( $wpdb, $ran_booster_fixture_table );
	if ( false === $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' ) ) {
		throw new RuntimeException( 'Child database timeout could not be set.' );
	}
	try {
		if ( 'inverse' === $ran_booster_arguments[5] ) {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Child transaction could not start.' );
			}
			$ran_booster_fixture_store->read( true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private bounded process rendezvous, no credential data.
		file_put_contents( $ran_booster_arguments[6], 'ready' );
		$ran_booster_fixture_count = count( $ran_booster_fixture_secrets( $ran_booster_arguments[4] )->github_adoption_inventory() );
		$ran_booster_fixture_store->read( true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Only the observed dummy-profile count crosses the child boundary.
		file_put_contents( $ran_booster_arguments[7], (string) $ran_booster_fixture_count );
	} finally {
		$wpdb->query( 'ROLLBACK' );
	}
	exit( 0 );
}

return static function ( wpdb $database, string $table, GitHubProviderSelection $context, string $root ) use ( $ran_booster_fixture_secrets ): void {
	$previous_timeout = (int) $database->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
	$suppressed       = $database->suppress_errors( true );
	if ( false === $database->query( 'SET SESSION innodb_lock_wait_timeout = 1' ) ) {
		throw new RuntimeException( 'Fixture database timeout could not be set.' );
	}
	$metadata = array(
		'label'         => 'Disposable lock proof',
		'kind'          => 'classic',
		'configuration' => array( 'owner' => '' ),
	);
	try {
		foreach ( array( 'ordered', 'inverse' ) as $mode ) {
			$path      = $root . '/lock-' . $mode . '.json';
			$ready     = $path . '.ready';
			$result    = $path . '.result';
			$log       = $path . '.log';
			$process   = null;
			$entered   = false;
			$timed_out = false;
			try {
				$ran_booster_fixture_secrets( $path )->save_credential( 'gh', 'seed', $metadata, 'dummy-seed-not-a-token' );
				$before    = hash_file( 'sha256', $path );
				$guard     = static function ( ?array $record ) use ( $context, $database, $table, $path, $ready, $result, $log, $mode, &$process, &$entered, &$timed_out ): bool {
					if ( ! $entered ) {
						// A separate open description proves this callback holds the actual SecretsFile lock.
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Nonblocking ownership observation of the private fixture lock only.
						$probe = fopen( $path . '.lock', 'r+b' );
						if ( false === $probe ) {
							throw new RuntimeException( 'Fixture lock missing.' );
						}
						$free = flock( $probe, LOCK_EX | LOCK_NB );
						if ( $free ) {
							flock( $probe, LOCK_UN );
						}
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the private lock observation handle.
						fclose( $probe );
						if ( ! $free ) {
							$entered = true;
							$pipes   = array();
							// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Installed CLI proof needs an independent process with its own database connection and wall deadline.
							$process = proc_open(
								array( 'timeout', '--signal=KILL', '10s', PHP_BINARY, __FILE__, '--selection-lock-child', ABSPATH . 'wp-load.php', $table, $path, $mode, $ready, $result ),
								array(
									0 => array( 'file', '/dev/null', 'r' ),
									1 => array( 'file', $log, 'a' ),
									2 => array( 'file', $log, 'a' ),
								),
								$pipes
							);
							if ( ! is_resource( $process ) ) {
								throw new RuntimeException( 'Fixture child could not start.' );
							}
							$deadline = microtime( true ) + 5;
							while ( ! is_file( $ready ) && microtime( true ) < $deadline ) {
								usleep( 10000 );
								clearstatcache( true, $ready );
							}
							if ( ! is_file( $ready ) ) {
								throw new RuntimeException( 'Fixture child did not reach its lock boundary.' );
							}
						}
					}
					$allowed   = $context->credential_access_allowed(
						null === $record ? null : array(
							'kind'          => $record['kind'] ?? null,
							'configuration' => $record['configuration'] ?? null,
						)
					);
					$timed_out = $timed_out || str_contains( strtolower( $database->last_error ), 'lock wait timeout' );
					return $allowed;
				};
				$succeeded = false;
				try {
					$ran_booster_fixture_secrets( $path, $guard )->save_credential( 'gh', 'contended', $metadata, 'dummy-new-not-a-token' );
					$succeeded = true;
				} catch ( RuntimeException $failure ) {
					if ( 'inverse' !== $mode || 'GitHub credential access is unavailable.' !== $failure->getMessage() ) {
						throw $failure;
					}
				}
				if ( ! $entered || ! is_resource( $process ) || 0 !== proc_close( $process ) ) {
					throw new RuntimeException( 'Actual lock-boundary child proof failed.' );
				}
				$process = null;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the bounded dummy-profile count.
				$count = file_get_contents( $result );
				if ( 'ordered' === $mode && ( ! $succeeded || $timed_out || '2' !== $count ) ) {
					throw new RuntimeException( 'Consistent sidecar-first ordering did not complete.' );
				}
				if ( 'inverse' === $mode && ( $succeeded || ! $timed_out || '1' !== $count || hash_file( 'sha256', $path ) !== $before ) ) {
					throw new RuntimeException( 'Inverse ordering did not refuse without persistence.' );
				}
			} finally {
				// The external wall deadline kills the whole child command even if a lock stalls.
				if ( is_resource( $process ) ) {
					proc_close( $process );
				}
				foreach ( array( $path, $path . '.lock', $ready, $result, $log ) as $file ) {
					if ( is_file( $file ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exact private dummy fixture paths; no installed sidecar or key is touched.
						unlink( $file );
					}
				}
			}
		}
	} finally {
		$restore = $database->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', $previous_timeout );
		if ( is_string( $restore ) ) {
			$database->query( $restore );
		}
		$database->suppress_errors( $suppressed );
	}
};
