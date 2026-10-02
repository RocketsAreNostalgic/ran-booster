<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Test doubles stay local to this focused persistence test and base64 inspects the defined key encoding.
// Native files model one atomic database option across forked processes.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Secrets\SiteKeyStore;
use RuntimeException;

#[CoversClass( SiteKeyStore::class )]
final class SiteKeyStoreTest extends TestCase {

	public const KEY     = '12345678901234567890123456789012';
	private const WINNER = 'abcdefghijklmnopqrstuvwxyzABCDEF';

	public function test_absent_option_does_not_create_akey(): void {
		$store = new TestSiteKeyStore();

		self::assertNull( $store->load() );
		self::assertSame( 0, $store->add_calls );
		self::assertSame( 0, $store->repair_calls );
	}

	public function test_first_creation_stores_canonical_non_autoloaded_base64_and_reads_it_back(): void {
		$store                = new TestSiteKeyStore();
		$store->generated_key = self::KEY;

		$result = $store->load_or_create();

		self::assertSame( self::KEY, $result['key'] );
		self::assertTrue( $result['created'] );
		self::assertSame( base64_encode( self::KEY ), $store->stored_value );
		self::assertSame( 44, strlen( (string) $store->stored_value ) );
		self::assertSame( 'off', $store->autoload );
		self::assertSame( 1, $store->add_calls );
	}

	public function test_concurrent_creation_loser_uses_the_winning_valid_key(): void {
		$store                = new TestSiteKeyStore();
		$store->generated_key = self::KEY;
		$store->race_winner   = base64_encode( self::WINNER );

		$result = $store->load_or_create();

		self::assertSame( self::WINNER, $result['key'] );
		self::assertFalse( $result['created'] );
		self::assertSame( base64_encode( self::WINNER ), $store->stored_value );
		self::assertSame( 1, $store->cache_invalidations );
		self::assertFalse( $store->stale_negative_cache );
	}

	public function test_concurrent_first_creators_elect_one_random_key_across_processes(): void {
		if ( ! function_exists( 'pcntl_fork' )
			|| ! function_exists( 'pcntl_waitpid' )
			|| ! function_exists( 'pcntl_wifexited' )
			|| ! function_exists( 'pcntl_wexitstatus' ) ) {
			self::markTestSkipped( 'The PCNTL extension is required for the first-key race proof.' );
		}

		$directory = sys_get_temp_dir() . '/ran-booster-key-race-' . bin2hex( random_bytes( 8 ) );
		$key_path  = $directory . '/option-value';
		$barrier   = $directory . '/start';
		$children  = array();
		$count     = 6;
		self::assertTrue( mkdir( $directory, 0700 ) );

		try {
			for ( $index = 0; $index < $count; ++$index ) {
				$pid = pcntl_fork();
				self::assertNotSame( -1, $pid );
				if ( 0 === $pid ) {
					while ( ! is_file( $barrier ) ) {
						usleep( 1000 );
					}

					try {
						$result  = ( new AtomicFileSiteKeyStore( $key_path ) )->load_or_create();
						$payload = json_encode(
							array(
								'key'     => base64_encode( $result['key'] ),
								'created' => $result['created'],
							),
							JSON_THROW_ON_ERROR
						);
						file_put_contents( $directory . '/result-' . $index . '.json', $payload );
						exit( 0 );
					} catch ( \Throwable ) {
						exit( 1 );
					}
				}

				$children[] = $pid;
			}

			self::assertNotFalse( file_put_contents( $barrier, 'start' ) );
			foreach ( $children as $pid ) {
				self::assertSame( $pid, pcntl_waitpid( $pid, $status ) );
				self::assertTrue( pcntl_wifexited( $status ) );
				self::assertSame( 0, pcntl_wexitstatus( $status ) );
			}

			$results = array();
			for ( $index = 0; $index < $count; ++$index ) {
				$decoded = json_decode(
					(string) file_get_contents( $directory . '/result-' . $index . '.json' ),
					true,
					4,
					JSON_THROW_ON_ERROR
				);
				self::assertIsArray( $decoded );
				$results[] = $decoded;
			}

			self::assertSame( 1, count( array_filter( $results, static fn ( array $result ): bool => true === $result['created'] ) ) );
			self::assertCount( 1, array_unique( array_column( $results, 'key' ) ) );
			self::assertSame( $results[0]['key'], file_get_contents( $key_path ) );
		} finally {
			$paths = glob( $directory . '/*' );
			foreach ( false === $paths ? array() : $paths as $path ) {
				unlink( $path );
			}
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}
	}

