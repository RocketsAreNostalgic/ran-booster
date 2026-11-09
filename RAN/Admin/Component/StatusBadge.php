<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * Renders one public Badge as static markup.
 *
 * Every status pill in the administration screens goes through here, so the
 * tone to intent mapping and the markup cannot drift between views.
 */
final class StatusBadge {

	/**
	 * Public Badge intents for the supported tones.
	 *
	 * @var array<string,string>
	 */
	public const INTENTS = array(
		'neutral' => 'none',
		'pending' => 'informational',
		'info'    => 'informational',
		'ok'      => 'stable',
		'success' => 'stable',
		'warning' => 'medium',
		'error'   => 'high',
	);

	/**
	 * Public Badge intents the stylesheet supports.
	 *
	 * @var list<string>
	 */
	public const SUPPORTED_INTENTS = array( 'none', 'stable', 'medium', 'high', 'informational' );

	/**
	 * Returns the Badge markup for a tone. Unknown tones render as neutral.
	 *
	 * @param array<string,string> $attributes Extra attributes: data-*, role, class or hidden.
	 */
	public static function markup( string $label, string $tone = 'neutral', array $attributes = array() ): string {
		if ( '' === $label ) {
			return '';
		}
		$tone = isset( self::INTENTS[ $tone ] ) ? $tone : 'neutral';

		return self::for_intent( $label, self::INTENTS[ $tone ], $attributes );
	}

	/**
	 * Returns the Badge markup for a public intent.
	 *
	 * The Badge is drawn with the public component's own static CSS and is not
	 * mounted as a React component, so it renders identically without JavaScript.
	 * Scripts that change a status in place call ranBoosterBadge.update().
	 *
	 * @param array<string,string> $attributes Extra attributes: data-*, role, class or hidden.
	 */
	public static function for_intent( string $label, string $intent, array $attributes = array() ): string {
		$intent  = in_array( $intent, self::SUPPORTED_INTENTS, true ) ? $intent : 'none';
		$classes = 'ran-wp-ui-text is-body-sm ran-wp-ui-badge is-' . $intent . '-intent';
		$extra   = '';
		foreach ( $attributes as $name => $value ) {
			if ( 'class' === $name ) {
				foreach ( preg_split( '/\s+/', $value ) ?: array() as $class ) {
					if ( 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $class ) ) {
						$classes .= ' ' . $class;
					}
				}
			} elseif ( 'hidden' === $name ) {
				$extra .= ' hidden';
			} elseif ( 'role' === $name && 'status' === $value ) {
				$extra .= ' role="status"';
			} elseif ( 1 === preg_match( '/^data-[a-z0-9-]+$/', $name ) ) {
				$extra .= ' ' . $name . ( '' === $value ? '' : '="' . esc_attr( $value ) . '"' );
			}
		}

		return '<span class="' . esc_attr( $classes ) . '" data-ran-booster-badge data-ran-booster-badge-intent="' . esc_attr( $intent ) . '"' . $extra . '>' . esc_html( $label ) . '</span>';
	}
}
