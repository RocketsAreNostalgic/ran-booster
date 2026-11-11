<?php

declare(strict_types=1);

// Loaded only by NonceFieldTest, in its own process, so it cannot shadow the shared view doubles.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function wp_nonce_field( string $action ): void {
	// Same markup as WordPress core: the nonce input id repeats its name.
	echo '<input type="hidden" id="_wpnonce" name="_wpnonce" value="nonce-for-' . esc_attr( $action ) . '" /><input type="hidden" name="_wp_http_referer" value="/wp-admin/admin.php" />';
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES );
}
