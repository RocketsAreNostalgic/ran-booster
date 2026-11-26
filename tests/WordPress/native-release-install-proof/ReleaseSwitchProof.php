<?php

// Refs #903, #987. The Branch-to-release switch through the real installed Core facade
// and WordPress upgrader, and the in-place release of the hold an unfinished one leaves.
// Only remote HTTP is synthetic.

use RAN\AddOn\ReleaseTracking\NativeProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\NativeReleaseTrackingFacade;
use RAN\Admin\AdminPackageProjection;
use RAN\Admin\ReleaseManagement\ReleaseManagementControls;
use RAN\PackageOperation;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\Storage\GitHubSelectionStore;
use RAN\WordPress\WordPressUpdaterLock;

if ( ! defined( 'RAN_BOOSTER_RELEASE_SWITCH_PROOF_LIBRARY_ONLY' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
}

final class RAN_Booster_ReleaseSwitchProof {

	private const VERSIONS = array( '0.5.0', '1.0.0', '2.0.0', '1.2.0-alpha', '0.2.2', '0.2.3' );
	/** Killed after the install result: the request never records its end. */
	private const KILLED = array( 'kill', 'foreign-lock', 'expired-lock' );
	/** Fresh starts after a release, each from the hold its base case leaves. */
	private const FRESH = array(
		'fresh-finished' => 'failed-install',
		'fresh-killed'   => 'kill',
		'fresh-damaged'  => 'kill',
		'fresh-deleted'  => 'kill',
	);
	/** Held targets with no package page: removed by the operator, then confirmed absent on the provider page. */
	private const REMOVED = array( 'fresh-damaged', 'fresh-deleted' );

	private string $slug;
	private string $identity;
	private string $directory;
	private string $repository;
	private string $metadata;
	private string $repository_id;
	private string $archive_root;
	private string $scenario;
	private bool $changed                    = false;
	private ?string $competing               = null;
	private int $streams                     = 0;
	private bool $selection_changed          = false;
	private bool $mutating                   = false;
	private bool $interrupted_before_custody = false;

	public function __construct( private string $kind, private string $case_name ) {
		$site = getenv( 'RAN_RELEASE_SWITCH_PROOF_SITE' );
		$root = is_string( $site ) ? realpath( $site ) : false;
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || false === $root || realpath( ABSPATH ) !== $root
			|| realpath( WP_CONTENT_DIR ) !== $root . '/wp-content' || realpath( WP_PLUGIN_DIR ) !== $root . '/wp-content/plugins'
			|| realpath( get_theme_root() ) !== $root . '/wp-content/themes' || 'http://localhost' !== get_option( 'siteurl' )
			|| is_link( $root . '/.ran-booster-disposable-test-site' )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact disposable-site marker before any fixture mutation.
			|| 'RAN Booster disposable test site' !== trim( (string) file_get_contents( $root . '/.ran-booster-disposable-test-site' ) )
			|| '7.0.3' !== get_bloginfo( 'version' ) || 802 !== PHP_MAJOR_VERSION * 100 + PHP_MINOR_VERSION
			// The raw stored-row reads need single-site options storage.
			|| is_multisite() || wp_using_ext_object_cache() || ! current_user_can( 'manage_options' ) ) {
			throw new RuntimeException( 'Disposable WordPress 7.0.3 / PHP 8.2 administrator identity not established.' );
		}
		$scenario = str_starts_with( $case_name, 'form-' ) ? substr( $case_name, 5 ) : $case_name;
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true )
			|| ! in_array( $scenario, array( 'choice-current', 'choice-latest', 'choice-dedup', 'choice-disappeared', 'same', 'prerelease-order', 'prerelease-no-consent', 'comparison-unavailable', 'stale-confirmation', 'return-lock', 'newer', 'active', 'older', 'disabled', 'failed-download', 'incompatible-zip', 'pre-install', 'pre-install-throw', 'stale', 'missing', 'fallback-repaired', 'compete', 'conflict', 'failed-install', 'kill', 'foreign-lock', 'expired-lock', 'fresh-finished', 'fresh-killed', 'fresh-damaged', 'fresh-deleted' ), true ) ) {
			throw new RuntimeException( 'Unknown proof case.' );
		}
		$this->scenario = self::FRESH[ $scenario ] ?? $scenario;
		$run            = getenv( 'RAN_RELEASE_SWITCH_PROOF_RUN' );
		if ( ! is_string( $run ) || 1 !== preg_match( '/\A[a-z0-9]{1,12}\z/D', $run ) ) {
			throw new RuntimeException( 'Invalid proof run identity.' );
		}
		$this->slug          = 'switch-proof-' . $kind . '-' . $case_name . '-' . $run;
		$this->repository_id = (string) ( 903000 + abs( crc32( $this->slug ) ) );
		$this->metadata      = 'plugin' === $kind ? $this->slug . '.php' : 'style.css';
		$this->identity      = 'plugin' === $kind ? $this->slug . '/' . $this->metadata : $this->slug;
		$this->directory     = $root . '/wp-content/' . ( 'plugin' === $kind ? 'plugins/' : 'themes/' ) . $this->slug;
		$this->repository    = 'switch-proof/' . $this->slug;
		$this->archive_root  = $root . '/release-switch-proof';
		foreach ( array( ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR, get_theme_root(), $this->directory, $this->archive_root ) as $path ) {
			if ( is_link( rtrim( $path, '/' ) ) ) {
				throw new RuntimeException( 'Symlinked disposable target rejected.' );
			}
		}
	}

	/** Published bytes differ from the Branch build, so a same-version install is visible. */
	private function contents( string $version, bool $published ): string {
		return ( 'plugin' === $this->kind ? "<?php\n/*\nPlugin" : "/*\nTheme" ) . ' Name: Release switch proof' . "\nVersion: $version\nRequires at least: 7.0\nRequires PHP: 8.2\nUpdate URI: https://github.com/" . $this->repository . "\n" . ( $published ? "Description: Published release build\n" : '' ) . "*/\n";
	}

	private function archive( int $number ): string {
		return $this->archive_root . '/' . $this->slug . '-' . $number . '.zip';
	}

	private function branch_version(): string {
		return match ( $this->scenario ) {
			'choice-current', 'choice-latest', 'choice-dedup', 'choice-disappeared' => '0.2.2',
			'same' => '1.0',
			'prerelease-order', 'prerelease-no-consent' => '1.2.0-dev',
			'comparison-unavailable' => 'local',
			default => '1.0.0',
		};
	}

	private function channel(): string {
		return in_array( $this->scenario, array( 'prerelease-order', 'prerelease-no-consent' ), true ) ? 'prerelease' : 'stable';
	}

	/** @return list<int> Newest first. */
	private function published(): array {
		return match ( $this->scenario ) {
			'choice-current', 'choice-latest' => array( 5, 4 ),
			'choice-disappeared' => $this->selection_changed ? array( 5 ) : array( 5, 4 ),
			'choice-dedup' => array( 4 ),
			'same' => array( 1, 0 ),
			'prerelease-order', 'prerelease-no-consent' => array( 3 ),
			'older' => array( 0 ),
			'stale', 'missing' => $this->selection_changed ? array( 2, 1, 0 ) : array( 1, 0 ),
			default => array( 2, 1, 0 ),
		};
	}

	/** @return array{0: ?string, 1: string, 2: bool, 3: bool} Result code, installed version, switched, custody retained. */
	private function expected(): array {
		return match ( $this->scenario ) {
			'choice-current', 'choice-dedup' => array( 'release_enabled', '0.2.2', true, false ),
			'choice-latest' => array( 'release_enabled', '0.2.3', true, false ),
			'choice-disappeared' => array( 'switch_refused', '0.2.2', false, false ),
			'same' => array( 'release_enabled', '1.0.0', true, false ),
			'prerelease-order' => array( 'release_enabled', '1.2.0-alpha', true, false ),
			'prerelease-no-consent' => array( 'release_older', '1.2.0-dev', false, false ),
			'comparison-unavailable' => array( 'version_comparison_unavailable', 'local', false, false ),
			'stale-confirmation' => array( 'release_selection_changed', '1.0.0', false, false ),
			'newer', 'active', 'compete', 'return-lock' => array( 'release_enabled', '2.0.0', true, false ),
			'older' => array( 'release_older', '1.0.0', false, false ),
			'disabled' => array( 'policy_disabled', '1.0.0', false, false ),
			'failed-download', 'incompatible-zip' => array( 'switch_refused', '1.0.0', false, false ),
			'pre-install', 'pre-install-throw' => array( 'switch_uncertain', '1.0.0', false, false ),
			'stale', 'fallback-repaired' => array( 'release_enabled', '1.0.0', true, false ),
			'missing' => array( 'switch_refused', '1.0.0', false, false ),
			'failed-install' => array( 'switch_uncertain', '1.0.0', false, true ),
			// The synchronous commit refuses the newly conflicting repository owner.
			'conflict' => array( 'switch_uncertain', '2.0.0', false, true ),
			'kill', 'foreign-lock', 'expired-lock' => array( null, '2.0.0', false, true ),
			default => throw new RuntimeException( 'Unknown proof case.' ),
		};
	}

	public function seed(): void {
		global $wpdb;
		if ( file_exists( $this->directory ) ) {
			throw new RuntimeException( 'Fixture target already exists; use a fresh disposable site.' );
		}
		wp_mkdir_p( $this->directory );
		wp_mkdir_p( $this->archive_root );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Synthetic Branch build beneath the verified, previously absent disposable target.
		file_put_contents( $this->directory . '/' . $this->metadata, $this->contents( $this->branch_version(), false ) );
		if ( 'theme' === $this->kind ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Synthetic theme entrypoint in this proof's exclusively owned target.
			file_put_contents( $this->directory . '/index.php', '<?php' );
		}
		foreach ( self::VERSIONS as $number => $version ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $this->archive( $number ), ZipArchive::CREATE | ZipArchive::EXCL ) ) {
				throw new RuntimeException( 'Fixture archive is not exclusively owned.' );
			}
			$header_version = 'incompatible-zip' === $this->scenario && 2 === $number ? '9.9.9' : $version;
			$zip->addFromString( $this->slug . '/' . $this->metadata, $this->contents( $header_version, true ) );
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
				// Automatic proves the switch resets it to Manual.
				'deployment_policy'      => in_array( $this->scenario, array( 'newer', 'choice-current', 'choice-latest', 'choice-dedup', 'choice-disappeared' ), true ) ? 'automatic' : ( 'disabled' === $this->scenario ? 'disabled' : 'manual' ),
				'source'                 => 'branch',
				'source_revision'        => 1,
				'provider'               => 'gh',
				'provider_repository_id' => $this->repository_id,
				'adoption_token'         => RAN\AdoptionIdentity::create( 'gh', $this->repository_id ),
				'private'                => 0,
			)
		);
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'Synthetic managed row was not inserted.' );
		}
		wp_clean_plugins_cache( false );
		wp_clean_themes_cache( false );
		if ( 'active' === $this->scenario ) {
			if ( 'plugin' === $this->kind ) {
				if ( is_wp_error( activate_plugin( $this->identity ) ) ) {
					throw new RuntimeException( 'Fixture activation failed.' );
				}
			} else {
				update_option( 'ran_release_switch_proof_previous_theme', get_stylesheet(), false );
				switch_theme( $this->slug );
			}
		}
		$this->seed_stored_check();
		$this->assert_activation();
		$this->report( 'seeded', $this->branch_version() );
	}

	/** A still-fresh stored check holding the ordinary offer: only a recheck replaces it on the next load. */
	private function seed_stored_check(): void {
		$name                               = $this->transient();
		$stored                             = get_site_transient( $name );
		$check                              = (object) ( is_object( $stored ) ? get_object_vars( $stored ) : array() );
		$check->last_checked                = time();
		$check->response                    = is_array( $check->response ?? null ) ? $check->response : array();
		$check->response[ $this->identity ] = $this->ordinary_offer( 'seeded' );
		set_site_transient( $name, $check );
	}

	/** @return array<string,mixed> */
	private function release( int $number ): array {
		return array(
			'id'           => 100 + $number,
			'tag_name'     => 'v' . self::VERSIONS[ $number ],
			'draft'        => false,
			'prerelease'   => 3 === $number,
			'immutable'    => false,
			'published_at' => '2026-11-21T00:00:00Z',
			'html_url'     => 'https://github.com/' . $this->repository . '/releases/tag/v' . self::VERSIONS[ $number ],
			'assets'       => ( 'fallback-repaired' === $this->scenario && ! $this->selection_changed && 2 === $number ) ? array() : array(
				array(
					'id'                   => 200 + $number,
					'name'                 => basename( $this->archive( $number ) ),
					'size'                 => filesize( $this->archive( $number ) ),
					'state'                => 'uploaded',
					// The digest disappears after authorisation, so the upgrader's fresh inspection refuses it.
					'digest'               => $this->changed ? null : 'sha256:' . hash_file( 'sha256', $this->archive( $number ) ),
					'browser_download_url' => 'https://api.github.com/repos/' . $this->repository . '/releases/assets/' . ( 200 + $number ),
				),
			),
		);
	}

	/** @param array<string,mixed> $args */
	public function http( mixed $pre, array $args, string $url ): mixed {
		unset( $pre );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 'api.wordpress.org' === wp_parse_url( $url, PHP_URL_HOST ) ) {
			return self::wordpress_org( $url );
		}
		if ( ! str_starts_with( $path, '/repos/' . $this->repository ) && '/repositories/' . $this->repository_id !== $path ) {
			return new WP_Error( 'release_switch_proof_network_denied' );
		}
		$number = (int) basename( $path );
		if ( 'choice-disappeared' === $this->scenario && $this->selection_changed
			&& ( str_ends_with( $path, '/releases/104' ) || str_ends_with( $path, '/releases/tags/v0.2.2' ) || str_ends_with( $path, '/releases/assets/204' ) ) ) {
			return array(
				'body'     => '',
				'headers'  => array(),
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
			);
		}
		if ( $this->mutating && str_starts_with( $this->scenario, 'choice-' ) && str_contains( $path, '/releases/assets/' )
			&& ( 'choice-latest' === $this->scenario ? 205 : 204 ) !== $number ) {
			throw new RuntimeException( 'A different release was substituted for the selected release.' );
		}

		if ( str_contains( $path, '/releases/assets/' ) && true === ( $args['stream'] ?? false ) && is_string( $args['filename'] ?? null ) ) {
			if ( $this->mutating && 'failed-download' === $this->scenario ) {
				return new WP_Error( 'release_switch_proof_download_failed' );
			}
			if ( $this->mutating && in_array( $this->scenario, array( 'stale', 'fallback-repaired' ), true ) && 201 !== $number ) {
				throw new RuntimeException( 'The confirmed release A was substituted.' );
			}
			if ( 'incompatible-zip' === $this->scenario && 202 !== $number ) {
				throw new RuntimeException( 'The selected incompatible ZIP was replaced by another candidate.' );
			}
			++$this->streams;
			if ( ! copy( $this->archive( $number - 200 ), $args['filename'] ) ) {
				throw new RuntimeException( 'Synthetic HTTP asset stream failed.' );
			}
			$body = '';
		} elseif ( str_ends_with( $path, '/releases' ) ) {
			$body = wp_json_encode( array_map( $this->release( ... ), $this->published() ) );
		} elseif ( str_contains( $path, '/releases/tags/' ) ) {
			$body = wp_json_encode( $this->release( (int) array_search( ltrim( basename( $path ), 'v' ), self::VERSIONS, true ) ) );
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
					'owner'          => array( 'login' => 'switch-proof' ),
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

	/**
	 * Submit the standard form using the exact hidden fields in its rendered markup.
	 *
	 * @param array<string,mixed> $request
	 * @return array<string,string>
	 */
	private function standard_confirmation( ReleaseManagementControls $controls, array $request ): array {
		$url = $controls->process_admin_post_request( 'enable', $request );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$html = $this->source_settings( $controls, $query );
		if ( 'comparison-unavailable' === $this->scenario ) {
			if ( str_contains( $html, 'data-ran-booster-switch-confirmation' ) || ! str_contains( $html, 'Booster could not compare the installed version with the published release version.' ) ) {
				throw new RuntimeException( 'The standard form offered a release without a valid comparison.' );
			}
			return array();
		}
		$has_consent = str_contains( $html, 'name="confirm_older_release" value="1" required' );
		$same_notice = str_contains( $html, 'Same version: this replaces the installed files with the release.' );
		if ( ( 'prerelease' === $this->channel() ) !== $has_consent || ( in_array( $this->scenario, array( 'same', 'choice-current', 'choice-latest', 'stale-confirmation', 'failed-download' ), true ) ) !== $same_notice ) {
			throw new RuntimeException( 'The standard form showed the wrong relationship or consent.' );
		}
		$dom = new DOMDocument();
		$dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath         = new DOMXPath( $dom );
		$form_selector = '//form[contains(@class, "ran-booster-switch-confirmation__form")]';
		if ( in_array( $this->scenario, array( 'choice-current', 'choice-latest' ), true ) ) {
			$current = $xpath->query( $form_selector . '[button[contains(normalize-space(.), "Reinstall 0.2.2")]]' );
			$latest  = $xpath->query( $form_selector . '[button[contains(normalize-space(.), "Upgrade to 0.2.3")]]' );
			if ( false === $current || 1 !== $current->length || false === $latest || 1 !== $latest->length ) {
				throw new RuntimeException( 'Both releases must have direct, separately signed submit buttons.' );
			}
			$form_selector .= 'choice-current' === $this->scenario ? '[button[contains(., "Reinstall")]]' : '[button[contains(., "Upgrade")]]';
		}
		if ( in_array( $this->scenario, array( 'stale-confirmation', 'failed-download' ), true ) ) {
			$form_selector .= '[button[contains(., "Upgrade to 2.0.0")]]';
		}
		$forms = $xpath->query( $form_selector );
		if ( false === $forms || 1 !== $forms->length ) {
			throw new RuntimeException( 'The proof must submit exactly one rendered confirmation form.' );
		}
		$inputs = $xpath->query( $form_selector . '//input[@type="hidden"]' );
		if ( false === $inputs || 0 === $inputs->length ) {
			throw new RuntimeException( 'No standard confirmation form was rendered.' );
		}
		$post = array();
		foreach ( $inputs as $input ) {
			if ( $input instanceof DOMElement ) {
				$post[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
			}
		}
		return $post;
	}

	/** @param array<int|string,mixed> $query Signed operation result. */
	private function source_settings( ReleaseManagementControls $controls, array $query ): string {
		$package = new AdminPackageProjection( $this->kind, $this->identity, $this->slug, 'gh', 'branch', 1, 'manual', admin_url( 'admin.php' ) );
		$get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Restore the CLI fixture's request after rendering the signed redirect.
		$_GET    = $query;
		ob_start();
		try {
			$controls->render_advanced_source_section( 'edit', $this->kind, 'release_asset', $package, $package->settings_url() );
		} finally {
			$html = (string) ob_get_clean();
			$_GET = $get;
		}
		return $html;
	}

	/** Preflight supplies the signed selection submitted to the real admin boundary. */
	private function enable(): string {
		add_filter( 'pre_http_request', $this->http( ... ), PHP_INT_MIN, 3 );
		$container = require dirname( __DIR__ ) . '/core-container-fixture.php';
		$facade    = $container->make( NativeReleaseTrackingFacade::class );
		$controls  = $container->make( ReleaseManagementControls::class );
		$channel   = $this->channel();
		$request   = array(
			'expected_type'            => $this->kind,
			'expected_identifier'      => $this->identity,
			'expected_source_revision' => '1',
			'release_channel'          => $channel,
			'_wpnonce'                 => wp_create_nonce( $facade->nonce_action( 'preflight', $this->kind, $this->identity, 1, $channel ) ),
		);
		$before    = $this->streams;
		if ( str_starts_with( $this->case_name, 'form-' ) ) {
			$request['_wpnonce'] = wp_create_nonce( $facade->nonce_action( 'enable', $this->kind, $this->identity, 1 ) );
			$request             = $this->standard_confirmation( $controls, $request );
			if ( array() === $request ) {
				if ( $this->streams !== $before ) {
					throw new RuntimeException( 'The refused form downloaded an archive.' );
				}
				return 'version_comparison_unavailable';
			}
		} else {
			$preflight = $controls->process_switch_preflight_request( $request );
			if ( ! $preflight['successful'] ) {
				if ( 'comparison-unavailable' === $this->scenario && ( $this->streams !== $before
					|| ! str_contains( (string) ( $preflight['data']['message'] ?? '' ), 'Booster could not compare the installed version with the published release version.' ) ) ) {
					throw new RuntimeException( 'The comparison refusal must explain the version issue without downloading a ZIP.' );
				}
				echo wp_json_encode(
					array(
						'preflight_streams' => $this->streams - $before,
						'mutation_streams'  => 0,
					)
				) . "\n";
				return $preflight['code'];
			}
			$data = $preflight['data'];
			if ( str_starts_with( $this->scenario, 'choice-' ) ) {
				if ( isset( $data['matching_release'] ) !== ( 'choice-dedup' !== $this->scenario ) ) {
					throw new RuntimeException( 'Expected only distinct published versions in the chooser.' );
				}
				if ( in_array( $this->scenario, array( 'choice-current', 'choice-disappeared' ), true ) ) {
					$data                             = $data['matching_release'];
					$request['switch_release_choice'] = 'installed';
				}
				$wanted = 'choice-latest' === $this->scenario ? '0.2.3' : '0.2.2';
				if ( ( $data['release_version'] ?? null ) !== $wanted ) {
					throw new RuntimeException( 'The chooser offered an unexpected release.' );
				}
			}
			$request['confirmed_installed_version'] = $data['confirmed_installed_version'];

			if ( 'fallback-repaired' === $this->scenario
			&& ( ! str_contains( (string) ( $data['fallback_message'] ?? '' ), '2.0.0' )
				|| ! str_contains( (string) ( $data['fallback_message'] ?? '' ), 'package asset' ) ) ) {
				throw new RuntimeException( 'Preflight did not explain the skipped release B.' );
			}
			$request['_wpnonce']                = wp_create_nonce( $facade->nonce_action( 'enable', $this->kind, $this->identity, 1 ) );
			$request['confirm_switch']          = '1';
			$request['confirmed_release_older'] = 'older' === $data['version_relationship'] ? '1' : '0';
			foreach ( array( 'identity', 'tag', 'version', 'nonce' ) as $field ) {
				$request[ 'confirmed_release_' . $field ] = $data[ 'nonce' === $field ? 'confirmed_release_nonce' : 'release_' . $field ];
			}
			if ( ( 'same' === $this->scenario && 'same' !== $data['version_relationship'] )
				|| ( 'prerelease' === $channel && 'older' !== $data['version_relationship'] ) ) {
				throw new RuntimeException( 'AJAX returned the wrong version relationship.' );
			}
		}
		$preflight_streams = $this->streams - $before;
		if ( 0 !== $preflight_streams ) {
			throw new RuntimeException( 'Displaying the version chooser fetched a ZIP.' );
		}
		if ( 'prerelease-order' === $this->scenario ) {
			$request['confirm_older_release'] = '1';
		}
		if ( in_array( $this->scenario, array( 'stale', 'missing', 'fallback-repaired' ), true ) ) {
			if ( '1.0.0' !== $request['confirmed_release_version'] || 'v1.0.0' !== $request['confirmed_release_tag'] ) {
				throw new RuntimeException( 'Preflight did not confirm release A.' );
			}
			$this->selection_changed = true;
			$this->changed           = 'missing' === $this->scenario;
		}
		if ( 'choice-disappeared' === $this->scenario ) {
			$this->selection_changed = true;
		}
		if ( 'stale-confirmation' === $this->scenario ) {
			unset( $request['confirmed_release_nonce'] );
		}
		$before           = $this->streams;
		$this->mutating   = true;
		$url              = $controls->process_admin_post_request( 'enable', $request );
		$this->mutating   = false;
		$mutation_streams = $this->streams - $before;
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$result = is_string( $query['ran_booster_release_result'] ?? null ) ? $query['ran_booster_release_result'] : '';
		if ( in_array( $this->scenario, array( 'choice-current', 'choice-latest', 'choice-dedup', 'same', 'prerelease-order', 'newer', 'active', 'compete', 'stale', 'fallback-repaired', 'incompatible-zip' ), true ) && 1 !== $mutation_streams ) {
			throw new RuntimeException( 'Expected exactly one mutation ZIP stream, got ' . $mutation_streams . ' for ' . $result . '.' );
		}
		if ( in_array( $this->scenario, array( 'choice-disappeared', 'missing', 'stale-confirmation', 'prerelease-no-consent' ), true ) && 0 !== $mutation_streams ) {
			throw new RuntimeException( 'Unverified release downloaded a mutation ZIP.' );
		}
		echo wp_json_encode(
			array(
				'preflight_streams' => $preflight_streams,
				'mutation_streams'  => $mutation_streams,
			)
		) . "\n";
		if ( 'stale-confirmation' === $this->scenario ) {
			$notice = $this->source_settings( $controls, $query );
			if ( ! str_contains( $notice, 'Choose Use releases to open a new confirmation.' ) || ! str_contains( $notice, '>Use releases<' ) ) {
				throw new RuntimeException( 'An expired switch confirmation did not offer a fresh selection.' );
			}
		}
		if ( in_array( $this->scenario, array( 'failed-download', 'same', 'prerelease-order' ), true ) ) {
			$_GET = $query + array(
				'page'    => 'plugin' === $this->kind ? 'ran-booster-plugins' : 'ran-booster-themes',
				'package' => $this->identity,
			);
			ob_start();
			$controls->render_operation_notice();
			$notice = (string) ob_get_clean();
			$expect = 'failed-download' === $this->scenario ? 'Choose Use releases to try again.' : 'Switched to releases.';
			if ( ! str_contains( $notice, $expect ) ) {
				throw new RuntimeException( 'The signed landing notice did not match the switch result.' );
			}
		}
		return $result;
	}

	public function run(): void {
		$this->attest();
		$this->assert_files( $this->branch_version(), false, $this->directory );
		$this->assert_activation();
		WP_Filesystem();

		if ( 'pre-install' === $this->scenario ) {
			// Refuse at WordPress's actual pre-install boundary, before destination replacement.
			add_filter( 'upgrader_pre_install', static fn () => new WP_Error( 'release_switch_proof_pre_install', 'Synthetic refusal before replacement.' ), PHP_INT_MAX );
		}
		if ( 'pre-install-throw' === $this->scenario ) {
			add_filter(
				'upgrader_pre_install',
				function ( mixed $reply ): mixed {
					if ( null === $this->lock_row() ) {
						return $reply;
					}
					$this->interrupted_before_custody = true;
					throw new RuntimeException( 'Synthetic interruption before custody.' );
				},
				PHP_INT_MAX
			);
		}
		if ( 'failed-install' === $this->scenario ) {
			add_filter( 'upgrader_post_install', static fn () => new WP_Error( 'release_switch_proof_after_replace', 'Synthetic failure after replacement.' ), PHP_INT_MAX );
		}
		if ( in_array( $this->scenario, self::KILLED, true ) ) {
			add_filter(
				'upgrader_install_package_result',
				function ( mixed $result ): mixed {
					$this->assert_files( '2.0.0', true, $this->directory );
					$pid = getmypid();
					if ( ! is_array( $result ) || ! is_int( $pid ) || ! posix_kill( $pid, 9 ) ) {
						throw new RuntimeException( 'Kill point did not follow installation.' );
					}
					return $result;
				},
				PHP_INT_MAX
			);
		}
		if ( 'conflict' === $this->scenario ) {
			// Another package from the same repository is adopted after the install, before the shutdown commit.
			add_filter(
				'upgrader_install_package_result',
				function ( mixed $result ): mixed {
					global $wpdb;
					if ( ! is_array( $result ) || 1 !== $wpdb->insert( ran_booster_table_name(), $this->conflicting_row() ) ) {
						throw new RuntimeException( 'Conflicting package was not added after installation.' );
					}
					return $result;
				},
				PHP_INT_MAX
			);
		}
		if ( 'compete' === $this->scenario ) {
			// A second switch runs to completion while this one holds WordPress's installer.
			add_filter( 'upgrader_pre_install', fn ( mixed $reply ): mixed => $this->compete( $reply ), PHP_INT_MAX );
		}
		$code = $this->enable();
		$this->attest();
		if ( 'pre-install-throw' === $this->scenario && ( ! $this->interrupted_before_custody || did_action( 'upgrader_process_complete' ) > 0 ) ) {
			throw new RuntimeException( 'The interruption must occur while holding the lock and without WordPress completion.' );
		}
		if ( ( 'compete' === $this->scenario ) !== ( null !== $this->competing ) ) {
			throw new RuntimeException( 'The competing switch did not run inside the installer.' );
		}
		if ( null !== $this->competing ) {
			echo wp_json_encode( array( 'competing_switch' => $this->competing ) ) . "\n";
		}
		[ $expected_code, $version, $switched ] = $this->expected();
		if ( $expected_code !== $code ) {
			throw new RuntimeException( 'Unexpected switch result: ' . $code );
		}
		// Successful completion commits the source before the handler returns.
		$this->assert_row( $switched );
		if ( $switched ) {
			$container = require dirname( __DIR__ ) . '/core-container-fixture.php';
			if ( isset( $container->make( GitHubSelectionStore::class )->read( true )['native_mutation'] ) ) {
				throw new RuntimeException( 'Successful switch returned with a native hold.' );
			}
		}
		// WordPress restores a failed install at shutdown, after this reply.
		$this->assert_files( 'failed-install' === $this->scenario ? '2.0.0' : $version, $switched || in_array( $this->scenario, array( 'failed-install', 'conflict' ), true ), $this->directory );
		$this->assert_activation();
		$this->report( $code, $version );
	}

	private function compete( mixed $reply ): mixed {
		if ( null !== $this->competing ) {
			return $reply;
		}
		$command = array( (string) getenv( 'PHP_BIN' ), '-d', 'memory_limit=512M', (string) getenv( 'WP_CLI_BIN' ), 'eval-file', __FILE__, '--path=' . ABSPATH, '--user=' . (string) getenv( 'RAN_RELEASE_SWITCH_PROOF_USER' ) );
		$env     = array_merge( getenv(), array( 'RAN_RELEASE_SWITCH_PROOF_PHASE' => 'compete' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- A second real WP-CLI request must run while this one holds WordPress's installer.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			$env
		);
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Competing switch could not start.' );
		}
		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) {
			throw new RuntimeException( 'Competing switch failed: ' . $errors );
		}
		$lines   = array_values( array_filter( explode( "\n", trim( $output ) ) ) );
		$last    = json_decode( (string) end( $lines ), true );
		$outcome = is_array( $last ) && is_string( $last['outcome'] ?? null ) ? $last['outcome'] : '';
		if ( ! in_array( $outcome, array( 'release_unavailable', 'switch_refused', 'switch_failed', 'switch_uncertain', 'service_unavailable' ), true ) ) {
			throw new RuntimeException( 'The competing switch was not refused: ' . $outcome );
		}
		$this->assert_files( '1.0.0', false, $this->directory );
		// The upgrader skin buffers output, so the outcome is reported after the reply.
		$this->competing = $outcome;
		return $reply;
	}

	public function branch_return(): void {
		$this->attest();
		$this->assert_row( true );
		$this->assert_files( '2.0.0', true, $this->directory );
		$container = require dirname( __DIR__ ) . '/core-container-fixture.php';
		$facade    = $container->make( NativeReleaseTrackingFacade::class );
		$controls  = $container->make( ReleaseManagementControls::class );
		$lock      = $container->make( WordPressUpdaterLock::class );
		$request   = array(
			'expected_type'            => $this->kind,
			'expected_identifier'      => $this->identity,
			'expected_source_revision' => '2',
			'_wpnonce'                 => wp_create_nonce( $facade->nonce_action( 'return_to_branch', $this->kind, $this->identity, 2 ) ),
		);

		$remote_requests = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$remote_requests ): WP_Error {
				++$remote_requests;
				return new WP_Error( 'branch_return_proof_unexpected_http' );
			},
			PHP_INT_MIN
		);

		foreach ( array( time(), time() - HOUR_IN_SECONDS - 60 ) as $time ) {
			$token = (string) $time;
			if ( null !== $this->lock_row() || ! add_option( 'auto_updater.lock', $token, '', false ) ) {
				throw new RuntimeException( 'Could not seed the fixture updater lock.' );
			}
			try {
				$url = $controls->process_admin_post_request( 'return_to_branch', $request );
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				if ( 'lock_unavailable' !== ( $query['ran_booster_release_result'] ?? null ) || $token !== $this->lock_row() ) {
					throw new RuntimeException( 'Use branch did not report and preserve the occupied lock.' );
				}
				$package = new AdminPackageProjection( $this->kind, $this->identity, $this->slug, 'gh', 'release_asset', 2, 'manual', admin_url( 'admin.php' ) );
				$_GET    = $query;
				ob_start();
				$controls->render_advanced_source_section( 'edit', $this->kind, 'branch', $package, $package->settings_url() );
				$notice = (string) ob_get_clean();
				if ( ! str_contains( $notice, 'The WordPress package updater lock blocked the switch to Branch.' ) || ! str_contains( $notice, 'choose Use branch again' ) ) {
					throw new RuntimeException( 'Use branch did not show the lock reason and retry action.' );
				}
				$this->assert_row( true );
				$this->assert_files( '2.0.0', true, $this->directory );
			} finally {
				// Only the test owner clears the exact synthetic token after the refused request.
				if ( ! $lock->release( $token ) ) {
					throw new RuntimeException( 'The fixture no longer owns its updater lock.' );
				}
			}
		}
		$controls->process_admin_post_request( 'return_to_branch', $request );
		$row = $this->managed_row();
		if ( 'branch' !== ( $row['source'] ?? null ) || '3' !== (string) ( $row['source_revision'] ?? '' )
			|| null !== ( $row['release_configuration'] ?? null ) || null !== $this->lock_row() || 0 !== $remote_requests ) {
			throw new RuntimeException( 'Use branch did not commit locally after the fixture freed the lock.' );
		}
		$this->assert_files( '2.0.0', true, $this->directory );
		$this->assert_activation();
		$this->attest();
		$this->report( 'branch_restored_after_lock', '2.0.0' );
		$this->cleanup();
	}

	public function competing(): void {
		$this->attest();
		$code = $this->enable();
		$this->assert_row( false );
		$this->report( $code, '1.0.0' );
	}

	public function readback(): void {
		global $wpdb;
		[ $reply, $version, $switched, $custody ] = $this->expected();
		$this->assert_row( $switched );
		$container = require dirname( __DIR__ ) . '/core-container-fixture.php';
		$hold      = $container->make( GitHubSelectionStore::class )->read( true )['native_mutation'] ?? null;
		if ( isset( $hold ) !== $custody ) {
			throw new RuntimeException( 'Unexpected native custody after the switch.' );
		}
		if ( 'pre-install-throw' === $this->scenario && null !== $this->lock_row() ) {
			throw new RuntimeException( 'The interrupted request left its updater lock behind.' );
		}
		if ( is_array( $hold ) ) {
			// A request that ended records it; only a killed or refused one leaves the hold started, still owning its lock.
			$finished = 'failed-install' === $this->scenario;
			$own      = GitHubProviderSelection::native_lock_token( $hold );
			if ( ( $finished ? 'finished' : 'started' ) !== $hold['phase'] || $this->identity !== $hold['installed_identity'] || null === $own
				|| ( $finished ? null : $own ) !== $this->lock_row() ) {
				throw new RuntimeException( 'Unexpected hold or updater lock after the switch.' );
			}
		}
		if ( in_array( $reply, array( 'release_enabled', 'switch_refused' ), true ) || ( 'switch_uncertain' === $reply && $custody ) ) {
			add_filter( 'pre_http_request', $this->http( ... ), PHP_INT_MIN, 3 );
			$status     = $container->make( NativeReleaseTrackingFacade::class )->status( $this->kind, $this->identity );
			$shown_hold = $container->make( RAN\Admin\ProviderSettingsPresenter::class )->native_hold();
			$shown      = RAN\Admin\ReleaseManagement\ReleaseTrackingOperations::settled( $reply, $switched, $status, $shown_hold, $this->kind, $this->identity, '1' );
			$expected   = match ( $reply ) {
				'release_enabled' => array( 'release_enabled', true ),
				'switch_refused' => array( 'switch_failed_unchanged', false ),
				default => array( 'switch_held', false ),
			};
			if ( $expected !== $shown ) {
				throw new RuntimeException( 'The landing page would misreport the switch.' );
			}
		}
		$this->assert_files( $version, $this->published_after_switch(), $this->directory );
		$this->assert_activation();
		if ( in_array( $this->scenario, self::KILLED, true ) ) {
			$this->assert_files( '1.0.0', false, WP_CONTENT_DIR . '/upgrade-temp-backup/' . ( 'plugin' === $this->kind ? 'plugins/' : 'themes/' ) . $this->slug );
		}
		$this->assert_stored_check( null );
		if ( 'choice-current' === $this->scenario ) {
			$this->seed_stored_check();
			$facade    = $container->make( NativeReleaseTrackingFacade::class );
			$nonce     = wp_create_nonce( $facade->nonce_action( 'refresh', $this->kind, $this->identity, 2 ) );
			$result    = $facade->refresh( $this->kind, $this->identity, 2, $nonce );
			$status    = $facade->status( $this->kind, $this->identity );
			$transient = get_site_transient( $this->transient() );
			$offer     = is_object( $transient ) && is_array( $transient->response ?? null ) ? ( $transient->response[ $this->identity ] ?? null ) : null;
			$offer     = is_object( $offer ) ? get_object_vars( $offer ) : $offer;
			if ( ! is_array( $offer ) || '0.2.3' !== ( $offer['new_version'] ?? null ) ) {
				throw new RuntimeException( 'The filtered WordPress transient did not offer release B.' );
			}
			echo wp_json_encode(
				array(
					'refresh'   => $result->code(),
					'latest'    => $status->latest_version(),
					'available' => $status->update_available(),
					'failure'   => $status->failure_code(),
				)
			) . "\n";
			if ( ! $result->successful() || ! $status->update_available() || '0.2.3' !== $status->latest_version() ) {
				throw new RuntimeException( 'Release B was not offered after installing the current release: ' . $result->code() );
			}
			$this->assert_files( '0.2.2', true, $this->directory );
			$this->assert_row( true );
		}
		$no_install = in_array( $this->scenario, array( 'comparison-unavailable', 'stale-confirmation', 'prerelease-no-consent', 'older' ), true );
		if ( $no_install ) {
			$this->assert_stored_check( null, true );
		}
		if ( ! $switched && ! $no_install && ! in_array( $this->scenario, array_merge( self::KILLED, array( 'conflict', 'disabled' ) ), true ) ) {
			// The next admin load: WordPress must check again, and the ordinary offer returns.
			$start = time();
			add_filter( 'pre_http_request', static fn ( mixed ...$request ): mixed => self::wordpress_org( (string) $request[2] ), PHP_INT_MIN, 3 );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The WordPress-owned hostname update hook, with its exact native spelling.
			add_filter( $this->transient() . '_github.com', fn ( mixed ...$check ): mixed => $this->identity === $check[2] ? $this->ordinary_offer( 'fresh' ) : $check[0], PHP_INT_MAX, 3 );
			'plugin' === $this->kind ? _maybe_update_plugins() : _maybe_update_themes();
			$this->assert_stored_check( $start );
		}
		$this->report( $no_install ? 'refused_without_switch' : ( $switched ? 'switched' : ( $custody ? 'custody_retained' : 'ordinary_offer_restored' ) ), $version );
		if ( $custody ) {
			// The release phase needs the managed row it keeps.
			return;
		}
		$this->cleanup();
	}

	/** The files the switch left: the release after a commit, a kill or a refused commit. */
	private function published_after_switch(): bool {
		return $this->expected()[2] || in_array( $this->scenario, array_merge( self::KILLED, array( 'conflict' ) ), true );
	}

	/** Release the hold in place with the operator's confirmations; the package, its row and its files stay as they are. */
	public function release_hold(): void {
		$this->attest();
		$container  = require dirname( __DIR__ ) . '/core-container-fixture.php';
		$removal    = $container->make( PackageRemovalService::class );
		$store      = $container->make( GitHubSelectionStore::class );
		$authorized = static fn (): bool => current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' );
		if ( in_array( $this->case_name, self::REMOVED, true ) ) {
			$this->remove_and_recover( $removal, $store, $authorized );
			return;
		}
		$hold   = $store->read( true )['native_mutation'] ?? null;
		$before = $this->managed_row();
		$review = $removal->native_recovery_confirmation();
		if ( ! is_array( $hold ) || $this->identity !== $review['identifier'] || ( 'finished' === $hold['phase'] ) !== $review['finished'] ) {
			throw new RuntimeException( 'Unexpected hold review.' );
		}
		$finished = $review['finished'];
		$this->refused( fn () => $removal->release_native_hold( $review['digest'], true, false, $authorized ) );
		if ( ! $finished ) {
			$this->refused( fn () => $removal->release_native_hold( $review['digest'], false, true, $authorized ) );
		}
		if ( 'foreign-lock' === $this->scenario ) {
			// Another operation's live lock: never removed, however the hold was left.
			$foreign = (string) max( time(), (int) GitHubProviderSelection::native_lock_token( $hold ) + 1 );
			$this->set_lock( $foreign );
			$this->refused( fn () => $removal->release_native_hold( $review['digest'], true, true, $authorized ) );
			if ( $foreign !== $this->lock_row() || $store->read( true )['native_mutation'] !== $hold ) {
				throw new RuntimeException( 'A live foreign updater lock was removed.' );
			}
		}
		if ( in_array( $this->scenario, array( 'foreign-lock', 'expired-lock' ), true ) ) {
			// An hour-old lock that is not the hold's own: the release reclaims it itself.
			$this->set_lock( (string) ( time() - 3601 ) );
		}
		$removal->release_native_hold( $review['digest'], ! $finished, true, $authorized );
		if ( isset( $store->read( true )['native_mutation'] ) || null !== $this->lock_row() || $this->managed_row() !== $before ) {
			throw new RuntimeException( 'The release left the hold or a lock, or changed the managed package.' );
		}
		$this->assert_files( $this->expected()[1], $this->published_after_switch(), $this->directory );
		$this->assert_activation();
		if ( 'conflict' === $this->scenario ) {
			global $wpdb;
			if ( 1 !== $wpdb->delete( ran_booster_table_name(), array( 'package' => $this->conflicting_row()['package'] ) ) ) {
				throw new RuntimeException( 'Conflicting row cleanup failed.' );
			}
		}
		$this->report( $finished ? 'finished_hold_released' : 'unfinished_hold_released', $this->expected()[1] );
	}

	/** After the operator releases the hold, the same confirmed switch can run again. */
	public function retry_switch(): void {
		$this->attest();
		// This site cannot reach WordPress.org, so a recheck after a completed install saves a check with no
		// response list, to which the release updater does not add its offer. Give it an ordinary check again.
		$this->seed_stored_check();
		$code = $this->enable();
		if ( 'release_enabled' !== $code ) {
			throw new RuntimeException( 'Unexpected switch result after the release: ' . $code );
		}
		$this->assert_row( true );
		$this->report( $code, '2.0.0' );
	}

	public function retried(): void {
		$this->assert_row( true );
		$container = require dirname( __DIR__ ) . '/core-container-fixture.php';
		if ( isset( $container->make( GitHubSelectionStore::class )->read( true )['native_mutation'] ) || null !== $this->lock_row() ) {
			throw new RuntimeException( 'The switch after the release left a hold or a lock.' );
		}
		$this->assert_files( '2.0.0', true, $this->directory );
		$this->assert_activation();
		$this->report( 'switched_after_release', '2.0.0' );
		$this->cleanup();
	}

	/**
	 * A held target with no package page: the in-place release is refused, the operator deletes the folder,
	 * confirms on the provider page, and Booster drops its record and clears the hold. The killed install's
	 * own lock is freed at once, without waiting for the hour-old reclaim.
	 *
	 * @param callable():bool $authorized
	 */
	private function remove_and_recover( PackageRemovalService $removal, GitHubSelectionStore $store, callable $authorized ): void {
		global $wp_filesystem;
		WP_Filesystem();
		if ( 'fresh-damaged' === $this->case_name ) {
			// Enough of the held target is gone that WordPress no longer registers it.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Damage only this proof's exclusively owned target.
			if ( ! unlink( $this->directory . '/' . $this->metadata ) || $this->registered() ) {
				throw new RuntimeException( 'The damaged target still registers.' );
			}
		} elseif ( ! $wp_filesystem->delete( $this->directory, true ) ) {
			throw new RuntimeException( 'The held folder was not deleted first.' );
		}
		$hold   = $store->read( true )['native_mutation'] ?? null;
		$review = $removal->native_recovery_confirmation();
		if ( ! is_array( $hold ) || 'started' !== $hold['phase'] || $this->identity !== $review['identifier'] || $review['readable'] || ! $review['managed']
			|| GitHubProviderSelection::native_lock_token( $hold ) !== $this->lock_row() ) {
			throw new RuntimeException( 'Unexpected review of a held target with no package page.' );
		}
		$this->refused( fn () => $removal->release_native_hold( $review['digest'], true, true, $authorized ) );
		if ( 'fresh-damaged' === $this->case_name ) {
			// Removal is confirmed only once the whole folder is gone.
			$this->refused( fn () => $removal->recover_native_removal( $review['digest'], true, true, $authorized ) );
			if ( ! $wp_filesystem->delete( $this->directory, true ) ) {
				throw new RuntimeException( 'The damaged folder was not deleted.' );
			}
		}
		clearstatcache();
		$this->refused( fn () => $removal->recover_native_removal( $review['digest'], true, false, $authorized ) );
		if ( $this->held( $store ) !== $hold || null === $this->managed_row() ) {
			throw new RuntimeException( 'A refused removal recovery changed the hold or the record.' );
		}
		$removal->recover_native_removal( $review['digest'], true, true, $authorized );
		if ( null !== $this->held( $store ) || null !== $this->lock_row() || null !== $this->managed_row() || file_exists( $this->directory ) ) {
			throw new RuntimeException( 'The removal recovery left the hold, a lock, the record or the folder.' );
		}
		$this->report( 'removed_and_recovered', 'none' );
	}

	/**
	 * Start clean after the release without any recovery tool: Stop managing the package, delete its files the
	 * ordinary WordPress way, then install the release through Booster as a new package.
	 */
	public function fresh_start(): void {
		$this->attest();
		WP_Filesystem();
		$container = require dirname( __DIR__ ) . '/core-container-fixture.php';
		$row       = $this->managed_row();
		$steps     = array( 'registered' => $this->registered() );
		if ( in_array( $this->case_name, self::REMOVED, true ) ) {
			// The provider-page removal recovery already dropped the record, and the operator deleted the folder.
			$steps += array(
				'stop_managing'    => 'removal_recovery',
				'wordpress_delete' => 'deleted_by_operator',
			);
		} else {
			$steps['stop_managing']    = $this->stop_managing( $container->make( PackageRemovalService::class ), $row );
			$deleted                   = 'plugin' === $this->kind ? delete_plugins( array( $this->identity ) ) : delete_theme( $this->slug );
			$steps['wordpress_delete'] = true === $deleted ? 'deleted' : ( is_wp_error( $deleted ) ? $deleted->get_error_code() : 'failed' );
			wp_clean_plugins_cache( false );
			wp_clean_themes_cache( false );
		}
		$steps['still_managed'] = null !== $this->managed_row();
		clearstatcache();
		$steps['files_left'] = file_exists( $this->directory );
		add_filter( 'pre_http_request', $this->http( ... ), PHP_INT_MIN, 3 );
		$facade                   = $container->make( NativeProspectiveReleaseFacade::class );
		$request                  = array(
			'provider'      => 'gh',
			'repository'    => $this->repository,
			'credential_id' => '',
			'branch'        => 'main',
		);
		$review                   = $facade->inspect( $this->kind, $request, '102', 'v2.0.0', 'stable', wp_create_nonce( $facade->nonce_action( 'inspect', $this->kind, null ) ) );
		$data                     = $review->data();
		$install                  = $facade->install( $this->kind, $request, '102', 'v2.0.0', (string) ( $data['fingerprint'] ?? '' ), 'stable', wp_create_nonce( $facade->nonce_action( 'install', $this->kind, $data['provider_expectation'] ?? null ) ), $data['provider_expectation'] ?? null );
		$steps['booster_install'] = $review->code() . ' ' . $install->code();
		$installed                = $this->managed_row();
		$steps['managed_source']  = $installed['source'] ?? null;
		echo wp_json_encode( array( 'fresh_start' => $steps ) ) . "\n";
		if ( ! in_array( $steps['stop_managing'], array( 'unlinked', 'removal_recovery' ), true ) || $steps['still_managed']
			|| ! in_array( $steps['wordpress_delete'], array( 'deleted', 'deleted_by_operator' ), true ) || $steps['files_left']
			|| 'release_ready installed' !== $steps['booster_install'] || 'release_asset' !== $steps['managed_source'] ) {
			throw new RuntimeException( 'No clean start after the release.' );
		}
		$this->assert_files( '2.0.0', true, $this->directory );
		$this->assert_activation();
		if ( isset( $container->make( GitHubSelectionStore::class )->read( true )['native_mutation'] ) || null !== $this->lock_row() ) {
			throw new RuntimeException( 'The fresh install left a hold or a lock.' );
		}
		$this->report( 'fresh_start', '2.0.0' );
		$this->cleanup();
	}

	/** @phpstan-impure Reads the stored hold afresh. */
	private function held( GitHubSelectionStore $store ): mixed {
		return $store->read( true )['native_mutation'] ?? null;
	}

	/** @param array<string, mixed>|null $row */
	private function stop_managing( PackageRemovalService $removal, ?array $row ): string {
		try {
			$result = $removal->execute(
				PackageOperation::from_input(
					'unlink-' . $this->kind,
					array(
						( 'plugin' === $this->kind ? 'file' : 'stylesheet' ) => $this->identity,
						'confirm_package_removal'  => '1',
						'expected_source_revision' => (string) ( $row['source_revision'] ?? '' ),
						'expected_adoption_token'  => $row['adoption_token'] ?? null,
					)
				)
			);
			return trim( $result->status . ' ' . $result->outcome_code );
		} catch ( Throwable $failure ) {
			return 'refused: ' . $failure->getMessage();
		}
	}

	private function registered(): bool {
		wp_clean_plugins_cache( false );
		wp_clean_themes_cache( false );
		return 'plugin' === $this->kind ? isset( get_plugins()[ $this->identity ] ) : ( wp_get_theme( $this->slug )->exists() && false === wp_get_theme( $this->slug )->errors() );
	}

	/** @param callable():void $operation */
	private function refused( callable $operation ): void {
		$released = false;
		try {
			$operation();
			$released = true;
		} catch ( RuntimeException ) {
			$released = false;
		}
		if ( $released ) {
			throw new RuntimeException( 'An unconfirmed or blocked hold release succeeded.' );
		}
	}

	/**
	 * @return array<string, mixed>|null
	 * @phpstan-impure Reads the stored row afresh.
	 */
	private function managed_row(): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE package = %s', ran_booster_table_name(), $this->identity ), ARRAY_A );
	}

	/**
	 * The raw updater lock row, past every option cache.
	 *
	 * @phpstan-impure Reads the stored row afresh.
	 */
	private function lock_row(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'auto_updater.lock' ) );
		return is_string( $value ) ? $value : null;
	}

	private function set_lock( string $value ): void {
		global $wpdb;
		if ( 1 !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $value, 'auto_updater.lock' ) ) ) {
			throw new RuntimeException( 'Synthetic updater lock was not set.' );
		}
		wp_cache_delete( 'auto_updater.lock', 'options' );
	}

	private function cleanup(): void {
		global $wpdb;
		// Leave the shared site as it was: activation back, then only this exact fixture row retired. Files stay for inspection.
		if ( 'active' === $this->scenario ) {
			'plugin' === $this->kind ? deactivate_plugins( $this->identity ) : switch_theme( (string) get_option( 'ran_release_switch_proof_previous_theme' ) );
			delete_option( 'ran_release_switch_proof_previous_theme' );
		}
		if ( 1 !== $wpdb->delete( ran_booster_table_name(), array( 'package' => $this->identity ) ) ) {
			throw new RuntimeException( 'Fixture row cleanup failed.' );
		}
		if ( str_starts_with( $this->scenario, 'choice-' ) ) {
			global $wp_filesystem;
			WP_Filesystem();
			if ( is_link( $this->directory ) || ! $wp_filesystem->delete( $this->directory, true ) || file_exists( $this->directory ) ) {
				throw new RuntimeException( 'Chooser fixture target cleanup failed.' );
			}
			foreach ( array_keys( self::VERSIONS ) as $number ) {
				$archive = $this->archive( $number );
				if ( is_link( $archive ) || ! $wp_filesystem->delete( $archive ) || file_exists( $archive ) ) {
					throw new RuntimeException( 'Chooser fixture archive cleanup failed.' );
				}
			}
		}
	}

	/** @return array<string, string|int> A Branch package of the other type, managed from this fixture's repository. */
	private function conflicting_row(): array {
		return array(
			'package'                => $this->slug . '-other' . ( 'plugin' === $this->kind ? '' : '/' . $this->slug . '-other.php' ),
			'repository'             => $this->repository,
			'branch'                 => 'main',
			'package_type'           => 'plugin' === $this->kind ? 2 : 1,
			'deployment_policy'      => 'manual',
			'source'                 => 'branch',
			'source_revision'        => 1,
			'provider'               => 'gh',
			'provider_repository_id' => $this->repository_id,
			'adoption_token'         => 'gh:' . $this->repository_id . ':22222222-2222-4222-8222-222222222222',
			'private'                => 0,
		);
	}

	private static function wordpress_org( string $url ): mixed {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( 'api.wordpress.org' !== wp_parse_url( $url, PHP_URL_HOST ) || ! preg_match( '#\A/(plugins|themes)/update-check/1\.1/\z#', $path, $match ) ) {
			return new WP_Error( 'release_switch_proof_network_denied' );
		}
		return array(
			'body'     => wp_json_encode(
				array(
					$match[1]      => array(),
					'translations' => array(),
					'no_update'    => array(),
				)
			),
			'headers'  => array(),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/** @return object|array<string,string> */
	private function ordinary_offer( string $stage ): object|array {
		$offer = array(
			'slug'                     => $this->slug,
			'version'                  => '9.0.0',
			'new_version'              => '9.0.0',
			'package'                  => 'https://downloads.invalid/' . $this->slug . '.zip',
			'url'                      => 'https://github.com/' . $this->repository,
			'ran_release_switch_proof' => $stage . '-' . $this->slug,
		);
		return 'plugin' === $this->kind ? (object) ( $offer + array( 'plugin' => $this->identity ) ) : $offer + array( 'theme' => $this->identity );
	}

	private function transient(): string {
		return 'plugin' === $this->kind ? 'update_plugins' : 'update_themes';
	}

	/** The stored row itself, past every read filter: never the one-off offer; after a recheck, the fresh ordinary offer. */
	private function assert_stored_check( ?int $rechecked_since, bool $expect_seeded = false ): void {
		global $wpdb;
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_site_transient_' . $this->transient() ) );
		$check = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		$entry = is_object( $check ) && is_array( $check->response ?? null ) ? ( $check->response[ $this->identity ] ?? null ) : null;
		$entry = is_object( $entry ) ? get_object_vars( $entry ) : $entry;
		if ( is_array( $entry ) && str_starts_with( (string) ( $entry['package'] ?? '' ), 'ran-wp-release-updater:' ) ) {
			throw new RuntimeException( 'The one-off offer reached the stored update check.' );
		}
		if ( $expect_seeded && ( ! is_array( $entry ) || ( $entry['ran_release_switch_proof'] ?? '' ) !== 'seeded-' . $this->slug ) ) {
			throw new RuntimeException( 'A refusal before installation disturbed the ordinary stored offer.' );
		}
		if ( null !== $rechecked_since
			&& ( ! is_object( $check ) || (int) ( $check->last_checked ?? 0 ) < $rechecked_since || ! is_array( $entry ) || ( $entry['ran_release_switch_proof'] ?? null ) !== 'fresh-' . $this->slug ) ) {
			throw new RuntimeException( 'The next load did not restore the ordinary offer from a fresh check.' );
		}
	}

	private function assert_row( bool $switched ): void {
		global $wpdb;
		$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT source, source_revision, deployment_policy, release_configuration FROM %i WHERE package = %s', ran_booster_table_name(), $this->identity ), ARRAY_A );
		$policy   = 'disabled' === $this->scenario ? 'disabled' : ( in_array( $this->scenario, array( 'newer', 'choice-current', 'choice-latest', 'choice-dedup', 'choice-disappeared' ), true ) && ! $switched ? 'automatic' : 'manual' );
		$expected = $switched
			? array(
				'release_asset',
				'2',
				$policy,
				wp_json_encode(
					array(
						'package_root'  => $this->slug,
						'metadata_file' => $this->metadata,
						'channel'       => $this->channel(),
					)
				),
			)
			: array( 'branch', '1', $policy, null );
		$actual   = is_array( $row ) ? array( $row['source'], (string) $row['source_revision'], $row['deployment_policy'], $row['release_configuration'] ) : null;
		if ( $switched && is_array( $actual ) && is_string( $actual[3] ) ) {
			$decoded   = json_decode( $actual[3], true );
			$actual[3] = is_array( $decoded ) ? wp_json_encode(
				array(
					'package_root'  => $decoded['package_root'] ?? null,
					'metadata_file' => $decoded['metadata_file'] ?? null,
					'channel'       => $decoded['channel'] ?? null,
				)
			) : $actual[3];
		}
		if ( $expected !== $actual ) {
			throw new RuntimeException( 'Unexpected stored package source: ' . wp_json_encode( $actual ) );
		}
	}

	private function assert_activation(): void {
		$active = 'plugin' === $this->kind ? is_plugin_active( $this->identity ) : get_stylesheet() === $this->slug;
		if ( ( 'active' === $this->scenario ) !== $active ) {
			throw new RuntimeException( 'Unexpected fixture activation state.' );
		}
	}

	private function assert_files( string $version, bool $published, string $directory ): void {
		$expected = array( $this->metadata => $this->contents( $version, $published ) );
		if ( 'theme' === $this->kind ) {
			$expected['index.php'] = '<?php';
		}
		$names = array_keys( $expected );
		sort( $names );
		clearstatcache();
		$actual = scandir( $directory );
		if ( is_link( $directory ) || false === $actual || array_values( array_diff( $actual, array( '.', '..' ) ) ) !== $names ) {
			throw new RuntimeException( 'Unexpected target file inventory.' );
		}
		foreach ( $expected as $name => $bytes ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Compare every actual fixture file against independent expected bytes.
			if ( is_link( $directory . '/' . $name ) || file_get_contents( $directory . '/' . $name ) !== $bytes ) {
				throw new RuntimeException( 'Unexpected target file bytes.' );
			}
		}
	}

	/** Every loaded Booster file must equal its entry in the Core ZIP under test; upgraders load from this site. */
	private function attest(): void {
		$archive = getenv( 'RAN_RELEASE_SWITCH_PROOF_CORE_ZIP' );
		$zip     = new ZipArchive();
		if ( ! is_string( $archive ) || true !== $zip->open( $archive, ZipArchive::RDONLY ) ) {
			throw new RuntimeException( 'Core archive under test is unavailable.' );
		}
		$plugins = realpath( WP_PLUGIN_DIR ) . '/';
		foreach ( array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() ) as $name ) {
			$file = ( new ReflectionClass( $name ) )->getFileName();
			if ( str_starts_with( $name, 'RAN\\' ) && ( ! is_string( $file ) || ! str_starts_with( $file, $plugins . 'ran-booster/' ) ) ) {
				throw new RuntimeException( 'A RAN class loaded from outside the installed Core.' );
			}
		}
		if ( self::archive_bytes_match( $zip->getFromName( 'ran-booster/__missing_attestation_control__.php' ), false ) ) {
			throw new RuntimeException( 'Archive byte attestation accepted a failed read.' );
		}
		$loaded = array();
		foreach ( get_included_files() as $file ) {
			if ( str_starts_with( $file, $plugins . 'ran-booster/' ) ) {
				$archive_bytes = $zip->getFromName( substr( $file, strlen( $plugins ) ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Compare loaded runtime bytes with the archive entry.
				$file_bytes      = file_get_contents( $file );
				$loaded[ $file ] = self::archive_bytes_match( $archive_bytes, $file_bytes );
			}
		}
		$updater = $plugins . 'ran-booster/vendor/ran/wp-release-updater/src/WordPress/NativePackageUpdater.php';
		if ( in_array( false, $loaded, true ) || true !== ( $loaded[ $updater ] ?? null ) ) {
			throw new RuntimeException( 'Loaded Core files do not match the archive under test.' );
		}
		echo wp_json_encode(
			array(
				'core_archive_sha256'           => hash_file( 'sha256', $archive ),
				'loaded_core_files_match_count' => count( $loaded ),
			)
		) . "\n";
	}

	/**
	 * Require both the archive entry and installed file read to succeed before comparing bytes,
	 * so two failed reads (false === false) never pass attestation.
	 */
	public static function archive_bytes_match( string|false $archive_bytes, string|false $file_bytes ): bool {
		return is_string( $archive_bytes ) && is_string( $file_bytes ) && $archive_bytes === $file_bytes;
	}

	private function report( string $outcome, string $version ): void {
		echo wp_json_encode(
			array(
				'type'      => $this->kind,
				'case'      => $this->case_name,
				'phase'     => getenv( 'RAN_RELEASE_SWITCH_PROOF_PHASE' ),
				'outcome'   => $outcome,
				'installed' => $version,
			)
		) . "\n";
	}
}

if ( ! defined( 'RAN_BOOSTER_RELEASE_SWITCH_PROOF_LIBRARY_ONLY' ) ) {
	$ran_booster_release_switch_proof = new RAN_Booster_ReleaseSwitchProof( (string) getenv( 'RAN_RELEASE_SWITCH_PROOF_TYPE' ), (string) getenv( 'RAN_RELEASE_SWITCH_PROOF_CASE' ) );
	match ( getenv( 'RAN_RELEASE_SWITCH_PROOF_PHASE' ) ) {
		'seed' => $ran_booster_release_switch_proof->seed(),
		'run' => $ran_booster_release_switch_proof->run(),
		'compete' => $ran_booster_release_switch_proof->competing(),
		'branch-return' => $ran_booster_release_switch_proof->branch_return(),
		'readback' => $ran_booster_release_switch_proof->readback(),
		'release' => $ran_booster_release_switch_proof->release_hold(),
		'retry' => $ran_booster_release_switch_proof->retry_switch(),
		'retried' => $ran_booster_release_switch_proof->retried(),
		'fresh' => $ran_booster_release_switch_proof->fresh_start(),
		default => throw new RuntimeException( 'Unknown proof phase.' ),
	};
}
