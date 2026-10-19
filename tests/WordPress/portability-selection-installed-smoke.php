<?php

declare(strict_types=1);

// Real installed consumers. Every phase is a fresh request; no production override
// or fake uploaded-file predicate. The dashboard phase requires a localhost HTTP host.

use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\AddOn\Portability\PortabilityFacade;
use RAN\AddOn\Portability\PortabilityReviewResult;
use RAN\Admin\PortabilityController;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use RAN\Portability\PortabilityApplicationService;
use RAN\Deployment\DeploymentPolicy;
use RAN\Storage\Database;
use RAN\Portability\TargetPackageReason;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN_Booster_GitHubReplacementFixture\Fixture;

if ( ! class_exists( Fixture::class, false ) ) {
	require_once dirname( __DIR__ ) . '/fixtures/github-replacement/fixture.php';
}
Fixture::guard();
if ( ! defined( 'LOGGED_IN_COOKIE' ) || ! defined( 'AUTH_COOKIE' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' ) || ! current_user_can( 'install_plugins' ) ) {
	throw new RuntimeException( 'Consumer proof requires the disposable-site administrator.' );
}
$ran_booster_container         = require __DIR__ . '/core-container-fixture.php';
$ran_booster_facade            = $ran_booster_container->make( PortabilityFacade::class );
$ran_booster_secrets           = $ran_booster_container->make( SecretsFile::class );
$ran_booster_plugins           = $ran_booster_container->make( PluginRepository::class );
$ran_booster_phase             = $args[0] ?? '';
$ran_booster_migrator          = str_starts_with( $ran_booster_phase, 'migrator-' );
$ran_booster_dashboard_success = str_starts_with( $ran_booster_phase, 'dashboard-success-' );
$ran_booster_identity          = $ran_booster_migrator ? 'ran-migrator-fixture/plugin.php' : 'portability-consumer-proof/plugin.php';
if ( $ran_booster_dashboard_success ) {
	$ran_booster_identity = 'portability-dashboard-proof/plugin.php';
}
$ran_booster_repository = $ran_booster_dashboard_success ? 'fixtures/dashboard-consumer' : 'fixtures/consumer';
$ran_booster_path       = WP_PLUGIN_DIR . '/' . $ran_booster_identity;
$ran_booster_assert     = static function ( bool $condition, string $reason ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed developer-authored CLI assertion, not HTML.
		throw new RuntimeException( 'Installed consumer proof: ' . $reason );
	}
};
$ran_booster_assert( defined( 'RAN_BOOSTER_PORTABILITY_API_VERSION' ) && 4 === RAN_BOOSTER_PORTABILITY_API_VERSION, 'expected public API 4' );
$ran_booster_assert( ! is_link( dirname( $ran_booster_path ) ) && ! is_link( $ran_booster_path ), 'non-symlinked target' );
$ran_booster_candidate = new PortabilityCandidate( 'plugin', $ran_booster_identity, 'Consumer proof', 'gh', 'fixtures/consumer', 'main', null, 'consumer-proof' );
$ran_booster_snapshot  = static function () use ( $ran_booster_secrets, $ran_booster_plugins, $ran_booster_identity, $ran_booster_path, $ran_booster_migrator ): array {
	global $wpdb;
	$tables = array();
	foreach ( array_merge( array( ran_booster_table_name(), Database::attempt_table_name() ), $ran_booster_migrator ? array( $wpdb->prefix . 'wppusher_packages' ) : array() ) as $table ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Cannot snapshot installed package and attempt records.' );
		}
		$tables[] = hash( 'sha256', (string) wp_json_encode( $rows ) );
	}
	$path = $ran_booster_secrets->path();
	try {
		$ran_booster_plugins->booster_plugin_from_file( $ran_booster_identity );
		$managed = true;
	} catch ( PluginNotFound ) {
		$managed = false;
	}
	return array(
		'ciphertext' => null !== $path && is_file( $path ) ? hash_file( 'sha256', $path ) : null,
		'target'     => is_file( $ran_booster_path ) ? hash_file( 'sha256', $ran_booster_path ) : null,
		'managed'    => $managed,
		'tables'     => $tables,
	);
};
$ran_booster_no_calls  = static function () use ( $ran_booster_assert ): void {
	foreach ( Fixture::counters() as $key => $count ) {
		// Fresh bootstrap may construct the approved provider, but must not call it.
		if ( ! str_ends_with( $key, '.factory_calls' ) ) {
			$ran_booster_assert( 0 === $count, 'stale consumer disclosed credentials or called provider' );
		}
	}
};

