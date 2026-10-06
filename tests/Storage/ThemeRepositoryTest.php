<?php

declare(strict_types=1);

namespace RAN\Tests\Storage;

use PHPUnit\Framework\TestCase;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;

require_once __DIR__ . '/../Support/ThemeRepositoryWordPressFunctions.php';

final class ThemeRepositoryTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_theme_repository_test_themes'] = array(
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
	}

	public function test_missing_theme_slug_throws_instead_of_creating_an_invalid_theme_identity(): void {
		$repository = new ThemeRepository();

		$this->expectException( ThemeNotFound::class );
		$repository->from_slug( 'not-a-theme' );
	}
}
