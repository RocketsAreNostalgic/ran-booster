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

	public function testAbsentOptionDoesNotCreateAKey(): void {
		$store = new TestSiteKeyStore();

		self::assertNull( $store->load() );
		self::assertSame( 0, $store->addCalls );
		self::assertSame( 0, $store->repairCalls );
	}

	public function testFirstCreationStoresCanonicalNonAutoloadedBase64AndReadsItBack(): void {
		$store               = new TestSiteKeyStore();
		$store->generatedKey = self::KEY;

		$result = $store->loadOrCreate();

		self::assertSame( self::KEY, $result['key'] );
		self::assertTrue( $result['created'] );
		self::assertSame( base64_encode( self::KEY ), $store->storedValue );
		self::assertSame( 44, strlen( (string) $store->storedValue ) );
		self::assertSame( 'off', $store->autoload );
		self::assertSame( 1, $store->addCalls );
	}

	public function testConcurrentCreationLoserUsesTheWinningValidKey(): void {
		$store               = new TestSiteKeyStore();
		$store->generatedKey = self::KEY;
		$store->raceWinner   = base64_encode( self::WINNER );

		$result = $store->loadOrCreate();

		self::assertSame( self::WINNER, $result['key'] );
		self::assertFalse( $result['created'] );
		self::assertSame( base64_encode( self::WINNER ), $store->storedValue );
		self::assertSame( 1, $store->cacheInvalidations );
		self::assertFalse( $store->staleNegativeCache );
	}

	public function testConcurrentFirstCreatorsElectOneRandomKeyAcrossProcesses(): void {
		if ( ! function_exists( 'pcntl_fork' )
			|| ! function_exists( 'pcntl_waitpid' )
			|| ! function_exists( 'pcntl_wifexited' )
			|| ! function_exists( 'pcntl_wexitstatus' ) ) {
			self::markTestSkipped( 'The PCNTL extension is required for the first-key race proof.' );
		}

		$directory = sys_get_temp_dir() . '/ran-booster-key-race-' . bin2hex( random_bytes( 8 ) );
		$keyPath   = $directory . '/option-value';
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
						$result  = ( new AtomicFileSiteKeyStore( $keyPath ) )->loadOrCreate();
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
			self::assertSame( $results[0]['key'], file_get_contents( $keyPath ) );
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

	public function testExistingValidKeyIsNeverReplaced(): void {
		$store              = new TestSiteKeyStore();
		$store->storedValue = base64_encode( self::WINNER );

		$result = $store->loadOrCreate();

		self::assertSame( self::WINNER, $result['key'] );
		self::assertFalse( $result['created'] );
		self::assertSame( 0, $store->addCalls );
	}

	#[DataProvider( 'malformedStoredKeyProvider' )]
	public function testMalformedStoredKeysFailClosedWithoutReplacement( mixed $stored ): void {
		$store              = new TestSiteKeyStore();
		$store->storedValue = $stored;

		try {
			$store->loadOrCreate();
			self::fail( 'A malformed stored key must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( is_string( $stored ) ? $stored : 'sentinel', $exception->getMessage() );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The trace is inspected only to prove key redaction.
			$traceArguments = var_export( $exception->getTrace()[0]['args'] ?? array(), true );
			self::assertStringNotContainsString( is_string( $stored ) ? $stored : 'sentinel', $traceArguments );
		}

		self::assertSame( 0, $store->addCalls );
	}

	/** @return array<string, array{mixed}> */
	public static function malformedStoredKeyProvider(): array {
		return array(
			'non-string'           => array( array( 'sentinel' ) ),
			'boolean false'        => array( false ),
			'invalid base64'       => array( 'sentinel-not-base64!' ),
			'unpadded base64'      => array( rtrim( base64_encode( self::KEY ), '=' ) ),
			'wrong decoded length' => array( base64_encode( 'too-short' ) ),
		);
	}

	public function testValidAutoloadedKeyIsRepairedWithoutChangingItsBytes(): void {
		$store              = new TestSiteKeyStore();
		$store->storedValue = base64_encode( self::KEY );
		$store->autoload    = 'on';
		$before             = $store->storedValue;

		self::assertSame( self::KEY, $store->load() );
		self::assertSame( 1, $store->repairCalls );
		self::assertSame( 'off', $store->autoload );
		self::assertSame( $before, $store->storedValue );
	}

	public function testReadOnlyLoadRejectsAutoloadedKeyWithoutRepairingIt(): void {
		$store              = new TestSiteKeyStore();
		$store->storedValue = base64_encode( self::KEY );
		$store->autoload    = 'on';

		try {
			$store->load( false );
			self::fail( 'A read-only load must reject an autoloaded site key.' );
		} catch ( RuntimeException ) {
			self::assertSame( 0, $store->repairCalls );
			self::assertSame( 'on', $store->autoload );
		}
	}

	public function testUnverifiableAutoloadRepairFailsWithoutChangingTheKey(): void {
		$store              = new TestSiteKeyStore();
		$store->storedValue = base64_encode( self::KEY );
		$store->autoload    = 'on';
		$store->failRepair  = true;
		$before             = $store->storedValue;

		$this->expectException( RuntimeException::class );

		try {
			$store->load();
		} finally {
			self::assertSame( $before, $store->storedValue );
		}
	}

	public function testMissingAutoloadMetadataFailsClosed(): void {
		$store                = new TestSiteKeyStore();
		$store->storedValue   = base64_encode( self::KEY );
		$store->autoloadKnown = false;

		$this->expectException( RuntimeException::class );
		$store->load();
	}

	public function testFailedCreationWithoutAWinnerFailsClosed(): void {
		$store               = new TestSiteKeyStore();
		$store->generatedKey = self::KEY;
		$store->failAdd      = true;

		$this->expectException( RuntimeException::class );
		$store->loadOrCreate();
	}

	public function testExactDeletionCannotRemoveADifferentKey(): void {
		$store              = new TestSiteKeyStore();
		$store->storedValue = base64_encode( self::WINNER );

		self::assertFalse( $store->deleteExact( self::KEY ) );
		self::assertSame( base64_encode( self::WINNER ), $store->storedValue );
		self::assertSame( 0, $store->cacheInvalidations );

		self::assertTrue( $store->deleteExact( self::WINNER ) );
		self::assertNull( $store->load() );
		self::assertSame( 1, $store->cacheInvalidations );
	}

	public function testDeletionStorageFailureDoesNotClaimSuccess(): void {
		$store              = new TestSiteKeyStore();
		$store->storedValue = base64_encode( self::KEY );
		$store->failDelete  = true;

		$this->expectException( RuntimeException::class );
		$store->deleteExact( self::KEY );
	}
}

final class TestSiteKeyStore extends SiteKeyStore {

	public mixed $storedValue;
	public string $autoload         = 'off';
	public bool $autoloadKnown      = true;
	public string $generatedKey     = SiteKeyStoreTest::KEY;
	public ?string $raceWinner      = null;
	public bool $failAdd            = false;
	public bool $failRepair         = false;
	public bool $failDelete         = false;
	public int $addCalls            = 0;
	public int $repairCalls         = 0;
	public int $cacheInvalidations  = 0;
	public bool $staleNegativeCache = false;

	public function __construct() {
		parent::__construct();
		$this->storedValue = $this->missingStoredValue();
	}

	protected function readStoredValue(): mixed {
		if ( $this->staleNegativeCache ) {
			return $this->missingStoredValue();
		}

		return $this->storedValue;
	}

	protected function addStoredValue( #[\SensitiveParameter] string $encoded ): bool {
		++$this->addCalls;
		if ( null !== $this->raceWinner ) {
			$this->storedValue        = $this->raceWinner;
			$this->staleNegativeCache = true;

			return false;
		}
		if ( $this->failAdd ) {
			return false;
		}

		$this->storedValue = $encoded;
		$this->autoload    = 'off';

		return true;
	}

	protected function readAutoloadValue(): ?string {
		return $this->autoloadKnown ? $this->autoload : null;
	}

	protected function repairAutoloadValue(): void {
		++$this->repairCalls;
		if ( ! $this->failRepair ) {
			$this->autoload = 'off';
		}
	}

	protected function deleteStoredValueExact( #[\SensitiveParameter] string $encoded ): int|false {
		if ( $this->failDelete ) {
			return false;
		}
		if ( $encoded !== $this->storedValue ) {
			return 0;
		}

		$this->storedValue = $this->missingStoredValue();

		return 1;
	}

	protected function invalidateOptionCache(): void {
		++$this->cacheInvalidations;
		$this->staleNegativeCache = false;
	}

	protected function generateKey(): string {
		return $this->generatedKey;
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

	protected function readStoredValue(): mixed {
		if ( ! is_file( $this->path ) ) {
			return $this->missingStoredValue();
		}

		return file_get_contents( $this->path );
	}

	protected function addStoredValue( #[\SensitiveParameter] string $encoded ): bool {
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

	protected function readAutoloadValue(): ?string {
		return 'off';
	}

	protected function deleteStoredValueExact( #[\SensitiveParameter] string $encoded ): int|false {
		if ( $encoded !== $this->readStoredValue() ) {
			return 0;
		}

		return unlink( $this->path ) ? 1 : false;
	}

	protected function invalidateOptionCache(): void {
	}
}
