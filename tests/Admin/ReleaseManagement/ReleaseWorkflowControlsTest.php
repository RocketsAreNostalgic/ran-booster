<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

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
use RAN\Admin\ReleaseManagement\ReleaseWorkflowPresenter;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowRequestController;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Storage\Database;
use RAN\Storage\RepositorySourceGuard;
use RAN\Tests\Admin\ReleaseManagement\GitHub\Support\PluginRepositoryDouble;
use RAN\Tests\Admin\ReleaseManagement\GitHub\Support\ThemeRepositoryDouble;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;
use RAN\Tests\Admin\ReleaseManagement\Support\RepositoryReleaseWorkflowProviderDouble;
use RAN\Tests\Admin\ReleaseManagement\Support\PartialRepositoryReleaseWorkflowProviderDouble;

final class ReleaseWorkflowControlsTest extends TestCase {
	#[Before]
	public function reset_word_press(): void {
		ReleaseManagementFixture::reset_word_press(); }

	public function test_registers_neutral_release_routes_without_adding_core_rows_to_the_public_extension_filter(): void {
		$controls = $this->controls();
		$controls->register();

		self::assertArrayHasKey( 'ran_booster_admin_package_source_choices', $GLOBALS['ran_booster_release_management_test_filters'] );
		self::assertSame(
			array( $controls, 'keep_release_settings_discoverable' ),
			$GLOBALS['ran_booster_release_management_test_filters']['ran_booster_admin_package_source_choices'][0]['callback']
		);
		self::assertSame( 20, $GLOBALS['ran_booster_release_management_test_filters']['ran_booster_admin_package_source_choices'][0]['priority'] );
		self::assertSame( 5, $GLOBALS['ran_booster_release_management_test_filters']['ran_booster_admin_package_source_choices'][0]['accepted_args'] );
		self::assertArrayNotHasKey( 'ran_booster_provider_repository_rows', $GLOBALS['ran_booster_release_management_test_filters'] );
		self::assertArrayHasKey( 'ran_booster_admin_package_release_readiness_actions', $GLOBALS['ran_booster_release_management_test_actions'] );
		self::assertSame(
			array( $controls, 'render_package_release_automation_link' ),
			$GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_package_release_readiness_actions'][0]['callback']
		);
		self::assertSame( 20, $GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_package_release_readiness_actions'][0]['priority'] );
		self::assertSame( 2, $GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_package_release_readiness_actions'][0]['accepted_args'] );
		self::assertArrayHasKey( 'ran_booster_admin_repository_release_sections', $GLOBALS['ran_booster_release_management_test_actions'] );
		self::assertSame(
			array( $controls, 'render_repository_release_sections' ),
			$GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_repository_release_sections'][0]['callback']
		);
		self::assertSame( 20, $GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_repository_release_sections'][0]['priority'] );
		self::assertSame( 2, $GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_repository_release_sections'][0]['accepted_args'] );
		self::assertArrayHasKey( 'admin_post_ran_booster_release_workflow', $GLOBALS['ran_booster_release_management_test_actions'] );
		self::assertCount( 1, $GLOBALS['ran_booster_release_management_test_actions']['admin_post_ran_booster_release_workflow'] );
		self::assertSame( array( $controls, 'handle_workflow' ), $GLOBALS['ran_booster_release_management_test_actions']['admin_post_ran_booster_release_workflow'][0]['callback'] );
		self::assertSame( 10, $GLOBALS['ran_booster_release_management_test_actions']['admin_post_ran_booster_release_workflow'][0]['priority'] );
		self::assertSame( 1, $GLOBALS['ran_booster_release_management_test_actions']['admin_post_ran_booster_release_workflow'][0]['accepted_args'] );
	}

	public function test_presenter_omits_repository_sections_without_a_current_provider_row(): void {
		$presenter = $this->presenter();

		self::assertNull( $presenter->repository_section_projection( array(), 'https://example.test/return', '', null ) );
		self::assertNull(
			$presenter->repository_section_projection(
				array(
					'provider_code' => 'fixture',
					'historical'    => true,
				),
				'https://example.test/return',
				'',
				null
			)
		);
	}

	public function test_registered_workflow_provider_without_metadata_leaves_repository_rows_untouched_without_warning_or_output(): void {
		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$rows     = array(
			'101' => array(
				'provider_code' => 'fixture',
				'repository_id' => '101',
				'details'       => array(),
				'actions'       => array(),
			),
		);
		$output   = '';
		ob_start();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only handler promotes missing-metadata warnings to exceptions.
		set_error_handler(
			static function ( int $severity, string $message ): never {
				throw new \ErrorException( $message, 0, $severity );
			}
		);
		try {
			$actual = $this->controls( provider: $provider, providers: $this->registry_without_metadata( $provider ) )->enrich_repository_rows( $rows, 'fixture', array(), 'https://example.test/return' );
		} finally {
			$output = (string) ob_get_clean();
			restore_error_handler();
		}

		self::assertSame( $rows, $actual );
		self::assertSame( '', $output );
	}

	public function test_capable_edit_keeps_release_asset_selectable_while_other_contexts_remain_unchanged(): void {
		$choices = array( 'release_asset' => array( 'disabled' => true ) );
		$package = new class() { public function provider_code(): string {
				return 'fixture';
		} };

		self::assertFalse( $this->controls()->keep_release_settings_discoverable( $choices, 'edit', 'plugin', $package, 'https://example.test' )['release_asset']['disabled'] );
		self::assertSame( $choices, $this->controls()->keep_release_settings_discoverable( $choices, 'create', 'plugin', $package, 'https://example.test' ) );
		self::assertSame( $choices, $this->controls( registered: false )->keep_release_settings_discoverable( $choices, 'edit', 'plugin', $package, 'https://example.test' ) );
	}

	public function test_handle_workflow_uses_native_and_htmx_redirect_transports(): void {
		$request = $this->request( 'inspect' );
		$_POST   = $request;
		try {
			$this->controls()->handle_workflow();
			self::fail( 'Expected the native redirect to stop execution.' ); // @phpstan-ignore deadCode.unreachable (Retain the failure assertion if the never-returning redirect contract regresses.)
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'native-redirect', $exception->getMessage() );
		}
		$native = (string) $GLOBALS['ran_booster_release_management_test_redirect'];
		self::assertStringStartsWith( 'https://example.test/wp-admin/admin.php?', $native );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $native, PHP_URL_QUERY ), $native_query );
		self::assertSame( 'ran-booster', $native_query['page'] );
		self::assertSame( 'fixture', $native_query['tab'] );
		self::assertSame( 'repositories', $native_query['panel'] );
		self::assertSame( '101', $native_query['repository'] );
		self::assertSame( 'releases', $native_query['repository_view'] );
		self::assertSame( 'ran-booster-repository-release-workflows', \RAN\Admin\ReleaseManagement\wp_parse_url( $native, PHP_URL_FRAGMENT ) );

		try {
			$_POST                      = $request;
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			try {
				$this->controls()->handle_workflow();
				self::fail( 'Expected the HX response to stop execution.' ); // @phpstan-ignore deadCode.unreachable (Retain the failure assertion if the never-returning response contract regresses.)
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'hx-redirect', $exception->getMessage() );
			}
			$header = (string) $GLOBALS['ran_booster_release_management_test_header'];
			self::assertSame(
				'HX-Location: ' . (string) \RAN\Admin\ReleaseManagement\wp_json_encode(
					array(
						'path'   => \RAN\Admin\ReleaseManagement\wp_make_link_relative( $native ),
						'target' => '#wpbody-content',
						'select' => '#wpbody-content',
						'swap'   => 'outerHTML show:none',
					)
				),
				$header
			);
		} finally {
			unset( $_SERVER['HTTP_HX_REQUEST'] );
			$_POST = array();
		}
	}