	public function test_existing_valid_key_is_never_replaced(): void {
		$store               = new TestSiteKeyStore();
		$store->stored_value = base64_encode( self::WINNER );

		$result = $store->load_or_create();

		self::assertSame( self::WINNER, $result['key'] );
		self::assertFalse( $result['created'] );
		self::assertSame( 0, $store->add_calls );
	}

	#[DataProvider( 'malformed_stored_key_provider' )]
	public function test_malformed_stored_keys_fail_closed_without_replacement( mixed $stored ): void {
		$store               = new TestSiteKeyStore();
		$store->stored_value = $stored;

		try {
			$store->load_or_create();
			self::fail( 'A malformed stored key must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( is_string( $stored ) ? $stored : 'sentinel', $exception->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The trace is inspected only to prove key redaction.
			$trace_arguments = var_export( $exception->getTrace()[0]['args'] ?? array(), true );
			self::assertStringNotContainsString( is_string( $stored ) ? $stored : 'sentinel', $trace_arguments );
		}

		self::assertSame( 0, $store->add_calls );
	}

	/** @return array<string, array{mixed}> */
	public static function malformed_stored_key_provider(): array {
		return array(
			'non-string'           => array( array( 'sentinel' ) ),
			'boolean false'        => array( false ),
			'invalid base64'       => array( 'sentinel-not-base64!' ),
			'unpadded base64'      => array( rtrim( base64_encode( self::KEY ), '=' ) ),
			'wrong decoded length' => array( base64_encode( 'too-short' ) ),
		);
	}

	public function test_valid_autoloaded_key_is_repaired_without_changing_its_bytes(): void {
		$store               = new TestSiteKeyStore();
		$store->stored_value = base64_encode( self::KEY );
		$store->autoload     = 'on';
		$before              = $store->stored_value;

		self::assertSame( self::KEY, $store->load() );
		self::assertSame( 1, $store->repair_calls );
		self::assertSame( 'off', $store->autoload );
		self::assertSame( $before, $store->stored_value );
	}

	public function test_read_only_load_rejects_autoloaded_key_without_repairing_it(): void {
		$store               = new TestSiteKeyStore();
		$store->stored_value = base64_encode( self::KEY );
		$store->autoload     = 'on';

		try {
			$store->load( false );
			self::fail( 'A read-only load must reject an autoloaded site key.' );
		} catch ( RuntimeException ) {
			self::assertSame( 0, $store->repair_calls );
			self::assertSame( 'on', $store->autoload );
		}
	}

	public function test_unverifiable_autoload_repair_fails_without_changing_the_key(): void {
		$store               = new TestSiteKeyStore();
		$store->stored_value = base64_encode( self::KEY );
		$store->autoload     = 'on';
		$store->fail_repair  = true;
		$before              = $store->stored_value;

		$this->expectException( RuntimeException::class );

		try {
			$store->load();
		} finally {
			self::assertSame( $before, $store->stored_value );
		}
	}

	public function test_missing_autoload_metadata_fails_closed(): void {
		$store                 = new TestSiteKeyStore();
		$store->stored_value   = base64_encode( self::KEY );
		$store->autoload_known = false;

		$this->expectException( RuntimeException::class );
		$store->load();
	}

	public function test_failed_creation_without_awinner_fails_closed(): void {
		$store                = new TestSiteKeyStore();
		$store->generated_key = self::KEY;
		$store->fail_add      = true;

		$this->expectException( RuntimeException::class );
		$store->load_or_create();
	}

	public function test_exact_deletion_cannot_remove_adifferent_key(): void {
		$store               = new TestSiteKeyStore();
		$store->stored_value = base64_encode( self::WINNER );

		self::assertFalse( $store->delete_exact( self::KEY ) );
		self::assertSame( base64_encode( self::WINNER ), $store->stored_value );
		self::assertSame( 0, $store->cache_invalidations );

		self::assertTrue( $store->delete_exact( self::WINNER ) );
		self::assertNull( $store->load() );
		self::assertSame( 1, $store->cache_invalidations );
	}

	public function test_deletion_storage_failure_does_not_claim_success(): void {
		$store               = new TestSiteKeyStore();
		$store->stored_value = base64_encode( self::KEY );
		$store->fail_delete  = true;

		$this->expectException( RuntimeException::class );
		$store->delete_exact( self::KEY );
	}
}

final class TestSiteKeyStore extends SiteKeyStore {

