<?php

namespace RAN;

use WP_Theme;

class Theme extends AbstractPackage {

	protected $stylesheet;
	protected $name;
	protected $themeURI;
	protected $description;
	protected $author;
	protected $authorURI;
	protected $version;
	protected $template;
	protected $status;
	protected $tags;
	protected $textDomain;
	protected $domainPath;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public static function fromWpThemeObject( WP_Theme $object ) {
		$theme = new static();

		$theme->stylesheet  = $object->get_stylesheet();
		$theme->name        = $object->get( 'Name' );
		$theme->themeURI    = $object->get( 'ThemeURI' );
		$theme->description = $object->get( 'Description' );
		$theme->author      = $object->get( 'Author' );
		$theme->authorURI   = $object->get( 'AuthorURI' );
		$theme->version     = $object->get( 'Version' );
		$theme->template    = $object->get_template();
		$theme->status      = $object->get( 'Status' );
		$theme->tags        = $object->get( 'Tags' );
		$theme->textDomain  = $object->get( 'TextDomain' );
		$theme->domainPath  = $object->get( 'DomainPath' );

		return $theme;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getIdentifier(): mixed {
		return $this->stylesheet;
	}

	protected function runtime_slug(): string {
		return (string) $this->getIdentifier();
	}
}
