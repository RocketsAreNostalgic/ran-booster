<?php

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

class Plugin extends AbstractPackage {

	/** @var string|null */
	protected $file;
	/** @var string|null */
	protected $name;
	/** @var string|null */
	protected $plugin_uri;
	/** @var string|null */
	protected $version;
	/** @var string|null */
	protected $description;
	/** @var string|null */
	protected $author;
	/** @var string|null */
	protected $author_uri;
	/** @var string|null */
	protected $text_domain;
	/** @var string|null */
	protected $domain_path;
	/** @var bool|null */
	protected $network;
	/** @var string|null */
	protected $title;
	/** @var string|null */
	protected $author_name;

	/**
	 * @param string $file WordPress plugin identifier.
	 * @param array{Name:string,PluginURI:string,Version:string,Description:string,Author:string,AuthorURI:string,TextDomain:string,DomainPath:string,Network:bool,Title:string,AuthorName:string} $plugin_data Headers returned by WordPress.
	 * @return static
	 */
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
