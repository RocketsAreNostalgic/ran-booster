<?php

declare(strict_types=1);

// A minimal WP_Theme: WordPress keeps the stylesheet spelling it was asked for, even when a case-insensitive filesystem resolved it to another folder.
if ( ! class_exists( 'WP_Theme' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Stand-in for WordPress's own WP_Theme class; its name cannot be prefixed.
	final class WP_Theme {

		public function __construct( private readonly string $stylesheet ) {
		}

		public function get_stylesheet(): string {
			return $this->stylesheet;
		}

		public function get_template(): string {
			return $this->stylesheet;
		}

		public function get( string $header ): string {
			return 'Name' === $header ? 'Example theme' : '';
		}

		public function exists(): bool {
			return true;
		}

		public function errors(): false {
			return false;
		}
	}
}
