<?php

declare(strict_types=1);

namespace RAN;

/** @param callable|null $callback */
function add_menu_page( string $pageTitle, string $menuTitle, string $capability, string $menuSlug, ?callable $callback = null, string $iconUrl = '' ): void {
	$GLOBALS['ran_booster_pro_page_menus'][] = array(
		'page_title' => $pageTitle,
		'menu_title' => $menuTitle,
		'capability' => $capability,
		'menu_slug'  => $menuSlug,
		'callback'   => $callback,
		'icon_url'   => $iconUrl,
	);
}

/** @param callable|null $callback */
function add_submenu_page( string $parentSlug, string $pageTitle, string $menuTitle, string $capability, string $menuSlug, ?callable $callback = null ): void {
	$GLOBALS['ran_booster_pro_page_submenus'][] = array(
		'parent_slug' => $parentSlug,
		'page_title'  => $pageTitle,
		'menu_title'  => $menuTitle,
		'capability'  => $capability,
		'menu_slug'   => $menuSlug,
		'callback'    => $callback,
	);
}

function wp_kses_post( string $content ): string {
	return $content;
}
