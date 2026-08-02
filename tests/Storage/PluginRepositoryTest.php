<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\TestCase;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;

require_once __DIR__ . '/../Support/PluginRepositoryWordPressFunctions.php';

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) . '/fixtures/wordpress/wp-content/plugins' );
}

final class PluginRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_plugin_repository_test_plugins'] = array(
			'example/example.php' => array( 'Name' => 'Example' ),
		);
	}

	public function testMissingSlugThrowsInsteadOfCreatingAnEmptyPluginIdentity(): void {
		$repository = new PluginRepository();

		$this->expectException( PluginNotFound::class );
		$repository->fromSlug( 'missing-package' );
	}

	public function testPluginInstallationCheckRequiresAWordPressRegisteredPlugin(): void {
		$repository = new class() extends PluginRepository {
			public function packageExistsForTest( string $identifier ): bool {
				return $this->packageExists( $identifier );
			}
		};

		self::assertTrue( $repository->packageExistsForTest( 'example/example.php' ) );
		self::assertFalse( $repository->packageExistsForTest( '' ) );
		self::assertFalse( $repository->packageExistsForTest( 'example/other.php' ) );
	}
}
