<?php

// Refs #969. Real installed Core and WordPress; only remote HTTP is synthetic.

use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

final class RAN_Booster_NativeReleaseInstallProof {

	private string $slug;
	private string $identity;
	private string $directory;
	private string $repository;
	private string $metadata;
	private string $repository_id;
	private bool $changed               = false;
	private int $downloads              = 0;
	private ?WP_Error $download_failure = null;
	private string $archive_root;

	public function __construct( private string $kind, private string $scenario ) {
		$site = getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE' );
		$root = is_string( $site ) ? realpath( $site ) : false;
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || false === $root || realpath( ABSPATH ) !== $root
			|| realpath( WP_CONTENT_DIR ) !== $root . '/wp-content' || realpath( WP_PLUGIN_DIR ) !== $root . '/wp-content/plugins'
			|| realpath( get_theme_root() ) !== $root . '/wp-content/themes' || 'http://localhost' !== get_option( 'siteurl' )
			|| is_link( $root . '/.ran-booster-disposable-test-site' )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact disposable-site ownership marker before any fixture mutation.
			|| "RAN Booster disposable test site\n" !== file_get_contents( $root . '/.ran-booster-disposable-test-site' )
			|| '7.0.3' !== get_bloginfo( 'version' ) || 802 !== PHP_MAJOR_VERSION * 100 + PHP_MINOR_VERSION ) {
			throw new RuntimeException( 'Disposable WordPress 7.0.3 / PHP 8.2 identity not established.' );
		}
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! in_array( $scenario, array( 'current', 'automatic', 'equal', 'older', 'older-override-off', 'newer', 'prerelease', 'repository-race', 'revision-race', 'mutable', 'assurance', 'disabled', 'active', 'restore', 'kill-current', 'kill-newer' ), true ) ) {
			throw new RuntimeException( 'Unknown proof case.' );
		}
		$run = getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_RUN' );
		if ( ! is_string( $run ) || 1 !== preg_match( '/\A[a-z0-9]{1,12}\z/D', $run ) ) {
			throw new RuntimeException( 'Invalid proof run identity.' ); }
		$this->slug          = 'native-proof-' . $kind . '-' . $scenario . '-' . $run;
		$this->repository_id = (string) ( 969000 + abs( crc32( $this->slug ) ) );
		$this->metadata      = 'plugin' === $kind ? $this->slug . '.php' : 'style.css';
		$this->identity      = 'plugin' === $kind ? $this->slug . '/' . $this->metadata : $this->slug;
		$this->directory     = $root . '/wp-content/' . ( 'plugin' === $kind ? 'plugins/' : 'themes/' ) . $this->slug;
		$this->repository    = 'native-proof/' . $this->slug;
		$this->archive_root  = $root . '/native-release-install-proof';
		foreach ( array( ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root(), $this->directory, $this->archive_root ) as $path ) {
			if ( is_link( rtrim( $path, '/' ) ) ) {
				throw new RuntimeException( 'Symlinked disposable target rejected.' );
			}
		}
	}

	private function contents( string $version ): string {
		return ( 'plugin' === $this->kind ? "<?php\n/*\nPlugin" : '/*' . "\nTheme" ) . ' Name: Native release install proof' . "\nVersion: $version\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/" . $this->repository . "\n*/\n";
	}

	private function archive( int $number ): string {
		return $this->archive_root . '/' . $this->slug . '-' . $number . '.zip';
	}

	public function seed(): void {
		global $wpdb;
		if ( file_exists( $this->directory ) ) {
			throw new RuntimeException( 'Fixture target already exists; use a fresh disposable site.' );
		}
		wp_mkdir_p( $this->directory );
		wp_mkdir_p( $this->archive_root );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write synthetic package only beneath the verified, previously absent disposable target.
		file_put_contents( $this->directory . '/' . $this->metadata, $this->contents( '1.0.0' ) );
		if ( 'theme' === $this->kind ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Synthetic theme entrypoint in this proof's exclusively owned target.
			file_put_contents( $this->directory . '/index.php', '<?php' );
		}
		foreach ( array( '0.5.0', '1.0.0', '2.0.0', '3.0.0', '2.1.0-rc.1' ) as $number => $version ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $this->archive( $number ), ZipArchive::CREATE | ZipArchive::EXCL ) ) {
				throw new RuntimeException( 'Fixture archive is not exclusively owned.' );
			}
			$zip->addFromString( $this->slug . '/' . $this->metadata, $this->contents( $version ) );
			if ( 'theme' === $this->kind ) {
				$zip->addFromString( $this->slug . '/index.php', '<?php' );
			}
			$zip->close();
		}
		$inserted = $wpdb->insert(
			ran_booster_table_name(),
			array(
				'package'                => $this->identity,
				'repository'             => $this->repository,
				'branch'                 => 'main',
				'package_type'           => 'plugin' === $this->kind ? 1 : 2,
				'deployment_policy'      => 'automatic' === $this->scenario ? 'automatic' : ( 'disabled' === $this->scenario ? 'disabled' : 'manual' ),
				'source'                 => 'release_asset',
				'source_revision'        => 1,
				'provider'               => 'gh',
				'provider_repository_id' => $this->repository_id,
				'adoption_token'         => RAN\AdoptionIdentity::create( 'gh', $this->repository_id ),
				'private'                => 0,
				'release_configuration'  => wp_json_encode(
					array(
						'channel'       => 'prerelease' === $this->scenario ? 'prerelease' : 'stable',
						'package_root'  => $this->slug,
						'metadata_file' => $this->metadata,
					)
				),
			)
		);
		wp_clean_plugins_cache( false );
		wp_clean_themes_cache( false );
		if ( 'active' === $this->scenario ) {
			if ( 'plugin' === $this->kind ) {
				$activation = activate_plugin( $this->identity );
				if ( is_wp_error( $activation ) ) {
					throw new RuntimeException( 'Fixture activation failed.' ); }
			} else {
				switch_theme( $this->slug ); }
		}
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'Synthetic managed row was not inserted.' );
		}
		$this->assert_activation();
	}

	/** @return array<string,mixed> */
	private function release( int $number ): array {
		$versions = array( '0.5.0', '1.0.0', '2.0.0', '3.0.0', '2.1.0-rc.1' );
		return array(
			'id'           => 100 + $number,
			'tag_name'     => 'v' . $versions[ $number ],
			'draft'        => false,
			'prerelease'   => 4 === $number,
			'immutable'    => false,
			'published_at' => '2026-11-16T00:00:00Z',
			'html_url'     => 'https://github.com/' . $this->repository . '/releases/tag/v' . $versions[ $number ],
			'assets'       => array(
				array(
					'id'                   => ( $this->changed && 'mutable' === $this->scenario ? 300 : 200 ) + $number,
					'name'                 => basename( $this->archive( $number ) ),
					'size'                 => filesize( $this->archive( $number ) ),
					'state'                => 'uploaded',
					'digest'               => $this->changed && 'assurance' === $this->scenario ? null : 'sha256:' . hash_file( 'sha256', $this->archive( $number ) ),
					'browser_download_url' => 'https://api.github.com/repos/' . $this->repository . '/releases/assets/' . ( 200 + $number ),
				),
			),
		);
	}

	/** @param array<string,mixed> $args */
	public function http( mixed $pre, array $args, string $url ): mixed {
		unset( $pre );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || ( ! str_starts_with( $path, '/repos/' . $this->repository ) && '/repositories/' . $this->repository_id !== $path ) ) {
			return new WP_Error( 'native_proof_network_denied' );
		}
		$number = (int) basename( $path );
		if ( str_contains( $path, '/releases/assets/' ) && true === ( $args['stream'] ?? false ) && is_string( $args['filename'] ?? null ) ) {
			++$this->downloads;
			if ( ! copy( $this->archive( $number - 200 ), $args['filename'] ) ) {
				throw new RuntimeException( 'Synthetic HTTP asset stream failed.' );
			}
			$body = '';
		} elseif ( str_ends_with( $path, '/releases' ) ) {
			$body = wp_json_encode( array_map( $this->release( ... ), array( 3, 4, 2, 1, 0 ) ) );
		} elseif ( str_contains( $path, '/releases/' ) ) {
			$body = wp_json_encode( $this->release( $number - 100 ) );
		} elseif ( str_contains( $path, '/commits/' ) ) {
			$body = str_repeat( 'a', 40 );
		} else {
			$body = wp_json_encode(
				array(
					'id'             => (int) $this->repository_id,
					'name'           => $this->slug,
					'full_name'      => $this->repository,
					'private'        => false,
					'default_branch' => 'main',
					'html_url'       => 'https://github.com/' . $this->repository,
					'owner'          => array( 'login' => 'native-proof' ),
				)
			);
		}
		return array(
			'body'     => $body,
			'headers'  => array(),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'filename' => $args['filename'] ?? null,
		);
	}

	public function run(): void {
		global $wpdb;
		$this->assert_files( '1.0.0', $this->directory );
		$this->assert_activation();
		add_filter( 'pre_http_request', $this->http( ... ), PHP_INT_MIN, 3 );
		WP_Filesystem();
		$headers = array(
			'Version'   => '1.0.0',
			'UpdateURI' => 'https://github.com/' . $this->repository,
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Invoke the WordPress-owned hostname update hook, with its exact native spelling.
		$offer = apply_filters( 'update_' . ( 'plugin' === $this->kind ? 'plugins' : 'themes' ) . '_github.com', false, $headers, $this->identity, array() );
		if ( 'disabled' === $this->scenario ) {
			if ( false !== $offer || 0 !== $this->download_count() ) {
				throw new RuntimeException( 'Disabled target was offered or acquired.' );
			}
			$this->assert_files( '1.0.0', $this->directory );
			$this->assert_activation();
			$this->report( 'discovery_refused', '1.0.0' );
			return;
		}
		if ( ! is_array( $offer ) || ! is_string( $offer['package'] ?? null ) ) {
			throw new RuntimeException( 'Production native discovery did not produce a control offer.' );
		}
		$chosen = match ( $this->scenario ) {
			'older', 'older-override-off' => 0, 'equal' => 1, 'newer', 'kill-newer' => 2, 'prerelease' => 4, default => 3 };
		if ( 3 !== $chosen ) {
			// Construct the proposed request-only offer, keeping the real binding and production guards.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the production offer protocol as data for this feasibility experiment.
			$decoded = base64_decode( strtr( substr( $offer['package'], strlen( 'ran-wp-release-updater:v1:' ) ), '-_', '+/' ), true );
			if ( ! is_string( $decoded ) ) {
				throw new RuntimeException( 'Invalid control token.' ); }
			$token = json_decode( $decoded, true, 32, JSON_THROW_ON_ERROR );
			$facts = $token['descriptor'];
			unset( $facts['fingerprint'] );
			$release                    = $this->release( $chosen );
			$facts['version']           = substr( $release['tag_name'], 1 );
			$facts['tag']               = $release['tag_name'];
			$facts['release_identity']  = (string) $release['id'];
			$facts['artifact_identity'] = (string) $release['assets'][0]['id'];
			$facts['artifact_filename'] = $release['assets'][0]['name'];
			$facts['artifact_size']     = $release['assets'][0]['size'];
			$facts['artifact_sha256']   = hash_file( 'sha256', $this->archive( $chosen ) );
			$facts['prerelease']        = 4 === $chosen;
			$token['descriptor']        = IdentityDescriptor::create( $facts )->to_array();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode the exact updater wire protocol, with throwing JSON failure semantics.
			$encoded = json_encode( $token, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Serialize data using the existing updater token format; no alternate installer or admission bypass.
			$offer['package'] = 'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( $encoded ), '+/', '-_' ), '=' );
			$offer['version'] = $facts['version'];
		}
		if ( in_array( $this->scenario, array( 'repository-race', 'revision-race' ), true ) ) {
			$wpdb->update( ran_booster_table_name(), 'repository-race' === $this->scenario ? array( 'provider_repository_id' => '970' ) : array( 'source_revision' => 2 ), array( 'package' => $this->identity ) );
		}
		add_filter(
			'upgrader_pre_download',
			function ( mixed $reply ): mixed {
				if ( is_wp_error( $reply ) ) {
					$this->download_failure = $reply; }
				return $reply;
			},
			PHP_INT_MAX
		);
		$this->changed = true;
		$this->reset_downloads();
		$offer['new_version']                  = $offer['version'];
		$offer['plugin']                       = $this->identity;
		$offer['theme']                        = $this->identity;
		$offer['native_release_install_proof'] = $this->slug;
		if ( 'restore' === $this->scenario ) {
			add_filter( 'upgrader_post_install', static fn () => new WP_Error( 'native_proof_after_replace', 'Synthetic failure after replacement.' ), PHP_INT_MAX );
		}
		if ( str_starts_with( $this->scenario, 'kill-' ) ) {
			add_filter(
				'upgrader_install_package_result',
				function ( mixed $result ): mixed {
					if ( ! is_array( $result ) || 1 !== $this->download_count() ) {
						throw new RuntimeException( 'Kill point did not follow installation.' ); }
					$this->assert_files( $this->expected_version(), $this->directory );
					$this->assert_activation();
					$pid = getmypid();
					if ( ! is_int( $pid ) || ! posix_kill( $pid, 9 ) ) {
						throw new RuntimeException( 'SIGKILL failed.' ); }
					return $result;
				},
				PHP_INT_MAX
			);
		}
		$offer_filter = fn () => (object) array( 'response' => array( $this->identity => 'plugin' === $this->kind ? (object) $offer : $offer ) );
		$hook         = 'pre_site_transient_update_' . ( 'plugin' === $this->kind ? 'plugins' : 'themes' );
		add_filter( $hook, $offer_filter, PHP_INT_MAX );
		try {
			$upgrader = 'plugin' === $this->kind ? new Plugin_Upgrader( new WP_Upgrader_Skin() ) : new Theme_Upgrader( new WP_Upgrader_Skin() );
			ob_start();
			try {
				// Keep native clear_update_cache=true on both paths; cleanup differs by path and outcome.
				$result        = 'active' === $this->scenario && $upgrader instanceof Plugin_Upgrader ? $upgrader->bulk_upgrade( array( $this->identity ) ) : $upgrader->upgrade( $this->identity );
				$native_return = get_debug_type( $result );
				if ( 'active' === $this->scenario && is_array( $result ) ) {
					$result = $result[ $this->identity ] ?? null; }
			} finally {
				ob_end_clean();
			}
			if ( is_wp_error( $upgrader->skin->result ) ) {
				$result = $upgrader->skin->result;
			}
		} finally {
			remove_filter( $hook, $offer_filter, PHP_INT_MAX );
		}
		if ( $this->download_failure instanceof WP_Error ) {
			$result = $this->download_failure; }
		$expected = $this->expected_version();
		$this->assert_files( $expected, $this->directory );
		$this->assert_activation();
		if ( 'restore' === $this->scenario ) {
			$restore_scheduled = false !== has_action( 'shutdown', array( $upgrader, 'restore_temp_backup' ) );
			if ( ! is_wp_error( $result ) || array( 'ran_wp_release_updater_unverified_install_result' ) !== $result->get_error_codes()
				|| 'WP_Error' !== $native_return || null !== $result->get_error_data() || ! $restore_scheduled || 1 !== $this->download_count() ) {
				throw new RuntimeException( 'Unexpected restoration trigger.' ); }
			$this->report( (string) $result->get_error_code(), $expected );
			echo wp_json_encode(
				array(
					'native_return_type' => $native_return,
					'native_error_data'  => $result->get_error_data(),
					'restore_scheduled'  => $restore_scheduled,
				)
			) . "\n";
			return;
		}
		$refusal = match ( $this->scenario ) {
			'equal', 'older', 'older-override-off' => 'ran_wp_release_updater_unverified_pre_download',
			'repository-race', 'revision-race' => 'ran_booster_native_update_authority_changed',
			'mutable' => 'ran_wp_release_updater_remote_release_changed',
			'assurance' => 'ran_wp_release_updater_acquisition_failed',
			default => null,
		};
		if ( null !== $refusal ) {
			if ( ! is_wp_error( $result ) || array( $refusal ) !== $result->get_error_codes() || 'null' !== $native_return || 0 !== $this->download_count() ) {
				throw new RuntimeException( 'Unexpected scenario-specific refusal or acquisition.' ); }
		} else {
			$bulk = 'plugin' === $this->kind && 'active' === $this->scenario;
			if ( ( $bulk ? ! is_array( $result ) || 'array' !== $native_return : true !== $result || 'bool' !== $native_return ) || 1 !== $this->download_count() ) {
				throw new RuntimeException( 'Unexpected native success or fresh-acquisition count.' ); }
		}
		if ( 'active' === $this->scenario ) {
			$this->report( 'activation_preserved', $expected );
		}
		$this->report( is_wp_error( $result ) ? (string) $result->get_error_code() : 'installed', $expected );
		echo wp_json_encode( array( 'native_return_type' => $native_return ) ) . "\n";
	}

	public function readback(): void {
		global $wpdb;
		$offer  = get_site_transient( 'update_' . ( 'plugin' === $this->kind ? 'plugins' : 'themes' ) );
		$entry  = is_object( $offer ) ? ( $offer->response[ $this->identity ] ?? null ) : null;
		$marker = is_object( $entry ) ? ( $entry->native_release_install_proof ?? null ) : ( is_array( $entry ) ? ( $entry['native_release_install_proof'] ?? null ) : null );
		// Characterize the observed failure as well as the passing lifetime cases.
		$persists = 'restore' === $this->scenario || ( 'plugin' === $this->kind && 'active' === $this->scenario );
		if ( $persists ? $marker !== $this->slug : null !== $entry ) {
			throw new RuntimeException( 'Unexpected fresh-request offer lifetime.' ); }
		$expected = 'restore' === $this->scenario ? '1.0.0' : $this->expected_version();
		$this->assert_files( $expected, $this->directory );
		$this->assert_activation();
		$this->report( $persists ? 'request_offer_persisted' : 'fresh_request_offer_absent', $expected );
		$this->report( 'active' === $this->scenario ? 'activation_preserved' : 'activation_remained_inactive', $expected );
		if ( str_starts_with( $this->scenario, 'kill-' ) ) {
			$this->assert_files( '1.0.0', WP_CONTENT_DIR . '/upgrade-temp-backup/' . ( 'plugin' === $this->kind ? 'plugins/' : 'themes/' ) . $this->slug );
			$this->report( 'old_backup_bytes_remain', '1.0.0' );
		}
		$this->report( 'restore' === $this->scenario ? 'filesystem_restored' : ( str_starts_with( $this->scenario, 'kill-' ) ? 'killed_request_replaced_bytes_no_completion' : 'filesystem_verified' ), $expected );
		// Retire only this exact fixture row after observation; preserve files for inspection.
		if ( 1 !== $wpdb->delete( ran_booster_table_name(), array( 'package' => $this->identity ) ) ) {
			throw new RuntimeException( 'Fixture row cleanup failed.' ); }
	}

	private function expected_version(): string {
		return match ( $this->scenario ) {
			'current', 'automatic', 'active', 'restore', 'kill-current' => '3.0.0',
			'newer', 'kill-newer' => '2.0.0',
			'prerelease' => '2.1.0-rc.1',
			default => '1.0.0',
		};
	}

	private function assert_activation(): void {
		$active = 'plugin' === $this->kind ? is_plugin_active( $this->identity ) : get_stylesheet() === $this->slug;
		if ( ( 'active' === $this->scenario ) !== $active ) {
			throw new RuntimeException( 'Unexpected fixture activation state.' ); }
	}

	private function assert_files( string $version, string $directory ): void {
		$expected = array( $this->metadata => $this->contents( $version ) );
		if ( 'theme' === $this->kind ) {
			$expected['index.php'] = '<?php'; }
		$names = array_keys( $expected );
		sort( $names );
		$actual = scandir( $directory );
		if ( is_link( $directory ) || false === $actual || array_values( array_diff( $actual, array( '.', '..' ) ) ) !== $names ) {
			throw new RuntimeException( 'Unexpected target file inventory.' ); }
		foreach ( $expected as $name => $bytes ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Compare every actual fixture file, including the rollback backup, against independent expected bytes.
			if ( is_link( $directory . '/' . $name ) || file_get_contents( $directory . '/' . $name ) !== $bytes ) {
				throw new RuntimeException( 'Unexpected target file bytes.' ); }
		}
	}

	/** Read the count after WordPress has invoked the HTTP callback. */
	private function reset_downloads(): void {
		$this->downloads = 0; }

	private function download_count(): int {
		return $this->downloads; }

	private function report( string $outcome, string $version ): void {
		echo wp_json_encode(
			array(
				'source_override_declared' => defined( 'RAN_BOOSTER_ALLOW_PRERELEASE_SOURCES' ) ? constant( 'RAN_BOOSTER_ALLOW_PRERELEASE_SOURCES' ) : null,
				'type'                     => $this->kind,
				'case'                     => $this->scenario,
				'phase'                    => getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_PHASE' ),
				'outcome'                  => $outcome,
				'installed'                => $version,
				'fresh_downloads'          => $this->downloads,
			)
		) . "\n";
	}
}

$ran_booster_native_release_proof = new RAN_Booster_NativeReleaseInstallProof( (string) getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_TYPE' ), (string) getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_CASE' ) );
match ( getenv( 'RAN_NATIVE_RELEASE_INSTALL_PROOF_PHASE' ) ) {
	'seed' => $ran_booster_native_release_proof->seed(),
	'run' => $ran_booster_native_release_proof->run(),
	'readback' => $ran_booster_native_release_proof->readback(),
	default => throw new RuntimeException( 'Unknown proof phase.' ),
};
