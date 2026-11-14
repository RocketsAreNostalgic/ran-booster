<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\ButtonClasses;

final class ButtonClassesTest extends TestCase {
	private const BASE = 'ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button';

	public function test_it_composes_the_library_button_classes_in_order(): void {
		self::assertSame( self::BASE . ' is-brand is-solid', ButtonClasses::for( 'brand', 'solid' ) );
		self::assertSame( self::BASE . ' is-neutral is-outline', ButtonClasses::for( 'neutral', 'outline' ) );
		self::assertSame( self::BASE . ' is-neutral is-minimal is-compact', ButtonClasses::for( 'neutral', 'minimal', true ) );
		self::assertSame( ButtonClasses::for( 'brand', 'solid' ), ButtonClasses::primary() );
		self::assertSame( self::BASE . ' is-brand is-outline', ButtonClasses::secondary() );
		self::assertSame( ButtonClasses::for( 'brand', 'outline', true, true ), ButtonClasses::secondary( true, true ) );
	}

	public function test_anchors_take_the_anchor_reset_instead_of_the_button_reset(): void {
		$classes = ButtonClasses::primary( false, true );

		self::assertStringStartsWith( 'ran-wp-ui-reset-a ', $classes );
		self::assertStringNotContainsString( 'ran-wp-ui-reset-button', $classes );
	}

	public function test_danger_is_a_booster_modifier_on_a_library_tone(): void {
		self::assertSame( self::BASE . ' is-brand is-solid ran-booster-button--danger', ButtonClasses::for( 'danger', 'solid' ) );
		self::assertSame( self::BASE . ' is-neutral is-outline ran-booster-button--danger', ButtonClasses::for( 'danger', 'outline' ) );
	}

	public function test_unknown_values_fall_back_and_core_classes_never_appear(): void {
		self::assertSame( self::BASE . ' is-neutral is-outline', ButtonClasses::for( 'warning" onclick="x', 'ghost' ) );

		foreach ( ButtonClasses::TONES as $tone ) {
			foreach ( ButtonClasses::VARIANTS as $variant ) {
				foreach ( array( true, false ) as $compact ) {
					foreach ( array( true, false ) as $anchor ) {
						$tokens = explode( ' ', ButtonClasses::for( $tone, $variant, $compact, $anchor ) );
						self::assertSame( array(), array_intersect( $tokens, array( 'button', 'button-primary', 'button-secondary', 'button-delete' ) ) );
						foreach ( $tokens as $token ) {
							self::assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $token );
						}
					}
				}
			}
		}
	}
}
