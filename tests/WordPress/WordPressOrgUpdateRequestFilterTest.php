<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressOrgUpdateRequestFilter;
use RuntimeException;

require_once __DIR__ . '/WordPressOrgUpdateRequestFilterWordPressFunctions.php';

#[CoversClass( WordPressOrgUpdateRequestFilter::class )]
final class WordPressOrgUpdateRequestFilterTest extends TestCase {

	public function testFiltersManagedPluginsAndThemesFromValidRequests(): void {
		$database = $this->createStub( Database::class );
		$database->method( 'isSupported' )->willReturn( true );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allBoosterPlugins' )->willReturn( array( 'managed/plugin.php' => new \stdClass() ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'allBoosterThemes' )->willReturn( array( 'managed-theme' => new \stdClass() ) );
		$filter = new WordPressOrgUpdateRequestFilter( $database, $plugins, $themes, 'ran-booster/ran-booster.php' );

		$pluginArgs    = $filter->plugins(
			array(
				'body' => array(
					'plugins' => \RAN\WordPress\wp_json_encode(
						array(
							'plugins' => array(
								'managed/plugin.php' => array(),
								'ran-booster/ran-booster.php' => array(),
								'other/plugin.php'   => array(),
							),
							'active'  => array( 'managed/plugin.php', 'ran-booster/ran-booster.php', 'other/plugin.php' ),
						)
					),
				),
			),
			'https://api.wordpress.org/plugins/update-check/1.1/'
		);
		$pluginPayload = json_decode( $pluginArgs['body']['plugins'], true );
		self::assertSame( array( 'other/plugin.php' ), array_keys( $pluginPayload['plugins'] ) );
		self::assertSame( array( 2 => 'other/plugin.php' ), $pluginPayload['active'] );

		$themeArgs    = $filter->themes(
			array(
				'body' => array(
					'themes' => \RAN\WordPress\wp_json_encode(
						array(
							'themes' => array(
								'managed-theme' => array(),
								'other-theme'   => array(),
							),
							'active' => 'managed-theme',
						)
					),
				),
			),
			'https://api.wordpress.org/themes/update-check/1.1/'
		);
		$themePayload = json_decode( $themeArgs['body']['themes'], true );
		self::assertSame( array( 'other-theme' ), array_keys( $themePayload['themes'] ) );
		self::assertArrayNotHasKey( 'active', $themePayload );
	}

	public function testMalformedRequestsReturnTheIncomingValueUnchanged(): void {
		$filter = $this->filter();
		$cases  = array(
			null,
			'previous-filter-result',
			array(),
			array( 'body' => 'invalid' ),
			array( 'body' => array() ),
			array( 'body' => array( 'plugins' => '{' ) ),
			array( 'body' => array( 'plugins' => '{"plugins":[],"active":"invalid"}' ) ),
			array( 'body' => array( 'themes' => '{"themes":[],"active":[]}' ) ),
		);

		foreach ( $cases as $args ) {
			self::assertSame( $args, $filter->plugins( $args, 'https://api.wordpress.org/plugins/update-check/1.1/' ) );
			self::assertSame( $args, $filter->themes( $args, 'https://api.wordpress.org/themes/update-check/1.1/' ) );
		}
	}

	public function testStorageFailureReturnsTheIncomingArgumentsUnchanged(): void {
		$database = $this->createStub( Database::class );
		$database->method( 'isSupported' )->willReturn( true );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'allBoosterPlugins' )->willThrowException( new RuntimeException( 'database details' ) );
		$args = array(
			'body' => array(
				'plugins' => '{"plugins":{"managed/plugin.php":{}},"active":[]}',
			),
		);

		self::assertSame(
			$args,
			( new WordPressOrgUpdateRequestFilter( $database, $plugins, $this->createStub( ThemeRepository::class ), 'booster.php' ) )
				->plugins( $args, 'https://api.wordpress.org/plugins/update-check/1.1/' )
		);
	}

	private function filter(): WordPressOrgUpdateRequestFilter {
		$database = $this->createStub( Database::class );
		$database->method( 'isSupported' )->willReturn( true );

		return new WordPressOrgUpdateRequestFilter(
			$database,
			$this->createStub( PluginRepository::class ),
			$this->createStub( ThemeRepository::class ),
			'ran-booster/ran-booster.php'
		);
	}
}
