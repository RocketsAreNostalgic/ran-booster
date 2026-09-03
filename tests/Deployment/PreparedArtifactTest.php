<?php

declare(strict_types=1);

namespace Tests\Deployment;

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

	public function testCleanupDeletesTheExactUnchangedArtifact(): void {
		$artifact = $this->artifact();
		$path     = $artifact->getPath();

		$artifact->cleanup();
		self::assertFileDoesNotExist( $path );
	}

	public function testCleanupRejectsChangedArtifactWithoutDeletingIt(): void {
		$artifact = $this->artifact();
		$path     = $artifact->getPath();
		file_put_contents( $path, 'changed Core artifact' );
		try {
			$artifact->cleanup();
			self::fail( 'Changed bytes must not be deleted as an owned artifact.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'The prepared deployment artifact changed before use.', $failure->getMessage() );
		}

		self::assertFileExists( $path );
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
