<?php

namespace RAN;

use WP_Theme;

class Theme extends AbstractPackage {

	protected $stylesheet;
	protected $name;
	protected $theme_uri;
	protected $description;
	protected $author;
	protected $author_uri;
	protected $version;
	protected $template;
	protected $status;
	protected $tags;
	protected $text_domain;
	protected $domain_path;

	public static function from_wp_theme_object( WP_Theme $object ) {
		$theme = new static();

		$theme->stylesheet  = $object->get_stylesheet();
		$theme->name        = $object->get( 'Name' );
		$theme->theme_uri   = $object->get( 'ThemeURI' );
		$theme->description = $object->get( 'Description' );
		$theme->author      = $object->get( 'Author' );
		$theme->author_uri  = $object->get( 'AuthorURI' );
		$theme->version     = $object->get( 'Version' );
		$theme->template    = $object->get_template();
		$theme->status      = $object->get( 'Status' );
		$theme->tags        = $object->get( 'Tags' );
		$theme->text_domain = $object->get( 'TextDomain' );
		$theme->domain_path = $object->get( 'DomainPath' );

		return $theme;
	}

	public function get_identifier(): mixed {
		return $this->stylesheet;
	}

	protected function runtime_slug(): string {
		return (string) $this->get_identifier();
	}
}
