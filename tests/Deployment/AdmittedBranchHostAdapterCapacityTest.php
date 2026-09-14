<?php

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test fixtures deliberately create isolated local ZIP files.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-only namespaced disk-space state.

namespace Tests\Deployment;

require_once __DIR__ . '/AdmittedBranchHostAdapterWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Deployment\AdmittedBranchHostAdapter;
use RAN\Deployment\DeploymentOutcome;
use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchiveArtifact;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use ZipArchive;

final class AdmittedBranchHostAdapterCapacityTest extends TestCase {
	/** @var list<string> */
	private array $directories = array();
	/** @var list<string> */
	private array $files = array();

	protected function setUp(): void {
		$this->ensureDirectory( WP_CONTENT_DIR );
		$this->ensureDirectory( WP_PLUGIN_DIR );
		unset( $GLOBALS['ran_booster_admitted_disk_free_space'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_admitted_disk_free_space'] );
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( array_reverse( $this->directories ) as $directory ) {
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}
	}

	public function testAdapterConsumesPackageExpandedByteFactWithoutRescanningZip(): void {
		$source = file_get_contents( __DIR__ . '/../../RAN/Deployment/AdmittedBranchHostAdapter.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( '$artifact->archive()->expandedBytes()', $source );
		self::assertStringNotContainsString( 'EXPANDED_RATIO', $source );
		self::assertStringNotContainsString( 'private function expandedBytes', $source );
		self::assertStringNotContainsString( '->statIndex(', $source );
	}

	public function testHostCapacityAcceptsExactlyTwoCopiesPlusTenPercentOverhead(): void {
		list( $artifact, $deployment ) = $this->preparedArtifact();
		$expanded                      = $artifact->archive()->expandedBytes();
		$required                      = ( $expanded * 2 ) + intdiv( $expanded + 9, 10 );
		$GLOBALS['ran_booster_admitted_disk_free_space'] = array(
			WP_CONTENT_DIR => $required,
			WP_PLUGIN_DIR  => $required,
		);

		try {
			$this->invokeCapacityCheck( $artifact, $deployment );
			self::addToAssertionCount( 1 );
		} finally {
			$artifact->cleanup();
		}
	}

	public function testHostCapacityMapsInsufficientDestinationSpaceToExistingOutcome(): void {
		list( $artifact, $deployment ) = $this->preparedArtifact();
		$expanded                      = $artifact->archive()->expandedBytes();
		$required                      = ( $expanded * 2 ) + intdiv( $expanded + 9, 10 );
		$GLOBALS['ran_booster_admitted_disk_free_space'] = array(
			WP_CONTENT_DIR => $required,
			WP_PLUGIN_DIR  => $required - 1,
		);

		try {
			$this->invokeCapacityCheck( $artifact, $deployment );
			self::fail( 'Insufficient destination capacity must fail the admitted host check.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_DEPLOYMENT_DISK_SPACE_LOW, $failure->outcomeCode );
		} finally {
			$artifact->cleanup();
		}
	}

	/** @return array{PreparedArchiveArtifact,BranchDeploymentDeclaration} */
	private function preparedArtifact(): array {
		$source = tempnam( sys_get_temp_dir(), 'ran-booster-capacity-source-' );
		if ( false === $source ) {
			throw new RuntimeException( 'Unable to create the capacity ZIP fixture path.' );
		}
		unlink( $source );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $source, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Unable to create the capacity ZIP fixture.' );
		}
		$zip->addFromString(
			'example/example.php',
			"<?php\n/*\nPlugin Name: Example\nVersion: 2.0.0\n*/\n"
		);
		$zip->close();
		$this->files[] = $source;

		$directory           = sys_get_temp_dir() . '/ran-booster-capacity-' . bin2hex( random_bytes( 8 ) );
		$this->directories[] = $directory;
		$deployment          = new BranchDeploymentDeclaration(
			'capacity-test',
			'plugin',
			'example',
			'owner/example',
			'R_example',
			'main',
			null,
			'install'
		);
		$offer               = new ArchiveOffer(
			'gh',
			'R_example',
			str_repeat( 'a', 40 ),
			static function ( string $destination, int $maximumArtifactBytes ) use ( $source ): void {
				if ( $maximumArtifactBytes < 1 || ! copy( $source, $destination ) ) {
					throw new RuntimeException( 'Unable to copy the capacity ZIP fixture.' );
				}
			},
			static function (): void {}
		);

		return array(
			new PreparedArchiveArtifact(
				PreparedArchive::downloadAndValidate( $offer, $deployment, $directory, 1048576 )
			),
			$deployment,
		);
	}

	private function invokeCapacityCheck( PreparedArchiveArtifact $artifact, BranchDeploymentDeclaration $deployment ): void {
		$adapter = ( new ReflectionClass( AdmittedBranchHostAdapter::class ) )->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod( AdmittedBranchHostAdapter::class, 'assertArtifactCapacity' );
		$method->invoke( $adapter, $artifact, $deployment );
	}

	private function ensureDirectory( string $path ): void {
		if ( ! is_dir( $path ) && ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
			throw new RuntimeException( 'Unable to create the capacity test directory.' );
		}
	}
}
