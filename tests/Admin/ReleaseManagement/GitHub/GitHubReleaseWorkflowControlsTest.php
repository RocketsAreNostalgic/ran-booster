<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\GitHub;

require_once __DIR__ . '/../Support/ReleaseManagementWordPressFunctions.php';
require_once dirname( __DIR__, 3 ) . '/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/GitHubReleaseWorkflowWordPressFunctions.php';
require_once __DIR__ . '/Support/GitHubReleaseWorkflowTransportFunctions.php';
require_once __DIR__ . '/Support/ReleaseWorkflowCredentialStoreDouble.php';
require_once __DIR__ . '/../Support/ReleaseManagementFixtures.php';
require_once dirname( __DIR__, 3 ) . '/Logging/LoggingWordPressFunctions.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Admin\ReleaseManagement\GitHub\GitHubReleaseWorkflowControls;
use RAN\Admin\ReleaseManagement\GitHub\GitHubReleaseWorkflowDisplay;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\WorkflowApplicationCoordinator;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
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
		unset( $GLOBALS['ran_booster_release_deployments_test_lock_owner'] );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The workflow store requires the focused advisory-lock database double.
		$GLOBALS['wpdb'] = new \RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupClaimDatabase();
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
				'admin_notices',
				'network_admin_notices',
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
			'release'     => array( ReleaseManagementFixture::status( 'release_asset' ), 'Published releases working', 'ok', 'release_asset' ),
		);

		foreach ( $cases as $name => $case ) {
			list( $status, $expected, $tone, $source ) = $case;
			$this->resetWordPress();
			$GLOBALS['ran_booster_release_management_test_multisite'] = true;
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
			self::assertStringStartsWith( 'https://example.test/wp-admin/network/admin.php?', $action['url'], $name );
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
		self::assertStringContainsString( '<a href="https://example.test/plugin-settings">Plugin settings</a>', $html );
		$headerPosition   = strpos( $html, 'ran-booster-repository-release-section__header' );
		$settingsPosition = strpos( $html, 'https://example.test/plugin-settings' );
		$bodyPosition     = strpos( $html, 'ran-booster-settings-section__body' );
		self::assertIsInt( $headerPosition );
		self::assertIsInt( $settingsPosition );
		self::assertIsInt( $bodyPosition );
		self::assertTrue( $headerPosition < $settingsPosition );
		self::assertTrue( $settingsPosition < $bodyPosition );
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
		self::assertStringContainsString( 'Anonymous public inspection', $html );
		self::assertStringContainsString( 'name="expected_identifier" value="example/example.php"', $html );
		self::assertStringContainsString( 'name="expected_source_revision" value="3"', $html );
		self::assertStringNotContainsString( '>Package settings</a>', $html );
		self::assertStringNotContainsString( 'Review package release settings', $html );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testPrivateRepositoryReleaseAssessmentRequiresASavedCredentialAndHidesAnonymousInspection(): void {
		$controls = $this->controls( plugins: new PluginRepositoryDouble( private: true ) );
		$row      = $this->repositoryRows()['101'];

		ob_start();
		$controls->renderRepositoryReleaseSections( $row, 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Anonymous public inspection', $html );
		self::assertStringContainsString( '<select name="booster_credential_id" required>', $html );
		self::assertStringContainsString( 'Private repository inspection needs a saved credential.', $html );

		$request                          = $this->request( 'inspect' );
		$request['booster_credential_id'] = '';
		$url                              = $controls->processWorkflowRequest( 'inspect', $request );

		self::assertSame( 'workflow_unauthorised', $this->query( $url )['ran_booster_github_release_workflow_result'] );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testPrivateRepositoryWorkflowReadsAreDisabledWithoutSavedCredentials(): void {
		$controls = $this->controls(
			plugins: new PluginRepositoryDouble( private: true ),
			credentials: new ReleaseWorkflowCredentialStoreDouble( array() )
		);
		$status   = ReleaseManagementFixture::status();
		$method   = new ReflectionMethod( $controls, 'workflowForm' );

		foreach ( array( 'inspect', 'outcome', 'update_inspect' ) as $operation ) {
			$form = $method->invoke( $controls, $operation, $status, '', '', 'stable', array(), false );

			self::assertIsArray( $form, $operation );
			self::assertTrue( $form['disabled'], $operation );
		}

		ob_start();
		$controls->renderRepositoryReleaseSections( $this->repositoryRows()['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<select name="booster_credential_id" disabled aria-disabled="true">', $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled aria-disabled="true">Assess release setup</button>', $html );
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
		$automationHeadingPosition = strpos( $html, 'ran-booster-repository-release-automation-heading' );
		$automationNoticePosition  = strpos( $html, 'This package needs the exact Update URI shown in Published release readiness above.' );
		$automationBodyPosition    = strpos( $html, 'Booster can assess this repository and prepare one atomic draft pull request.' );
		self::assertIsInt( $automationHeadingPosition );
		self::assertIsInt( $automationNoticePosition );
		self::assertIsInt( $automationBodyPosition );
		self::assertTrue( $automationHeadingPosition < $automationNoticePosition );
		self::assertTrue( $automationNoticePosition < $automationBodyPosition );
		self::assertSame( 1, substr_count( $html, 'This package needs the exact Update URI shown in Published release readiness above.' ) );
		self::assertStringNotContainsString( 'No exact local release-workflow status is available for this repository.', $html );
		$blockerPosition   = strpos( $html, 'The installed package does not declare the required Update URI.' );
		$lifecyclePosition = strpos( $html, 'Published release lifecycle' );
		self::assertIsInt( $blockerPosition );
		self::assertIsInt( $lifecyclePosition );
		self::assertTrue( $blockerPosition < $lifecyclePosition );
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

	public function testRepositoryRowsAcceptCaseVariantLocatorOnlyWithExactStableRepositoryIdentity(): void {
		$rows                      = $this->repositoryRows();
		$rows['101']['repository'] = 'Example/Example';
		$matchingFacade            = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$matching                  = $this->controls( $matchingFacade )->enrichRepositoryRows( $rows, 'gh', array(), 'https://example.test/return' );

		self::assertSame( 'Ready to assess', $matching['101']['details'][0]['value'] );
		self::assertSame( 1, $matchingFacade->statusReads );

		$mismatchedFacade = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$mismatched       = $this->controls( $mismatchedFacade, new PluginRepositoryDouble( repositoryId: '202' ) )->enrichRepositoryRows( $rows, 'gh', array(), 'https://example.test/return' );

		self::assertSame( 'Unavailable', $mismatched['101']['details'][0]['value'] );
		self::assertSame( 0, $mismatchedFacade->statusReads );
	}

	public function testRepositoryRowsBoundsDetailsBeforeNormalizationAndRetainsEveryAction(): void {
		$rows = $this->repositoryRows();
		foreach ( range( 1, 4 ) as $number ) {
			$rows['101']['details'][] = array(
				'key'   => 'core:detail-' . $number,
				'label' => 'Core detail ' . $number,
				'value' => 'Kept',
				'tone'  => 'ok',
			);
		}
		foreach ( range( 2, 17 ) as $number ) {
			$identifier                          = 'example/package-' . $number . '.php';
			$rows['101']['package_references'][] = $identifier;
			$rows['101']['package_summaries'][]  = array(
				'type'            => 'plugin',
				'identifier'      => $identifier,
				'source'          => 'branch',
				'source_revision' => 3,
			);
		}

		$projected = $this->controls()->enrichRepositoryRows( $rows, 'gh', array(), 'https://example.test/return' );

		self::assertCount( 20, $projected['101']['details'] );
		self::assertSame( array_column( $rows['101']['details'], 'key' ), array_column( array_slice( $projected['101']['details'], 0, 4 ), 'key' ) );
		self::assertCount( 17, $projected['101']['actions'] );
		self::assertSame(
			array_map(
				static fn ( array $summary ): string => 'gh:release-automation-' . substr( hash( 'sha256', $summary['type'] . '|' . $summary['identifier'] ), 0, 16 ),
				$rows['101']['package_summaries']
			),
			array_keys( $projected['101']['actions'] )
		);
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

	public function testRequestBoundaryRefusalsHaveBoundedCategoriesAndDoNotReadSavedCredentials(): void {
		$cases = array(
			'malformed_request'       => static function ( array $request ): array {
				unset( $request['_wpnonce'] );
				return $request;
			},
			'permissions_unavailable' => static function ( array $request ): array {
				$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'manage_options' );
				return $request;
			},
			'package_source_changed'  => static function ( array $request ): array {
				return $request;
			},
			'nonce_expired'           => static function ( array $request ): array {
				$request['_wpnonce'] = 'expired-nonce';
				return $request;
			},
		);

		foreach ( $cases as $diagnostic => $prepare ) {
			$this->resetWordPress();
			$plugins = 'package_source_changed' === $diagnostic ? new PluginRepositoryDouble( sourceRevision: 4 ) : new PluginRepositoryDouble();
			$url     = $this->controls( plugins: $plugins )->processWorkflowRequest( 'inspect', $prepare( $this->request( 'inspect' ) ) );
			$query   = $this->query( $url );

			self::assertSame( 'workflow_invalid_request', $query['ran_booster_github_release_workflow_result'], $diagnostic );
			self::assertSame( 'request_validation', $query['ran_booster_github_release_workflow_failure_stage'], $diagnostic );
			self::assertSame( $diagnostic, $query['ran_booster_github_release_workflow_diagnostic'], $diagnostic );
			self::assertSame( '0', $query['ran_booster_github_release_workflow_diagnostic_available'], $diagnostic );
			self::assertSame( '', $query['ran_booster_github_release_workflow_reference'], $diagnostic );
			self::assertNotContains( 'credential_1', $GLOBALS['ran_booster_github_release_workflow_test_unslashed'] ?? array(), $diagnostic );
			self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array(), $diagnostic );
		}
	}

	public function testChangedSourceRevisionReturnsToRepositoryAndRendersSignedResultWithoutBindingItToCurrentForms(): void {
		$currentStatus = $this->statusAtRevision( ReleaseManagementFixture::status(), 4 );
		$credentials   = new ReleaseWorkflowCredentialStoreDouble();
		$plugins       = new PluginRepositoryDouble( sourceRevision: 4 );
		$controls      = $this->controls( new ReleaseTrackingFacadeDouble( $currentStatus ), $plugins, credentials: $credentials );
		$url           = $controls->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );

		self::assertStringContainsString( 'panel=repositories', $url );
		self::assertStringContainsString( 'repository=101', $url );
		self::assertStringContainsString( 'repository_view=releases', $url );
		self::assertSame( 0, $credentials->profileReads );
		self::assertSame( 0, $credentials->materialReads );

		$_GET = $this->query( $url );
		$row  = $this->repositoryRows()['101'];
		$row['package_summaries'][0]['source_revision'] = 4;
		$renderCredentials                              = new ReleaseWorkflowCredentialStoreDouble();
		ob_start();
		$this->controls( new ReleaseTrackingFacadeDouble( $currentStatus ), new PluginRepositoryDouble( sourceRevision: 4 ), credentials: $renderCredentials )
			->renderRepositoryReleaseSections( $row, 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-github-release-workflow-result', $html );
		self::assertStringContainsString( 'Booster stopped before contacting GitHub because this request no longer matched the current page or package.', $html );
		self::assertStringContainsString( 'The saved package or source changed before Booster could act.', $html );
		self::assertStringContainsString( 'name="expected_source_revision" value="4"', $html );
		self::assertStringContainsString( '<button type="submit" class="button">Assess release setup</button>', $html );
		self::assertSame( 0, $renderCredentials->materialReads );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testMissingCurrentPackageRendersSignedSourceChangedResultOnPackageSurface(): void {
		$credentials = new ReleaseWorkflowCredentialStoreDouble();
		$controls    = $this->controls( plugins: new PluginRepositoryDouble( missing: true ), credentials: $credentials );
		$url         = $controls->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );

		self::assertStringContainsString( 'page=ran-booster-plugins', $url );
		self::assertStringContainsString( 'package=example%2Fexample.php', $url );
		self::assertStringNotContainsString( 'panel=repositories', $url );
		$_GET = $this->query( $url );

		ob_start();
		$controls->renderPackageFallbackResultNotice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-github-release-workflow-result', $html );
		self::assertStringContainsString( 'The saved package or source changed before Booster could act.', $html );
		self::assertSame( 0, $credentials->profileReads );
		self::assertSame( 0, $credentials->materialReads );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testRequestBoundaryLoggingContainsOnlyTheBoundedDiagnosticAndReference(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-release-workflow-request-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates an isolated test-only capture directory.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new TemporaryDebugCapture( $directory . '/capture.json' );
		$capture->start();
		BoosterLogger::configureCapture( $capture );

		try {
			$request                          = $this->request( 'inspect' );
			$request['confirm_repository']    = 'do-not-log-confirmation';
			$request['booster_credential_id'] = 'do-not-log-credential';
			unset( $request['_wpnonce'] );
			$query   = $this->query( $this->controls()->processWorkflowRequest( 'inspect', $request ) );
			$entries = $capture->snapshot()['entries'];

			self::assertCount( 1, $entries );
			self::assertSame( '1', $query['ran_booster_github_release_workflow_diagnostic_available'] );
			self::assertMatchesRegularExpression( '/\\A[a-f0-9]{32}\\z/', $query['ran_booster_github_release_workflow_reference'] );
			self::assertStringContainsString( 'GitHub release workflow request refused', $entries[0]['line'] );
			self::assertStringContainsString( '"diagnostic_id":"malformed_request"', $entries[0]['line'] );
			self::assertStringContainsString( '"correlation_id":"' . $query['ran_booster_github_release_workflow_reference'] . '"', $entries[0]['line'] );
			self::assertStringNotContainsString( 'do-not-log-confirmation', $entries[0]['line'] );
			self::assertStringNotContainsString( 'do-not-log-credential', $entries[0]['line'] );
		} finally {
			BoosterLogger::configureCapture( null );
			$capture->deleteManagedStorage();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the isolated test-only directory.
			self::assertTrue( rmdir( $directory ) );
		}
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
		self::assertSame( array( array( 'assessment_preflight', 'plugin', 'example/example.php', 3, 'stable', 'core-preflight' ) ), $facade->calls );
		self::assertCount( 1, $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );

		$query = $this->query( $url );
		self::assertSame( 'workflow_remote_unavailable', $query['ran_booster_github_release_workflow_result'] );
		self::assertSame( '0', $query['ran_booster_github_release_workflow_success'] );
		self::assertSame( 'stable', $query['ran_booster_github_release_workflow_channel'] );
		self::assertSame( 'repository_snapshot', $query['ran_booster_github_release_workflow_failure_stage'] );
		self::assertSame( 'repository_snapshot_unavailable', $query['ran_booster_github_release_workflow_diagnostic'] );
		self::assertSame( '0', $query['ran_booster_github_release_workflow_diagnostic_available'] );
		self::assertSame( '', $query['ran_booster_github_release_workflow_reference'] );
		self::assertSame( '3', $query['ran_booster_github_release_workflow_source_revision'] );
		self::assertArrayHasKey( 'ran_booster_github_release_workflow_result_nonce', $query );
		$requestedResult = new ReflectionMethod( $this->controls(), 'requestedResult' );
		$_GET            = $query;
		self::assertSame( 'repository_snapshot_unavailable', $requestedResult->invoke( $this->controls() )['diagnostic_code'] );
		$_GET['ran_booster_github_release_workflow_diagnostic'] = 'provider_unavailable';
		self::assertNull( $requestedResult->invoke( $this->controls() ) );
		$_GET = $query;
		$_GET['ran_booster_github_release_workflow_source_revision'] = '4';
		self::assertNull( $requestedResult->invoke( $this->controls() ) );
		$_GET = array();
		self::assertStringNotContainsString( 'release_deployments', $url );
	}

	public function testRepositoryResultNoticeIsBoundToTheExactSourceRevision(): void {
		$query  = $this->query( $this->controls()->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) ) );
		$method = new ReflectionMethod( $this->controls(), 'resultNonceAction' );
		$action = $method->invoke(
			$this->controls(),
			'workflow_remote_unavailable',
			false,
			'plugin',
			'example/example.php',
			4,
			'stable',
			'repository_snapshot',
			'repository_snapshot_unavailable',
			false,
			''
		);
		$query['ran_booster_github_release_workflow_source_revision'] = '4';
		$query['ran_booster_github_release_workflow_result_nonce']    = 'nonce-for-' . $action;
		$_GET = $query;

		ob_start();
		$this->controls()->renderRepositoryReleaseSections( $this->repositoryRows()['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'data-ran-booster-github-release-workflow-result', $html );
	}

	public function testMixedRepositoryAutomationObservationsDoNotClaimCanonicalSuccess(): void {
		$method = new ReflectionMethod( $this->controls(), 'repositoryReleaseAutomationState' );
		$state  = $method->invoke( $this->controls(), '', false, true, false, 'mixed_observations' );

		self::assertSame( 'Multiple assessments', $state['label'] );
		self::assertSame( 'ran-booster-badge--info', $state['tone'] );
		self::assertSame( 'notice-info', $state['notice_tone'] );
		self::assertStringContainsString( 'differs between packages', $state['message'] );
	}

	public function testEveryRepositoryAutomationStateKeepsAnExplicitBoosterSetupLine(): void {
		$method = new ReflectionMethod( $this->controls(), 'repositoryReleaseAutomationState' );
		$cases  = array(
			'needs_attention'     => array( '', false, false, false, 'unassessed' ),
			'ready'               => array( '', true, false, false, 'unassessed' ),
			'blocked'             => array( '', false, false, true, 'unassessed' ),
			'existing_automation' => array( '', false, true, false, 'existing_automation_detected' ),
			'verified_automation' => array( '', false, true, false, 'booster_setup_verified' ),
			'mixed_observations'  => array( '', false, true, false, 'mixed_observations' ),
			'no_automation'       => array( '', false, true, false, 'no_recognisable_automation' ),
			'setup_recorded'      => array( 'Plugin · Example plugin', false, false, false, 'unassessed' ),
		);

		foreach ( $cases as $name => $arguments ) {
			$state = $method->invoke( $this->controls(), ...$arguments );
			self::assertStringStartsWith( 'Booster setup:', $state['provenance'], $name );
		}
	}

	public function testFailedWorkflowIsCapturedWithOnlySafeDiagnosticContext(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-release-workflow-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates an isolated test-only capture directory.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new TemporaryDebugCapture( $directory . '/secrets.json' );
		$capture->start();
		BoosterLogger::configureCapture( $capture );

		try {
			$url     = $this->controls()->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );
			$query   = $this->query( $url );
			$entries = $capture->snapshot()['entries'];

			self::assertCount( 1, $entries );
			self::assertStringContainsString( '[ran-booster] GitHub release workflow failed', $entries[0]['line'] );
			self::assertStringContainsString( '"operation":"inspect"', $entries[0]['line'] );
			self::assertStringContainsString( '"outcome_code":"workflow_remote_unavailable"', $entries[0]['line'] );
			self::assertStringContainsString( '"step":"repository_snapshot"', $entries[0]['line'] );
			self::assertStringContainsString( '"correlation_id":"' . $query['ran_booster_github_release_workflow_reference'] . '"', $entries[0]['line'] );
			self::assertStringNotContainsString( 'saved-secret', $entries[0]['line'] );
			self::assertStringNotContainsString( 'credential_1', $entries[0]['line'] );
		} finally {
			BoosterLogger::configureCapture( null );
			$capture->deleteManagedStorage();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the isolated test-only directory.
			self::assertTrue( rmdir( $directory ) );
		}
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
			if ( in_array( $operation, array( 'inspect', 'setup' ), true ) ) {
				self::assertSame( 'nonce-for-release-tracking-assessment_preflight-plugin-example/example.php-3-stable', $form['fields']['core_preflight_nonce_stable'], $operation );
			}
		}

		$url   = $controls->processWorkflowRequest( 'setup', $this->request( 'setup', $preview ) );
		$query = $this->query( $url );
		self::assertSame( $preview, $query['ran_booster_github_release_workflow_preview'] );
		self::assertArrayNotHasKey( 'ran_booster_release_workflow_preview', $query );
		self::assertArrayNotHasKey( 'ran_booster_release_deployments_preview', $query );
	}

	public function testWriteFormsAreDisabledWhenNoSavedCredentialChoicesExist(): void {
		$controls = $this->controls();
		$status   = ReleaseManagementFixture::status();
		$method   = new ReflectionMethod( $controls, 'workflowForm' );

		foreach ( array( 'setup', 'update_setup' ) as $operation ) {
			$form = $method->invoke( $controls, $operation, $status, str_repeat( 'a', 32 ), 'example/example', 'stable', array() );

			self::assertIsArray( $form, $operation );
			self::assertSame( array(), $form['credentials'], $operation );
			self::assertTrue( $form['disabled'], $operation );
		}
	}

	public function testAllWorkflowPostReturnsUseNetworkAdminOnMultisite(): void {
		$GLOBALS['ran_booster_release_management_test_multisite'] = true;
		$controls = $this->controls();

		foreach ( array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ) as $operation ) {
			$preview = in_array( $operation, array( 'setup', 'update_setup' ), true ) ? str_repeat( 'a', 32 ) : '';
			$url     = $controls->processWorkflowRequest( $operation, $this->request( $operation, $preview ) );

			self::assertStringStartsWith( 'https://example.test/wp-admin/network/admin.php?', $url, $operation );
			self::assertStringContainsString( 'page=ran-booster', $url, $operation );
			self::assertStringContainsString( 'tab=gh', $url, $operation );
			self::assertStringContainsString( 'panel=repositories', $url, $operation );
			self::assertStringContainsString( 'repository=101', $url, $operation );
			self::assertStringContainsString( 'repository_view=releases', $url, $operation );
		}
	}

	public function testWorkflowCredentialNavigationUsesNetworkAdminOnMultisite(): void {
		$GLOBALS['ran_booster_release_management_test_multisite'] = true;
		$controls = $this->controls();

		$workflowForm = new ReflectionMethod( $controls, 'workflowForm' );
		$form         = $workflowForm->invoke( $controls, 'inspect', ReleaseManagementFixture::status(), '', '', 'stable' );

		self::assertIsArray( $form );
		self::assertSame(
			'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=gh&view=credentials',
			$form['credentials_url']
		);

		$unavailableWorkflowView = new ReflectionMethod( $controls, 'unavailableWorkflowView' );
		$unavailable             = $unavailableWorkflowView->invoke( $controls, 'Release workflow management is unavailable.' );

		self::assertSame(
			'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=gh&view=credentials',
			$unavailable['forms']['inspect']['credentials_url']
		);
	}

	public function testVerifiedResultFromAnotherPackageIsNotRenderedOnCurrentScreen(): void {
		$status   = $this->workflowStatus( 'other/other.php' );
		$facade   = new ReleaseTrackingFacadeDouble( $status );
		$controls = $this->controls( $facade );
		$payload  = array( 'workflow_partial', false, 'plugin', 'example/example.php', 'stable', '', '' );
		$action   = 'ran-booster-github-release-workflow-result-' . hash( 'sha256', (string) \RAN\Admin\ReleaseManagement\wp_json_encode( $payload ) );
		$_GET     = array(
			'page'                                        => 'ran-booster-plugins',
			'package'                                     => 'other/other.php',
			'ran_booster_github_release_workflow_result'  => 'workflow_partial',
			'ran_booster_github_release_workflow_success' => '0',
			'ran_booster_github_release_workflow_type'    => 'plugin',
			'ran_booster_github_release_workflow_package' => 'example/example.php',
			'ran_booster_github_release_workflow_channel' => 'stable',
			'ran_booster_github_release_workflow_failure_stage' => '',
			'ran_booster_github_release_workflow_reference' => '',
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
		self::assertStringNotContainsString( '>Established</span>', $repositoryHtml );
		self::assertStringNotContainsString( 'Release automation is established.', $repositoryHtml );
	}

	public function testEligiblePublishedReleaseReportsAvailabilityWithoutWorkflowProvenance(): void {
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

		self::assertStringContainsString( '>Not assessed</span>', $html );
		self::assertStringContainsString( 'ran-booster-badge--info', $html );
		self::assertStringNotContainsString( '>Needs attention</span>', $html );
		self::assertStringNotContainsString( '>Established</span>', $html );
		self::assertStringNotContainsString( 'return to Branch', $html );
		self::assertStringContainsString( 'Published releases are working. Booster has not assessed how this repository produces them.', $html );
		self::assertStringContainsString( 'Booster setup: Not recorded.', $html );
		self::assertStringContainsString( 'Booster can assess this repository and prepare one atomic draft pull request.', $html );
		self::assertStringContainsString( 'Assess release setup', $html );
		self::assertStringNotContainsString( '<button type="button" class="button" disabled aria-disabled="true">Set up release automation</button>', $html );
	}

	public function testPublishedReleaseDoesNotEstablishAutomationWithUnrelatedBranchPackages(): void {
		$controls                    = $this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );
		$row                         = $this->repositoryRows( 'release_asset' )['101'];
		$row['source_key']           = 'mixed';
		$row['package_references'][] = 'example-theme';
		$row['package_summaries'][]  = array(
			'type'            => 'theme',
			'identifier'      => 'example-theme',
			'source'          => 'branch',
			'source_revision' => 3,
		);

		ob_start();
		$controls->renderRepositoryReleaseSections( $row, 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Not assessed</span>', $html );
		self::assertStringNotContainsString( '>Needs attention</span>', $html );
		self::assertStringNotContainsString( '>Established</span>', $html );
		self::assertStringContainsString( 'Booster could not confirm that this release status belongs to the exact saved package and source.', $html );
	}

	public function testRepositoryAutomationFailsClosedForASecondReleaseRelationshipWithUnconfirmedStatus(): void {
		$controls                    = $this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );
		$row                         = $this->repositoryRows( 'release_asset' )['101'];
		$row['package_references'][] = 'example-theme';
		$row['package_summaries'][]  = array(
			'type'            => 'theme',
			'identifier'      => 'example-theme',
			'source'          => 'release_asset',
			'source_revision' => 3,
		);

		ob_start();
		$controls->renderRepositoryReleaseSections( $row, 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Booster could not confirm this package’s exact local release status.', $html );
		self::assertStringNotContainsString( '>Established</span>', $html );
		self::assertStringNotContainsString( 'Published releases are being produced for this repository.', $html );
	}

	public function testRepositoryReleasePanelFailsClosedWhenStatusSourceDoesNotMatchTheExactSummary(): void {
		$controls = $this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );

		ob_start();
		$controls->renderRepositoryReleaseSections( $this->repositoryRows( 'branch' )['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Booster could not confirm this package’s exact local release status.', $html );
		self::assertStringContainsString( 'Booster could not confirm that this release status belongs to the exact saved package and source.', $html );
		self::assertStringNotContainsString( '1 of 1 packages use Published releases.', $html );
		self::assertStringNotContainsString( '>Established</span>', $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled aria-disabled="true">Assess release setup</button>', $html );
	}

	public function testRepositoryReleasePanelDoesNotTreatACompatibleReleaseAsWorkflowProof(): void {
		self::assertTrue( ( new SetupRecordStore() )->save( $this->setupRecord( 'other/other.php' ) ) );
		$controls = $this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );
		$row      = $this->repositoryRows( 'release_asset' )['101'];
		$row['package_summaries'][0]['settings_url'] = 'https://example.test/plugin-settings';

		ob_start();
		$controls->renderRepositoryReleaseSections( $row, 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '1 of 1 packages use Published releases.', $html );
		self::assertStringContainsString( 'Published releases · Stable track.', $html );
		self::assertStringContainsString( '>Blocked</span>', $html );
		self::assertStringNotContainsString( '>Established</span>', $html );
		self::assertStringContainsString( 'A local workflow record is occupied by a different package or revision. Review it before setup.', $html );
		self::assertStringContainsString( 'Booster setup: Not recorded.', $html );
		self::assertStringContainsString( 'name="booster_credential_id"', $html );
		self::assertStringContainsString( '<select name="booster_credential_id" disabled aria-disabled="true">', $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled aria-disabled="true">Assess release setup</button>', $html );
		self::assertStringNotContainsString( 'Set up release automation', $html );
		self::assertStringNotContainsString( 'Review recorded draft pull request', $html );
		self::assertStringContainsString( '<a href="https://example.test/plugin-settings">Plugin settings</a>', $html );
		self::assertStringNotContainsString( 'Review package release settings', $html );
	}

	public function testRepositoryReleasePanelDisablesWorkflowWhenThePackageInventoryIsIncomplete(): void {
		$controls                         = $this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );
		$row                              = $this->repositoryRows( 'release_asset' )['101'];
		$row['package_summaries_omitted'] = 1;
		$row['package_summaries'][0]['settings_url'] = 'https://example.test/plugin-settings';

		ob_start();
		$controls->renderRepositoryReleaseSections( $row, 'https://example.test/return?repository_view=releases' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Inventory incomplete</span>', $html );
		self::assertStringContainsString( 'The full managed-package inventory for this repository is not available. Reload the repository before assessing or setting up release automation.', $html );
		self::assertStringContainsString( 'The complete managed-package inventory is unavailable.', $html );
		self::assertStringNotContainsString( '1 of 1 packages use Published releases.', $html );
		self::assertStringNotContainsString( 'Published releases are working. Booster has not assessed how this repository produces them.', $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled aria-disabled="true">Assess release setup</button>', $html );
		self::assertStringNotContainsString( 'Open draft pull request</button>', $html );
	}

	public function testRepositoryReleasePanelKeepsAnExactAutomationObservationAfterALaterFailure(): void {
		self::assertTrue(
			( new SetupRecordStore() )->saveAssessmentObservation(
				array(
					'kind'               => 'existing_automation_detected',
					'repository_id'      => '101',
					'package_type'       => 'plugin',
					'package_identifier' => 'example/example.php',
					'source_revision'    => 3,
					'observed_at'        => '2026-08-27T12:34:56Z',
				)
			)
		);

		ob_start();
		$this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) )
			->renderRepositoryReleaseSections( $this->repositoryRows( 'release_asset' )['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Existing automation found</span>', $html );
		self::assertStringContainsString( 'ran-booster-badge--info', $html );
		self::assertStringContainsString( 'ran-booster-repository-release-automation__header', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-release-automation__notices"', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-release-automation__body"', $html );
		self::assertStringContainsString( '<div class="notice notice-info inline"><p>Existing release automation was found in this repository. Booster will not overwrite it.</p>', $html );
		self::assertStringContainsString( 'Booster setup: Not recorded.', $html );
		self::assertStringContainsString( 'href="https://github.com/example/example/actions"', $html );
		self::assertStringContainsString( 'Review existing automation on GitHub', $html );
		self::assertStringNotContainsString( '>Not assessed</span>', $html );
		$noticesPosition    = strpos( $html, 'ran-booster-repository-release-automation__notices' );
		$provenancePosition = strpos( $html, 'Booster setup: Not recorded.' );
		$bodyPosition       = strpos( $html, 'ran-booster-repository-release-automation__body' );
		self::assertIsInt( $noticesPosition );
		self::assertIsInt( $provenancePosition );
		self::assertIsInt( $bodyPosition );
		self::assertTrue( $provenancePosition < $noticesPosition );
		self::assertTrue( $noticesPosition < $bodyPosition );

		self::assertTrue(
			( new SetupRecordStore() )->recordFailure(
				array(
					'operation'             => 'inspect',
					'outcome_code'          => 'workflow_remote_unavailable',
					'failure_stage'         => 'repository_snapshot',
					'package_type'          => 'plugin',
					'package_identifier'    => 'example/example.php',
					'source_revision'       => 3,
					'repository_id'         => '101',
					'diagnostic_code'       => 'repository_snapshot_unavailable',
					'diagnostic_available'  => false,
					'correlation_reference' => str_repeat( 'e', 32 ),
					'recorded_at'           => '2026-08-27T12:35:56Z',
				)
			)
		);

		ob_start();
		$this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) )
			->renderRepositoryReleaseSections( $this->repositoryRows( 'release_asset' )['101'], 'https://example.test/return' );
		$afterLaterFailure = (string) ob_get_clean();

		self::assertStringContainsString( '>Existing automation found</span>', $afterLaterFailure );
		self::assertStringNotContainsString( '>Not assessed</span>', $afterLaterFailure );
	}

	public function testRepositoryReleasePanelDoesNotCollapseAnUnassessedPackageIntoAnotherPackagesVerifiedObservation(): void {
		$verifiedStatus              = ReleaseManagementFixture::status( 'release_asset' );
		$unassessedStatus            = $this->workflowStatus( 'other/other.php' );
		$facade                      = new ReleaseTrackingFacadeDouble(
			$verifiedStatus,
			array(
				'plugin|example/example.php' => $verifiedStatus,
				'plugin|other/other.php'     => $unassessedStatus,
			)
		);
		$row                         = $this->repositoryRows( 'release_asset' )['101'];
		$row['package_references'][] = 'other/other.php';
		$row['package_summaries'][0]['display_name'] = 'Example Plugin';
		$row['package_summaries'][]                  = array(
			'type'            => 'plugin',
			'identifier'      => 'other/other.php',
			'display_name'    => 'Other Plugin',
			'source'          => 'branch',
			'source_revision' => 3,
		);

		self::assertTrue(
			( new SetupRecordStore() )->saveAssessmentObservation(
				array(
					'kind'               => 'booster_setup_verified',
					'repository_id'      => '101',
					'package_type'       => 'plugin',
					'package_identifier' => 'example/example.php',
					'source_revision'    => 3,
					'observed_at'        => '2026-08-27T12:34:56Z',
				)
			)
		);

		ob_start();
		$this->controls( $facade )->renderRepositoryReleaseSections( $row, 'https://example.test/return' );
		$html = (string) ob_get_clean();

		$headerStart = strpos( $html, 'ran-booster-repository-release-automation__header' );
		self::assertIsInt( $headerStart );
		$headerEnd = strpos( $html, '</header>', $headerStart );
		self::assertIsInt( $headerEnd );
		$header = substr( $html, $headerStart, $headerEnd - $headerStart );

		self::assertStringContainsString( '>Multiple assessments</span>', $header );
		self::assertStringNotContainsString( '>Compatible automation verified</span>', $header );
		self::assertStringContainsString( 'Example Plugin', $html );
		self::assertStringContainsString( '>Compatible automation verified</span>', $html );
		self::assertStringContainsString( 'Other Plugin', $html );
		self::assertStringContainsString( '>Not assessed</span>', $html );
	}

	public function testCredentialFailureDoesNotReplaceThePersistedAutomationObservation(): void {
		$store = new SetupRecordStore();
		self::assertTrue(
			$store->saveAssessmentObservation(
				array(
					'kind'               => 'existing_automation_detected',
					'repository_id'      => '101',
					'package_type'       => 'plugin',
					'package_identifier' => 'example/example.php',
					'source_revision'    => 3,
					'observed_at'        => '2026-08-27T12:34:56Z',
				)
			)
		);
		$request                          = $this->request( 'inspect' );
		$request['booster_credential_id'] = 'missing-credential';
		$_GET                             = $this->query( $this->controls()->processWorkflowRequest( 'inspect', $request ) );

		ob_start();
		$this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) )
			->renderRepositoryReleaseSections( $this->repositoryRows( 'release_asset' )['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Existing automation found</span>', $html );
		self::assertStringContainsString( 'Existing release automation was found in this repository.', $html );
		self::assertStringContainsString( '<div class="notice notice-error inline" data-ran-booster-github-release-workflow-result>', $html );
		self::assertStringContainsString( 'GitHub did not authorise the operation with the selected saved credential.', $html );
		self::assertStringNotContainsString( '>Not assessed</span>', $html );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-github-release-workflow-result' ) );
		self::assertLessThan(
			strpos( $html, 'ran-booster-repository-release-package__body' ),
			strpos( $html, 'data-ran-booster-github-release-workflow-result' )
		);
	}

	public function testAssessmentOutcomesPersistExactObservationStateOutsideFailureHistory(): void {
		$method = new ReflectionMethod( $this->controls(), 'preserveWorkflowObservation' );
		$status = ReleaseManagementFixture::status();
		foreach ( array(
			'workflow_release_automation_conflict' => 'existing_automation_detected',
			'workflow_release_automation_present'  => 'booster_setup_verified',
			'workflow_inspected'                   => 'no_recognisable_automation',
		) as $code => $kind ) {
			self::assertTrue(
				$method->invoke(
					$this->controls(),
					$status,
					$this->workflowResultFixture( $code, 'workflow_inspected' === $code )
				)
			);
			self::assertSame(
				$kind,
				( new SetupRecordStore() )->assessmentObservation( '101', 'plugin', 'example/example.php', 3 )['kind']
			);
		}
		self::assertSame( array(), ( new SetupRecordStore() )->failureHistory( '101', 'plugin', 'example/example.php', 3 ) );
	}

	public function testPersistedAssessmentObservationStillEmitsThePrgResultMarker(): void {
		$controls = $this->controls();
		$result   = $this->workflowResultFixture( 'workflow_inspected', true );
		$preserve = new ReflectionMethod( $controls, 'preserveWorkflowObservation' );
		$query    = new ReflectionMethod( $controls, 'resultQueryArguments' );

		self::assertTrue( $preserve->invoke( $controls, ReleaseManagementFixture::status(), $result ) );
		$_GET = $query->invoke( $controls, $result, 'stable', 3 );

		ob_start();
		$controls->renderRepositoryReleaseSections( $this->repositoryRows()['101'], 'https://example.test/return' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<div data-ran-booster-github-release-workflow-result hidden></div>', $html );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-github-release-workflow-result' ) );
	}

	public function testMergedOutcomeKeepsTheExactSetupRecordAsTheOnlyDurableState(): void {
		self::assertTrue( ( new SetupRecordStore() )->save( $this->setupRecord() ) );
		$method    = new ReflectionMethod( $this->controls(), 'workflowViewFor' );
		$merged    = $method->invoke( $this->controls(), 'plugin', 'example/example.php', 3, 'workflow_pr_merged', true, '', 'stable' );
		$persisted = $method->invoke( $this->controls(), 'plugin', 'example/example.php', 3, '', false, '', 'stable' );

		self::assertSame( 'setup_recorded', $merged['automation_state'] );
		self::assertArrayHasKey( 'outcome', $merged['forms'] );
		self::assertSame( 'setup_recorded', $persisted['automation_state'] );
	}

	public function testExactPackageWorkflowViewExposesOnlyItsPersistedFailureHistory(): void {
		self::assertTrue(
			( new SetupRecordStore() )->recordFailure(
				array(
					'operation'             => 'inspect',
					'outcome_code'          => 'workflow_remote_unavailable',
					'failure_stage'         => 'repository_snapshot',
					'package_type'          => 'plugin',
					'package_identifier'    => 'example/example.php',
					'source_revision'       => 3,
					'repository_id'         => '101',
					'diagnostic_code'       => 'repository_snapshot_unavailable',
					'diagnostic_available'  => false,
					'correlation_reference' => str_repeat( 'c', 32 ),
					'recorded_at'           => '2026-08-27T12:34:56Z',
				)
			)
		);
		$method = new ReflectionMethod( $this->controls(), 'workflowViewFor' );
		$view   = $method->invoke( $this->controls(), 'plugin', 'example/example.php', 3, '', false, '', 'stable' );

		self::assertIsArray( $view );
		self::assertSame( 'repository_snapshot', $view['failure_history'][0]['failure_stage'] );
		self::assertSame( '2026-08-27T12:34:56Z', $view['failure_history'][0]['recorded_at'] );
		self::assertSame( str_repeat( 'c', 32 ), $view['failure_history'][0]['correlation_reference'] );
		self::assertArrayNotHasKey( 'repository', $view['failure_history'][0] );

		$revisionFour = $this->statusAtRevision( ReleaseManagementFixture::status(), 4 );
		$stale        = $method->invoke(
			$this->controls( new ReleaseTrackingFacadeDouble( $revisionFour ), new PluginRepositoryDouble( sourceRevision: 4 ) ),
			'plugin',
			'example/example.php',
			4,
			'',
			false,
			'',
			'stable'
		);
		self::assertIsArray( $stale );
		self::assertSame( array(), $stale['failure_history'] );
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

	/** @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string} */
	private function workflowResultFixture( string $code, bool $successful ): array {
		return array(
			'type'                  => 'plugin',
			'identifier'            => 'example/example.php',
			'code'                  => $code,
			'successful'            => $successful,
			'preview_key'           => '',
			'failure_stage'         => $successful ? '' : 'repository_snapshot',
			'diagnostic_code'       => $successful ? '' : 'release_automation_detected',
			'diagnostic_available'  => false,
			'correlation_reference' => '',
		);
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

	private function statusAtRevision( ReleaseTrackingStatus $status, int $revision ): ReleaseTrackingStatus {
		return new ReleaseTrackingStatus(
			$status->type(),
			$status->identifier(),
			$status->source(),
			$revision,
			$status->providerRepositoryId(),
			$status->deploymentPolicy(),
			$status->eligibility(),
			$status->preflight(),
			$status->packageRoot(),
			$status->installedVersion(),
			$status->latestVersion(),
			$status->updateAvailable(),
			$status->lastCheckedAt(),
			$status->cooldownUntil(),
			$status->failureCode(),
			$status->channel()
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
