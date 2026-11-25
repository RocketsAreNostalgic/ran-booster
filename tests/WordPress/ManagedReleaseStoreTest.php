<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

require_once __DIR__ . '/ManagedReleaseRuntimeWordPressFunctions.php';
require_once __DIR__ . '/ManagedReleaseStoreDatabase.php';
require_once dirname( __DIR__ ) . '/Portability/WpPusherCoexistenceWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentPolicy;
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

	public function test_configuration_folder_and_plugin_stem_boundaries(): void {
		foreach ( array( 100, 101, 191 ) as $length ) {
			$root = str_repeat( 'A', $length );
			self::assertSame( $root, ( new ManagedReleaseConfiguration( $root, str_repeat( 'b', 100 ) . '.php' ) )->package_root() );
		}
		foreach ( array( array( str_repeat( 'A', 192 ), 'main.php' ), array( 'root', str_repeat( 'b', 101 ) . '.php' ) ) as $values ) {
			try {
				new ManagedReleaseConfiguration( $values[0], $values[1] );
				self::fail( 'Invalid component accepted.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

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
		$store->transition( 'plugin', 'installed/example.php', PackageSource::RELEASE_ASSET, 4, PackageSource::BRANCH, null, 7, 'gh:fixture-repository:11111111-1111-4111-8111-111111111111' );
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
				$store->change_channel( 'plugin', 'installed/example.php', 4, 'prerelease', 7, 'gh:fixture-repository:11111111-1111-4111-8111-111111111111' );
			} else {
				$store->transition( 'plugin', 'installed/example.php', PackageSource::RELEASE_ASSET, 4, PackageSource::BRANCH, null, 7, 'gh:fixture-repository:11111111-1111-4111-8111-111111111111' );
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

		self::assertFalse( $store->transition( 'plugin', 'installed/example.php', PackageSource::RELEASE_ASSET, 0, PackageSource::BRANCH, null, 7, 'gh:fixture-repository:11111111-1111-4111-8111-111111111111' ) );
		self::assertFalse( $store->change_channel( 'plugin', 'installed/example.php', 0, 'prerelease', 7, 'gh:fixture-repository:11111111-1111-4111-8111-111111111111' ) );
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
				'package_type'          => 1,
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
				7,
				'gh:fixture-repository:11111111-1111-4111-8111-111111111111'
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
				'package_type'          => 1,
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
				7,
				'gh:fixture-repository:11111111-1111-4111-8111-111111111111'
			)
		);
		self::assertSame(
			array(
				'adoption_token'    => 'gh:fixture-repository:11111111-1111-4111-8111-111111111111',
				'package_type'      => 1,
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
				'package_type'          => 2,
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
				0,
				'gh:fixture-repository:11111111-1111-4111-8111-111111111111'
			)
		);
		self::assertSame( array(), $database->updates );
		self::assertSame( 'disabled', $database->row['deployment_policy'] );
	}

	public function test_returning_to_branch_distinguishes_an_unavailable_repository_source_guard_from_stale_state(): void {
		$database                           = new ManagedReleaseStoreDatabase(
			array(
				'package_type'          => 1,
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
				7,
				'gh:fixture-repository:11111111-1111-4111-8111-111111111111'
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
				'package_type'          => 1,
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
			$store->change_channel( 'plugin', 'installed/example.php', 4, 'prerelease', 7, 'gh:fixture-repository:11111111-1111-4111-8111-111111111111' );
			self::fail( 'An unavailable repository source guard must not be reported as a stale channel change.' );
		} catch ( ManagedReleaseRepositorySourceUnavailable $failure ) {
			self::assertStringContainsString( 'repository source relationship', $failure->getMessage() );
		}

		self::assertSame( array(), $database->updates );
	}

	public function test_release_transition_and_channel_change_reject_nested_rows_without_writing(): void {
		$database = new ManagedReleaseStoreDatabase(
			array(
				'package_type'          => 1,
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
				7,
				'gh:fixture-repository:11111111-1111-4111-8111-111111111111'
			);
			self::fail( 'A release transition must reject a configured subdirectory.' );
		} catch ( ManagedReleaseSubdirectoryNotSupported $failure ) {
			self::assertStringContainsString( 'subdirectory is not supported', $failure->getMessage() );
		}
		self::assertSame( array(), $database->updates );

		$database->row['source']                = PackageSource::RELEASE_ASSET->value;
		$database->row['release_configuration'] = ( new ManagedReleaseConfiguration( 'example', 'example.php' ) )->to_json();
		try {
			$store->change_channel( 'plugin', 'installed/example.php', 4, 'prerelease', 7, 'gh:fixture-repository:11111111-1111-4111-8111-111111111111' );
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
						'package_type'          => 1,
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
						7,
						'gh:fixture-repository:11111111-1111-4111-8111-111111111111'
					)
				);
				self::assertSame( $expected_policy, $database->row['deployment_policy'] );
				self::assertSame( $expected_policy, $database->updates[0][1]['deployment_policy'] );
			}
		}
	}

	public function test_switch_completes_native_custody_inside_its_transaction_before_the_row_lock(): void {
		$row      = array(
			'package_type'          => 1,
			'package'               => 'installed/example.php',
			'source'                => PackageSource::BRANCH->value,
			'source_revision'       => 4,
			'source_previous'       => null,
			'source_changed_at'     => null,
			'source_changed_by'     => null,
			'deployment_policy'     => 'manual',
			'release_configuration' => null,
		);
		$token    = 'gh:fixture-repository:11111111-1111-4111-8111-111111111111';
		$database = new ManagedReleaseStoreDatabase( $row );
		$store    = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );
		$seen     = array();
		self::assertTrue(
			$store->transition(
				'plugin',
				'installed/example.php',
				PackageSource::BRANCH,
				4,
				PackageSource::RELEASE_ASSET,
				new ManagedReleaseConfiguration( 'installed', 'example.php' ),
				7,
				$token,
				function ( object $connection, string $type, string $identifier ) use ( $database, &$seen ): void {
					$seen = array( $connection === $database, $type, $identifier, $database->reads, end( $database->queries ) );
				}
			)
		);
		self::assertSame( array( true, 'plugin', 'installed/example.php', 0, 'START TRANSACTION' ), $seen );
		self::assertSame( 'COMMIT', end( $database->queries ) );

		$database = new ManagedReleaseStoreDatabase( $row );
		$store    = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );
		try {
			$store->transition(
				'plugin',
				'installed/example.php',
				PackageSource::BRANCH,
				4,
				PackageSource::RELEASE_ASSET,
				new ManagedReleaseConfiguration( 'installed', 'example.php' ),
				7,
				$token,
				static function (): void {
					throw new RuntimeException( 'custody changed' );
				}
			);
			self::fail( 'A failed custody completion switched the source.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'custody changed', $failure->getMessage() );
		}
		self::assertSame( array(), $database->updates );
		self::assertSame( 'ROLLBACK', end( $database->queries ) );
		self::assertSame( PackageSource::BRANCH->value, $database->row['source'] );
	}

	public function test_switch_commit_refuses_a_second_package_from_the_repository_and_rolls_back_custody_removal(): void {
		$database                    = new ManagedReleaseStoreDatabase(
			array(
				'package_type'          => 1,
				'package'               => 'installed/example.php',
				'source'                => PackageSource::BRANCH->value,
				'source_revision'       => 4,
				'source_previous'       => null,
				'source_changed_at'     => null,
				'source_changed_by'     => null,
				'deployment_policy'     => 'manual',
				'release_configuration' => null,
			)
		);
		$database->repository_others = array(
			array(
				'type'    => 2,
				'package' => 'added-during-install',
			),
		);
		$store                       = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );
		$completed                   = false;
		self::assertFalse(
			$store->transition(
				'plugin',
				'installed/example.php',
				PackageSource::BRANCH,
				4,
				PackageSource::RELEASE_ASSET,
				new ManagedReleaseConfiguration( 'installed', 'example.php' ),
				7,
				'gh:fixture-repository:11111111-1111-4111-8111-111111111111',
				static function () use ( &$completed ): void {
					$completed = true;
				}
			)
		);
		// Custody removal ran in the transaction that the exclusivity refusal rolled back.
		self::assertTrue( $completed );
		self::assertSame( array(), $database->updates );
		self::assertSame( 'ROLLBACK', end( $database->queries ) );
		self::assertContains( 'START TRANSACTION', $database->queries );
		self::assertSame( PackageSource::BRANCH->value, $database->row['source'] );
	}

	public function test_same_source_channel_cas_preserves_release_identity_and_resets_automatic(): void {
		$configuration   = new ManagedReleaseConfiguration(
			'canonical-example',
			'example.php',
			'prerelease'
		);
		$database        = new ManagedReleaseStoreDatabase(
			array(
				'package_type'          => 1,
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
				17,
				'gh:fixture-repository:11111111-1111-4111-8111-111111111111'
			)
		);
		self::assertSame(
			array(
				'adoption_token'        => 'gh:fixture-repository:11111111-1111-4111-8111-111111111111',
				'package_type'          => 1,
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
					'package_type'          => 1,
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
					7,
					'gh:fixture-repository:11111111-1111-4111-8111-111111111111'
				)
			);
			self::assertSame( array(), $database->updates );
		}
	}
	public function test_adoption_binding_rejects_recreated_revision_and_changed_readback(): void {
		$token = 'gh:fixture-repository:11111111-1111-4111-8111-111111111111';
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( false, true ) as $change_channel ) {
				foreach ( array( false, true ) as $readback_changed ) {
					$configuration                  = new ManagedReleaseConfiguration( 'installed', 'plugin' === $type ? 'example.php' : 'style.css' );
					$database                       = new ManagedReleaseStoreDatabase(
						array(
							'package_type'          => 'plugin' === $type ? 1 : 2,
							'package'               => 'installed/example.php',
							'source'                => 'release_asset',
							'source_revision'       => 4,
							'deployment_policy'     => 'manual',
							'release_configuration' => $configuration->to_json(),
							'adoption_token'        => $readback_changed ? $token : 'gh:fixture-repository:22222222-2222-4222-8222-222222222222',
						)
					);
					$database->replace_after_update = $readback_changed;
					$store                          = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );
					self::assertFalse(
						$change_channel
						? $store->change_channel( $type, 'installed/example.php', 4, 'prerelease', 1, $token )
						: $store->transition( $type, 'installed/example.php', PackageSource::RELEASE_ASSET, 4, PackageSource::BRANCH, null, 1, $token )
					);
					self::assertCount( $readback_changed ? 1 : 0, $database->updates );
					self::assertContains( 'ROLLBACK', $database->queries );
					self::assertNotContains( 'COMMIT', $database->queries );
				}
			}
		}
	}

	public function test_transition_in_transaction_uses_pinned_executor_with_binary_predicates_and_verifies_readback(): void {
		$token         = 'gh:fixture-repository:11111111-1111-4111-8111-111111111111';
		$configuration = new ManagedReleaseConfiguration( 'installed', 'example.php', 'prerelease' );
		$state         = (object) array(
			'type'                   => 1,
			'package_type'           => 1,
			'package'                => 'installed/example.php',
			'source'                 => 'branch',
			'source_revision'        => 3,
			'source_previous'        => null,
			'source_changed_at'      => null,
			'source_changed_by'      => null,
			'deployment_policy'      => 'automatic',
			'release_configuration'  => null,
			'adoption_token'         => $token,
			'provider'               => 'gh',
			'provider_repository_id' => 'fixture-repository',
			'subdirectory'           => null,
		);
		$store         = new ManagedReleaseStore(
			new ManagedReleaseStoreDatabase( (array) $state ),
			$this->createStub( Database::class ),
			static fn (): string => '2026-11-23 18:00:00'
		);
		/** @var list<array{query: string, arguments: list<int|string>, mode: string}> $executed */
		$executed = array();
		$execute  = static function ( string $query, array $arguments, string $mode ) use ( $state, &$executed ): int|array {
			$executed[] = array(
				'query'     => $query,
				'arguments' => $arguments,
				'mode'      => $mode,
			);
			if ( 'results' === $mode ) {
				return array( clone $state );
			}
			if ( 'affected' === $mode ) {
				$state->source                = (string) $arguments[1];
				$state->source_revision       = (int) $arguments[2];
				$state->source_previous       = (string) $arguments[3];
				$state->source_changed_at     = (string) $arguments[4];
				$state->source_changed_by     = (int) $arguments[5];
				$state->deployment_policy     = (string) $arguments[6];
				$state->release_configuration = (string) $arguments[7];
				return 1;
			}
			throw new RuntimeException( 'Unexpected executor mode.' );
		};

		self::assertTrue(
			$store->transition_in_transaction(
				'plugin',
				'installed/example.php',
				PackageSource::BRANCH,
				3,
				PackageSource::RELEASE_ASSET,
				$configuration,
				7,
				$token,
				$execute
			)
		);
		self::assertSame( 'release_asset', $state->source );
		self::assertSame( 4, $state->source_revision );
		self::assertSame( 'manual', $state->deployment_policy );
		self::assertSame( $configuration->to_json(), $state->release_configuration );
		self::assertCount( 4, $executed );
		self::assertStringContainsString( 'CAST(package AS BINARY) = CAST(%s AS BINARY)', $executed[0]['query'] );
		self::assertStringContainsString( 'CAST(provider_repository_id AS BINARY) = CAST(%s AS BINARY)', $executed[1]['query'] );
		self::assertSame( 'affected', $executed[2]['mode'] );
		self::assertStringContainsString( 'CAST(adoption_token AS BINARY) = CAST(%s AS BINARY)', $executed[2]['query'] );
		self::assertStringContainsString( 'CAST(package AS BINARY) = CAST(%s AS BINARY)', $executed[2]['query'] );

		$state->deployment_policy = 'manual';
		self::assertFalse(
			$store->transition_in_transaction(
				'plugin',
				'installed/example.php',
				PackageSource::RELEASE_ASSET,
				3,
				PackageSource::BRANCH,
				null,
				0,
				$token,
				$execute
			)
		);
		$state->source_revision = 4;
		/** @var list<array{query: string, arguments: array<array-key, mixed>, mode: string}> $release_executed */
		$release_executed = array();
		$execute_release  = static function ( string $query, array $arguments, string $mode ) use ( $state, &$release_executed ): int|array {
			$release_executed[] = array(
				'query'     => $query,
				'arguments' => $arguments,
				'mode'      => $mode,
			);
			if ( 'results' === $mode ) {
				return array( clone $state );
			}
			$state->source                = (string) $arguments[1];
			$state->source_revision       = (int) $arguments[2];
			$state->source_previous       = (string) $arguments[3];
			$state->source_changed_at     = (string) $arguments[4];
			$state->source_changed_by     = null;
			$state->deployment_policy     = (string) $arguments[5];
			$state->release_configuration = null;
			return 1;
		};
		self::assertTrue(
			$store->transition_in_transaction(
				'plugin',
				'installed/example.php',
				PackageSource::RELEASE_ASSET,
				4,
				PackageSource::BRANCH,
				null,
				0,
				$token,
				$execute_release
			)
		);
		self::assertSame( 'branch', $state->source );
		self::assertSame( 5, $state->source_revision );
		self::assertNull( $state->source_changed_by );
		self::assertNull( $state->release_configuration );
		self::assertCount( 4, $release_executed );
		self::assertStringContainsString( 'source_changed_by = NULL', $release_executed[2]['query'] );
		self::assertStringContainsString( 'release_configuration = NULL', $release_executed[2]['query'] );

		$executed                                        = array();
		$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array( \RAN\Portability\WpPusherCoexistencePolicy::WP_PUSHER_PLUGIN );
		try {
			$guard_rejected = false;
			try {
				$store->require_transition_ready();
			} catch ( RuntimeException $failure ) {
				$guard_rejected = str_contains( $failure->getMessage(), 'WP Pusher is active' );
			}
			self::assertTrue( $guard_rejected );

			$GLOBALS['ran_booster_wp_pusher_active_plugins'] = 'malformed';
			self::assertTrue(
				$store->transition_in_transaction(
					'plugin',
					'installed/example.php',
					PackageSource::BRANCH,
					5,
					PackageSource::RELEASE_ASSET,
					$configuration,
					7,
					$token,
					$execute
				)
			);
			self::assertSame( 'release_asset', $state->source );
			self::assertSame( 6, $state->source_revision );
			self::assertCount( 4, $executed );
		} finally {
			$GLOBALS['ran_booster_wp_pusher_active_plugins'] = array();
		}

		$state->source            = PackageSource::BRANCH->value;
		$state->deployment_policy = DeploymentPolicy::DISABLED->value;
		$executed                 = array();
		self::assertFalse(
			$store->transition_in_transaction(
				'plugin',
				'installed/example.php',
				PackageSource::BRANCH,
				6,
				PackageSource::RELEASE_ASSET,
				$configuration,
				7,
				$token,
				$execute
			)
		);
		self::assertSame( 'branch', $state->source );
		self::assertCount( 1, $executed, 'The pinned package read observes Disabled before the source update.' );
	}
}
