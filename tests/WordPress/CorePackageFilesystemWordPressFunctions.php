<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Isolated WordPress error identity with its optional message constructor argument.
final class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Isolated host fixture supplies WordPress's path helper alongside its error identity.
function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}
