<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.
// phpcs:disable

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
	private array $plugins = array();
	private array $themes = array();
	private string $originalStylesheet;

	public function __construct( private readonly string $runId ) {
		if ( preg_match( '/^[a-f0-9]{12}$/D', $runId ) !== 1 ) {
			throw new RuntimeException( 'The proof run identity is invalid.' );
		}

		$this->originalStylesheet = (string) get_option( 'stylesheet' );

		clearstatcache( true, ABSPATH . '.maintenance' );
		if ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) ) {
			throw new RuntimeException( 'The proof refuses to replace a pre-existing maintenance marker.' );
		}
	}

	public function run(): void {
		$unrelatedSlug       = $this->slug( 'unrelated-plugin' );
		$unrelatedIdentifier = $this->pluginIdentifier( $unrelatedSlug );
		$unrelatedArchive    = $this->archive( 'plugin', $unrelatedSlug, '9.0.0', 'unrelated-original' );
		$this->installPlugin( $unrelatedSlug, $unrelatedArchive );

		$pluginSlug       = $this->slug( 'plugin' );
		$pluginIdentifier = $this->pluginIdentifier( $pluginSlug );
		$this->installPlugin( $pluginSlug, $this->archive( 'plugin', $pluginSlug, '1.0.0', 'plugin-install' ) );
		$this->assertPlugin( $pluginIdentifier, '1.0.0', 'plugin-install', false );

		$this->updatePlugin(
			$pluginSlug,
			$this->archive( 'plugin', $pluginSlug, '2.0.0', 'plugin-inactive-update' ),
			'2.0.0',
			$unrelatedIdentifier,
			$unrelatedArchive
		);
		$this->assertPlugin( $pluginIdentifier, '2.0.0', 'plugin-inactive-update', false );
		$this->assertPlugin( $unrelatedIdentifier, '9.0.0', 'unrelated-original', false );

		$this->activatePlugin( $pluginIdentifier );
		$this->updatePlugin(
			$pluginSlug,
			$this->archive( 'plugin', $pluginSlug, '3.0.0', 'plugin-active-update' ),
			'3.0.0',
			$unrelatedIdentifier,
			$unrelatedArchive
		);
		$this->assertPlugin( $pluginIdentifier, '3.0.0', 'plugin-active-update', true );

		$this->updatePlugin(
			$pluginSlug,
			$this->archive( 'plugin', $pluginSlug, '3.0.0', 'plugin-same-version-new-bytes' ),
			'3.0.0',
			$unrelatedIdentifier,
			$unrelatedArchive
		);
		$this->assertPlugin( $pluginIdentifier, '3.0.0', 'plugin-same-version-new-bytes', true );

		$this->updatePlugin(
			$pluginSlug,
			$this->archive( 'plugin', $pluginSlug, '1.5.0', 'plugin-downgrade' ),
			'1.5.0',
			$unrelatedIdentifier,
			$unrelatedArchive
		);
		$this->assertPlugin( $pluginIdentifier, '1.5.0', 'plugin-downgrade', true );

		$this->updatePlugin(
			$pluginSlug,
			$this->archive( 'plugin', $pluginSlug, '4.0.0', 'plugin-fatal-update' ),
			'4.0.0',
			$unrelatedIdentifier,
			$unrelatedArchive,
			true
		);
		$this->assertPlugin( $pluginIdentifier, '1.5.0', 'plugin-downgrade', true );
		$this->assertBackupAbsent( 'plugins', $pluginSlug );

		$themeSlug = $this->slug( 'theme' );
		$this->installTheme( $themeSlug, $this->archive( 'theme', $themeSlug, '1.0.0', 'theme-install' ) );
		$this->assertTheme( $themeSlug, '1.0.0', 'theme-install', false );

		$this->updateTheme( $themeSlug, $this->archive( 'theme', $themeSlug, '2.0.0', 'theme-inactive-update' ), '2.0.0' );
		$this->assertTheme( $themeSlug, '2.0.0', 'theme-inactive-update', false );

		switch_theme( $themeSlug );
		$this->updateTheme( $themeSlug, $this->archive( 'theme', $themeSlug, '3.0.0', 'theme-active-update' ), '3.0.0' );
		$this->assertTheme( $themeSlug, '3.0.0', 'theme-active-update', true );

		$this->assertMaintenanceAbsent();
	}

	public function cleanup(): void {
		if ( $this->originalStylesheet !== (string) get_option( 'stylesheet' ) ) {
			switch_theme( $this->originalStylesheet );
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
			if ( is_file( $archive ) && ! unlink( $archive ) ) {
				throw new RuntimeException( 'A disposable proof archive could not be removed.' );
			}
		}
	}

	private function installPlugin( string $slug, string $archive ): void {
		$identifier = $this->pluginIdentifier( $slug );
		$this->assertDestinationAbsent( WP_PLUGIN_DIR . '/' . $slug );
		$result = $this->withSourceSelection(
			$slug,
			static fn () => ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->install( $archive )
		);
		if ( true !== $result ) {
			throw new RuntimeException( 'WordPress core did not install the disposable proof plugin.' );
		}
		$this->plugins[] = $identifier;
	}

	private function installTheme( string $slug, string $archive ): void {
		$this->assertDestinationAbsent( get_theme_root() . '/' . $slug );
		$result = $this->withSourceSelection(
			$slug,
			static fn () => ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->install( $archive )
		);
		if ( true !== $result ) {
			throw new RuntimeException( 'WordPress core did not install the disposable proof theme.' );
		}
		$this->themes[] = $slug;
	}

	private function updatePlugin(
		string $slug,
		string $archive,
		string $version,
		string $unrelatedIdentifier,
		string $unrelatedArchive,
		bool $simulatedFatalScrape = false
	): void {
		$identifier = $this->pluginIdentifier( $slug );
		$offer      = (object) array(
			'id'           => 'https://proof.invalid/' . $slug,
			'slug'         => $slug,
			'plugin'       => $identifier,
			'new_version'  => $version,
			'package'      => $archive,
			'autoupdate'   => true,
			'requires_php' => '8.2',
		);

		$this->updateOne(
			'plugin',
			$slug,
			$offer,
			'pre_site_transient_update_plugins',
			static function ( mixed $transient ) use ( $identifier, $offer, $unrelatedIdentifier, $unrelatedArchive ): object {
				if ( ! is_object( $transient ) ) {
					$transient = new stdClass();
				}
				if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
					$transient->response = array();
				}
				$transient->response[ $identifier ] = $offer;
				$transient->response[ $unrelatedIdentifier ] = (object) array(
					'slug'        => dirname( $unrelatedIdentifier ),
					'plugin'      => $unrelatedIdentifier,
					'new_version' => '10.0.0',
					'package'     => $unrelatedArchive,
					'autoupdate'  => true,
				);

				return $transient;
			},
			$identifier,
			$simulatedFatalScrape
		);
	}

	private function updateTheme( string $slug, string $archive, string $version ): void {
		$offer = (object) array(
			'id'           => 'https://proof.invalid/' . $slug,
			'theme'        => $slug,
			'new_version'  => $version,
			'package'      => $archive,
			'autoupdate'   => true,
			'requires_php' => '8.2',
		);

		$this->updateOne(
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

	private function updateOne(
		string $type,
		string $slug,
		object $offer,
		string $transientHook,
		Closure $transientFilter,
		string $expectedIdentifier,
		bool $simulatedFatalScrape
	): void {
		$sourceFilter   = $this->sourceSelectionFilter( $slug );
		$archivePath    = (string) ( $offer->package ?? '' );
		$vcsFilter      = $this->vcsFilter( $type, $expectedIdentifier );
		$preDownload    = static function ( mixed $reply, mixed $package, mixed $upgrader, array $extra ) use ( $archivePath, $type, $expectedIdentifier ): mixed {
			$operationIdentifier = 'plugin' === $type ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null );
			if ( is_string( $package )
				&& hash_equals( $archivePath, $package )
				&& $type === ( $extra['type'] ?? null )
				&& 'update' === ( $extra['action'] ?? null )
				&& $expectedIdentifier === $operationIdentifier
			) {
				return $archivePath;
			}

			return $reply;
		};
		$scrapeResponse = $this->simulatedScrapeResponseFilter( $simulatedFatalScrape );
		$completions    = array();
		$complete       = static function ( object $upgrader, array $extra ) use ( &$completions ): void {
			$completions[] = $extra;
		};
		$automaticCompleteCalls = 0;
		$automaticComplete      = static function () use ( &$automaticCompleteCalls ): void {
			++$automaticCompleteCalls;
		};
		$targetContext = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root( $expectedIdentifier );
		if ( false !== $vcsFilter( true, $targetContext ) || true !== $vcsFilter( true, ABSPATH ) ) {
			throw new RuntimeException( 'The disposable VCS exception is not limited to the target package context.' );
		}

		add_filter( $transientHook, $transientFilter, 10, 1 );
		add_filter( 'upgrader_pre_download', $preDownload, 10, 4 );
		add_filter( 'upgrader_source_selection', $sourceFilter, 10, 3 );
		add_filter( 'automatic_updates_is_vcs_checkout', $vcsFilter, 10, 2 );
		add_filter( 'pre_http_request', $scrapeResponse, 10, 3 );
		add_action( 'upgrader_process_complete', $complete, 100, 2 );
		add_action( 'automatic_updates_complete', $automaticComplete, 100, 1 );

		try {
			$result = ( new WP_Automatic_Updater() )->update( $type, $offer );
		} finally {
			remove_filter( $transientHook, $transientFilter, 10 );
			remove_filter( 'upgrader_pre_download', $preDownload, 10 );
			remove_filter( 'upgrader_source_selection', $sourceFilter, 10 );
			remove_filter( 'automatic_updates_is_vcs_checkout', $vcsFilter, 10 );
			remove_filter( 'pre_http_request', $scrapeResponse, 10 );
			remove_action( 'upgrader_process_complete', $complete, 100 );
			remove_action( 'automatic_updates_complete', $automaticComplete, 100 );
		}

		if ( $simulatedFatalScrape ) {
			if ( ! is_wp_error( $result ) || 'plugin_update_fatal_error_rollback_successful' !== $result->get_error_code() ) {
				$code = is_wp_error( $result ) ? $result->get_error_code() : get_debug_type( $result );
				throw new RuntimeException( 'WordPress core did not report restoration after the simulated fatal-scrape response: ' . $code );
			}
		} elseif ( true !== $result ) {
			$code = is_wp_error( $result ) ? $result->get_error_code() : get_debug_type( $result );
			throw new RuntimeException( 'The direct WordPress automatic update failed: ' . $code );
		}
		if ( 1 !== count( $completions ) ) {
			throw new RuntimeException( 'The direct update did not complete exactly one package operation.' );
		}
		$extra = $completions[0];
		if ( $type !== ( $extra['type'] ?? null ) || 'update' !== ( $extra['action'] ?? null ) ) {
			throw new RuntimeException( 'The direct update completed an unexpected package operation.' );
		}
		$completedIdentifier = 'plugin' === $type ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null );
		if ( $expectedIdentifier !== $completedIdentifier ) {
			throw new RuntimeException( 'The direct update completed an unrelated package.' );
		}
		if ( 0 !== $automaticCompleteCalls ) {
			throw new RuntimeException( 'The proof invoked the automatic updater sweep rather than one update.' );
		}
		foreach (
			array(
				$transientHook              => $transientFilter,
				'upgrader_pre_download'      => $preDownload,
				'upgrader_source_selection' => $sourceFilter,
				'automatic_updates_is_vcs_checkout' => $vcsFilter,
				'pre_http_request'           => $scrapeResponse,
				'upgrader_process_complete'  => $complete,
				'automatic_updates_complete' => $automaticComplete,
			) as $hook => $callback
		) {
			if ( false !== has_filter( $hook, $callback ) ) {
				throw new RuntimeException( 'The proof left a scoped WordPress hook installed.' );
			}
		}
	}

	private function vcsFilter( string $type, string $identifier ): Closure {
		$allowedContext = WP_PLUGIN_DIR;
		if ( 'theme' === $type ) {
			$themeRoot = realpath( get_theme_root( $identifier ) );
			if ( false === $themeRoot || ! is_dir( $themeRoot ) ) {
				return static fn ( bool $checkout, string $context ): bool => $checkout;
			}
			$allowedContext = $themeRoot;
		}

		return static function ( bool $checkout, string $context ) use ( $type, $allowedContext ): bool {
			if ( 'plugin' === $type ) {
				return WP_PLUGIN_DIR === $context ? false : $checkout;
			}
			$canonicalContext = realpath( $context );

			return false !== $canonicalContext && hash_equals( $allowedContext, $canonicalContext ) ? false : $checkout;
		};
	}

	private function withSourceSelection( string $slug, callable $operation ): mixed {
		$sourceFilter = $this->sourceSelectionFilter( $slug );
		add_filter( 'upgrader_source_selection', $sourceFilter, 10, 3 );
		try {
			return $operation();
		} finally {
			remove_filter( 'upgrader_source_selection', $sourceFilter, 10 );
			if ( false !== has_filter( 'upgrader_source_selection', $sourceFilter ) ) {
				throw new RuntimeException( 'The proof left its install source filter installed.' );
			}
		}
	}

	private function sourceSelectionFilter( string $slug ): Closure {
		return static function ( mixed $source, mixed $remoteSource, mixed $upgrader ) use ( $slug ): mixed {
			if ( ! is_string( $source ) || ! is_string( $remoteSource ) ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable source path is invalid.' );
			}
			$sourceRoot = realpath( $source );
			$remoteRoot = realpath( $remoteSource );
			if ( false === $sourceRoot || false === $remoteRoot || ! is_dir( $sourceRoot ) || ! is_dir( $remoteRoot ) ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable source path is unavailable.' );
			}
			$prefix = trailingslashit( $remoteRoot );
			if ( ! str_starts_with( trailingslashit( $sourceRoot ), $prefix ) || $sourceRoot === $remoteRoot ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable source escaped its extraction root.' );
			}
			$destination = $remoteRoot . DIRECTORY_SEPARATOR . $slug;
			if ( file_exists( $destination ) || is_link( $destination ) ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable destination already exists.' );
			}

			global $wp_filesystem;
			if ( ! is_object( $wp_filesystem ) || ! $wp_filesystem->move( $sourceRoot, $destination, false ) ) {
				return new WP_Error( 'ran_booster_core_proof_source', 'The disposable source could not be selected.' );
			}

			return trailingslashit( $destination );
		};
	}

	private function simulatedScrapeResponseFilter( bool $fatal ): Closure {
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
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
	}

	private function archive( string $kind, string $slug, string $version, string $marker ): string {
		$path = wp_tempnam( 'ran-booster-core-proof-' . $this->runId . '.zip' );
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

	private function assertPlugin( string $identifier, string $version, string $marker, bool $active ): void {
		wp_clean_plugins_cache( false );
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $identifier, false, false );
		$observedVersion = $data['Version'] ?? null;
		$observedActive  = is_plugin_active( $identifier );
		if ( $version !== $observedVersion || $active !== $observedActive ) {
			throw new RuntimeException(
				'The disposable proof plugin state is incorrect: expected version '
				. $version . ' and active=' . ( $active ? 'yes' : 'no' )
				. ', observed version ' . ( is_string( $observedVersion ) ? $observedVersion : 'unavailable' )
				. ' and active=' . ( $observedActive ? 'yes' : 'no' ) . '.'
			);
		}
		$this->assertMarker( WP_PLUGIN_DIR . '/' . dirname( $identifier ) . '/ran-booster-proof.txt', $marker );
	}

	private function assertTheme( string $slug, string $version, string $marker, bool $active ): void {
		wp_clean_themes_cache();
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists()
			|| $version !== (string) $theme->get( 'Version' )
			|| $active !== ( $slug === (string) get_option( 'stylesheet' ) )
		) {
			throw new RuntimeException( 'The disposable proof theme state is incorrect.' );
		}
		$this->assertMarker( get_theme_root( $slug ) . '/' . $slug . '/ran-booster-proof.txt', $marker );
	}

	private function assertMarker( string $path, string $expected ): void {
		$contents = is_file( $path ) ? file_get_contents( $path ) : false;
		if ( $expected . "\n" !== $contents ) {
			throw new RuntimeException( 'WordPress did not install the exact disposable package bytes.' );
		}
	}

	private function activatePlugin( string $identifier ): void {
		$result = activate_plugin( $identifier, '', false, true );
		if ( is_wp_error( $result ) || ! is_plugin_active( $identifier ) ) {
			throw new RuntimeException( 'The disposable proof plugin could not be activated.' );
		}
	}

	private function assertMaintenanceAbsent(): void {
		clearstatcache( true, ABSPATH . '.maintenance' );
		if ( file_exists( ABSPATH . '.maintenance' ) || is_link( ABSPATH . '.maintenance' ) ) {
			throw new RuntimeException( 'WordPress left maintenance mode after a successful proof update.' );
		}
	}

	private function assertBackupAbsent( string $kind, string $slug ): void {
		$path = WP_CONTENT_DIR . '/upgrade-temp-backup/' . $kind . '/' . $slug;
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'WordPress retained a temporary backup after reporting successful restoration.' );
		}
	}

	private function assertDestinationAbsent( string $path ): void {
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'A disposable proof destination already exists.' );
		}
	}

	private function slug( string $role ): string {
		return 'ran-booster-core-' . $role . '-' . $this->runId;
	}

	private function pluginIdentifier( string $slug ): string {
		return $slug . '/' . $slug . '.php';
	}
}

$proof = new RanBoosterCoreUpdaterProof( bin2hex( random_bytes( 6 ) ) );
$failure = null;

try {
	$proof->run();
} catch ( Throwable $caught ) {
	$failure = $caught;
}

try {
	$proof->cleanup();
} catch ( Throwable $cleanupFailure ) {
	$failure = $cleanupFailure;
}

if ( null !== $failure ) {
	throw $failure;
}

WP_CLI::success( 'WordPress-core updater proof passed: local installs, one-item updates, activation, same-version bytes, downgrade-shaped replacement, restoration after a simulated fatal-scrape response, unrelated-package isolation and exact hook cleanup.' );
