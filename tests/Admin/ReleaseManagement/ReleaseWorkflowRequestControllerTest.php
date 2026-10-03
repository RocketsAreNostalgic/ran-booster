<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';
require_once __DIR__ . '/Support/ReleaseTrackingFacadeDouble.php';
require_once __DIR__ . '/Support/RepositoryReleaseWorkflowProviderDouble.php';
require_once __DIR__ . '/Support/PartialRepositoryReleaseWorkflowProviderDouble.php';
require_once __DIR__ . '/GitHub/Support/PluginRepositoryDouble.php';
require_once __DIR__ . '/GitHub/Support/ThemeRepositoryDouble.php';
require_once __DIR__ . '/../../Storage/StorageTestEnvironment.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowControls;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowRequestController;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Storage\Database;
use RAN\Storage\RepositorySourceGuard;
use Tests\Admin\ReleaseManagement\GitHub\Support\PluginRepositoryDouble;
use Tests\Admin\ReleaseManagement\GitHub\Support\ThemeRepositoryDouble;
use Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;
use Tests\Admin\ReleaseManagement\Support\RepositoryReleaseWorkflowProviderDouble;
use Tests\Admin\ReleaseManagement\Support\PartialRepositoryReleaseWorkflowProviderDouble;

final class ReleaseWorkflowRequestControllerTest extends TestCase {
	#[Before]
	public function reset_word_press(): void {
		ReleaseManagementFixture::reset_word_press(); }

	public function test_non_git_hub_fixture_completes_all_three_operations_through_the_single_neutral_route(): void {
		foreach ( array( 'inspect', 'setup', 'outcome' ) as $operation ) {
			$preview  = ( 'setup' === $operation ) ? str_repeat( 'a', 32 ) : '';
			$provider = $this->provider_for( $operation, $preview );
			$url      = $this->controller( provider: $provider )->process_workflow_request( $this->request( $operation, $preview ) );
			self::assertStringContainsString( 'ran_booster_release_workflow_result=workflow_' . $operation . '_complete', $url );
			$call = $provider->calls[ array_key_last( $provider->calls ) ];
			self::assertSame( $operation, $call['operation'] );
			self::assertSame( 'credential_1', $call['credential_id'] );
			self::assertSame( 'fixture', $provider->get_metadata()->code->value );
		}
	}

	public function test_forged_legacy_and_prerelease_operations_fail_before_provider_or_preflight_access(): void {
		foreach ( array( 'update_inspect', 'update_setup', 'inspect' ) as $operation ) {
			$provider                         = new RepositoryReleaseWorkflowProviderDouble();
			$tracking                         = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
			$request                          = $this->request( $operation );
			$request['booster_credential_id'] = array( 'forged-secret' );
			if ( 'inspect' === $operation ) {
				$request['release_channel'] = 'prerelease';
			}
			$url = $this->controller( tracking: $tracking, provider: $provider )->process_workflow_request( $request );
			self::assertStringContainsString( 'workflow_invalid_request', $url );
			self::assertSame( array(), $provider->calls );
			self::assertSame( array(), $tracking->calls );
			self::assertSame( 0, $tracking->status_reads );
			self::assertSame( 0, $provider->status_reads );
		}
	}

	public function test_workflow_provider_exception_becomes_a_signed_unavailable_result_without_a_workflow_operation(): void {
		$provider                     = new RepositoryReleaseWorkflowProviderDouble();
		$provider->throw_on_operation = true;
		$url                          = $this->controller( provider: $provider )->process_workflow_request( $this->request( 'inspect' ) );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verifies the immediately preceding signed PRG result.
		$result = $this->controller( provider: $provider )->requested_result();

		self::assertSame( 'workflow_remote_unavailable', $result['code'] );
		self::assertSame( 'unexpected', $result['failure_stage'] );
		self::assertSame( 'unexpected_runtime_failure', $result['diagnostic_code'] );
		self::assertSame( array(), $provider->calls );
	}

