<?php

declare(strict_types=1);

namespace RAN\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster;

/**
 * The extension catalogue is read while the plugin file loads (to register native updates for installed
 * extensions), before the textdomain is available. That path must not translate anything.
 * Each test runs in its own process because it defines the global WordPress functions.
 */
final class BoosterExtensionCatalogueEarlyTranslationTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_declarations_build_without_translating_before_init(): void {
		$this->define_wordpress_functions( false );

		$declarations = Booster::extension_declarations( false );

		self::assertNotSame( array(), $declarations );
		foreach ( $declarations as $extension ) {
			self::assertSame( '', $extension['description'] );
			self::assertSame( '', $extension['availability'] );
			self::assertSame( array(), $extension['requirements'] );
			self::assertSame( array(), $extension['features'] );
			self::assertIsString( $extension['plugin'] );
			self::assertNotSame( '', $extension['plugin'] );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_update_registration_cards_carry_every_fact_without_translating_before_init(): void {
		$this->define_wordpress_functions( false );

		$cards = $this->cards( false );

		self::assertNotSame( array(), $cards );
		foreach ( $cards as $card ) {
			// The keys the native update registration requires (ExtensionUpdates::valid()).
			foreach ( array( 'plugin', 'release_version', 'asset_sha256', 'repository', 'repository_id', 'canonical_update_uri', 'state' ) as $key ) {
				self::assertIsString( $card[ $key ] );
				self::assertNotSame( '', $card[ $key ], $key );
			}
			self::assertIsBool( $card['compatible'] );
			self::assertSame( '', $card['compatibility_label'] );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_catalogue_is_translated_for_display_after_init(): void {
		$this->define_wordpress_functions( true );

		foreach ( Booster::extension_declarations() as $extension ) {
			self::assertStringStartsWith( '[translated] ', $extension['description'] );
			self::assertStringStartsWith( '[translated] ', $extension['availability'] );
			self::assertNotSame( array(), $extension['requirements'] );
		}
		foreach ( $this->cards( true ) as $card ) {
			self::assertStringStartsWith( '[translated] ', $card['compatibility_label'] );
		}
	}

	/** @return list<array<string, mixed>> */
	private function cards( bool $translated ): array {
		$method = new \ReflectionMethod( Booster::class, 'extension_cards' );
		$cards  = $method->invoke( new Booster(), array(), $translated );
		self::assertIsArray( $cards );

		return array_values( $cards );
	}

	private function define_wordpress_functions( bool $init_has_run ): void {
		$GLOBALS['ran_booster_test_init_has_run'] = $init_has_run;
		require_once __DIR__ . '/BoosterExtensionCatalogueEarlyTranslationFunctions.php';
	}
}
