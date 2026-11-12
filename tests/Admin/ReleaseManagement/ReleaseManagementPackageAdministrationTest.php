<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

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
use RAN\Tests\Admin\ReleaseManagement\Support\PackageProjection;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;

final class ReleaseManagementPackageAdministrationTest extends TestCase {
	public function test_unavailable_readiness_keeps_native_text_and_explicit_badge_intent(): void {
		$package = new PackageProjection();
		ob_start();
		( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->render_settings( $package, null, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-high-intent" data-ran-booster-badge data-ran-booster-badge-intent="high">Not ready</span>', $html );
		self::assertStringContainsString( 'Published release status is temporarily unavailable. Try again.', $html );
		self::assertStringContainsString( 'role="tooltip" hidden>Available once release status is available.</span>', $html );
	}

	#[Before]
	public function reset_word_press(): void {
		ReleaseManagementFixture::reset_word_press();
	}

	public function test_unmarked_display_projection_is_rejected_before_reading_its_values(): void {
		$package = new class() {
			public int $reads = 0;
			public function type(): string {
				++$this->reads;
				return 'plugin';
			}
			public function identifier(): string {
				return 'example/example.php';
			}
			public function display_name(): string {
				return 'Example';
			}
			public function source(): string {
				return 'branch';
			}
			public function source_revision(): int {
				return 3;
			}
			public function settings_url(): string {
				return 'https://example.test/wp-admin/admin.php';
			}
		};
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		ob_start();
		$display->render_settings( $package, null, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertSame( 0, $package->reads );
		self::assertSame( '', $html );
	}

	public function test_projection_protocol_does_not_replace_source_identity_validation(): void {
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		foreach ( array(
			new PackageProjection( 'unknown' ),
			new PackageProjection( 'branch', 'unknown' ),
			new PackageProjection( 'branch', 'plugin', 0 ),
		) as $package ) {
			ob_start();
			$display->render_settings( $package, null, $package->settings_url() );
			$html = (string) ob_get_clean();
			self::assertSame( '', $html );
		}
	}

	public function test_branch_settings_render_one_core_owned_form_without_mutating(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Install published releases through WordPress.', $html );
		self::assertStringNotContainsString( 'Install published releases.', $html );
		self::assertStringNotContainsString( 'Deploy published releases.', $html, 'The card sub-line text is not repeated in the pane.' );
		self::assertSame( 1, substr_count( $html, 'ran-booster-package-source-pane__description' ), 'The release pane carries one strapline.' );
		self::assertStringContainsString( '<p class="ran-booster-package-source-pane__description">Deploy when new packages are released.</p>', $html );
		self::assertTrue( strpos( $html, 'ran-booster-package-source-pane__description' ) < strpos( $html, 'ran-booster-release-management' ), 'The strapline leads the pane.' );
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
		self::assertStringContainsString( 'id="ran-booster-release-readiness-status"><span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-stable-intent" data-ran-booster-badge data-ran-booster-badge-intent="stable">Ready</span></span>', $html );
		self::assertStringNotContainsString( 'Ready for releases', $html );
		self::assertSame( 1, substr_count( $html, '<h3 id="ran-booster-release-management-heading">Release readiness</h3>' ), 'Release readiness is the title of the readiness table.' );
		self::assertSame( 1, substr_count( $html, 'Release readiness' ) );
		self::assertSame( 1, preg_match( '/<header class="ran-booster-package-source-pane__header">\s*<div class="ran-booster-source-switch-bar[^"]*"[^>]*>.*?<\/div>\s*<\/header>/s', $html ), 'Without notices the pane header holds only the source switch bar.' );
		self::assertStringNotContainsString( '<h4 id="ran-booster-release-readiness-status"', $html );
		self::assertMatchesRegularExpression( '~<div class="ran-booster-readiness-panel__top">\s*<div>\s*<h3 id="ran-booster-release-management-heading">Release readiness</h3>\s*</div>\s*<span id="ran-booster-release-readiness-status">~', $html );
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
			'/<details id="ran-booster-release-track-settings" class="ran-booster-settings-disclosure ran-booster-release-track-section ran-booster-disclosure" data-ran-booster-ui-disclosure-fallback data-ran-booster-package-disclosure>/',
			$html
		);
		self::assertStringContainsString( '<h3 class="ran-booster-section__title ran-booster-settings-disclosure__label">Release track</h3>', $html );
		self::assertStringNotContainsString( 'Release Track', $html );
		self::assertMatchesRegularExpression(
			'/<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-none-intent" data-ran-booster-badge data-ran-booster-badge-intent="none" data-ran-booster-release-track-summary>\s*' . $expected_summary . '\s*<\\/span>/',
			$html
		);
		self::assertStringContainsString( '<div data-ran-booster-ui-disclosure-content class="ran-booster-settings-disclosure__body ran-booster-disclosure__body">', $html );
		self::assertStringContainsString( '<form id="ran-booster-release-track-form"', $html );
		if ( 'branch' === $source ) {
			self::assertStringContainsString( 'form="ran-booster-release-track-form"', $html );
			self::assertStringContainsString( '<fieldset class="ran-booster-release-track-control"', $html );
			self::assertStringNotContainsString( 'ran-booster-release-track-control is-disabled', $html );
		} else {
			self::assertStringContainsString( 'name="action" value="ran_booster_release_change_channel"', $html );
			self::assertStringContainsString( '<fieldset class="ran-booster-release-track-control"', $html );
		}
		self::assertStringNotContainsString( 'ran-booster-initial-release-track', $html );
		self::assertStringNotContainsString( '<select', $html );
		self::assertStringNotContainsString( 'Activate Releases to change the release track.', $html );
	}

	/** @return array<string,array{string,string,string,string}> */
	public static function release_track_disclosure_packages(): array {
		return array(
			'plugin stable'   => array( 'branch', 'plugin', 'stable', 'Stable' ),
			'theme branch'    => array( 'branch', 'theme', 'stable', 'Stable' ),
			'plugin releases' => array( 'release_asset', 'plugin', 'stable', 'Stable' ),
			'theme preview'   => array( 'release_asset', 'theme', 'prerelease', 'Preview' ),
		);
	}

	#[DataProvider( 'initial_release_tracks' )]
	public function test_initial_track_is_associated_with_enable_form_and_forwarded( string $type, string $channel ): void {
		$package = new PackageProjection( 'branch', $type );
		$status  = ReleaseManagementFixture::status( 'branch', $type );
		$nonce   = 'nonce-for-release-tracking-enable-' . $type . '-' . $package->identifier() . '-3';
		ob_start();
		( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->render_settings( $package, $status, $package->settings_url(), $channel, array( 'enable' => $nonce ) );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Initial release track', $html );
		self::assertStringNotContainsString( '<select', $html );
		self::assertSame( 2, preg_match_all( '/<input type="radio"[^>]*name="release_channel"[^>]*form="ran-booster-release-track-form"/', $html ) );
		self::assertSame( 1, preg_match( '/<input type="radio"[^>]*name="release_channel" value="' . $channel . '"[^>]*checked=/', $html ) );
		self::assertSame( 1, substr_count( $html, ' checked=' ) );
		self::assertStringContainsString( '<fieldset class="ran-booster-release-track-control"', $html );
		self::assertStringNotContainsString( 'Activate Releases to change the release track.', $html );
		self::assertStringContainsString( 'form="ran-booster-release-track-form" data-ran-booster-use-releases data-ran-booster-use-releases-ready="true" aria-disabled="false">Use releases', $html );

		$tracking = new ReleaseTrackingFacadeDouble( $status );
		$request  = array(
			'expected_type'            => $type,
			'expected_identifier'      => $package->identifier(),
			'expected_source_revision' => '3',
			'_wpnonce'                 => $nonce,
			'release_channel'          => $channel,
		);
		ReleaseManagementFixture::controls( $tracking )->process_admin_post_request( 'enable', $request );
		self::assertSame( array( array( 'enable', $type, $package->identifier(), 3, $channel, $nonce ) ), $tracking->calls );
	}

	/** @return array<string,array{string,string}> */
	public static function initial_release_tracks(): array {
		return array(
			'plugin stable'  => array( 'plugin', 'stable' ),
			'plugin preview' => array( 'plugin', 'prerelease' ),
			'theme stable'   => array( 'theme', 'stable' ),
			'theme preview'  => array( 'theme', 'prerelease' ),
		);
	}

	#[DataProvider( 'initial_track_gate_failures' )]
	public function test_initial_track_is_disabled_when_transition_is_blocked( string $eligibility, string $failure, bool $has_nonce ): void {
		$package = new PackageProjection();
		$status  = ReleaseManagementFixture::status( eligibility_code: $eligibility, failure_code: $failure );
		ob_start();
		( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->render_settings( $package, $status, $package->settings_url(), 'prerelease', $has_nonce ? array( 'enable' => 'nonce' ) : array() );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<fieldset class="ran-booster-release-track-control is-disabled" disabled="disabled"', $html );
		self::assertStringNotContainsString( '<select', $html );
		self::assertStringContainsString( 'form="ran-booster-release-track-form" data-ran-booster-use-releases data-ran-booster-use-releases-ready="false" aria-disabled="true" aria-describedby="ran-booster-release-use-releases-reason">Use releases', $html );
		self::assertStringContainsString( 'id="ran-booster-release-readiness-status"', $html );
	}

	/** @return array<string,array{string,string,bool}> */
	public static function initial_track_gate_failures(): array {
		return array(
			'missing nonce' => array( 'eligible', '', false ),
			'ineligible'    => array( 'missing_update_uri', '', true ),
			'conflict'      => array( 'eligible', 'release_repository_conflict', true ),
		);
	}

	public function test_shared_repository_notice_lists_other_packages_and_keeps_identity_green(): void {
		$controls = $this->conflict_controls();
		$package  = new PackageProjection();
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-release-gate-notice' ) );
		self::assertStringContainsString( 'data-ran-booster-release-gate-notice data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p>', $html );
		self::assertStringContainsString( '<ul class="ul-disc">', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins&amp;package=nested%2Fnested.php', $html );
		self::assertStringContainsString( 'page=ran-booster-themes&amp;package=companion-theme', $html );
		self::assertStringContainsString( 'id="ran-booster-release-readiness-status"><span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-high-intent" data-ran-booster-badge data-ran-booster-badge-intent="high">Repository shared</span></span>', $html );
		self::assertStringNotContainsString( 'id="ran-booster-release-readiness-status"><span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-stable-intent" data-ran-booster-badge data-ran-booster-badge-intent="stable">Ready</span></span>', $html );
		self::assertStringContainsString( 'Package identity and Update URI match the repository.', $html );
		self::assertMatchesRegularExpression( '/button[^>]+disabled[^>]*>Use releases<\/button>/', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-browser-disabled="true"', $html );
		self::assertStringNotContainsString( 'Published release status needs attention.', $html );
		self::assertStringNotContainsString( 'Why this happened and how to fix it', $html );
		if ( 1 !== preg_match( '/<ul class="ul-disc">(.*?)<\/ul>/s', $html, $list ) ) {
			self::fail( 'Expected the package administration list.' );
		}
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

		self::assertStringContainsString( 'id="ran-booster-release-readiness-status"><span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-high-intent" data-ran-booster-badge data-ran-booster-badge-intent="high">Storage unavailable</span></span>', $html );
		self::assertStringContainsString( 'Booster could not safely read this package&#039;s repository storage. Check package storage and retry.', $html );
		self::assertStringContainsString( '<fieldset class="ran-booster-release-track-control is-disabled" disabled="disabled"', $html );
		self::assertMatchesRegularExpression( '/button[^>]+disabled[^>]*>Check releases<\/button>/', $html );
		self::assertStringNotContainsString( 'Open WordPress updates', $this->readiness_actions( $html ) );
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

		self::assertStringContainsString( 'form="ran-booster-release-track-form" data-ran-booster-use-releases data-ran-booster-use-releases-ready="false" aria-disabled="true" aria-describedby="ran-booster-release-use-releases-reason">Use releases</button>', $html );
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
			self::assertStringNotContainsString( 'id="ran-booster-release-readiness-status"><span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-stable-intent" data-ran-booster-badge data-ran-booster-badge-intent="stable">Ready</span></span>', $html );
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

	#[DataProvider( 'installation_review_outcomes' )]
	public function test_installation_review_notice_orders_state_and_safe_action( string $code, string $heading, string $state ): void {
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		ob_start();
		$display->render_operation_notice( $code, false );
		$html   = (string) ob_get_clean();
		$action = 'Inspect the installed version, activation state and Booster management before using Link an already installed plugin (or theme) or retrying.';

		self::assertStringContainsString( 'notice-warning', $html );
		self::assertStringContainsString( '<strong>' . $heading . '</strong>', $html );
		self::assertLessThan( strpos( $html, $state ), strpos( $html, $heading ) );
		self::assertLessThan( strpos( $html, $action ), strpos( $html, $state ) );
		self::assertSame( 1, substr_count( $html, $action ) );
		self::assertStringNotContainsString( 'data-ran-booster-package-success', $html );
		if ( 'installed_but_unmanaged' !== $code ) {
			self::assertStringNotContainsString( 'A package is present', $html );
			self::assertStringNotContainsString( 'was installed', $html );
		}
	}

	/** @return list<array{string, string, string}> */
	public static function installation_review_outcomes(): array {
		return array(
			array( 'installed_but_unmanaged', 'Package present, Booster management not established', 'A package is present, but Booster did not establish management for it.' ),
			array( 'management_state_uncertain', 'Installation outcome needs review', 'Booster could not confirm the final installation or management state.' ),
			array( 'installation_cleanup_failed', 'Installation outcome needs review', 'Booster could not confirm the installation or management state after finalization failed.' ),
		);
	}

	public function test_captured_source_pane_keeps_installation_review_notice(): void {
		$controls = ReleaseManagementFixture::controls();
		$package  = new PackageProjection();
		$this->set_conflict_result( 'installation_cleanup_failed' );
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Installation outcome needs review', $html );
		self::assertStringContainsString( 'Inspect the installed version, activation state and Booster management', $html );
		self::assertSame( 1, substr_count( $html, 'Installation outcome needs review' ) );
	}

	public function test_other_operation_notices_keep_success_and_diagnostic_behavior(): void {
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		ob_start();
		$display->render_operation_notice( 'installed', true );
		$success = (string) ob_get_clean();
		self::assertStringContainsString( 'notice-success', $success );
		self::assertStringContainsString( 'data-ran-booster-package-success', $success );
		self::assertStringContainsString( 'The published release was installed and is now managed by Booster.', $success );
		self::assertStringNotContainsString( 'Installation outcome needs review', $success );

		ob_start();
		$display->render_operation_notice( 'preflight_unavailable', false );
		$failure = (string) ob_get_clean();
		self::assertStringContainsString( 'notice-error', $failure );
		self::assertStringContainsString( 'Why this happened and how to fix it', $failure );
		self::assertStringContainsString( 'Open Troubleshooting', $failure );
		self::assertStringNotContainsString( 'Installation outcome needs review', $failure );
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
		$database = new \RAN\Tests\Support\RepositorySourceGuardDatabase();
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

	private function set_conflict_result( string $code, bool $successful = false ): void {
		$payload = \RAN\Admin\ReleaseManagement\wp_json_encode( array( $code, $successful, 'plugin', 'example/example.php', 'stable' ) );
		self::assertIsString( $payload );
		$_GET = array(
			'ran_booster_release_result'       => $code,
			'ran_booster_release_success'      => $successful ? '1' : '0',
			'ran_booster_release_type'         => 'plugin',
			'ran_booster_release_package'      => 'example/example.php',
			'ran_booster_release_channel'      => 'stable',
			'ran_booster_release_result_nonce' => 'nonce-for-ran-booster-result-' . hash( 'sha256', $payload ),
		);
	}

	private function readiness_actions( string $html ): string {
		if ( 1 !== preg_match( '~<div class="ran-booster-readiness-actions">[\s\S]*?</section>~', $html, $matches ) ) {
			self::fail( 'The Release readiness actions row must render.' );
		}
		return $matches[0];
	}

	/** @return array<string,array{string,string,string}> */
	public static function inactive_recheck_states(): array {
		return array(
			'releases tracked' => array( 'release_asset', 'plugin', 'Not needed while releases are tracked.' ),
			'branch eligible'  => array( 'branch', 'plugin', 'This package is already eligible for releases.' ),
		);
	}

	#[DataProvider( 'inactive_recheck_states' )]
	public function test_inactive_recheck_stays_visible_focusable_and_explained( string $source, string $type, string $reason ): void {
		$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( $source, $type ) ) );
		$package  = new PackageProjection( $source, $type );

		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		$html    = (string) ob_get_clean();
		$actions = $this->readiness_actions( $html );

		self::assertSame( 2, preg_match_all( '/<form[\s>]/', $actions ), 'The actions row holds exactly Recheck eligibility and Check releases.' );
		self::assertStringNotContainsString( 'update-core.php', $actions );
		self::assertStringNotContainsString( 'Open WordPress updates', $actions );
		self::assertStringNotContainsString( 'Manage release workflow', $html );
		if ( 1 !== preg_match( '~<button type="submit"([^>]*)>Recheck eligibility</button>~', $actions, $button ) ) {
			self::fail( 'Recheck eligibility must stay rendered.' );
		}
		self::assertStringContainsString( 'aria-disabled="true"', $button[1] );
		self::assertDoesNotMatchRegularExpression( '/\sdisabled(=|\s|$)/', $button[1] );
		self::assertStringContainsString( 'aria-describedby="ran-booster-release-recheck-reason"', $button[1] );
		self::assertStringContainsString( '<span id="ran-booster-release-recheck-reason" class="ran-booster-reason-tooltip__bubble" role="tooltip" hidden>' . $reason . '</span>', $actions );
		self::assertStringNotContainsString( 'Open repository', $html );
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

		self::assertMatchesRegularExpression( '~<span class="ran-booster-readiness-actions__links">\s*<a href="https://example.test/repository-releases">Manage release automation</a>\s*</span>~', $html, 'Add-on output keeps its trailing group.' );
		self::assertStringNotContainsString( 'Open repository', $html );
		self::assertSame( 1, substr_count( $html, 'ran-booster-readiness-actions__links' ) );
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
		self::assertStringContainsString( 'id="ran-booster-release-readiness-status"><span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-high-intent" data-ran-booster-badge data-ran-booster-badge-intent="high">Not ready</span></span>', $html );
		self::assertStringNotContainsString( 'Not ready for releases', $html );
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
		self::assertStringContainsString( 'Preview also includes alpha, beta and release-candidate builds. Switching affects future eligibility only and resets Automatic updates to Manual.', $html );
		self::assertStringNotContainsString( 'does not install or downgrade', $html );
		self::assertStringNotContainsString( 'type="hidden" name="release_channel"', $html );
		self::assertStringNotContainsString( 'Use published releases', $html );
	}

	public function test_managed_release_track_form_opts_into_the_shared_submit_busy_state(): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'release_asset', channel: 'stable' )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertSame( 1, preg_match( '/<form id="ran-booster-release-track-form"[^>]*data-ran-booster-submit-busy/', $html ) );
		if ( 1 !== preg_match( '/<button type="submit" class="button ran-booster-release-track-option" name="release_channel" value="prerelease"[^>]*>\s*<span>Preview<\/span>\s*<\/button>/', $html, $button ) ) {
			self::fail( 'The alternative track must render as a submit button wrapping its label in a span.' );
		}
		self::assertStringContainsString( 'data-ran-booster-busy-label="Switching…"', $button[0] );
		self::assertStringContainsString( 'data-ran-booster-busy-message="Switching the release track. This can take a few seconds."', $button[0] );
		self::assertStringContainsString( 'aria-label="Switch to Preview releases"', $button[0] );
		if ( 1 !== preg_match( '/<span class="button button-primary ran-booster-release-track-option is-current"[^>]*>/', $html, $current ) ) {
			self::fail( 'Expected the current track span.' );
		}
		self::assertStringNotContainsString( 'data-ran-booster-busy-label', $current[0], 'The current track is a non-interactive span and carries no busy label.' );
		self::assertSame( 1, preg_match( '/<\/div>\s*<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status><\/span>\s*<p id="ran-booster-release-track-description"/', $html ) );
	}

	public function test_branch_release_track_radios_do_not_opt_into_the_submit_busy_state(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertSame( 1, preg_match( '/<form id="ran-booster-release-track-form"/', $html ) );
		self::assertSame( 0, preg_match( '/<form id="ran-booster-release-track-form"[^>]*data-ran-booster-submit-busy/', $html ) );
	}

	public function test_branch_use_releases_button_carries_a_busy_label_and_the_form_announces_it(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		if ( 1 !== preg_match( '/<button[^>]*data-ran-booster-use-releases [^>]*>Use releases<\/button>/', $html, $button ) ) {
			self::fail( 'Expected the Use releases button.' );
		}
		self::assertStringContainsString( 'data-ran-booster-busy-label="Switching…"', $button[0] );
		self::assertStringContainsString( 'data-ran-booster-busy-message="Switching to published releases. This can take a few seconds."', $button[0] );
		self::assertSame( 1, preg_match( '/<form id="ran-booster-release-track-form".*?<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status><\/span><\/form>/s', $html ) );
	}

	public function test_readiness_actions_carry_busy_labels_and_each_form_announces_once(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', channel: 'stable' ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		foreach ( array(
			'Recheck eligibility' => array( 'Rechecking…', 'Rechecking eligibility. This can take a few seconds.' ),
			'Check releases'      => array( 'Checking…', 'Checking for published releases. This can take a few seconds.' ),
		) as $idle => list( $label, $message ) ) {
			if ( 1 !== preg_match( '/<button[^>]*>' . preg_quote( $idle, '/' ) . '<\/button>\s*(?:<span id="ran-booster-release-recheck-reason"[^>]*>[^<]*<\/span>\s*<\/span>\s*)?<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status><\/span>\s*<\/form>/', $html, $match ) ) {
				self::fail( 'Expected ' . $idle . ' to be the last control before its form status.' );
			}
			self::assertStringContainsString( 'data-ran-booster-busy-label="' . $label . '"', $match[0] );
			self::assertStringContainsString( 'data-ran-booster-busy-message="' . $message . '"', $match[0] );
		}
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
		self::assertStringNotContainsString( 'Review the latest eligible release and the installed version.', $html );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-managed-release-placeholder' ) );
		self::assertStringContainsString( 'class="ran-booster-release-candidates ran-booster-release-candidates--pending" aria-hidden="true" data-ran-booster-managed-release-placeholder>', $html );
		self::assertStringContainsString( '<p class="ran-booster-release-candidates__heading">Available published releases</p>', $html );
		self::assertSame( 2, substr_count( $html, 'class="ran-booster-release-candidate ran-booster-release-candidate--placeholder"' ) );
		self::assertSame( 2, substr_count( $html, '<input type="radio" disabled tabindex="-1">' ) );
		self::assertSame( 2, substr_count( $html, 'class="ran-booster-release-candidate__skeleton"></span>' ) );
		self::assertStringContainsString( '<p class="ran-booster-release-candidates__note">Published releases appear here once releases are in use.</p>', $html );
		self::assertStringContainsString( '<fieldset class="ran-booster-release-candidates" data-ran-booster-managed-release-candidates hidden>', $html );
		self::assertStringNotContainsString( 'Downgrades are unavailable because package data migrations may not be reversible.', $html );
		self::assertStringContainsString( '<strong>Installed identity and Update URI</strong>', $html );
		self::assertStringContainsString( '<strong>Release status</strong>', $html );
		self::assertStringNotContainsString( '<strong>Package root</strong>', $html );
		self::assertStringNotContainsString( '<strong>Installation route</strong>', $html );
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

		self::assertStringNotContainsString( '>Install now</a>', $html );
		// The browser keeps the offer data; the Update now button moved to the Package operations group.
		self::assertStringNotContainsString( '>Update now</a>', $html );
		self::assertStringNotContainsString( 'ran-booster-managed-release-browser__footer', $html );
		self::assertStringNotContainsString( 'data-ran-booster-managed-release-native-update>', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-native-update-version="1.2.0"', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-native-update-release-id=""', $html );
		self::assertStringContainsString( 'aria-disabled="true"', $html );
		self::assertStringContainsString( 'action=upgrade-plugin', $html );
		self::assertStringContainsString( 'plugin=example%2Fexample.php', $html );
		self::assertStringContainsString( '_wpnonce=nonce-for-upgrade-plugin_example%2Fexample.php', $html );
	}

	public function test_package_operation_actions_render_a_disabled_secondary_update_now_for_release_packages_only(): void {
		$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );

		ob_start();
		$controls->render_package_operation_actions( 'release_asset', 'release_asset' );
		$html = trim( (string) ob_get_clean() );

		self::assertMatchesRegularExpression( '/^<a class="ran-wp-ui-reset-a ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-neutral is-outline disabled ran-booster-managed-release-native-update" aria-disabled="true" tabindex="-1" data-ran-booster-managed-release-native-update>Update now<\/a>$/', $html );
		self::assertStringNotContainsString( 'href=', $html );

		foreach ( array( array( 'branch', 'branch' ), array( 'branch', 'release_asset' ), array( 'release_asset', 'branch' ) ) as $sources ) {
			ob_start();
			$controls->render_package_operation_actions( $sources[0], $sources[1] );
			self::assertSame( '', trim( (string) ob_get_clean() ), implode( '/', $sources ) );
		}
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
		self::assertDoesNotMatchRegularExpression( '/class="[^"]*\\bran-booster-source-transition\\b/', $html );
		self::assertStringNotContainsString( '>Branch currently active.', $html );
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
	public function test_update_uri_gate_appears_once_after_the_action_and_before_the_checklist_for_plugins_and_themes( string $type, string $eligibility_code, string $readiness_message ): void {
		$tracking = new ReleaseTrackingFacadeDouble(
			ReleaseManagementFixture::status( 'branch', $type, $eligibility_code )
		);
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'branch', $type );

		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		$gate_position      = strpos( $html, 'data-ran-booster-release-gate-notice' );
		$component_position = strpos( $html, 'Use releases' );
		$heading_position   = strpos( $html, 'id="ran-booster-release-management-heading"' );
		$checklist_position = strpos( $html, 'Installed identity and Update URI' );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-release-gate-notice' ) );
		self::assertIsInt( $gate_position );
		self::assertIsInt( $component_position );
		self::assertIsInt( $heading_position );
		self::assertIsInt( $checklist_position );
		self::assertLessThan( $gate_position, $component_position );
		self::assertLessThan( $heading_position, $gate_position );
		self::assertLessThan( $checklist_position, $heading_position );
		self::assertStringNotContainsString( '>Branch currently active.', $html );
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
		self::assertStringContainsString( 'class="ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-solid" aria-disabled="false">Use branch', $html );
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

	public function test_eligible_branch_transition_action_follows_readiness_and_the_release_track_and_submits_its_form(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		$heading_position    = strpos( $html, 'id="ran-booster-release-management-heading"' );
		$checklist_position  = strpos( $html, 'Installed identity and Update URI' );
		$disclosure_position = strpos( $html, 'id="ran-booster-release-track-settings"' );
		$track_form_position = strpos( $html, '<form id="ran-booster-release-track-form"' );
		$track_position      = strpos( $html, 'data-ran-booster-release-channel-control' );
		$action_position     = strpos( $html, '>Use releases<' );
		self::assertIsInt( $heading_position );
		self::assertIsInt( $checklist_position );
		self::assertIsInt( $disclosure_position );
		self::assertIsInt( $track_form_position );
		self::assertIsInt( $track_position );
		self::assertIsInt( $action_position );
		self::assertTrue( $heading_position < $checklist_position );
		self::assertTrue( $checklist_position < $disclosure_position );
		self::assertTrue( $disclosure_position < $track_form_position );
		self::assertTrue( $track_form_position < $track_position );
		self::assertTrue( $action_position < $heading_position );
		self::assertStringNotContainsString( '</details></div>', substr( $html, 0, $action_position ) );
		self::assertStringContainsString( 'data-ran-booster-source-transition', $html );
		self::assertStringContainsString( 'form="ran-booster-release-track-form" data-ran-booster-use-releases data-ran-booster-use-releases-ready="true" aria-disabled="false">Use releases', $html );
		self::assertStringNotContainsString( 'form="ran-booster-release-track-form" disabled', $html );
		self::assertStringNotContainsString( 'aria-describedby', substr( $html, (int) strpos( $html, 'data-ran-booster-use-releases ' ), 200 ) );
		self::assertDoesNotMatchRegularExpression( '/class="[^"]*\\bran-booster-source-transition\\b/', $html );
		self::assertStringContainsString( 'class="ran-booster-source-switch-bar ran-booster-release-switch-actions"', $html );
		self::assertStringContainsString( 'Branch currently active.', $html );
		self::assertStringNotContainsString( 'Initial release track', $html );
		self::assertStringNotContainsString( '<select', $html );
		self::assertStringNotContainsString( 'Activate Releases to change the release track.', $html );
		self::assertStringNotContainsString( 'Uses saved package settings. Save any edits before switching.', $html );
		self::assertStringNotContainsString( 'Automatic resets to Manual.', $html );
		self::assertSame( 1, substr_count( $html, '>Use releases<' ) );
		self::assertStringContainsString( 'data-ran-booster-use-releases-summary="Branch currently active. Switch to releases using the %s track." data-ran-booster-use-releases-summary-unavailable="Releases aren’t ready. Fix the readiness checks below.">Branch currently active. Switch to releases using the Stable track.</p>', $html );
		self::assertSame( 1, preg_match( '/<span class="ran-booster-reason-tooltip">\s*<button[^>]*data-ran-booster-use-releases /', $html ), 'A ready action carries no reason tooltip hook.' );
		self::assertTrue( strpos( $html, 'data-ran-booster-use-releases-summary' ) < $action_position );
	}

	public function test_switch_sentence_names_the_saved_preview_track(): void {
		$package = new PackageProjection();
		ob_start();
		( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->render_settings( $package, ReleaseManagementFixture::status(), $package->settings_url(), 'prerelease', array( 'enable' => 'nonce' ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Branch currently active. Switch to releases using the Preview track.</p>', $html );
	}

	public function test_not_ready_switch_bar_stays_visible_aria_disabled_with_a_reason_tooltip(): void {
		$package = new PackageProjection();
		$status  = ReleaseManagementFixture::status( eligibility_code: 'missing_update_uri' );
		ob_start();
		( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->render_settings( $package, $status, $package->settings_url(), 'stable', array() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Releases aren’t ready. Fix the readiness checks below.</p>', $html );
		self::assertStringNotContainsString( 'Branch currently active.', preg_replace( '/data-ran-booster-use-releases-summary="[^"]*"/', '', $html ) ?? '' );
		if ( 1 !== preg_match( '/<button[^>]*data-ran-booster-use-releases [^>]*>Use releases<\/button>/', $html, $button ) ) {
			self::fail( 'The Use releases button must stay visible.' );
		}
		self::assertStringContainsString( 'aria-disabled="true"', $button[0] );
		self::assertStringContainsString( 'form="ran-booster-release-track-form"', $button[0] );
		self::assertStringContainsString( 'aria-describedby="ran-booster-release-use-releases-reason"', $button[0] );
		self::assertDoesNotMatchRegularExpression( '/\sdisabled(=|\s|>)/', $button[0] );
		self::assertStringContainsString( '<span class="ran-booster-reason-tooltip" data-ran-booster-reason-tooltip>', $html );
		self::assertStringContainsString( 'id="ran-booster-release-use-releases-reason" class="ran-booster-reason-tooltip__bubble" role="tooltip" data-ran-booster-use-releases-reason hidden>Available once the readiness checks pass.</span>', $html );
	}

	public function test_switch_bar_appears_only_on_the_tab_of_the_inactive_source(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', channel: 'stable' ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );

		// Releases active: its own tab has no bar, the Branch tab has the bar.
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$releases_tab = (string) ob_get_clean();
		self::assertStringNotContainsString( 'ran-booster-source-switch-bar', $releases_tab );
		self::assertStringNotContainsString( 'Use releases', $releases_tab );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $package, $package->settings_url() );
		$branch_tab = (string) ob_get_clean();
		self::assertSame( 1, preg_match_all( '/class="ran-booster-source-switch-bar ran-booster-release-return-form ran-booster-source-transition"/', $branch_tab ) );
		self::assertStringContainsString( 'Releases currently active.', $branch_tab );

		// Branch active: the Branch tab has no bar, the Releases tab has the bar.
		$branch_package  = new PackageProjection();
		$branch_tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$branch_controls = ReleaseManagementFixture::controls( $branch_tracking );
		ob_start();
		$branch_controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $branch_package, $branch_package->settings_url() );
		$branch_active_branch_tab = (string) ob_get_clean();
		self::assertStringNotContainsString( 'ran-booster-source-switch-bar', $branch_active_branch_tab );
		self::assertStringNotContainsString( 'Use branch', $branch_active_branch_tab );

		ob_start();
		$branch_controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $branch_package, $branch_package->settings_url() );
		$branch_active_releases_tab = (string) ob_get_clean();
		self::assertSame( 1, substr_count( $branch_active_releases_tab, 'data-ran-booster-source-switch-bar="branch"' ) );
		self::assertStringNotContainsString( 'Releases currently active.', $branch_active_releases_tab );
	}

	public function test_switch_bar_sits_at_the_top_of_the_releases_pane_under_its_description(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		$description = strpos( $html, 'ran-booster-package-source-pane__description' );
		$bar         = strpos( $html, 'data-ran-booster-source-switch-bar="branch"' );
		$readiness   = strpos( $html, 'class="ran-booster-readiness-panel"' );
		self::assertIsInt( $description );
		self::assertIsInt( $bar );
		self::assertIsInt( $readiness );
		self::assertTrue( $description < $bar );
		self::assertTrue( $bar < $readiness );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-use-releases ' ) );
	}

	public function test_error_result_keeps_the_switch_bar_with_the_notice_above_it(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection();
		$this->set_conflict_result( 'source_changed' );
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		$notices = strpos( $html, 'data-ran-booster-release-notices' );
		$notice  = strpos( $html, 'Package settings changed after this browser page was opened.' );
		$bar     = strpos( $html, 'data-ran-booster-source-switch-bar="branch"' );
		self::assertIsInt( $notices );
		self::assertIsInt( $notice );
		self::assertIsInt( $bar );
		self::assertTrue( $notices < $notice );
		self::assertTrue( $notice < $bar );
		self::assertStringContainsString( '>Use releases<', $html );
	}

	public function test_error_result_on_the_branch_tab_keeps_the_return_bar_below_the_notice(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', channel: 'stable' ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );
		$this->set_conflict_result( 'source_changed' );
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		$notices = strpos( $html, 'data-ran-booster-release-notices' );
		$bar     = strpos( $html, 'class="ran-booster-source-switch-bar ran-booster-release-return-form' );
		self::assertIsInt( $notices );
		self::assertIsInt( $bar );
		self::assertTrue( $notices < $bar );
		self::assertStringContainsString( 'Package settings changed after this browser page was opened.', $html );
		self::assertStringContainsString( '>Use branch<', $html );
	}

	public function test_success_result_shows_the_notice_and_no_switch_bar_on_the_now_active_source(): void {
		// Use releases succeeded: Releases is active, so its tab has the notice and no bar.
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', channel: 'stable' ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset' );
		$this->set_conflict_result( 'release_enabled', true );
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$releases_html = (string) ob_get_clean();
		self::assertStringContainsString( 'Release tracking enabled.', $releases_html );
		self::assertStringNotContainsString( 'ran-booster-source-switch-bar', $releases_html );
		self::assertStringNotContainsString( 'Use releases', $releases_html );

		// Use branch succeeded: Branch is active, so its tab has the notice and no bar.
		$branch_tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$branch_controls = ReleaseManagementFixture::controls( $branch_tracking );
		$branch_package  = new PackageProjection();
		$this->set_conflict_result( 'branch_restored', true );
		ob_start();
		$branch_controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $branch_package, $branch_package->settings_url() );
		$branch_html = (string) ob_get_clean();
		self::assertStringContainsString( 'Branch-based package management is restored.', $branch_html );
		self::assertStringNotContainsString( 'ran-booster-source-switch-bar', $branch_html );
		self::assertStringNotContainsString( 'Use branch', $branch_html );
	}

	public function test_branch_release_status_row_is_neutral_only_while_releases_are_not_tracked(): void {
		$render = static function ( string $source, string $failure = '', ?string $eligibility = null ): string {
			$status  = null === $eligibility
				? ReleaseManagementFixture::status( $source, 'plugin', failure_code: $failure )
				: ReleaseManagementFixture::status( $source, 'plugin', $eligibility, failure_code: $failure );
			$package = new PackageProjection( $source );
			ob_start();
			ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( $status ) )->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
			return (string) ob_get_clean();
		};
		$row    = static function ( string $html ): string {
			if ( 1 !== preg_match( '/<li class="ran-booster-readiness-item ([^"]*)">\s*<span class="ran-booster-readiness-icon" aria-hidden="true"><\/span>\s*<strong>Release status<\/strong>/', $html, $m ) ) {
				self::fail( 'Expected the Release status readiness row.' );
			}
			return $m[1];
		};

		$branch = $render( 'branch' );
		self::assertStringContainsString( 'Branch is active; releases are not tracked.', $branch );
		self::assertSame( 'is-neutral', $row( $branch ) );
		self::assertSame( 'is-ok', $row( $render( 'release_asset' ) ) );
		self::assertSame( 'is-warning', $row( $render( 'release_asset', 'release_unavailable' ) ) );
		self::assertSame( 'is-warning', $row( $render( 'branch', 'release_unavailable' ) ) );
		self::assertSame( 'is-warning', $row( $render( 'branch', 'repository_source_unavailable' ) ) );
	}

	/** @param array<string,mixed> $arguments */
	private function render_release_view( array $arguments, string $operation_notice = '' ): string {
		$status  = ReleaseManagementFixture::status( 'release_asset', 'plugin', ...$arguments );
		$package = new PackageProjection( 'release_asset' );
		ob_start();
		( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->render_settings( $package, $status, $package->settings_url(), '', array(), false, $operation_notice );
		return (string) ob_get_clean();
	}

	private function readiness_panel( string $html ): string {
		$start = strpos( $html, '<div class="ran-booster-readiness-panel">' );
		$end   = strpos( $html, '</section>', (int) $start );
		if ( false === $start || false === $end ) {
			self::fail( 'Expected the readiness panel.' );
		}
		return substr( $html, $start, $end - $start );
	}

	public function test_release_status_notice_sits_under_the_operation_notice_above_the_readiness_panel(): void {
		$operation = '<div class="notice notice-success" data-test-operation-notice><p>Release tracking enabled.</p></div>';
		foreach ( array( $operation, '' ) as $notice ) {
			$html = $this->render_release_view(
				array(
					'failure_code'     => 'release_unavailable',
					'preflight_reason' => 'release_incompatible',
				),
				$notice
			);

			$status_at = strpos( $html, 'data-ran-booster-release-status-notice' );
			$panel_at  = strpos( $html, '<div class="ran-booster-readiness-panel">' );
			self::assertNotFalse( $status_at );
			self::assertNotFalse( $panel_at );
			self::assertLessThan( $panel_at, $status_at );
			if ( '' !== $notice ) {
				$operation_at = strpos( $html, 'data-test-operation-notice' );
				self::assertNotFalse( $operation_at );
				self::assertLessThan( $status_at, $operation_at );
				self::assertSame( 1, substr_count( $html, 'data-ran-booster-release-notices' ) );
			}
			$panel = $this->readiness_panel( $html );
			self::assertStringNotContainsString( 'data-ran-booster-release-notices', $panel );
			self::assertStringNotContainsString( 'class="notice', $panel );
			self::assertStringNotContainsString( 'Why this happened and how to fix it', $panel );
			self::assertStringContainsString( 'Why this happened and how to fix it', $html );
		}
	}

	public function test_release_failure_states_use_accurate_wording_and_never_the_old_message(): void {
		$cases = array(
			'no_releases'          => array( 'No published release was found for the selected track.', 'Publish a release for the selected Stable or Preview track, then use Check releases.' ),
			'release_incompatible' => array( 'A published release was found, but it is not eligible.', 'exactly one package ZIP asset' ),
			''                     => array( 'Booster could not confirm an eligible published release for this track.', 'make sure it has one package ZIP' ),
		);
		foreach ( $cases as $reason => $expected ) {
			$html = $this->render_release_view(
				array(
					'failure_code'     => 'release_unavailable',
					'preflight_reason' => (string) $reason,
				)
			);
			self::assertStringContainsString( $expected[0], $html, (string) $reason );
			self::assertStringContainsString( $expected[1], $html, (string) $reason );
			self::assertStringContainsString( '<code>release_unavailable</code>', $html, (string) $reason );
			self::assertStringNotContainsString( 'No matching published release is available yet.', $html, (string) $reason );
			self::assertStringNotContainsString( 'then recheck eligibility', $html, (string) $reason );
			self::assertSame( 'is-warning', $this->release_status_row_class( $html ), (string) $reason );
		}
	}

	private function release_status_row_class( string $html ): string {
		if ( 1 !== preg_match( '/<li class="ran-booster-readiness-item ([^"]*)">\s*<span class="ran-booster-readiness-icon" aria-hidden="true"><\/span>\s*<strong>Release status<\/strong>/', $html, $m ) ) {
			self::fail( 'Expected the Release status readiness row.' );
		}
		return $m[1];
	}

	public function test_up_to_date_release_status_is_green_without_a_warning_notice(): void {
		$html = $this->render_release_view( array() );

		self::assertSame( 'is-ok', $this->release_status_row_class( $html ) );
		self::assertStringContainsString( 'Version 1.0.0 is installed; no newer eligible release was found.', $html );
		self::assertStringNotContainsString( 'data-ran-booster-release-status-notice', $html );
		self::assertStringNotContainsString( 'Why this happened and how to fix it', $html );
		self::assertStringNotContainsString( 'Use Check releases to look for a newer version.', $html );
	}

	public function test_a_status_with_no_check_in_this_request_is_neutral_and_never_claims_it_was_never_checked(): void {
		$html = $this->render_release_view( array( 'last_checked_at' => '' ) );

		self::assertSame( 'is-neutral', $this->release_status_row_class( $html ) );
		self::assertStringContainsString( 'Use Check releases to look for a newer version.', $html );
		self::assertStringNotContainsString( 'have not been checked', $html );
		self::assertStringNotContainsString( 'no newer eligible release was found', $html );
		self::assertStringNotContainsString( 'data-ran-booster-release-status-notice', $html );
		self::assertStringNotContainsString( 'No published release was found', $html );
		self::assertStringNotContainsString( 'could not confirm an eligible published release', $html );
		self::assertStringNotContainsString( 'No matching published release is available yet.', $html );
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
		self::assertStringContainsString( 'form="ran-booster-release-track-form" data-ran-booster-use-releases data-ran-booster-use-releases-ready="false" aria-disabled="true" aria-describedby="ran-booster-release-use-releases-reason"', $html );
		self::assertStringNotContainsString( '<select', $html );
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

	public function test_overwritten_post_global_is_rejected_before_release_mutation(): void {
		$tracking      = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls      = ReleaseManagementFixture::controls( $tracking );
		$original_post = $GLOBALS['_POST'] ?? array();

		try {
			foreach ( array( null, 'not-an-array', new \stdClass() ) as $malformed ) {
				$GLOBALS['_POST'] = $malformed;
				try {
					$controls->handle_enable();
				} catch ( \RuntimeException $redirect ) {
					self::assertSame( 'native-redirect', $redirect->getMessage() );
				}
				self::assertStringContainsString( 'ran_booster_release_result=invalid_request', $GLOBALS['ran_booster_release_management_test_redirect'] );
				self::assertSame( array(), $tracking->calls );
			}
		} finally {
			$GLOBALS['_POST'] = $original_post;
		}
	}

	public function test_unknown_operation_is_rejected_before_any_release_mutation(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$controls = ReleaseManagementFixture::controls( $tracking );

		$url = $controls->process_admin_post_request( 'unknown', $this->request( 'enable' ) );

		self::assertStringContainsString( 'ran_booster_release_result=invalid_request', $url );
		self::assertSame( array(), $tracking->calls );
	}

	public function test_direct_unknown_operation_keeps_the_existing_match_error_without_mutation(): void {
		$tracking   = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$operations = new \RAN\Admin\ReleaseManagement\ReleaseTrackingOperations( $tracking );

		try {
			$operations->execute( 'unknown', 'plugin', 'example/example.php', 3, 'stable', 'nonce' );
			self::fail( 'Unknown operations must not execute a release mutation.' );
		} catch ( \UnhandledMatchError ) {
			self::assertSame( array(), $tracking->calls );
		}
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

	public function test_a_refresh_that_recorded_no_check_does_not_report_completion(): void {
		$tracking   = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', last_checked_at: '' ) );
		$operations = new \RAN\Admin\ReleaseManagement\ReleaseTrackingOperations( $tracking );

		$outcome = $operations->execute( 'refresh', 'plugin', 'example/example.php', 3, 'stable', $this->nonce( 'refresh' ) );

		self::assertSame( 'refresh_unchecked', $outcome['code'] );
		self::assertFalse( $outcome['successful'] );
	}

	public function test_a_refresh_that_recorded_a_check_still_reports_the_result(): void {
		$tracking   = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$operations = new \RAN\Admin\ReleaseManagement\ReleaseTrackingOperations( $tracking );

		$outcome = $operations->execute( 'refresh', 'plugin', 'example/example.php', 3, 'stable', $this->nonce( 'refresh' ) );

		self::assertSame( 'release_current', $outcome['code'] );
		self::assertTrue( $outcome['successful'] );
	}

	public function test_an_unchecked_refresh_shows_a_warning_that_agrees_with_the_status(): void {
		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', last_checked_at: '' ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$url      = $controls->process_admin_post_request( 'refresh', $this->request( 'refresh' ) );
		$query    = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsing the already signed local PRG fixture.
		parse_str( $query, $_GET );

		ob_start();
		$controls->render_operation_notice();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Releases were not checked. Use Check releases to retry.', $html );
		self::assertStringContainsString( 'notice-warning', $html );
		self::assertStringNotContainsString( 'Release check complete.', $html );
		self::assertStringNotContainsString( 'notice-success', $html );
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
		self::assertStringContainsString( 'Release check complete.', $html );
		self::assertStringNotContainsString( 'no newer eligible release was found', $html );
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