// Send a genuine authenticated multipart request through WordPress admin-ajax.
// A new session is used only for this request and destroyed afterwards.
$ran_booster_dashboard = static function ( bool $apply, ?array $expectation, string $credential_action = 'import', bool $adopt = true ) use ( $ran_booster_identity, $ran_booster_repository, $ran_booster_assert ): array {
	$blueprint = new PackageBlueprint(
		array( new BlueprintPackage( 'plugin', $ran_booster_identity, 'Consumer proof', 'gh', 'replacement-fixture:' . $ran_booster_repository, $ran_booster_repository, 'main', null ) ),
		array(
			new BlueprintCredential(
				'gh',
				'Consumer import proof',
				'classic',
				array( 'owner' => '' ),
				'ghp_' . str_repeat( 'c', 40 ),
				array(
					array(
						'type'       => 'plugin',
						'identifier' => $ran_booster_identity,
					),
				)
			),
		)
	);
	$archive   = getenv( 'RAN_BOOSTER_CONSUMER_ARCHIVE' );
	if ( ! is_string( $archive ) || ! is_file( $archive ) || is_link( $archive ) || realpath( $archive ) !== $archive ) {
		throw new RuntimeException( 'Private runner-owned archive required.' );
	}
	$sessions                    = WP_Session_Tokens::get_instance( get_current_user_id() );
	$expiry                      = time() + 600;
	$token                       = $sessions->create( $expiry );
	$logged                      = wp_generate_auth_cookie( get_current_user_id(), $expiry, 'logged_in', $token );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged;
	try {
		if ( 0 === filesize( $archive ) ) {
			( new BlueprintArchive() )->write_to( $archive, $blueprint, 'consumer-proof-password' );
		}
		$fields = array(
			'action'                          => $apply ? PortabilityController::APPLY_ACTION : PortabilityController::PREVIEW_ACTION,
			'nonce'                           => wp_create_nonce( $apply ? PortabilityController::APPLY_NONCE_ACTION : PortabilityController::PREVIEW_NONCE_ACTION ),
			'password'                        => 'consumer-proof-password',
			'credential_decisions[0][action]' => $credential_action,
			'row'                             => '0',
			'review_action'                   => 'adopt',
			'adopt'                           => $adopt ? '1' : '0',
			'selected_rows[]'                 => '0',
		);
		if ( null !== $expectation ) {
			$fields['provider_expectation'] = (string) wp_json_encode( $expectation );
		}
		$boundary = 'ran-consumer-' . bin2hex( random_bytes( 12 ) );
		$body     = '';
		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . $value . "\r\n";
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads only this request's generated encrypted proof ZIP.
		$bytes = file_get_contents( $archive );
		$ran_booster_assert( is_string( $bytes ), 'read generated archive' );
		$body    .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"blueprint\"; filename=\"blueprint.zip\"\r\nContent-Type: application/zip\r\n\r\n" . $bytes . "\r\n--" . $boundary . "--\r\n";
		$response = wp_remote_post(
			Fixture::expected_origin() . '/wp-admin/admin-ajax.php',
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
					'Cookie'       => LOGGED_IN_COOKIE . '=' . $logged . '; ' . AUTH_COOKIE . '=' . wp_generate_auth_cookie( get_current_user_id(), $expiry, 'auth', $token ),
				),
				'body'        => $body,
			)
		);
		$ran_booster_assert( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ), 'authenticated dashboard HTTP response' );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$ran_booster_assert( is_array( $decoded ) && true === ( $decoded['success'] ?? null ) && is_array( $decoded['data'] ?? null ), 'dashboard JSON success envelope' );
		if ( ! is_array( $decoded ) || ! is_array( $decoded['data'] ?? null ) ) {
			throw new RuntimeException( 'Dashboard response is invalid.' );
		}
		return $decoded['data'];
	} finally {
		$sessions->destroy( $token );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}
};


