<?php

declare(strict_types=1);

namespace Tests\WordPress;

require_once __DIR__ . '/../Support/CoreUpdateClaimFixture.php';

use Closure;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\PreparedArtifact;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\CorePackageExecutionResult;
use ReflectionMethod;
use Tests\Support\CoreUpdateClaimFixture;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Tests deliberately own private temporary files.

final class CorePackageExecutorTest extends TestCase {

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

	public function testExactScopeAndPathReturnOneTypedClaim(): void {
		$artifact = $this->artifact();
		$callback = $this->handoffCallback( $artifact );
		$path     = $artifact->getPath();

		$claim = $callback(
			null,
			$path,
			$path,
			$this->extra(),
			'plugin',
			'example/example.php'
		);

		self::assertInstanceOf( CoreUpdateClaimFixture::class, $claim );
		self::assertSame(
			'1.2.3',
			$claim->acceptCoreUpdate( 'plugin', 'example/example.php', 'update', $path )
		);
		$artifact->cleanup();
		self::assertFileExists( $path );
		self::assertTrue( $claim->discard() );
	}

	public function testRejectsEveryScopeOrPathMismatchWithoutTransferringCleanup(): void {
		$artifact = $this->artifact();
		$callback = $this->handoffCallback( $artifact );
		$path     = $artifact->getPath();
		$extra    = $this->extra();

		$cases = array(
			'non-string reply'       => array( array(), $path, $extra, 'plugin', 'example/example.php' ),
			'wrong reply path'       => array( '/tmp/other.zip', $path, $extra, 'plugin', 'example/example.php' ),
			'non-string package'     => array( $path, array(), $extra, 'plugin', 'example/example.php' ),
			'wrong package path'     => array( $path, '/tmp/other.zip', $extra, 'plugin', 'example/example.php' ),
			'non-array context'      => array( $path, $path, 'invalid', 'plugin', 'example/example.php' ),
			'wrong operation'        => array( $path, $path, array_replace( $extra, array( 'action' => 'install' ) ), 'plugin', 'example/example.php' ),
			'wrong context target'   => array( $path, $path, array_replace( $extra, array( 'plugin' => 'other/other.php' ) ), 'plugin', 'example/example.php' ),
			'wrong updater type'     => array( $path, $path, $extra, 'theme', 'example/example.php' ),
			'wrong updater identity' => array( $path, $path, $extra, 'plugin', 'other/other.php' ),
		);
		foreach ( $cases as $case => $arguments ) {
			self::assertNull( $callback( null, ...$arguments ), $case );
		}

		$artifact->cleanup();
		self::assertFileDoesNotExist( $path );
	}

	public function testOldBooleanContractRemainsFailClosed(): void {
		$artifact = $this->artifact();
		$callback = $this->handoffCallback( $artifact );
		$path     = $artifact->getPath();

		self::assertFalse(
			$callback( false, $path, $path, $this->extra(), 'plugin', 'example/example.php' )
		);
		$artifact->cleanup();
		self::assertFileDoesNotExist( $path );
	}

	public function testEarlierTypedClaimSurvivesASecondCoreCallback(): void {
		$firstArtifact  = $this->artifact();
		$secondArtifact = $this->artifact( 'second immutable Core artifact' );
		$firstCallback  = $this->handoffCallback( $firstArtifact );
		$secondCallback = $this->handoffCallback( $secondArtifact );
		$firstPath      = $firstArtifact->getPath();

		$claim = $firstCallback(
			null,
			$firstPath,
			$firstPath,
			$this->extra(),
			'plugin',
			'example/example.php'
		);
		self::assertSame(
			$claim,
			$secondCallback(
				$claim,
				$secondArtifact->getPath(),
				$secondArtifact->getPath(),
				$this->extra(),
				'plugin',
				'example/example.php'
			)
		);

		$secondArtifact->cleanup();
		self::assertFileDoesNotExist( $secondArtifact->getPath() );
		self::assertTrue( $claim->discard() );
	}

	public function testAutomaticUpdaterInstallationResultWithOneExactCompletionSucceeds(): void {
		$result = $this->mapCoreResult(
			$this->wordpressInstallationResult(),
			array( $this->extra() )
		);

		self::assertTrue( $result->isSuccessful() );
	}

	public function testInstallationResultArrayWithoutOneExactCompletionFailsClosed(): void {
		$cases = array(
			'missing completion'   => array(),
			'wrong completion'     => array(
				array(
					'plugin' => 'other/other.php',
					'type'   => 'plugin',
					'action' => 'update',
				),
			),
			'multiple completions' => array(
				$this->extra(),
				$this->extra(),
			),
		);

		foreach ( $cases as $case => $completions ) {
			$result = $this->mapCoreResult( $this->wordpressInstallationResult(), $completions );

			self::assertFalse( $result->isSuccessful(), $case );
		}
	}

	private function handoffCallback( PreparedArtifact $artifact ): Closure {
		$artifact->assertUnchanged();
		$method = new ReflectionMethod( CorePackageExecutor::class, 'coreReinstallHandoffFilter' );

		return $method->invoke(
			new CorePackageExecutor(),
			'plugin',
			'example/example.php',
			$artifact
		);
	}

	/** @return array{plugin: string, type: string, action: string} */
	private function extra( string $action = 'update' ): array {
		return array(
			'plugin' => 'example/example.php',
			'type'   => 'plugin',
			'action' => $action,
		);
	}

	/** @return array{source: string, source_files: list<string>, destination: string, destination_name: string, local_destination: string, remote_destination: string, clear_destination: bool} */
	private function wordpressInstallationResult(): array {
		return array(
			'source'             => '/tmp/upgrade/example/',
			'source_files'       => array( 'example' ),
			'destination'        => '/wp-content/plugins/example/',
			'destination_name'   => 'example',
			'local_destination'  => '/wp-content/plugins',
			'remote_destination' => '/wp-content/plugins/example/',
			'clear_destination'  => true,
		);
	}

	/** @param list<array<string, mixed>> $completions */
	private function mapCoreResult( mixed $coreResult, array $completions ): CorePackageExecutionResult {
		$method = new ReflectionMethod( CorePackageExecutor::class, 'mapResult' );

		return $method->invoke( new CorePackageExecutor(), $coreResult, 'plugin', 'update', 'example/example.php', $completions );
	}

	private function artifact( string $contents = 'immutable Core artifact' ): PreparedArtifact {
		$path = tempnam( sys_get_temp_dir(), 'ran-booster-executor-claim-' );
		self::assertIsString( $path );
		$this->paths[] = $path;
		file_put_contents( $path, $contents );
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