	public function test_fallback_package_settings_render_the_signed_workflow_result_without_workflow_controls(): void {
		$request                           = $this->request( 'inspect' );
		$request['expected_repository_id'] = 'missing-repository';
		$url                               = $this->controller()->process_workflow_request( $request );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Exercises a URL signed by the immediately preceding control call.

		$package = new class() {
			public function provider_code(): string {
				return 'fixture'; }
			public function type(): string {
				return 'plugin'; }
			public function identifier(): string {
				return 'example/example.php'; }
			public function source_revision(): int {
				return 3; }
		};
		ob_start();
		$this->controls()->render_package_release_automation_link( $package, ReleaseManagementFixture::status() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-release-workflow-result', $html );
		self::assertStringContainsString( 'Booster stopped before contacting the repository provider', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_fallback_package_workflow_notice_requires_an_unchanged_signed_result_and_matching_screen(): void {
		$request                           = $this->request( 'inspect' );
		$request['expected_repository_id'] = 'missing-repository';
		$url                               = $this->controller()->process_workflow_request( $request );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$package        = new class() {
			public function provider_code(): string {
				return 'fixture'; }
			public function type(): string {
				return 'plugin'; }
			public function identifier(): string {
				return 'example/example.php'; }
			public function source_revision(): int {
				return 3; }
		};
		$renders_notice = function ( array $get ) use ( $package ): bool {
			$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Exercises display-only signed-result verification.
			ob_start();
			$this->controls()->render_package_release_automation_link( $package, ReleaseManagementFixture::status() );
			return str_contains( (string) ob_get_clean(), 'data-ran-booster-release-workflow-result' );
		};

		self::assertTrue( $renders_notice( $query ) );
		foreach ( array(
			'ran_booster_release_workflow_result'          => 'workflow_remote_unavailable',
			'ran_booster_release_workflow_success'         => '1',
			'ran_booster_release_workflow_type'            => 'theme',
			'ran_booster_release_workflow_package'         => 'other/other.php',
			'ran_booster_release_workflow_source_revision' => '4',
			'ran_booster_release_workflow_provider'        => 'other',
			'ran_booster_release_workflow_repository'      => '102',
			'ran_booster_release_workflow_channel'         => 'prerelease',
			'ran_booster_release_workflow_failure_stage'   => 'unexpected',
			'ran_booster_release_workflow_diagnostic'      => 'unexpected_runtime_failure',
			'ran_booster_release_workflow_diagnostic_available' => '1',
			'ran_booster_release_workflow_reference'       => str_repeat( 'a', 32 ),
			'ran_booster_release_workflow_message'         => 'Different message.',
			'ran_booster_release_workflow_remediation'     => 'Different remediation.',
			'ran_booster_release_workflow_result_nonce'    => 'wrong',
		) as $field => $value ) {
			$mutated           = $query;
			$mutated[ $field ] = $value;
			self::assertFalse( $renders_notice( $mutated ), $field );
		}
		$wrong_page         = $query;
		$wrong_page['page'] = 'ran-booster-themes';
		self::assertFalse( $renders_notice( $wrong_page ) );
		$wrong_package            = $query;
		$wrong_package['package'] = 'other/other.php';
		self::assertFalse( $renders_notice( $wrong_package ) );
	}

	public function test_passive_rows_remain_untouched_when_the_provider_has_no_complete_workflow_aggregate(): void {
		$rows     = array(
			'101' => array(
				'provider_code'     => 'partial',
				'repository_id'     => '101',
				'repository'        => 'example/example',
				'package_summaries' => array(),
				'details'           => array(),
				'actions'           => array(),
			),
		);
		$controls = $this->controls( provider: new PartialRepositoryReleaseWorkflowProviderDouble() );

		self::assertSame( $rows, $controls->enrich_repository_rows( $rows, 'partial', array(), 'https://example.test/return' ) );
	}

	public function test_incomplete_workflow_provider_does_not_present_repository_automation_as_ready_to_assess(): void {
		$controls = $this->controls( provider: new PartialRepositoryReleaseWorkflowProviderDouble(), source_guard: $this->source_guard( 'partial' ) );
		$row      = array(
			'provider_code'     => 'partial',
			'repository_id'     => '101',
			'repository'        => 'example/example',
			'package_summaries' => array(
				array(
					'type'            => 'plugin',
					'identifier'      => 'example/example.php',
					'source'          => 'branch',
					'source_revision' => 3,
				),
			),
		);

		ob_start();
		$controls->render_repository_release_sections( $row, 'https://example.test/repositories' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Unavailable<', $html );
		self::assertStringNotContainsString( 'Ready to assess', $html );
		self::assertStringContainsString( 'button type="submit" class="button" disabled aria-disabled="true">Assess release setup</button>', $html );
	}

	public function test_passive_repository_render_reads_only_status_and_opaque_preview_without_a_workflow_mutation(): void {
		$key      = str_repeat( 'a', 32 );
		$provider = new RepositoryReleaseWorkflowProviderDouble(
			preview: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
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
			),
			workflow_result: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowResult( 'workflow_inspected', true, $key )
		);
		$url      = $this->controller( provider: $provider )->process_workflow_request( $this->request( 'inspect' ) );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Uses the immediately preceding signed result and opaque preview key.
		$provider->calls        = array();
		$provider->status_reads = 0;
		$row                    = array(
			'provider_code'     => 'fixture',
			'repository_id'     => '101',
			'repository'        => 'example/example',
			'package_summaries' => array(
				array(
					'type'            => 'plugin',
					'identifier'      => 'example/example.php',
					'source'          => 'branch',
					'source_revision' => 3,
				),
			),
		);

		ob_start();
		$this->controls( provider: $provider )->render_repository_release_sections( $row, 'https://example.test/repositories' );
		$html = (string) ob_get_clean();

		self::assertGreaterThan( 0, $provider->status_reads );
		self::assertSame( array( 'preview' ), array_column( $provider->calls, 'operation' ) );
		self::assertStringContainsString( 'Release publishing', $html );
		self::assertStringContainsString( 'example/example</strong> · main', $html );
	}

	public function test_passive_rows_remain_untouched_when_the_capable_provider_has_no_registered_admin_surface(): void {
		$rows = array(
			'101' => array(
				'provider_code'     => 'fixture',
				'repository_id'     => '101',
				'repository'        => 'example/example',
				'package_summaries' => array(
					array(
						'type'            => 'plugin',
						'identifier'      => 'example/example.php',
						'source'          => 'branch',
						'source_revision' => 3,
					),
				),
				'details'           => array(),
				'actions'           => array(),
			),
		);

		self::assertSame( $rows, $this->controls( provider: new RepositoryReleaseWorkflowProviderDouble( admin_surface: false ) )->enrich_repository_rows( $rows, 'fixture', array(), 'https://example.test/return' ) );
	}

	public function test_incomplete_repository_inventory_receives_no_workflow_enrichment(): void {
		$rows = array(
			'101' => array(
				'provider_code'             => 'fixture',
				'repository_id'             => '101',
				'repository'                => 'example/example',
				'package_summaries_omitted' => 1,
				'package_summaries'         => array(
					array(
						'type'            => 'plugin',
						'identifier'      => 'example/example.php',
						'source'          => 'branch',
						'source_revision' => 3,
					),
				),
				'details'                   => array(),
				'actions'                   => array(),
			),
		);

		self::assertSame( $rows, $this->controls()->enrich_repository_rows( $rows, 'fixture', array(), 'https://example.test/return' ) );
	}

	public function test_unavailable_repository_source_keeps_its_diagnostic_code(): void {
		$presenter = $this->presenter( source_guard: $this->unavailable_source_guard() );
		$url       = $this->controller( source_guard: $this->unavailable_source_guard() )->process_workflow_request( $this->request( 'inspect' ) );

		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertStringContainsString( 'repository_source_unavailable', $url );
		self::assertStringNotContainsString( 'repository_release_owner_exists', $url );

		$workflow_view_for = new \ReflectionMethod( ReleaseWorkflowPresenter::class, 'workflow_view_for' );
		$view              = $workflow_view_for->invoke( $presenter, 'plugin', 'example/example.php', 3, '', false, '', 'stable' );

		self::assertTrue( $view['unavailable'] );
		self::assertSame( 'Booster could not safely read this package\'s repository source relationship. Check package storage and retry.', $view['unavailable_reason'] );
		self::assertTrue( $view['forms']['inspect']['disabled'] );
	}

	public function test_release_workflow_repository_action_uses_the_core_namespaced_action_contract(): void {
		$rows   = $this->controls()->enrich_repository_rows(
			array(
				'101' => array(
					'provider_code'     => 'fixture',
					'repository_id'     => '101',
					'repository'        => 'example/example',
					'historical'        => false,
					'package_summaries' => array(
						array(
							'type'            => 'plugin',
							'identifier'      => 'example/example.php',
							'source'          => 'branch',
							'source_revision' => 3,
						),
					),
					'details'           => array(),
					'actions'           => array(),
				),
			),
			'fixture',
			array(),
			'https://example.test/repositories'
		);
		$action = array_values( $rows['101']['actions'] )[0];

		self::assertMatchesRegularExpression( '/\Acore:release-workflow-[a-f0-9]{16}\z/', $action['key'] );
		self::assertSame( $action['key'], $rows['101']['details'][0]['key'] );
		self::assertSame( 'release_workflow', $rows['101']['details'][0]['category'] );
	}

	public function test_repository_projection_uses_the_same_benign_existing_workflow_observation_as_the_repository_panel(): void {
		$status   = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
			'fixture',
			'101',
			false,
			false,
			observation_kind: 'existing_automation_detected',
			observed_at: '2026-08-31T12:00:00Z'
		);
		$controls = $this->controls( provider: new RepositoryReleaseWorkflowProviderDouble( status: $status ) );
		$rows     = $controls->enrich_repository_rows(
			array(
				'101' => array(
					'provider_code'     => 'fixture',
					'repository_id'     => '101',
					'repository'        => 'example/example',
					'historical'        => false,
					'package_summaries' => array(
						array(
							'type'            => 'plugin',
							'identifier'      => 'example/example.php',
							'source'          => 'branch',
							'source_revision' => 3,
						),
					),
					'details'           => array(),
					'actions'           => array(),
				),
			),
			'fixture',
			array(),
			'https://example.test/repositories'
		);

		self::assertSame( 'Existing workflow found', $rows['101']['details'][0]['value'] );
		self::assertSame( 'info', $rows['101']['details'][0]['tone'] );
	}

	public function test_repository_projection_marks_a_repository_relationship_conflict_as_blocked(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( failure_code: 'release_repository_conflict' )
		);
		$rows     = $this->controls( tracking: $tracking )->enrich_repository_rows(
			array(
				'101' => array(
					'provider_code'     => 'fixture',
					'repository_id'     => '101',
					'repository'        => 'example/example',
					'historical'        => false,
					'package_summaries' => array(
						array(
							'type'            => 'plugin',
							'identifier'      => 'example/example.php',
							'source'          => 'branch',
							'source_revision' => 3,
						),
					),
					'details'           => array(),
					'actions'           => array(),
				),
			),
			'fixture',
			array(),
			'https://example.test/repositories'
		);

		self::assertSame( 'Blocked', $rows['101']['details'][0]['value'] );
		self::assertSame( 'warning', $rows['101']['details'][0]['tone'] );
		self::assertNotSame( 'Ready to assess', $rows['101']['details'][0]['value'] );
	}

	public function test_release_workflow_repository_enrichment_honours_remaining_row_capacity_for_full_rows(): void {
		$rows           = $this->controls()->enrich_repository_rows(
			array(
				'101' => array(
					'provider_code'     => 'fixture',
					'repository_id'     => '101',
					'repository'        => 'example/example',
					'historical'        => false,
					'package_summaries' => array(
						array(
							'type'            => 'plugin',
							'identifier'      => 'example/example.php',
							'source'          => 'branch',
							'source_revision' => 3,
						),
					),
					'details'           => array_fill(
						0,
						20,
						array(
							'key'   => 'core-existing-detail',
							'label' => 'Existing',
							'value' => 'safe',
							'tone'  => 'success',
						)
					),
					'actions'           => array(
						'core:existing' => array(
							'key'           => 'core:existing',
							'label'         => 'Existing action',
							'type'          => 'link',
							'url'           => 'https://example.test',
							'hidden'        => array(),
							'disabled'      => false,
							'external'      => false,
							'described_by'  => '',
							'screen_reader' => 'existing',
						),
					),
				),
			),
			'fixture',
			array(),
			'https://example.test/repositories'
		);
		$result_details = $rows['101']['details'] ?? array();

		self::assertCount( 20, $result_details );
		self::assertArrayHasKey( 'core:existing', $rows['101']['actions'] );
		self::assertCount( 1, $rows['101']['actions'] );
		self::assertSame(
			array_fill(
				0,
				20,
				array(
					'key'   => 'core-existing-detail',
					'label' => 'Existing',
					'value' => 'safe',
					'tone'  => 'success',
				)
			),
			$result_details
		);
	}

	public function test_release_workflow_repository_enrichment_adds_one_row_when_one_slot_remains(): void {
		$row_summary    = array(
			'type'            => 'plugin',
			'identifier'      => 'example/example.php',
			'source'          => 'branch',
			'source_revision' => 3,
		);
		$rows           = $this->controls()->enrich_repository_rows(
			array(
				'101' => array(
					'provider_code'     => 'fixture',
					'repository_id'     => '101',
					'repository'        => 'example/example',
					'historical'        => false,
					'package_summaries' => array( $row_summary, $row_summary ),
					'details'           => array_fill(
						0,
						19,
						array(
							'key'   => 'core-existing-detail',
							'label' => 'Existing',
							'value' => 'safe',
							'tone'  => 'success',
						)
					),
					'actions'           => array(
						'core:existing' => array(
							'key'           => 'core:existing',
							'label'         => 'Existing action',
							'type'          => 'link',
							'url'           => 'https://example.test',
							'hidden'        => array(),
							'disabled'      => false,
							'external'      => false,
							'described_by'  => '',
							'screen_reader' => 'existing',
						),
					),
				),
			),
			'fixture',
			array(),
			'https://example.test/repositories'
		);
		$result_details = $rows['101']['details'] ?? array();

		self::assertCount( 20, $result_details );
		self::assertCount( 2, $rows['101']['actions'] );
		self::assertSame(
			1,
			count(
				array_filter(
					$rows['101']['actions'],
					static fn ( array $action ): bool => str_starts_with( (string) ( $action['key'] ?? '' ), 'core:release-workflow-' )
				)
			)
		);
		self::assertSame(
			1,
			count(
				array_filter(
					$result_details,
					static fn ( array $detail ): bool => str_starts_with( (string) ( $detail['key'] ?? '' ), 'core:release-workflow-' )
				)
			)
		);
	}

	public function test_optional_package_helper_renders_nothing_for_missing_or_incomplete_workflow_providers(): void {
		$package = new class() {
			public function provider_code(): string {
				return 'partial'; }
			public function type(): string {
				return 'plugin'; }
			public function identifier(): string {
				return 'example/example.php'; }
			public function source_revision(): int {
				return 3; }
		};

		foreach ( array(
			'missing'    => $this->controls( registered: false ),
			'incomplete' => $this->controls( provider: new PartialRepositoryReleaseWorkflowProviderDouble() ),
		) as $case => $controls ) {
			ob_start();
			$controls->render_package_release_automation_link( $package, ReleaseManagementFixture::status() );
			$html = (string) ob_get_clean();

			self::assertSame( '', $html, $case );
		}
	}

	public function test_signed_workflow_result_preserves_provider_message_and_remediation_for_display(): void {
		$provider = new RepositoryReleaseWorkflowProviderDouble(
			workflow_result: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowResult(
				'workflow_partial',
				false,
				failure_stage: 'repository_mutation',
				diagnostic_code: 'repository_mutation_unverified',
				message: 'Provider-specific workflow message.',
				remediation: 'Provider-specific remediation.'
			)
		);
		$url      = $this->controller( provider: $provider )->process_workflow_request( $this->request( 'inspect' ) );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Exercises the signed PRG result parser.

		$result = $this->controller( provider: $provider )->requested_result();

		self::assertNotNull( $result );
		self::assertSame( 'Provider-specific workflow message.', $result['message'] );
		self::assertSame( 'Provider-specific remediation.', $result['remediation'] );

		$workflow_view_for = new \ReflectionMethod( ReleaseWorkflowPresenter::class, 'workflow_view_for' );
		$view              = $workflow_view_for->invoke(
			$this->presenter( provider: $provider ),
			'plugin',
			'example/example.php',
			3,
			$result['code'],
			$result['successful'],
			'',
			$result['channel'],
			$result['failure_stage'],
			$result['diagnostic_code'],
			$result['diagnostic_available'],
			$result['correlation_reference'],
			$result['message'],
			$result['remediation']
		);

		self::assertSame( 'Provider-specific workflow message.', $view['result_message'] );
		self::assertSame( 'Provider-specific remediation.', $view['result_remediation'] );
	}

	public function test_only_bootstrap_records_expose_outcome_controls_for_the_same_package(): void {
		foreach ( array( '', 'bootstrap' ) as $operation ) {
			$record            = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
				'fixture',
				'101',
				false,
				true,
				'https://fixture.example/pull/1',
				'plugin',
				'example/example.php',
				2,
				$operation
			);
			$provider          = new RepositoryReleaseWorkflowProviderDouble( status: $record );
			$workflow_view_for = new \ReflectionMethod( ReleaseWorkflowPresenter::class, 'workflow_view_for' );
			$view              = $workflow_view_for->invoke( $this->presenter( provider: $provider ), 'plugin', 'example/example.php', 3, '', false, '', 'stable' );

			if ( '' === $operation ) {
				self::assertTrue( $view['unavailable'] );
				self::assertSame( 'blocked', $view['automation_state'] );
				self::assertNull( $view['record'] );
				self::assertSame( array( 'unsupported' => true ), $view['legacy'] );
				self::assertArrayNotHasKey( 'outcome', $view['forms'] );
			} else {
				self::assertFalse( $view['unavailable'] );
				self::assertSame( 'setup_recorded', $view['automation_state'] );
				self::assertSame( array( 'pull_request_url' => 'https://fixture.example/pull/1' ), $view['record'] );
				self::assertNull( $view['legacy'] );
				self::assertArrayHasKey( 'outcome', $view['forms'] );
			}
			self::assertSame( array(), $provider->calls );
		}
	}

