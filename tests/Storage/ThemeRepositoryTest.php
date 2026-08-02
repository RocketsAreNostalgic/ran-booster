<?php

declare(strict_types=1);

namespace Tests\Storage;

use PHPUnit\Framework\TestCase;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;

require_once __DIR__ . '/../Support/ThemeRepositoryWordPressFunctions.php';

final class ThemeRepositoryTest extends TestCase {

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

	public function testThemeInstallationCheckRequiresAWordPressRecognizedTheme(): void {
		$repository = new class() extends ThemeRepository {
			public function packageExistsForTest( string $identifier ): bool {
				return $this->packageExists( $identifier );
			}
		};

		self::assertTrue( $repository->packageExistsForTest( 'example-theme' ) );
		self::assertFalse( $repository->packageExistsForTest( '' ) );
		self::assertFalse( $repository->packageExistsForTest( 'broken-theme' ) );
		self::assertFalse( $repository->packageExistsForTest( 'not-a-theme' ) );
	}

	public function testMissingThemeSlugThrowsInsteadOfCreatingAnInvalidThemeIdentity(): void {
		$repository = new ThemeRepository();

		$this->expectException( ThemeNotFound::class );
		$repository->fromSlug( 'not-a-theme' );
	}
}
