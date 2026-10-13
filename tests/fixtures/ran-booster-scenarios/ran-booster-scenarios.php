<?php
/**
 * Plugin Name: RAN Booster Scenarios (disposable sites only)
 * Description: Version-locked, opt-in controlled release scenarios for Booster acceptance.
 * Version: 0.1.0
 * Requires PHP: 8.2
 * Requires at least: 7.0
 * License: GPL-2.0-only
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/catalog.php';

/** Empty means compatible. Re-evaluate for every request, including cron and CLI. */
function ran_booster_scenarios_guard(): string {
	if ( ! defined( 'RAN_BOOSTER_SCENARIOS_DISPOSABLE' ) || true !== constant( 'RAN_BOOSTER_SCENARIOS_DISPOSABLE' ) ) {
		return 'Set RAN_BOOSTER_SCENARIOS_DISPOSABLE to true on a disposable site.';
	}
	if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
		return 'Only local and development WordPress environments are supported.';
	}
	foreach ( array(
		'RAN_BOOSTER_PROVIDER_API_VERSION'          => 15,
		'RAN_BOOSTER_ADDON_API_VERSION'             => 17,
		'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' => 3,
	) as $marker => $version ) {
		if ( ! defined( $marker ) || constant( $marker ) !== $version ) {
			return 'Active Core APIs do not match beta.35.';
		}
	}
	$core_file = WP_PLUGIN_DIR . '/ran-booster/ran-booster.php';
	if ( ! is_file( $core_file ) || '1.0.0-beta.35' !== ( get_file_data( $core_file, array( 'version' => 'Version' ), 'plugin' )['version'] ?? '' ) ) {
		return 'Install exactly Core 1.0.0-beta.35 in wp-content/plugins/ran-booster.';
	}
	$marker_file = WP_PLUGIN_DIR . '/ran-booster/ran-booster-release.json';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact local published release marker; never remote content.
	$marker = is_file( $marker_file ) ? json_decode( (string) file_get_contents( $marker_file ), true ) : null;
	if ( ! is_array( $marker ) || 'ran-booster-core-release' !== ( $marker['schema'] ?? null ) || 1 !== ( $marker['schema_version'] ?? null ) || '1.0.0-beta.35' !== ( $marker['version'] ?? null ) || '5743df3af0a33c35839aec0bb369a72d084b3d7a' !== ( $marker['commit'] ?? null ) ) {
		return 'Install the exact published Core beta.35 ZIP, including its release marker.';
	}
	return '';
}

/** @return array{scenario: string, stage: int, target: string} */
function ran_booster_scenarios_state(): array {
	$state   = get_option( 'ran_booster_scenarios_state', array() );
	$catalog = ran_booster_scenarios_catalog();
	if ( ! is_array( $state ) || ! is_string( $state['scenario'] ?? null ) || ! isset( $catalog[ $state['scenario'] ] ) || ! in_array( $state['stage'] ?? null, array( 0, 1 ), true ) || ! in_array( $state['target'] ?? null, array( 'both', 'plugin', 'theme' ), true ) ) {
		return array(
			'scenario' => '',
			'stage'    => 0,
			'target'   => 'both',
		);
	}
	return array(
		'scenario' => $state['scenario'],
		'stage'    => $state['stage'],
		'target'   => $state['target'],
	);
}

/** @param array<string, string> $headers
 * @return array{body: string, headers: array<string, string>, response: array{code: int, message: string}}
 */
function ran_booster_scenarios_response( mixed $body, int $code = 200, array $headers = array() ): array {
	$encoded = wp_json_encode( $body );
	return array(
		'body'     => is_string( $encoded ) ? $encoded : '',
		'headers'  => $headers,
		'response' => array(
			'code'    => $code,
			'message' => 'Controlled scenario',
		),
	);
}

/** Serve only bundled harmless ZIPs; streams must target a pre-created regular WordPress temp file.
 *
 * @param array<string, mixed> $args HTTP request arguments.
 */
