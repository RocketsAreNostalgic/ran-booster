<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * The marker that ends a plain text link opening another site in a new tab.
 *
 * A decorative arrow for sighted users followed by the new-tab hint for
 * assistive technology. Print it as the last child of the anchor so the
 * accessible name reads "Label (opens in a new tab)" with no duplicate hint.
 */
final class ExternalLinkMarker {

	private const ARROW = '<span aria-hidden="true">↗</span>';

	/** The marker markup; the new-tab hint is escaped translated text. */
	public static function markup(): string {
		return self::ARROW . '<span class="screen-reader-text">' . esc_html__( ' (opens in a new tab)', 'ran-booster' ) . '</span>';
	}

	/**
	 * Give every new-tab text link in extension-supplied HTML exactly one marker.
	 *
	 * An anchor that already shows the arrow is left alone; one carrying only
	 * the new-tab hint gets the arrow before it; one with neither gets the full
	 * marker. Anchors without target="_blank" and anchors drawn as buttons are
	 * never touched. Idempotent, and an unclosed anchor is left as it was.
	 */
	public static function normalize_html( string $html ): string {
		if ( ! str_contains( $html, '<a' ) && ! str_contains( $html, '<A' ) ) {
			return $html;
		}

		$hint = '#<span\b[^>]*\bclass\s*=\s*["\'][^"\']*\bscreen-reader-text\b[^"\']*["\'][^>]*>\s*(?:\(opens in a new tab\)|'
			. preg_quote( trim( esc_html__( ' (opens in a new tab)', 'ran-booster' ) ), '#' )
			. ')\s*</span>#i';

		$normalized = preg_replace_callback(
			'#<a\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>((?:(?!<a\b|</a>).)*)</a>#is',
			static function ( array $found ) use ( $hint ): string {
				$attributes = $found[1];
				$inner      = $found[2];

				if (
					1 !== preg_match( '#(?<![\w-])target\s*=\s*(["\']?)_blank\1(?=[\s/]|$)#i', $attributes )
					|| 1 === preg_match( '#(?<![\w-])class\s*=\s*(?:"[^"]*|\'[^\']*)button#i', $attributes )
					|| str_contains( $inner, '↗' )
				) {
					return $found[0];
				}

				if ( 1 === preg_match( $hint, $inner, $hint_match, PREG_OFFSET_CAPTURE ) ) {
					$before = substr( $inner, 0, $hint_match[0][1] );
					$gap    = ( '' === $before || 1 === preg_match( '/\s\z/', $before ) ) ? '' : ' ';
					$inner  = $before . $gap . self::ARROW . substr( $inner, $hint_match[0][1] );
				} else {
					$inner .= ( 1 === preg_match( '/\s\z|\A\z/', $inner ) ? '' : ' ' ) . self::markup();
				}

				return '<a' . $attributes . '>' . $inner . '</a>';
			},
			$html
		);

		return is_string( $normalized ) ? $normalized : $html;
	}

	/** Print the marker markup. */
	public static function print(): void {
		echo self::markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Constant markup around an escaped translation.
	}
}
