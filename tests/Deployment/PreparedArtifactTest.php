<?php

declare(strict_types=1);

namespace Tests\Deployment;

require_once dirname( __DIR__, 2 ) . '/../ran-wp-release-updater/src/Archive/TemporaryArtifact.php';

use PHPUnit\Framework\TestCase;
use RAN\Deployment\PreparedArtifact;
use RuntimeException;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests deliberately own private temporary files.

final class PreparedArtifactTest extends TestCase {

	/** @var list<string> */
	private array $paths = array();

	protected function tearDown(): void {
		foreach ( $this->paths as $path ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}

	public function testTransfersCleanupOwnership(): void {
		$artifact = $this->artifact();
		$path     = $artifact->getPath();
		$artifact->assertUnchanged();
		$claim = $artifact->claimForNativeUpdate( 'plugin', 'example/example.php' );

		$artifact->cleanup();
		self::assertFileExists( $path );
		self::assertSame(
			'1.2.3',
			$claim->acceptCoreUpdate( 'plugin', 'example/example.php', 'update', $path )
		);
		self::assertTrue( $claim->discard() );
		self::assertFileDoesNotExist( $path );
	}

	public function testClaimRequiresCoreVerificationAndCanBeMintedOnlyOnce(): void {
		$artifact = $this->artifact();

		try {
			$artifact->claimForNativeUpdate( 'plugin', 'example/example.php' );
			self::fail( 'An unverified artifact must not transfer cleanup ownership.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The prepared deployment artifact is unavailable.', $failure->getMessage() );
		}

		$artifact->assertUnchanged();
		$claim = $artifact->claimForNativeUpdate( 'plugin', 'example/example.php' );
		try {
			$artifact->claimForNativeUpdate( 'plugin', 'example/example.php' );
			self::fail( 'A prepared artifact must transfer ownership only once.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The prepared deployment artifact is unavailable.', $failure->getMessage() );
		}

		self::assertTrue( $claim->discard() );
	}

	public function testChangedArtifactCannotTransferCleanupOwnership(): void {
		$artifact = $this->artifact();
		$artifact->assertUnchanged();
		file_put_contents( $artifact->getPath(), 'changed Core artifact' );

		$this->expectException( RuntimeException::class );
		$artifact->claimForNativeUpdate( 'plugin', 'example/example.php' );
	}

	private function artifact(): PreparedArtifact {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-claim-' );
		self::assertIsString( $path );
		$this->paths[] = $path;
		file_put_contents( $path, 'immutable Core artifact' );
		chmod( $path, 0600 );
		$identity = PreparedArtifact::regularFileIdentity( $path );
		self::assertIsArray( $identity );

		return new PreparedArtifact(
			$path,
			str_repeat( 'a', 40 ),
			'1.2.3',
			hash_file( 'sha256', $path ),
			$identity['device'],
			$identity['inode'],
			$identity['size'],
			$identity['permissions'],
			$identity['links']
		);
	}
}
