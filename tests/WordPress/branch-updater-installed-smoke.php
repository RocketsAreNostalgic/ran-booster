<?php

// WP-CLI proof of Branch's released adapter loaded through the installed Core ZIP.

use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;

function ran_booster_branch_installed_smoke(): void {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$root     = realpath( ABSPATH );
	$expected = getenv( 'RAN_BOOSTER_WORDPRESS_PATH' );
	$marker   = ABSPATH . '.ran-booster-disposable-test-site';
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_CORE_UPDATER_TEST_DISPOSABLE' )
		|| ! is_string( $expected ) || false === $root || realpath( $expected ) !== $root
		|| rtrim( ABSPATH, '/' ) !== $root || rtrim( $expected, '/' ) !== $root
		|| WP_CONTENT_DIR !== $root . '/wp-content' || realpath( WP_CONTENT_DIR ) !== WP_CONTENT_DIR
		|| WP_PLUGIN_DIR !== WP_CONTENT_DIR . '/plugins' || realpath( WP_PLUGIN_DIR ) !== WP_PLUGIN_DIR
		|| get_theme_root() !== WP_CONTENT_DIR . '/themes' || realpath( get_theme_root() ) !== get_theme_root()
		|| 'http://localhost' !== get_option( 'siteurl' ) || 'http://localhost' !== get_option( 'home' )
		|| is_link( $marker ) || ! is_file( $marker )
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact disposable-site marker before any filesystem mutation.
		|| "RAN Booster disposable test site\n" !== file_get_contents( $marker )
		|| is_link( WP_PLUGIN_DIR . '/ran-booster' ) || ! is_plugin_active( 'ran-booster/ran-booster.php' ) ) {
		throw new RuntimeException( 'Branch consumer proof requires the exact disposable installed Core site.' );
	}
	$source = WP_PLUGIN_DIR . '/ran-booster/vendor/ran/wp-branch-updater/src/WordPress/WordPressCorePackageExecutor.php';
	if ( realpath( $source ) !== $source || ( new ReflectionClass( WordPressCorePackageExecutor::class ) )->getFileName() !== $source ) {
		throw new RuntimeException( 'Branch executor was not loaded from the installed Core vendor package.' );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( ! WP_Filesystem() ) {
		throw new RuntimeException( 'Native WordPress filesystem is unavailable.' );
	}
	global $wp_filesystem;
	if ( ! $wp_filesystem instanceof WP_Filesystem_Direct ) {
		throw new RuntimeException( 'Branch proof requires the native direct filesystem.' );
	}
	$filesystem = $wp_filesystem;
	$run_id     = strtolower( wp_generate_password( 12, false, false ) );
	$custody    = WP_CONTENT_DIR . '/ran-branch-custody-' . $run_id;
	$executor   = new WordPressCorePackageExecutor();
	foreach ( array( 'plugin', 'theme' ) as $type ) {
		$slug       = 'ran-branch-consumer-' . $type . '-' . $run_id;
		$identifier = 'plugin' === $type ? $slug . '/' . $slug . '.php' : $slug;
		$target     = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $slug;
		if ( file_exists( $target ) || is_link( $target ) || file_exists( $custody ) || is_link( $custody ) ) {
			throw new RuntimeException( 'Disposable Branch target or custody already exists.' );
		}
		try {
			foreach ( array(
				'install' => '1.0.0',
				'update'  => '2.0.0',
			) as $operation => $version ) {
				if ( is_link( $target ) || ( file_exists( $target ) && realpath( $target ) !== $target ) ) {
					throw new RuntimeException( 'Branch target ceased to be a canonical disposable path.' );
				}
				$declaration             = new BranchDeploymentDeclaration( $run_id, $type, $slug, 'fixtures/consumer', 'fixture', 'main', null, $operation, 'packages/target', $identifier );
				$artifact                = ran_booster_branch_installed_artifact( $declaration, $version, $custody );
				$hook                    = 'pre_site_transient_update_' . ( 'plugin' === $type ? 'plugins' : 'themes' );
				$foreign                 = new class() {
					/** @var array<string,mixed> */
					public array $response      = array();
					public int $last_checked    = 0;
					public string $branch_probe = 'preserved';
				};
				$foreign->last_checked   = time();
				$previous                = array(
					$type         => $identifier,
					'slug'        => $slug,
					'new_version' => '1.0.0',
					'package'     => '',
				);
				$original_offer          = 'plugin' === $type ? (object) $previous : $previous;
				$expected_offer          = $original_offer;
				$response                = array();
				$response[ $identifier ] =& $original_offer;
				$foreign->response       =& $response;
				$inject                  = static fn (): object => $foreign;
				$observed                = false;
				$observe                 = static function ( mixed $value ) use ( &$observed, $identifier, $type ): mixed {
					if ( $value instanceof stdClass && 'preserved' === ( $value->branch_probe ?? null ) ) {
						$offer    = $value->response[ $identifier ] ?? null;
						$observed = $observed || ( 'plugin' === $type ? is_object( $offer ) && '2.0.0' === ( $offer->new_version ?? null ) : is_array( $offer ) && '2.0.0' === ( $offer['new_version'] ?? null ) );
					}
					return $value;
				};
				if ( 'update' === $operation ) {
					add_filter( $hook, $inject, 5, 0 );
					add_filter( $hook, $observe, 20 );
				}
				try {
					if ( 'plugin' === $type ) {
						$result = 'install' === $operation ? $executor->install_plugin( $artifact, $slug, 'packages/target' ) : $executor->update_plugin( $artifact, $slug, 'packages/target', $identifier );
					} else {
						$result = 'install' === $operation ? $executor->install_theme( $artifact, $slug, 'packages/target' ) : $executor->update_theme( $artifact, $slug, 'packages/target', $identifier );
					}
					if ( ! $result->is_successful() || ( 'update' === $operation && ( ! $observed
						|| $expected_offer !== $original_offer || array( $identifier => $expected_offer ) !== $foreign->response
						|| get_site_transient( 'update_' . ( 'plugin' === $type ? 'plugins' : 'themes' ) ) !== $foreign ) ) ) {
						throw new RuntimeException( 'Branch operation failed or leaked its scoped transient offer.' );
					}
				} finally {
					remove_filter( $hook, $inject, 5 );
					remove_filter( $hook, $observe, 20 );
					$artifact->cleanup();
				}
				wp_clean_plugins_cache( false );
				wp_clean_themes_cache( false );
				$installed_version = 'plugin' === $type ? get_plugin_data( $target . '/' . $slug . '.php', false, false )['Version'] : wp_get_theme( $slug )->get( 'Version' );
				if ( $version !== $installed_version || ( 'plugin' === $type ? is_plugin_active( $identifier ) : get_stylesheet() === $slug )
					|| file_exists( $target . '/outside.txt' ) || file_exists( $target . '/packages' )
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify exact installed fixture bytes after the native updater operation.
					|| file_get_contents( $target . '/proof.txt' ) !== $version || wp_doing_cron() || file_exists( ABSPATH . '.maintenance' ) ) {
					throw new RuntimeException( 'Branch installed readback, inactivity or source relocation failed.' );
				}
			}
		} finally {
			if ( is_link( $target ) || is_link( $custody ) ) {
				throw new RuntimeException( 'Refusing symlinked Branch proof cleanup.' );
			}
			if ( is_dir( $target ) ) {
				$deleted = 'plugin' === $type ? delete_plugins( array( $identifier ) ) : delete_theme( $slug );
				if ( true !== $deleted ) {
					throw new RuntimeException( 'WordPress could not delete the disposable Branch target.' );
				}
			}
			if ( is_dir( $custody ) && ! $filesystem->delete( $custody, true ) ) {
				throw new RuntimeException( 'WordPress could not delete the Branch custody directory.' );
			}
		}
	}
	echo "Installed Branch consumer: plugin/theme install/update, source relocation and reference-safe transient scope passed.\n";
}

