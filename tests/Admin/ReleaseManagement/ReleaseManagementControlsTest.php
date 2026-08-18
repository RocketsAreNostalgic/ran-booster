<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Tests\Admin\ReleaseManagement\Support\ProspectiveReleaseFacadeDouble;
use Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;

final class ReleaseManagementControlsTest extends TestCase {
	#[Before]
	public function resetWordPress(): void {
		ReleaseManagementFixture::resetWordPress();
	}

	public function testRegistersOneCoreOwnedNeutralControlSurfaceWithNewRouteNames(): void {
		$controls = ReleaseManagementFixture::controls();
		$controls->register();

		$filters = array_keys( $GLOBALS['ran_booster_release_management_test_filters'] ?? array() );
		$actions = array_keys( $GLOBALS['ran_booster_release_management_test_actions'] ?? array() );

		self::assertSame(
			array(
				'ran_booster_admin_package_management_rows',
				'ran_booster_admin_package_management_actions',
				'ran_booster_admin_package_source_choices',
				'ran_booster_admin_package_advanced_source_summary',
				'ran_booster_documentation_sections_before_about',
			),
			$filters
		);
		self::assertSame(
			array(
				'ran_booster_admin_package_advanced_source_sections',
				'admin_notices',
				'admin_enqueue_scripts',
				'admin_post_ran_booster_release_enable',
				'admin_post_ran_booster_release_refresh',
				'admin_post_ran_booster_release_return_to_branch',
				'admin_post_ran_booster_release_change_channel',
				'admin_post_ran_booster_release_install',
				'wp_ajax_ran_booster_release_list_candidates',
				'wp_ajax_ran_booster_release_inspect',
			),
			$actions
		);
		self::assertStringNotContainsString( 'release_deployments', implode( '|', array_merge( $filters, $actions ) ) );
	}

	public function testHydratesTheExistingSourceChoiceWithoutAStandaloneProductIdentity(): void {
		$controls = ReleaseManagementFixture::controls();
		$choices  = array(
			'release_asset' => array(
				'heading'     => 'Unavailable feature',
				'description' => 'Unavailable.',
				'meta'        => 'Unavailable',
				'url'         => '',
				'disabled'    => true,
			),
		);

		$hydrated = $controls->filterSourceChoices(
			$choices,
			'create',
			'plugin',
			null,
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins-create'
		);

		self::assertSame( array( 'release_asset' ), array_keys( $hydrated ) );
		self::assertSame( 'Published releases', $hydrated['release_asset']['heading'] );
		self::assertTrue( $hydrated['release_asset']['hydrated'] );
		self::assertTrue( $hydrated['release_asset']['client_hydratable'] );
		self::assertStringNotContainsString(
			'Release Deployments',
			implode( ' ', array_filter( $hydrated['release_asset'], 'is_string' ) )
		);
	}

	public function testCreateAssetsExposeOnlyCompleteProviderProjectionAndNewActions(): void {
		$prospective                     = new ProspectiveReleaseFacadeDouble();
		$prospective->supportedProviders = array( 'gh', 'acme' );
		$controls                        = ReleaseManagementFixture::controls( prospective: $prospective );
		$_GET['page']                    = 'ran-booster-themes-create'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen fixture.

		$controls->enqueueProspectiveAssets();

		self::assertSame( array( 'ran-booster-release-management' ), array_keys( $GLOBALS['ran_booster_release_management_test_scripts'] ?? array() ) );
		self::assertSame( array( 'ran-booster-release-management' ), array_keys( $GLOBALS['ran_booster_release_management_test_styles'] ?? array() ) );
		$projection = $GLOBALS['ran_booster_release_management_test_localized']['ran-booster-release-management']['ranBoosterReleaseManagement'] ?? null;
		self::assertIsArray( $projection );
		self::assertSame( 'theme', $projection['type'] );
		self::assertSame( array( 'gh', 'acme' ), $projection['supportedProviders'] );
		self::assertSame(
			array(
				'listCandidates' => 'ran_booster_release_list_candidates',
				'inspect'        => 'ran_booster_release_inspect',
				'install'        => 'ran_booster_release_install',
			),
			$projection['actions']
		);
		self::assertStringNotContainsString(
			'release_deployments',
			(string) \RAN\Admin\ReleaseManagement\wp_json_encode( $projection )
		);
	}

	public function testDocumentationIsCoreOwnedAndContainsNoRetiredProductNames(): void {
		$controls = ReleaseManagementFixture::controls();
		$sections = $controls->filterDocumentationSections( array(), 'https://example.test/docs', 'site' );

		self::assertCount( 1, $sections );
		self::assertStringNotContainsString( 'release-deployments', $sections[0]['id'] );
		self::assertIsCallable( $sections[0]['content'] );
		ob_start();
		( $sections[0]['content'] )();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Published releases', $sections[0]['summary'] );
		self::assertStringNotContainsString( 'Release Deployments', $html );
		self::assertStringNotContainsString( 'ran-booster-release-deployments', $html );
		self::assertStringNotContainsString( 'add-on', strtolower( $html ) );
	}

	public function testNeutralControlSourceContainsNoRetiredRouteQueryAssetOrTextDomain(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Direct local source-conformance read.
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/RAN/Admin/ReleaseManagement/ReleaseManagementControls.php' );
		self::assertIsString( $source );

		foreach ( array(
			'ran_booster_release_deployments',
			'ran-booster-release-deployments',
			"'ran-booster-release-deployments'",
			'Release Deployments add-on',
		) as $retiredName ) {
			self::assertStringNotContainsString( $retiredName, $source );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Direct local fallback-view conformance read.
		$fallback = file_get_contents( dirname( __DIR__, 3 ) . '/views/packages/source-choices.php' );
		self::assertIsString( $fallback );
		self::assertStringContainsString( 'Published releases', $fallback );
		self::assertStringNotContainsString( 'Release Deployments add-on', $fallback );
		self::assertStringNotContainsString( 'Subscriber feature', $fallback );
	}
}
