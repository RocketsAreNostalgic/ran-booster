<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\GitHub;

require_once __DIR__ . '/../Support/ReleaseManagementWordPressFunctions.php';
require_once dirname( __DIR__, 3 ) . '/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/GitHubReleaseWorkflowWordPressFunctions.php';
require_once __DIR__ . '/Support/GitHubReleaseWorkflowTransportFunctions.php';
require_once __DIR__ . '/Support/ReleaseWorkflowCredentialStoreDouble.php';
require_once __DIR__ . '/../Support/ReleaseManagementFixtures.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Admin\ReleaseManagement\GitHub\GitHubReleaseWorkflowControls;
use RAN\Admin\ReleaseManagement\GitHub\GitHubReleaseWorkflowDisplay;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\WorkflowApplicationCoordinator;
use RAN\RepositoryProvider\ProviderCredentialStore;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Admin\ReleaseManagement\GitHub\Support\PluginRepositoryDouble;
use Tests\Admin\ReleaseManagement\GitHub\Support\ThemeRepositoryDouble;
use Tests\Admin\ReleaseManagement\GitHub\Support\ReleaseWorkflowCredentialStoreDouble;
use Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;

final class GitHubReleaseWorkflowControlsTest extends TestCase {
	public function testGitHubPackageKeepsDisabledReleaseSettingsViewNavigable(): void {
		$choices  = array(
			'release_asset' => array(
				'disabled' => true,
			),
		);
		$controls = $this->controls();

		$github = $controls->keepReleaseSettingsDiscoverable(
			$choices,
			'edit',
			'plugin',
			$this->githubPackage(),
			'https://example.test/settings'
		);
		$other  = $controls->keepReleaseSettingsDiscoverable(
			$choices,
			'edit',
			'plugin',
			new class() {
				public function providerCode(): string {
					return 'bb';
				}
			},
			'https://example.test/settings'
		);

		self::assertFalse( $github['release_asset']['disabled'] );
		self::assertTrue( $other['release_asset']['disabled'] );
	}

	#[Before]
	public function resetWordPress(): void {
		ReleaseManagementFixture::resetWordPress();
		$GLOBALS['ran_booster_release_deployments_test_options']    = array();
		$GLOBALS['ran_booster_release_deployments_test_transients'] = array();
		foreach ( array( 'unslashed', 'events', 'remote' ) as $suffix ) {
			unset( $GLOBALS[ 'ran_booster_github_release_workflow_test_' . $suffix ] );
		}
	}

	public function testRegistersOnlyTheFiveNewGitHubWorkflowRoutes(): void {
		$controls = $this->controls();
		$controls->register();

		self::assertSame(
			array(
				'ran_booster_admin_package_release_readiness_actions',
				'ran_booster_admin_repository_release_sections',
				'admin_post_ran_booster_github_release_workflow_inspect',
				'admin_post_ran_booster_github_release_workflow_setup',
				'admin_post_ran_booster_github_release_workflow_outcome',
				'admin_post_ran_booster_github_release_workflow_update_inspect',
				'admin_post_ran_booster_github_release_workflow_update_setup',
			),
			array_keys( $GLOBALS['ran_booster_release_management_test_actions'] ?? array() )
		);
		self::assertSame(
			array(
				'callback'      => array( $controls, 'enrichRepositoryRows' ),
				'priority'      => 20,
				'accepted_args' => 4,
			),
			$GLOBALS['ran_booster_release_management_test_filters']['ran_booster_provider_repository_rows'][0]
		);
		self::assertStringNotContainsString( 'release_deployments', implode( '|', array_keys( $GLOBALS['ran_booster_release_management_test_actions'] ) ) );
	}

