<?php

// Runs through WP-CLI against the marked disposable site. The `prove-off`
// action captures the registered native callback, then delegates the Settings
// save to a separate WordPress process before exercising that old callback.

use RAN\Admin\GitHubVisibility;
use RAN\Settings\GeneralPreferenceStore;
use RAN\WordPress\CoreSelfUpdatePolicy;
use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;

/** @var list<string> $args */
$ran_booster_admission_race_args = $args;

final class RAN_Booster_Core_Self_Update_Admission_Race {

	private const CORE = 'ran-booster/ran-booster.php';

	private string $action;
	private string $php;
	private string $wp;

	/** @param list<string> $args */
	public function __construct( array $args ) {
		$this->action = $args[0] ?? '';
		$php          = getenv( 'PHP_BIN' );
		$wp           = getenv( 'WP_CLI_BIN' );
		$this->php    = is_string( $php ) && '' !== $php ? $php : PHP_BINARY;
		$this->wp     = is_string( $wp ) && '' !== $wp ? $wp : 'wp';
	}

	public function run(): void {
		if ( ! in_array( $this->action, array( 'save-off', 'save-on', 'prove-off', 'prove-on' ), true ) ) {
			throw new RuntimeException( 'Expected a Core self-update admission race action.' );
		}
		$this->assert_fixture();
		if ( str_starts_with( $this->action, 'save-' ) ) {
			$this->assert_candidate();
			$this->save( 'save-on' === $this->action );
			return;
		}
		$this->assert_candidate();
		$callback = $this->core_callback();
		if ( 'prove-off' === $this->action ) {
			$this->prove_old_callback_is_fenced( $callback );
			return;
		}
		$this->prove_new_callback_discovers_empty_feed( $callback );
	}

