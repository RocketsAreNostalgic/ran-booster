<?php

declare(strict_types=1);

if ( ! defined( 'WPINC' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
	define( 'WPINC', 'test' );
}

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
	define( 'ABSPATH', '/tmp/ran-booster-tests/' );
}

if ( ! function_exists( 'esc_attr' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function esc_attr( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function esc_html( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function __( string $text, string $domain = 'default' ): string {
		return $GLOBALS['ran_booster_admin_test_translations'][ $domain ][ $text ]
			?? $GLOBALS['ran_booster_package_view_translations'][ $domain ][ $text ]
			?? $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function _x( string $text, string $context, string $domain = 'default' ): string {
		return $GLOBALS['ran_booster_admin_test_translations'][ $domain ][ $context . "\004" . $text ]
			?? $GLOBALS['ran_booster_package_view_translations'][ $domain ][ $context . "\004" . $text ]
			?? $text;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function add_query_arg( mixed $key, mixed $value = null, mixed $url = null ): string {
		if ( is_array( $key ) ) {
			$base = (string) $value;

			return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . http_build_query( $key );
		}

		$base = (string) $url;

		return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . http_build_query( array( (string) $key => $value ) );
	}
}

if ( ! function_exists( '_n' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		unset( $domain );

		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( '_nx' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function _nx( string $single, string $plural, int $number, string $context, string $domain = 'default' ): string {
		unset( $context, $domain );

		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'disabled' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string {
		$result = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
		if ( $display ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test shim returns a fixed HTML attribute.
			echo $result;
		}

		return $result;
	}
}

if ( ! function_exists( 'checked' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
		$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $display ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test shim returns a fixed HTML attribute.
			echo $result;
		}

		return $result;
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- Test shim forwards the supplied fixture text.
		echo esc_attr( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function esc_html__( string $text, string $domain = 'default' ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- Test shim forwards the supplied fixture text.
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function esc_html_e( string $text, string $domain = 'default' ): void {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain -- Test shim forwards the supplied fixture text.
		echo esc_html__( $text, $domain );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function esc_url( mixed $value ): string {
		return esc_attr( $value );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function plugins_url( string $path = '', string $plugin = '' ): string {
		unset( $plugin );

		return 'https://example.test/wp-content/plugins/ran-booster/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function is_wp_error( mixed $value ): bool {
		return $value instanceof \WP_Error;
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function is_plugin_active( string $plugin ): bool {
		return in_array( $plugin, $GLOBALS['ran_booster_bulk_active_plugins'] ?? array(), true );
	}
}

if ( ! function_exists( 'activate_plugin' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function activate_plugin( string $plugin, string $redirect = '' ): mixed {
		$GLOBALS['ran_booster_bulk_activation_redirects'][ $plugin ] = $redirect;
		$result = $GLOBALS['ran_booster_bulk_activation_results'][ $plugin ] ?? null;
		if ( $result instanceof \Throwable ) {
			throw $result;
		}
		if ( is_wp_error( $result ) ) {
			if ( in_array( $plugin, $GLOBALS['ran_booster_bulk_activation_errors_with_active_state'] ?? array(), true ) ) {
				$GLOBALS['ran_booster_bulk_active_plugins'][] = $plugin;
			}

			return $result;
		}

		$GLOBALS['ran_booster_bulk_active_plugins'][] = $plugin;
		$GLOBALS['ran_booster_bulk_active_plugins']   = array_values(
			array_unique( $GLOBALS['ran_booster_bulk_active_plugins'] )
		);

		return null;
	}
}

if ( ! function_exists( 'deactivate_plugins' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function deactivate_plugins( string|array $plugins, bool $silent = false, ?bool $network_wide = null ): void {
		unset( $silent, $network_wide );
		foreach ( (array) $plugins as $plugin ) {
			if ( in_array( $plugin, $GLOBALS['ran_booster_bulk_deactivation_failures'] ?? array(), true ) ) {
				continue;
			}
			$GLOBALS['ran_booster_bulk_active_plugins'] = array_values(
				array_diff( $GLOBALS['ran_booster_bulk_active_plugins'] ?? array(), array( $plugin ) )
			);
		}
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function wp_kses_post( mixed $value ): string {
		return (string) $value;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function wp_strip_all_tags( mixed $value, bool $remove_breaks = false ): string {
		unset( $remove_breaks );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Focused WordPress test shim.
		return strip_tags( (string) $value );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
		foreach ( $GLOBALS['ran_booster_admin_view_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$arguments );
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function do_action( string $hook, mixed ...$arguments ): void {
		foreach ( $GLOBALS['ran_booster_admin_view_actions'][ $hook ] ?? array() as $callback ) {
			$callback( ...$arguments );
		}
	}
}

if ( ! function_exists( 'has_action' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function has_action( string $hook ): bool {
		return array() !== ( $GLOBALS['ran_booster_admin_view_actions'][ $hook ] ?? array() );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function wp_json_encode( mixed $value ): string|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test shim implements WordPress JSON behavior.
		return json_encode( $value );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function wp_date( string $format ): string {
		unset( $format );

		return (string) ( $GLOBALS['ran_booster_admin_view_year'] ?? '2026' );
	}
}

if ( ! function_exists( 'get_file_data' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function get_file_data( string $file, array $default_headers, string $context = '' ): array {
		unset( $file, $default_headers, $context );

		return $GLOBALS['ran_booster_admin_view_plugin_headers'] ?? array(
			'author'     => 'Rockets Are Nostalgic',
			'author_uri' => 'https://github.com/RocketsAreNostalgic',
		);
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function is_multisite(): bool {
		return true === ( $GLOBALS['ran_booster_package_view_multisite'] ?? false );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'network_admin_url' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function network_admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/network/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function rest_url( string $path = '' ): string {
		return 'https://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function wp_parse_url( string $url ): array|false {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Test shim for the WordPress wrapper.
		return parse_url( $url );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Preserve the WordPress function parameter signature. WordPress function double must retain the host-owned name.
		global $ran_booster_storage_test_options;

		if ( array_key_exists( $option, $ran_booster_storage_test_options ?? array() ) ) {
			return $ran_booster_storage_test_options[ $option ];
		}
		if ( \RAN\Storage\Database::VERSION_OPTION === $option
			&& ! ( $GLOBALS['ran_booster_storage_test_schema_unset'] ?? false ) ) {
			return \RAN\Storage\Database::$booster_db_version;
		}

		return $default;
	}
}

if ( ! function_exists( 'settings_errors' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function settings_errors(): void {
	}
}

if ( ! function_exists( 'settings_fields' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function settings_fields( string $group ): void {
		unset( $group );
	}
}

if ( ! function_exists( 'do_settings_sections' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function do_settings_sections( string $page ): void {
		unset( $page );
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function submit_button( string $text ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function wp_nonce_field( string $action ): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">';
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'nonce-for-' . $action;
	}
}

if ( ! function_exists( 'selected' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $display ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test shim emits one fixed attribute literal or an empty string.
			echo $result;
		}

		return $result;
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress function double must retain the host-owned name.
	function esc_textarea( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
