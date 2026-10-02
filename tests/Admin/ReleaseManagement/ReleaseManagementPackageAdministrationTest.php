<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';
require_once __DIR__ . '/../../Storage/StorageTestEnvironment.php';

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
	public function reset_word_press(): void {
		ReleaseManagementFixture::reset_word_press();
	}

	public function test_branch_settings_render_one_core_owned_form_without_mutating(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Install published releases through WordPress.', $html );
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
		self::assertStringContainsString( '<strong>Installed identity and Update URI</strong>', $html );
		self::assertStringContainsString( '<h4>Ready for releases</h4>', $html );
		self::assertStringContainsString( 'Branch is active; releases are not tracked.', $html );
		self::assertStringNotContainsString( '<strong>Provider</strong>', $html );
		self::assertStringNotContainsString( '<strong>Repository</strong>', $html );
		self::assertSame( array(), $tracking->calls );
	}

	#[DataProvider( 'release_track_disclosure_packages' )]
	public function test_release_track_is_a_closed_native_disclosure_with_the_selected_summary( string $source, string $type, string $channel, string $expected_summary ): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( $source, $type, channel: $channel ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( $source, $type );

		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'/<details id="ran-booster-release-track-settings" class="ran-booster-settings-disclosure ran-booster-release-track-section" data-ran-booster-package-disclosure>/',
			$html
		);
		self::assertStringContainsString( '<h3 class="ran-booster-section__title ran-booster-settings-disclosure__label">Release Track</h3>', $html );
		self::assertMatchesRegularExpression(
			'/<span class="ran-booster-advanced-source-summary__badge" data-ran-booster-release-track-summary>\s*' . $expected_summary . '\s*<\\/span>/',
			$html
		);
		self::assertStringContainsString( '<div class="ran-booster-settings-disclosure__body">', $html );
		self::assertStringContainsString( '<form id="ran-booster-release-track-form"', $html );
		if ( 'branch' === $source ) {
			self::assertStringContainsString( 'form="ran-booster-release-track-form"', $html );
		} else {
			self::assertStringContainsString( 'name="action" value="ran_booster_release_change_channel"', $html );
		}
	}

	/** @return array<string,array{string,string,string,string}> */
	public static function release_track_disclosure_packages(): array {
		return array(
			'plugin stable' => array( 'branch', 'plugin', 'stable', 'Stable' ),
			'theme preview' => array( 'release_asset', 'theme', 'prerelease', 'Preview' ),
		);
	}

	public function test_shared_repository_notice_lists_other_packages_and_keeps_identity_green(): void {
		$controls = $this->conflict_controls();
		$package  = new PackageProjection();
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-release-gate-notice' ) );
		self::assertStringContainsString( '<ul class="ul-disc">', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins&amp;package=nested%2Fnested.php', $html );
		self::assertStringContainsString( 'page=ran-booster-themes&amp;package=companion-theme', $html );
		self::assertStringContainsString( '<h4>Repository shared</h4>', $html );
		self::assertStringNotContainsString( '<h4>Ready for releases</h4>', $html );
		self::assertStringContainsString( 'The installed package identity and Update URI match the configured repository.', $html );
		self::assertMatchesRegularExpression( '/button[^>]+disabled[^>]*>Use releases<\/button>/', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-browser-disabled="true"', $html );
		self::assertStringNotContainsString( 'Published release status needs attention.', $html );
		self::assertStringNotContainsString( 'Why this happened and how to fix it', $html );
		preg_match( '/<ul class="ul-disc">(.*?)<\/ul>/s', $html, $list );
		self::assertStringNotContainsString( 'example%2Fexample.php', $list[1] );
		$choices = $controls->filter_source_choices( array( 'release_asset' => array() ), 'edit', 'plugin', $package, $package->settings_url() );
		self::assertFalse( $choices['release_asset']['disabled'] );
	}

	public function test_unavailable_repository_storage_disables_managed_release_actions_without_rendering_a_conflict_list(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'release_asset', failure_code: 'repository_source_unavailable' )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<h4>Storage unavailable</h4>', $html );
		self::assertStringContainsString( 'Booster could not safely read this package&#039;s repository storage. Check package storage and retry.', $html );
		self::assertStringContainsString( '<fieldset class="ran-booster-release-track-control is-disabled" disabled="disabled"', $html );
		self::assertMatchesRegularExpression( '/button[^>]+disabled[^>]*>Check releases<\/button>/', $html );
		self::assertStringContainsString( '<a class="button disabled" aria-disabled="true" tabindex="-1">Open WordPress updates</a>', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-browser-disabled="true"', $html );
		self::assertStringNotContainsString( 'Conflicting packages', $html );
		self::assertStringNotContainsString( '<ul class="ul-disc">', $html );
	}

	public function test_unavailable_branch_release_source_disables_the_release_transition(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( failure_code: 'release_unavailable' )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'form="ran-booster-release-track-form" disabled="disabled" aria-disabled="true">Use releases</button>', $html );
	}

	public function test_conflict_remains_blocked_when_list_is_unavailable_and_does_not_duplicate_the_result(): void {
		foreach ( array( false, true ) as $unavailable ) {
			$controls = $this->conflict_controls( $unavailable );
			$package  = new PackageProjection();
			$this->set_conflict_result( 'release_repository_conflict' );
			ob_start();
			$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
			$html = (string) ob_get_clean();
			self::assertSame( 1, substr_count( $html, 'Releases require exclusive use of this repository.' ) );
			self::assertMatchesRegularExpression( '/button[^>]+disabled[^>]*>Use releases<\/button>/', $html );
			self::assertStringNotContainsString( 'Why this happened and how to fix it', $html );
			self::assertStringNotContainsString( '<h4>Ready for releases</h4>', $html );
		}
	}

	public function test_conflict_does_not_suppress_an_unrelated_stale_page_notice(): void {
		$controls = $this->conflict_controls();
		$package  = new PackageProjection();
		$this->set_conflict_result( 'source_changed' );
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Package settings changed after this browser page was opened.', $html );
		self::assertStringContainsString( 'Conflicting packages', $html );
	}

	public function test_conflict_result_has_actionable_fallback_without_generic_diagnostics(): void {
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		ob_start();
		$display->render_operation_notice( 'release_repository_conflict', false );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Releases require exclusive use of this repository.', $html );
		self::assertStringContainsString( 'their files can stay installed.', $html );
		self::assertStringContainsString( 'notice-warning', $html );
		self::assertStringNotContainsString( 'Published release status needs attention.', $html );
		self::assertStringNotContainsString( '<details>', $html );
	}

	public function test_conflict_names_are_escaped_and_long_lists_link_to_exact_repository_status(): void {
		$package = new PackageProjection();
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		ob_start();
		$display->render_settings(
			$package,
			ReleaseManagementFixture::status( failure_code: 'release_repository_conflict' ),
			$package->settings_url(),
			repository_conflict: array(
				array(
					'name' => '<img src=x onerror=alert(1)>',
					'url'  => $package->settings_url(),
					'type' => 'plugin',
				),
			)
		);
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		self::assertStringNotContainsString( '<img', $html );

		$controls = $this->conflict_controls( extra_count: 12 );
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $html, '>View all conflicting packages</a>' ) );
		self::assertStringContainsString( 'tab=gh&amp;panel=repositories&amp;repository=101&amp;repository_view=status', $html );
		self::assertStringNotContainsString( 'extra-11', $html );
	}

	private function conflict_controls( bool $unavailable = false, int $extra_count = 0 ): \RAN\Admin\ReleaseManagement\ReleaseManagementControls {
		$database = new \Tests\Support\RepositorySourceGuardDatabase();
		foreach ( array( array( 1, 'example/example.php' ), array( 1, 'nested/nested.php' ), array( 2, 'companion-theme' ) ) as [ $type, $identifier ] ) {
			$database->rows[] = (object) array(
				'type'                   => $type,
				'package'                => $identifier,
				'source'                 => 'branch',
				'provider'               => 'gh',
				'provider_repository_id' => '101',
			);
		}
		for ( $i = 0; $i < $extra_count; ++$i ) {
			$database->rows[] = (object) array(
				'type'                   => 2,
				'package'                => 'extra-' . $i,
				'source'                 => 'branch',
				'provider'               => 'gh',
				'provider_repository_id' => '101',
			);
		}
		$database->last_error = $unavailable ? 'Read unavailable' : '';
		return ReleaseManagementFixture::controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( failure_code: 'release_repository_conflict' ) ),
			source_guard: new \RAN\Storage\RepositorySourceGuard( $database, $this->createStub( \RAN\Storage\Database::class ) )
		);
	}

	private function set_conflict_result( string $code ): void {
		$payload = \RAN\Admin\ReleaseManagement\wp_json_encode( array( $code, false, 'plugin', 'example/example.php', 'stable' ) );
		$_GET    = array(
			'ran_booster_release_result'       => $code,
			'ran_booster_release_success'      => '0',
			'ran_booster_release_type'         => 'plugin',
			'ran_booster_release_package'      => 'example/example.php',
			'ran_booster_release_channel'      => 'stable',
			'ran_booster_release_result_nonce' => 'nonce-for-ran-booster-result-' . hash( 'sha256', $payload ),
		);
	}

	public function test_package_release_readiness_actions_render_inside_the_existing_action_row(): void {
		\RAN\Admin\ReleaseManagement\add_action(
			'ran_booster_admin_package_release_readiness_actions',
			static function ( object $package, object $status ): void {
				unset( $package, $status );
				echo '<a href="https://example.test/repository-releases">Manage release automation</a>';
			},
			20,
			2
		);
		$controls = ReleaseManagementFixture::controls();
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		$actions_start = strpos( $html, '<div class="ran-booster-readiness-actions">' );
		$link_position = strpos( $html, '>Manage release automation</a>' );
		$actions_end   = false === $actions_start ? false : strpos( $html, '</div>', $actions_start );
		self::assertIsInt( $actions_start );
		self::assertIsInt( $link_position );
		self::assertIsInt( $actions_end );
		self::assertTrue( $actions_start < $link_position );
		self::assertTrue( $link_position < $actions_end );
	}

	public function test_ineligible_release_track_is_visibly_bounded_and_explains_why_it_is_disabled(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( eligibility_code: ReleaseTrackingEligibility::MISSING_UPDATE_URI )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'ran-booster-settings-disclosure ran-booster-release-track-section', $html );
		self::assertStringContainsString( 'ran-booster-release-track-control is-disabled" disabled', $html );
		self::assertSame( 2, substr_count( $html, 'class="button ran-booster-release-track-option"' ) );
		self::assertStringContainsString( 'Stable follows final published releases. Preview also includes eligible alpha, beta and release-candidate builds.', $html );
		self::assertStringContainsString( 'ran-booster-release-notices', $html );
		self::assertStringContainsString( '<h4>Not ready for releases</h4>', $html );
		self::assertStringContainsString( 'Published releases require an Update URI matching this repository. Use the header shown below, then recheck eligibility.', $html );
		self::assertStringNotContainsString( 'This package header does not declare an Update URI for its configured repository.', $html );
		self::assertStringContainsString( 'Add this exact header, deploy the corrected package, then check again:', $html );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-managed-release-browser-disabled="true"' ) );
	}

	public function test_managed_release_track_presents_current_and_alternative_as_one_control(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'release_asset', channel: 'stable' )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="button button-primary ran-booster-release-track-option is-current" aria-current="true"', $html );
		self::assertStringContainsString( 'Current release track', $html );
		self::assertStringContainsString( 'name="release_channel" value="prerelease"', $html );
		self::assertStringContainsString( 'aria-label="Switch to Preview releases"', $html );
		self::assertStringContainsString( 'Preview includes published alpha, beta, release-candidate, and stable releases; switching affects future eligibility only, resets Automatic to Manual, and does not install or downgrade.', $html );
		self::assertStringNotContainsString( 'type="hidden" name="release_channel"', $html );
		self::assertStringNotContainsString( 'Use published releases', $html );
	}

	public function test_missing_managed_status_keeps_the_known_release_shell_disabled_without_inventing_versions(): void {
		$tracking                  = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->throw_on_status = true;
		$controls                  = ReleaseManagementFixture::controls( $tracking );
		$package                   = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, '<section class="ran-booster-release-management"' ) );
		self::assertStringContainsString( 'ran-booster-release-track-control is-disabled', $html );
		self::assertStringNotContainsString( 'ran-booster-release-track-option is-current', $html );
		self::assertStringContainsString( '<form id="ran-booster-release-track-form"', $html );
		self::assertStringContainsString( '<fieldset class="ran-booster-release-track-control is-disabled" disabled="disabled"', $html );
		self::assertStringNotContainsString( 'name="action" value="ran_booster_release_change_channel"', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-browser-disabled="true"', $html );
		self::assertMatchesRegularExpression( '/data-ran-booster-release-track-summary>\s*Unknown\s*<\\/span>/', $html );
		self::assertStringContainsString( 'data-ran-booster-release-gate-notice', $html );
		self::assertStringContainsString( 'Published release status is temporarily unavailable. Try again.', $html );
		self::assertStringNotContainsString( 'Published release controls are temporarily unavailable.', $html );
		self::assertStringNotContainsString( 'Version 1.0.0 is installed', $html );
		self::assertStringNotContainsString( 'data-ran-booster-managed-release-list-nonce', $html );
		self::assertStringNotContainsString( 'Use published releases', $html );
	}

	public function test_managed_browser_uses_saved_identity_and_keeps_word_press_as_installer(): void {
		$tracking                       = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->candidate_list       = new RepositoryReleaseCandidateList(
			array(
				new RepositoryReleaseCandidate( '42', 'v1.2.0', '1.2.0', false, '2026-08-20T09:00:00Z', array( 'example.zip' ) ),
				new RepositoryReleaseCandidate( '41', 'v0.9.0', '0.9.0', false, '2026-08-19T09:00:00Z', array( 'example.zip' ) ),
			)
		);
		$tracking->candidate_inspection = new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-plugin', '1.2.0', 'https://example.test/releases/v1.2.0', 'v1.2.0', '1.2.0', 'newer' );
		$controls                       = ReleaseManagementFixture::controls( $tracking );
		$package                        = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'data-ran-booster-managed-release-browser', $html );
		self::assertStringContainsString( 'Review the latest eligible release and the installed version.', $html );
		self::assertStringNotContainsString( 'Downgrades are unavailable because package data migrations may not be reversible.', $html );
		self::assertStringContainsString( '<strong>Installed identity and Update URI</strong>', $html );
		self::assertStringContainsString( '<strong>Release status</strong>', $html );
		self::assertStringNotContainsString( '<strong>Package root</strong>', $html );
		self::assertStringNotContainsString( '<strong>Installation route</strong>', $html );
		self::assertStringContainsString( 'Open WordPress updates', $html );
		self::assertStringNotContainsString( 'Install published plugin', $html );

		$list = $controls->process_managed_browser_request( 'list_candidates', $this->managed_request( 'list_candidates' ) );
		self::assertTrue( $list['successful'] );
		self::assertSame( 'newer', $list['data']['candidates'][0]['version_relationship'] );
		self::assertSame( 'older', $list['data']['candidates'][1]['version_relationship'] );
		self::assertSame( array( 'list_candidates', 'plugin', 'example/example.php', 3, 'stable', 'nonce-for-release-tracking-list_candidates-plugin-example/example.php-3-stable' ), $tracking->calls[0] );

		$inspect_request                = $this->managed_request( 'inspect_candidate' );
		$inspect_request['release_id']  = '42';
		$inspect_request['release_tag'] = 'v1.2.0';
		$inspect                        = $controls->process_managed_browser_request( 'inspect_candidate', $inspect_request );
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

	public function test_managed_candidate_listing_rejects_a_source_change_during_provider_read(): void {
		$tracking                       = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->candidate_list       = new RepositoryReleaseCandidateList(
			array( new RepositoryReleaseCandidate( '42', 'v1.2.0', '1.2.0', false, '2026-08-20T09:00:00Z', array( 'example.zip' ) ) )
		);
		$tracking->after_candidate_list = static function () use ( $tracking ): void {
			$tracking->set_status( ReleaseManagementFixture::status( 'branch' ) );
		};
		$controls                       = ReleaseManagementFixture::controls( $tracking );

		$result = $controls->process_managed_browser_request( 'list_candidates', $this->managed_request( 'list_candidates' ) );

		self::assertFalse( $result['successful'] );
		self::assertSame( 'source_changed', $result['code'] );
		self::assertSame( array(), $result['data'] );
		self::assertSame( 2, $tracking->status_reads );
	}

	public function test_managed_candidate_inspection_recomputes_relationship_from_the_fresh_installed_version(): void {
		$tracking                             = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->candidate_inspection       = new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-plugin', '1.2.0', 'https://example.test/releases/v1.2.0', 'v1.2.0', '1.2.0', 'newer' );
		$tracking->after_candidate_inspection = static function () use ( $tracking ): void {
			$tracking->set_status(
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
		$controls                             = ReleaseManagementFixture::controls( $tracking );
		$request                              = $this->managed_request( 'inspect_candidate' );
		$request['release_id']                = '42';
		$request['release_tag']               = 'v1.2.0';

		$result = $controls->process_managed_browser_request( 'inspect_candidate', $request );

		self::assertTrue( $result['successful'] );
		self::assertSame( '1.2.0', $result['data']['installed_version'] );
		self::assertSame( 'same', $result['data']['version_relationship'] );
		self::assertSame( 1, $tracking->status_reads );
	}

	public function test_managed_browser_remains_available_when_native_updater_status_cannot_be_read(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'release_asset', failure_code: 'release_runtime_unavailable' )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'This server cannot currently run the published-release validator.', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-browser', $html );
		self::assertStringContainsString( 'Refresh releases', $html );
		self::assertStringContainsString( 'aria-disabled="true"', $html );
	}

	public function test_managed_browser_renders_a_disabled_native_core_update_bound_to_the_current_offer(): void {
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
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
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

	public function test_managed_theme_browser_uses_the_native_theme_upgrade_route(): void {
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
		$controls->render_advanced_source_section( 'edit', 'theme', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'action=upgrade-theme', $html );
		self::assertStringContainsString( 'theme=example-theme', $html );
		self::assertStringContainsString( '_wpnonce=nonce-for-upgrade-theme_example-theme', $html );
	}

	public function test_managed_browser_separates_empty_stable_and_preview_tracks_from_read_failures(): void {
		$tracking                 = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->candidate_list = new RepositoryReleaseCandidateList( array() );
		$controls                 = ReleaseManagementFixture::controls( $tracking );

		foreach ( array( 'stable', 'prerelease' ) as $channel ) {
			$list = $controls->process_managed_browser_request( 'list_candidates', $this->managed_request( 'list_candidates', $channel ) );
			self::assertTrue( $list['successful'] );
			self::assertSame( 'no_releases', $list['code'] );
			self::assertSame( $channel, $list['data']['channel'] );
			self::assertSame( array(), $list['data']['candidates'] );
		}
	}

	public function test_managed_browser_preview_preserves_stable_and_prerelease_candidates(): void {
		$tracking                 = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', channel: 'prerelease' ) );
		$tracking->candidate_list = new RepositoryReleaseCandidateList(
			array(
				new RepositoryReleaseCandidate( '42', 'v1.2.0', '1.2.0', false, '2026-08-20T09:00:00Z', array( 'example.zip' ) ),
				new RepositoryReleaseCandidate( '41', 'v1.2.0-rc.1', '1.2.0-rc.1', true, '2026-08-19T09:00:00Z', array( 'example.zip' ) ),
				new RepositoryReleaseCandidate( '40', 'v1.2.0-beta.1', '1.2.0-beta.1', true, '2026-08-18T09:00:00Z', array( 'example.zip' ) ),
			)
		);
		$controls                 = ReleaseManagementFixture::controls( $tracking );

		$list = $controls->process_managed_browser_request( 'list_candidates', $this->managed_request( 'list_candidates', 'prerelease' ) );

		self::assertTrue( $list['successful'] );
		self::assertSame( 'release_candidates_available', $list['code'] );
		self::assertSame( array( 'v1.2.0', 'v1.2.0-rc.1', 'v1.2.0-beta.1' ), array_column( $list['data']['candidates'], 'tag' ) );
		self::assertSame( array( false, true, true ), array_column( $list['data']['candidates'], 'prerelease' ) );
	}

	public function test_managed_browser_preview_retains_an_all_stable_list(): void {
		$tracking                 = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', channel: 'prerelease' ) );
		$tracking->candidate_list = new RepositoryReleaseCandidateList(
			array(
				new RepositoryReleaseCandidate( '42', 'v1.2.0', '1.2.0', false, '2026-08-20T09:00:00Z', array( 'example.zip' ) ),
				new RepositoryReleaseCandidate( '41', 'v1.1.0', '1.1.0', false, '2026-08-19T09:00:00Z', array( 'example.zip' ) ),
			)
		);
		$controls                 = ReleaseManagementFixture::controls( $tracking );

		$list = $controls->process_managed_browser_request( 'list_candidates', $this->managed_request( 'list_candidates', 'prerelease' ) );

		self::assertTrue( $list['successful'] );
		self::assertSame( 'release_candidates_available', $list['code'] );
		self::assertSame( 'prerelease', $list['data']['channel'] );
		self::assertSame( array( 'v1.2.0', 'v1.1.0' ), array_column( $list['data']['candidates'], 'tag' ) );
	}

	/** @return array<string,string> */
	private function managed_request( string $operation, string $channel = 'stable' ): array {
		return array(
			'expected_type'            => 'plugin',
			'expected_identifier'      => 'example/example.php',
			'expected_source_revision' => '3',
			'release_channel'          => $channel,
			'_wpnonce'                 => 'nonce-for-release-tracking-' . $operation . '-plugin-example/example.php-3-' . $channel,
		);
	}

	#[DataProvider( 'package_types' )]
	public function test_release_managed_rows_and_actions_have_plugin_theme_parity( string $type, string $identifier ): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'release_asset', $type, update_available: true )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset', $type );
		$rows     = array(
			$identifier => array(
				'name'   => 'Example',
				'status' => '',
			),
		);

		$presented = $controls->filter_management_rows( $rows, $type, array( $package ) );
		$actions   = $controls->filter_management_actions( array( 'settings' => array( 'label' => 'Settings' ) ), $type, $package );

		self::assertSame( array( $identifier ), $tracking->last_identifiers );
		self::assertNotSame( $rows, $presented );
		self::assertArrayHasKey( 'ran-booster-release:native-update', $actions );
		self::assertSame( 'https://example.test/wp-admin/update-core.php', $actions['ran-booster-release:native-update']['url'] );
		self::assertStringNotContainsString(
			'release_deployments',
			(string) \RAN\Admin\ReleaseManagement\wp_json_encode( array( $presented, $actions ) )
		);
	}

	/** @return iterable<string, array{string,string}> */
	public static function package_types(): iterable {
		yield 'plugin' => array( 'plugin', 'example/example.php' );
		yield 'theme' => array( 'theme', 'example-theme' );
	}

	public function test_branch_and_throwing_status_paths_add_no_management_presentation(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$rows     = array( 'example/example.php' => array( 'name' => 'Example' ) );

		self::assertSame(
			$rows,
			$controls->filter_management_rows( $rows, 'plugin', array( new PackageProjection( 'branch' ) ) )
		);
		self::assertSame( 0, $tracking->status_list_reads );

		$tracking->throw_on_status = true;
		self::assertSame(
			$rows,
			$controls->filter_management_rows( $rows, 'plugin', array( new PackageProjection( 'release_asset' ) ) )
		);
		$actions = $controls->filter_management_actions(
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

	public function test_unsupported_provider_disables_branch_transition_but_preserves_release_recovery(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( eligibility_code: ReleaseTrackingEligibility::UNSUPPORTED_PROVIDER )
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

		$branch  = $controls->filter_source_choices(
			$choices,
			'edit',
			'plugin',
			new PackageProjection(),
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php'
		);
		$release = $controls->filter_source_choices(
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

	public function test_nested_branch_disables_published_release_choice_without_offering_branch_recovery(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( eligibility_code: ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED )
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

		$choice = $controls->filter_source_choices(
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

	#[DataProvider( 'nested_package_types' )]
	public function test_nested_branch_readiness_explains_that_branch_remains_available( string $type ): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status(
				'branch',
				$type,
				ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED
			)
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'branch', $type );

		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		$html                  = (string) ob_get_clean();
		$use_releases_position = strpos( $html, 'Use releases' );
		$checklist_position    = strpos( $html, 'Installed identity and Update URI' );

		self::assertStringContainsString( 'data-ran-booster-release-gate-notice', $html );
		self::assertStringContainsString( 'continue using its configured repository subdirectory with Branch deployments', $html );
		self::assertStringContainsString( '<strong>Installed identity and Update URI</strong>', $html );
		self::assertStringContainsString( 'This package uses a repository subdirectory.', $html );
		self::assertMatchesRegularExpression( '/button[^>]+disabled[^>]*>Use releases<\\/button>/', $html );
		self::assertIsInt( $use_releases_position );
		self::assertIsInt( $checklist_position );
		self::assertTrue( $use_releases_position < $checklist_position );
		self::assertSame( 1, preg_match_all( '/class="[^"]*\\bran-booster-source-transition\\b[^"]*"/', $html ) );
		self::assertStringNotContainsString( 'Use branch', $html );
		self::assertStringNotContainsString( 'Releases currently active.', $html );
		self::assertStringNotContainsString( 'Branch cannot be selected', $html );
		self::assertStringNotContainsString( 'Return to Branch', $html );
		self::assertStringNotContainsString( 'Add this exact header', $html );
		self::assertStringNotContainsString( 'The repository provider does not support published releases.', $html );
		self::assertStringNotContainsString( 'The saved repository needs attention.', $html );
		self::assertStringNotContainsString( '<strong>Update URI</strong>', $html );
		self::assertStringContainsString( 'Recheck eligibility', $html );
		self::assertStringContainsString( 'ran_booster_open_advanced=1', $html );
	}

	/** @return array<string,array{string}> */
	public static function nested_package_types(): array {
		return array(
			'plugin' => array( 'plugin' ),
			'theme'  => array( 'theme' ),
		);
	}

	public function test_missing_update_uri_still_offers_the_exact_header_remediation(): void {
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
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Add this exact header', $html );
		self::assertStringContainsString( 'Update URI: https://github.com/example/example', $html );
	}

	#[DataProvider( 'update_uri_remediation_cases' )]
	public function test_update_uri_gate_appears_once_before_the_action_and_checklist_for_plugins_and_themes( string $type, string $eligibility_code, string $readiness_message ): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'branch', $type, $eligibility_code )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'branch', $type );

		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		$gate_position      = strpos( $html, 'data-ran-booster-release-gate-notice' );
		$component_position = strpos( $html, '<div class="ran-booster-source-transition">' );
		$heading_position   = strpos( $html, 'id="ran-booster-release-management-heading"' );
		$checklist_position = strpos( $html, 'Installed identity and Update URI' );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-release-gate-notice' ) );
		self::assertIsInt( $gate_position );
		self::assertIsInt( $component_position );
		self::assertIsInt( $heading_position );
		self::assertIsInt( $checklist_position );
		self::assertTrue( $gate_position < $component_position );
		self::assertTrue( $component_position < $heading_position );
		self::assertTrue( $heading_position < $checklist_position );
		self::assertStringContainsString( 'Branch currently active.', $html );
		self::assertStringContainsString( 'Published releases require an Update URI matching this repository. Use the header shown below, then recheck eligibility.', $html );
		self::assertStringContainsString( $readiness_message, $html );
		self::assertStringContainsString( 'Update URI: https://github.com/example/example', $html );
	}

	/** @return array<string,array{string,string,string}> */
	public static function update_uri_remediation_cases(): array {
		return array(
			'plugin missing URI'    => array( 'plugin', ReleaseTrackingEligibility::MISSING_UPDATE_URI, 'Missing from the installed package header.' ),
			'plugin mismatched URI' => array( 'plugin', ReleaseTrackingEligibility::MISMATCHED_UPDATE_URI, 'Does not match the configured repository.' ),
			'theme missing URI'     => array( 'theme', ReleaseTrackingEligibility::MISSING_UPDATE_URI, 'Missing from the installed package header.' ),
			'theme mismatched URI'  => array( 'theme', ReleaseTrackingEligibility::MISMATCHED_UPDATE_URI, 'Does not match the configured repository.' ),
		);
	}

	public function test_nested_published_release_renders_only_the_return_to_branch_recovery(): void {
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
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Installation route', $html );
		self::assertStringNotContainsString( 'Native WordPress Updates.', $html );
		self::assertStringContainsString( '<strong>Installed identity and Update URI</strong>', $html );
		self::assertStringContainsString( '<strong>Release status</strong>', $html );
		self::assertStringContainsString( 'Why this happened and how to fix it', $html );
		self::assertStringNotContainsString( 'The repository provider does not support published releases.', $html );
		self::assertStringNotContainsString( 'The saved repository needs attention.', $html );
		$recovery_position  = strpos( $html, 'Use branch' );
		$checklist_position = strpos( $html, 'Installed identity and Update URI' );
		self::assertSame( 1, substr_count( $html, 'Use branch' ) );
		self::assertIsInt( $recovery_position );
		self::assertIsInt( $checklist_position );
		self::assertTrue( $recovery_position < $checklist_position );
		self::assertSame( 1, preg_match_all( '/class="[^"]*\\bran-booster-source-transition\\b[^"]*"/', $html ) );
		self::assertStringContainsString( 'class="button button-primary" aria-disabled="false">Use branch', $html );
	}

	public function test_ordinary_published_release_does_not_offer_branch_recovery(): void {
		$controls = ReleaseManagementFixture::controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) )
		);
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Use branch', $html );
	}

	public function test_published_release_branch_view_retains_return_to_branch_recovery(): void {
		$controls = ReleaseManagementFixture::controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) )
		);
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Releases currently active.', $html );
		self::assertStringContainsString( 'Use branch', $html );
	}

	public function test_eligible_branch_transition_appears_at_the_top_and_submits_the_track_form(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		$heading_position    = strpos( $html, 'id="ran-booster-release-management-heading"' );
		$action_position     = strpos( $html, 'form="ran-booster-release-track-form"' );
		$track_form_position = strpos( $html, '<form id="ran-booster-release-track-form"' );
		$track_position      = strpos( $html, 'data-ran-booster-release-channel-control' );
		$checklist_position  = strpos( $html, 'Installed identity and Update URI' );
		self::assertIsInt( $heading_position );
		self::assertIsInt( $action_position );
		self::assertIsInt( $track_form_position );
		self::assertIsInt( $track_position );
		self::assertIsInt( $checklist_position );
		self::assertTrue( $action_position < $heading_position );
		self::assertTrue( $heading_position < $checklist_position );
		self::assertTrue( $action_position < $track_form_position );
		self::assertTrue( $track_form_position < $track_position );
		self::assertStringContainsString( '<div class="ran-booster-source-transition">', $html );
		self::assertStringContainsString( 'Branch currently active.', $html );
		self::assertStringContainsString( 'Use releases', $html );
		self::assertStringContainsString( 'data-ran-booster-source-transition', $html );
		self::assertStringNotContainsString( 'Uses saved package settings. Save any edits before switching.', $html );
		self::assertStringNotContainsString( 'Automatic resets to Manual.', $html );
	}

	public function test_automatic_branch_transition_shows_its_warning_only_at_the_top(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( deployment_policy: 'automatic' ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		$warning_position   = strpos( $html, 'Switching resets Automatic to Manual.' );
		$checklist_position = strpos( $html, 'Installed identity and Update URI' );
		self::assertIsInt( $warning_position );
		self::assertIsInt( $checklist_position );
		self::assertTrue( $warning_position < $checklist_position );
		self::assertStringNotContainsString( 'Booster will freshly validate a matching release', $html );
	}

	public function test_active_branch_pane_does_not_render_an_irrelevant_return_action(): void {
		$controls = ReleaseManagementFixture::controls();
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertSame( '', $html );
	}

	public function test_missing_transition_nonce_disables_the_published_release_action_at_the_top(): void {
		$tracking                 = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$tracking->throw_on_nonce = true;
		$controls                 = ReleaseManagementFixture::controls( $tracking );
		$package                  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Use releases', $html );
		self::assertStringContainsString( 'form="ran-booster-release-track-form" disabled="disabled" aria-disabled="true"', $html );
		self::assertStringContainsString( 'Published releases cannot be selected because source transition controls are temporarily unavailable.', $html );
		self::assertStringContainsString( '<fieldset class="ran-booster-release-track-control is-disabled" disabled="disabled"', $html );
	}

	public function test_missing_branch_status_renders_the_disabled_shell_and_single_top_gate_notice(): void {
		$tracking                  = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$tracking->throw_on_status = true;
		$controls                  = ReleaseManagementFixture::controls( $tracking );
		$package                   = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-release-gate-notice' ) );
		self::assertStringContainsString( 'Published release status is temporarily unavailable. Try again.', $html );
		self::assertStringContainsString( '<fieldset class="ran-booster-release-track-control is-disabled" disabled="disabled"', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-browser-disabled="true"', $html );
		self::assertStringNotContainsString( 'Published release controls are temporarily unavailable.', $html );
	}

	public function test_every_mutation_forwards_exact_authority_revision_channel_and_nonce(): void {
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

			$url = $controls->process_admin_post_request( $operation, $request );
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

	public function test_package_settings_return_destination_uses_network_admin_on_multisite(): void {
		$GLOBALS['ran_booster_release_management_test_multisite'] = true;
		$controls                   = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) );
		$request                    = $this->request( 'enable' );
		$request['release_channel'] = 'stable';

		$url = $controls->process_admin_post_request( 'enable', $request );

		self::assertStringStartsWith( 'https://example.test/wp-admin/network/admin.php?', $url );
		self::assertStringContainsString( 'page=ran-booster-plugins', $url );
		self::assertStringContainsString( 'package=example%2Fexample.php', $url );
	}

	public function test_change_channel_uses_an_origin_relative_hx_location_and_keeps_native_redirect_absolute(): void {
		$request                    = $this->request( 'change_channel' );
		$request['release_channel'] = 'prerelease';

		$_POST                      = $request;
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		try {
			ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) )->handle_change_channel();
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
			ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) )->handle_change_channel();
			self::fail( 'Expected the native redirect to stop execution.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'native-redirect', $exception->getMessage() );
		}

		self::assertStringStartsWith( 'https://example.test/wp-admin/', (string) $GLOBALS['ran_booster_release_management_test_redirect'] );
	}

	public function test_invalid_nonce_revision_and_capability_fail_before_mutation(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );

		$invalid_nonce             = $this->request( 'enable' );
		$invalid_nonce['_wpnonce'] = 'wrong';
		$controls->process_admin_post_request( 'enable', $invalid_nonce );

		$invalid_revision                             = $this->request( 'enable' );
		$invalid_revision['expected_source_revision'] = '0';
		$controls->process_admin_post_request( 'enable', $invalid_revision );

		$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'update_plugins' );
		$controls->process_admin_post_request( 'enable', $this->request( 'enable' ) );

		self::assertSame( array(), $tracking->calls );
	}

	public function test_signed_prg_notice_reads_fresh_status_without_repeating_mutation(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$request  = $this->request( 'refresh' );
		$url      = $controls->process_admin_post_request( 'refresh', $request );
		$query    = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsing the already signed local PRG fixture.
		parse_str( $query, $_GET );

		ob_start();
		$controls->render_operation_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice-success', $html );
		self::assertStringContainsString( 'Version 1.0.0 is installed; no newer eligible release was found.', $html );
		self::assertStringNotContainsString( 'release_deployments', $html );
		self::assertSame(
			array( array( 'refresh', 'plugin', 'example/example.php', 3, $this->nonce( 'refresh' ) ) ),
			$tracking->calls
		);
		self::assertSame( 2, $tracking->status_reads );
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
