<?php

declare(strict_types=1);


namespace RAN\Tests\Deployment;

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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->ensure_directory( WP_CONTENT_DIR );
		$this->ensure_directory( WP_PLUGIN_DIR );
		unset( $GLOBALS['ran_booster_admitted_disk_free_space'] );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_admitted_disk_free_space'] );
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) || is_link( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $file );
			}
		}
		foreach ( array_reverse( $this->directories ) as $directory ) {
			if ( is_dir( $directory ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				rmdir( $directory );
			}
		}
	}

	public function test_adapter_consumes_package_expanded_byte_fact_without_rescanning_zip(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		$source = file_get_contents( __DIR__ . '/../../RAN/Deployment/AdmittedBranchHostAdapter.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( '$artifact->archive()->expanded_bytes()', $source );
		self::assertStringNotContainsString( 'EXPANDED_RATIO', $source );
		self::assertStringNotContainsString( 'private function expanded_bytes', $source );
		self::assertStringNotContainsString( '->statIndex(', $source );
	}

	public function test_host_capacity_accepts_exactly_two_copies_plus_ten_percent_overhead(): void {
		list( $artifact, $deployment )                   = $this->prepared_artifact();
		$expanded                                        = $artifact->archive()->expanded_bytes();
		$required                                        = ( $expanded * 2 ) + intdiv( $expanded + 9, 10 );
		$GLOBALS['ran_booster_admitted_disk_free_space'] = array(
			WP_CONTENT_DIR => $required,
			WP_PLUGIN_DIR  => $required,
		);

		try {
			$this->invoke_capacity_check( $artifact, $deployment );
			self::addToAssertionCount( 1 );
		} finally {
			$artifact->cleanup();
		}
	}

	public function test_host_capacity_maps_insufficient_destination_space_to_existing_outcome(): void {
		list( $artifact, $deployment )                   = $this->prepared_artifact();
		$expanded                                        = $artifact->archive()->expanded_bytes();
		$required                                        = ( $expanded * 2 ) + intdiv( $expanded + 9, 10 );
		$GLOBALS['ran_booster_admitted_disk_free_space'] = array(
			WP_CONTENT_DIR => $required,
			WP_PLUGIN_DIR  => $required - 1,
		);

		try {
			$this->invoke_capacity_check( $artifact, $deployment );
			self::fail( 'Insufficient destination capacity must fail the admitted host check.' );
		} catch ( AdmittedBranchStageFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_DEPLOYMENT_DISK_SPACE_LOW, $failure->outcome_code );
		} finally {
			$artifact->cleanup();
		}
	}

	/** @return array{PreparedArchiveArtifact,BranchDeploymentDeclaration} */
	private function prepared_artifact(): array {
		$source = tempnam( sys_get_temp_dir(), 'ran-booster-capacity-source-' );
		if ( false === $source ) {
			throw new RuntimeException( 'Unable to create the capacity ZIP fixture path.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
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
			static function ( string $destination, int $maximum_artifact_bytes ) use ( $source ): void {
				if ( $maximum_artifact_bytes < 1 || ! copy( $source, $destination ) ) {
					throw new RuntimeException( 'Unable to copy the capacity ZIP fixture.' );
				}
			},
			static function (): void {}
		);

		return array(
			new PreparedArchiveArtifact(
				PreparedArchive::download_and_validate( $offer, $deployment, $directory, 1048576 )
			),
			$deployment,
		);
	}

	private function invoke_capacity_check( PreparedArchiveArtifact $artifact, BranchDeploymentDeclaration $deployment ): void {
		$adapter = ( new ReflectionClass( AdmittedBranchHostAdapter::class ) )->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod( AdmittedBranchHostAdapter::class, 'assert_artifact_capacity' );
		$method->invoke( $adapter, $artifact, $deployment );
	}

	private function ensure_directory( string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		if ( ! is_dir( $path ) && ! mkdir( $path, 0777, true ) && ! is_dir( $path ) ) {
			throw new RuntimeException( 'Unable to create the capacity test directory.' );
		}
	}
}
