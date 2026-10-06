<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use RAN\Deployment\PreparedArtifact;
use RuntimeException;


final class PreparedArtifactTest extends TestCase {

	/** @var list<string> */
	private array $paths = array();

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		foreach ( $this->paths as $path ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $path );
			}
		}
	}

	public function test_cleanup_deletes_the_exact_unchanged_artifact(): void {
		$artifact = $this->artifact();
		$path     = $artifact->get_path();

		$artifact->cleanup();
		self::assertFileDoesNotExist( $path );
	}

	public function test_cleanup_rejects_changed_artifact_without_deleting_it(): void {
		$artifact = $this->artifact();
		$path     = $artifact->get_path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		file_put_contents( $path, 'immutable Core artifact' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $path, 0600 );
		$identity = PreparedArtifact::regular_file_identity( $path );
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
