<?php

declare(strict_types=1);

namespace RAN\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\InvalidPackageSubdirectory;
use RAN\PackageSubdirectory;

final class PackageSubdirectoryTest extends TestCase {

	#[DataProvider( 'valid_paths' )]
	public function test_it_normalizes_valid_relative_paths( mixed $input, ?string $expected ): void {
		self::assertSame( $expected, PackageSubdirectory::normalize( $input ) );
	}

	/** @return array<string, array{mixed, string|null}> */
	public static function valid_paths(): array {
		return array(
			'absent'               => array( null, null ),
			'empty'                => array( '', null ),
			'whitespace'           => array( '  ', null ),
			'single'               => array( 'plugin', 'plugin' ),
			'nested'               => array( 'packages/example-plugin', 'packages/example-plugin' ),
			'trimmed'              => array( ' packages/example-plugin ', 'packages/example-plugin' ),
			'trailing slash'       => array( 'branch-fixture/', 'branch-fixture' ),
			'literal token'        => array( 'packages/%41ddon', 'packages/%41ddon' ),
			'encoded nested colon' => array( 'packages/C%3A-name', 'packages/C%3A-name' ),
			'bounded decode depth' => array( 'packages/%' . str_repeat( '25', 7 ) . '41', 'packages/%' . str_repeat( '25', 7 ) . '41' ),
		);
	}

	#[DataProvider( 'invalid_paths' )]
	public function test_it_rejects_unsafe_paths( mixed $path ): void {
		$this->expectException( InvalidArgumentException::class );

		PackageSubdirectory::normalize( $path );
	}

	/** @return array<string, array{mixed}> */
	public static function invalid_paths(): array {
		return array(
			'non-string'                => array( array( 'packages/example' ) ),
			'absolute'                  => array( '/packages/example' ),
			'UNC'                       => array( '\\\\server\\share' ),
			'drive absolute'            => array( 'C:/packages/example' ),
			'drive relative'            => array( 'C:packages/example' ),
			'encoded drive prefix'      => array( 'C%3A/packages/example' ),
			'encoded drive letter'      => array( '%43:/packages/example' ),
			'double encoded drive'      => array( 'C%253A/packages/example' ),
			'backslash'                 => array( 'packages\\example' ),
			'empty segment'             => array( 'packages//example' ),
			'root'                      => array( '/' ),
			'double root'               => array( '//' ),
			'trailing double separator' => array( 'branch//' ),
			'current segment'           => array( 'packages/./example' ),
			'parent segment'            => array( 'packages/../example' ),
			'encoded parent'            => array( 'packages/%2e%2e/example' ),
			'double encoded parent'     => array( 'packages/%252e%252e/example' ),
			'encoded separator'         => array( 'packages%2fexample' ),
			'double encoded separator'  => array( 'packages%252fexample' ),
			'deep encoded separator'    => array( 'packages%2525252fexample' ),
			'encoded backslash'         => array( 'packages%5cexample' ),
			'decode depth exceeded'     => array( 'packages/%' . str_repeat( '25', 8 ) . '41' ),
			'NUL'                       => array( "packages/\0example" ),
			'control'                   => array( "packages/\nexample" ),
			'control only'              => array( "\n" ),
		);
	}

	public function test_it_maps_shared_path_failures_to_booster_exception(): void {
		$this->expectException( InvalidPackageSubdirectory::class );
		$this->expectExceptionMessage( 'The package subdirectory must be a normalized relative path.' );

		PackageSubdirectory::normalize( 'C%3A/packages/example' );
	}

	public function test_it_derives_only_validated_slugs(): void {
		self::assertSame( 'example-plugin', PackageSubdirectory::slug( 'packages/example-plugin' ) );
		self::assertSame( 'example-plugin', PackageSubdirectory::normalize_slug( value: 'example-plugin' ) );
		self::assertSame( 'repository', PackageSubdirectory::installation_slug( 'repository', null ) );
		self::assertSame( 'example-plugin', PackageSubdirectory::installation_slug( 'repository', 'packages/example-plugin' ) );
		self::assertSame( 'tnyGmaps', PackageSubdirectory::installation_slug( provider_slug: 'tnyGmaps', subdirectory: null ) );
		self::assertSame( 'tnyGmaps', PackageSubdirectory::installation_slug( 'repository', 'packages/tnyGmaps' ) );
		self::assertSame( 'tnygmaps', PackageSubdirectory::deployment_slug( 'tnyGmaps', null ) );
		self::assertSame( 'tnygmaps', PackageSubdirectory::deployment_slug( provider_slug: 'repository', subdirectory: 'packages/tnyGmaps' ) );

		$this->expectException( InvalidArgumentException::class );
		PackageSubdirectory::normalize_slug( 'packages/example-plugin' );
	}

	public function test_it_rejects_trailing_separator_for_provider_destination_slug(): void {
		$this->expectException( InvalidArgumentException::class );

		PackageSubdirectory::normalize_slug( 'foo/' );
	}

	public function test_it_is_idempotent_after_trailing_separator_canonicalization(): void {
		$normalized = PackageSubdirectory::normalize( 'branch-fixture/' );

		self::assertSame( 'branch-fixture', $normalized );
		self::assertSame( $normalized, PackageSubdirectory::normalize( $normalized ) );
	}
}
