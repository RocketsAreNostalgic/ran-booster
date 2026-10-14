<?php

// Included by the maintained disposable core-updater proof after package cleanup.

use RAN\Deployment\DeploymentStorageFailure;
use RAN\WordPress\WordPressUpdaterLock;

final class RAN_Booster_UpdaterLockInstalledSmoke {

	private const NAME = 'auto_updater.lock';

	/** @var list<string> */
	private array $owned_tokens = array();

	public function __construct( private readonly wpdb $database ) {}

	public function run(): void {
		$this->check( null === $this->row(), 'The installed lock proof requires an idle native updater lock.' );
		$lock = new WordPressUpdaterLock();
		try {
			$this->check( null === $lock->current_token(), 'An absent native row must have no current token.' );
			$this->prime_cache();
			$token                = $lock->acquire();
			$this->owned_tokens[] = $token;
			$this->check( $token === $this->row() && $token === $lock->current_token(), 'The actual facade must acquire and read its exact native row.' );
			$this->check_cache( false );
			$this->prime_cache();
			$this->check( ! $lock->release( $token . '-wrong' ), 'Wrong-token release must fail.' );
			$this->check( $token === $this->row(), 'Wrong-token release must preserve the native owner.' );
			$this->check_cache( true );
			$this->check( $lock->release( $token ), 'The exact native token must release.' );
			$this->check_cache( false );

			$stale                = (string) ( time() - 3601 );
			$this->owned_tokens[] = $stale;
			$this->check( add_option( self::NAME, $stale, '', false ), 'The disposable stale row must be created.' );
			$this->prime_cache();
			$this->check( null === $lock->current_token(), 'An expired row must not authorize an automatic update.' );
			$token                = $lock->acquire();
			$this->owned_tokens[] = $token;
			$this->check( $stale !== $token && $token === $this->row(), 'The actual facade must reclaim only the stale owner.' );
			$this->check_cache( false );
			$this->check( $lock->release( $token ), 'The reclaimed token must release.' );

			$this->expect_storage_failure( 'SELECT', static fn () => $lock->current_token() );
			$this->expect_storage_failure( 'INSERT', static fn () => $lock->acquire() );
			$token                = $lock->acquire();
			$this->owned_tokens[] = $token;
			$this->expect_storage_failure( 'DELETE', static fn () => $lock->release( $token ) );
			$this->check( $token === $this->row(), 'A failed DELETE must preserve its owner.' );
			$this->check( $lock->release( $token ), 'The owner must remain releasable after the query filter is removed.' );

			$callback_failure = new RuntimeException( 'Disposable lock callback failure.' );
			$propagated       = false;
			try {
				$lock->run(
					function () use ( $lock, $callback_failure ): void {
						$held = $lock->current_token();
						if ( ! is_string( $held ) ) {
							throw new RuntimeException( 'The run callback must observe a held native row.' );
						}
						$this->owned_tokens[] = $held;
						throw $callback_failure;
					}
				);
			} catch ( RuntimeException $caught ) {
				$this->check( $caught === $callback_failure && null === $this->row(), 'The original callback exception must survive exact cleanup.' );
				$propagated = true;
			}

			$this->check( $propagated, 'A callback failure must propagate.' );

			$replacement          = (string) ( time() + 60 );
			$this->owned_tokens[] = $replacement;
			try {
				$lock->run(
					function () use ( $lock, $replacement ): void {
						$held = $lock->current_token();
						if ( ! is_string( $held ) ) {
							throw new RuntimeException( 'The replacement probe must start while its original owner is held.' );
						}
						$this->owned_tokens[] = $held;
						$this->check(
							1 === $this->database->update(
								$this->database->options,
								array( 'option_value' => $replacement ),
								array(
									'option_name'  => self::NAME,
									'option_value' => $held,
								)
							),
							'The controlled replacement must change exactly its original native row.'
						);
					}
				);
				throw new RuntimeException( 'A replaced token must not report successful release.' );
			} catch ( RuntimeException $caught ) {
				$this->check( 'The WordPress updater lock could not be released.' === $caught->getMessage(), 'Replacement must retain the Core release diagnostic.' );
			}
			$this->check( $replacement === $this->row() && $replacement === $lock->current_token(), 'The original owner must not delete its replacement.' );
		} finally {
			foreach ( array_unique( $this->owned_tokens ) as $owned_token ) {
				$lock->release( $owned_token );
			}
			$this->check( null === $this->row(), 'The disposable native lock proof must leave no owned row.' );
		}
	}

	/** @param callable(): mixed $operation */
	private function expect_storage_failure( string $prefix, callable $operation ): void {
		$injected = false;
		$filter   = function ( string $query ) use ( $prefix, &$injected ): string {
			if ( str_starts_with( $query, $prefix ) && str_contains( $query, $this->database->options ) && str_contains( $query, self::NAME ) ) {
				$injected = true;
				return 'SELECT /* disposable native lock failure */ FROM';
			}
			return $query;
		};
		$previous = $this->database->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$operation();
			throw new RuntimeException( 'A native query failure must be translated.' );
		} catch ( DeploymentStorageFailure $caught ) {
			$this->check( $injected && 'RAN Booster could not safely store deployment state.' === $caught->getMessage(), 'Native read/write failures must retain the safe Core storage classification.' );
		} finally {
			remove_filter( 'query', $filter );
			$this->database->suppress_errors( $previous );
		}
	}

	private function row(): ?string {
		$value = $this->database->get_var( $this->database->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $this->database->options, self::NAME ) );
		$this->check( '' === $this->database->last_error, 'The installed native lock fixture row must remain readable.' );
		return is_string( $value ) ? $value : null;
	}

	private function prime_cache(): void {
		wp_cache_set( self::NAME, 'disposable-lock-cache', 'options' );
		wp_cache_set( 'notoptions', array( 'disposable-lock-cache' => true ), 'options' );
		$this->check_cache( true );
	}

	private function check_cache( bool $expected ): void {
		foreach ( array( self::NAME, 'notoptions' ) as $key ) {
			$found = false;
			wp_cache_get( $key, 'options', false, $found );
			$this->check( $expected === $found, 'Actual WordPress option-cache invalidation must follow only successful ownership writes.' );
		}
	}

	private function check( bool $condition, string $message ): void {
		if ( ! $condition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Disposable CLI assertion text is not rendered as HTML.
			throw new RuntimeException( $message );
		}
	}
}

global $wpdb;

$ran_booster_lock_marker = ABSPATH . '.ran-booster-disposable-test-site';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact local disposable-site marker before the native SQL proof.
if ( ! is_file( $ran_booster_lock_marker ) || is_link( $ran_booster_lock_marker ) || is_link( rtrim( ABSPATH, '/\\' ) ) || realpath( WP_CONTENT_DIR ) !== realpath( ABSPATH . 'wp-content' ) || is_link( WP_CONTENT_DIR ) || 'RAN Booster disposable test site' !== trim( (string) file_get_contents( $ran_booster_lock_marker ) ) || 'http://localhost' !== get_option( 'siteurl' ) ) {
	throw new RuntimeException( 'The installed native lock proof requires the marked localhost disposable site.' );
}
if ( ! $wpdb instanceof wpdb || is_multisite() ) {
	throw new RuntimeException( 'The installed native lock proof requires ordinary single-site WordPress wpdb.' );
}
$ran_booster_lock_smoke = new RAN_Booster_UpdaterLockInstalledSmoke( $wpdb );
$ran_booster_lock_smoke->run();
WP_CLI::success( 'Actual Core native lock passed installed wpdb, option cache, query-filter failure, stale-owner and callback-cleanup checks.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process.)
