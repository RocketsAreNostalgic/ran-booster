<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress API stub for the isolated supplier notice test.
function __( string $text, string $domain = 'default' ): string {
	unset( $domain );
	return $text;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress API stub for the isolated supplier notice test.
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress API stub for the isolated supplier notice test.
function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress API stub for the isolated supplier notice test.
function current_user_can( string $capability ): bool {
	return in_array( $capability, $GLOBALS['ran_booster_supplier_caps'] ?? array(), true );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress API stub for the isolated supplier notice test.
function get_current_screen(): object {
	return (object) array( 'id' => $GLOBALS['ran_booster_supplier_screen'] ?? 'plugins' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress API stub for the isolated supplier notice test.
function get_option( string $name, mixed $fallback = false ): mixed {
	return $GLOBALS['ran_booster_supplier_options'][ $name ] ?? $fallback;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress API stub for the isolated supplier notice test.
function update_option( string $name, mixed $value, ?bool $autoload = null ): bool {
	$GLOBALS['ran_booster_supplier_options'][ $name ]  = $value;
	$GLOBALS['ran_booster_supplier_autoload'][ $name ] = $autoload;
	return true;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress API stub for the isolated supplier notice test.
function delete_option( string $name ): bool {
	unset( $GLOBALS['ran_booster_supplier_options'][ $name ] );
	return true;
}

/** @return array<string, array<string, string>> */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress API stub for the isolated supplier notice test.
function get_plugins(): array {
	return array( 'alpha/alpha.php' => array( 'Name' => 'Alpha Provider' ) );
}
