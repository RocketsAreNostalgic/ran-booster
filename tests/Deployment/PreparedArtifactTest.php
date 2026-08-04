<?php

declare(strict_types=1);

namespace Tests\Deployment;

require_once __DIR__ . '/../Support/CoreUpdateClaimFixture.php';

use PHPUnit\Framework\TestCase;
use RAN\Deployment\PreparedArtifact;
use RuntimeException;
use Tests\Support\CoreUpdateClaimFixture;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests deliberately own private temporary files.

final class PreparedArtifactTest extends TestCase {

	/** @var list<string> */
	private array $paths = array();

	protected function setUp(): void {
		CoreUpdateClaimFixture::reset();
	}

	protected function tearDown(): void {
		foreach ( $this->paths as $path ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}

	public function testTransfersCleanupOwnershipWithoutRepeatingTheDigestProof(): void {
		$artifact = $this->artifact();
		$artifact->assertUnchanged();
		$claim = $artifact->claimForNativeUpdate( 'plugin', 'example/example.php' );

		self::assertSame( 0, CoreUpdateClaimFixture::$digestChecks );
		$artifact->cleanup();
		self::assertFileExists( $claim->path() );
		self::assertSame(
			'1.2.3',
			$claim->acceptCoreUpdate( 'plugin', 'example/example.php', 'update', $claim->path() )
		);
		self::assertSame( 1, CoreUpdateClaimFixture::$digestChecks );
		self::assertTrue( $claim->discard() );
		self::assertFileDoesNotExist( $claim->path() );
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
