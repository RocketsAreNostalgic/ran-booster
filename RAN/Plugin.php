<?php

namespace RAN;

class Plugin extends AbstractPackage {

	protected $file;
	protected $name;
	protected $pluginURI;
	protected $version;
	protected $description;
	protected $author;
	protected $authorURI;
	protected $textDomain;
	protected $domainPath;
	protected $network;
	protected $title;
	protected $authorName;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public static function fromWpArray( $file, array $array ) {
		$plugin = new static();

		$plugin->file        = $file;
		$plugin->name        = $array['Name'];
		$plugin->pluginURI   = $array['PluginURI'];
		$plugin->version     = $array['Version'];
		$plugin->description = $array['Description'];
		$plugin->author      = $array['Author'];
		$plugin->authorURI   = $array['AuthorURI'];
		$plugin->textDomain  = $array['TextDomain'];
		$plugin->domainPath  = $array['DomainPath'];
		$plugin->network     = $array['Network'];
		$plugin->title       = $array['Title'];
		$plugin->authorName  = $array['AuthorName'];

		return $plugin;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public API names and dynamic getter contracts remain deferred to their connected caller cohort under #167.
	public function getIdentifier(): mixed {
		return $this->file;
	}

	protected function runtime_slug(): string {
		$identifier = trim( (string) $this->getIdentifier(), '/' );
		$directory  = dirname( $identifier );

		return '.' === $directory
			? (string) pathinfo( $identifier, PATHINFO_FILENAME )
			: basename( $directory );
	}
}
