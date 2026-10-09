<?php

// This standalone, opt-in harness must create and destroy its isolated filesystem and MySQL fixture without WordPress runtime helpers.

/*
 * Deliberately opt-in, local-only release proof.  The parent process creates a
 * fresh private WordPress/MySQL fixture; worker requests are WP-CLI processes
 * inside that fixture.  It never points at the Local site or its database.
 */

const RAN_BOOSTER_P44_MARKER = 'RAN_BOOSTER_PHASE44_DISPOSABLE';

if ( '1' === getenv( 'RAN_BOOSTER_PHASE44_WORKER' ) ) {
	ran_booster_phase44_worker();
	exit( 0 );
}

ran_booster_phase44_launcher();

function ran_booster_phase44_launcher(): void {
	$root                    = realpath( dirname( __DIR__, 2 ) );
	$wp_environment          = getenv( 'RAN_BOOSTER_PHASE44_WORDPRESS_ROOT' );
	$wp                      = $wp_environment ? $wp_environment : dirname( (string) $root, 3 );
	$wp                      = is_string( $wp ) ? realpath( $wp ) : false;
	$zip                     = is_string( $root ) ? $root . '/build/ran-booster-1.0.0-beta.27.zip' : '';
	$artifact_sha256         = getenv( 'RAN_BOOSTER_PHASE44_ARTIFACT_SHA256' );
	$artifact_source_commit  = getenv( 'RAN_BOOSTER_PHASE44_ARTIFACT_SOURCE_COMMIT' );
	$php                     = ran_booster_phase44_php82();
	$wp_cli                  = '/usr/local/bin/wp';
	$mysqld_environment      = getenv( 'RAN_BOOSTER_PHASE44_MYSQLD' );
	$mysqld                  = $mysqld_environment ? $mysqld_environment : '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.4.0/bin/darwin-arm64/bin/mysqld';
	$artifact_contract_valid = is_string( $artifact_sha256 )
		&& is_string( $artifact_source_commit )
		&& 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $artifact_sha256 )
		&& 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $artifact_source_commit )
		&& is_file( $zip )
		&& ran_booster_phase44_exact_artifact( $zip, $artifact_sha256, $artifact_source_commit );
	if ( ! is_string( $root ) || ! is_string( $wp ) || ! is_file( $wp . '/wp-load.php' ) || is_link( $wp ) || ! $artifact_contract_valid || ! is_file( $php ) || ! is_executable( $php ) || ! is_file( $wp_cli ) || ! is_file( $mysqld ) || ! is_executable( $mysqld ) ) {
		throw new RuntimeException( 'Phase 4.4 requires exact artifact SHA-256 and source-commit environment contracts, local WordPress 7.0.4, PHP 8.2, WP-CLI, mysqld and the built Core ZIP.' );
	}
	if ( '7.0.4' !== trim( ran_booster_phase44_command( array( $php, $wp_cli, '--path=' . $wp, 'core', 'version' ), $wp )['stdout'] ) ) {
		throw new RuntimeException( 'Phase 4.4 refuses a WordPress source other than 7.0.4.' );
	}
	$base = '/private/tmp/ran-booster-phase44-' . bin2hex( random_bytes( 16 ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	if ( '/private/tmp' !== realpath( dirname( $base ) ) || file_exists( $base ) || is_link( $base ) || ! mkdir( $base, 0700 ) ) {
		throw new RuntimeException( 'Phase 4.4 could not establish a private disposable root.' );
	}
	$marker = $base . '/' . RAN_BOOSTER_P44_MARKER . '.marker';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	file_put_contents( $marker, RAN_BOOSTER_P44_MARKER . "\n" );
	$server = null;
	try {
		$mysql  = $base . '/mysql';
		$data   = $mysql . '/data';
		$socket = $mysql . '/mysql.sock';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		mkdir( $mysql, 0700, true );
		ran_booster_phase44_command( array( $mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $data ), $mysql );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Standalone proof owns an isolated child process and its pipes; no WordPress process wrapper exists.
		$server = proc_open(
			array( $mysqld, '--no-defaults', '--datadir=' . $data, '--socket=' . $socket, '--pid-file=' . $mysql . '/mysqld.pid', '--skip-networking', '--log-error=' . $mysql . '/mysqld.err' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'file', $mysql . '/mysqld.out', 'a' ),
				2 => array( 'file', $mysql . '/mysqld.err', 'a' ),
			),
			$pipes,
			$mysql
		);
		if ( ! is_resource( $server ) ) {
			throw new RuntimeException( 'Phase 4.4 could not start its isolated mysqld.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		fclose( $pipes[0] );
		ran_booster_phase44_mysql_ready( $socket );
		$db = 'ran_booster_p44_' . random_int( 100000, 999999 );
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- Standalone proof controls its isolated MySQL process before WordPress bootstrap.
		$mysqli = mysqli_init();
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect -- Standalone proof connects only to its private test socket before WordPress bootstrap.
		mysqli_real_connect( $mysqli, null, 'root', '', null, 0, $socket );
		$mysqli->query( 'CREATE DATABASE `' . $db . '`' );
		$mysqli->close();
		$site = $base . '/site';
		ran_booster_phase44_copy_tree( $wp, $site, array( 'wp-content', 'wp-config.php', '.git' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		mkdir( $site . '/wp-content/plugins', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		mkdir( $site . '/wp-content/themes', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		mkdir( $site . '/wp-content/uploads', 0700, true );
		ran_booster_phase44_extract_core( $zip, $site . '/wp-content/plugins' );
		ran_booster_phase44_copy_tree( $root . '/tests/fixtures/ran-booster-release-capability-provider', $site . '/wp-content/plugins/ran-booster-release-capability-provider' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		file_put_contents( $site . '/.ran-booster-disposable-test-site', "RAN Booster disposable test site\n" );
		ran_booster_phase44_config( $site . '/wp-config.php', $db, $socket );
		$env = array(
			'RAN_BOOSTER_PHASE44_ROOT'   => $root,
			'RAN_BOOSTER_PHASE44_MARKER' => $marker,
			'RAN_BOOSTER_PHASE44_SITE'   => $site,
			'RAN_BOOSTER_PHASE44_WORKER' => '1',
		);
		ran_booster_phase44_command( array( $php, $wp_cli, '--path=' . $site, 'core', 'install', '--skip-email', '--url=http://phase44.invalid', '--title=phase44', '--admin_user=admin', '--admin_password=phase44-password', '--admin_email=admin@example.invalid' ), $site, $env );
		ran_booster_phase44_command( array( $php, $wp_cli, '--path=' . $site, 'plugin', 'activate', 'ran-booster' ), $site, $env );
		ran_booster_phase44_command( array( $php, $wp_cli, '--path=' . $site, 'plugin', 'activate', 'ran-booster-release-capability-provider' ), $site, $env );
		$phases = array();
		foreach ( array( 'manual', 'automatic' ) as $policy ) {
			foreach ( array( 'plugin', 'theme' ) as $type ) {
				foreach ( array( 'success', 'failure' ) as $mode ) {
								$phases[] = array( 'native', $policy, $type, $mode );
				}
			}
		}
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( 'failure', 'success' ) as $mode ) {
				$phases[] = array( 'prospective', 'manual', $type, $mode );
			}
		}
		$results = array();
		foreach ( $phases as $phase ) {
			$run_env = $env + array(
				'RAN_BOOSTER_PHASE44_KIND'   => $phase[0],
				'RAN_BOOSTER_PHASE44_POLICY' => $phase[1],
				'RAN_BOOSTER_PHASE44_TYPE'   => $phase[2],
				'RAN_BOOSTER_PHASE44_MODE'   => $phase[3],
			);
			$out     = ran_booster_phase44_command( array( $php, $wp_cli, '--path=' . $site, 'eval-file', __FILE__, '--user=admin' ), $site, $run_env );
			$proof   = json_decode( trim( $out['stdout'] ), true, 32, JSON_THROW_ON_ERROR );
			if ( true !== ( $proof['pass'] ?? null ) ) {
				throw new RuntimeException( 'Phase 4.4 proof failed: ' . implode( ':', $phase ) );
			}
			if ( 'native' === $phase[0] && 'failure' === $phase[3] ) {
				$identity = 'plugin' === $phase[2] ? 'phase44-plugin/phase44-plugin.php' : 'phase44-theme';
				$read     = ran_booster_phase44_command( array( $php, $wp_cli, '--path=' . $site, 'eval', 'echo wp_json_encode(array("version"=>"plugin"==="' . $phase[2] . '"?get_plugin_data(WP_PLUGIN_DIR . "/' . $identity . '",false,false)["Version"]:get_file_data(get_theme_root() . "/' . $identity . '/style.css",array("Version"=>"Version"),"theme")["Version"],"digest"=>hash_file("sha256","plugin"==="' . $phase[2] . '"?WP_PLUGIN_DIR . "/' . $identity . '":get_theme_root() . "/' . $identity . '/style.css"),"backup"=>is_dir(WP_CONTENT_DIR . "/upgrade-temp-backup/" . ("plugin"==="' . $phase[2] . '"?"plugins/phase44-plugin":"themes/phase44-theme")),"maintenance"=>is_file(ABSPATH . ".maintenance")));', '--user=admin' ), $site, $env );
				$post     = json_decode( trim( $read['stdout'] ), true, 16, JSON_THROW_ON_ERROR );
				if ( '1.0.0' !== ( $post['version'] ?? null ) || ( $proof['before_digest'] ?? null ) !== ( $post['digest'] ?? null ) || true === ( $post['backup'] ?? null ) || true === ( $post['maintenance'] ?? null ) ) {
					throw new RuntimeException( 'Post-shutdown rollback readback failed.' );
				}
				$proof['post_shutdown'] = $post;
			}
				$results[ implode( ':', $phase ) ] = $proof;
		}
		$readback = ran_booster_phase44_command( array( $php, $wp_cli, '--path=' . $site, 'eval', 'echo wp_json_encode(array("active"=>is_plugin_active("ran-booster/ran-booster.php"),"version"=>get_plugin_data(WP_PLUGIN_DIR . "/ran-booster/ran-booster.php", false, false)["Version"]));', '--user=admin' ), $site, $env );
		$body     = json_decode( trim( $readback['stdout'] ), true, 16, JSON_THROW_ON_ERROR );
		if ( true !== ( $body['active'] ?? null ) || '1.0.0-beta.27' !== ( $body['version'] ?? null ) ) {
			throw new RuntimeException( 'Installed Core CLI readback failed.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixture serializes exact test protocol bytes independently of WordPress JSON filters.
		echo json_encode(
			array(
				'result'   => 'PASS',
				'phases'   => array_keys( $results ),
				'readback' => $body,
			),
			JSON_UNESCAPED_SLASHES
		) . PHP_EOL;
	} finally {
		if ( is_resource( $server ) ) {
			proc_terminate( $server, 15 );
			usleep( 500000 );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup tolerates an already-removed disposable fixture or stopped child process.
			@proc_close( $server ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		if ( ! is_link( $base ) && realpath( $base ) === $base && '/private/tmp' === realpath( dirname( $base ) ) && is_file( $marker ) && RAN_BOOSTER_P44_MARKER . "\n" === file_get_contents( $marker ) ) { // @phpstan-ignore booleanNot.alwaysTrue (Cleanup must recheck the disposable root against concurrent symlink replacement before deleting it.)
			ran_booster_phase44_remove_tree( $base );
		}
	}
}

/**
 * The artifact source is deliberately supplied separately from this proof's
 * checkout HEAD: test-only evidence may follow the built release artifact.
 */
function ran_booster_phase44_exact_artifact( string $zip, string $expected_sha256, string $expected_source_commit ): bool {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_hash_file -- The caller supplies the exact immutable artifact digest.
	$actual_sha256 = hash_file( 'sha256', $zip );
	if ( ! is_string( $actual_sha256 ) || ! hash_equals( $expected_sha256, $actual_sha256 ) ) {
		return false;
	}
	$archive = new ZipArchive();
	if ( true !== $archive->open( $zip, ZipArchive::RDONLY ) ) {
		return false;
	}
	try {
		$marker = $archive->getFromName( 'ran-booster/ran-booster-release.json' );
	} finally {
		$archive->close();
	}
	try {
		$document = is_string( $marker ) ? json_decode( $marker, true, 16, JSON_THROW_ON_ERROR ) : null;
	} catch ( JsonException ) {
		return false;
	}

	return array(
		'schema'         => 'ran-booster-core-release',
		'schema_version' => 1,
		'version'        => '1.0.0-beta.27',
		'commit'         => $expected_source_commit,
	) === $document;
}

function ran_booster_phase44_worker(): void {
	$root   = getenv( 'RAN_BOOSTER_PHASE44_ROOT' );
	$site   = getenv( 'RAN_BOOSTER_PHASE44_SITE' );
	$marker = getenv( 'RAN_BOOSTER_PHASE44_MARKER' );
	$kind   = getenv( 'RAN_BOOSTER_PHASE44_KIND' );
	$policy = getenv( 'RAN_BOOSTER_PHASE44_POLICY' );
	$type   = getenv( 'RAN_BOOSTER_PHASE44_TYPE' );
	$mode   = getenv( 'RAN_BOOSTER_PHASE44_MODE' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	if ( ! is_string( $root ) || ! is_string( $site ) || ! is_string( $marker ) || ! is_file( $marker ) || RAN_BOOSTER_P44_MARKER . "\n" !== file_get_contents( $marker ) || realpath( ABSPATH ) !== realpath( $site ) || ! in_array( $kind, array( 'native', 'prospective' ), true ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! in_array( $mode, array( 'success', 'failure' ), true ) ) {
		throw new RuntimeException( 'Phase 4.4 worker guard rejected its environment.' );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
	$origin = WP_PLUGIN_DIR . '/ran-booster';
	foreach ( array( RAN\Booster::class, RAN\WordPress\CorePackageExecutor::class, RAN\Deployment\PreparedArtifact::class, RAN\AddOn\ReleaseTracking\NativeProspectiveReleaseFacade::class, RAN\BoosterGitHubProvider\V1\GitHubReleaseNativeTarget::class ) as $class ) {
		$file = ( new ReflectionClass( $class ) )->getFileName();
		if ( ! is_string( $file ) || ! str_starts_with( $file, $origin . '/' ) ) {
			throw new RuntimeException( 'A Core class did not originate in the installed release.' );
		}
	}
	$mail               = 0;
	$automatic_complete = 0;
	add_action(
		'automatic_updates_complete',
		static function () use ( &$automatic_complete ): void {
			++$automatic_complete;
		},
		PHP_INT_MIN
	);
	add_filter( // @phpstan-ignore arguments.count (WordPress supplies the documented hook arguments; this controlled callback deliberately consumes only the needed subset.)
		'pre_wp_mail',
		static function () use ( &$mail ): bool {
			++$mail;
			return true;
		},
		PHP_INT_MIN,
		2
	);
	$http = array(
		'allowed'           => 0,
		'expected_blocked'  => array(),
		'unmatched_blocked' => array(),
	);
	add_filter(
		'pre_http_request',
		static function ( mixed $pre, array $args, string $url ) use ( &$http ): mixed {
			unset( $pre );
			if ( 'https://phase44-network-guard.invalid/' === $url ) {
				return new WP_Error( 'phase44_network_forbidden' );
			}
			/** @var array{archive: string, mode: string, type: string}|null $fixture Populated only by this worker's native phase. */
			$fixture  = $GLOBALS['ran_booster_phase44_github_fixture'] ?? null;
			$response = is_array( $fixture ) ? ran_booster_phase44_github_response( $fixture, $args, $url ) : null;
			if ( is_array( $response ) ) {
				++$http['allowed'];
				return $response;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Controlled HTTP fixture parses exact native URL components; retain native return and failure semantics.
			$parts          = parse_url( $url );
			$entry          = array(
				'method' => is_string( $args['method'] ?? null ) ? $args['method'] : 'unknown',
				'origin' => is_array( $parts ) ? (string) ( ( $parts['scheme'] ?? '' ) . '://' . ( $parts['host'] ?? '' ) ) : 'invalid',
				'path'   => is_array( $parts ) && is_string( $parts['path'] ?? null ) ? $parts['path'] : '',
			);
			$expected_paths = array( '/core/version-check/1.7/', '/plugins/update-check/1.1/', '/themes/update-check/1.1/' );
			if ( 'POST' === $entry['method'] && in_array( $entry['origin'], array( 'https://api.wordpress.org', 'http://api.wordpress.org' ), true ) && in_array( $entry['path'], $expected_paths, true ) ) {
				$http['expected_blocked'][] = $entry;
			} elseif ( count( $http['unmatched_blocked'] ) < 16 ) {
				$http['unmatched_blocked'][] = $entry;
			}
			return new WP_Error( 'phase44_network_forbidden' );
		},
		PHP_INT_MIN,
		3
	);
	if ( ! is_wp_error( wp_remote_get( 'https://phase44-network-guard.invalid/' ) ) ) {
		throw new RuntimeException( 'Network guard failed closed.' );
	}
	if ( 'prospective' === $kind ) {
		$proof = ran_booster_phase44_prospective( $root, $site, $type, $mode );
	} else {
		$proof = ran_booster_phase44_native( $site, $type, $policy, $mode ); }
	$expected_mail      = 'native' === $kind && 'automatic' === $policy ? 1 : 0;
	$expected_count     = 'native' === $kind ? count( $http['expected_blocked'] ) : 0;
	$expected_automatic = 'native' === $kind && 'automatic' === $policy ? 1 : 0;
	if ( ( 'native' === $kind && ( $expected_count < 1 || $expected_count > 16 ) ) || ( 'prospective' === $kind && 0 !== $expected_count ) || array() !== $http['unmatched_blocked'] || $expected_mail !== $mail || $expected_automatic !== $automatic_complete ) {
		throw new RuntimeException(
			'The disposable worker guard counts are unexpected: '
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixture serializes exact test protocol bytes independently of WordPress JSON filters.
			. json_encode(
				array(
					'kind'           => $kind,
					'policy'         => $policy,
					'type'           => $type,
					'mode'           => $mode,
					'http'           => $http,
					'expected_count' => $expected_count,
					'mail'           => $mail,
					'expected_mail'  => $expected_mail,
				),
				JSON_THROW_ON_ERROR
			)
		);
	}
	$proof['pass']                       = true;
	$proof['origin']                     = $origin;
	$proof['guards']                     = array(
		'http' => $http,
		'mail' => $mail,
	);
	$proof['automatic_updates_complete'] = $automatic_complete;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixture serializes exact test protocol bytes independently of WordPress JSON filters.
	echo json_encode( $proof, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
}

/** @return array<string, mixed> */
function ran_booster_phase44_native( string $site, string $type, string $policy, string $mode ): array {
	$slug = 'phase44-' . $type;
	$id   = 'plugin' === $type ? $slug . '/' . $slug . '.php' : $slug;
	$uri  = 'https://github.com/phase44-owner/' . $slug;
	ran_booster_phase44_fixture( $site, $type, $slug, '1.0.0', $uri );
	$before_digest                                 = hash_file( 'sha256', 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $id : get_theme_root() . '/' . $id . '/style.css' );
	$archive                                       = ran_booster_phase44_archive( $site, $type, $slug, '2.0.0', $uri );
	$GLOBALS['ran_booster_phase44_github_fixture'] = array(
		'archive' => $archive,
		'mode'    => $mode,
		'type'    => $type,
	);
	$target                                        = new RAN\BoosterGitHubProvider\V1\GitHubReleaseNativeTarget( $type, 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $id : get_theme_root() . '/' . $id . '/style.css', 'phase44-owner/' . $slug, '101', $slug, $id, static fn (): string => 'phase44-token', 'stable', $policy ); // @phpstan-ignore argument.type, argument.type (Historical beta27 artifact proof deliberately uses that artifact's native-target constructor; current Composer provider has a later contract.)
	if ( ! $target->register() ) {
		throw new RuntimeException( 'Installed Core target registration failed.' );
	}
	$headers = 'plugin' === $type ? get_plugin_data( WP_PLUGIN_DIR . '/' . $id, false, false ) : get_file_data(
		get_theme_root() . '/' . $id . '/style.css',
		array(
			'Name'      => 'Theme Name',
			'Version'   => 'Version',
			'UpdateURI' => 'Update URI',
		),
		'theme'
	);
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The native WordPress Update URI hostname hook deliberately contains github.com. Exercise the WordPress-owned lifecycle hook consumed by the runtime.
	$offer = apply_filters( 'update_' . ( 'plugin' === $type ? 'plugins_' : 'themes_' ) . 'github.com', false, $headers, $id, array() );
	if ( ! is_array( $offer ) || '2.0.0' !== ( $offer['version'] ?? null ) || ! is_string( $offer['package'] ?? null ) || ! str_starts_with( $offer['package'], 'ran-wp-release-updater:v1:' ) ) {
		throw new RuntimeException( 'Installed Core target did not publish the exact fixture offer.' );
	}
	$offer['new_version'] = $offer['version'];
	$transient            = (object) array(
		'last_checked' => time(),
		'response'     => array(),
		'checked'      => array(),
	);
	if ( 'plugin' === $type ) {
		foreach ( get_plugins() as $file => $plugin ) {
			$transient->checked[ $file ] = $plugin['Version'];
		} $offer['plugin']          = $id;
		$transient->response[ $id ] = (object) $offer;
		set_site_transient( 'update_plugins', $transient, 60 );
	} else {
		foreach ( wp_get_themes() as $name => $theme ) {
			$transient->checked[ $name ] = $theme->get( 'Version' );
		} $offer['theme']           = $id;
		$offer['slug']              = $id;
		$transient->response[ $id ] = $offer;
		set_site_transient( 'update_themes', $transient, 60 ); }
	$rollback = array(
		'post_copy' => false,
		'version'   => null,
		'backup'    => false,
		'hook'      => null,
	);
	if ( 'failure' === $mode ) {
		$fail = static function ( mixed $response, array $extra ) use ( $type, $id, &$rollback ): mixed {
			$key              = 'plugin' === $type ? 'plugin' : 'theme';
			$rollback['hook'] = array(
				'action'   => is_string( $extra['action'] ?? null ) ? $extra['action'] : null,
				'type'     => is_string( $extra['type'] ?? null ) ? $extra['type'] : null,
				'identity' => is_string( $extra[ $key ] ?? null ) ? $extra[ $key ] : null,
			);
			if ( ( $extra[ $key ] ?? null ) !== $id ) {
				return $response;
			}
			$rollback['post_copy'] = true;
			$rollback['version']   = 'plugin' === $type ? ( get_plugin_data( WP_PLUGIN_DIR . '/' . $id, false, false )['Version'] ?? null ) : ( get_file_data( get_theme_root() . '/' . $id . '/style.css', array( 'Version' => 'Version' ), 'theme' )['Version'] ?? null );
			$slug                  = 'plugin' === $type ? dirname( $id ) : $id;
			$rollback['backup']    = is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/' . ( 'plugin' === $type ? 'plugins/' : 'themes/' ) . $slug );
			return new WP_Error( 'phase44_injected_rollback' );
		};
		add_filter( 'upgrader_post_install', $fail, PHP_INT_MAX, 3 ); } // @phpstan-ignore arguments.count (WordPress supplies the documented hook arguments; this controlled callback deliberately consumes only the needed subset.)
	try {
		if ( 'automatic' === $policy ) {
			if ( 'plugin' === $type && is_plugin_active( $id ) ) {
				deactivate_plugins( $id, true );
			} set_site_transient(
				'update_core',
				(object) array(
					'last_checked'    => time(),
					'updates'         => array(),
					'version_checked' => wp_get_wp_version(),
				),
				60
			);
			$automatic = new class() extends WP_Automatic_Updater { public function result_for( string $type, string $identity ): mixed {
					foreach ( $this->update_results[ $type ] ?? array() as $entry ) {
						$key = 'plugin' === $type ? ( $entry->item->plugin ?? null ) : ( $entry->item->theme ?? null );
						if ( $identity === $key ) {
							return $entry->result;
						}
					} return null;
			} };
			$automatic->run();
			$result = $automatic->result_for( $type, $id );
		} else {
			$result = 'plugin' === $type ? ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $id, array( 'clear_update_cache' => false ) ) : ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $id, array( 'clear_update_cache' => false ) ); }
	} finally {
		if ( isset( $fail ) ) {
			remove_filter( 'upgrader_post_install', $fail, PHP_INT_MAX );
		} unset( $GLOBALS['ran_booster_phase44_github_fixture'] );
	}
	$version = 'plugin' === $type ? ( get_plugin_data( WP_PLUGIN_DIR . '/' . $id, false, false )['Version'] ?? '' ) : ( get_file_data( get_theme_root() . '/' . $id . '/style.css', array( 'Version' => 'Version' ), 'theme' )['Version'] ?? '' );
	if ( 'success' === $mode && '2.0.0' !== $version ) {
		throw new RuntimeException( 'Native success did not update the installed fixture.' );
	}
	$backup_path     = WP_CONTENT_DIR . '/upgrade-temp-backup/' . ( 'plugin' === $type ? 'plugins/' : 'themes/' ) . ( 'plugin' === $type ? dirname( $id ) : $id );
	$result_evidence = array(
		'is_wp_error'       => is_wp_error( $result ),
		'code'              => is_wp_error( $result ) ? $result->get_error_code() : null,
		'final_version'     => $version,
		'backup_after'      => is_dir( $backup_path ),
		'maintenance_after' => is_file( ABSPATH . '.maintenance' ),
	);
	if ( 'failure' === $mode && ( ! is_wp_error( $result ) || 'phase44_injected_rollback' !== $result->get_error_code() || true !== $rollback['post_copy'] || '2.0.0' !== $rollback['version'] || true !== $rollback['backup'] ) ) {
		throw new RuntimeException(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixture serializes exact test protocol bytes independently of WordPress JSON filters.
			'Injected native rollback failure evidence: ' . json_encode(
				array(
					'result'   => $result_evidence,
					'rollback' => $rollback,
				),
				JSON_THROW_ON_ERROR
			)
		);
	}
	return array(
		'kind'            => 'native',
		'policy'          => $policy,
		'type'            => $type,
		'mode'            => $mode,
		'version'         => $version,
		'before_digest'   => $before_digest,
		'rollback'        => $rollback,
		'result_evidence' => $result_evidence,
		'result'          => is_wp_error( $result ) ? $result->get_error_code() : null,
	);
}

/**
 * @param array{archive:string,mode:string,type:string} $fixture
 * @param array<string,mixed> $args
 * @return array<string,mixed>|null
 */
function ran_booster_phase44_github_response( array $fixture, array $args, string $url ): ?array {
	$locator = 'phase44-owner/phase44-' . $fixture['type'];
	$tag     = 'v2.0.0';
	$asset   = 'https://api.github.com/repos/' . $locator . '/releases/assets/301';
	$known   = array( 'https://api.github.com/repos/' . $locator . '/releases?per_page=20&page=1', 'https://api.github.com/repositories/101', 'https://api.github.com/repos/' . $locator . '/releases/201', 'https://api.github.com/repos/' . $locator . '/commits/' . $tag, $asset );
	$headers = $args['headers'] ?? null;
	if ( ! in_array( $url, $known, true ) || ! is_array( $headers ) || 'Bearer phase44-token' !== ( $headers['Authorization'] ?? null ) || 'ran-wp-release-updater' !== ( $headers['User-Agent'] ?? null ) || ( $asset === $url ? 'application/octet-stream' : 'application/vnd.github+json' ) !== ( $headers['Accept'] ?? null ) ) {
		return null;
	}
	$release = array(
		'id'           => 201,
		'draft'        => false,
		'prerelease'   => false,
		'immutable'    => true,
		'html_url'     => 'https://github.com/' . $locator . '/releases/tag/' . $tag,
		'published_at' => '2026-08-22T10:00:00Z',
		'tag_name'     => $tag,
		'assets'       => array(
			array(
				'id'     => 301,
				'name'   => basename( $fixture['archive'] ),
				'size'   => filesize( $fixture['archive'] ),
				'state'  => 'uploaded',
				'digest' => 'sha256:' . hash_file( 'sha256', $fixture['archive'] ),
			),
		),
	);
	if ( str_ends_with( $url, 'page=1' ) ) {
		$body = array( $release );
	} elseif ( str_ends_with( $url, '/101' ) ) {
		$body = array( 'id' => 101 );
	} elseif ( str_ends_with( $url, '/201' ) ) {
		$body = $release;
	} elseif ( str_contains( $url, '/commits/' ) ) {
		$body = array( 'sha' => str_repeat( 'a', 40 ) );
	} elseif ( true === ( $args['stream'] ?? false ) && is_string( $args['filename'] ?? null ) && copy( $fixture['archive'], $args['filename'] ) ) {
		$body = null;
	} else {
		return null;
	}
	return array(
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixture serializes exact test protocol bytes independently of WordPress JSON filters.
		'body'     => null === $body ? '' : json_encode( $body, JSON_THROW_ON_ERROR ),
		'headers'  => array(),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'filename' => $args['filename'] ?? null,
	);
}

/** @return array<string, mixed> */
function ran_booster_phase44_prospective( string $root, string $site, string $type, string $mode ): array {
	$package_root = 'ran-booster-p2-fixture-' . $type;
	$archive      = ran_booster_phase44_archive( $site, $type, $package_root, '2.0.0', 'https://p2.invalid/fixtures/' . $type );
	update_option( 'ran_booster_p2_' . $type . '_archive', $archive, false );
	$container = require $root . '/tests/WordPress/core-container-fixture.php';
	$facade    = $container->make( RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade::class );
	$request   = array(
		'provider'      => 'p2-release',
		'repository'    => 'fixtures/' . $type,
		'credential_id' => '',
		'branch'        => 'main',
	);
	$list      = $facade->list_candidates( $type, $request, 'stable', wp_create_nonce( $facade->nonce_action( 'list_candidates', $type ) ) );
	if ( ! $list->successful() ) {
		throw new RuntimeException( 'Prospective listing failed.' );
	}
	$inspection = $facade->inspect( $type, $request, '42', 'v2.0.0', 'stable', wp_create_nonce( $facade->nonce_action( 'inspect', $type ) ) );
	if ( ! $inspection->successful() ) {
		throw new RuntimeException( 'Prospective inspection failed.' );
	}
	$fingerprint = $inspection->data()['fingerprint'] ?? '';
	if ( ! is_string( $fingerprint ) ) {
		throw new RuntimeException( 'Prospective fingerprint is unavailable.' );
	}
	$source = array(
		'fired'   => false,
		'regular' => false,
		'root'    => '',
	);
	if ( 'failure' === $mode ) {
		$veto = static function ( mixed $path, mixed $remote, mixed $upgrader, array $extra ) use ( $package_root, $type, &$source ): mixed {
			unset( $remote, $upgrader );
			$source_root = is_string( $path ) ? basename( untrailingslashit( $path ) ) : '';
			if ( 'install' !== ( $extra['action'] ?? null )
				|| ( $extra['type'] ?? null ) !== $type
				|| ! hash_equals( $package_root, $source_root ) ) {
				return $path;
			}
			$source['fired']   = true;
			$source['regular'] = is_dir( $path ) && ! is_link( $path );
			$source['root']    = $source_root;

			return new WP_Error( 'phase44_prospective_source_veto' );
		};
		add_filter( 'upgrader_source_selection', $veto, 5, 4 );
	}
	try {
		$result = $facade->install( $type, $request, '42', 'v2.0.0', $fingerprint, 'stable', wp_create_nonce( $facade->nonce_action( 'install', $type ) ) );
	} finally {
		if ( isset( $veto ) ) {
			remove_filter( 'upgrader_source_selection', $veto, 5 );
		}
	}
	if ( ( 'success' === $mode && ! $result->successful() ) || ( 'failure' === $mode && ( $result->successful() || ! $source['fired'] || ! $source['regular'] || ! hash_equals( $package_root, $source['root'] ) ) ) ) {
		throw new RuntimeException( 'Prospective result did not match the requested manual scenario.' );
	}
	$artifact = get_option( 'ran_booster_p2_last_artifact', '' );
	if ( is_string( $artifact ) && '' !== $artifact && ( file_exists( $artifact ) || is_link( $artifact ) ) ) {
		throw new RuntimeException( 'Prospective artifact was not cleaned.' );
	}
	$identifier        = 'plugin' === $type ? 'ran-booster-p2-fixture-plugin/ran-booster-p2-fixture-plugin.php' : 'ran-booster-p2-fixture-theme';
	$destination       = 'plugin' === $type ? WP_PLUGIN_DIR . '/ran-booster-p2-fixture-plugin' : get_theme_root() . '/ran-booster-p2-fixture-theme';
	$deployment_policy = null;
	if ( 'success' === $mode ) {
		$repository        = 'plugin' === $type ? $container->make( RAN\Storage\PluginRepository::class ) : $container->make( RAN\Storage\ThemeRepository::class );
		$package           = 'plugin' === $type ? $repository->booster_plugin_from_file( $identifier ) : $repository->booster_theme_from_stylesheet( $identifier );
		$deployment_policy = $package->get_deployment_policy()->value;
		if ( 'manual' !== $deployment_policy ) {
			throw new RuntimeException( 'Prospective adoption did not retain the Manual deployment policy.' );
		}
	} elseif ( file_exists( $destination ) || is_link( $destination ) ) {
		throw new RuntimeException( 'Prospective pre-mutation veto created a destination.' );
	}
	return array(
		'kind'              => 'prospective',
		'policy'            => 'manual',
		'type'              => $type,
		'mode'              => $mode,
		'code'              => $result->code(),
		'artifact_clean'    => true,
		'deployment_policy' => $deployment_policy,
		'source_veto'       => $source,
	);
}

function ran_booster_phase44_fixture( string $site, string $type, string $slug, string $version, string $uri ): void {
	$dir = $site . '/wp-content/' . ( 'plugin' === $type ? 'plugins/' : 'themes/' ) . $slug;
	if ( is_dir( $dir ) ) {
		ran_booster_phase44_remove_tree( $dir );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	} mkdir( $dir, 0700, true );
	if ( 'plugin' === $type ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		file_put_contents( $dir . '/' . $slug . '.php', "<?php\n/*\nPlugin Name: Phase44\nVersion: $version\nUpdate URI: $uri\n*/\n" );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		file_put_contents( $dir . '/style.css', "/*\nTheme Name: Phase44\nVersion: $version\nUpdate URI: $uri\n*/\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		file_put_contents( $dir . '/index.php', '<?php' ); } }
function ran_booster_phase44_archive( string $site, string $type, string $slug, string $version, string $uri ): string {
	$path = $site . '/wp-content/uploads/phase44-' . $type . '-' . bin2hex( random_bytes( 4 ) ) . '.zip';
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Could not create fixture ZIP.' );
	} $name = 'plugin' === $type ? $slug . '.php' : 'style.css';
	$header = 'plugin' === $type ? 'Plugin Name' : 'Theme Name';
	$zip->addFromString( $slug . '/' . $name, ( 'plugin' === $type ? "<?php\n" : '' ) . "/*\n$header: Phase44\nVersion: $version\nUpdate URI: $uri\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n" );
	if ( 'theme' === $type ) {
		$zip->addFromString( $slug . '/index.php', '<?php' );
	} $zip->close();
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	chmod( $path, 0600 );
	return $path; }
function ran_booster_phase44_extract_core( string $zip_path, string $plugins ): void {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		throw new RuntimeException( 'Could not open Core ZIP.' );
	}
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive exposes numFiles; changing its spelling skips the archive safety loop.
	for ( $i = 0; $i < $zip->numFiles; ++$i ) {
		$name = $zip->getNameIndex( $i );
		if ( ! is_string( $name ) || ! str_starts_with( $name, 'ran-booster/' ) || str_contains( $name, '..' ) || str_contains( $name, '\\' ) ) {
			throw new RuntimeException( 'Core ZIP path is unsafe.' );
		}
	} if ( ! $zip->extractTo( $plugins ) ) {
		throw new RuntimeException( 'Could not install Core ZIP.' );
	} $zip->close();
	if ( ! is_file( $plugins . '/ran-booster/ran-booster.php' ) ) {
		throw new RuntimeException( 'Installed Core ZIP is incomplete.' );
	} }
function ran_booster_phase44_config( string $path, string $db, string $socket ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	file_put_contents( $path, "<?php\ndefine('DB_NAME','$db'); define('DB_USER','root'); define('DB_PASSWORD',''); define('DB_HOST','localhost:$socket'); define('DB_CHARSET','utf8'); define('DB_COLLATE',''); define('AUTH_KEY','phase44'); define('SECURE_AUTH_KEY','phase44'); define('LOGGED_IN_KEY','phase44'); define('NONCE_KEY','phase44'); define('AUTH_SALT','phase44'); define('SECURE_AUTH_SALT','phase44'); define('LOGGED_IN_SALT','phase44'); define('NONCE_SALT','phase44'); \$table_prefix='wp_'; define('FS_METHOD','direct'); define('DISABLE_WP_CRON',true); if(!defined('ABSPATH')) define('ABSPATH',dirname(__FILE__).'/'); require_once ABSPATH.'wp-settings.php';\n" ); }
function ran_booster_phase44_php82(): string {
	$from_env = getenv( 'RAN_BOOSTER_PHASE44_PHP82' );
	if ( is_string( $from_env ) && '' !== $from_env ) {
		return $from_env;
	} $found = glob( '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2*/bin/darwin-arm64/bin/php' );
	return is_array( $found ) && isset( $found[0] ) ? $found[0] : ''; }
function ran_booster_phase44_mysql_ready( string $socket ): void {
	for ( $i = 0;$i < 120;++$i ) {
		try {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- Standalone proof controls its isolated MySQL process before WordPress bootstrap.
			$m = mysqli_init();
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect -- Standalone proof connects only to its private test socket before WordPress bootstrap.
			if ( mysqli_real_connect( $m, null, 'root', '', null, 0, $socket ) ) {
				$m->close();
				return;
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This expected exception is the exercised negative path; the surrounding proof continues deliberately.
		} catch ( mysqli_sql_exception ) {
		} usleep( 25000 );
	} throw new RuntimeException( 'Isolated MySQL did not become ready.' ); }
/**
 * @param list<string> $cmd
 * @param array<string, string>|null $env
 * @return array{stdout: string|false, stderr: string|false}
 */
function ran_booster_phase44_command( array $cmd, string $cwd, ?array $env = null ): array {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Standalone proof owns an isolated child process and its pipes; no WordPress process wrapper exists.
	$p = proc_open(
		$cmd,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd,
		$env
	);
	if ( ! is_resource( $p ) ) {
		throw new RuntimeException( 'Could not launch disposable command.' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	} fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	fclose( $pipes[2] );
	$code = proc_close( $p );
	if ( 0 !== $code ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( 'Disposable command failed: ' . implode( ' ', $cmd ) . ' ' . substr( $out . "\n" . $err, 0, 4000 ) );
	} return array(
		'stdout' => $out,
		'stderr' => $err,
	); }
/** @param list<string> $exclude */
function ran_booster_phase44_copy_tree( string $source, string $target, array $exclude = array() ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	if ( ! is_dir( $source ) || is_link( $source ) || ! mkdir( $target, 0700, true ) ) {
		throw new RuntimeException( 'Could not create disposable copy.' );
	} $skip = array_flip( array_merge( $exclude, array( '.git', 'vendor', 'node_modules' ) ) );
	$it     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $it as $entry ) {
		$relative = substr( $entry->getPathname(), strlen( $source ) + 1 );
		if ( $entry->isLink() || isset( $skip[ explode( DIRECTORY_SEPARATOR, $relative )[0] ] ) ) {
			continue;
		} $dest = $target . '/' . $relative;
		if ( $entry->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
			mkdir( $dest, 0700, true );
		} elseif ( ! copy( $entry->getPathname(), $dest ) ) {
			throw new RuntimeException( 'Disposable copy failed.' );
		}
	}}
function ran_booster_phase44_remove_tree( string $path ): void {
	if ( ! is_dir( $path ) || is_link( $path ) ) {
		return;
	} $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $entry ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
		$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable fixture owns exact native files, streams and cleanup paths; WordPress filesystem abstraction would change the proof.
	} rmdir( $path ); }
