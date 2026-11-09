<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * Renders the intent icon of a static public Notice.
 *
 * Notice.Root draws a 24px @wordpress/icons glyph for each intent (info,
 * caution, published and error; neutral has none) after its children. A
 * static notice carries the same SVG as markup, decorative and hidden from
 * assistive technology: the notice text carries the meaning. A notice whose
 * intent a script changes in place stays mounted, because its icon would have
 * to change with its state class.
 */
final class NoticeIcon {

	/**
	 * Path data per intent, with whether the path joins its strokes round.
	 *
	 * @var array<string,list<array{0:string,1:bool}>>
	 */
	public const PATHS = array(
		'info'    => array(
			array( 'M12 16V11M12 9.5V8M19.25 12C19.25 16.0041 16.0041 19.25 12 19.25C7.99594 19.25 4.75 16.0041 4.75 12C4.75 7.99594 7.99594 4.75 12 4.75C16.0041 4.75 19.25 7.99594 19.25 12Z', true ),
		),
		'warning' => array(
			array( 'M12 8L12 13M12 14.5L12 16M4.75 12C4.75 7.99593 7.99594 4.75 12 4.75C16.0041 4.75 19.25 7.99594 19.25 12C19.25 16.0041 16.0041 19.25 12 19.25C7.99594 19.25 4.75 16.0041 4.75 12Z', true ),
		),
		'success' => array(
			array( 'M12 19.25C16.0041 19.25 19.25 16.0041 19.25 12C19.25 7.99594 16.0041 4.75 12 4.75C7.99594 4.75 4.75 7.99594 4.75 12C4.75 16.0041 7.99594 19.25 12 19.25Z', true ),
			array( 'M9 12L11 14L15 10', false ),
		),
		'error'   => array(
			array( 'M12 9L12 14M12 15.5V17M11.1284 5.04947L3.83827 18.0097C3.46331 18.6763 3.94502 19.5 4.70985 19.5H19.2902C20.055 19.5 20.5367 18.6763 20.1617 18.0097L12.8716 5.04947C12.4893 4.36982 11.5107 4.36982 11.1284 5.04947Z', false ),
		),
	);

	/**
	 * Returns the icon markup for a Notice intent, or an empty string for
	 * neutral and unknown intents.
	 */
	public static function markup( string $intent ): string {
		if ( ! isset( self::PATHS[ $intent ] ) ) {
			return '';
		}
		$paths = '';
		foreach ( self::PATHS[ $intent ] as $path ) {
			$paths .= '<path d="' . esc_attr( $path[0] ) . '"' . ( $path[1] ? ' stroke-linejoin="round"' : '' ) . '></path>';
		}

		return '<svg class="ran-wp-ui-notice__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">' . $paths . '</svg>';
	}

	/**
	 * Prints the icon markup for a Notice intent.
	 */
	public static function print_intent( string $intent ): void {
		echo self::markup( $intent ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() escapes the path data; the rest is constant.
	}
}
