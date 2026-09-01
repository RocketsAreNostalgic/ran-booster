<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\RepositoryProvider\RepositoryReleaseCandidate;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use Tests\Admin\ReleaseManagement\Support\PackageProjection;
use Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;

final class ReleaseManagementPackageAdministrationTest extends TestCase {
	#[Before]
	public function resetWordPress(): void {
		ReleaseManagementFixture::resetWordPress();
	}

	public function testBranchSettingsRenderOneCoreOwnedFormWithoutMutating(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Track verified release assets and install them through WordPress.', $html );
		self::assertStringContainsString( 'action="https://example.test/wp-admin/admin-post.php"', $html );
		self::assertStringContainsString( 'name="action" value="ran_booster_release_enable"', $html );
		self::assertStringContainsString( 'name="expected_type" value="plugin"', $html );
		self::assertStringContainsString( 'name="expected_identifier" value="example/example.php"', $html );
		self::assertStringContainsString( 'name="expected_source_revision" value="3"', $html );
		self::assertStringContainsString(
			'name="_wpnonce" value="nonce-for-release-tracking-enable-plugin-example/example.php-3"',
			$html
		);
		self::assertStringContainsString( 'name="release_channel" value="stable"', $html );
		self::assertStringContainsString( 'name="release_channel" value="prerelease"', $html );
		self::assertStringNotContainsString( 'ran_booster_release_deployments', $html );
		self::assertSame( array(), $tracking->calls );
	}