	public function test_workflow_result_falls_back_to_the_package_release_asset_settings_when_the_repository_cannot_be_resolved(): void {
		$request                           = $this->request( 'inspect' );
		$request['expected_repository_id'] = 'missing-repository';
		$url                               = $this->controller()->process_workflow_request( $request );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $query );

		self::assertSame( 'ran-booster-plugins', $query['page'] );
		self::assertSame( 'example/example.php', $query['package'] );
		self::assertSame( 'release_asset', $query['source_view'] );
		self::assertSame( '1', $query['ran_booster_open_advanced'] );
		self::assertArrayNotHasKey( 'repository_view', $query );
		self::assertSame( 'ran-booster-advanced-source-settings', \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_FRAGMENT ) );
	}

	public function test_workflow_result_returns_to_the_exact_repository_release_view(): void {
		$url = $this->controller()->process_workflow_request( $this->request( 'inspect' ) );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $query );

		self::assertSame( 'repositories', $query['panel'] );
		self::assertSame( '101', $query['repository'] );
		self::assertSame( 'releases', $query['repository_view'] );
		self::assertArrayNotHasKey( 'source_view', $query );
		self::assertArrayNotHasKey( 'ran_booster_open_advanced', $query );
		self::assertSame( 'ran-booster-repository-release-workflows', \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_FRAGMENT ) );
	}

	public function test_missing_aggregate_and_wrong_authority_fail_closed_without_provider_calls(): void {
		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$url      = $this->controller( provider: $provider, registered: false )->process_workflow_request( $this->request( 'inspect' ) );
		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertSame( array(), $provider->calls );

		foreach ( array(
			'expected_provider'        => 'other',
			'expected_repository_id'   => 'other',
			'expected_source_revision' => '4',
		) as $field => $value ) {
			$provider          = new RepositoryReleaseWorkflowProviderDouble();
			$request           = $this->request( 'inspect' );
			$request[ $field ] = $value;
			$url               = $this->controller( provider: $provider )->process_workflow_request( $request );
			self::assertStringContainsString( 'workflow_invalid_request', $url );
			self::assertSame( array(), $provider->calls );
		}
	}

	public function test_partial_workflow_dependency_is_rejected_before_any_workflow_operation(): void {
		$provider                     = new PartialRepositoryReleaseWorkflowProviderDouble();
		$request                      = $this->request( 'inspect' );
		$request['expected_provider'] = 'partial';
		$request['_wpnonce']          = 'nonce-for-ran-booster-release-workflow-inspect-' . hash( 'sha256', (string) \RAN\Admin\ReleaseManagement\wp_json_encode( array( 'partial', '101', 'plugin', 'example/example.php', 3, '' ) ) );
		$url                          = $this->controller( provider: $provider )->process_workflow_request( $request );

		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertStringContainsString( 'provider_unavailable', $url );
	}

	public function test_registered_workflow_provider_without_metadata_fails_closed_without_warning_or_output(): void {
		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$output   = '';
		ob_start();
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only handler promotes missing-metadata warnings to exceptions.
		set_error_handler(
			static function ( int $severity, string $message ): never {
				throw new \ErrorException( $message, 0, $severity );
			}
		);
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.Security.EscapeOutput.ExceptionNotEscaped
		try {
			$url = $this->controller( provider: $provider, providers: $this->registry_without_metadata( $provider ) )->process_workflow_request( $this->request( 'inspect' ) );
		} finally {
			$output = (string) ob_get_clean();
			restore_error_handler();
		}

		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertStringContainsString( 'provider_unavailable', $url );
		self::assertSame( array(), $provider->calls );
		self::assertSame( '', $output );
	}

	public function test_malformed_authority_fields_and_expired_nonce_do_not_reach_a_provider_operation(): void {
		$cases = array(
			'operation'  => array( 'workflow_operation', 'retired_operation' ),
			'type'       => array( 'expected_type', 'theme' ),
			'identifier' => array( 'expected_identifier', 'other/other.php' ),
			'preview'    => array( 'preview_key', 'not-a-preview-key' ),
		);
		foreach ( $cases as $case ) {
			$provider            = new RepositoryReleaseWorkflowProviderDouble();
			$request             = $this->request( 'inspect' );
			$request[ $case[0] ] = $case[1];
			$this->controller( provider: $provider )->process_workflow_request( $request );
			self::assertSame( array(), $provider->calls, $case[0] );
		}

		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$GLOBALS['ran_booster_release_management_test_nonce_age'] = 2;
		$this->controller( provider: $provider )->process_workflow_request( $this->request( 'inspect' ) );
		self::assertSame( array(), $provider->calls );
	}

	public function test_rejected_preview_and_preflight_do_not_invoke_a_write_operation(): void {
		$key      = str_repeat( 'a', 32 );
		$provider = new RepositoryReleaseWorkflowProviderDouble(
			preview: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
				$key,
				'fixture',
				'101',
				'bootstrap',
				'stable',
				'other/repository',
				array(
					'repository'      => 'example/example',
					'default_branch'  => 'main',
					'base_sha'        => str_repeat( 'b', 40 ),
					'pack_version'    => '1.0.0',
					'template_digest' => str_repeat( 'c', 64 ),
				),
				array()
			)
		);
		$this->controller( provider: $provider )->process_workflow_request( $this->request( 'setup', $key ) );

		self::assertSame( array( 'preview' ), array_column( $provider->calls, 'operation' ) );
	}

	public function test_wrong_tuple_and_nonce_refuse_before_provider_status_or_workflow_calls(): void {
		foreach ( array(
			'provider'   => array( 'expected_provider', 'other' ),
			'repository' => array( 'expected_repository_id', 'other' ),
			'revision'   => array( 'expected_source_revision', '4' ),
			'nonce'      => array( '_wpnonce', 'wrong' ),
		) as $case => $change ) {
			$provider              = new RepositoryReleaseWorkflowProviderDouble();
			$request               = $this->request( 'inspect' );
			$request[ $change[0] ] = $change[1];
			$this->controller( provider: $provider )->process_workflow_request( $request );

			self::assertSame( array(), $provider->calls, $case );
			self::assertSame( 0, $provider->status_reads, $case );
		}
	}

	public function test_wrong_credential_and_preview_tuple_refuse_before_any_workflow_remote_operation(): void {
		$key                              = str_repeat( 'a', 32 );
		$provider                         = new RepositoryReleaseWorkflowProviderDouble();
		$request                          = $this->request( 'setup', $key );
		$request['booster_credential_id'] = 'not-a-saved-credential';
		$url                              = $this->controller( provider: $provider )->process_workflow_request( $request );

		self::assertStringContainsString( 'workflow_unauthorised', $url );
		self::assertSame( 1, $provider->status_reads );
		self::assertSame( array(), $provider->calls );

		$provider = new RepositoryReleaseWorkflowProviderDouble(
			preview: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
				$key,
				'other',
				'101',
				'bootstrap',
				'stable',
				'example/example',
				array(
					'repository'      => 'example/example',
					'default_branch'  => 'main',
					'base_sha'        => str_repeat( 'b', 40 ),
					'pack_version'    => '1.0.0',
					'template_digest' => str_repeat( 'c', 64 ),
				),
				array()
			)
		);
		$this->controller( provider: $provider )->process_workflow_request( $this->request( 'setup', $key ) );

		self::assertSame( array( 'preview' ), array_column( $provider->calls, 'operation' ) );
	}

	public function test_anonymous_public_inspection_passes_null_credential_and_permissions_and_nonce_do_not_reach_provider(): void {
		$provider                         = new RepositoryReleaseWorkflowProviderDouble();
		$request                          = $this->request( 'inspect' );
		$request['booster_credential_id'] = '';
		$this->controller( provider: $provider )->process_workflow_request( $request );
		self::assertSame( null, $provider->calls[0]['credential_id'] );

		ReleaseManagementFixture::reset_word_press();
		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'manage_options' );
		$this->controller( provider: $provider )->process_workflow_request( $this->request( 'inspect' ) );
		self::assertSame( array(), $provider->calls );

		ReleaseManagementFixture::reset_word_press();
		$request             = $this->request( 'inspect' );
		$request['_wpnonce'] = 'wrong';
		$this->controller( provider: $provider )->process_workflow_request( $request );
		self::assertSame( array(), $provider->calls );
	}

	public function test_setup_uses_the_preview_channel_for_core_preflight(): void {
		$key                                    = str_repeat( 'a', 32 );
		$preview                                = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
			$key,
			'fixture',
			'101',
			'bootstrap',
			'stable',
			'example/example',
			array(
				'repository'      => 'example/example',
				'default_branch'  => 'main',
				'base_sha'        => str_repeat( 'b', 40 ),
				'pack_version'    => '1.0.0',
				'template_digest' => str_repeat( 'c', 64 ),
			),
			array()
		);
		$provider                               = new RepositoryReleaseWorkflowProviderDouble( preview: $preview );
		$tracking                               = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$request                                = $this->request( 'setup', $key );
		$request['core_preflight_nonce_stable'] = 'preflight-stable';
		$this->controller( tracking: $tracking, provider: $provider )->process_workflow_request( $request );

		self::assertSame( array( 'assessment_preflight', 'plugin', 'example/example.php', 3, 'stable', 'preflight-stable' ), $tracking->calls[0] );
		self::assertSame( 'setup', $provider->calls[1]['operation'] );
	}

	public function test_same_package_identity_can_reconcile_an_occupied_workflow_record_after_the_source_revision_advances(): void {
		$record   = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
			'fixture',
			'101',
			false,
			true,
			'https://fixture.example/pull/1',
			'plugin',
			'example/example.php',
			2,
			'bootstrap',
			credential_choices: array(
				array(
					'id'    => 'credential_1',
					'label' => 'Fixture credential',
				),
			)
		);
		$provider = new RepositoryReleaseWorkflowProviderDouble( status: $record );

		$this->controller( provider: $provider )->process_workflow_request( $this->request( 'outcome' ) );

		self::assertSame( array( 'outcome' ), array_column( $provider->calls, 'operation' ) );
	}

	public function test_retired_workflow_record_cannot_invoke_outcome_for_the_same_package(): void {
		$record   = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
			'fixture',
			'101',
			false,
			true,
			'https://fixture.example/pull/1',
			'plugin',
			'example/example.php',
			3,
			'',
			credential_choices: array(
				array(
					'id'    => 'credential_1',
					'label' => 'Fixture credential',
				),
			)
		);
		$provider = new RepositoryReleaseWorkflowProviderDouble( status: $record );

		$url = $this->controller( provider: $provider )->process_workflow_request( $this->request( 'outcome' ) );

		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertStringContainsString( 'package_source_changed', $url );
		self::assertSame( array(), $provider->calls );
	}

	public function test_different_package_identity_cannot_reconcile_an_occupied_workflow_record(): void {
		$record   = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
			'fixture',
			'101',
			false,
			true,
			'https://fixture.example/pull/1',
			'plugin',
			'other/other.php',
			2,
			'bootstrap',
			credential_choices: array(
				array(
					'id'    => 'credential_1',
					'label' => 'Fixture credential',
				),
			)
		);
		$provider = new RepositoryReleaseWorkflowProviderDouble( status: $record );

		$this->controller( provider: $provider )->process_workflow_request( $this->request( 'outcome' ) );

		self::assertSame( array(), $provider->calls );
	}

	private function controller( ?ReleaseTrackingFacadeDouble $tracking = null, ?RepositoryProvider $provider = null, bool $registered = true, ?RepositorySourceGuard $source_guard = null, ?ProviderRegistry $providers = null ): ReleaseWorkflowRequestController {
		$provider ??= new RepositoryReleaseWorkflowProviderDouble();
		return new ReleaseWorkflowRequestController( $tracking ?? new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ), new PluginRepositoryDouble( provider_code: $provider->get_metadata()->code->value ), new ThemeRepositoryDouble(), $providers ?? new ProviderRegistry( $registered ? array( $provider ) : array() ), $source_guard ?? $this->source_guard() );
	}

	private function registry_without_metadata( RepositoryProvider $provider ): ProviderRegistry {
		$providers = new ProviderRegistry( array( $provider ) );
		( new \ReflectionProperty( ProviderRegistry::class, 'provider_metadata' ) )->setValue( $providers, array() );
		return $providers;
	}

	private function provider_for( string $operation, string $preview_key ): RepositoryReleaseWorkflowProviderDouble {
		$record  = ( 'outcome' === $operation )
			? new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
				'fixture',
				'101',
				true,
				true,
				'https://fixture.example/pull/1',
				'plugin',
				'example/example.php',
				3,
				'bootstrap',
				credential_choices: array(
					array(
						'id'    => 'credential_1',
						'label' => 'Fixture credential',
					),
				)
			) : null;
		$preview = '' !== $preview_key
			? new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
				$preview_key,
				'fixture',
				'101',
				'bootstrap',
				'stable',
				'example/example',
				array(
					'repository'      => 'example/example',
					'default_branch'  => 'main',
					'base_sha'        => str_repeat( 'b', 40 ),
					'pack_version'    => '1.0.0',
					'template_digest' => str_repeat( 'c', 64 ),
				),
				array()
			) : null;
		return new RepositoryReleaseWorkflowProviderDouble( preview: $preview, status: $record );
	}

	private function request( string $operation, string $preview = '' ): array {
		$request = array(
			'workflow_operation'       => $operation,
			'expected_provider'        => 'fixture',
			'expected_repository_id'   => '101',
			'expected_type'            => 'plugin',
			'expected_identifier'      => 'example/example.php',
			'expected_source_revision' => '3',
			'booster_credential_id'    => 'credential_1',
			'confirm_repository'       => 'example/example',
			'preview_key'              => $preview,
		);
		if ( 'inspect' === $operation ) {
			$request['release_channel']             = 'stable';
			$request['core_preflight_nonce_stable'] = 'preflight-stable'; }
		if ( 'setup' === $operation ) {
			$request['core_preflight_nonce_stable'] = 'preflight-stable'; }
		$request['_wpnonce'] = 'nonce-for-ran-booster-release-workflow-' . $operation . '-' . hash( 'sha256', (string) \RAN\Admin\ReleaseManagement\wp_json_encode( array( 'fixture', '101', 'plugin', 'example/example.php', 3, $preview ) ) );
		return $request;
	}

	private function source_guard( string $provider_code = 'fixture' ): RepositorySourceGuard {
		$database  = new class( $provider_code ) { public string $last_error = '';
			public function __construct( private string $provider_code ) {}
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of prepare retains the production method contract; these inputs do not affect this controlled result.
			public function prepare( string $query, mixed ...$arguments ): string {
				return $query;
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of get_results retains the production method contract; these inputs do not affect this controlled result.
			} public function get_results( string $query ): array {
				return array(
					(object) array(
						'type'                   => 1,
						'package'                => 'example/example.php',
						'source'                 => 'branch',
						'provider'               => $this->provider_code,
						'provider_repository_id' => '101',
					),
				);
			} };
		$lifecycle = new class() extends Database { public function require_ready(): void {} };
		return new RepositorySourceGuard( $database, $lifecycle );
	}
}
