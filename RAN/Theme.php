<?php

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use WP_Theme;

class Theme extends AbstractPackage {

	/** @var string|null */
	protected $stylesheet;
	/** @var string|false|null */
	protected $name;
	/** @var string|false|null */
	protected $theme_uri;
	/** @var string|false|null */
	protected $description;
	/** @var string|false|null */
	protected $author;
	/** @var string|false|null */
	protected $author_uri;
	/** @var string|false|null */
	protected $version;
	/** @var string|null */
	protected $template;
	/** @var string|false|null */
	protected $status;
	/** @var array<array-key, string>|false|null */
	protected $tags;
	/** @var string|false|null */
	protected $text_domain;
	/** @var string|false|null */
	protected $domain_path;

	/** @return static */
	public static function from_wp_theme_object( WP_Theme $wp_theme ) {
		$theme = new static();

		$theme->stylesheet  = $wp_theme->get_stylesheet();
		$theme->name        = $wp_theme->get( 'Name' );
		$theme->theme_uri   = $wp_theme->get( 'ThemeURI' );
		$theme->description = $wp_theme->get( 'Description' );
		$theme->author      = $wp_theme->get( 'Author' );
		$theme->author_uri  = $wp_theme->get( 'AuthorURI' );
		$theme->version     = $wp_theme->get( 'Version' );
		$theme->template    = $wp_theme->get_template();
		$theme->status      = $wp_theme->get( 'Status' );
		$theme->tags        = $wp_theme->get( 'Tags' );
		$theme->text_domain = $wp_theme->get( 'TextDomain' );
		$theme->domain_path = $wp_theme->get( 'DomainPath' );

		return $theme;
	}

	public function get_identifier(): mixed {
		return $this->stylesheet;
	}

	protected function runtime_slug(): string {
		return (string) $this->get_identifier();
	}
}
