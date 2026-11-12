<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PackageStorageOperation;

/**
 * Package storage is read while the plugin file loads, before the textdomain exists. A failure built
 * then must not translate (WordPress logs a doing-it-wrong notice for any early translation).
 * Each test runs in its own process because it defines the global WordPress functions.
 */
final class PackageStorageFailureEarlyTranslationTest extends TestCase {

	/** @return array<string, array{string, string}> */
	public static function factories(): array {
		return array(
			'query failed'               => array( 'query_failed', 'ran_booster_storage_query_failed' ),
			'write failed'               => array( 'write_failed', 'ran_booster_storage_write_failed' ),
			'transaction unavailable'    => array( 'transaction_unavailable', 'ran_booster_storage_transaction_unavailable' ),
			'unsupported database'       => array( 'unsupported_database', 'ran_booster_storage_database_unsupported' ),
			'duplicate package rows'     => array( 'duplicate_package_rows', 'ran_booster_storage_duplicate_package' ),
			'invalid provider identity'  => array( 'invalid_provider_identity', 'ran_booster_storage_invalid_provider_identity' ),
			'repository source conflict' => array( 'repository_source_conflict', 'ran_booster_repository_source_conflict' ),
		);
	}

	#[DataProvider( 'factories' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_failure_built_before_init_does_not_translate( string $factory, string $diagnostic_id ): void {
		$this->define_wordpress_functions( false );

		$failure = PackageStorageFailure::$factory();

		self::assertSame( $diagnostic_id, $failure->get_diagnostic_id() );
		self::assertSame( $diagnostic_id, $failure->getMessage(), 'Before init the diagnostic ID stands in for the message.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_unverified_write_failure_and_a_shared_repository_owner_do_not_translate_before_init(): void {
		$this->define_wordpress_functions( false );

		self::assertSame(
			'ran_booster_storage_verification_failed',
			PackageStorageFailure::after_write_could_not_be_verified( PackageStorageOperation::UPDATE )->getMessage()
		);
		self::assertSame( 'ran_booster_repository_source_conflict', PackageStorageFailure::repository_source_conflict( 'owner/plugin.php' )->getMessage() );
	}

	#[DataProvider( 'factories' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_failure_built_after_init_is_translated( string $factory, string $diagnostic_id ): void {
		$this->define_wordpress_functions( true );

		$failure = PackageStorageFailure::$factory();

		self::assertSame( $diagnostic_id, $failure->get_diagnostic_id() );
		self::assertStringStartsWith( '[translated] ', $failure->getMessage() );
		self::assertNotSame( $diagnostic_id, $failure->getMessage() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_shared_repository_owner_is_named_in_the_translated_message_after_init(): void {
		$this->define_wordpress_functions( true );

		self::assertStringContainsString( 'owner/plugin.php', PackageStorageFailure::repository_source_conflict( 'owner/plugin.php' )->getMessage() );
		self::assertStringContainsString(
			'The saved state may have changed',
			PackageStorageFailure::after_write_could_not_be_verified( PackageStorageOperation::UPDATE )->getMessage()
		);
	}

	private function define_wordpress_functions( bool $init_has_run ): void {
		$GLOBALS['ran_booster_test_init_has_run'] = $init_has_run;
		require_once __DIR__ . '/PackageStorageFailureWordPressFunctions.php';
	}
}
