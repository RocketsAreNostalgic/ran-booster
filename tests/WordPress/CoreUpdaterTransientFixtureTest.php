<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use Closure;
use PHPUnit\Framework\TestCase;

final class CoreUpdaterTransientFixtureTest extends TestCase {

	public function test_both_transient_fixtures_preserve_foreign_object_identity_and_magic_access(): void {
		foreach ( array( 'plugin', 'theme', 'core-plugin', 'core-theme' ) as $type ) {
			$filter  = $this->filter( $type );
			$foreign = new class() {
				/** @var array<string, mixed> */
				public array $stored = array();
				/** @var list<string> */
				public array $writes    = array();
				public int $reads       = 0;
				public int $isset_calls = 0;

				public function __isset( string $name ): bool {
					++$this->isset_calls;
					return isset( $this->stored[ $name ] );
				}

				public function &__get( string $name ): mixed {
					++$this->reads;
					return $this->stored[ $name ];
				}

				public function __set( string $name, mixed $value ): void {
					$this->writes[]        = $name;
					$this->stored[ $name ] = $value;
				}
			};
			self::assertSame( $foreign, $filter( $foreign ) );
			self::assertSame( array( 'response' ), $foreign->writes );
			self::assertSame( 'plugin' === $type ? 2 : 1, $foreign->reads );
			self::assertSame( 1, $foreign->isset_calls );
			self::assertIsArray( $foreign->stored['response'] );
			self::assertArrayHasKey( 'proof-item', $foreign->stored['response'] );
			self::assertSame( $foreign, $filter( $foreign ) );
			self::assertSame( array( 'response' ), $foreign->writes );
			self::assertSame( 'plugin' === $type ? 5 : 3, $foreign->reads );
			self::assertSame( 2, $foreign->isset_calls );
			foreach ( array( null, false ) as $malformed ) {
				$invalid              = clone $foreign;
				$invalid->stored      = array( 'response' => $malformed );
				$invalid->writes      = array();
				$invalid->reads       = 0;
				$invalid->isset_calls = 0;
				self::assertSame( $invalid, $filter( $invalid ) );
				self::assertSame( array( 'response' ), $invalid->writes );
				self::assertSame( ( 'plugin' === $type ? 2 : 1 ) + ( null === $malformed ? 0 : 1 ), $invalid->reads );
				self::assertSame( 1, $invalid->isset_calls );
			}

			$plain = (object) array(
				'response' => array( 'retained' => 'keep' ),
				'other'    => 'untouched',
			);
			self::assertSame( $plain, $filter( $plain ) );
			self::assertSame( 'keep', $plain->response['retained'] );
			self::assertSame( 'untouched', $plain->other );
			self::assertInstanceOf( \stdClass::class, $filter( false ) );
		}
	}

	public function test_both_transient_fixtures_preserve_native_property_write_errors(): void {
		foreach ( array( 'plugin', 'theme', 'core-plugin', 'core-theme' ) as $type ) {
			$foreign = new class() {
				public readonly mixed $response;

				public function __construct() {
					$this->response = null;
				}
			};
			try {
				$this->filter( $type )( $foreign );
				self::fail( 'A readonly foreign response must reject the existing write.' );
			} catch ( \Error $failure ) {
				self::assertStringContainsString( 'readonly property', $failure->getMessage() );
			}
		}
	}

	/** @return Closure(mixed): object */
	private function filter( string $type ): Closure {
		if ( str_starts_with( $type, 'core-' ) ) {
			$method = new \ReflectionMethod( \RAN\WordPress\CorePackageExecutor::class, 'transient_filter' );

			return $method->invoke( new \RAN\WordPress\CorePackageExecutor(), substr( $type, 5 ), (object) array( 'package' => '/fixture/archive.zip' ), 'proof-item' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the two real transient callbacks without running the disposable installed-site proof.
		$source = file_get_contents( __DIR__ . '/core-updater-proof.php' );
		self::assertIsString( $source );
		$method = strpos( $source, 'private function update_' . $type . '(' );
		self::assertIsInt( $method );
		$start = strpos( $source, 'static function ( mixed $transient )', $method );
		self::assertIsInt( $start );
		$end = strpos( $source, "\n\t\t\t},", $start );
		self::assertIsInt( $end );
		$identifier           = 'proof-item';
		$slug                 = 'proof-item';
		$archive              = '/fixture/archive.zip';
		$version              = '2.0.0';
		$offer                = (object) array(
			'plugin'  => $identifier,
			'package' => $archive,
		);
		$unrelated_identifier = 'other/item.php';
		$unrelated_archive    = '/fixture/unrelated.zip';
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Execute only the actual isolated callbacks; no WordPress bootstrap, filesystem mutation or installed proof is invoked.
		$filter = eval( 'return ' . substr( $source, $start, $end - $start ) . "\n};" );
		self::assertInstanceOf( Closure::class, $filter );

		return $filter;
	}
}