function ran_booster_scenarios_archive( string $slug, array $args ): mixed {
	if ( ! in_array( $slug, array( 'scenario-plugin', 'scenario-theme' ), true ) ) {
		return new WP_Error( 'ran_booster_scenarios_archive', 'Unknown fixture archive.' );
	}
	$archive = __DIR__ . '/archives/' . $slug . '.zip';
	if ( ! is_file( $archive ) || is_link( $archive ) ) {
		return new WP_Error( 'ran_booster_scenarios_archive', 'Bundled archive missing.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Serve bundled local fixture bytes, never a remote archive.
	$bytes = file_get_contents( $archive );
	if ( false === $bytes ) {
		return new WP_Error( 'ran_booster_scenarios_archive', 'Bundled archive unreadable.' );
	}
	$response = array(
		'body'     => $bytes,
		'headers'  => array( 'content-type' => 'application/zip' ),
		'response' => array(
			'code'    => 200,
			'message' => 'Controlled fixture archive',
		),
	);
	if ( true === ( $args['stream'] ?? false ) ) {
		$filename = $args['filename'] ?? null;
		$temp     = realpath( get_temp_dir() );
		$resolved = is_string( $filename ) ? realpath( $filename ) : false;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Match WordPress HTTP streaming into its validated temporary file using exact local fixture bytes.
		if ( ! is_string( $filename ) || false === $temp || false === $resolved || is_link( $filename ) || ! is_file( $filename ) || dirname( $resolved ) !== $temp || file_put_contents( $resolved, $bytes ) !== strlen( $bytes ) ) {
			return new WP_Error( 'ran_booster_scenarios_stream', 'Archive streaming requires a regular pre-created file directly in the WordPress temp directory.' );
		}
		$response['body']     = '';
		$response['filename'] = $filename;
	}
	return $response;
}

/** @return array{id: int, name: string, full_name: string, private: bool, default_branch: string, html_url: string, owner: array{login: string}} */
function ran_booster_scenarios_repository( string $slug, int $repository_id ): array {
	return array(
		'id'             => $repository_id,
		'name'           => $slug,
		'full_name'      => 'ran-booster-scenarios/' . $slug,
		'private'        => false,
		'default_branch' => 'main',
		'html_url'       => 'https://github.com/ran-booster-scenarios/' . $slug,
		'owner'          => array( 'login' => 'ran-booster-scenarios' ),
	);
}

/**
 * Intercept only the reserved fake GitHub identity; never fabricate unrelated provider traffic.
 *
 * @param array<string, mixed> $args HTTP request arguments.
 */
function ran_booster_scenarios_http( mixed $pre, array $args, string $url ): mixed {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	$fake = in_array( $host, array( 'api.github.com', 'github.com', 'raw.githubusercontent.com', 'codeload.github.com' ), true )
		&& ( preg_match( '~^/(?:(?:repos|users|orgs)/)?ran-booster-scenarios(?:/|$)~i', $path ) === 1 || preg_match( '~^/repositories/94000[12](?:/|$)~', $path ) === 1 );
	if ( ! $fake ) {
		return $pre;
	}
	$blocked = new WP_Error( 'ran_booster_scenarios_blocked', 'Synthetic target blocked: fixture disabled, incompatible or unsupported request.' );
	$state   = ran_booster_scenarios_state();
	if ( '' !== ran_booster_scenarios_guard() || '' === $state['scenario'] || 'api.github.com' !== $host || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || 'GET' !== strtoupper( is_string( $args['method'] ?? null ) ? $args['method'] : 'GET' ) ) {
		return $blocked;
	}
	$scenario = ran_booster_scenarios_catalog()[ $state['scenario'] ];
	$mode     = 1 === $state['stage'] && '' !== $scenario['next'] ? $scenario['next'] : $scenario['initial'];
	if ( in_array( $mode, array( 'unavailable', 'denied', 'rate-limited' ), true ) ) {
		$codes = array(
			'unavailable'  => 503,
			'denied'       => 403,
			'rate-limited' => 429,
		);
		return ran_booster_scenarios_response(
			array( 'message' => $mode ),
			$codes[ $mode ],
			'rate-limited' === $mode ? array(
				'retry-after'           => '60',
				'x-ratelimit-remaining' => '0',
			) : array()
		);
	}
	$repositories = array(
		'scenario-plugin' => 940001,
		'scenario-theme'  => 940002,
	);
	if ( '/users/ran-booster-scenarios' === $path ) {
		return ran_booster_scenarios_response(
			array(
				'login' => 'ran-booster-scenarios',
				'type'  => 'Organization',
			)
		);
	}
	if ( '/orgs/ran-booster-scenarios/repos' === $path ) {
		$items = array();
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $parameters );
		if ( isset( $parameters['page'] ) && '1' !== $parameters['page'] ) {
			return ran_booster_scenarios_response( $items );
		}
		foreach ( $repositories as $slug => $repository_id ) {
			if ( 'both' === $state['target'] || 'scenario-' . $state['target'] === $slug ) {
				$items[] = ran_booster_scenarios_repository( $slug, $repository_id );
			}
		}
		return ran_booster_scenarios_response( $items );
	}

	foreach ( $repositories as $slug => $repository_id ) {
		$repository = 'ran-booster-scenarios/' . $slug;
		$base       = '/repos/' . $repository;
		if ( $path !== $base && '/repositories/' . $repository_id !== $path && ! str_starts_with( $path, $base . '/' ) ) {
			continue;
		}
		if ( 'both' !== $state['target'] && 'scenario-' . $state['target'] !== $slug ) {
			return $blocked;
		}

		if ( $path === $base || '/repositories/' . $repository_id === $path ) {
			return ran_booster_scenarios_response( ran_booster_scenarios_repository( $slug, $repository_id ) );
		}
		if ( in_array( $path, array( $base . '/commits/main', $base . '/commits/v2.0.0', $base . '/commits/' . str_repeat( 'a', 40 ) ), true ) ) {
			$response = ran_booster_scenarios_response( array( 'sha' => str_repeat( 'a', 40 ) ) );
			$headers  = is_array( $args['headers'] ?? null ) ? array_change_key_case( $args['headers'], CASE_LOWER ) : array();
			if ( 'application/vnd.github.sha' === ( $headers['accept'] ?? null ) ) {
				$response['body']                    = str_repeat( 'a', 40 );
				$response['headers']['content-type'] = 'text/plain';
			}
			return $response;
		}
		if ( $base . '/branches/main' === $path ) {
			return ran_booster_scenarios_response(
				array(
					'name'   => 'main',
					'commit' => array( 'sha' => str_repeat( 'a', 40 ) ),
				)
			);
		}
		if ( $base . '/zipball/' . str_repeat( 'a', 40 ) === $path ) {
			return ran_booster_scenarios_archive( $slug, $args );
		}
		if ( $base . '/releases/assets/' . ( $repository_id + 200 ) === $path ) {
			return in_array( $mode, array( 'immutable', 'mutable' ), true ) ? ran_booster_scenarios_archive( $slug, $args ) : $blocked;
		}
		if ( ! in_array( $path, array( $base . '/releases', $base . '/releases/latest', $base . '/releases/tags/v2.0.0', $base . '/releases/' . ( $repository_id + 100 ) ), true ) ) {
			return $blocked;
		}
		if ( 'no-release' === $mode ) {
			return ran_booster_scenarios_response( $path === $base . '/releases' ? array() : array( 'message' => 'Not Found' ), $path === $base . '/releases' ? 200 : 404 );
		}
		if ( 'malformed' === $mode ) {
			return array(
				'body'     => '{invalid-json',
				'headers'  => array(),
				'response' => array(
					'code'    => 200,
					'message' => 'Controlled malformed JSON',
				),
			);
		}
		$archive = __DIR__ . '/archives/' . $slug . '.zip';
		if ( ! is_file( $archive ) || is_link( $archive ) || ! is_readable( $archive ) ) {
			return new WP_Error( 'ran_booster_scenarios_archive', 'Build or reinstall the fixture with its bundled archives before testing release offers.' );
		}
		$release = array(
			'id'           => $repository_id + 100,
			'tag_name'     => 'v2.0.0',
			'draft'        => false,
			'prerelease'   => false,
			'immutable'    => 'immutable' === $mode,
			'published_at' => '2026-10-13T00:00:00Z',
			'html_url'     => 'https://github.com/' . $repository . '/releases/tag/v2.0.0',
			'assets'       => array(
				array(
					'id'                   => $repository_id + 200,
					'name'                 => $slug . '.zip',
					'size'                 => (int) filesize( __DIR__ . '/archives/' . $slug . '.zip' ),
					'state'                => 'uploaded',
					'digest'               => 'sha256:' . hash_file( 'sha256', __DIR__ . '/archives/' . $slug . '.zip' ),
					'browser_download_url' => 'https://api.github.com' . $base . '/releases/assets/' . ( $repository_id + 200 ),
				),
			),
		);
		return ran_booster_scenarios_response( $path === $base . '/releases' ? array( $release ) : $release );
	}
	return $blocked;
}

/** Mutate fixture selection only; Booster records, caches and logging settings are untouched. */
function ran_booster_scenarios_save(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Administrator access required.' );
	}
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( 'POST required.' );
	}
	check_admin_referer( 'ran_booster_scenarios_save' );
	$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
	if ( 'reset' === $operation ) {
		delete_option( 'ran_booster_scenarios_state' );
	} elseif ( '' === ran_booster_scenarios_guard() ) {
		$state   = ran_booster_scenarios_state();
		$catalog = ran_booster_scenarios_catalog();
		if ( 'select' === $operation ) {
			$selected = isset( $_POST['scenario'] ) && is_string( $_POST['scenario'] ) ? sanitize_key( wp_unslash( $_POST['scenario'] ) ) : '';
			$target   = isset( $_POST['target'] ) && is_string( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';
			if ( isset( $catalog[ $selected ] ) && in_array( $target, array( 'both', 'plugin', 'theme' ), true ) ) {
				update_option(
					'ran_booster_scenarios_state',
					array(
						'scenario' => $selected,
						'stage'    => 0,
						'target'   => $target,
					),
					false
				);
			}
		} elseif ( 'advance' === $operation && isset( $catalog[ $state['scenario'] ] ) && '' !== $catalog[ $state['scenario'] ]['next'] ) {
			update_option(
				'ran_booster_scenarios_state',
				array(
					'scenario' => $state['scenario'],
					'stage'    => 1,
					'target'   => $state['target'],
				),
				false
			);
		}
	}
	wp_safe_redirect( admin_url( 'tools.php?page=ran-booster-scenarios' ) );
	exit;
}

