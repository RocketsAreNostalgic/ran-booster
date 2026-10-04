<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

if ( ! defined( 'DOING_CRON' ) ) {
	define( 'DOING_CRON', true );
}

if ( PHP_VERSION_ID < 80200 || version_compare( get_bloginfo( 'version' ), '7.0', '<' ) ) {
	throw new RuntimeException( 'The WordPress-core updater proof requires PHP 8.2 and WordPress 7.0 or newer.' );
}
if ( is_multisite() ) {
	throw new RuntimeException( 'The WordPress-core updater proof requires a single-site installation.' );
}
if ( ! wp_doing_cron() ) {
	throw new RuntimeException( 'The WordPress-core updater proof requires background-update cron semantics.' );
}
if ( ! class_exists( ZipArchive::class ) ) {
	throw new RuntimeException( 'The WordPress-core updater proof requires ZipArchive.' );
}

final class RanBoosterCoreUpdaterProof {
	private array $archives = array();
	private array $plugins  = array();
	private array $themes   = array();
	private string $original_stylesheet;

	public function __construct( private readonly string $run_id ) {
		if ( preg_match( '/^[a-f0-9]{12}$/D', $run_id ) !== 1 ) {
			throw new RuntimeException( 'The proof run identity is invalid.' );
		}

		$this->original_stylesheet = (string) get_option( 'stylesheet' );

		clearstatcache( true, ABSPATH . '.maintenance' );
		if ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) ) {
			throw new RuntimeException( 'The proof refuses to replace a pre-existing maintenance marker.' );
		}
	}

	public function run(): void {
		$unrelated_slug       = $this->slug( 'unrelated-plugin' );
		$unrelated_identifier = $this->plugin_identifier( $unrelated_slug );
		$unrelated_archive    = $this->archive( 'plugin', $unrelated_slug, '9.0.0', 'unrelated-original' );
		$this->install_plugin( $unrelated_slug, $unrelated_archive );

		$plugin_slug       = $this->slug( 'plugin' );
		$plugin_identifier = $this->plugin_identifier( $plugin_slug );
		$this->install_plugin( $plugin_slug, $this->archive( 'plugin', $plugin_slug, '1.0.0', 'plugin-install' ) );
		$this->assert_plugin( $plugin_identifier, '1.0.0', 'plugin-install', false );

		$this->update_plugin(
			$plugin_slug,
			$this->archive( 'plugin', $plugin_slug, '2.0.0', 'plugin-inactive-update' ),
			'2.0.0',
			$unrelated_identifier,
			$unrelated_archive
		);
		$this->assert_plugin( $plugin_identifier, '2.0.0', 'plugin-inactive-update', false );
		$this->assert_plugin( $unrelated_identifier, '9.0.0', 'unrelated-original', false );

		$this->activate_plugin( $plugin_identifier );
		$this->update_plugin(
			$plugin_slug,
			$this->archive( 'plugin', $plugin_slug, '3.0.0', 'plugin-active-update' ),
			'3.0.0',
			$unrelated_identifier,
			$unrelated_archive
		);
		$this->assert_plugin( $plugin_identifier, '3.0.0', 'plugin-active-update', true );

		$this->update_plugin(
			$plugin_slug,
			$this->archive( 'plugin', $plugin_slug, '3.0.0', 'plugin-same-version-new-bytes' ),
			'3.0.0',
			$unrelated_identifier,
			$unrelated_archive
		);
		$this->assert_plugin( $plugin_identifier, '3.0.0', 'plugin-same-version-new-bytes', true );

		$this->update_plugin(
			$plugin_slug,
			$this->archive( 'plugin', $plugin_slug, '1.5.0', 'plugin-downgrade' ),
			'1.5.0',
			$unrelated_identifier,
			$unrelated_archive
		);
		$this->assert_plugin( $plugin_identifier, '1.5.0', 'plugin-downgrade', true );

		$this->update_plugin(
			$plugin_slug,
			$this->archive( 'plugin', $plugin_slug, '4.0.0', 'plugin-fatal-update' ),
			'4.0.0',
			$unrelated_identifier,
			$unrelated_archive,
			true
		);
		$this->assert_plugin( $plugin_identifier, '1.5.0', 'plugin-downgrade', true );
		$this->assert_backup_absent( 'plugins', $plugin_slug );

		$theme_slug = $this->slug( 'theme' );
		$this->install_theme( $theme_slug, $this->archive( 'theme', $theme_slug, '1.0.0', 'theme-install' ) );
		$this->assert_theme( $theme_slug, '1.0.0', 'theme-install', false );

		$this->update_theme( $theme_slug, $this->archive( 'theme', $theme_slug, '2.0.0', 'theme-inactive-update' ), '2.0.0' );
		$this->assert_theme( $theme_slug, '2.0.0', 'theme-inactive-update', false );

		switch_theme( $theme_slug );
		$this->update_theme( $theme_slug, $this->archive( 'theme', $theme_slug, '3.0.0', 'theme-active-update' ), '3.0.0' );
		$this->assert_theme( $theme_slug, '3.0.0', 'theme-active-update', true );

		$this->assert_maintenance_absent();
	}

	public function cleanup(): void {
		if ( (string) get_option( 'stylesheet' ) !== $this->original_stylesheet ) {
			switch_theme( $this->original_stylesheet );
		}

		foreach ( array_reverse( $this->plugins ) as $identifier ) {
			if ( is_plugin_active( $identifier ) ) {
				deactivate_plugins( $identifier, true );
			}
			$directory = WP_PLUGIN_DIR . '/' . dirname( $identifier );
			if ( is_dir( $directory ) && ! is_link( $directory ) ) {
				$result = delete_plugins( array( $identifier ) );
				if ( is_wp_error( $result ) || false === $result || is_dir( $directory ) ) {
					throw new RuntimeException( 'A disposable proof plugin could not be removed.' );
				}
			}
		}

		foreach ( array_reverse( $this->themes ) as $stylesheet ) {
			$directory = get_theme_root( $stylesheet ) . '/' . $stylesheet;
			if ( is_dir( $directory ) && ! is_link( $directory ) ) {
				$result = delete_theme( $stylesheet );
				if ( is_wp_error( $result ) || false === $result || is_dir( $directory ) ) {
					throw new RuntimeException( 'A disposable proof theme could not be removed.' );
				}
			}
		}

		foreach ( $this->archives as $archive ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
			if ( is_file( $archive ) && ! unlink( $archive ) ) {
				throw new RuntimeException( 'A disposable proof archive could not be removed.' );
			}
		}
	}

	private function install_plugin( string $slug, string $archive ): void {
		$identifier = $this->plugin_identifier( $slug );
		$this->assert_destination_absent( WP_PLUGIN_DIR . '/' . $slug );
		$result = $this->with_source_selection(
			$slug,
			static fn () => ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->install( $archive )
		);
		if ( true !== $result ) {
			throw new RuntimeException( 'WordPress core did not install the disposable proof plugin.' );
		}
		$this->plugins[] = $identifier;
	}

	private function install_theme( string $slug, string $archive ): void {
		$this->assert_destination_absent( get_theme_root() . '/' . $slug );
		$result = $this->with_source_selection(
			$slug,
			static fn () => ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->install( $archive )
		);
		if ( true !== $result ) {
			throw new RuntimeException( 'WordPress core did not install the disposable proof theme.' );
		}
		$this->themes[] = $slug;
	}

	private function update_plugin(
		string $slug,
		string $archive,
		string $version,
		string $unrelated_identifier,
		string $unrelated_archive,
		bool $simulated_fatal_scrape = false
	): void {
		$identifier = $this->plugin_identifier( $slug );
		$offer      = (object) array(
			'id'           => 'https://proof.invalid/' . $slug,
			'slug'         => $slug,
			'plugin'       => $identifier,
			'new_version'  => $version,
			'package'      => $archive,
			'autoupdate'   => true,
			'requires_php' => '8.2',
		);

		$this->update_one(
			'plugin',
			$slug,
			$offer,
			'pre_site_transient_update_plugins',
			static function ( mixed $transient ) use ( $identifier, $offer, $unrelated_identifier, $unrelated_archive ): object {
				if ( ! is_object( $transient ) ) {
					$transient = new stdClass();
				}
				if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
					$transient->response = array();
				}
				$transient->response[ $identifier ]           = $offer;
				$transient->response[ $unrelated_identifier ] = (object) array(
					'slug'        => dirname( $unrelated_identifier ),
					'plugin'      => $unrelated_identifier,
					'new_version' => '10.0.0',
					'package'     => $unrelated_archive,
					'autoupdate'  => true,
				);

				return $transient;
			},
			$identifier,
			$simulated_fatal_scrape
		);
	}

	private function update_theme( string $slug, string $archive, string $version ): void {
		$offer = (object) array(
			'id'           => 'https://proof.invalid/' . $slug,
			'theme'        => $slug,
			'new_version'  => $version,
			'package'      => $archive,
			'autoupdate'   => true,
			'requires_php' => '8.2',
		);

		$this->update_one(
			'theme',
			$slug,
			$offer,
			'pre_site_transient_update_themes',
			static function ( mixed $transient ) use ( $slug, $archive, $version ): object {
				if ( ! is_object( $transient ) ) {
					$transient = new stdClass();
				}
				if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
					$transient->response = array();
				}
				$transient->response[ $slug ] = array(
					'theme'       => $slug,
					'new_version' => $version,
					'package'     => $archive,
				);

				return $transient;
			},
			$slug,
			false
		);
	}

	private function update_one(
		string $type,
		string $slug,
		object $offer,
		string $transient_hook,
		Closure $transient_filter,
		string $expected_identifier,
		bool $simulated_fatal_scrape
	): void {
		$source_filter = $this->source_selection_filter( $slug );
		$archive_path  = (string) ( $offer->package ?? '' );
		$vcs_filter    = $this->vcs_filter( $type, $expected_identifier );
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
		$pre_download    = static function ( mixed $reply, mixed $package, mixed $upgrader, array $extra ) use ( $archive_path, $type, $expected_identifier ): mixed {
			$operation_identifier = 'plugin' === $type ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null );
			if ( is_string( $package )
				&& hash_equals( $archive_path, $package )
				&& ( $extra['type'] ?? null ) === $type
				&& 'update' === ( $extra['action'] ?? null )
				&& $expected_identifier === $operation_identifier
			) {
				return $archive_path;
			}

			return $reply;
		};
		$scrape_response = $this->simulated_scrape_response_filter( $simulated_fatal_scrape );
		$completions     = array();
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
		$complete                 = static function ( object $upgrader, array $extra ) use ( &$completions ): void {
			$completions[] = $extra;
		};
		$automatic_complete_calls = 0;
		$automatic_complete       = static function () use ( &$automatic_complete_calls ): void {
			++$automatic_complete_calls;
		};
		$target_context           = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root( $expected_identifier );
		if ( false !== $vcs_filter( true, $target_context ) || true !== $vcs_filter( true, ABSPATH ) ) {
			throw new RuntimeException( 'The disposable VCS exception is not limited to the target package context.' );
		}

		add_filter( $transient_hook, $transient_filter, 10, 1 );
		add_filter( 'upgrader_pre_download', $pre_download, 10, 4 );
		add_filter( 'upgrader_source_selection', $source_filter, 10, 3 );
		add_filter( 'automatic_updates_is_vcs_checkout', $vcs_filter, 10, 2 );
		add_filter( 'pre_http_request', $scrape_response, 10, 3 );
		add_action( 'upgrader_process_complete', $complete, 100, 2 );
		add_action( 'automatic_updates_complete', $automatic_complete, 100, 1 );

		try {
			$result = ( new WP_Automatic_Updater() )->update( $type, $offer );
		} finally {
			remove_filter( $transient_hook, $transient_filter, 10 );
			remove_filter( 'upgrader_pre_download', $pre_download, 10 );
			remove_filter( 'upgrader_source_selection', $source_filter, 10 );
			remove_filter( 'automatic_updates_is_vcs_checkout', $vcs_filter, 10 );
			remove_filter( 'pre_http_request', $scrape_response, 10 );
			remove_action( 'upgrader_process_complete', $complete, 100 );
			remove_action( 'automatic_updates_complete', $automatic_complete, 100 );
		}

		if ( $simulated_fatal_scrape ) {
			if ( ! is_wp_error( $result ) || 'plugin_update_fatal_error_rollback_successful' !== $result->get_error_code() ) {
				$code = is_wp_error( $result ) ? $result->get_error_code() : get_debug_type( $result );
				// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
				throw new RuntimeException( 'WordPress core did not report restoration after the simulated fatal-scrape response: ' . $code );
			}
		} elseif ( true !== $result ) {
			$code = is_wp_error( $result ) ? $result->get_error_code() : get_debug_type( $result );
			// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
			throw new RuntimeException( 'The direct WordPress automatic update failed: ' . $code );
		}
		if ( 1 !== count( $completions ) ) {
			throw new RuntimeException( 'The direct update did not complete exactly one package operation.' );
		}
		$extra = $completions[0];
		if ( ( $extra['type'] ?? null ) !== $type || 'update' !== ( $extra['action'] ?? null ) ) {
			throw new RuntimeException( 'The direct update completed an unexpected package operation.' );
		}
		$completed_identifier = 'plugin' === $type ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null );
		if ( $expected_identifier !== $completed_identifier ) {
			throw new RuntimeException( 'The direct update completed an unrelated package.' );
		}
		if ( 0 !== $automatic_complete_calls ) {
			throw new RuntimeException( 'The proof invoked the automatic updater sweep rather than one update.' );
		}
		foreach (
			array(
				$transient_hook                     => $transient_filter,
				'upgrader_pre_download'             => $pre_download,
				'upgrader_source_selection'         => $source_filter,
				'automatic_updates_is_vcs_checkout' => $vcs_filter,
				'pre_http_request'                  => $scrape_response,
				'upgrader_process_complete'         => $complete,
				'automatic_updates_complete'        => $automatic_complete,
			) as $hook => $callback
		) {
			if ( false !== has_filter( $hook, $callback ) ) {
				throw new RuntimeException( 'The proof left a scoped WordPress hook installed.' );
			}
		}
	}

	private function vcs_filter( string $type, string $identifier ): Closure {
		$allowed_context = WP_PLUGIN_DIR;
		if ( 'theme' === $type ) {
			$theme_root = realpath( get_theme_root( $identifier ) );
			if ( false === $theme_root || ! is_dir( $theme_root ) ) {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
				return static fn ( bool $checkout, string $context ): bool => $checkout;
			}
			$allowed_context = $theme_root;
		}

		return static function ( bool $checkout, string $context ) use ( $type, $allowed_context ): bool {
			if ( 'plugin' === $type ) {
				return WP_PLUGIN_DIR === $context ? false : $checkout;
			}
			$canonical_context = realpath( $context );

			return false !== $canonical_context && hash_equals( $allowed_context, $canonical_context ) ? false : $checkout;
		};
	}

	private function with_source_selection( string $slug, callable $operation ): mixed {
		$source_filter = $this->source_selection_filter( $slug );
		add_filter( 'upgrader_source_selection', $source_filter, 10, 3 );
		try {
			return $operation();
		} finally {
			remove_filter( 'upgrader_source_selection', $source_filter, 10 );
			if ( false !== has_filter( 'upgrader_source_selection', $source_filter ) ) {
				throw new RuntimeException( 'The proof left its install source filter installed.' );
			}
		}
	}

	private function source_selection_filter( string $slug ): Closure {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
		return static function ( mixed $source, mixed $remote_source, mixed $upgrader ) use ( $slug ): mixed {
			if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable source path is invalid.' );
			}
			$source_root = realpath( $source );
			$remote_root = realpath( $remote_source );
			if ( false === $source_root || false === $remote_root || ! is_dir( $source_root ) || ! is_dir( $remote_root ) ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable source path is unavailable.' );
			}
			$prefix = trailingslashit( $remote_root );
			if ( ! str_starts_with( trailingslashit( $source_root ), $prefix ) || $source_root === $remote_root ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable source escaped its extraction root.' );
			}
			$destination = $remote_root . DIRECTORY_SEPARATOR . $slug;
			if ( file_exists( $destination ) || is_link( $destination ) ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable destination already exists.' );
			}

			global $wp_filesystem;
			if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->move( $source_root, $destination, false ) ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable source could not be selected.' );
			}

			return trailingslashit( $destination );
		};
	}

	private function simulated_scrape_response_filter( bool $fatal ): Closure {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Preserve the WordPress callback argument positions; the fixture uses only the arguments needed for its controlled result.
		return static function ( mixed $preempt, array $arguments, string $url ) use ( $fatal ): mixed {
			$query = wp_parse_url( $url, PHP_URL_QUERY );
			if ( ! is_string( $query ) ) {
				return $preempt;
			}
			parse_str( $query, $parameters );
			$key = $parameters['wp_scrape_key'] ?? null;
			if ( ! is_string( $key ) || preg_match( '/^[a-f0-9]{32}$/D', $key ) !== 1 ) {
				return $preempt;
			}
			$start = '###### wp_scraping_result_start:' . $key . ' ######';
			$end   = '###### wp_scraping_result_end:' . $key . ' ######';

			return array(
				'headers'  => array(),
				'body'     => $start . ( $fatal ? '{"type":1}' : '{}' ) . $end,
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
	}

	private function archive( string $kind, string $slug, string $version, string $marker ): string {
		$path = wp_tempnam( 'ran-booster-core-proof-' . $this->run_id . '.zip' );
		if ( ! is_string( $path ) || '' === $path ) {
			throw new RuntimeException( 'A disposable proof archive could not be created.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'A disposable proof archive could not be opened.' );
		}
		$root = 'repository-' . preg_replace( '/[^a-z0-9-]/', '-', $marker );
		if ( 'plugin' === $kind ) {
			$header = "<?php\n/*\nPlugin Name: RAN Booster core updater proof {$slug}\nVersion: {$version}\nRequires at least: 7.0\nRequires PHP: 8.2\n*/\n";
			$zip->addFromString( $root . '/' . $slug . '.php', $header );
		} else {
			$header = "/*\nTheme Name: RAN Booster core updater proof {$slug}\nVersion: {$version}\nRequires at least: 7.0\nRequires PHP: 8.2\n*/\n";
			$zip->addFromString( $root . '/style.css', $header );
			$zip->addFromString( $root . '/index.php', "<?php\n" );
		}
		$zip->addFromString( $root . '/ran-booster-proof.txt', $marker . "\n" );
		if ( ! $zip->close() ) {
			throw new RuntimeException( 'A disposable proof archive could not be finalized.' );
		}
		$this->archives[] = $path;

		return $path;
	}

	private function assert_plugin( string $identifier, string $version, string $marker, bool $active ): void {
		wp_clean_plugins_cache( false );
		$data             = get_plugin_data( WP_PLUGIN_DIR . '/' . $identifier, false, false );
		$observed_version = $data['Version'] ?? null;
		$observed_active  = is_plugin_active( $identifier );
		if ( $version !== $observed_version || $active !== $observed_active ) {
			throw new RuntimeException(
				'The disposable proof plugin state is incorrect: expected version '
				// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
				. $version . ' and active=' . ( $active ? 'yes' : 'no' )
				// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
				. ', observed version ' . ( is_string( $observed_version ) ? $observed_version : 'unavailable' )
				. ' and active=' . ( $observed_active ? 'yes' : 'no' ) . '.'
			);
		}
		$this->assert_marker( WP_PLUGIN_DIR . '/' . dirname( $identifier ) . '/ran-booster-proof.txt', $marker );
	}

	private function assert_theme( string $slug, string $version, string $marker, bool $active ): void {
		wp_clean_themes_cache();
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists()
			|| $version !== (string) $theme->get( 'Version' )
			|| ( (string) get_option( 'stylesheet' ) === $slug ) !== $active
		) {
			throw new RuntimeException( 'The disposable proof theme state is incorrect.' );
		}
		$this->assert_marker( get_theme_root( $slug ) . '/' . $slug . '/ran-booster-proof.txt', $marker );
	}

	private function assert_marker( string $path, string $expected ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		$contents = is_file( $path ) ? file_get_contents( $path ) : false;
		if ( $expected . "\n" !== $contents ) {
			throw new RuntimeException( 'WordPress did not install the exact disposable package bytes.' );
		}
	}

	private function activate_plugin( string $identifier ): void {
		$result = activate_plugin( $identifier, '', false, true );
		if ( is_wp_error( $result ) || ! is_plugin_active( $identifier ) ) {
			throw new RuntimeException( 'The disposable proof plugin could not be activated.' );
		}
	}

	private function assert_maintenance_absent(): void {
		clearstatcache( true, ABSPATH . '.maintenance' );
		if ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) ) {
			throw new RuntimeException( 'WordPress left maintenance mode after a successful proof update.' );
		}
	}

	private function assert_backup_absent( string $kind, string $slug ): void {
		$path = WP_CONTENT_DIR . '/upgrade-temp-backup/' . $kind . '/' . $slug;
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'WordPress retained a temporary backup after reporting successful restoration.' );
		}
	}

	private function assert_destination_absent( string $path ): void {
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'A disposable proof destination already exists.' );
		}
	}

	private function slug( string $role ): string {
		return 'ran-booster-core-' . $role . '-' . $this->run_id;
	}

	private function plugin_identifier( string $slug ): string {
		return $slug . '/' . $slug . '.php';
	}
}

$proof   = new RanBoosterCoreUpdaterProof( bin2hex( random_bytes( 6 ) ) );
$failure = null;

try {
	$proof->run();
} catch ( Throwable $caught ) {
	$failure = $caught;
}

try {
	$proof->cleanup();
} catch ( Throwable $cleanup_failure ) {
	$failure = $cleanup_failure;
}

if ( null !== $failure ) {
	throw $failure;
}

WP_CLI::success( 'WordPress-core updater proof passed: local installs, one-item updates, activation, same-version bytes, downgrade-shaped replacement, restoration after a simulated fatal-scrape response, unrelated-package isolation and exact hook cleanup.' );