// Optional current Migrator consumer. The satellite wrapper owns source setup;
// this block owns only HTTP requests and readback on the same disposable site.
if ( $ran_booster_migrator ) {
	$ran_booster_assert( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && true === WP_HTTP_BLOCK_EXTERNAL && defined( 'WP_ACCESSIBLE_HOSTS' ) && 'localhost' === WP_ACCESSIBLE_HOSTS, 'site must block external HTTP and allow only localhost' );
	if ( 'migrator-preflight' === $ran_booster_phase ) {
		$ran_booster_assert( ! file_exists( dirname( $ran_booster_path ) ), 'fresh Migrator target required' );
		return;
	}
	$ran_booster_state_path    = getenv( 'RAN_BOOSTER_MIGRATOR_HTTP_STATE' );
	$ran_booster_readback_path = getenv( 'RAN_BOOSTER_MIGRATOR_READBACK' );
	foreach ( array( $ran_booster_state_path, $ran_booster_readback_path ) as $ran_booster_private_path ) {
		if ( ! is_string( $ran_booster_private_path ) || ! is_file( $ran_booster_private_path ) || is_link( $ran_booster_private_path ) || realpath( $ran_booster_private_path ) !== $ran_booster_private_path ) {
			throw new RuntimeException( 'Runner-owned private Migrator state/readback files required.' );
		}
	}
	if ( ! is_string( $ran_booster_state_path ) || ! is_string( $ran_booster_readback_path ) ) {
		throw new RuntimeException( 'Migrator proof paths are unavailable.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Exact runner-owned private HTTP session state, never logged.
	$ran_booster_bytes = file_get_contents( $ran_booster_state_path );
	$ran_booster_state = is_string( $ran_booster_bytes ) && '' !== $ran_booster_bytes ? json_decode( $ran_booster_bytes, true ) : array();
	if ( ! is_array( $ran_booster_state ) ) {
		throw new RuntimeException( 'Invalid Migrator session state.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only the mandatory satellite wrapper's final JSON output.
	$ran_booster_bytes    = file_get_contents( $ran_booster_readback_path );
	$ran_booster_readback = is_string( $ran_booster_bytes ) ? json_decode( $ran_booster_bytes, true ) : null;
	if ( ! is_array( $ran_booster_readback ) || 734001 !== ( $ran_booster_readback['source_id'] ?? null ) || true !== ( $ran_booster_readback['route_composed'] ?? null ) ) {
		throw new RuntimeException( 'Qualified current-source wrapper readback required.' );
	}
	$ran_booster_sessions = WP_Session_Tokens::get_instance( get_current_user_id() );
	if ( 'migrator-session' === $ran_booster_phase ) {
		$ran_booster_assert( array() === $ran_booster_state && true === $ran_booster_readback['present'] && is_string( $ran_booster_readback['source_fingerprint'] ), 'fresh seeded Migrator source required' );
		$ran_booster_state = array(
			'token'              => $ran_booster_sessions->create( time() + 1800 ),
			'expiry'             => time() + 1800,
			'user'               => get_current_user_id(),
			'source_fingerprint' => $ran_booster_readback['source_fingerprint'],
		);
	}
	if ( ! is_string( $ran_booster_state['token'] ?? null ) || ! is_int( $ran_booster_state['expiry'] ?? null ) || get_current_user_id() !== ( $ran_booster_state['user'] ?? null ) ) {
		throw new RuntimeException( 'Migrator HTTP session prerequisite is missing.' );
	}
	$ran_booster_http = static function ( ?array $form = null ) use ( $ran_booster_state, $ran_booster_assert ): array {
		$headers = array( 'Cookie' => LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie( get_current_user_id(), $ran_booster_state['expiry'], 'logged_in', $ran_booster_state['token'] ) . '; ' . AUTH_COOKIE . '=' . wp_generate_auth_cookie( get_current_user_id(), $ran_booster_state['expiry'], 'auth', $ran_booster_state['token'] ) );
		$options = array(
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( null !== $form ) {
			$options['headers']['HX-Request'] = 'true';
			$options['headers']['HX-Target']  = $form['target'];
			$options['body']                  = $form['fields'];
			$response                         = wp_remote_post( Fixture::expected_origin() . '/wp-admin/admin-post.php', $options );
		} else {
			$response = wp_remote_get( Fixture::expected_origin() . '/wp-admin/admin.php?page=ran-booster&tab=portability', $options );
		}
		$ran_booster_assert( ! is_wp_error( $response ), 'Migrator authenticated HTTP transport' );
		return array(
			'status' => wp_remote_retrieve_response_code( $response ),
			'body'   => wp_remote_retrieve_body( $response ),
		);
	};
	$ran_booster_form = static function ( string $html, string $operation ) use ( $ran_booster_assert ): array {
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="UTF-8"><table>' . $html . '</table>', LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new DOMXPath( $document );
		$forms = $xpath->query( '//form[input[@name="source_id" and @value="734001"] and input[@name="ran_booster_wp_pusher_migrator_action" and @value="' . $operation . '"]]' );
		$form  = false === $forms ? null : $forms->item( 0 );
		if ( ! $form instanceof DOMElement ) {
			throw new RuntimeException( 'Actual actionable Migrator form is missing.' );
		}
		$fields = json_decode( $form->getAttribute( 'hx-vals' ), true );
		if ( ! is_array( $fields ) ) {
			throw new RuntimeException( 'Actual enhanced Migrator transport fields are missing.' );
		}
		foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
			if ( '' !== $input->getAttribute( 'name' ) ) {
				$fields[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
			}
		}
		$ran_booster_assert( 'ran_booster_wp_pusher_migrator_package' === ( $fields['action'] ?? null ) && is_string( $fields['_wpnonce'] ?? null ), 'actual authorized Migrator form' );
		$fields['credential_id'] = ''; // This helper's exact retained source row is public.
		return array(
			'target' => ltrim( $form->getAttribute( 'hx-target' ), '#' ),
			'fields' => $fields,
		);
	};
	switch ( $ran_booster_phase ) {
		case 'migrator-session':
			break;
		case 'migrator-review':
			$ran_booster_page = $ran_booster_http();
			$ran_booster_assert( 200 === $ran_booster_page['status'], 'real Transporter page' );
			$ran_booster_request = $ran_booster_form( $ran_booster_page['body'], 'review' );
			$ran_booster_assert( ( $ran_booster_request['fields']['source_fingerprint'] ?? null ) === $ran_booster_state['source_fingerprint'], 'form retains actual source fingerprint' );
			$ran_booster_review = $ran_booster_http( $ran_booster_request );
			$ran_booster_assert( 200 === $ran_booster_review['status'], 'actual Migrator review response' );
			$ran_booster_state['apply'] = $ran_booster_form( $ran_booster_review['body'], 'apply' );
			break;
		case 'migrator-stale':
		case 'migrator-apply':
			if ( ! is_array( $ran_booster_state['apply'] ?? null ) ) {
				throw new RuntimeException( 'Actual reviewed Migrator Apply form is required.' );
			}
			$ran_booster_before   = $ran_booster_snapshot();
			$ran_booster_response = $ran_booster_http( $ran_booster_state['apply'] );
			if ( 'migrator-stale' === $ran_booster_phase ) {
				$ran_booster_assert( 422 === $ran_booster_response['status'] && str_contains( $ran_booster_response['body'], esc_html( TargetPackageReason::PROVIDER_SELECTION_CHANGED->message() ) ), 'specific stale Migrator refusal' );
				$ran_booster_assert( ! $ran_booster_before['managed'] && $ran_booster_before === $ran_booster_snapshot(), 'stale Migrator preserves source, target, attempts and ciphertext' );
				wp_cache_delete( Fixture::COUNTERS, 'options' );
				$ran_booster_no_calls();
			} else {
				$ran_booster_assert( 200 === $ran_booster_response['status'] && str_contains( $ran_booster_response['body'], 'data-ran-booster-wp-pusher-migration-complete="true"' ), 'fresh Migrator completes verified adoption and cleanup' );
				wp_cache_delete( Fixture::COUNTERS, 'options' );
				$ran_booster_counts = Fixture::counters();
				$ran_booster_assert( $ran_booster_counts['b.provider_calls'] > 0 && 0 === $ran_booster_counts['a.provider_calls'], 'fresh Migrator Apply checks the selected provider' );
				$ran_booster_after = $ran_booster_snapshot();
				$ran_booster_assert( $ran_booster_before['target'] === $ran_booster_after['target'] && $ran_booster_before['ciphertext'] === $ran_booster_after['ciphertext'], 'Migrator does not replace package bytes or credentials' );
			}
			break;
		case 'migrator-retained':
			$ran_booster_assert( true === $ran_booster_readback['present'] && $ran_booster_state['source_fingerprint'] === $ran_booster_readback['source_fingerprint'], 'stale Apply retains exact source row' );
			break;
		case 'migrator-complete':
			$ran_booster_assert( false === $ran_booster_readback['present'] && null === $ran_booster_readback['source_fingerprint'], 'successful Apply removes exact source row' );
			$ran_booster_package = $ran_booster_plugins->booster_plugin_from_file( $ran_booster_identity );
			$ran_booster_assert( DeploymentPolicy::DISABLED === $ran_booster_package->get_deployment_policy(), 'Migrator adoption persists Disabled' );
			$ran_booster_comparison = $ran_booster_container->make( PortabilityApplicationService::class )->compare_local( new BlueprintPackage( 'plugin', $ran_booster_identity, 'Migrator fixture', 'gh', 'replacement-fixture:fixtures/ran-migrator-fixture', 'fixtures/ran-migrator-fixture', 'main', null ) );
			$ran_booster_assert( 'matching' === $ran_booster_comparison['state'] && 'unknown' === $ran_booster_comparison['installed_equality'], 'Migrator adoption is configuration equality only' );
			$ran_booster_sessions->destroy( $ran_booster_state['token'] );
			$ran_booster_state = array();
			break;
		default:
			throw new RuntimeException( 'Unknown Migrator consumer phase.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Persist only this disposable proof's private bounded HTTP session/form state; no production session store.
	if ( false === file_put_contents( $ran_booster_state_path, wp_json_encode( $ran_booster_state, JSON_THROW_ON_ERROR ), LOCK_EX ) ) {
		throw new RuntimeException( 'Cannot retain private Migrator HTTP state.' );
	}
	return;
}

switch ( $ran_booster_phase ) {
	case 'setup':
	case 'dashboard-success-setup':
		$ran_booster_assert( ! file_exists( dirname( $ran_booster_path ) ), 'fresh target required' );
		$ran_booster_assert( null !== $ran_booster_secrets->path(), 'configured encrypted credential storage required' );
		break;
	case 'seed-credential':
		$ran_booster_assert( ! isset( $ran_booster_secrets->credential_profiles( 'gh' )['consumer-proof'] ), 'fresh credential required' );
		$ran_booster_secrets->save_credential(
			'gh',
			'consumer-proof',
			array(
				'label'         => 'Consumer proof',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'ghp_' . str_repeat( 'd', 40 )
		);
		break;
	case 'api-review':
		$ran_booster_review = $ran_booster_facade->review( $ran_booster_candidate, wp_create_nonce( $ran_booster_facade->nonce_action( 'review', $ran_booster_candidate, null, null ) ) );
		$ran_booster_assert( PortabilityReviewResult::ADOPT === $ran_booster_review->action && null !== $ran_booster_review->provider_expectation, 'actionable public review required' );
		update_option(
			'ran_booster_consumer_api_review',
			array(
				'fingerprint' => $ran_booster_review->fingerprint,
				'expectation' => $ran_booster_review->provider_expectation,
			),
			false
		);
		break;
	case 'api-stale':
		$ran_booster_saved = get_option( 'ran_booster_consumer_api_review' );
		if ( ! is_array( $ran_booster_saved ) || ! is_string( $ran_booster_saved['fingerprint'] ?? null ) || ! is_array( $ran_booster_saved['expectation'] ?? null ) ) {
			throw new RuntimeException( 'Public review prerequisite is missing.' );
		}
		$ran_booster_before = $ran_booster_snapshot();
		$ran_booster_result = $ran_booster_facade->apply( $ran_booster_candidate, $ran_booster_saved['fingerprint'], wp_create_nonce( $ran_booster_facade->nonce_action( 'apply', $ran_booster_candidate, $ran_booster_saved['fingerprint'], $ran_booster_saved['expectation'] ) ), $ran_booster_saved['expectation'] );
		$ran_booster_assert( 'blocked' === $ran_booster_result->status && 'provider_selection_changed' === $ran_booster_result->reason && ! $ran_booster_result->target_verified, 'stale public Apply must refuse specifically' );
		$ran_booster_assert( ! $ran_booster_before['managed'] && $ran_booster_before === $ran_booster_snapshot(), 'public refusal must preserve target and ciphertext' );
		$ran_booster_no_calls();
		break;
	case 'api-adopt':
		$ran_booster_saved = get_option( 'ran_booster_consumer_api_review' );
		if ( ! is_array( $ran_booster_saved ) || ! is_string( $ran_booster_saved['fingerprint'] ?? null ) || ! is_array( $ran_booster_saved['expectation'] ?? null ) ) {
			throw new RuntimeException( 'Fresh public review prerequisite is missing.' );
		}
		$ran_booster_before = $ran_booster_snapshot();
		$ran_booster_result = $ran_booster_facade->apply( $ran_booster_candidate, $ran_booster_saved['fingerprint'], wp_create_nonce( $ran_booster_facade->nonce_action( 'apply', $ran_booster_candidate, $ran_booster_saved['fingerprint'], $ran_booster_saved['expectation'] ) ), $ran_booster_saved['expectation'] );
		$ran_booster_assert( 'adopted' === $ran_booster_result->status && $ran_booster_result->target_verified, 'fresh public adoption must succeed' );
		$ran_booster_counts = Fixture::counters();
		$ran_booster_assert( $ran_booster_counts['b.provider_calls'] > 0 && $ran_booster_counts['b.credential_material_returns'] > 0, 'fresh Apply must recheck current provider and credential' );
		$ran_booster_after = $ran_booster_snapshot();
		$ran_booster_assert( $ran_booster_before['target'] === $ran_booster_after['target'] && $ran_booster_before['ciphertext'] === $ran_booster_after['ciphertext'], 'adoption must not replace installed bytes or credential' );
		break;
	case 'adoption-readback':
	case 'dashboard-success-readback':
		$ran_booster_package = $ran_booster_plugins->booster_plugin_from_file( $ran_booster_identity );
		$ran_booster_assert( DeploymentPolicy::DISABLED === $ran_booster_package->get_deployment_policy(), 'fresh request must observe Disabled adoption' );
		$ran_booster_comparison = $ran_booster_container->make( PortabilityApplicationService::class )->compare_local( new BlueprintPackage( 'plugin', $ran_booster_identity, 'Consumer proof', 'gh', 'replacement-fixture:' . $ran_booster_repository, $ran_booster_repository, 'main', null ) );
		$ran_booster_assert( 'matching' === $ran_booster_comparison['state'] && 'unknown' === $ran_booster_comparison['installed_equality'], 'matching configuration is not verified installed equality' );
		if ( $ran_booster_dashboard_success ) {
			$ran_booster_imported = get_option( 'ran_booster_consumer_dashboard_import' );
			$ran_booster_assert( is_string( $ran_booster_imported ) && $ran_booster_imported === $ran_booster_package->get_credential_id(), 'fresh request retains the explicitly imported credential binding' );
			$ran_booster_material = $ran_booster_secrets->credential_material( 'gh', $ran_booster_package->get_credential_id() );
			$ran_booster_assert( 'ghp_' . str_repeat( 'c', 40 ) === ( $ran_booster_material['secret'] ?? null ), 'fresh request reads the exact synthetic imported credential' );
			unset( $ran_booster_material );
		}
		break;
	case 'dashboard-review':
	case 'dashboard-success-review':
		$ran_booster_counts_before = Fixture::counters();
		$ran_booster_data          = $ran_booster_dashboard( false, null );
		$ran_booster_html          = $ran_booster_data['html'] ?? null;
		$ran_booster_assert( is_string( $ran_booster_html ), 'dashboard HTML review required' );
		if ( ! is_string( $ran_booster_html ) ) {
			throw new RuntimeException( 'Missing dashboard review.' );
		}
		$ran_booster_document = new DOMDocument();
		$ran_booster_document->loadHTML( '<?xml encoding="UTF-8">' . $ran_booster_html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$ran_booster_rows = ( new DOMXPath( $ran_booster_document ) )->query( '//tr[@data-portability-row="0"]' );
		$ran_booster_row  = false === $ran_booster_rows ? null : $ran_booster_rows->item( 0 );
		$ran_booster_assert( $ran_booster_row instanceof DOMElement && 'adopt' === $ran_booster_row->getAttribute( 'data-portability-action' ), 'actionable uploaded dashboard review required' );
		if ( ! $ran_booster_row instanceof DOMElement ) {
			throw new RuntimeException( 'Missing dashboard row.' );
		}
		$ran_booster_expectation = json_decode( $ran_booster_row->getAttribute( 'data-portability-provider-expectation' ), true );
		$ran_booster_assert( is_array( $ran_booster_expectation ), 'real dashboard context required' );
		update_option( 'ran_booster_consumer_dashboard_review', $ran_booster_expectation, false );
		wp_cache_delete( Fixture::COUNTERS, 'options' );
		$ran_booster_counts_after = Fixture::counters();
		$ran_booster_assert( $ran_booster_counts_after['a.credential_material_returns'] + $ran_booster_counts_after['b.credential_material_returns'] > $ran_booster_counts_before['a.credential_material_returns'] + $ran_booster_counts_before['b.credential_material_returns'], 'dashboard preview really used the temporary imported credential' );
		break;
	case 'dashboard-success-no-consent':
	case 'dashboard-success-apply':
		$ran_booster_saved = get_option( 'ran_booster_consumer_dashboard_review' );
		if ( ! is_array( $ran_booster_saved ) ) {
			throw new RuntimeException( 'Fresh dashboard review prerequisite is missing.' );
		}
		$ran_booster_before = $ran_booster_snapshot();
		$ran_booster_assert( ! $ran_booster_before['managed'], 'dashboard success needs a distinct unmanaged target' );
		if ( 'dashboard-success-no-consent' === $ran_booster_phase ) {
			foreach ( array( array( 'leave', true ), array( 'import', false ) ) as [ $ran_booster_decision, $ran_booster_adopt ] ) {
				$ran_booster_data = $ran_booster_dashboard( true, $ran_booster_saved, $ran_booster_decision, $ran_booster_adopt );
				$ran_booster_assert( 'skipped' === ( $ran_booster_data['status'] ?? null ) && 'none' === ( $ran_booster_data['credential_state'] ?? null ), 'missing import or adoption consent must skip' );
				$ran_booster_assert( $ran_booster_before === $ran_booster_snapshot(), 'unconsented dashboard action must preserve bytes, ciphertext and rows' );
			}
			wp_cache_delete( Fixture::COUNTERS, 'options' );
			$ran_booster_no_calls();
			break;
		}
		$ran_booster_profiles_before = array_keys( $ran_booster_secrets->credential_profiles( 'gh' ) );
		$ran_booster_data            = $ran_booster_dashboard( true, $ran_booster_saved );
		$ran_booster_assert( 'adopted' === ( $ran_booster_data['status'] ?? null ) && 'transferred_available' === ( $ran_booster_data['credential_state'] ?? null ), 'real dashboard Apply must adopt and import the selected credential' );
		$ran_booster_after = $ran_booster_snapshot();
		$ran_booster_assert( $ran_booster_after['managed'] && $ran_booster_before['target'] === $ran_booster_after['target'] && $ran_booster_before['ciphertext'] !== $ran_booster_after['ciphertext'], 'dashboard adoption preserves package bytes and persists credential import' );
		$ran_booster_assert( $ran_booster_before['tables'][1] === $ran_booster_after['tables'][1] && ! is_plugin_active( $ran_booster_identity ), 'dashboard adoption neither deploys nor activates the target' );
		$ran_booster_added = array_values( array_diff( array_keys( $ran_booster_secrets->credential_profiles( 'gh' ) ), $ran_booster_profiles_before ) );
		$ran_booster_assert( 1 === count( $ran_booster_added ), 'dashboard imports exactly one new credential profile' );
		$ran_booster_package = $ran_booster_plugins->booster_plugin_from_file( $ran_booster_identity );
		$ran_booster_assert( ( $ran_booster_added[0] ?? null ) === $ran_booster_package->get_credential_id(), 'dashboard binds the imported profile to its target' );
		update_option( 'ran_booster_consumer_dashboard_import', $ran_booster_package->get_credential_id(), false );
		wp_cache_delete( Fixture::COUNTERS, 'options' );
		$ran_booster_counts = Fixture::counters();
		$ran_booster_assert( $ran_booster_counts['b.provider_calls'] > 0 && $ran_booster_counts['b.credential_material_returns'] > 0 && 0 === $ran_booster_counts['a.provider_calls'], 'dashboard Apply freshly verifies through B with the imported material' );
		break;
	case 'dashboard-stale':
		$ran_booster_saved = get_option( 'ran_booster_consumer_dashboard_review' );
		if ( ! is_array( $ran_booster_saved ) ) {
			throw new RuntimeException( 'Dashboard review prerequisite is missing.' );
		}
		$ran_booster_before = $ran_booster_snapshot();
		$ran_booster_data   = $ran_booster_dashboard( true, $ran_booster_saved );
		$ran_booster_assert( 'skipped' === ( $ran_booster_data['status'] ?? null ) && TargetPackageReason::PROVIDER_SELECTION_CHANGED->message() === ( $ran_booster_data['message'] ?? null ), 'stale dashboard Apply must refuse specifically' );
		$ran_booster_assert( ! $ran_booster_before['managed'] && $ran_booster_before === $ran_booster_snapshot(), 'dashboard refusal must preserve target and ciphertext before credential import' );
		// Counter reads in this CLI process must bypass its pre-HTTP option cache.
		wp_cache_delete( Fixture::COUNTERS, 'options' );
		$ran_booster_no_calls();
		break;
	default:
		throw new RuntimeException( 'Unknown consumer proof phase.' );
}
