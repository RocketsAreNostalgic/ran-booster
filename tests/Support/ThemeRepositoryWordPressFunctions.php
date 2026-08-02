<?php

declare(strict_types=1);

namespace RAN\Storage;

if ( ! function_exists( __NAMESPACE__ . '\\wp_get_theme' ) ) {
	function wp_get_theme( string $stylesheet ): object {
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