	public function testIneligibleReleaseTrackIsVisiblyBoundedAndExplainsWhyItIsDisabled(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( eligibilityCode: ReleaseTrackingEligibility::MISSING_UPDATE_URI )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'ran-booster-settings-section ran-booster-release-track-section', $html );
		self::assertStringContainsString( 'ran-booster-release-track-control is-disabled" disabled', $html );
		self::assertSame( 2, substr_count( $html, 'class="button ran-booster-release-track-option"' ) );
		self::assertStringContainsString( 'Stable follows final published releases. Preview also includes prereleases.', $html );
		self::assertStringContainsString( 'notice notice-warning inline ran-booster-release-track-notice', $html );
		self::assertStringContainsString( 'Complete the eligibility requirements above before choosing a release track.', $html );
	}

	public function testManagedReleaseTrackPresentsCurrentAndAlternativeAsOneControl(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'release_asset', channel: 'stable' )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="button button-primary ran-booster-release-track-option is-current" aria-current="true"', $html );
		self::assertStringContainsString( 'Current release track', $html );
		self::assertStringContainsString( 'name="release_channel" value="prerelease"', $html );
		self::assertStringContainsString( 'aria-label="Switch to Preview releases"', $html );
		self::assertStringContainsString( 'Preview includes published alpha, beta, release-candidate, and stable releases; switching affects future eligibility only, resets Automatic to Manual, and does not install or downgrade.', $html );
		self::assertStringNotContainsString( 'type="hidden" name="release_channel"', $html );
	}

	public function testManagedBrowserUsesSavedIdentityAndKeepsWordPressAsInstaller(): void {
		$tracking                      = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->candidateList       = new RepositoryReleaseCandidateList(
			array(
				new RepositoryReleaseCandidate( '42', 'v1.2.0', '1.2.0', false, '2026-08-20T09:00:00Z', array( 'example.zip' ) ),
				new RepositoryReleaseCandidate( '41', 'v0.9.0', '0.9.0', false, '2026-08-19T09:00:00Z', array( 'example.zip' ) ),
			)
		);
		$tracking->candidateInspection = new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-plugin', '1.2.0', 'https://example.test/releases/v1.2.0', 'v1.2.0', '1.2.0', 'newer' );
		$controls                      = ReleaseManagementFixture::controls( $tracking );
		$package                       = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'data-ran-booster-managed-release-browser', $html );
		self::assertStringNotContainsString( 'Downgrades are unavailable because package data migrations may not be reversible.', $html );
		self::assertStringContainsString( 'Open WordPress updates', $html );
		self::assertStringNotContainsString( 'Install published plugin', $html );

		$list = $controls->processManagedBrowserRequest( 'list_candidates', $this->managedRequest( 'list_candidates' ) );
		self::assertTrue( $list['successful'] );
		self::assertSame( 'newer', $list['data']['candidates'][0]['version_relationship'] );
		self::assertSame( 'older', $list['data']['candidates'][1]['version_relationship'] );
		self::assertSame( array( 'list_candidates', 'plugin', 'example/example.php', 3, 'stable', 'nonce-for-release-tracking-list_candidates-plugin-example/example.php-3-stable' ), $tracking->calls[0] );

		$inspectRequest                = $this->managedRequest( 'inspect_candidate' );
		$inspectRequest['release_id']  = '42';
		$inspectRequest['release_tag'] = 'v1.2.0';
		$inspect                       = $controls->processManagedBrowserRequest( 'inspect_candidate', $inspectRequest );
		self::assertTrue( $inspect['successful'] );
		self::assertSame( '1.0.0', $inspect['data']['installed_version'] );
		self::assertSame(
			array(
				'available'  => false,
				'release_id' => '',
				'version'    => '1.1.0',
			),
			$inspect['data']['native_offer']
		);
		self::assertSame( array( 'inspect_candidate', 'plugin', 'example/example.php', 3, '42', 'v1.2.0', 'stable', 'nonce-for-release-tracking-inspect_candidate-plugin-example/example.php-3-stable' ), $tracking->calls[1] );
	}

	public function testManagedCandidateListingRejectsASourceChangeDuringProviderRead(): void {
		$tracking                     = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->candidateList      = new RepositoryReleaseCandidateList(
			array( new RepositoryReleaseCandidate( '42', 'v1.2.0', '1.2.0', false, '2026-08-20T09:00:00Z', array( 'example.zip' ) ) )
		);
		$tracking->afterCandidateList = static function () use ( $tracking ): void {
			$tracking->setStatus( ReleaseManagementFixture::status( 'branch' ) );
		};
		$controls                     = ReleaseManagementFixture::controls( $tracking );

		$result = $controls->processManagedBrowserRequest( 'list_candidates', $this->managedRequest( 'list_candidates' ) );

		self::assertFalse( $result['successful'] );
		self::assertSame( 'source_changed', $result['code'] );
		self::assertSame( array(), $result['data'] );
		self::assertSame( 2, $tracking->statusReads );
	}

	public function testManagedCandidateInspectionRecomputesRelationshipFromTheFreshInstalledVersion(): void {
		$tracking                           = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->candidateInspection      = new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-plugin', '1.2.0', 'https://example.test/releases/v1.2.0', 'v1.2.0', '1.2.0', 'newer' );
		$tracking->afterCandidateInspection = static function () use ( $tracking ): void {
			$tracking->setStatus(
				new ReleaseTrackingStatus(
					'plugin',
					'example/example.php',
					'release_asset',
					3,
					'101',
					'manual',
					new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE, 'https://github.com/example/example', 'example-plugin' ),
					new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-plugin', '1.2.0', 'https://example.test/releases/v1.2.0' ),
					'example-plugin',
					'1.2.0',
					'1.2.0'
				)
			);
		};
		$controls                           = ReleaseManagementFixture::controls( $tracking );
		$request                            = $this->managedRequest( 'inspect_candidate' );
		$request['release_id']              = '42';
		$request['release_tag']             = 'v1.2.0';

		$result = $controls->processManagedBrowserRequest( 'inspect_candidate', $request );

		self::assertTrue( $result['successful'] );
		self::assertSame( '1.2.0', $result['data']['installed_version'] );
		self::assertSame( 'same', $result['data']['version_relationship'] );
		self::assertSame( 1, $tracking->statusReads );
	}

	public function testManagedBrowserRemainsAvailableWhenNativeUpdaterStatusCannotBeRead(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'release_asset', failureCode: 'release_runtime_unavailable' )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'This server cannot currently run the published-release validator.', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-browser', $html );
		self::assertStringContainsString( 'Refresh releases', $html );
		self::assertStringContainsString( 'aria-disabled="true"', $html );
	}

	public function testManagedBrowserRendersADisabledNativeCoreUpdateBoundToTheCurrentOffer(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			new \RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus(
				'plugin',
				'example/example.php',
				'release_asset',
				3,
				'101',
				'manual',
				new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE, 'https://github.com/example/example', 'example-plugin' ),
				new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-plugin', '1.2.0', 'https://example.test/releases/v1.2.0' ),
				'example-plugin',
				'1.0.0',
				'1.2.0',
				true,
				'',
				'',
				'',
				'stable'
			)
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Install now</a>', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-native-update', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-native-update-version="1.2.0"', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-native-update-release-id=""', $html );
		self::assertStringContainsString( 'button button-primary disabled ran-booster-managed-release-native-update', $html );
		self::assertStringContainsString( 'aria-disabled="true"', $html );
		self::assertStringContainsString( 'action=upgrade-plugin', $html );
		self::assertStringContainsString( 'plugin=example%2Fexample.php', $html );
		self::assertStringContainsString( '_wpnonce=nonce-for-upgrade-plugin_example%2Fexample.php', $html );
	}

	public function testManagedThemeBrowserUsesTheNativeThemeUpgradeRoute(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			new \RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus(
				'theme',
				'example-theme',
				'release_asset',
				3,
				'101',
				'manual',
				new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE, 'https://github.com/example/example', 'example-theme' ),
				new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-theme', '1.2.0', 'https://example.test/releases/v1.2.0' ),
				'example-theme',
				'1.0.0',
				'1.2.0',
				true,
				'',
				'',
				'',
				'stable'
			)
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset', 'theme' );

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'theme', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'action=upgrade-theme', $html );
		self::assertStringContainsString( 'theme=example-theme', $html );
		self::assertStringContainsString( '_wpnonce=nonce-for-upgrade-theme_example-theme', $html );
	}

	public function testManagedBrowserSeparatesEmptyStableAndPreviewTracksFromReadFailures(): void {
		$tracking                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->candidateList = new RepositoryReleaseCandidateList( array() );
		$controls                = ReleaseManagementFixture::controls( $tracking );

		foreach ( array( 'stable', 'prerelease' ) as $channel ) {
			$list = $controls->processManagedBrowserRequest( 'list_candidates', $this->managedRequest( 'list_candidates', $channel ) );
			self::assertTrue( $list['successful'] );
			self::assertSame( 'no_releases', $list['code'] );
			self::assertSame( $channel, $list['data']['channel'] );
			self::assertSame( array(), $list['data']['candidates'] );
		}
	}

	public function testManagedBrowserPreviewPreservesStableAndPrereleaseCandidates(): void {
		$tracking                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', channel: 'prerelease' ) );
		$tracking->candidateList = new RepositoryReleaseCandidateList(
			array(
				new RepositoryReleaseCandidate( '42', 'v1.2.0', '1.2.0', false, '2026-08-20T09:00:00Z', array( 'example.zip' ) ),
				new RepositoryReleaseCandidate( '41', 'v1.2.0-rc.1', '1.2.0-rc.1', true, '2026-08-19T09:00:00Z', array( 'example.zip' ) ),
				new RepositoryReleaseCandidate( '40', 'v1.2.0-beta.1', '1.2.0-beta.1', true, '2026-08-18T09:00:00Z', array( 'example.zip' ) ),
			)
		);
		$controls                = ReleaseManagementFixture::controls( $tracking );

		$list = $controls->processManagedBrowserRequest( 'list_candidates', $this->managedRequest( 'list_candidates', 'prerelease' ) );

		self::assertTrue( $list['successful'] );
		self::assertSame( 'release_candidates_available', $list['code'] );
		self::assertSame( array( 'v1.2.0', 'v1.2.0-rc.1', 'v1.2.0-beta.1' ), array_column( $list['data']['candidates'], 'tag' ) );
		self::assertSame( array( false, true, true ), array_column( $list['data']['candidates'], 'prerelease' ) );
	}

	public function testManagedBrowserPreviewRetainsAnAllStableList(): void {
		$tracking                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', channel: 'prerelease' ) );
		$tracking->candidateList = new RepositoryReleaseCandidateList(
			array(
				new RepositoryReleaseCandidate( '42', 'v1.2.0', '1.2.0', false, '2026-08-20T09:00:00Z', array( 'example.zip' ) ),
				new RepositoryReleaseCandidate( '41', 'v1.1.0', '1.1.0', false, '2026-08-19T09:00:00Z', array( 'example.zip' ) ),
			)
		);
		$controls                = ReleaseManagementFixture::controls( $tracking );

		$list = $controls->processManagedBrowserRequest( 'list_candidates', $this->managedRequest( 'list_candidates', 'prerelease' ) );

		self::assertTrue( $list['successful'] );
		self::assertSame( 'release_candidates_available', $list['code'] );
		self::assertSame( 'prerelease', $list['data']['channel'] );
		self::assertSame( array( 'v1.2.0', 'v1.1.0' ), array_column( $list['data']['candidates'], 'tag' ) );
	}

	/** @return array<string,string> */
	private function managedRequest( string $operation, string $channel = 'stable' ): array {
		return array(
			'expected_type'            => 'plugin',
			'expected_identifier'      => 'example/example.php',
			'expected_source_revision' => '3',
			'release_channel'          => $channel,
			'_wpnonce'                 => 'nonce-for-release-tracking-' . $operation . '-plugin-example/example.php-3-' . $channel,
		);
	}

	#[DataProvider( 'packageTypes' )]
	public function testReleaseManagedRowsAndActionsHavePluginThemeParity( string $type, string $identifier ): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'release_asset', $type, updateAvailable: true )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset', $type );
		$rows     = array(
			$identifier => array(
				'name'   => 'Example',
				'status' => '',
			),
		);

		$presented = $controls->filterManagementRows( $rows, $type, array( $package ) );
		$actions   = $controls->filterManagementActions( array( 'settings' => array( 'label' => 'Settings' ) ), $type, $package );

		self::assertSame( array( $identifier ), $tracking->lastIdentifiers );
		self::assertNotSame( $rows, $presented );
		self::assertArrayHasKey( 'ran-booster-release:native-update', $actions );
		self::assertSame( 'https://example.test/wp-admin/update-core.php', $actions['ran-booster-release:native-update']['url'] );
		self::assertStringNotContainsString(
			'release_deployments',
			(string) \RAN\Admin\ReleaseManagement\wp_json_encode( array( $presented, $actions ) )
		);
	}

	/** @return iterable<string, array{string,string}> */
	public static function packageTypes(): iterable {
		yield 'plugin' => array( 'plugin', 'example/example.php' );
		yield 'theme' => array( 'theme', 'example-theme' );
	}

	public function testBranchAndThrowingStatusPathsAddNoManagementPresentation(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$rows     = array( 'example/example.php' => array( 'name' => 'Example' ) );

		self::assertSame(
			$rows,
			$controls->filterManagementRows( $rows, 'plugin', array( new PackageProjection( 'branch' ) ) )
		);
		self::assertSame( 0, $tracking->statusListReads );

		$tracking->throwOnStatus = true;
		self::assertSame(
			$rows,
			$controls->filterManagementRows( $rows, 'plugin', array( new PackageProjection( 'release_asset' ) ) )
		);
		$actions = $controls->filterManagementActions(
			array( 'settings' => array( 'label' => 'Settings' ) ),
			'plugin',
			new PackageProjection( 'release_asset' )
		);
		self::assertSame( array( 'label' => 'Settings' ), $actions['settings'] );
		self::assertSame( 'link', $actions['ran-booster-release:refresh']['type'] );
		self::assertSame( '', $actions['ran-booster-release:refresh']['url'] );
		self::assertTrue( $actions['ran-booster-release:refresh']['disabled'] );
		self::assertArrayNotHasKey( 'hidden', $actions['ran-booster-release:refresh'] );
		self::assertSame( array(), $tracking->calls );
	}

	public function testUnsupportedProviderDisablesBranchTransitionButPreservesReleaseRecovery(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( eligibilityCode: ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$choices  = array(
			'release_asset' => array(
				'heading'     => 'Unavailable',
				'description' => 'Unavailable.',
				'meta'        => 'Unavailable',
				'url'         => '',
				'disabled'    => false,
			),
		);

		$branch  = $controls->filterSourceChoices(
			$choices,
			'edit',
			'plugin',
			new PackageProjection(),
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php'
		);
		$release = $controls->filterSourceChoices(
			$choices,
			'edit',
			'plugin',
			new PackageProjection( 'release_asset' ),
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php'
		);

		self::assertTrue( $branch['release_asset']['disabled'] );
		self::assertStringContainsString( 'not available', $branch['release_asset']['description'] );
		self::assertFalse( $release['release_asset']['disabled'] );
	}

	public function testNestedBranchDisablesPublishedReleaseChoiceWithoutOfferingBranchRecovery(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( eligibilityCode: ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$choices  = array(
			'release_asset' => array(
				'heading'     => 'Published releases',
				'description' => '',
				'meta'        => '',
				'url'         => '',
				'disabled'    => false,
			),
		);

		$choice = $controls->filterSourceChoices(
			$choices,
			'edit',
			'plugin',
			new PackageProjection(),
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php'
		);

		self::assertTrue( $choice['release_asset']['disabled'] );
		self::assertStringContainsString( 'repository root', $choice['release_asset']['description'] );
		self::assertStringContainsString( 'continue using Branch deployments', $choice['release_asset']['description'] );
		self::assertStringNotContainsString( 'Return to Branch', $choice['release_asset']['description'] );
	}

	public function testNestedBranchReadinessExplainsThatBranchRemainsAvailable(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status(
				'branch',
				'plugin',
				ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED
			)
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Current source remains Branch.', $html );
		self::assertStringContainsString( 'continue using its configured repository subdirectory with Branch deployments', $html );
		self::assertStringContainsString( '<strong>Package location</strong>', $html );
		self::assertStringContainsString( 'This package uses a repository subdirectory.', $html );
		self::assertStringNotContainsString( 'Return to Branch', $html );
		self::assertStringNotContainsString( 'Add this exact header', $html );
		self::assertStringNotContainsString( 'The repository provider does not support published releases.', $html );
		self::assertStringNotContainsString( 'The saved repository needs attention.', $html );
		self::assertStringNotContainsString( '<strong>Update URI</strong>', $html );
		self::assertStringContainsString( 'Recheck eligibility', $html );
		self::assertStringContainsString( 'ran_booster_open_advanced=1', $html );
	}

	public function testMissingUpdateUriStillOffersTheExactHeaderRemediation(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status(
				'branch',
				'plugin',
				ReleaseTrackingEligibility::MISSING_UPDATE_URI
			)
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Add this exact header', $html );
		self::assertStringContainsString( 'Update URI: https://github.com/example/example', $html );
	}

	public function testNestedPublishedReleaseRendersOnlyTheReturnToBranchRecovery(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status(
				'release_asset',
				'plugin',
				ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED,
				false,
				'stable',
				'subdirectory_not_supported'
			)
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Installation route', $html );
		self::assertStringNotContainsString( 'Native WordPress Updates.', $html );
		self::assertStringContainsString( '<strong>Package location</strong>', $html );
		self::assertStringNotContainsString( 'The repository provider does not support published releases.', $html );
		self::assertStringNotContainsString( 'The saved repository needs attention.', $html );
		self::assertStringContainsString( 'Return to branch deployments', $html );
		self::assertStringContainsString( 'class="button button-primary">Return to branch deployments', $html );
	}

	public function testEligibleBranchTransitionAppearsAfterReleaseTrackControls(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->renderAdvancedSourceSection( 'edit', 'plugin', 'release_asset', $package, $package->settingsUrl() );
		$html = (string) ob_get_clean();

		$trackPosition   = strpos( $html, 'data-ran-booster-release-channel-control' );
		$warningPosition = strpos( $html, 'Booster will freshly validate a matching release' );
		$actionPosition  = strpos( $html, 'Validate and switch source' );
		self::assertIsInt( $trackPosition );
		self::assertIsInt( $warningPosition );
		self::assertIsInt( $actionPosition );
		self::assertTrue( $trackPosition < $warningPosition );
		self::assertTrue( $warningPosition < $actionPosition );
		self::assertStringNotContainsString( 'Keep branch source', $html );
	}

	public function testEveryMutationForwardsExactAuthorityRevisionChannelAndNonce(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );

		foreach ( array( 'enable', 'change_channel', 'refresh', 'return_to_branch' ) as $operation ) {
			$request = $this->request( $operation );
			if ( 'refresh' === $operation ) {
				$request['return_to_settings'] = '1';
			}
			if ( 'enable' === $operation ) {
				$request['release_channel'] = 'stable';
			}
			if ( 'change_channel' === $operation ) {
				$request['release_channel'] = 'prerelease';
			}

			$url = $controls->processAdminPostRequest( $operation, $request );
			self::assertStringContainsString( 'ran_booster_release_result=', $url, $operation );
			self::assertStringContainsString( 'ran_booster_release_result_nonce=', $url, $operation );
			self::assertStringContainsString( 'ran_booster_open_advanced=1', $url, $operation );
			self::assertStringNotContainsString( 'release_deployments', $url, $operation );
		}

		self::assertSame(
			array(
				array( 'enable', 'plugin', 'example/example.php', 3, 'stable', $this->nonce( 'enable' ) ),
				array( 'change_channel', 'plugin', 'example/example.php', 3, 'prerelease', $this->nonce( 'change_channel' ) ),
				array( 'refresh', 'plugin', 'example/example.php', 3, $this->nonce( 'refresh' ) ),
				array( 'return_to_branch', 'plugin', 'example/example.php', 3, $this->nonce( 'return_to_branch' ) ),
			),
			$tracking->calls
		);
	}

	public function testChangeChannelUsesAnOriginRelativeHxLocationAndKeepsNativeRedirectAbsolute(): void {
		$request                    = $this->request( 'change_channel' );
		$request['release_channel'] = 'prerelease';

		$_POST                      = $request;
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		try {
			ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) )->handleChangeChannel();
			self::fail( 'Expected the HX response to stop execution.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'hx-redirect', $exception->getMessage() );
		}

		$header   = (string) $GLOBALS['ran_booster_release_management_test_header'];
		$location = json_decode( substr( $header, strlen( 'HX-Location: ' ) ), true );
		self::assertIsArray( $location );
		self::assertStringStartsWith( '/wp-admin/', $location['path'] );
		self::assertStringNotContainsString( 'https://example.test', $location['path'] );

		unset( $_SERVER['HTTP_HX_REQUEST'] );
		$_POST = $request;
		try {
			ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) )->handleChangeChannel();
			self::fail( 'Expected the native redirect to stop execution.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'native-redirect', $exception->getMessage() );
		}

		self::assertStringStartsWith( 'https://example.test/wp-admin/', (string) $GLOBALS['ran_booster_release_management_test_redirect'] );
	}

	public function testInvalidNonceRevisionAndCapabilityFailBeforeMutation(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );

		$invalidNonce             = $this->request( 'enable' );
		$invalidNonce['_wpnonce'] = 'wrong';
		$controls->processAdminPostRequest( 'enable', $invalidNonce );

		$invalidRevision                             = $this->request( 'enable' );
		$invalidRevision['expected_source_revision'] = '0';
		$controls->processAdminPostRequest( 'enable', $invalidRevision );

		$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'update_plugins' );
		$controls->processAdminPostRequest( 'enable', $this->request( 'enable' ) );

		self::assertSame( array(), $tracking->calls );
	}

	public function testSignedPrgNoticeReadsFreshStatusWithoutRepeatingMutation(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$request  = $this->request( 'refresh' );
		$url      = $controls->processAdminPostRequest( 'refresh', $request );
		$query    = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsing the already signed local PRG fixture.
		parse_str( $query, $_GET );

		ob_start();
		$controls->renderOperationNotice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-success', $html );
		self::assertStringContainsString( 'Version 1.0.0 is installed; no newer eligible release was found.', $html );
		self::assertStringNotContainsString( 'release_deployments', $html );
		self::assertSame(
			array( array( 'refresh', 'plugin', 'example/example.php', 3, $this->nonce( 'refresh' ) ) ),
			$tracking->calls
		);
		self::assertSame( 2, $tracking->statusReads );
	}

	/** @return array<string, string> */
	private function request( string $operation ): array {
		return array(
			'expected_type'            => 'plugin',
			'expected_identifier'      => 'example/example.php',
			'expected_source_revision' => '3',
			'_wpnonce'                 => $this->nonce( $operation ),
		);
	}

	private function nonce( string $operation ): string {
		return 'nonce-for-release-tracking-' . $operation . '-plugin-example/example.php-3';
	}
}
