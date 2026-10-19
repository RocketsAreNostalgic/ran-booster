<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN_Booster_GitHubReplacementFixture\Fixture;

require_once __DIR__ . '/../fixtures/github-replacement/fixture.php';
require_once __DIR__ . '/../Support/GitHubReplacementFixtureWordPressFunctions.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GitHubReplacementFixtureGuardTest extends TestCase {
	public function test_default_origin_remains_ci_localhost(): void {
		self::assertSame( 'http://localhost', Fixture::expected_origin() );
	}

	/** @return iterable<string,array{mixed,bool}> */
	public static function origins(): iterable {
		yield 'local acceptance' => array( 'http://localhost:10013', true );
		yield 'lowest port' => array( 'http://localhost:1', true );
		yield 'highest port' => array( 'http://localhost:65535', true );
		foreach ( array( 'https://localhost:10013', 'http://localhost:0', 'http://localhost:65536', 'http://localhost:010013', 'http://localhost:', 'http://localhost/', 'http://localhost?x', 'http://localhost#x', 'http://user@localhost', 'http://localhost.example', 'http://127.0.0.1:10013', "http://localhost\n", '', false, 10013 ) as $index => $origin ) {
			yield 'invalid ' . $index => array( $origin, false );
		}
	}

	#[DataProvider( 'origins' )]
	public function test_only_explicit_local_origin_is_accepted( mixed $origin, bool $accepted ): void {
		define( 'RAN_BOOSTER_REPLACEMENT_FIXTURE_ORIGIN', $origin );
		if ( ! $accepted ) {
			$this->expectException( \RuntimeException::class );
		}
		self::assertSame( $origin, Fixture::expected_origin() );
	}

	/** @return iterable<string,array{string}> */
	public static function site_cases(): iterable {
		foreach ( array( 'valid', 'wrong-home', 'wrong-siteurl', 'wrong-marker', 'missing-marker', 'symlink-marker', 'symlink-plugin' ) as $case ) {
			yield $case => array( $case );
		}
	}

	#[DataProvider( 'site_cases' )]
	public function test_guard_preserves_exact_site_and_marker_requirements( string $case ): void {
		$root = sys_get_temp_dir() . '/ran-replacement-guard-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Exact random test-owned fixture path; no WordPress site is mutated.
		mkdir( $root . '/wp-content/plugins', 0700, true );
		$marker = $root . '/.ran-booster-disposable-test-site';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact random test-owned fixture path; no WordPress site is mutated.
		file_put_contents( $marker, 'RAN Booster disposable test site' );
		define( 'ABSPATH', $root . '/' );
		define( 'WP_CONTENT_DIR', $root . '/wp-content' );
		define( 'WP_PLUGIN_DIR', $root . '/wp-content/plugins' );
		define( 'RAN_BOOSTER_REPLACEMENT_FIXTURE_ORIGIN', 'http://localhost:10013' );
		$GLOBALS['ran_booster_fixture_guard_options'] = array( 'home' => 'http://localhost:10013', 'siteurl' => 'http://localhost:10013' );
		if ( 'wrong-home' === $case || 'wrong-siteurl' === $case ) {
			$GLOBALS['ran_booster_fixture_guard_options'][ substr( $case, 6 ) ] = 'http://localhost';
		} elseif ( 'wrong-marker' === $case ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact random test-owned fixture path; no WordPress site is mutated.
			file_put_contents( $marker, 'not disposable' );
		} elseif ( 'missing-marker' === $case || 'symlink-marker' === $case ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exact random test-owned fixture path; no WordPress site is mutated.
			unlink( $marker );
			if ( 'symlink-marker' === $case ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact random test-owned fixture path; no WordPress site is mutated.
				file_put_contents( $root . '/marker-source', 'RAN Booster disposable test site' );
				symlink( $root . '/marker-source', $marker );
			}
		} elseif ( 'symlink-plugin' === $case ) {
			symlink( $root, WP_PLUGIN_DIR . '/github-replacement' );
		}
		try {
			if ( 'valid' !== $case ) {
				$this->expectException( \RuntimeException::class );
			}
			Fixture::guard();
			self::assertSame( 'valid', $case );
		} finally {
			foreach ( array( $marker, $root . '/marker-source', WP_PLUGIN_DIR . '/github-replacement' ) as $path ) {
				if ( file_exists( $path ) || is_link( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Exact random test-owned fixture path; no WordPress site is mutated.
					unlink( $path );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exact random test-owned fixture path; no WordPress site is mutated.
			rmdir( WP_PLUGIN_DIR );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exact random test-owned fixture path; no WordPress site is mutated.
			rmdir( WP_CONTENT_DIR );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Exact random test-owned fixture path; no WordPress site is mutated.
			rmdir( $root );
		}
	}
}
