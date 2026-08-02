<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\PackageSubdirectory;

final class PackageSubdirectoryTest extends TestCase {

	#[DataProvider( 'validPaths' )]
	public function testItNormalizesValidRelativePaths( mixed $input, ?string $expected ): void {
		self::assertSame( $expected, PackageSubdirectory::normalize( $input ) );
	}

	/** @return array<string, array{mixed, string|null}> */
	public static function validPaths(): array {
		return array(
			'absent'        => array( null, null ),
			'empty'         => array( '', null ),
			'whitespace'    => array( '  ', null ),
			'single'        => array( 'plugin', 'plugin' ),
			'nested'        => array( 'packages/example-plugin', 'packages/example-plugin' ),
			'trimmed'       => array( ' packages/example-plugin ', 'packages/example-plugin' ),
			'literal token' => array( 'packages/%41ddon', 'packages/%41ddon' ),
		);
	}

	#[DataProvider( 'invalidPaths' )]
	public function testItRejectsUnsafePaths( mixed $path ): void {
		$this->expectException( InvalidArgumentException::class );

		PackageSubdirectory::normalize( $path );
	}

	/** @return array<string, array{mixed}> */
	public static function invalidPaths(): array {
		return array(
			'non-string'               => array( array( 'packages/example' ) ),
			'absolute'                 => array( '/packages/example' ),
			'UNC'                      => array( '\\\\server\\share' ),
			'drive absolute'           => array( 'C:/packages/example' ),
			'drive relative'           => array( 'C:packages/example' ),
			'backslash'                => array( 'packages\\example' ),
			'empty segment'            => array( 'packages//example' ),
			'trailing separator'       => array( 'packages/example/' ),
			'current segment'          => array( 'packages/./example' ),
			'parent segment'           => array( 'packages/../example' ),
			'encoded parent'           => array( 'packages/%2e%2e/example' ),
			'double encoded parent'    => array( 'packages/%252e%252e/example' ),
			'encoded separator'        => array( 'packages%2fexample' ),
			'double encoded separator' => array( 'packages%252fexample' ),
			'deep encoded separator'   => array( 'packages%2525252fexample' ),
			'encoded backslash'        => array( 'packages%5cexample' ),
			'NUL'                      => array( "packages/\0example" ),
			'control'                  => array( "packages/\nexample" ),
		);
	}

	public function testItDerivesOnlyValidatedSlugs(): void {
		self::assertSame( 'example-plugin', PackageSubdirectory::slug( 'packages/example-plugin' ) );
		self::assertSame( 'example-plugin', PackageSubdirectory::normalizeSlug( 'example-plugin' ) );
		self::assertSame( 'repository', PackageSubdirectory::installationSlug( 'repository', null ) );
		self::assertSame( 'example-plugin', PackageSubdirectory::installationSlug( 'repository', 'packages/example-plugin' ) );
		self::assertSame( 'tnyGmaps', PackageSubdirectory::installationSlug( 'tnyGmaps', null ) );
		self::assertSame( 'tnyGmaps', PackageSubdirectory::installationSlug( 'repository', 'packages/tnyGmaps' ) );
		self::assertSame( 'tnygmaps', PackageSubdirectory::deploymentSlug( 'tnyGmaps', null ) );
		self::assertSame( 'tnygmaps', PackageSubdirectory::deploymentSlug( 'repository', 'packages/tnyGmaps' ) );

		$this->expectException( InvalidArgumentException::class );
		PackageSubdirectory::normalizeSlug( 'packages/example-plugin' );
	}
}
