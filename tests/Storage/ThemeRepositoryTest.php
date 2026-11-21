<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;

require_once __DIR__ . '/../Support/ThemeRepositoryWordPressFunctions.php';

final class ThemeRepositoryTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_theme_repository_test_reads']     = array();
		$GLOBALS['ran_booster_theme_repository_test_installed'] = array();
		$GLOBALS['ran_booster_theme_repository_test_themes']    = array(
			'example-theme' => new class() {
				public function exists(): bool {
					return true;
				}

				public function errors(): false {
					return false;
				}
			},
			'broken-theme'  => new class() {
				public function exists(): bool {
					return true;
				}

				public function errors(): object {
					return new \stdClass();
				}
			},
		);
	}

	public function test_theme_installation_check_requires_aword_press_recognized_theme(): void {
		$repository = new class() extends ThemeRepository {
			public function package_exists_for_test( string $identifier ): bool {
				return $this->package_exists( $identifier );
			}
		};

		self::assertTrue( $repository->package_exists_for_test( 'example-theme' ) );
		self::assertFalse( $repository->package_exists_for_test( '' ) );
		self::assertFalse( $repository->package_exists_for_test( 'broken-theme' ) );
		self::assertFalse( $repository->package_exists_for_test( 'not-a-theme' ) );
		self::assertSame( array( 'example-theme', 'broken-theme', 'not-a-theme' ), $GLOBALS['ran_booster_theme_repository_test_reads'] );
	}

	/** @return array<string, array{string}> */
	public static function lookups_by_name(): array {
		return array(
			'connect by name'             => array( 'from_slug' ),
			'connect by given stylesheet' => array( 'installed_theme_from_stylesheet' ),
		);
	}

	#[DataProvider( 'lookups_by_name' )]
	public function test_a_theme_found_by_another_spelling_is_recorded_under_the_installed_folder( string $method ): void {
		// The installed folder is mytheme; the source repository is spelled MyTheme.
		$GLOBALS['ran_booster_theme_repository_test_installed'] = array( 'mytheme' => new \WP_Theme( 'mytheme' ) );

		$theme = ( new ThemeRepository() )->$method( 'MyTheme' );

		self::assertSame( 'mytheme', $theme->get_identifier() );
	}

	public function test_two_folders_that_differ_only_in_case_each_keep_their_own_spelling(): void {
		// A case-sensitive filesystem can hold both; an exact name never resolves to the other folder.
		$GLOBALS['ran_booster_theme_repository_test_installed'] = array(
			'MyTheme' => new \WP_Theme( 'MyTheme' ),
			'mytheme' => new \WP_Theme( 'mytheme' ),
		);
		$GLOBALS['ran_booster_theme_repository_test_themes']    = array(
			'MyTheme' => new \WP_Theme( 'MyTheme' ),
			'mytheme' => new \WP_Theme( 'mytheme' ),
		);

		self::assertSame( 'mytheme', ( new ThemeRepository() )->from_slug( 'mytheme' )->get_identifier() );
		self::assertSame( 'MyTheme', ( new ThemeRepository() )->from_slug( 'MyTheme' )->get_identifier() );
	}

	public function test_a_theme_found_by_its_own_spelling_keeps_it(): void {
		$GLOBALS['ran_booster_theme_repository_test_installed'] = array( 'MyTheme' => new \WP_Theme( 'MyTheme' ) );

		self::assertSame( 'MyTheme', ( new ThemeRepository() )->from_slug( 'MyTheme' )->get_identifier() );
	}

	public function test_missing_theme_slug_throws_instead_of_creating_an_invalid_theme_identity(): void {
		$repository = new ThemeRepository();

		$this->expectException( ThemeNotFound::class );
		$repository->from_slug( 'not-a-theme' );
	}
}