	private function assert_fixture(): void {
		$marker = ABSPATH . '.ran-booster-disposable-test-site';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The exact local marker establishes the disposable fixture boundary.
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || is_multisite() || ! is_file( $marker ) || is_link( $marker ) || "RAN Booster disposable test site\n" !== file_get_contents( $marker ) || ! defined( 'WP_CONTENT_DIR' ) || ! defined( 'WP_PLUGIN_DIR' ) || realpath( WP_CONTENT_DIR ) !== realpath( ABSPATH ) . '/wp-content' || realpath( WP_PLUGIN_DIR ) !== realpath( WP_CONTENT_DIR ) . '/plugins' || is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR ) || ! is_plugin_active( self::CORE ) ) {
			throw new RuntimeException( 'The marked active disposable Core fixture is required.' );
		}
		if ( defined( 'RAN_BOOSTER_SELF_UPDATE_MODE' ) ) {
			throw new RuntimeException( 'The admission race must not bypass Settings with a Core mode constant.' );
		}
	}

	private function assert_candidate(): void {
		$directory = WP_PLUGIN_DIR . '/ran-booster';
		$plugin    = $directory . '/ran-booster.php';
		$marker    = $directory . '/ran-booster-release.json';
		$source    = array( $directory . '/.git', $directory . '/composer.json' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validates only the physical disposable release marker.
		$release = is_file( $marker ) && ! is_link( $marker ) ? json_decode( (string) file_get_contents( $marker ), true ) : null;
		$headers = get_file_data(
			$plugin,
			array(
				'Version'   => 'Version',
				'UpdateURI' => 'Update URI',
			),
			'plugin'
		);
		if ( is_array( $release ) ) {
			$keys = array_keys( $release );
			sort( $keys );
		} else {
			$keys = array();
		}
		if ( is_link( $directory ) || is_link( $plugin ) || ! is_dir( $directory ) || ! is_file( $plugin ) || ! is_file( $marker ) || is_link( $marker ) || ! is_array( $release ) || array( 'commit', 'schema', 'schema_version', 'version' ) !== $keys || 'ran-booster-core-release' !== ( $release['schema'] ?? null ) || 1 !== ( $release['schema_version'] ?? null ) || ! is_string( $release['version'] ?? null ) || ! is_string( $headers['Version'] ?? null ) || $release['version'] !== $headers['Version'] || ! is_string( $release['commit'] ?? null ) || 1 !== preg_match( '/\A[0-9a-f]{40}\z/D', $release['commit'] ) || ! is_string( $headers['UpdateURI'] ?? null ) || 'https://github.com/RocketsAreNostalgic/ran-booster' !== $headers['UpdateURI'] ) {
			throw new RuntimeException( 'The physical Core candidate is not a valid non-Git updater target.' );
		}
		foreach ( $source as $path ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				throw new RuntimeException( 'The physical Core candidate retained a source-tree marker.' );
			}
		}
	}

	private function save( bool $enabled ): void {
		$preferences = new GeneralPreferenceStore();
		$visibility  = new GitHubVisibility( $preferences );
		$snapshot    = $preferences->view_snapshot();
		$submitted   = array();
		foreach ( $snapshot['fields'] as $key => $field ) {
			if ( ! $field['locked'] && 'hide_github' !== $key && is_bool( $field['value'] ?? null ) ) {
				$submitted[ $key ] = 'self_update_discovery' === $key ? ( $enabled ? '1' : '0' ) : ( $field['value'] ? '1' : '0' );
			} elseif ( ! $field['locked'] && 'hide_github' !== $key && is_int( $field['value'] ?? null ) ) {
				$submitted[ $key ] = (string) $field['value'];
			}
		}
		$result    = $preferences->save( $snapshot['token'], $submitted, $visibility->with_management_admission_lock( ... ), $visibility->github_hide_eligible( ... ) );
		$file      = WP_PLUGIN_DIR . '/' . self::CORE;
		$version   = (string) ( get_file_data( $file, array( 'Version' => 'Version' ), 'plugin' )['Version'] ?? '' );
		$effective = CoreSelfUpdatePolicy::detect( $file, $version, true )->allows_native_discovery();
		if ( ! in_array( $result, array( 'saved', 'unchanged' ), true ) || '' === $version || ( $enabled && true !== $effective ) || ( ! $enabled && false !== $effective ) ) {
			throw new RuntimeException( 'The separate Settings process did not persist the requested Core discovery preference.' );
		}
	}

	private function prove_old_callback_is_fenced( Closure $callback ): void {
		$file    = WP_PLUGIN_DIR . '/' . self::CORE;
		$version = (string) ( get_file_data( $file, array( 'Version' => 'Version' ), 'plugin' )['Version'] ?? '' );
		if ( '' === $version || ! CoreSelfUpdatePolicy::detect( $file, $version, true )->allows_native_discovery() ) {
			throw new RuntimeException( 'The old callback was not bootstrapped while Core discovery was enabled.' );
		}
		$this->run_child( 'save-off' );
		$attempts  = 0;
		$feeds     = 0;
		$transport = $this->empty_release_transport( $attempts, $feeds );
		add_filter( 'pre_http_request', $transport, PHP_INT_MIN, 3 );
		try {
			$headers      = get_file_data(
				WP_PLUGIN_DIR . '/' . self::CORE,
				array(
					'Version'   => 'Version',
					'UpdateURI' => 'Update URI',
				),
				'plugin'
			);
			$unrelated    = new stdClass();
			$pass_through = $callback( $unrelated, $headers, 'unrelated/unrelated.php', array( 'en_GB' ) );
			$fenced       = $callback( false, $headers, self::CORE, array( 'en_GB' ) );
		} finally {
			remove_filter( 'pre_http_request', $transport, PHP_INT_MIN );
		}
		if ( $unrelated !== $pass_through || false !== $fenced || 0 !== $attempts || 0 !== $feeds ) {
			throw new RuntimeException( 'A saved-off Core discovery request reached the feed or changed an unrelated package; attempts=' . $attempts . ', feeds=' . $feeds . '.' );
		}
	}

	private function prove_new_callback_discovers_empty_feed( Closure $callback ): void {
		$attempts     = 0;
		$feeds        = 0;
		$started      = array();
		$observations = array();
		$transport    = $this->empty_release_transport( $attempts, $feeds );
		$start        = static function ( array $payload ) use ( &$started ): void {
			$started[] = $payload;
		};
		$observe      = static function ( array $payload ) use ( &$observations ): void {
			$observations[] = $payload;
		};
		add_filter( 'pre_http_request', $transport, PHP_INT_MIN, 3 );
		add_action( 'ran_wp_release_updater_check_started_v1', $start );
		add_action( 'ran_wp_release_updater_check_completed_v1', $observe );
		try {
			$headers = get_file_data(
				WP_PLUGIN_DIR . '/' . self::CORE,
				array(
					'Version'   => 'Version',
					'UpdateURI' => 'Update URI',
				),
				'plugin'
			);
			$offer   = $callback( false, $headers, self::CORE, array( 'en_GB' ) );
		} finally {
			remove_filter( 'pre_http_request', $transport, PHP_INT_MIN );
			remove_action( 'ran_wp_release_updater_check_started_v1', $start );
			remove_action( 'ran_wp_release_updater_check_completed_v1', $observe );
		}
		$start       = $started[0] ?? null;
		$observation = $observations[0] ?? null;
		if ( false !== $offer || 1 !== $attempts || 1 !== $feeds || 1 !== count( $started ) || 1 !== count( $observations ) || ! is_array( $start ) || ! is_array( $observation ) || self::CORE !== ( $start['installed_identity'] ?? null ) || 'plugin' !== ( $start['target_type'] ?? null ) || 'github' !== ( $start['provider_code'] ?? null ) || '1319710173' !== ( $start['repository_identity'] ?? null ) || 'prerelease' !== ( $start['channel'] ?? null ) || ! is_string( $start['check_id'] ?? null ) || ( $observation['check_id'] ?? null ) !== $start['check_id'] || 'succeeded' !== ( $observation['status'] ?? null ) || 'no_candidates' !== ( $observation['reason'] ?? null ) || null !== ( $observation['offered_version'] ?? null ) ) {
			throw new RuntimeException( 'The enabled Core callback did not complete one empty-feed no-candidates discovery.' );
		}
	}

	/** @return Closure(mixed,array<string,mixed>,string,list<string>):mixed */
	private function core_callback(): Closure {
		global $wp_filter;
		$callbacks = $wp_filter['update_plugins_github.com']->callbacks ?? array();
		foreach ( $callbacks as $entries ) {
			foreach ( $entries as $entry ) {
				$candidate = $entry['function'] ?? null;
				$updater   = is_array( $candidate ) ? ( $candidate[0] ?? null ) : null;
				if ( ! $updater instanceof NativePackageUpdater || 'filter_update' !== ( $candidate[1] ?? null ) ) {
					continue;
				}
				$identity = ( new ReflectionProperty( NativePackageUpdater::class, 'installed_identity' ) )->getValue( $updater );
				if ( self::CORE === $identity ) {
					return static function ( mixed $update, array $headers, string $identity, array $locales ) use ( $updater ): mixed {
						$list = array();
						foreach ( $locales as $locale ) {
							if ( ! is_string( $locale ) ) {
								throw new RuntimeException( 'The native Core callback requires string locales.' );
							}
							$list[] = $locale;
						}
						try {
							return $updater->filter_update( $update, $headers, $identity, $list );
						} finally {
							// WP-CLI eval-file exits without the normal WordPress shutdown lifecycle; use the updater's public finalizer only after the callback observation completes.
							$updater->finalize_pending_install();
						}
					};
				}
			}
		}
		throw new RuntimeException( 'The active Core fixture did not register its own native GitHub callback.' );
	}

	/** @return Closure(mixed,array<string,mixed>,string):mixed */
	private function empty_release_transport( int &$attempts, int &$feeds ): Closure {
		return static function ( mixed $preempt, array $arguments, string $url ) use ( &$attempts, &$feeds ): mixed {
			unset( $preempt, $arguments );
			++$attempts;
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? null ) || 'api.github.com' !== ( $parts['host'] ?? null ) || '/repos/RocketsAreNostalgic/ran-booster/releases' !== ( $parts['path'] ?? null ) ) {
				return new WP_Error( 'ran_booster_admission_race_network_veto', 'Only the local empty release fixture is allowed.' );
			}
			++$feeds;
			return array(
				'body'     => '[]',
				'headers'  => array(),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};
	}

	private function run_child( string $action ): void {
		if ( '' === $this->php || '' === $this->wp ) {
			throw new RuntimeException( 'The separate WordPress process command is unavailable.' );
		}
		$command = array( $this->php, $this->wp, 'eval-file', __FILE__, $action, '--path=' . ABSPATH );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The maintained WP-CLI proof requires a separate WordPress database connection.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'The separate WordPress Settings process could not start.' );
		}
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes only owned WP-CLI process pipes.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes only owned WP-CLI process pipes.
		fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) {
			throw new RuntimeException( 'The separate WordPress Settings process failed: ' . substr( $output, 0, 500 ) );
		}
	}
}

( new RAN_Booster_Core_Self_Update_Admission_Race( $ran_booster_admission_race_args ) )->run();
