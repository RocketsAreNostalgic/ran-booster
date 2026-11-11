<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * Prints WordPress's nonce field without its duplicate-prone element id.
 *
 * wp_nonce_field() gives its hidden input an id equal to the field name, so a
 * screen with several forms repeats id="_wpnonce", which is invalid HTML. The
 * field name, value, action and the referer field are unchanged, so
 * check_admin_referer() and form serialisation read the same request keys.
 */
final class NonceField {

	/**
	 * Prints the nonce field and referer field for an action, without an id.
	 *
	 * @param string $action Nonce action.
	 */
	public static function print( string $action ): void {
		ob_start();
		wp_nonce_field( $action );
		$field = (string) ob_get_clean();

		echo str_replace( ' id="_wpnonce"', '', $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup produced and escaped by wp_nonce_field().
	}
}
