<?php

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

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

	public static function from_wp_array( $file, array $plugin_data ) {
		$plugin = new static();

		$plugin->file        = $file;
		$plugin->name        = $plugin_data['Name'];
		$plugin->plugin_uri  = $plugin_data['PluginURI'];
		$plugin->version     = $plugin_data['Version'];
		$plugin->description = $plugin_data['Description'];
		$plugin->author      = $plugin_data['Author'];
		$plugin->author_uri  = $plugin_data['AuthorURI'];
		$plugin->text_domain = $plugin_data['TextDomain'];
		$plugin->domain_path = $plugin_data['DomainPath'];
		$plugin->network     = $plugin_data['Network'];
		$plugin->title       = $plugin_data['Title'];
		$plugin->author_name = $plugin_data['AuthorName'];

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
