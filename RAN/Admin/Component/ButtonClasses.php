<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * Class lists for the public Button drawn as static markup.
 *
 * Every server-rendered button in the administration screens takes its
 * classes from here, so the composition the stylesheet expects cannot drift
 * between views. The markup must not also carry WordPress core's button
 * classes: those rules are unlayered and outrank the library's layered ones.
 */
final class ButtonClasses {

	/**
	 * Supported tones. `danger` is Booster's own tone, drawn on the library's
	 * brand solid (or neutral outline) by the ran-booster-button--danger class.
	 *
	 * @var list<string>
	 */
	public const TONES = array( 'brand', 'neutral', 'danger' );

	/**
	 * Supported variants.
	 *
	 * @var list<string>
	 */
	public const VARIANTS = array( 'solid', 'outline', 'minimal' );

	/**
	 * Returns the class list for a Button. Unknown tones and variants fall back
	 * to neutral and outline.
	 *
	 * The list only ever holds the fixed tokens below, so it is safe in an
	 * attribute; views still pass it through esc_attr() with any extra classes.
	 *
	 * @param string $tone    brand, neutral or danger.
	 * @param string $variant solid, outline or minimal.
	 * @param bool   $compact 32px instead of the 40px default, for dense rows only.
	 * @param bool   $anchor  The element is an `a`, which takes the anchor reset.
	 */
	public static function for( string $tone, string $variant, bool $compact = false, bool $anchor = false ): string {
		$tone    = in_array( $tone, self::TONES, true ) ? $tone : 'neutral';
		$variant = in_array( $variant, self::VARIANTS, true ) ? $variant : 'outline';
		$classes = array(
			$anchor ? 'ran-wp-ui-reset-a' : 'ran-wp-ui-reset-button',
			'ran-wp-ui-box-sizing',
			'ran-wp-ui-outset-ring--focus-except-active',
			'ran-wp-ui-button',
		);

		if ( 'danger' === $tone ) {
			$classes[] = 'outline' === $variant ? 'is-neutral' : 'is-brand';
			$classes[] = 'outline' === $variant ? 'is-outline' : 'is-solid';
			$classes[] = 'ran-booster-button--danger';
		} else {
			$classes[] = 'is-' . $tone;
			$classes[] = 'is-' . $variant;
		}

		if ( $compact ) {
			$classes[] = 'is-compact';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Returns the class list for the page's primary action: brand solid.
	 */
	public static function primary( bool $compact = false, bool $anchor = false ): string {
		return self::for( 'brand', 'solid', $compact, $anchor );
	}

	/**
	 * Returns the class list for a secondary action: brand outline. The brand
	 * tone is Booster's admin-accent bridge (00-foundations.css), so it follows
	 * the user's admin colour scheme. Controls that sit with native form
	 * controls (pagination, bulk Apply) ask for `for( 'neutral', 'outline' )`.
	 */
	public static function secondary( bool $compact = false, bool $anchor = false ): string {
		return self::for( 'brand', 'outline', $compact, $anchor );
	}
}
