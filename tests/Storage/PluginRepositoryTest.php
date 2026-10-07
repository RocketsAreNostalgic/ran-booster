<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use PHPUnit\Framework\TestCase;
use RAN\Plugin;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;

require_once __DIR__ . '/../Support/PluginRepositoryWordPressFunctions.php';

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) . '/fixtures/wordpress/wp-content/plugins' );
}

final class PluginRepositoryTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_plugin_repository_test_plugins'] = array(
			'example/example.php' => array(
				'Name'        => 'Example',
				'PluginURI'   => 'https://example.test/plugin',
				'Version'     => '1.0.0',
				'Description' => 'Example plugin.',
				'Author'      => 'Example',
				'AuthorURI'   => 'https://example.test',
				'TextDomain'  => 'example',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => 'Example',
			),
		);
	}

	public function test_slug_hydration_does_not_require_aglobal_container(): void {
		$plugin = ( new PluginRepository() )->from_slug( 'example' );

		self::assertInstanceOf( Plugin::class, $plugin );
		self::assertSame( 'example/example.php', $plugin->get_identifier() );
	}

	public function test_missing_slug_throws_instead_of_creating_an_empty_plugin_identity(): void {
		$repository = new PluginRepository();

		$this->expectException( PluginNotFound::class );
		$repository->from_slug( 'missing-package' );
	}

	public function test_plugin_installation_check_requires_aword_press_registered_plugin(): void {
		$repository = new class() extends PluginRepository {
			public function package_exists_for_test( string $identifier ): bool {
				return $this->package_exists( $identifier );
			}
		};

		self::assertTrue( $repository->package_exists_for_test( 'example/example.php' ) );
		self::assertFalse( $repository->package_exists_for_test( '' ) );
		self::assertFalse( $repository->package_exists_for_test( 'example/other.php' ) );
	}
}