	public function testRepositoryRowsExposeLocalReleaseAutomationStatusAndNavigation(): void {
		$cases = array(
			'ready'       => array( ReleaseManagementFixture::status(), 'Ready to assess', 'ok', 'branch' ),
			'unavailable' => array( ReleaseManagementFixture::status( eligibilityCode: ReleaseTrackingEligibility::MISSING_UPDATE_URI ), 'Unavailable', 'warning', 'branch' ),
			'release'     => array( ReleaseManagementFixture::status( 'release_asset' ), 'Not needed', 'ok', 'release_asset' ),
		);

		foreach ( $cases as $name => $case ) {
			list( $status, $expected, $tone, $source ) = $case;
			$this->resetWordPress();
			$rows   = $this->controls( new ReleaseTrackingFacadeDouble( $status ) )->enrichRepositoryRows(
				$this->repositoryRows( $source ),
				'gh',
				array(),
				'https://example.test/return'
			);
			$detail = $rows['101']['details'][0];
			$action = array_values( $rows['101']['actions'] )[0];

			self::assertSame( 'Release automation', $detail['label'], $name );
			self::assertSame( $expected, $detail['value'], $name );
			self::assertSame( $tone, $detail['tone'], $name );
			self::assertFalse( $action['disabled'], $name );
			self::assertStringContainsString( 'panel=repositories', $action['url'], $name );
			self::assertStringContainsString( 'repository=101', $action['url'], $name );
			self::assertStringContainsString( 'repository_view=releases', $action['url'], $name );
			self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array(), $name );
		}
	}

	public function testRepositoryRowsReportOnlyAnExactMatchingSetupRecordAsRecorded(): void {
		self::assertTrue( ( new SetupRecordStore() )->save( $this->setupRecord() ) );
		$controls   = $this->controls();
		$configured = $controls->enrichRepositoryRows( $this->repositoryRows(), 'gh', array(), 'https://example.test/return' );

		self::assertSame( 'Setup recorded', $configured['101']['details'][0]['value'] );
		self::assertSame( 'pending', $configured['101']['details'][0]['tone'] );
		$GLOBALS['ran_booster_release_deployments_test_options'] = array();
		self::assertTrue( ( new SetupRecordStore() )->save( $this->setupRecord( 'other/other.php' ) ) );
		$mismatched = $this->controls()->enrichRepositoryRows( $this->repositoryRows(), 'gh', array(), 'https://example.test/return' );

		self::assertSame( 'Unavailable', $mismatched['101']['details'][0]['value'] );
	}

	public function testRepositoryReleasePanelUsesLocalExactPackageStatusAndSavedCredentials(): void {
		$controls = $this->controls();
		$row      = $this->repositoryRows()['101'];
		$row['package_summaries'][0]['display_name'] = 'Example plugin';
		$row['package_summaries'][0]['settings_url'] = 'https://example.test/plugin-settings';

		ob_start();
		$controls->renderRepositoryReleaseSections( $row, 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<h3 id="ran-booster-repository-release-heading">Published releases</h3>', $html );
		self::assertStringContainsString( '<h4 id="ran-booster-repository-release-readiness-heading">Published release readiness</h4>', $html );
		self::assertStringContainsString( 'Plugin readiness — Example plugin', $html );
		self::assertStringContainsString( 'Provider capability', $html );
		self::assertStringContainsString( 'GitHub supports published releases.', $html );
		self::assertStringContainsString( 'Repository relationship', $html );
		self::assertStringContainsString( '1 exact package relationship is recorded for example/example.', $html );
		self::assertStringContainsString( 'Installed identity and Update URI match the configured repository.', $html );
		self::assertStringContainsString( 'Plugin source — Example plugin', $html );
		self::assertStringContainsString( 'Branch deployments. Change source and track in package settings.', $html );
		self::assertStringContainsString( 'No package uses Published releases yet.', $html );
		self::assertStringContainsString( 'Booster does not contact the provider while rendering this checklist.', $html );
		self::assertStringContainsString( '<h4 id="ran-booster-repository-release-automation-heading">Release automation</h4>', $html );
		self::assertStringContainsString( 'No local workflow record claims this repository yet. It is ready to assess.', $html );
		self::assertStringContainsString( '>Ready to assess</span>', $html );
		self::assertStringContainsString( 'No local workflow record claims this repository yet. It is ready to assess.', $html );
		$readinessPosition  = strpos( $html, 'ran-booster-repository-release-readiness-heading' );
		$automationPosition = strpos( $html, 'ran-booster-repository-release-automation-heading' );
		$statePosition      = strpos( $html, '>Ready to assess</span>' );
		self::assertIsInt( $readinessPosition );
		self::assertIsInt( $automationPosition );
		self::assertIsInt( $statePosition );
		self::assertTrue( $readinessPosition < $automationPosition );
		self::assertTrue( $automationPosition < $statePosition );
		self::assertStringNotContainsString( '?>', $html );
		self::assertStringNotContainsString( '<h3>Example plugin</h3>', $html );
		self::assertStringNotContainsString( '<details', $html );
		self::assertStringContainsString( 'Assess release setup', $html );
		self::assertStringContainsString( 'name="booster_credential_id"', $html );
		self::assertStringContainsString( 'name="expected_identifier" value="example/example.php"', $html );
		self::assertStringContainsString( 'name="expected_source_revision" value="3"', $html );
		self::assertStringNotContainsString( '>Package settings</a>', $html );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testRepositoryReleaseLifecycleFailsClosedOnPackageEligibilityWithoutConflatingWorkflowReadiness(): void {
		$controls = $this->controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( eligibilityCode: ReleaseTrackingEligibility::MISSING_UPDATE_URI ) )
		);

		ob_start();
		$controls->renderRepositoryReleaseSections( $this->repositoryRows()['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'One or more packages need attention before using published releases.', $html );
		self::assertStringContainsString( 'Plugin readiness — example/example.php', $html );
		self::assertStringContainsString( 'The installed package does not declare the required Update URI.', $html );
		self::assertStringContainsString( 'No package uses Published releases yet.', $html );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testRepositoryReleaseReadinessFailsClosedForAWorkflowRecordOwnedByAnotherPackage(): void {
		self::assertTrue( ( new SetupRecordStore() )->save( $this->setupRecord( 'other/other.php' ) ) );
		$controls = $this->controls();

		ob_start();
		$controls->renderRepositoryReleaseSections( $this->repositoryRows()['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'A local workflow record is occupied by a different package or revision.', $html );
		self::assertStringNotContainsString( 'A matching local workflow record is owned by', $html );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testRepositoryRowsFailClosedOnPackageIdentityMismatchAndIgnoreOtherProviders(): void {
		$rows     = $this->repositoryRows();
		$facade   = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = $this->controls( $facade, new PluginRepositoryDouble( repositoryId: '202' ) );

		self::assertSame( $rows, $controls->enrichRepositoryRows( $rows, 'bb', array(), 'https://example.test/return' ) );
		$github = $controls->enrichRepositoryRows( $rows, 'gh', array(), 'https://example.test/return' );

		self::assertSame( 'Unavailable', $github['101']['details'][0]['value'] );
		self::assertSame( 0, $facade->statusReads );
	}

	public function testMultipleRepositoryPackagesHaveDistinctBoundedVisibleLabels(): void {
		$rows                               = $this->repositoryRows();
		$rows['101']['package_references']  = array( 'example/example.php', 'example-theme' );
		$rows['101']['package_summaries'][] = array(
			'type'            => 'theme',
			'identifier'      => 'example-theme',
			'source'          => 'branch',
			'source_revision' => 3,
		);
		$pluginStatus                       = ReleaseManagementFixture::status();
		$facade                             = new ReleaseTrackingFacadeDouble( $pluginStatus );
		$controls                           = $this->controls( $facade );
		$projected                          = $controls->enrichRepositoryRows( $rows, 'gh', array(), 'https://example.test/return' );
		$labels                             = array_column( $projected['101']['details'], 'label' );
		$actions                            = array_values( $projected['101']['actions'] );
		$actionLabels                       = array_column( $actions, 'label' );

		self::assertSame( array( 'Release automation — example/example.php', 'Release automation — example-theme' ), $labels );
		self::assertSame( array( 'Release automation: example/example.php', 'Release automation: example-theme' ), $actionLabels );
		self::assertStringContainsString( 'repository_view=releases', $actions[0]['url'] );
		self::assertSame( $actions[0]['url'], $actions[1]['url'] );
		foreach ( array_merge( $labels, $actionLabels ) as $label ) {
			self::assertLessThanOrEqual( 96, strlen( $label ) );
		}
	}

	public function testComposesTheMovedGitHubKernelWithoutOwningRemoteOrSetupState(): void {
		$controls = $this->controls();
		foreach ( array(
			'applications'    => WorkflowApplicationCoordinator::class,
			'workflowRecords' => SetupRecordStore::class,
			'display'         => GitHubReleaseWorkflowDisplay::class,
		) as $property => $class ) {
			$value = ( new ReflectionProperty( $controls, $property ) )->getValue( $controls );
			self::assertInstanceOf( $class, $value, $property );
		}

		$source = dirname( __DIR__, 4 ) . '/RAN/Admin/ReleaseManagement/GitHub/GitHubReleaseWorkflowControls.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local composition boundary under test.
		$bytes = file_get_contents( $source );
		self::assertIsString( $bytes );
		self::assertStringContainsString( 'RAN\\Booster\\GitHub\\ReleaseDeployments\\WorkflowAssistance\\WorkflowApplicationCoordinator', $bytes );
		self::assertStringNotContainsString( 'function repository(', $bytes );
		self::assertStringNotContainsString( 'update_option(', $bytes );
	}

	public function testPermissionsRejectBeforePackageLookupOrSavedCredentialRead(): void {
		foreach ( array( 'manage_options', 'update_plugins' ) as $denied ) {
			$this->resetWordPress();
			$plugins = new PluginRepositoryDouble();
			$facade  = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
			$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( $denied );

			$this->controls( $facade, $plugins )->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );

			self::assertSame( 0, $plugins->reads, $denied );
			self::assertSame( 0, $facade->statusReads, $denied );
			self::assertNotContains( 'credential_1', $GLOBALS['ran_booster_github_release_workflow_test_unslashed'] ?? array(), $denied );
			self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array(), $denied );
		}
	}

	public function testNonGitHubMissingAndStalePackagesRejectBeforeStatusOrSavedCredentialRead(): void {
		foreach ( array(
			'non-gh'  => new PluginRepositoryDouble( 'acme', 3 ),
			'missing' => new PluginRepositoryDouble( 'gh', 3, true ),
			'stale'   => new PluginRepositoryDouble( 'gh', 4 ),
		) as $name => $plugins ) {
			$this->resetWordPress();
			$facade = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );

			$this->controls( $facade, $plugins )->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );

			self::assertSame( 1, $plugins->reads, $name );
			self::assertSame( 0, $facade->statusReads, $name );
			self::assertSame( array(), $facade->calls, $name );
			self::assertNotContains( 'credential_1', $GLOBALS['ran_booster_github_release_workflow_test_unslashed'] ?? array(), $name );
			self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array(), $name );
		}
	}

	public function testNonceRejectsAfterExactGitHubPackageGateButBeforeSavedCredentialRead(): void {
		$plugins             = new PluginRepositoryDouble();
		$facade              = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$request             = $this->request( 'inspect' );
		$request['_wpnonce'] = 'wrong-nonce';

		$this->controls( $facade, $plugins )->processWorkflowRequest( 'inspect', $request );

		self::assertSame( 1, $plugins->reads );
		self::assertSame( 1, $facade->statusReads );
		self::assertSame( array(), $facade->calls );
		self::assertNotContains( 'credential_1', $GLOBALS['ran_booster_github_release_workflow_test_unslashed'] ?? array() );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testAuthorisedInspectResolvesSavedCredentialOnlyAfterCapabilitiesAndNonce(): void {
		$facade      = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$credentials = new ReleaseWorkflowCredentialStoreDouble();
		$url         = $this->controls( $facade, credentials: $credentials )->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );
		$events      = $GLOBALS['ran_booster_github_release_workflow_test_events'] ?? array();

		$manage     = array_search( 'capability:manage_options', $events, true );
		$update     = array_search( 'capability:update_plugins', $events, true );
		$verify     = array_search( 'verify:' . $this->nonceAction( 'inspect' ), $events, true );
		$credential = array_search( 'unslash:credential_1', $events, true );
		self::assertIsInt( $manage );
		self::assertIsInt( $update );
		self::assertIsInt( $verify );
		self::assertIsInt( $credential );
		self::assertTrue( $manage < $credential );
		self::assertTrue( $update < $credential );
		self::assertTrue( $verify < $credential );
		self::assertSame( 1, $credentials->profileReads );
		self::assertSame( 1, $credentials->materialReads );
		self::assertSame( array( array( 'preflight', 'plugin', 'example/example.php', 3, 'stable', 'core-preflight' ) ), $facade->calls );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );

		$query = $this->query( $url );
		self::assertSame( 'workflow_release_ready', $query['ran_booster_github_release_workflow_result'] );
		self::assertSame( '1', $query['ran_booster_github_release_workflow_success'] );
		self::assertSame( 'stable', $query['ran_booster_github_release_workflow_channel'] );
		self::assertArrayHasKey( 'ran_booster_github_release_workflow_result_nonce', $query );
		self::assertStringNotContainsString( 'release_deployments', $url );
	}

	public function testWriteRejectsMissingSavedCredentialBeforeCoreExecution(): void {
		$credentials                      = new ReleaseWorkflowCredentialStoreDouble();
		$request                          = $this->request( 'setup', str_repeat( 'a', 32 ) );
		$request['booster_credential_id'] = '';
		$url                              = $this->controls( credentials: $credentials )->processWorkflowRequest( 'setup', $request );

		self::assertSame( 0, $credentials->profileReads );
		self::assertSame( 0, $credentials->materialReads );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
		self::assertSame( 'workflow_unauthorised', $this->query( $url )['ran_booster_github_release_workflow_result'] );
	}

	public function testAllFiveFormsUseTheirExactRegisteredActionAndNewPreviewVocabulary(): void {
		$controls = $this->controls();
		$status   = ReleaseManagementFixture::status();
		$method   = new ReflectionMethod( $controls, 'workflowForm' );
		$preview  = str_repeat( 'a', 32 );

		foreach ( array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ) as $operation ) {
			$usesPreview = in_array( $operation, array( 'setup', 'update_setup' ), true );
			$channel     = in_array( $operation, array( 'inspect', 'setup' ), true ) ? 'stable' : '';
			$form        = $method->invoke( $controls, $operation, $status, $usesPreview ? $preview : '', $usesPreview ? 'example/example' : '', $channel );

			self::assertIsArray( $form, $operation );
			self::assertSame( 'ran_booster_github_release_workflow_' . $operation, $form['fields']['action'], $operation );
			self::assertStringNotContainsString( 'workflow_workflow', $form['fields']['action'], $operation );
			self::assertStringNotContainsString( 'release_deployments', (string) \RAN\Admin\ReleaseManagement\wp_json_encode( $form ), $operation );
		}

		$url   = $controls->processWorkflowRequest( 'setup', $this->request( 'setup', $preview ) );
		$query = $this->query( $url );
		self::assertSame( $preview, $query['ran_booster_github_release_workflow_preview'] );
		self::assertArrayNotHasKey( 'ran_booster_release_workflow_preview', $query );
		self::assertArrayNotHasKey( 'ran_booster_release_deployments_preview', $query );
	}

	public function testVerifiedResultFromAnotherPackageIsNotRenderedOnCurrentScreen(): void {
		$status   = $this->workflowStatus( 'other/other.php' );
		$facade   = new ReleaseTrackingFacadeDouble( $status );
		$controls = $this->controls( $facade );
		$payload  = array( 'workflow_partial', false, 'plugin', 'example/example.php', 'stable' );
		$action   = 'ran-booster-github-release-workflow-result-' . hash( 'sha256', (string) \RAN\Admin\ReleaseManagement\wp_json_encode( $payload ) );
		$_GET     = array(
			'page'                                        => 'ran-booster-plugins',
			'package'                                     => 'other/other.php',
			'ran_booster_github_release_workflow_result'  => 'workflow_partial',
			'ran_booster_github_release_workflow_success' => '0',
			'ran_booster_github_release_workflow_type'    => 'plugin',
			'ran_booster_github_release_workflow_package' => 'example/example.php',
			'ran_booster_github_release_workflow_channel' => 'stable',
			'ran_booster_github_release_workflow_result_nonce' => 'nonce-for-' . $action,
		);
		$package  = new class() {
			public function providerCode(): string {
				return 'gh';
			}
			public function type(): string {
				return 'plugin';
			}
			public function identifier(): string {
				return 'other/other.php';
			}
			public function sourceRevision(): int {
				return 3;
			}
		};

		ob_start();
		$controls->renderPackageReleaseAutomationLink( $package, $status );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Manage release automation', $html );
		self::assertStringContainsString( 'repository_view=releases', html_entity_decode( $html ) );
		self::assertStringNotContainsString( 'ran-booster-badge', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'GitHub may have accepted only part', $html );
	}

	public function testIneligibleGitHubPackageRendersOnlyTheRepositoryAutomationLink(): void {
		$status   = ReleaseManagementFixture::status( eligibilityCode: ReleaseTrackingEligibility::MISSING_UPDATE_URI );
		$facade   = new ReleaseTrackingFacadeDouble( $status );
		$controls = $this->controls( $facade );
		$package  = new class() {
			public function providerCode(): string {
				return 'gh';
			}
			public function type(): string {
				return 'plugin';
			}
			public function identifier(): string {
				return 'example/example.php';
			}
			public function sourceRevision(): int {
				return 3;
			}
		};

		ob_start();
		$controls->renderPackageReleaseAutomationLink( $package, $status );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Manage release automation', $html );
		self::assertStringContainsString( 'repository_view=releases', html_entity_decode( $html ) );
		self::assertStringNotContainsString( 'ran-booster-badge', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'booster_credential_id', $html );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );

		$controls->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );
		self::assertNotContains( 'credential_1', $GLOBALS['ran_booster_github_release_workflow_test_unslashed'] ?? array() );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testPackageAutomationLinkFailsClosedForMismatchedStatus(): void {
		$facade                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$facade->throwOnStatus = true;
		$controls              = $this->controls( $facade );

		ob_start();
		$controls->renderPackageReleaseAutomationLink( $this->githubPackage(), $this->workflowStatus( 'other/other.php' ) );
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'booster_credential_id', $html );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testEligiblePublishedReleaseRendersOnlyTheRepositoryAutomationLink(): void {
		$controls = $this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );

		ob_start();
		$controls->renderPackageReleaseAutomationLink( $this->githubPackage( 'release_asset' ), ReleaseManagementFixture::status( 'release_asset' ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Manage release automation', $html );
		self::assertStringContainsString( 'repository_view=releases', html_entity_decode( $html ) );
		self::assertStringNotContainsString( 'ran-booster-badge', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'booster_credential_id', $html );
	}

	public function testIneligiblePublishedReleaseCannotReportAutomationAsNotNeeded(): void {
		$status   = ReleaseManagementFixture::status( 'release_asset', eligibilityCode: ReleaseTrackingEligibility::MISSING_UPDATE_URI );
		$controls = $this->controls( new ReleaseTrackingFacadeDouble( $status ) );

		ob_start();
		$controls->renderRepositoryReleaseSections( $this->repositoryRows( 'release_asset' )['101'], 'https://example.test/return' );
		$repositoryHtml = (string) ob_get_clean();

		self::assertStringContainsString( 'The installed package does not declare the required Update URI.', $repositoryHtml );
		self::assertStringNotContainsString( '>Not needed</span>', $repositoryHtml );
		self::assertStringNotContainsString( 'Release automation is not needed.', $repositoryHtml );
	}

	public function testEligiblePublishedReleaseWithoutFreshPreflightReportsAutomationAvailableFromBranch(): void {
		$status   = new ReleaseTrackingStatus(
			'plugin',
			'example/example.php',
			'release_asset',
			3,
			'101',
			'manual',
			new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE, 'https://github.com/example/example', 'example-plugin' ),
			null,
			'example-plugin',
			'1.0.0',
			'1.0.0'
		);
		$controls = $this->controls( new ReleaseTrackingFacadeDouble( $status ) );

		ob_start();
		$controls->renderRepositoryReleaseSections( $this->repositoryRows( 'release_asset' )['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Available from Branch</span>', $html );
		self::assertStringNotContainsString( '>Needs attention</span>', $html );
		self::assertStringNotContainsString( '>Not needed</span>', $html );
		self::assertStringContainsString( 'available after returning a package to Branch', $html );
	}

	public function testRepositoryReleasePanelFailsClosedWhenStatusSourceDoesNotMatchTheExactSummary(): void {
		$controls = $this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );

		ob_start();
		$controls->renderRepositoryReleaseSections( $this->repositoryRows( 'branch' )['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Booster could not confirm this package’s exact local release status.', $html );
		self::assertStringContainsString( 'Booster could not confirm that this release status belongs to the exact saved package and source.', $html );
		self::assertStringNotContainsString( '1 of 1 packages use Published releases.', $html );
		self::assertStringNotContainsString( '>Not needed</span>', $html );
		self::assertStringNotContainsString( 'Assess release setup', $html );
	}

	public function testRepositoryReleasePanelDisablesBootstrapWhenACompatibleReleaseIsProven(): void {
		$controls = $this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );
		$row      = $this->repositoryRows( 'release_asset' )['101'];
		$row['package_summaries'][0]['settings_url'] = 'https://example.test/plugin-settings';

		ob_start();
		$controls->renderRepositoryReleaseSections( $row, 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '1 of 1 packages use Published releases.', $html );
		self::assertStringContainsString( 'Published releases · Stable track.', $html );
		self::assertStringContainsString( '>Not needed</span>', $html );
		self::assertStringContainsString( 'A compatible published release is already available. Release-workflow bootstrap is unnecessary.', $html );
		self::assertStringContainsString( '<button type="button" class="button" disabled>Set up release automation</button>', $html );
		self::assertStringNotContainsString( 'Assess release setup', $html );
		self::assertStringNotContainsString( 'Review package release settings', $html );
	}

	private function githubPackage( string $source = 'branch' ): object {
		return new class( $source ) {
			public function __construct( private readonly string $source ) {
			}
			public function providerCode(): string {
				return 'gh';
			}
			public function type(): string {
				return 'plugin';
			}
			public function identifier(): string {
				return 'example/example.php';
			}
			public function sourceRevision(): int {
				return 3;
			}
			public function source(): string {
				return $this->source;
			}
		};
	}

	private function controls(
		?ReleaseTrackingFacadeDouble $facade = null,
		?PluginRepositoryDouble $plugins = null,
		?ThemeRepositoryDouble $themes = null,
		?ProviderCredentialStore $credentials = null
	): GitHubReleaseWorkflowControls {
		return new GitHubReleaseWorkflowControls(
			$facade ?? new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ),
			$plugins ?? new PluginRepositoryDouble(),
			$themes ?? new ThemeRepositoryDouble(),
			$credentials ?? new ReleaseWorkflowCredentialStoreDouble()
		);
	}

	/** @return array<string,string> */
	private function request( string $operation, string $preview = '' ): array {
		$request = array(
			'expected_type'            => 'plugin',
			'expected_identifier'      => 'example/example.php',
			'expected_source_revision' => '3',
			'_wpnonce'                 => 'nonce-for-' . $this->nonceAction( $operation, $preview ),
			'booster_credential_id'    => 'credential_1',
			'confirm_repository'       => 'example/example',
		);
		if ( 'inspect' === $operation ) {
			$request['release_channel']             = 'stable';
			$request['core_preflight_nonce_stable'] = 'core-preflight';
		}
		if ( '' !== $preview ) {
			$request['preview_key'] = $preview;
		}
		return $request;
	}

	private function nonceAction( string $operation, string $preview = '' ): string {
		return 'ran-booster-github-release-workflow-' . $operation . '-' . hash(
			'sha256',
			implode( '|', array( 'plugin', 'example/example.php', 3, '101', $preview ) )
		);
	}

	/** @return array<string,string> */
	private function query( string $url ): array {
		$query = array();
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return array_filter( $query, 'is_string' );
	}

	private function workflowStatus( string $identifier ): ReleaseTrackingStatus {
		return new ReleaseTrackingStatus(
			'plugin',
			$identifier,
			'branch',
			3,
			'101',
			'manual',
			new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE, 'https://github.com/other/other', 'other' ),
			new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'other' ),
			'other',
			'1.0.0'
		);
	}

	/** @return array<string, array<string, mixed>> */
	private function repositoryRows( string $source = 'branch' ): array {
		return array(
			'101' => array(
				'key'                => '101',
				'provider_code'      => 'gh',
				'repository_id'      => '101',
				'repository'         => 'example/example',
				'source_key'         => $source,
				'historical'         => false,
				'package_references' => array( 'example/example.php' ),
				'package_summaries'  => array(
					array(
						'type'            => 'plugin',
						'identifier'      => 'example/example.php',
						'source'          => $source,
						'source_revision' => 3,
					),
				),
				'details'            => array(),
				'actions'            => array(),
			),
		);
	}

	/** @return array<string, int|string> */
	private function setupRecord( string $identifier = 'example/example.php' ): array {
		return array(
			'schema_version'        => 2,
			'operation'             => 'bootstrap',
			'repo_id'               => '101',
			'repository'            => 'example/example',
			'package_type'          => 'plugin',
			'package_identifier'    => $identifier,
			'source_revision'       => 3,
			'default_branch'        => 'main',
			'base_sha'              => str_repeat( 'a', 40 ),
			'setup_branch'          => 'ran-booster/release-setup-v2-aaaaaaaaaaaa-deadbeef',
			'head_sha'              => str_repeat( 'b', 40 ),
			'pr_number'             => 42,
			'profile_id'            => 'source-ready-wordpress-plugin/2',
			'template_repo_name'    => 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates',
			'template_repo_id'      => '1322743261',
			'template_release_id'   => 41,
			'template_tag'          => 'v1.2.3',
			'template_commit'       => str_repeat( 'c', 40 ),
			'template_asset_id'     => 73,
			'template_asset_name'   => 'ran-booster-release-bootstrap-templates.zip',
			'template_asset_size'   => 1000,
			'template_asset_digest' => str_repeat( 'd', 64 ),
			'manifest_digest'       => str_repeat( 'e', 64 ),
			'receipt_digest'        => str_repeat( 'f', 64 ),
			'consumer_api'          => 2,
			'pack_version'          => '1.2.3',
			'bundle_hash'           => str_repeat( '1', 64 ),
			'changed_path_hash'     => str_repeat( '2', 64 ),
		);
	}
}