	public mixed $stored_value;
	public string $autoload           = 'off';
	public bool $autoload_known       = true;
	public string $generated_key      = SiteKeyStoreTest::KEY;
	public ?string $race_winner       = null;
	public bool $fail_add             = false;
	public bool $fail_repair          = false;
	public bool $fail_delete          = false;
	public int $add_calls             = 0;
	public int $repair_calls          = 0;
	public int $cache_invalidations   = 0;
	public bool $stale_negative_cache = false;

	public function __construct() {
		parent::__construct();
		$this->stored_value = $this->missing_stored_value();
	}

	protected function read_stored_value(): mixed {
		if ( $this->stale_negative_cache ) {
			return $this->missing_stored_value();
		}

		return $this->stored_value;
	}

	protected function add_stored_value( #[\SensitiveParameter] string $encoded ): bool {
		++$this->add_calls;
		if ( null !== $this->race_winner ) {
			$this->stored_value         = $this->race_winner;
			$this->stale_negative_cache = true;

			return false;
		}
		if ( $this->fail_add ) {
			return false;
		}

		$this->stored_value = $encoded;
		$this->autoload     = 'off';

		return true;
	}

	protected function read_autoload_value(): ?string {
		return $this->autoload_known ? $this->autoload : null;
	}

	protected function repair_autoload_value(): void {
		++$this->repair_calls;
		if ( ! $this->fail_repair ) {
			$this->autoload = 'off';
		}
	}

	protected function delete_stored_value_exact( #[\SensitiveParameter] string $encoded ): int|false {
		if ( $this->fail_delete ) {
			return false;
		}
		if ( $encoded !== $this->stored_value ) {
			return 0;
		}

		$this->stored_value = $this->missing_stored_value();

		return 1;
	}

	protected function invalidate_option_cache(): void {
		++$this->cache_invalidations;
		$this->stale_negative_cache = false;
	}

	protected function generate_key(): string {
		return $this->generated_key;
	}
}

/**
 * Models atomic add_option() visibility using a fully written temporary inode
 * and one atomic hard-link election.
 */
final class AtomicFileSiteKeyStore extends SiteKeyStore {

	public function __construct( private string $path ) {
		parent::__construct();
	}

	protected function read_stored_value(): mixed {
		if ( ! is_file( $this->path ) ) {
			return $this->missing_stored_value();
		}

		return file_get_contents( $this->path );
	}

	protected function add_stored_value( #[\SensitiveParameter] string $encoded ): bool {
		$temporary = $this->path . '.candidate-' . bin2hex( random_bytes( 8 ) );
		try {
			if ( strlen( $encoded ) !== file_put_contents( $temporary, $encoded, LOCK_EX )
				|| ! chmod( $temporary, 0600 )
			) {
				return false;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Losing the expected atomic election emits E_WARNING.
			return @link( $temporary, $this->path );
		} finally {
			if ( is_file( $temporary ) ) {
				unlink( $temporary );
			}
		}
	}

	protected function read_autoload_value(): ?string {
		return 'off';
	}

	protected function delete_stored_value_exact( #[\SensitiveParameter] string $encoded ): int|false {
		if ( $encoded !== $this->read_stored_value() ) {
			return 0;
		}

		return unlink( $this->path ) ? 1 : false;
	}

	protected function invalidate_option_cache(): void {
	}
}
