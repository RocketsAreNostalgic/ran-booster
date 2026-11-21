<?php

declare(strict_types=1);

namespace RAN\Storage;

require_once __DIR__ . '/WpThemeStub.php';

if ( ! function_exists( __NAMESPACE__ . '\\wp_get_themes' ) ) {
	/** @return array<string, \WP_Theme> The installed themes by their folder's own spelling. */
	function wp_get_themes(): array {
		return $GLOBALS['ran_booster_theme_repository_test_installed'] ?? array();
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_get_theme' ) ) {
	function wp_get_theme( string $stylesheet ): object {
		$GLOBALS['ran_booster_theme_repository_test_reads'][] = $stylesheet;
		// A case-insensitive filesystem resolves another spelling of an installed folder but keeps the spelling asked for.
		foreach ( $GLOBALS['ran_booster_theme_repository_test_installed'] ?? array() as $folder => $unused ) {
			if ( ! isset( $GLOBALS['ran_booster_theme_repository_test_themes'][ $stylesheet ] ) && 0 === strcasecmp( (string) $folder, $stylesheet ) ) {
				return new \WP_Theme( $stylesheet );
			}
		}
		return $GLOBALS['ran_booster_theme_repository_test_themes'][ $stylesheet ]
			?? new class() {
				public function exists(): bool {
					return false;
				}

				public function errors(): false {
					return false;
				}
			};
	}
}
