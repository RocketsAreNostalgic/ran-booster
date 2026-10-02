<?php

namespace RAN;

class Plugin extends AbstractPackage {

	protected $file;
	protected $name;
	protected $plugin_uri;
	protected $version;
	protected $description;
	protected $author;
	protected $author_uri;
	protected $text_domain;
	protected $domain_path;
	protected $network;
	protected $title;
	protected $author_name;

	public static function from_wp_array( $file, array $array ) {
		$plugin = new static();

		$plugin->file        = $file;
		$plugin->name        = $array['Name'];
		$plugin->plugin_uri   = $array['PluginURI'];
		$plugin->version     = $array['Version'];
		$plugin->description = $array['Description'];
		$plugin->author      = $array['Author'];
		$plugin->author_uri   = $array['AuthorURI'];
		$plugin->text_domain  = $array['TextDomain'];
		$plugin->domain_path  = $array['DomainPath'];
		$plugin->network     = $array['Network'];
		$plugin->title       = $array['Title'];
		$plugin->author_name  = $array['AuthorName'];

		return $plugin;
	}

	public function get_identifier(): mixed {
		return $this->file;
	}

	protected function runtime_slug(): string {
		$identifier = trim( (string) $this->get_identifier(), '/' );
		$directory  = dirname( $identifier );

		return '.' === $directory
			? (string) pathinfo( $identifier, PATHINFO_FILENAME )
			: basename( $directory );
	}
}
