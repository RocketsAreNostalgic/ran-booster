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

final class ReleaseWorkflowControlsTest extends TestCase {
	#[Before]
	public function resetWordPress(): void {
		ReleaseManagementFixture::resetWordPress(); }

	public function testRegistersNeutralReleaseRoutesWithoutAddingCoreRowsToThePublicExtensionFilter(): void {
		$controls = $this->controls();
		$controls->register();

		self::assertArrayHasKey( 'ran_booster_admin_package_source_choices', $GLOBALS['ran_booster_release_management_test_filters'] );
		self::assertSame(
			array( $controls, 'keepReleaseSettingsDiscoverable' ),
			$GLOBALS['ran_booster_release_management_test_filters']['ran_booster_admin_package_source_choices'][0]['callback']
		);
		self::assertSame( 20, $GLOBALS['ran_booster_release_management_test_filters']['ran_booster_admin_package_source_choices'][0]['priority'] );
		self::assertSame( 5, $GLOBALS['ran_booster_release_management_test_filters']['ran_booster_admin_package_source_choices'][0]['accepted_args'] );
		self::assertArrayNotHasKey( 'ran_booster_provider_repository_rows', $GLOBALS['ran_booster_release_management_test_filters'] );
		self::assertArrayHasKey( 'ran_booster_admin_package_release_readiness_actions', $GLOBALS['ran_booster_release_management_test_actions'] );
		self::assertSame(
			array( $controls, 'renderPackageReleaseAutomationLink' ),
			$GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_package_release_readiness_actions'][0]['callback']
		);
		self::assertSame( 20, $GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_package_release_readiness_actions'][0]['priority'] );
		self::assertSame( 2, $GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_package_release_readiness_actions'][0]['accepted_args'] );
		self::assertArrayHasKey( 'ran_booster_admin_repository_release_sections', $GLOBALS['ran_booster_release_management_test_actions'] );
		self::assertSame(
			array( $controls, 'renderRepositoryReleaseSections' ),
			$GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_repository_release_sections'][0]['callback']
		);
		self::assertSame( 20, $GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_repository_release_sections'][0]['priority'] );
		self::assertSame( 2, $GLOBALS['ran_booster_release_management_test_actions']['ran_booster_admin_repository_release_sections'][0]['accepted_args'] );
		self::assertArrayHasKey( 'admin_post_ran_booster_release_workflow', $GLOBALS['ran_booster_release_management_test_actions'] );
		self::assertCount( 1, $GLOBALS['ran_booster_release_management_test_actions']['admin_post_ran_booster_release_workflow'] );
		self::assertSame( array( $controls, 'handleWorkflow' ), $GLOBALS['ran_booster_release_management_test_actions']['admin_post_ran_booster_release_workflow'][0]['callback'] );
		self::assertSame( 10, $GLOBALS['ran_booster_release_management_test_actions']['admin_post_ran_booster_release_workflow'][0]['priority'] );
		self::assertSame( 1, $GLOBALS['ran_booster_release_management_test_actions']['admin_post_ran_booster_release_workflow'][0]['accepted_args'] );
	}

	public function testCapableEditKeepsReleaseAssetSelectableWhileOtherContextsRemainUnchanged(): void {
		$choices = array( 'release_asset' => array( 'disabled' => true ) );
		$package = new class() { public function providerCode(): string {
				return 'fixture';
		} };

		self::assertFalse( $this->controls()->keepReleaseSettingsDiscoverable( $choices, 'edit', 'plugin', $package, 'https://example.test' )['release_asset']['disabled'] );
		self::assertSame( $choices, $this->controls()->keepReleaseSettingsDiscoverable( $choices, 'create', 'plugin', $package, 'https://example.test' ) );
		self::assertSame( $choices, $this->controls( registered: false )->keepReleaseSettingsDiscoverable( $choices, 'edit', 'plugin', $package, 'https://example.test' ) );
	}

	public function testHandleWorkflowUsesNativeAndHtmxRedirectTransports(): void {
		$request = $this->request( 'inspect' );
		$_POST   = $request;
		try {
			$this->controls()->handleWorkflow();
			self::fail( 'Expected the native redirect to stop execution.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'native-redirect', $exception->getMessage() );
		}
		$native = (string) $GLOBALS['ran_booster_release_management_test_redirect'];
		self::assertStringStartsWith( 'https://example.test/wp-admin/admin.php?', $native );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $native, PHP_URL_QUERY ), $nativeQuery );
		self::assertSame( 'ran-booster', $nativeQuery['page'] );
		self::assertSame( 'fixture', $nativeQuery['tab'] );
		self::assertSame( 'repositories', $nativeQuery['panel'] );
		self::assertSame( '101', $nativeQuery['repository'] );
		self::assertSame( 'releases', $nativeQuery['repository_view'] );
		self::assertSame( 'ran-booster-repository-release-workflows', \RAN\Admin\ReleaseManagement\wp_parse_url( $native, PHP_URL_FRAGMENT ) );

		try {
			$_POST                      = $request;
			$_SERVER['HTTP_HX_REQUEST'] = 'true';
			try {
				$this->controls()->handleWorkflow();
				self::fail( 'Expected the HX response to stop execution.' );
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

	public function testFallbackPackageSettingsRenderTheSignedWorkflowResultWithoutWorkflowControls(): void {
		$request                           = $this->request( 'inspect' );
		$request['expected_repository_id'] = 'missing-repository';
		$url                               = $this->controller()->processWorkflowRequest( $request );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Exercises a URL signed by the immediately preceding control call.

		$package = new class() {
			public function providerCode(): string {
				return 'fixture'; }
			public function type(): string {
				return 'plugin'; }
			public function identifier(): string {
				return 'example/example.php'; }
			public function sourceRevision(): int {
				return 3; }
		};
		ob_start();
		$this->controls()->renderPackageReleaseAutomationLink( $package, ReleaseManagementFixture::status() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-release-workflow-result', $html );
		self::assertStringContainsString( 'Booster stopped before contacting the repository provider', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function testFallbackPackageWorkflowNoticeRequiresAnUnchangedSignedResultAndMatchingScreen(): void {
		$request                           = $this->request( 'inspect' );
		$request['expected_repository_id'] = 'missing-repository';
		$url                               = $this->controller()->processWorkflowRequest( $request );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$package       = new class() {
			public function providerCode(): string {
				return 'fixture'; }
			public function type(): string {
				return 'plugin'; }
			public function identifier(): string {
				return 'example/example.php'; }
			public function sourceRevision(): int {
				return 3; }
		};
		$rendersNotice = function ( array $get ) use ( $package ): bool {
			$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Exercises display-only signed-result verification.
			ob_start();
			$this->controls()->renderPackageReleaseAutomationLink( $package, ReleaseManagementFixture::status() );
			return str_contains( (string) ob_get_clean(), 'data-ran-booster-release-workflow-result' );
		};

		self::assertTrue( $rendersNotice( $query ) );
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
			self::assertFalse( $rendersNotice( $mutated ), $field );
		}
		$wrongPage         = $query;
		$wrongPage['page'] = 'ran-booster-themes';
		self::assertFalse( $rendersNotice( $wrongPage ) );
		$wrongPackage            = $query;
		$wrongPackage['package'] = 'other/other.php';
		self::assertFalse( $rendersNotice( $wrongPackage ) );
	}

	public function testPassiveRowsRemainUntouchedWhenTheProviderHasNoCompleteWorkflowAggregate(): void {
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

		self::assertSame( $rows, $controls->enrichRepositoryRows( $rows, 'partial', array(), 'https://example.test/return' ) );
	}

	public function testIncompleteWorkflowProviderDoesNotPresentRepositoryAutomationAsReadyToAssess(): void {
		$controls = $this->controls( provider: new PartialRepositoryReleaseWorkflowProviderDouble(), sourceGuard: $this->sourceGuard( 'partial' ) );
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
		$controls->renderRepositoryReleaseSections( $row, 'https://example.test/repositories' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Unavailable<', $html );
		self::assertStringNotContainsString( 'Ready to assess', $html );
		self::assertStringContainsString( 'button type="submit" class="button" disabled aria-disabled="true">Assess release setup</button>', $html );
	}

	public function testPassiveRepositoryRenderReadsOnlyStatusAndOpaquePreviewWithoutAWorkflowMutation(): void {
		$key      = str_repeat( 'a', 32 );
		$provider = new RepositoryReleaseWorkflowProviderDouble(
			preview: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
				$key,
				'fixture',
				'101',
				'bootstrap',
				'prerelease',
				'example/example',
				array(
					'repository'       => 'example/example',
					'default_branch'   => 'main',
					'base_sha'         => str_repeat( 'b', 40 ),
					'pack_version'     => '1.0.0',
					'template_digest'  => str_repeat( 'c', 64 ),
					'old_template_tag' => '',
					'new_template_tag' => 'v1.0.0',
				),
				array()
			),
			workflowResult: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowResult( 'workflow_inspected', true, $key )
		);
		$url      = $this->controller( provider: $provider )->processWorkflowRequest( $this->request( 'inspect' ) );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Uses the immediately preceding signed result and opaque preview key.
		$provider->calls       = array();
		$provider->statusReads = 0;
		$row                   = array(
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
		$this->controls( provider: $provider )->renderRepositoryReleaseSections( $row, 'https://example.test/repositories' );
		$html = (string) ob_get_clean();

		self::assertGreaterThan( 0, $provider->statusReads );
		self::assertSame( array( 'preview' ), array_column( $provider->calls, 'operation' ) );
		self::assertStringContainsString( 'Release publishing', $html );
		self::assertStringContainsString( 'example/example</strong> · main', $html );
	}

	public function testPassiveRowsRemainUntouchedWhenTheCapableProviderHasNoRegisteredAdminSurface(): void {
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

		self::assertSame( $rows, $this->controls( provider: new RepositoryReleaseWorkflowProviderDouble( adminSurface: false ) )->enrichRepositoryRows( $rows, 'fixture', array(), 'https://example.test/return' ) );
	}

	public function testIncompleteRepositoryInventoryReceivesNoWorkflowEnrichment(): void {
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

		self::assertSame( $rows, $this->controls()->enrichRepositoryRows( $rows, 'fixture', array(), 'https://example.test/return' ) );
	}

	public function testUnavailableRepositorySourceKeepsItsDiagnosticCode(): void {
		$controls = $this->controls( sourceGuard: $this->unavailableSourceGuard() );
		$url      = $this->controller( sourceGuard: $this->unavailableSourceGuard() )->processWorkflowRequest( $this->request( 'inspect' ) );

		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertStringContainsString( 'repository_source_unavailable', $url );
		self::assertStringNotContainsString( 'repository_release_owner_exists', $url );

		$workflowViewFor = new \ReflectionMethod( ReleaseWorkflowControls::class, 'workflowViewFor' );
		$view            = $workflowViewFor->invoke( $controls, 'plugin', 'example/example.php', 3, '', false, '', 'stable' );

		self::assertTrue( $view['unavailable'] );
		self::assertSame( 'Booster could not safely read this package\'s repository source relationship. Check package storage and retry.', $view['unavailable_reason'] );
		self::assertTrue( $view['forms']['inspect']['disabled'] );
	}

	public function testReleaseWorkflowRepositoryActionUsesTheCoreNamespacedActionContract(): void {
		$rows   = $this->controls()->enrichRepositoryRows(
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

	public function testRepositoryProjectionUsesTheSameBenignExistingWorkflowObservationAsTheRepositoryPanel(): void {
		$status   = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
			'fixture',
			'101',
			false,
			false,
			observationKind: 'existing_automation_detected',
			observedAt: '2026-08-31T12:00:00Z'
		);
		$controls = $this->controls( provider: new RepositoryReleaseWorkflowProviderDouble( status: $status ) );
		$rows     = $controls->enrichRepositoryRows(
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

	public function testRepositoryProjectionMarksARepositoryRelationshipConflictAsBlocked(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( failureCode: 'release_repository_conflict' )
		);
		$rows     = $this->controls( tracking: $tracking )->enrichRepositoryRows(
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

	public function testReleaseWorkflowRepositoryEnrichmentHonoursRemainingRowCapacityForFullRows(): void {
		$rows          = $this->controls()->enrichRepositoryRows(
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
		$resultDetails = $rows['101']['details'] ?? array();

		self::assertCount( 20, $resultDetails );
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
			$resultDetails
		);
	}

	public function testReleaseWorkflowRepositoryEnrichmentAddsOneRowWhenOneSlotRemains(): void {
		$rowSummary    = array(
			'type'            => 'plugin',
			'identifier'      => 'example/example.php',
			'source'          => 'branch',
			'source_revision' => 3,
		);
		$rows          = $this->controls()->enrichRepositoryRows(
			array(
				'101' => array(
					'provider_code'     => 'fixture',
					'repository_id'     => '101',
					'repository'        => 'example/example',
					'historical'        => false,
					'package_summaries' => array( $rowSummary, $rowSummary ),
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
		$resultDetails = $rows['101']['details'] ?? array();

		self::assertCount( 20, $resultDetails );
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
					$resultDetails,
					static fn ( array $detail ): bool => str_starts_with( (string) ( $detail['key'] ?? '' ), 'core:release-workflow-' )
				)
			)
		);
	}

	public function testOptionalPackageHelperRendersNothingForMissingOrIncompleteWorkflowProviders(): void {
		$package = new class() {
			public function providerCode(): string {
				return 'partial'; }
			public function type(): string {
				return 'plugin'; }
			public function identifier(): string {
				return 'example/example.php'; }
			public function sourceRevision(): int {
				return 3; }
		};

		foreach ( array(
			'missing'    => $this->controls( registered: false ),
			'incomplete' => $this->controls( provider: new PartialRepositoryReleaseWorkflowProviderDouble() ),
		) as $case => $controls ) {
			ob_start();
			$controls->renderPackageReleaseAutomationLink( $package, ReleaseManagementFixture::status() );
			$html = (string) ob_get_clean();

			self::assertSame( '', $html, $case );
		}
	}

	public function testSignedWorkflowResultPreservesProviderMessageAndRemediationForDisplay(): void {
		$provider = new RepositoryReleaseWorkflowProviderDouble(
			workflowResult: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowResult(
				'workflow_partial',
				false,
				failureStage: 'repository_mutation',
				diagnosticCode: 'repository_mutation_unverified',
				message: 'Provider-specific workflow message.',
				remediation: 'Provider-specific remediation.'
			)
		);
		$url      = $this->controller( provider: $provider )->processWorkflowRequest( $this->request( 'inspect' ) );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url,WordPress.Security.NonceVerification.Recommended -- Exercises the signed PRG result parser.

		$result = $this->controller( provider: $provider )->requestedResult();

		self::assertSame( 'Provider-specific workflow message.', $result['message'] );
		self::assertSame( 'Provider-specific remediation.', $result['remediation'] );

		$workflowViewFor = new \ReflectionMethod( ReleaseWorkflowControls::class, 'workflowViewFor' );
		$view            = $workflowViewFor->invoke(
			$this->controls( provider: $provider ),
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

	public function testEmptyProviderWriteGuidanceUsesTheCoreFallback(): void {
		$status   = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
			'fixture',
			'101',
			false,
			false,
			credentialChoices: array(
				array(
					'id'    => 'credential_1',
					'label' => 'Fixture credential',
				),
			)
		);
		$controls = $this->controls( provider: new RepositoryReleaseWorkflowProviderDouble( status: $status ) );

		$workflowViewFor = new \ReflectionMethod( ReleaseWorkflowControls::class, 'workflowViewFor' );
		$view            = $workflowViewFor->invoke( $controls, 'plugin', 'example/example.php', 3, '', false, '', 'stable' );

		self::assertSame(
			'Choose a saved credential that can manage release workflows and open pull requests. Its secret is never stored with this setup.',
			$view['forms']['inspect']['write_guidance']
		);
	}

	private function controller( ?ReleaseTrackingFacadeDouble $tracking = null, ?RepositoryProvider $provider = null, bool $registered = true, ?RepositorySourceGuard $sourceGuard = null ): ReleaseWorkflowRequestController {
		$provider ??= new RepositoryReleaseWorkflowProviderDouble();
		return new ReleaseWorkflowRequestController( $tracking ?? new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ), new PluginRepositoryDouble( providerCode: $provider->getMetadata()->code->value ), new ThemeRepositoryDouble(), new ProviderRegistry( $registered ? array( $provider ) : array() ), $sourceGuard ?? $this->sourceGuard() );
	}

	private function controls( ?ReleaseTrackingFacadeDouble $tracking = null, ?RepositoryProvider $provider = null, bool $registered = true, ?RepositorySourceGuard $sourceGuard = null ): ReleaseWorkflowControls {
		$provider ??= new RepositoryReleaseWorkflowProviderDouble();
		return new ReleaseWorkflowControls( $tracking ?? new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ), new PluginRepositoryDouble( providerCode: $provider->getMetadata()->code->value ), new ThemeRepositoryDouble(), new ProviderRegistry( $registered ? array( $provider ) : array() ), $sourceGuard ?? $this->sourceGuard() );
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
			$request['core_preflight_nonce_prerelease'] = 'preflight-prerelease'; }
		$request['_wpnonce'] = 'nonce-for-ran-booster-release-workflow-' . $operation . '-' . hash( 'sha256', (string) \RAN\Admin\ReleaseManagement\wp_json_encode( array( 'fixture', '101', 'plugin', 'example/example.php', 3, $preview ) ) );
		return $request;
	}

	private function sourceGuard( string $providerCode = 'fixture' ): RepositorySourceGuard {
		$database  = new class( $providerCode ) { public string $last_error = '';
			public function __construct( private string $providerCode ) {}
			public function prepare( string $query, mixed ...$arguments ): string {
				return $query;
			} public function get_results( string $query ): array {
				return array(
					(object) array(
						'type'                   => 1,
						'package'                => 'example/example.php',
						'source'                 => 'branch',
						'provider'               => $this->providerCode,
						'provider_repository_id' => '101',
					),
				);
			} };
		$lifecycle = new class() extends Database { public function requireReady(): void {} };
		return new RepositorySourceGuard( $database, $lifecycle );
	}

	private function unavailableSourceGuard(): RepositorySourceGuard {
		$database  = new class() { public string $last_error = 'fixture unavailable';
			public function prepare( string $query, mixed ...$arguments ): string {
				return $query;
			} public function get_results( string $query ): array {
				return array();
			} };
		$lifecycle = new class() extends Database { public function requireReady(): void {} };
		return new RepositorySourceGuard( $database, $lifecycle );
	}
}
