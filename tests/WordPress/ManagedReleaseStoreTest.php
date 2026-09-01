<?php

declare(strict_types=1);

namespace Tests\WordPress;

require_once __DIR__ . '/ManagedReleaseRuntimeWordPressFunctions.php';
require_once __DIR__ . '/ManagedReleaseStoreDatabase.php';
require_once dirname( __DIR__ ) . '/Portability/WpPusherCoexistenceWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\PackageSource;
use RAN\Storage\Database;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseRepositorySourceUnavailable;
use RAN\WordPress\ManagedReleaseStore;
use RAN\WordPress\ManagedReleaseSubdirectoryNotSupported;
use RuntimeException;

final class ManagedReleaseStoreTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_wp_pusher_active_plugins'] );
	}

	public function testActiveWpPusherBlocksReleaseConfigurationWrites(): void {
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

	public function testExactSourceRevisionCasPreservesPolicyAndVerifiesTheWrite(): void {
		$database  = new ManagedReleaseStoreDatabase(
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
		$lifecycle = $this->createMock( Database::class );
		$lifecycle->expects( self::exactly( 4 ) )->method( 'requireReady' );
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
		self::assertSame( $configuration->toJson(), $database->row['release_configuration'] );
		self::assertSame(
			$configuration->toArray(),
			$store->configuration( 'plugin', 'installed/example.php' )?->toArray()
		);
	}

	public function testStaleRevisionDoesNotWrite(): void {
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

	public function testReturningToBranchDistinguishesAnUnavailableRepositorySourceGuardFromStaleState(): void {
		$database                         = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'release_asset',
				'source_revision'       => 4,
				'deployment_policy'     => 'manual',
				'release_configuration' => '{}',
			)
		);
		$database->sourceGuardUnavailable = true;
		$store                            = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );

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

	public function testChangingReleaseChannelDistinguishesAnUnavailableRepositorySourceGuardFromStaleState(): void {
		$database                         = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'release_asset',
				'source_revision'       => 4,
				'deployment_policy'     => 'manual',
				'release_configuration' => ( new ManagedReleaseConfiguration( 'example', 'example.php', 'stable' ) )->toJson(),
			)
		);
		$database->sourceGuardUnavailable = true;
		$store                            = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );

		try {
			$store->changeChannel( 'plugin', 'installed/example.php', 4, 'prerelease', 7 );
			self::fail( 'An unavailable repository source guard must not be reported as a stale channel change.' );
		} catch ( ManagedReleaseRepositorySourceUnavailable $failure ) {
			self::assertStringContainsString( 'repository source relationship', $failure->getMessage() );
		}

		self::assertSame( array(), $database->updates );
	}

	public function testReleaseTransitionAndChannelChangeRejectNestedRowsWithoutWriting(): void {
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
		$database->row['release_configuration'] = ( new ManagedReleaseConfiguration( 'example', 'example.php' ) )->toJson();
		try {
			$store->changeChannel( 'plugin', 'installed/example.php', 4, 'prerelease', 7 );
			self::fail( 'A release channel change must reject a configured subdirectory.' );
		} catch ( ManagedReleaseSubdirectoryNotSupported $failure ) {
			self::assertStringContainsString( 'subdirectory is not supported', $failure->getMessage() );
		}
		self::assertSame( array(), $database->updates );
	}

	public function testSourceTransitionsPreserveDisabledAndManualAndResetAutomatic(): void {
		foreach ( array(
			'disabled'  => 'disabled',
			'manual'    => 'manual',
			'automatic' => 'manual',
		) as $policy => $expectedPolicy ) {
			foreach ( array(
				array( PackageSource::BRANCH, PackageSource::RELEASE_ASSET, new ManagedReleaseConfiguration( 'example', 'example.php' ) ),
				array( PackageSource::RELEASE_ASSET, PackageSource::BRANCH, null ),
			) as $transition ) {
				list( $expectedSource, $newSource, $configuration ) = $transition;
				$database = new ManagedReleaseStoreDatabase(
					array(
						'type'                  => 1,
						'package'               => 'installed/example.php',
						'source'                => $expectedSource->value,
						'source_revision'       => 4,
						'source_previous'       => null,
						'source_changed_at'     => null,
						'source_changed_by'     => null,
						'deployment_policy'     => $policy,
						'release_configuration' => PackageSource::RELEASE_ASSET === $expectedSource ? '{}' : null,
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
						$expectedSource,
						4,
						$newSource,
						$configuration,
						7
					)
				);
				self::assertSame( $expectedPolicy, $database->row['deployment_policy'] );
				self::assertSame( $expectedPolicy, $database->updates[0][1]['deployment_policy'] );
			}
		}
	}

	public function testSameSourceChannelCasPreservesReleaseIdentityAndResetsAutomatic(): void {
		$configuration = new ManagedReleaseConfiguration(
			'canonical-example',
			'example.php',
			'prerelease'
		);
		$database      = new ManagedReleaseStoreDatabase(
			array(
				'type'                  => 1,
				'package'               => 'installed/example.php',
				'source'                => 'release_asset',
				'source_revision'       => 4,
				'source_previous'       => 'branch',
				'source_changed_at'     => '2026-07-20 08:00:00',
				'source_changed_by'     => 3,
				'deployment_policy'     => 'automatic',
				'release_configuration' => $configuration->toJson(),
			)
		);
		$lifecycle     = $this->createMock( Database::class );
		$lifecycle->expects( self::exactly( 3 ) )->method( 'requireReady' );
		$store = new ManagedReleaseStore(
			$database,
			$lifecycle,
			static fn (): string => '2026-07-28 11:00:00'
		);

		self::assertTrue(
			$store->changeChannel(
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
				'release_configuration' => $configuration->toJson(),
			),
			$database->updates[0][2]
		);
		self::assertSame( 'release_asset', $database->row['source'] );
		self::assertSame( 'branch', $database->row['source_previous'] );
		self::assertSame( 5, $database->row['source_revision'] );
		self::assertSame( 'manual', $database->row['deployment_policy'] );
		self::assertSame( '2026-07-28 11:00:00', $database->row['source_changed_at'] );
		self::assertSame( 17, $database->row['source_changed_by'] );

		$changed = ManagedReleaseConfiguration::fromJson( $database->row['release_configuration'] );
		self::assertSame( 'stable', $changed->channel() );
		self::assertSame( $configuration->packageRoot(), $changed->packageRoot() );
		self::assertSame( $configuration->metadataFile(), $changed->metadataFile() );
	}

	public function testSameSourceChannelCasRejectsStaleAndUnchangedRequestsWithoutWriting(): void {
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
					'release_configuration' => $configuration->toJson(),
				)
			);
			$store    = new ManagedReleaseStore( $database, $this->createStub( Database::class ) );

			self::assertFalse(
				$store->changeChannel(
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