	public function test_empty_provider_write_guidance_uses_the_core_fallback(): void {
		$status    = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
			'fixture',
			'101',
			false,
			false,
			credential_choices: array(
				array(
					'id'    => 'credential_1',
					'label' => 'Fixture credential',
				),
			)
		);
		$presenter = $this->presenter( provider: new RepositoryReleaseWorkflowProviderDouble( status: $status ) );

		$workflow_view_for = new \ReflectionMethod( ReleaseWorkflowPresenter::class, 'workflow_view_for' );
		$view              = $workflow_view_for->invoke( $presenter, 'plugin', 'example/example.php', 3, '', false, '', 'stable' );

		self::assertSame(
			'Choose a saved credential that can manage release workflows and open pull requests. Its secret is never stored with this setup.',
			$view['forms']['inspect']['write_guidance']
		);
	}

	private function controller( ?ReleaseTrackingFacadeDouble $tracking = null, ?RepositoryProvider $provider = null, bool $registered = true, ?RepositorySourceGuard $source_guard = null ): ReleaseWorkflowRequestController {
		$provider ??= new RepositoryReleaseWorkflowProviderDouble();
		return new ReleaseWorkflowRequestController( $tracking ?? new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ), new PluginRepositoryDouble( provider_code: $provider->get_metadata()->code->value ), new ThemeRepositoryDouble(), new ProviderRegistry( $registered ? array( $provider ) : array() ), $source_guard ?? $this->source_guard() );
	}

	private function controls( ?ReleaseTrackingFacadeDouble $tracking = null, ?RepositoryProvider $provider = null, bool $registered = true, ?RepositorySourceGuard $source_guard = null, ?ProviderRegistry $providers = null ): ReleaseWorkflowControls {
		$provider ??= new RepositoryReleaseWorkflowProviderDouble();
		return new ReleaseWorkflowControls( $tracking ?? new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ), new PluginRepositoryDouble( provider_code: $provider->get_metadata()->code->value ), new ThemeRepositoryDouble(), $providers ?? new ProviderRegistry( $registered ? array( $provider ) : array() ), $source_guard ?? $this->source_guard() );
	}

	private function registry_without_metadata( RepositoryProvider $provider ): ProviderRegistry {
		$providers = new ProviderRegistry( array( $provider ) );
		( new \ReflectionProperty( ProviderRegistry::class, 'provider_metadata' ) )->setValue( $providers, array() );
		return $providers;
	}

	private function presenter( ?ReleaseTrackingFacadeDouble $tracking = null, ?RepositoryProvider $provider = null, bool $registered = true, ?RepositorySourceGuard $source_guard = null ): ReleaseWorkflowPresenter {
		$provider     ??= new RepositoryReleaseWorkflowProviderDouble();
		$tracking     ??= new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$source_guard ??= $this->source_guard();
		$plugins        = new PluginRepositoryDouble( provider_code: $provider->get_metadata()->code->value );
		$themes         = new ThemeRepositoryDouble();
		$providers      = new ProviderRegistry( $registered ? array( $provider ) : array() );
		$requests       = new ReleaseWorkflowRequestController( $tracking, $plugins, $themes, $providers, $source_guard );

		return new ReleaseWorkflowPresenter( $tracking, $plugins, $themes, $providers, $requests, $source_guard );
	}

	/** @return array<string,string> */
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
			}
			/** @return list<object> */
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of get_results retains the production method contract; these inputs do not affect this controlled result.
			public function get_results( string $query ): array {
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

	private function unavailable_source_guard(): RepositorySourceGuard {
		$database  = new class() { public string $last_error = 'fixture unavailable';
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of prepare retains the production method contract; these inputs do not affect this controlled result.
			public function prepare( string $query, mixed ...$arguments ): string {
				return $query;
			}
			/** @return list<object> */
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of get_results retains the production method contract; these inputs do not affect this controlled result.
			public function get_results( string $query ): array {
				return array();
			} };
		$lifecycle = new class() extends Database { public function require_ready(): void {} };
		return new RepositorySourceGuard( $database, $lifecycle );
	}
}
