<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\GitHub;

require_once __DIR__ . '/../Support/ReleaseManagementWordPressFunctions.php';
require_once dirname( __DIR__, 3 ) . '/Booster/GitHub/ReleaseDeployments/WorkflowAssistance/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/GitHubReleaseWorkflowWordPressFunctions.php';
require_once __DIR__ . '/Support/GitHubReleaseWorkflowTransportFunctions.php';
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
use ReflectionMethod;
use ReflectionProperty;
use Tests\Admin\ReleaseManagement\GitHub\Support\PluginRepositoryDouble;
use Tests\Admin\ReleaseManagement\GitHub\Support\ThemeRepositoryDouble;
use Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;

final class GitHubReleaseWorkflowControlsTest extends TestCase {
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
		$this->controls()->register();

		self::assertSame(
			array(
				'ran_booster_admin_package_advanced_source_sections',
				'admin_post_ran_booster_github_release_workflow_inspect',
				'admin_post_ran_booster_github_release_workflow_setup',
				'admin_post_ran_booster_github_release_workflow_outcome',
				'admin_post_ran_booster_github_release_workflow_update_inspect',
				'admin_post_ran_booster_github_release_workflow_update_setup',
			),
			array_keys( $GLOBALS['ran_booster_release_management_test_actions'] ?? array() )
		);
		self::assertStringNotContainsString( 'release_deployments', implode( '|', array_keys( $GLOBALS['ran_booster_release_management_test_actions'] ) ) );
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

	public function testPermissionsRejectBeforePackageLookupOrRequestOnlyTokenRead(): void {
		foreach ( array( 'manage_options', 'update_plugins' ) as $denied ) {
			$this->resetWordPress();
			$plugins = new PluginRepositoryDouble();
			$facade  = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
			$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( $denied );

			$this->controls( $facade, $plugins )->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );

			self::assertSame( 0, $plugins->reads, $denied );
			self::assertSame( 0, $facade->statusReads, $denied );
			self::assertNotContains( 'request-only-secret', $GLOBALS['ran_booster_github_release_workflow_test_unslashed'] ?? array(), $denied );
			self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array(), $denied );
		}
	}

	public function testNonGitHubMissingAndStalePackagesRejectBeforeStatusOrTokenRead(): void {
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
			self::assertNotContains( 'request-only-secret', $GLOBALS['ran_booster_github_release_workflow_test_unslashed'] ?? array(), $name );
			self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array(), $name );
		}
	}

	public function testNonceRejectsAfterExactGitHubPackageGateButBeforeTokenRead(): void {
		$plugins             = new PluginRepositoryDouble();
		$facade              = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$request             = $this->request( 'inspect' );
		$request['_wpnonce'] = 'wrong-nonce';

		$this->controls( $facade, $plugins )->processWorkflowRequest( 'inspect', $request );

		self::assertSame( 1, $plugins->reads );
		self::assertSame( 1, $facade->statusReads );
		self::assertSame( array(), $facade->calls );
		self::assertNotContains( 'request-only-secret', $GLOBALS['ran_booster_github_release_workflow_test_unslashed'] ?? array() );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testAuthorisedInspectReadsTokenOnlyAfterCapabilitiesAndNonce(): void {
		$facade = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$url    = $this->controls( $facade )->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );
		$events = $GLOBALS['ran_booster_github_release_workflow_test_events'] ?? array();

		$manage = array_search( 'capability:manage_options', $events, true );
		$update = array_search( 'capability:update_plugins', $events, true );
		$verify = array_search( 'verify:' . $this->nonceAction( 'inspect' ), $events, true );
		$secret = array_search( 'unslash:request-only-secret', $events, true );
		self::assertIsInt( $manage );
		self::assertIsInt( $update );
		self::assertIsInt( $verify );
		self::assertIsInt( $secret );
		self::assertTrue( $manage < $secret );
		self::assertTrue( $update < $secret );
		self::assertTrue( $verify < $secret );
		self::assertSame( array( array( 'preflight', 'plugin', 'example/example.php', 3, 'stable', 'core-preflight' ) ), $facade->calls );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );

		$query = $this->query( $url );
		self::assertSame( 'workflow_release_ready', $query['ran_booster_github_release_workflow_result'] );
		self::assertSame( '1', $query['ran_booster_github_release_workflow_success'] );
		self::assertSame( 'stable', $query['ran_booster_github_release_workflow_channel'] );
		self::assertArrayHasKey( 'ran_booster_github_release_workflow_result_nonce', $query );
		self::assertStringNotContainsString( 'release_deployments', $url );
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
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Assess source-ready release setup', $html );
		self::assertStringNotContainsString( 'GitHub may have accepted only part', $html );
		self::assertStringNotContainsString( 'notice-warning', $html );
	}

	public function testIneligibleGitHubPackageRendersDisabledWorkflowWithoutReadingSecretsOrRemoteState(): void {
		$facade   = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( eligibilityCode: ReleaseTrackingEligibility::MISSING_UPDATE_URI )
		);
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
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Release automation', $html );
		self::assertStringContainsString( 'Release automation cannot be assessed with the current package settings.', $html );
		self::assertStringContainsString( 'exact Update URI shown in Published release readiness above', $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled>', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'github_token', $html );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );

		$controls->processWorkflowRequest( 'inspect', $this->request( 'inspect' ) );
		self::assertNotContains( 'request-only-secret', $GLOBALS['ran_booster_github_release_workflow_test_unslashed'] ?? array() );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testUnavailableLocalStatusRendersDisabledWorkflowWithoutRemoteState(): void {
		$facade                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$facade->throwOnStatus = true;
		$controls              = $this->controls( $facade );

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $this->githubPackage(), 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<details class="ran-booster-release-workflow" open>', $html );
		self::assertStringContainsString( 'could not read the local Published release readiness', $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled>', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'github_token', $html );
		self::assertSame( array(), $GLOBALS['ran_booster_github_release_workflow_test_remote'] ?? array() );
	}

	public function testEligiblePublishedReleaseWithoutSetupRecordRendersDisabledBranchRecoveryPrompt(): void {
		$controls = $this->controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $this->githubPackage( 'release_asset' ), 'https://example.test/settings' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<details class="ran-booster-release-workflow" open>', $html );
		self::assertStringContainsString( 'Return to Branch before assessing setup again.', $html );
		self::assertStringContainsString( '<button type="submit" class="button" disabled>', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertStringNotContainsString( 'github_token', $html );
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
		?ThemeRepositoryDouble $themes = null
	): GitHubReleaseWorkflowControls {
		return new GitHubReleaseWorkflowControls(
			$facade ?? new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ),
			$plugins ?? new PluginRepositoryDouble(),
			$themes ?? new ThemeRepositoryDouble()
		);
	}

	/** @return array<string,string> */
	private function request( string $operation, string $preview = '' ): array {
		$request = array(
			'expected_type'            => 'plugin',
			'expected_identifier'      => 'example/example.php',
			'expected_source_revision' => '3',
			'_wpnonce'                 => 'nonce-for-' . $this->nonceAction( $operation, $preview ),
			'github_token'             => 'request-only-secret',
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
}