function ran_booster_branch_installed_artifact( BranchDeploymentDeclaration $declaration, string $version, string $custody ): PreparedArchive {
	$source = wp_tempnam( 'ran-branch-consumer.zip' );
	$zip    = new ZipArchive();
	try {
		if ( true !== $zip->open( $source, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( 'Cannot create Branch fixture ZIP.' );
		}
		$prefix = 'repository/packages/target/';
		if ( 'plugin' === $declaration->package_type ) {
			$zip->addFromString( $prefix . $declaration->slug . '.php', "<?php\n/*\nPlugin Name: Branch consumer proof\nVersion: " . $version . "\n*/\n" );
		} else {
			$zip->addFromString( $prefix . 'style.css', "/*\nTheme Name: Branch consumer proof\nVersion: " . $version . "\n*/\n" );
			$zip->addFromString( $prefix . 'index.php', '<?php // Disposable theme.' );
		}
		$zip->addFromString( $prefix . 'proof.txt', $version );
		$zip->addFromString( 'repository/outside.txt', 'excluded' );
		$zip->close();
		$offer = new ArchiveOffer(
			'fixture',
			'fixture',
			'local',
			static function ( string $destination, int $maximum_bytes ) use ( $source ): void {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copy controlled local bytes into Branch's production custody boundary.
				if ( filesize( $source ) > $maximum_bytes || ! copy( $source, $destination ) ) {
					throw new RuntimeException( 'Cannot supply bounded Branch fixture bytes.' );
				}
			},
			static function (): void {}
		);
		return PreparedArchive::download_and_validate( $offer, $declaration, $custody );
	} finally {
		wp_delete_file( $source );
	}
}

ran_booster_branch_installed_smoke();
