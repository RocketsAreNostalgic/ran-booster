<?php

declare(strict_types=1);

namespace RAN\Storage;

if ( ! function_exists( __NAMESPACE__ . '\\get_plugins' ) ) {
	/** @return array<string, array{Name:string,PluginURI:string,Version:string,Description:string,Author:string,AuthorURI:string,TextDomain:string,DomainPath:string,Network:bool,Title:string,AuthorName:string}> */
	function get_plugins(): array {
		return $GLOBALS['ran_booster_plugin_repository_test_plugins'] ?? array();
	}
}
