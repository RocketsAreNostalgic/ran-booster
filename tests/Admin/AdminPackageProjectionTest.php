<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\AdminPackageProjection;

final class AdminPackageProjectionTest extends TestCase {

	public function test_subdirectory_normalizes_whitespace_only_root(): void {
		self::assertSame( '', $this->projection( " \t\n " )->subdirectory() );
	}

	public function test_subdirectory_normalizes_aconfigured_path(): void {
		self::assertSame( 'plugins/example', $this->projection( '  plugins/example  ' )->subdirectory() );
	}

	public function test_subdirectory_accepts_a255_character_path_and_rejects_an_overflow(): void {
		self::assertSame( str_repeat( 'a', 255 ), $this->projection( str_repeat( 'a', 255 ) )->subdirectory() );

		$this->expectException( InvalidArgumentException::class );
		$this->projection( str_repeat( 'a', 256 ) );
	}

	private function projection( string $subdirectory ): AdminPackageProjection {
		return new AdminPackageProjection(
			'plugin',
			'example/example.php',
			'Example',
			'fixture-provider',
			'branch',
			1,
			'manual',
			'https://example.test/settings',
			$subdirectory
		);
	}
}