/** Tools page with read-only route links; normal Core controls perform all product operations. */
function ran_booster_scenarios_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$state = ran_booster_scenarios_state();
	$guard = ran_booster_scenarios_guard();
	echo '<div class="wrap"><h1>Booster scenarios · 0.1.0 / Core beta.35</h1><p>' . esc_html( '' !== $guard ? $guard : 'Compatible disposable environment.' ) . '</p>';
	echo '<p>Selected: ' . esc_html( '' !== $state['scenario'] ? $state['scenario'] : 'disabled' ) . '; stage: ' . esc_html( (string) $state['stage'] ) . '; target: ' . esc_html( $state['target'] ) . '. Selection persists across cron and browser requests.</p>';
	$catalog = ran_booster_scenarios_catalog();
	if ( isset( $catalog[ $state['scenario'] ] ) ) {
		echo '<p><strong>Expected:</strong> ' . esc_html( $catalog[ $state['scenario'] ]['expected'] ) . '</p>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ran_booster_scenarios_save">';
	wp_nonce_field( 'ran_booster_scenarios_save' );
	echo '<label for="ran-scenario">Scenario </label><select id="ran-scenario" name="scenario">';
	foreach ( ran_booster_scenarios_catalog() as $key => $scenario ) {
		echo '<option value="' . esc_attr( $key ) . '" ' . selected( $state['scenario'], $key, false ) . '>' . esc_html( $scenario['label'] ) . '</option>';
	}
	echo '</select> <label for="ran-target">Target</label><select id="ran-target" name="target">';
	foreach ( array( 'both', 'plugin', 'theme' ) as $target ) {
		echo '<option value="' . esc_attr( $target ) . '" ' . selected( $state['target'], $target, false ) . '>' . esc_html( $target ) . '</option>';
	}
	echo '</select> <button class="button" name="operation" value="select">Select / restart</button> <button class="button" name="operation" value="advance">Advance to stage 1</button> <button class="button" name="operation" value="reset">Disable / reset fixture selection</button></form>';
	echo '<p>Use only ran-booster-scenarios/scenario-plugin and ran-booster-scenarios/scenario-theme. No real credentials needed. Install the documented local stub targets first. Only the bundled harmless plugin/theme archives can be installed through normal WordPress controls. These prove synthetic installation, not real GitHub publishing.</p><p>After changing stage, use Booster’s normal refresh action or cron; existing caches and history are intentionally preserved. Reset removes only fixture selection, not Booster records or history. Logging stays under Booster’s normal settings.</p><h2>Test routes</h2><ul>';
	foreach ( ran_booster_scenarios_routes() as $label => $route ) {
		echo '<li><a href="' . esc_url( admin_url( $route ) ) . '">' . esc_html( $label ) . '</a></li>';
	}
	echo '</ul></div>';
}

/** Visible state on every administrator page, including an incompatible selected fixture. */
function ran_booster_scenarios_notice(): void {
	if ( current_user_can( 'manage_options' ) ) {
		$state = ran_booster_scenarios_state();
		$guard = ran_booster_scenarios_guard();
		echo '<div class="notice notice-warning"><p>Booster test fixture installed. ' . esc_html( '' !== $guard ? $guard : 'Scenario: ' . ( '' !== $state['scenario'] ? $state['scenario'] : 'disabled' ) . ', stage ' . $state['stage'] ) . ' <a href="' . esc_url( admin_url( 'tools.php?page=ran-booster-scenarios' ) ) . '">Scenario controls</a></p></div>';
	}
}

add_filter( 'pre_http_request', 'ran_booster_scenarios_http', PHP_INT_MAX, 3 );
add_action( 'admin_post_ran_booster_scenarios_save', 'ran_booster_scenarios_save' );
add_action( 'admin_notices', 'ran_booster_scenarios_notice' );
add_action(
	'admin_menu',
	static function (): void {
		add_management_page( 'Booster scenarios', 'Booster scenarios', 'manage_options', 'ran-booster-scenarios', 'ran_booster_scenarios_page' );
	}
);
