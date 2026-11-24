<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Throwable;

final class WordPressOrgUpdateRequestFilter {

	public function __construct(
		private Database $database,
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private string $booster_plugin
	) {
	}

	public function plugins( mixed $args, mixed $url ): mixed {
		return $this->filter( $args, $url, 'plugin' );
	}

	public function themes( mixed $args, mixed $url ): mixed {
		return $this->filter( $args, $url, 'theme' );
	}

	private function filter( mixed $args, mixed $url, string $type ): mixed {
		$key = $type . 's';
		if ( ! is_string( $url )
			|| ! $this->is_update_endpoint( $url, $key )
			|| ! is_array( $args )
			|| ! is_array( $args['body'] ?? null )
			|| ! is_string( $args['body'][ $key ] ?? null ) ) {
			return $args;
		}

		$payload = json_decode( $args['body'][ $key ], true );
		if ( ! is_array( $payload )
			|| ! is_array( $payload[ $key ] ?? null )
			|| ( 'plugin' === $type && ! is_array( $payload['active'] ?? null ) )
			|| ( 'theme' === $type && isset( $payload['active'] ) && ! is_string( $payload['active'] ) ) ) {
			return $args;
		}

		$managed = array();
		try {
			if ( $this->database->is_supported() ) {
				$managed = array_keys(
					'plugin' === $type
						? $this->plugins->all_booster_plugins()
						: $this->themes->all_booster_themes()
				);
			}
		} catch ( Throwable ) {
			$managed = array();
		}

		$removed = false;
		if ( 'plugin' === $type ) {
			$managed[]      = $this->booster_plugin;
			$active_removed = false;
			foreach ( $managed as $package ) {
				if ( array_key_exists( $package, $payload['plugins'] ) ) {
					unset( $payload['plugins'][ $package ] );
					$removed = true;
				}
				foreach ( array_keys( $payload['active'], $package, true ) as $active ) {
					unset( $payload['active'][ $active ] );
					$active_removed = true;
					$removed        = true;
				}
			}
			if ( $active_removed ) {
				$payload['active'] = array_values( $payload['active'] );
			}
		} else {
			foreach ( $managed as $package ) {
				if ( array_key_exists( $package, $payload['themes'] ) ) {
					unset( $payload['themes'][ $package ] );
					$removed = true;
				}
			}
			if ( isset( $payload['active'] ) && in_array( $payload['active'], $managed, true ) ) {
				unset( $payload['active'] );
				$removed = true;
			}
		}

		if ( ! $removed ) {
			return $args;
		}

		if ( array() === $payload[ $key ] ) {
			$payload[ $key ] = new \stdClass();
		}

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) ) {
			return $args;
		}
		$args['body'][ $key ] = $encoded;

		return $args;
	}

	private function is_update_endpoint( string $url, string $key ): bool {
		$parts = wp_parse_url( $url );
		if ( false === $parts
			|| ! isset( $parts['scheme'], $parts['host'], $parts['path'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['port'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] ) ) {
			return false;
		}

		return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			&& 'api.wordpress.org' === strtolower( $parts['host'] )
			&& "/{$key}/update-check/1.1/" === $parts['path'];
	}
}
