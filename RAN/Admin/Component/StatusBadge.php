<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * Renders one public Badge host with its native fallback.
 *
 * Every status pill in the administration screens goes through here, so the
 * tone to intent mapping and the fallback markup cannot drift between views.
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
	 * Returns the Badge markup. Unknown tones render as neutral.
	 *
	 * @param array<string,string> $attributes Extra host attributes, such as data-* flags.
	 */
	public static function markup( string $label, string $tone = 'neutral', array $attributes = array() ): string {
		if ( '' === $label ) {
			return '';
		}
		$tone  = isset( self::INTENTS[ $tone ] ) ? $tone : 'neutral';
		$extra = '';
		foreach ( $attributes as $name => $value ) {
			if ( 1 === preg_match( '/^data-[a-z0-9-]+$/', $name ) ) {
				$extra .= ' ' . $name . ( '' === $value ? '' : '="' . esc_attr( $value ) . '"' );
			}
		}

		return '<span data-ran-booster-ui-badge data-ran-booster-ui-badge-label="' . esc_attr( $label ) . '" data-ran-booster-ui-badge-intent="' . esc_attr( self::INTENTS[ $tone ] ) . '"' . $extra . '>'
			. '<span data-ran-booster-ui-badge-fallback class="ran-booster-badge ran-booster-badge--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>'
			. '<span data-ran-booster-ui-badge-mount></span></span>';
	}
}
