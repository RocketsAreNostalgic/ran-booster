<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressOrgUpdateRequestFilter;
use RuntimeException;

require_once __DIR__ . '/WordPressOrgUpdateRequestFilterWordPressFunctions.php';

#[CoversClass( WordPressOrgUpdateRequestFilter::class )]
final class WordPressOrgUpdateRequestFilterTest extends TestCase {

	public function test_filters_managed_plugins_and_themes_from_valid_requests(): void {
		$database = $this->createStub( Database::class );
		$database->method( 'is_supported' )->willReturn( true );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_booster_plugins' )->willReturn( array( 'managed/plugin.php' => new \stdClass() ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_booster_themes' )->willReturn( array( 'managed-theme' => new \stdClass() ) );
		$filter = new WordPressOrgUpdateRequestFilter( $database, $plugins, $themes, 'ran-booster/ran-booster.php' );

		$plugin_args    = $filter->plugins(
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
		$plugin_payload = json_decode( $plugin_args['body']['plugins'], true );
		self::assertSame( array( 'other/plugin.php' ), array_keys( $plugin_payload['plugins'] ) );
		self::assertSame( array( 'other/plugin.php' ), $plugin_payload['active'] );
		self::assertSame( '{"plugins":{"other\/plugin.php":[]},"active":["other\/plugin.php"]}', $plugin_args['body']['plugins'] );

		$theme_args    = $filter->themes(
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
		$theme_payload = json_decode( $theme_args['body']['themes'], true );
		self::assertSame( array( 'other-theme' ), array_keys( $theme_payload['themes'] ) );
		self::assertArrayNotHasKey( 'active', $theme_payload );
	}

	#[DataProvider( 'exact_update_endpoints' )]
	public function test_filters_only_exact_word_press_org_update_endpoints( string $method, string $url ): void {
		$database = $this->createStub( Database::class );
		$database->method( 'is_supported' )->willReturn( true );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_booster_plugins' )->willReturn( array( 'managed/plugin.php' => new \stdClass() ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_booster_themes' )->willReturn( array( 'managed-theme' => new \stdClass() ) );
		$filter = new WordPressOrgUpdateRequestFilter( $database, $plugins, $themes, 'ran-booster/ran-booster.php' );
		$args   = 'plugins' === $method
			? array( 'body' => array( 'plugins' => '{"plugins":{"managed/plugin.php":{}},"active":["managed/plugin.php"]}' ) )
			: array( 'body' => array( 'themes' => '{"themes":{"managed-theme":{}},"active":"managed-theme"}' ) );

		$result = $filter->{$method}( $args, $url );
		self::assertNotSame( $args, $result );
	}

	/** @return array<string, array{'plugins'|'themes', string}> */
	public static function exact_update_endpoints(): array {
		return array(
			'plugin HTTPS'           => array( 'plugins', 'https://api.wordpress.org/plugins/update-check/1.1/' ),
			'plugin HTTP'            => array( 'plugins', 'http://api.wordpress.org/plugins/update-check/1.1/' ),
			'theme DNS case variant' => array( 'themes', 'https://API.WORDPRESS.ORG/themes/update-check/1.1/' ),
		);
	}

	#[DataProvider( 'non_exact_update_endpoints' )]
	public function test_leaves_non_exact_word_press_org_update_urls_unchanged( string $method, string $url ): void {
		$args = 'plugins' === $method
			? array( 'body' => array( 'plugins' => '{"plugins":{"managed/plugin.php":{},"ran-booster/ran-booster.php":{}},"active":["managed/plugin.php","ran-booster/ran-booster.php"]}' ) )
			: array( 'body' => array( 'themes' => '{"themes":{"managed-theme":{}},"active":"managed-theme"}' ) );

		self::assertSame( $args, $this->filter_with_managed_records()->{$method}( $args, $url ) );
	}

	/** @return array<string, array{'plugins'|'themes', string}> */
	public static function non_exact_update_endpoints(): array {
		return array(
			'endpoint prefix' => array( 'plugins', 'https://api.wordpress.org/plugins/update-check/1.1/extra' ),
			'user info'       => array( 'plugins', 'https://user@api.wordpress.org/plugins/update-check/1.1/' ),
			'user and pass'   => array( 'plugins', 'https://user:pass@api.wordpress.org/plugins/update-check/1.1/' ),
			'explicit port'   => array( 'plugins', 'https://api.wordpress.org:443/plugins/update-check/1.1/' ),
			'query'           => array( 'plugins', 'https://api.wordpress.org/plugins/update-check/1.1/?request=1' ),
			'empty query'     => array( 'plugins', 'https://api.wordpress.org/plugins/update-check/1.1/?' ),
			'fragment'        => array( 'plugins', 'https://api.wordpress.org/plugins/update-check/1.1/#request' ),
			'empty fragment'  => array( 'plugins', 'https://api.wordpress.org/plugins/update-check/1.1/#' ),
			'suffix host'     => array( 'plugins', 'https://api.wordpress.org.example/plugins/update-check/1.1/' ),
			'lookalike host'  => array( 'plugins', 'https://api.wordpress.org.evil/plugins/update-check/1.1/' ),
			'other version'   => array( 'plugins', 'https://api.wordpress.org/plugins/update-check/1.2/' ),
			'other path'      => array( 'themes', 'https://api.wordpress.org/plugins/update-check/1.1/' ),
			'malformed URL'   => array( 'themes', 'https://api.wordpress.org:bad/themes/update-check/1.1/' ),
		);
	}

	public function test_malformed_requests_return_the_incoming_value_unchanged(): void {
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

	public function test_storage_failure_returns_the_incoming_arguments_unchanged(): void {
		$database = $this->createStub( Database::class );
		$database->method( 'is_supported' )->willReturn( true );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_booster_plugins' )->willThrowException( new RuntimeException( 'database details' ) );
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
		$database->method( 'is_supported' )->willReturn( true );

		return new WordPressOrgUpdateRequestFilter(
			$database,
			$this->createStub( PluginRepository::class ),
			$this->createStub( ThemeRepository::class ),
			'ran-booster/ran-booster.php'
		);
	}

	private function filter_with_managed_records(): WordPressOrgUpdateRequestFilter {
		$database = $this->createStub( Database::class );
		$database->method( 'is_supported' )->willReturn( true );
		$plugins = $this->createStub( PluginRepository::class );
		$plugins->method( 'all_booster_plugins' )->willReturn( array( 'managed/plugin.php' => new \stdClass() ) );
		$themes = $this->createStub( ThemeRepository::class );
		$themes->method( 'all_booster_themes' )->willReturn( array( 'managed-theme' => new \stdClass() ) );

		return new WordPressOrgUpdateRequestFilter( $database, $plugins, $themes, 'ran-booster/ran-booster.php' );
	}
}
