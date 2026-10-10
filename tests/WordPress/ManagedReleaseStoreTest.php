<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

require_once __DIR__ . '/ManagedReleaseRuntimeWordPressFunctions.php';
require_once __DIR__ . '/ManagedReleaseStoreDatabase.php';
require_once dirname( __DIR__ ) . '/Portability/WpPusherCoexistenceWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\PackageSource;
use RAN\Storage\Database;
use RAN\Storage\DatabaseCompatibilityFailure;
use RAN\Storage\DatabaseLifecycleFailure;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseRepositorySourceUnavailable;
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseSubdirectoryNotSupported;
use RuntimeException;

final class ManagedReleaseStoreTest extends TestCase {

	public function test_read_only_connection_can_read_release_configuration_without_write_methods(): void {
		$database = $this->createMock( \RAN\Storage\SqlReadConnection::class );
		$database->expects( self::once() )->method( 'prepare' )->willReturn( 'prepared fixture query' );
		$database->expects( self::once() )->method( 'get_results' )->with( 'prepared fixture query' )->willReturn( array( (object) array( 'release_configuration' => null ) ) );
		$store = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );
		self::assertNull( $store->configuration( 'plugin', 'installed/example.php' ) );
	}

	public function test_read_only_connection_cannot_start_a_release_transaction(): void {
		$database = $this->createStub( \RAN\Storage\SqlReadConnection::class );
		$store    = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'The managed release database is unavailable.' );
		$store->transition( 'plugin', 'installed/example.php', PackageSource::RELEASE_ASSET, 4, PackageSource::BRANCH, null, 7 );
	}

	public function test_undeclared_release_connection_is_rejected_after_lifecycle_readiness(): void {
		$lifecycle = $this->createMock( Database::class );
		$lifecycle->expects( self::once() )->method( 'require_ready' );
		$store = new ManagedReleaseStore( new \stdClass(), $lifecycle );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'The managed release database is unavailable.' );
		$store->configuration( 'plugin', 'installed/example.php' );
	}

	/** @return iterable<string, array{bool, RuntimeException}> */
	public static function storage_failures(): iterable {
		foreach ( array(
			'transition' => false,
			'channel'    => true,
		) as $operation => $channel ) {
			yield $operation . ' compatibility' => array( $channel, new DatabaseCompatibilityFailure( 'unsupported_version' ) );
			yield $operation . ' lifecycle' => array( $channel, new DatabaseLifecycleFailure( 'schema_operation_failed' ) );
		}
	}

	#[DataProvider( 'storage_failures' )]
	public function test_unready_storage_rejects_mutation_before_transaction_commands( bool $channel, RuntimeException $failure ): void {
		$database  = new ManagedReleaseStoreDatabase( array() );
		$lifecycle = $this->createMock( Database::class );
		$lifecycle->expects( self::once() )->method( 'require_ready' )->willThrowException( $failure );
		$store = new ManagedReleaseStore( $database, $lifecycle );

		try {
			if ( $channel ) {
				$store->change_channel( 'plugin', 'installed/example.php', 4, 'prerelease', 7 );
			} else {
				$store->transition( 'plugin', 'installed/example.php', PackageSource::RELEASE_ASSET, 4, PackageSource::BRANCH, null, 7 );
			}
			self::fail( 'Unready storage must reject the mutation.' );
		} catch ( RuntimeException $caught ) {
			self::assertSame( $failure, $caught );
		}

		self::assertSame( array(), $database->queries );
		self::assertSame( 0, $database->reads );
		self::assertSame( array(), $database->updates );
	}

	public function test_invalid_revision_does_not_prepare_storage_or_start_a_transaction(): void {
		$database  = new ManagedReleaseStoreDatabase( array() );
		$lifecycle = $this->createMock( Database::class );
		$lifecycle->expects( self::never() )->method( 'require_ready' );
		$store = new ManagedReleaseStore( $database, $lifecycle );

		self::assertFalse( $store->transition( 'plugin', 'installed/example.php', PackageSource::RELEASE_ASSET, 0, PackageSource::BRANCH, null, 7 ) );
		self::assertFalse( $store->change_channel( 'plugin', 'installed/example.php', 0, 'prerelease', 7 ) );
		self::assertSame( array(), $database->queries );
		self::assertSame( 0, $database->reads );
		self::assertSame( array(), $database->updates );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
	}

	public function test_active_wp_pusher_blocks_release_configuration_writes(): void {
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( 'wppusher/wppusher.php' );

		$database = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'branch',
				'source_revision'       => 4,
				'deployment_policy'     => 'manual',
				'release_configuration' => null,
			)
		);
		$store    = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );

		try {
			$store->transition(
				'plugin',
				'installed/example.php',
				PackageSource::BRANCH,
				4,
				PackageSource::RELEASE_ASSET,
				new ManagedReleaseConfiguration( 'example', 'example.php' ),
				7
			);
			self::fail( 'Release configuration must remain unchanged during an active conflict.' );
		} catch ( RuntimeException $failure ) {
			self::assertStringContainsString( 'Deactivate WP Pusher', $failure->getMessage() );
		}
		self::assertSame( array(), $database->updates );
	}

	public function test_exact_source_revision_cas_preserves_policy_and_verifies_the_write(): void {
		$database        = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'branch',
				'source_revision'       => 4,
				'source_previous'       => null,
				'source_changed_at'     => null,
				'source_changed_by'     => null,
				'deployment_policy'     => 'manual',
				'release_configuration' => null,
			)
		);
		$lifecycle       = $this->createMock( Database::class );
		$readiness_calls = 0;
		$lifecycle->expects( self::exactly( 5 ) )->method( 'require_ready' )->willReturnCallback(
			static function () use ( $database, &$readiness_calls ): void {
				if ( 0 === $readiness_calls++ ) {
					self::assertSame( array(), $database->queries, 'Schema readiness must precede transaction commands.' );
				}
			}
		);
		$store         = new ManagedReleaseStore(
			$database,
			$lifecycle,
			static fn (): string => '2026-07-25 08:00:00'
		);
		$configuration = new ManagedReleaseConfiguration( 'canonical-example', 'example.php' );

		self::assertTrue(
			$store->transition(
				'plugin',
				'installed/example.php',
				PackageSource::BRANCH,
				4,
				PackageSource::RELEASE_ASSET,
				$configuration,
				7
			)
		);
		self::assertSame(
			array(
				'type'              => 1,
				'package'           => 'installed/example.php',
				'source'            => 'branch',
				'source_revision'   => 4,
				'deployment_policy' => 'manual',
			),
			$database->updates[0][2]
		);
		self::assertSame( 'manual', $database->row['deployment_policy'] );
		self::assertSame( 'release_asset', $database->row['source'] );
		self::assertSame( 5, $database->row['source_revision'] );
		self::assertSame( $configuration->to_json(), $database->row['release_configuration'] );
		self::assertSame(
			$configuration->to_array(),
			$store->configuration( 'plugin', 'installed/example.php' )?->to_array()
		);
	}

	public function test_stale_revision_does_not_write(): void {
		$database = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 2,
				'package'               => 'example-theme',
				'source'                => 'release_asset',
				'source_revision'       => 3,
				'deployment_policy'     => 'disabled',
				'release_configuration' => '{}',
			)
		);
		$store    = new ManagedReleaseStore(
			$database,
			$this->createStub( Database::class )
		);

		self::assertFalse(
			$store->transition(
				'theme',
				'example-theme',
				PackageSource::RELEASE_ASSET,
				2,
				PackageSource::BRANCH,
				null,
				0
			)
		);
		self::assertSame( array(), $database->updates );
		self::assertSame( 'disabled', $database->row['deployment_policy'] );
	}

	public function test_returning_to_branch_distinguishes_an_unavailable_repository_source_guard_from_stale_state(): void {
		$database                           = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'release_asset',
				'source_revision'       => 4,
				'deployment_policy'     => 'manual',
				'release_configuration' => '{}',
			)
		);
		$database->source_guard_unavailable = true;
		$store                              = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );

		try {
			$store->transition(
				'plugin',
				'installed/example.php',
				PackageSource::RELEASE_ASSET,
				4,
				PackageSource::BRANCH,
				null,
				7
			);
			self::fail( 'An unavailable repository source guard must not be reported as a stale transition.' );
		} catch ( ManagedReleaseRepositorySourceUnavailable $failure ) {
			self::assertStringContainsString( 'repository source relationship', $failure->getMessage() );
		}

		self::assertSame( array(), $database->updates );
	}

	public function test_changing_release_channel_distinguishes_an_unavailable_repository_source_guard_from_stale_state(): void {
		$database                           = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'release_asset',
				'source_revision'       => 4,
				'deployment_policy'     => 'manual',
				'release_configuration' => ( new ManagedReleaseConfiguration( 'example', 'example.php', 'stable' ) )->to_json(),
			)
		);
		$database->source_guard_unavailable = true;
		$store                              = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );

		try {
			$store->change_channel( 'plugin', 'installed/example.php', 4, 'prerelease', 7 );
			self::fail( 'An unavailable repository source guard must not be reported as a stale channel change.' );
		} catch ( ManagedReleaseRepositorySourceUnavailable $failure ) {
			self::assertStringContainsString( 'repository source relationship', $failure->getMessage() );
		}

		self::assertSame( array(), $database->updates );
	}

	public function test_release_transition_and_channel_change_reject_nested_rows_without_writing(): void {
		$database = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'branch',
				'source_revision'       => 4,
				'deployment_policy'     => 'manual',
				'subdirectory'          => 'packages/example',
				'release_configuration' => null,
			)
		);
		$store    = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );

		try {
			$store->transition(
				'plugin',
				'installed/example.php',
				PackageSource::BRANCH,
				4,
				PackageSource::RELEASE_ASSET,
				new ManagedReleaseConfiguration( 'example', 'example.php' ),
				7
			);
			self::fail( 'A release transition must reject a configured subdirectory.' );
		} catch ( ManagedReleaseSubdirectoryNotSupported $failure ) {
			self::assertStringContainsString( 'subdirectory is not supported', $failure->getMessage() );
		}
		self::assertSame( array(), $database->updates );

		$database->row['source']                = PackageSource::RELEASE_ASSET->value;
		$database->row['release_configuration'] = ( new ManagedReleaseConfiguration( 'example', 'example.php' ) )->to_json();
		try {
			$store->change_channel( 'plugin', 'installed/example.php', 4, 'prerelease', 7 );
			self::fail( 'A release channel change must reject a configured subdirectory.' );
		} catch ( ManagedReleaseSubdirectoryNotSupported $failure ) {
			self::assertStringContainsString( 'subdirectory is not supported', $failure->getMessage() );
		}
		self::assertSame( array(), $database->updates );
	}

	public function test_source_transitions_preserve_disabled_and_manual_and_reset_automatic(): void {
		foreach ( array(
			'disabled'  => 'disabled',
			'manual'    => 'manual',
			'automatic' => 'manual',
		) as $policy => $expected_policy ) {
			foreach ( array(
				array( PackageSource::BRANCH, PackageSource::RELEASE_ASSET, new ManagedReleaseConfiguration( 'example', 'example.php' ) ),
				array( PackageSource::RELEASE_ASSET, PackageSource::BRANCH, null ),
			) as $transition ) {
				list( $expected_source, $new_source, $configuration ) = $transition;
				$database = new ManagedReleaseStoreDatabase(
					array(
						'type'                  => 1,
						'package'               => 'installed/example.php',
						'source'                => $expected_source->value,
						'source_revision'       => 4,
						'source_previous'       => null,
						'source_changed_at'     => null,
						'source_changed_by'     => null,
						'deployment_policy'     => $policy,
						'release_configuration' => PackageSource::RELEASE_ASSET === $expected_source ? '{}' : null,
					)
				);
				$store    = new ManagedReleaseStore(
					$database,
					$this->createStub( Database::class )
				);

				self::assertTrue(
					$store->transition(
						'plugin',
						'installed/example.php',
						$expected_source,
						4,
						$new_source,
						$configuration,
						7
					)
				);
				self::assertSame( $expected_policy, $database->row['deployment_policy'] );
				self::assertSame( $expected_policy, $database->updates[0][1]['deployment_policy'] );
			}
		}
	}

	public function test_same_source_channel_cas_preserves_release_identity_and_resets_automatic(): void {
		$configuration   = new ManagedReleaseConfiguration(
			'canonical-example',
			'example.php',
			'prerelease'
		);
		$database        = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'release_asset',
				'source_revision'       => 4,
				'source_previous'       => 'branch',
				'source_changed_at'     => '2026-07-20 08:00:00',
				'source_changed_by'     => 3,
				'deployment_policy'     => 'automatic',
				'release_configuration' => $configuration->to_json(),
			)
		);
		$lifecycle       = $this->createMock( Database::class );
		$readiness_calls = 0;
		$lifecycle->expects( self::exactly( 4 ) )->method( 'require_ready' )->willReturnCallback(
			static function () use ( $database, &$readiness_calls ): void {
				if ( 0 === $readiness_calls++ ) {
					self::assertSame( array(), $database->queries, 'Schema readiness must precede transaction commands.' );
				}
			}
		);
		$store = new ManagedReleaseStore(
			$database,
			$lifecycle,
			static fn (): string => '2026-07-28 11:00:00'
		);

		self::assertTrue(
			$store->change_channel(
				'plugin',
				'installed/example.php',
				4,
				'stable',
				17
			)
		);
		self::assertSame(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'release_asset',
				'source_revision'       => 4,
				'deployment_policy'     => 'automatic',
				'release_configuration' => $configuration->to_json(),
			),
			$database->updates[0][2]
		);
		self::assertSame( 'release_asset', $database->row['source'] );
		self::assertSame( 'branch', $database->row['source_previous'] );
		self::assertSame( 5, $database->row['source_revision'] );
		self::assertSame( 'manual', $database->row['deployment_policy'] );
		self::assertSame( '2026-07-28 11:00:00', $database->row['source_changed_at'] );
		self::assertSame( 17, $database->row['source_changed_by'] );

		$changed = ManagedReleaseConfiguration::from_json( $database->row['release_configuration'] );
		self::assertSame( 'stable', $changed->channel() );
		self::assertSame( $configuration->package_root(), $changed->package_root() );
		self::assertSame( $configuration->metadata_file(), $changed->metadata_file() );
	}

	public function test_same_source_channel_cas_rejects_stale_and_unchanged_requests_without_writing(): void {
		$configuration = new ManagedReleaseConfiguration( 'example', 'example.php' );
		foreach (
			array(
				array( 3, 'prerelease' ),
				array( 4, 'stable' ),
			) as $request
		) {
			$database = new ManagedReleaseStoreDatabase(
				array(
					'type'                  => 1,
					'package'               => 'installed/example.php',
					'source'                => 'release_asset',
					'source_revision'       => 4,
					'deployment_policy'     => 'manual',
					'release_configuration' => $configuration->to_json(),
				)
			);
			$store    = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );

			self::assertFalse(
				$store->change_channel(
					'plugin',
					'installed/example.php',
					$request[0],
					$request[1],
					7
				)
			);
			self::assertSame( array(), $database->updates );
		}
	}
}
