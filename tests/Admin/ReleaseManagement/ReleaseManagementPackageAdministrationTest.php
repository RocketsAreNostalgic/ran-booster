<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';
require_once __DIR__ . '/../../Storage/StorageTestEnvironment.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingResult;
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
		self::assertStringContainsString( 'Use Recheck eligibility to check published versions on Stable and Preview.', $html );
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
		self::assertStringNotContainsString( 'id="ran-booster-browse-track"', $html );
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
			'confirm_switch'           => '1',
		);
		$request += $this->confirmed_release_fields( $type, $package->identifier(), $channel );
		ReleaseManagementFixture::controls( $tracking )->process_admin_post_request( 'enable', $request );
		self::assertSame(
			array(
				array(
					'enable',
					$type,
					$package->identifier(),
					3,
					$channel,
					$nonce,
					'',
					'',
					array(
						'identity'          => 'rel-2.0.0',
						'tag'               => 'v2.0.0',
						'version'           => '2.0.0',
						'installed_version' => '1.0.0',
					),
				),
			),
			$tracking->calls
		);
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
		self::assertStringNotContainsString( 'data-ran-booster-managed-release-browser', $html );
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

	/** @return array<string,array{string,string}> */
	public static function failures_with_their_own_message(): array {
		return array(
			'release changed'        => array( 'release_changed', 'The release changed after it was inspected. No package was installed. Use Check releases and try again.' ),
			'release invalid'        => array( 'release_invalid', 'The selected release does not satisfy the selected channel requirements. No package was installed.' ),
			'source paused'          => array( 'source_paused', 'Updates are paused by a site setting. No files were changed.' ),
			'package already exists' => array( 'package_already_exists', 'That plugin or theme is already installed or managed. No package was overwritten.' ),
		);
	}

	#[DataProvider( 'failures_with_their_own_message' )]
	public function test_a_failed_install_shows_its_own_message_not_the_generic_status( string $code, string $message ): void {
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		ob_start();
		$display->render_operation_notice( $code, false );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( $message, $html );
		self::assertStringNotContainsString( 'needs attention', $html );
		self::assertStringNotContainsString( 'Why this happened and how to fix it', $html );
	}

	#[DataProvider( 'diagnostic_failures' )]
	public function test_a_diagnostic_failure_still_shows_its_message_and_help( string $code, string $message, string $action ): void {
		require_once __DIR__ . '/../../Support/NoticeIconGlobalWordPressFunctions.php';
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		ob_start();
		$display->render_operation_notice( $code, false );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( $message, $html );
		self::assertStringContainsString( $action, $html );
		self::assertStringContainsString( 'Why this happened and how to fix it', $html );
		self::assertStringContainsString( '<code>' . $code . '</code>', $html );
	}

	/** @return array<string,array{string,string,string}> */
	public static function diagnostic_failures(): array {
		$headers = 'Publish a package whose WordPress and PHP compatibility headers include this site, or choose a compatible release track.';
		$zip     = 'Check the uploaded ZIP on the release, then use Check releases to get a fresh offer.';
		return array(
			'version header'       => array( 'release_header_missing', 'The published package is missing its WordPress version header.', 'Correct the plugin or theme header in the distributable ZIP' ),
			'compatibility header' => array( 'package_compatibility_missing', 'The published release is not compatible with this WordPress site.', $headers ),
			'digest'               => array( 'download_digest_mismatch', 'The downloaded ZIP does not match the published digest.', $zip ),
			'size'                 => array( 'download_size_mismatch', 'The downloaded ZIP does not match the published size.', $zip ),
			'PHP'                  => array( 'archive_php_requirement_incompatible', 'The release ZIP requires a different PHP version.', $headers ),
			'WordPress'            => array( 'archive_wordpress_requirement_incompatible', 'The release ZIP requires a different WordPress version.', $headers ),
		);
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

	public static function eligible_branch_types(): array {
		return array( array( 'plugin' ), array( 'theme' ) );
	}

	#[DataProvider( 'eligible_branch_types' )]
	public function test_eligible_branch_can_recheck_release_eligibility( string $type ): void {
		$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'branch', $type ) ) );
		$package  = new PackageProjection( 'branch', $type );
		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		$actions = $this->readiness_actions( (string) ob_get_clean() );
		self::assertMatchesRegularExpression( '~<button type="submit"[^>]*aria-disabled="false"[^>]*>Recheck eligibility</button>~', $actions );
		self::assertStringContainsString( 'name="ran_booster_release_recheck" value="1"', $actions );
	}

	#[DataProvider( 'eligible_branch_types' )]
	public function test_release_inspection_requires_explicit_scoped_request_and_never_executes_switch( string $type ): void {
		$status                   = ReleaseManagementFixture::status( 'branch', $type );
		$tracking                 = new ReleaseTrackingFacadeDouble( $status );
		$controls                 = ReleaseManagementFixture::controls( $tracking );
		$tracking->candidate_list = new RepositoryReleaseCandidateList( array() );
		$package                  = new PackageProjection( 'branch', $type );
		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertSame( array(), $tracking->calls );
		self::assertStringNotContainsString( 'Browse releases', $html );
		self::assertStringContainsString( 'data-ran-booster-branch-preview', $html );
		self::assertStringContainsString( 'Refresh releases', $html );
		self::assertStringNotContainsString( 'data-ran-booster-managed-release-review', $html );
		self::assertStringNotContainsString( 'Check stable releases', $html );
		$_GET['ran_booster_release_list']                  = 'stable';
		$_GET['_ran_booster_release_list_nonce']['stable'] = 'invalid';
		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		ob_end_clean();
		self::assertSame( array(), $tracking->calls );
		$_GET['_ran_booster_release_list_nonce']['stable'] = 'nonce-for-' . $tracking->nonce_action( 'list_candidates', $type, $status->identifier(), 3, 'stable' );
		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertCount( 1, $tracking->calls );
		self::assertSame( 'list_candidates', $tracking->calls[0][0] );
		self::assertStringContainsString( 'Stable track: no published releases found in this lookup.', $html );
	}

	public function test_branch_browse_renders_real_disabled_options_for_each_track(): void {
		foreach ( array( 'stable', 'prerelease' ) as $channel ) {
			$status                           = ReleaseManagementFixture::status( 'branch', 'plugin' );
			$tracking                         = new ReleaseTrackingFacadeDouble( $status );
			$tracking->candidate_list         = new RepositoryReleaseCandidateList(
				array(
					new \RAN\RepositoryProvider\RepositoryReleaseCandidate( '1', 'v2.0.0', '2.0.0', 'prerelease' === $channel, '2026-01-01T00:00:00Z', array() ),
				)
			);
			$controls                         = ReleaseManagementFixture::controls( $tracking );
			$package                          = new PackageProjection( 'branch', 'plugin' );
			$_GET['ran_booster_release_list'] = $channel;
			$_GET['_ran_booster_release_list_nonce'][ $channel ] = 'nonce-for-' . $tracking->nonce_action( 'list_candidates', 'plugin', $status->identifier(), 3, $channel );
			ob_start();
			$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
			$html = (string) ob_get_clean();
			self::assertCount( 1, $tracking->calls );
			self::assertSame( 'list_candidates', $tracking->calls[0][0] );
			self::assertStringContainsString( '2.0.0 (v2.0.0) · ' . ( 'stable' === $channel ? 'Stable' : 'Preview' ), $html );
			self::assertMatchesRegularExpression( '/<fieldset class="ran-booster-release-candidates" disabled>.*?<input type="radio"[^>]* disabled>/s', $html );
			self::assertStringNotContainsString( 'track releases found:', $html );
			self::assertStringNotContainsString( 'data-ran-booster-managed-release-review', $html );
		}
	}

	public function test_recheck_reads_both_tracks_with_scoped_nonces_and_finds_highest_version(): void {
		require_once dirname( __DIR__, 3 ) . '/vendor/ran/wp-release-updater/src/Contract/ReleaseVersion.php';
		$status                              = ReleaseManagementFixture::status( 'branch', 'plugin' );
		$tracking                            = new ReleaseTrackingFacadeDouble( $status );
		$tracking->candidate_list            = new RepositoryReleaseCandidateList(
			array(
				new \RAN\RepositoryProvider\RepositoryReleaseCandidate( '1', 'v1.0.0', '1.0.0', false, '2026-01-01T00:00:00Z', array() ),
				new \RAN\RepositoryProvider\RepositoryReleaseCandidate( '2', 'v2.0.0', '2.0.0', false, '2026-01-02T00:00:00Z', array() ),
			)
		);
		$controls                            = ReleaseManagementFixture::controls( $tracking );
		$package                             = new PackageProjection( 'branch', 'plugin' );
		$_GET['ran_booster_release_recheck'] = '1';
		foreach ( array( 'stable', 'prerelease' ) as $channel ) {
			$_GET['_ran_booster_release_list_nonce'][ $channel ] = 'nonce-for-' . $tracking->nonce_action( 'list_candidates', 'plugin', $status->identifier(), 3, $channel );
		}
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertCount( 2, $tracking->calls );
		self::assertMatchesRegularExpression( '/<strong>Release status<\/strong>\s*<span>Branch is active for updates\. Latest Stable release found: 2\.0\.0\. Latest Preview release found: 2\.0\.0\.<\/span>/', $html );
		self::assertStringContainsString( 'Latest Preview release found: 2.0.0.', $html );
	}

	public function test_browse_preview_is_read_only_and_stays_open_for_managed_source(): void {
		$status                           = ReleaseManagementFixture::status( 'release_asset', 'plugin' );
		$tracking                         = new ReleaseTrackingFacadeDouble( $status );
		$tracking->candidate_list         = new RepositoryReleaseCandidateList( array() );
		$controls                         = ReleaseManagementFixture::controls( $tracking );
		$package                          = new PackageProjection( 'release_asset', 'plugin' );
		$_GET['ran_booster_release_list'] = 'prerelease';
		$_GET['_ran_booster_release_list_nonce']['prerelease'] = 'nonce-for-' . $tracking->nonce_action( 'list_candidates', 'plugin', $status->identifier(), 3, 'prerelease' );
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertCount( 1, $tracking->calls );
		self::assertSame( 'list_candidates', $tracking->calls[0][0] );
		self::assertStringContainsString( 'Preview track: no published releases found in this lookup.', $html );
		self::assertMatchesRegularExpression( '/<details id="ran-booster-release-track-settings"[^>]* open>/', $html );
		self::assertStringNotContainsString( 'id="ran-booster-browse-track"', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-channel="stable"', $html );
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
		self::assertStringNotContainsString( 'data-ran-booster-managed-release-browser', $html );
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_managed_browser_uses_saved_identity_and_keeps_word_press_as_installer(): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		require_once __DIR__ . '/../../Support/PackageViewWordPressFunctions.php';
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
		self::assertStringContainsString( 'hidden aria-disabled="true" data-ran-booster-managed-release-review>Review selected release</button>', $html );
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
				'version'    => '',
			),
			$inspect['data']['native_offer']
		);
		self::assertSame( array( 'inspect_candidate', 'plugin', 'example/example.php', 3, '42', 'v1.2.0', 'stable', 'nonce-for-release-tracking-inspect_candidate-plugin-example/example.php-3-stable' ), $tracking->calls[1] );
	}

	#[DataProvider( 'managed_picker_relationships' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_managed_listing_and_inspection_share_the_updater_release_order( string $installed, string $candidate, string $expected, bool $runtime_available ): void {
		if ( $runtime_available ) {
			require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		} else {
			self::assertFalse( class_exists( \RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion::class, false ) );
		}

		$tag                            = 'v' . $candidate;
		$status                         = new ReleaseTrackingStatus(
			'plugin',
			'example/example.php',
			'release_asset',
			3,
			'101',
			'manual',
			new ReleaseTrackingEligibility( ReleaseTrackingEligibility::ELIGIBLE ),
			installed_version: $installed,
			channel: 'prerelease'
		);
		$tracking                       = new ReleaseTrackingFacadeDouble( $status );
		$tracking->candidate_list       = new RepositoryReleaseCandidateList(
			array( new RepositoryReleaseCandidate( '42', $tag, $candidate, str_contains( $candidate, '-' ), '2026-08-20T09:00:00Z', array( 'example.zip' ) ) )
		);
		$tracking->candidate_inspection = new ReleaseTrackingPreflight( ReleaseTrackingPreflight::READY, 'example-plugin', $candidate, release_tag: $tag );
		$controls                       = ReleaseManagementFixture::controls( $tracking );
		$list                           = $controls->process_managed_browser_request( 'list_candidates', $this->managed_request( 'list_candidates', 'prerelease' ) );
		$inspection                     = $controls->process_managed_browser_request(
			'inspect_candidate',
			$this->managed_request( 'inspect_candidate', 'prerelease' ) + array(
				'release_id'  => '42',
				'release_tag' => $tag,
			)
		);

		self::assertTrue( $list['successful'] );
		self::assertTrue( $inspection['successful'] );
		self::assertSame( $expected, $list['data']['candidates'][0]['version_relationship'] );
		self::assertSame( $expected, $inspection['data']['version_relationship'] );
	}

	/** @return array<string,array{string,string,string,bool}> */
	public static function managed_picker_relationships(): array {
		return array(
			'updater newer'       => array( '1.2.0-alpha.1', '1.2.0-dev.1', 'newer', true ),
			'updater older'       => array( '1.2.0-dev.1', '1.2.0-alpha.1', 'older', true ),
			'normalized same'     => array( '1.2', '1.2.0', 'same', true ),
			'invalid installed'   => array( '1.2.0+unsupported', '2.0.0', 'invalid', true ),
			'invalid candidate'   => array( '1.2.0', '2.0.0+unsupported', 'invalid', true ),
			'unavailable runtime' => array( '1.2.0', '2.0.0', 'invalid', false ),
		);
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_managed_candidate_inspection_recomputes_relationship_from_the_fresh_installed_version(): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
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

	public function test_managed_browser_links_to_the_wordpress_screen_only_when_accessible(): void {
		$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ) );
		$package  = new PackageProjection( 'release_asset' );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'data-ran-booster-managed-release-native-update>', $html );
		self::assertStringNotContainsString( 'data-ran-booster-managed-release-native-update-url=', $html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-wordpress-url="https://example.test/wp-admin/plugins.php"', $html );
		$GLOBALS['ran_booster_release_management_test_multisite'] = true;
		try {
			ob_start();
			$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
			$network_html = (string) ob_get_clean();
			$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'manage_network_plugins' );
			ob_start();
			$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
			$denied_html = (string) ob_get_clean();
		} finally {
			unset( $GLOBALS['ran_booster_release_management_test_multisite'], $GLOBALS['ran_booster_release_management_test_denied_capabilities'] );
		}
		self::assertStringContainsString( 'data-ran-booster-managed-release-wordpress-url="https://example.test/wp-admin/network/plugins.php"', $network_html );
		self::assertStringContainsString( 'data-ran-booster-managed-release-wordpress-url=""', $denied_html );
	}

	/** @return iterable<string,array{string,string,string,string,list<string>}> */
	public static function native_update_routes(): iterable {
		yield 'plugin' => array( 'plugin', 'example/example.php', 'plugins.php?action=update-selected', 'bulk-plugins', array( 'activate_plugins', 'update_plugins' ) );
		yield 'theme' => array( 'theme', 'example-theme', 'update.php?action=upgrade-theme', 'upgrade-theme_example-theme', array( 'update_themes' ) );
	}

	/** @param list<string> $required_capabilities */
	#[DataProvider( 'native_update_routes' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_native_update_renders_an_authorized_target_and_nonce_without_picker_activity( string $type, string $identifier, string $route, string $nonce_action, array $required_capabilities ): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		require_once __DIR__ . '/../../Support/PackageViewWordPressFunctions.php';
		$transient = 'plugin' === $type ? 'update_plugins' : 'update_themes';
		$entry     = array(
			$type         => $identifier,
			'new_version' => '2.0',
			'package'     => 'identity-bound-offer',
		);
		$GLOBALS['ran_booster_test_site_transients'][ $transient ] = (object) array(
			'response' => array( $identifier => 'plugin' === $type ? (object) $entry : $entry ),
		);
		$status   = ReleaseManagementFixture::status( 'release_asset', $type, update_available: true );
		$tracking = new ReleaseTrackingFacadeDouble( $status );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$render   = static function ( int $revision = 3 ) use ( $controls, $type, $identifier ): string {
			ob_start();
			$controls->render_package_operation_actions( 'release_asset', 'release_asset', $type, $identifier, $revision );
			return (string) ob_get_clean();
		};

		$html = $render();
		self::assertStringContainsString( 'href="https://example.test/wp-admin/' . $route, $html );
		self::assertStringContainsString( ( 'plugin' === $type ? 'plugins' : 'theme' ) . '=' . $identifier, rawurldecode( $html ) );
		self::assertStringContainsString( '_wpnonce=nonce-for-' . $nonce_action, $html );
		self::assertStringContainsString( '>Update to 2.0</a>', $html );

		$package         = new PackageProjection( 'release_asset', $type );
		$display         = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		$render_settings = static function () use ( $display, $package, $status ): string {
			ob_start();
			$display->render_settings( $package, $status, $package->settings_url() );
			return (string) ob_get_clean();
		};
		$status_html     = $render_settings();
		self::assertStringContainsString( 'version 2.0 is available.', $status_html );
		self::assertStringNotContainsString( 'Published release inspected', $status_html );
		self::assertSame( array(), $tracking->calls );

		foreach ( $required_capabilities as $capability ) {
			$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( $capability );
			self::assertStringNotContainsString( 'href=', $render() );
		}
		unset( $GLOBALS['ran_booster_release_management_test_denied_capabilities'] );
		self::assertStringNotContainsString( 'href=', $render( 4 ) );

		foreach (
			array(
				static fn (): bool => true,
				static fn (): bool => throw new \RuntimeException( 'Policy unavailable.' ),
			) as $policy
		) {
			$blocked = ReleaseManagementFixture::controls( $tracking, updates_forced_off: $policy );
			ob_start();
			$blocked->render_package_operation_actions( 'release_asset', 'release_asset', $type, $identifier, 3 );
			self::assertStringNotContainsString( 'href=', (string) ob_get_clean() );
			ob_start();
			$blocked->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
			$pane = (string) ob_get_clean();
			self::assertStringContainsString( 'Updates are off for this package.', $pane );
			self::assertStringNotContainsString( 'version 2.0 is available.', $pane );
		}
		unset( $GLOBALS['ran_booster_test_site_transients'][ $transient ] );
		self::assertStringNotContainsString( 'href=', $render() );
		self::assertStringContainsString( 'WordPress has no current update offer.', $render_settings() );
	}

	/** @return iterable<string,array{string,string,string,string}> */
	public static function native_update_repository_blocks(): iterable {
		foreach ( self::package_types() as $coordinates ) {
			list( $type, $identifier ) = $coordinates;
			foreach (
				array(
					'release_repository_conflict'     => 'Repository shared',
					'repository_release_owner_exists' => 'Repository shared',
					'repository_source_unavailable'   => 'Storage unavailable',
				) as $failure_code => $heading
			) {
				yield $type . ' ' . $failure_code => array( $type, $identifier, $failure_code, $heading );
			}
		}
	}

	#[DataProvider( 'native_update_repository_blocks' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_repository_block_suppresses_cached_offer_in_package_operations_and_release_track( string $type, string $identifier, string $failure_code, string $heading ): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		require_once __DIR__ . '/../../Support/PackageViewWordPressFunctions.php';
		$transient = 'plugin' === $type ? 'update_plugins' : 'update_themes';
		$entry     = array(
			$type         => $identifier,
			'new_version' => '2.0',
			'package'     => 'identity-bound-offer',
		);
		$GLOBALS['ran_booster_test_site_transients'][ $transient ] = (object) array(
			'response' => array( $identifier => 'plugin' === $type ? (object) $entry : $entry ),
		);

		$tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', $type, failure_code: $failure_code, update_available: true ) );
		$controls = ReleaseManagementFixture::controls( $tracking );
		$package  = new PackageProjection( 'release_asset', $type );

		self::assertSame( '2.0', \RAN\WordPress\ManagedReleaseTargetRegistrar::cached_offer_version( $type, $identifier, '1.0.0' ) );

		ob_start();
		$controls->render_advanced_source_section( 'edit', $type, 'release_asset', $package, $package->settings_url() );
		$pane = (string) ob_get_clean();
		self::assertStringContainsString( $heading, $pane );
		self::assertStringNotContainsString( 'version 2.0 is available.', $pane );

		ob_start();
		$controls->render_package_operation_actions( 'release_asset', 'release_asset', $type, $identifier, $package->source_revision() );
		$action = (string) ob_get_clean();
		self::assertStringNotContainsString( 'href=', $action );
		self::assertStringContainsString( 'aria-disabled="true" tabindex="-1" data-ran-booster-managed-release-native-update>Update now</a>', $action );
		self::assertSame( array(), $tracking->calls );
	}

	public function test_package_operation_actions_render_disabled_when_wordpress_holds_no_offer(): void {
		$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', update_available: true ) ) );

		ob_start();
		$controls->render_package_operation_actions( 'release_asset', 'release_asset', 'plugin', 'example/example.php', 3 );
		$html = trim( (string) ob_get_clean() );

		self::assertMatchesRegularExpression( '/^<a class="ran-wp-ui-reset-a ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-outline disabled ran-booster-managed-release-native-update" aria-disabled="true" tabindex="-1" data-ran-booster-managed-release-native-update>Update now<\/a>$/', $html );
		self::assertStringNotContainsString( 'href=', $html );

		foreach ( array( array( 'branch', 'branch' ), array( 'branch', 'release_asset' ), array( 'release_asset', 'branch' ) ) as $sources ) {
			ob_start();
			$controls->render_package_operation_actions( $sources[0], $sources[1], 'plugin', 'example/example.php', 3 );
			self::assertSame( '', trim( (string) ob_get_clean() ), implode( '/', $sources ) );
		}
	}

	#[DataProvider( 'package_types' )]
	public function test_managed_picker_callbacks_preserve_listing_observations_and_digest_failure( string $type, string $identifier ): void {
		$report = array(
			'scanned_count' => 3,
			'dropped_count' => 1,
			'drops'         => array(
				array(
					'release_identity' => '40',
					'tag'              => 'release-1.2',
					'reason'           => 'tag_not_version',
				),
			),
			'truncated'     => false,
		);
		foreach ( array( 'stable', 'prerelease' ) as $channel ) {
			$tracking                       = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', $type, channel: $channel ) );
			$controls                       = ReleaseManagementFixture::controls( $tracking );
			$request                        = $this->managed_request( 'list_candidates', $channel );
			$request['expected_type']       = $type;
			$request['expected_identifier'] = $identifier;
			$request['_wpnonce']            = 'nonce-for-release-tracking-list_candidates-' . $type . '-' . $identifier . '-3-' . $channel;
			foreach ( array( array(), array( new RepositoryReleaseCandidate( '42', 'v1.2.0', '1.2.0', false, '2026-08-20T09:00:00Z', array( 'example.zip' ) ) ) ) as $candidates ) {
				$tracking->candidate_list = new RepositoryReleaseCandidateList( $candidates, true, $report );
				$result                   = $controls->process_managed_browser_request( 'list_candidates', $request );
				self::assertTrue( $result['successful'] );
				self::assertSame( array() === $candidates ? 'no_releases' : 'release_candidates_available', $result['code'] );
				self::assertTrue( $result['data']['search_exhausted'] );
				self::assertSame( 3, $result['data']['diagnostics']['scanned_count'] );
				self::assertSame(
					array(
						array(
							'reason' => 'tag_not_version',
							'tag'    => 'release-1.2',
						),
					),
					$result['data']['diagnostics']['drops']
				);
			}
		}
		$tracking->candidate_inspection = new ReleaseTrackingPreflight( ReleaseTrackingPreflight::RELEASE_UNAVAILABLE, 'example', reason_code: 'digest_missing' );
		$request['_wpnonce']            = 'nonce-for-release-tracking-inspect_candidate-' . $type . '-' . $identifier . '-3-' . $channel;
		$request['release_id']          = '42';
		$request['release_tag']         = 'v1.2.0';
		$result                         = $controls->process_managed_browser_request( 'inspect_candidate', $request );
		self::assertFalse( $result['successful'] );
		self::assertSame( array( 'rejection_reason' => 'digest_missing' ), $result['data'] );
		$tracking->after_candidate_inspection = static function () use ( $tracking, $type ): void {
			$tracking->set_status( ReleaseManagementFixture::status( 'branch', $type ) );
		};
		$result                               = $controls->process_managed_browser_request( 'inspect_candidate', $request );
		self::assertSame( 'source_changed', $result['code'] );
		self::assertSame( array(), $result['data'] );
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
		self::assertStringNotContainsString( 'id="ran-booster-browse-track"', $html );
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

	public function test_return_to_branch_notices_distinguish_recovery_and_unavailable_readback(): void {
		$controls = ReleaseManagementFixture::controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) ),
			native_hold: static fn (): array => array(
				'type'       => 'plugin',
				'identifier' => 'example/example.php',
				'url'        => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh#ran-booster-native-hold',
			)
		);
		$package  = new PackageProjection( 'release_asset' );

		$this->set_conflict_result( 'branch_switch_held' );
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $package, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'A GitHub install hold blocked the switch to Branch.', $html );
		self::assertStringContainsString( '#ran-booster-native-hold">review the install hold</a>', $html );
		self::assertStringNotContainsString( 'The WordPress package updater lock blocked', $html );

		$this->set_conflict_result( 'branch_switch_unavailable' );
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $package, $package->settings_url() );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Booster could not verify the switch to Branch. Reload this page to check the current source.', $html );
		self::assertStringNotContainsString( 'The WordPress package updater lock blocked', $html );
	}

	public function test_success_result_shows_the_notice_and_no_switch_bar_on_the_now_active_source(): void {
		// Use releases succeeded: Releases is active, so its result renders at top of page with no switch bar inside Advanced.
		$tracking        = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', channel: 'stable', revision: 4 ) );
		$controls        = ReleaseManagementFixture::controls( $tracking );
		$package         = new PackageProjection( 'release_asset', revision_value: 4 );
		$_GET            = $this->query_of( $controls->process_admin_post_request( 'enable', $this->request( 'enable' ) + array( 'confirm_switch' => '1' ) ) );
		$_GET['page']    = 'ran-booster-plugins';
		$_GET['package'] = $package->identifier();
		ob_start();
		$controls->render_operation_notice();
		$top_html = (string) ob_get_clean();
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$releases_html = (string) ob_get_clean();
		self::assertStringContainsString( 'Switched to releases. Version 1.0.0 is installed. Updates are set to Manual.', $top_html );
		self::assertStringNotContainsString( 'Switched to releases.', $releases_html );
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
		self::assertStringContainsString( 'Switched to Branch. The installed files were kept. Review the Updates setting before enabling automatic branch deployments.', $branch_html );
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
		self::assertStringContainsString( 'Use Recheck eligibility to check published versions on Stable and Preview.', $branch );
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
			'no_releases'          => array( 'No eligible releases were found in this search.', 'Review the release picker for skipped releases and the selected track, then use Check releases.' ),
			'release_incompatible' => array( 'A published release was found, but it does not have exactly one package ZIP, or its header or Update URI does not match.', 'exactly one package ZIP asset' ),
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
		self::assertStringContainsString( 'Version 1.0.0 is installed. No newer eligible release was found.', $html );
		self::assertStringNotContainsString( 'data-ran-booster-release-status-notice', $html );
		self::assertStringNotContainsString( 'Why this happened and how to fix it', $html );
		self::assertStringNotContainsString( 'Use Check releases to look for a newer version.', $html );
	}

	/** @return array<string,array{0:string}> */
	public static function disabled_check_states(): array {
		return array(
			// The status line ignores what a check found, so a last-observed-newer and a last-observed-current package read the same.
			'checked'       => array( '2026-07-24T20:00:00+00:00' ),
			'never checked' => array( '' ),
		);
	}

	#[DataProvider( 'disabled_check_states' )]
	public function test_a_disabled_package_status_makes_no_claim_about_newer_releases( string $last_checked_at ): void {
		$html = $this->render_release_view(
			array(
				'deployment_policy' => 'disabled',
				'last_checked_at'   => $last_checked_at,
			)
		);

		self::assertStringContainsString( 'Version 1.0.0 is installed. Updates are off for this package.</span>', $html );
		self::assertStringNotContainsString( 'ewer eligible release', $html );
		self::assertStringNotContainsString( 'Use Check releases to look for a newer version.', $html );
	}

	public function test_a_disabled_package_status_and_its_newer_release_notice_do_not_contradict(): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		$disabled                             = ReleaseManagementFixture::status( 'release_asset', deployment_policy: 'disabled' );
		$tracking                             = new ReleaseTrackingFacadeDouble( $disabled );
		$tracking->refresh_result             = \RAN\AddOn\ReleaseTracking\ReleaseTrackingResult::failed( 'refresh_newer_while_off', 'Published release information was not offered.', detail: '0.2.2', check_reason: 'newer_release_observed' );
		$controls                             = ReleaseManagementFixture::controls( $tracking );
		$url                                  = $controls->process_admin_post_request( 'refresh', $this->request( 'refresh' ) );
		$query                                = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsing the already signed local PRG fixture.
		parse_str( $query, $_GET );
		ob_start();
		$controls->render_operation_notice();
		$notice = (string) ob_get_clean();
		$status = $this->readiness_panel( $this->render_release_view( array( 'deployment_policy' => 'disabled' ) ) );

		self::assertStringContainsString( 'Newer release 0.2.2 available. Updates are off for this package.', $notice );
		self::assertStringContainsString( 'Updates are off for this package.</span>', $status );
		self::assertStringNotContainsString( 'No newer', $status );
	}

	public function test_a_disabled_package_list_row_makes_no_claim_about_newer_releases(): void {
		$package = new PackageProjection( 'release_asset' );
		$status  = ReleaseManagementFixture::status( 'release_asset', deployment_policy: 'disabled' );
		$rows    = ( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->present_management( array( $package->identifier() => array() ), 'plugin', array( $package ), array( $package->identifier() => $status ) );

		self::assertSame( 'Version 1.0.0 is installed. Updates are off for this package.', $rows[ $package->identifier() ]['status'] );
	}

	#[DataProvider( 'update_uri_failure_pairs' )]
	public function test_an_update_uri_failure_the_eligibility_check_states_shows_one_sentence( string $eligibility_code, string $failure_code, string $sentence ): void {
		$generic = 'Published release status needs attention.';
		$package = new PackageProjection( 'release_asset' );
		$status  = ReleaseManagementFixture::status( 'release_asset', eligibility_code: $eligibility_code, failure_code: $failure_code );
		ob_start();
		( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->render_settings( $package, $status, $package->settings_url() );
		$html = (string) ob_get_clean();
		$rows = ( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->present_management( array( $package->identifier() => array() ), 'plugin', array( $package ), array( $package->identifier() => $status ) );

		self::assertSame( 1, substr_count( $html, $sentence ) );
		self::assertStringNotContainsString( 'data-ran-booster-release-status-notice', $html );
		self::assertStringNotContainsString( $generic, $html );
		self::assertStringNotContainsString( 'Why this happened and how to fix it', $html );
		self::assertSame( $sentence, $rows[ $package->identifier() ]['status'] );
	}

	/** @return array<string,array{string,string,string}> */
	public static function update_uri_failure_pairs(): array {
		return array(
			'missing'    => array( 'missing_update_uri', 'github_updater_installed_header_missing', 'This package header does not declare an Update URI for its configured repository.' ),
			'mismatched' => array( 'mismatched_update_uri', 'github_updater_installed_update_uri_mismatch', 'This package header Update URI does not match its configured repository.' ),
		);
	}

	#[DataProvider( 'unpaired_installed_failures' )]
	public function test_an_installed_failure_the_eligibility_check_does_not_state_keeps_the_generic_sentence( string $eligibility_code, string $failure_code ): void {
		$generic = 'Published release status needs attention.';
		$package = new PackageProjection( 'release_asset' );
		$status  = ReleaseManagementFixture::status( 'release_asset', eligibility_code: $eligibility_code, failure_code: $failure_code );
		ob_start();
		( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->render_settings( $package, $status, $package->settings_url() );
		$html = (string) ob_get_clean();
		$rows = ( new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay() )->present_management( array( $package->identifier() => array() ), 'plugin', array( $package ), array( $package->identifier() => $status ) );

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-release-status-notice' ) );
		self::assertStringContainsString( $generic, $html );
		self::assertStringContainsString( 'Why this happened and how to fix it', $html );
		self::assertSame( $generic, $rows[ $package->identifier() ]['status'] );
	}

	/** @return array<string,array{string,string}> */
	public static function unpaired_installed_failures(): array {
		return array(
			'eligible package'          => array( 'eligible', 'github_updater_installed_header_missing' ),
			'unreadable installed file' => array( 'missing_update_uri', 'github_updater_installed_file_unreadable' ),
			'other eligibility code'    => array( 'mismatched_update_uri', 'github_updater_installed_header_missing' ),
		);
	}

	public function test_manual_and_automatic_packages_keep_the_up_to_date_wording(): void {
		foreach ( array( 'manual', 'automatic' ) as $policy ) {
			self::assertStringContainsString( 'Version 1.0.0 is installed. No newer eligible release was found.</span>', $this->render_release_view( array( 'deployment_policy' => $policy ) ) );
		}
	}

	public function test_a_status_with_no_check_in_this_request_is_neutral_and_never_claims_it_was_never_checked(): void {
		$html = $this->render_release_view( array( 'last_checked_at' => '' ) );

		self::assertSame( 'is-neutral', $this->release_status_row_class( $html ) );
		self::assertStringContainsString( 'Use Check releases to look for a newer version.', $html );
		self::assertStringNotContainsString( 'have not been checked', $html );
		self::assertStringNotContainsString( 'ewer eligible release was found', $html );
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
		self::assertStringNotContainsString( 'data-ran-booster-managed-release-browser', $html );
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
				$request['confirm_switch']  = '1';
			}
			if ( 'change_channel' === $operation ) {
				$request['release_channel'] = 'prerelease';
			}

			$url = $controls->process_admin_post_request( $operation, $request );
			// A confirmed release operation travels as a flash, not as signed query arguments; the switch is settled where it lands.
			self::assertSame( 'enable' === $operation, str_contains( $url, 'ran_booster_release_result=release_enabled' ), $operation );
			self::assertStringContainsString( 'ran_booster_open_advanced=1', $url, $operation );
			self::assertStringNotContainsString( 'release_deployments', $url, $operation );
		}

		self::assertSame(
			array(
				array(
					'enable',
					'plugin',
					'example/example.php',
					3,
					'stable',
					$this->nonce( 'enable' ),
					'',
					'',
					array(
						'identity'          => 'rel-2.0.0',
						'tag'               => 'v2.0.0',
						'version'           => '2.0.0',
						'installed_version' => '1.0.0',
					),
				),
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
		self::assertSame( 'example/example.php', $this->query_of( $url )['package'] ?? null );
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

	/** @return array<string, array{string, string, string, string}> Code, intent, snackbar text, inline detail. */
	public static function check_results_that_did_not_succeed(): array {
		return array(
			'disabled'     => array( 'refresh_skipped_disabled', 'info', 'Check not run. Updates are off for this package.', 'Nothing is wrong. Turn updates on in the package settings to check for releases.' ),
			'forced off'   => array( 'refresh_skipped_forced_off', 'info', 'Check not run. Site configuration turns updates off.', 'Site configuration blocks updates for every package' ),
			'recent'       => array( 'refresh_recent', 'info', 'Checked a moment ago.', '' ),
			'newer'        => array( 'refresh_newer_while_off', 'info', 'Newer release available. Updates are off for this package.', '' ),
			'current'      => array( 'refresh_current_while_off', 'info', 'Checked: no newer release. Updates are off for this package.', '' ),
			'not admitted' => array( 'refresh_unchecked', 'warning', 'Releases were not checked. Use Check releases to retry.', '' ),
			'rejected'     => array( 'refresh_rejected', 'warning', 'The newest release cannot be used.', 'Activity records the reason.' ),
			'failed'       => array( 'refresh_check_failed', 'error', 'The release check failed.', 'see Activity.' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'check_results_that_did_not_succeed' )]
	public function test_a_check_that_did_not_succeed_is_one_inline_notice_the_host_reads( string $code, string $intent, string $text, string $detail ): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		$tracking                             = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->refresh_result             = \RAN\AddOn\ReleaseTracking\ReleaseTrackingResult::failed( $code, 'Published release information was not checked.', check_reason: 'policy_disabled' );
		$controls                             = ReleaseManagementFixture::controls( $tracking );
		$url                                  = $controls->process_admin_post_request( 'refresh', $this->request( 'refresh' ) );
		$query                                = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsing the already signed local PRG fixture.
		parse_str( $query, $_GET );

		// Any intent, info included, is one element: no flash item repeats the notice, so nothing depends on a stylesheet to hide a duplicate.
		self::assertSame( array(), $GLOBALS['ran_booster_feedback_test']['meta'] );
		ob_start();
		\RAN\Admin\Feedback\FeedbackFlash::render();
		self::assertSame( '', (string) ob_get_clean() );

		ob_start();
		$controls->render_operation_notice();
		$inline = (string) ob_get_clean();
		self::assertStringContainsString( 'data-feedback-key="' . $code . '" role="' . ( 'error' === $intent ? 'alert' : 'status' ) . '"', $inline );
		self::assertSame( 1, substr_count( $inline, 'data-feedback-key' ) );
		self::assertStringContainsString( 'data-ran-booster-ui-notice-intent="' . $intent . '"', $inline );
		self::assertStringContainsString( $text, $inline );
		self::assertStringContainsString( $detail, $inline );
		self::assertStringNotContainsString( 'notice-success', $inline );
	}

	public function test_a_newer_release_found_while_updates_are_off_names_its_version_in_the_notice(): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		$tracking                             = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->refresh_result             = \RAN\AddOn\ReleaseTracking\ReleaseTrackingResult::failed( 'refresh_newer_while_off', 'Published release information was not offered.', detail: '2.0.0-rc.1', check_reason: 'newer_release_observed' );
		$controls                             = ReleaseManagementFixture::controls( $tracking );
		$url                                  = $controls->process_admin_post_request( 'refresh', $this->request( 'refresh' ) );
		$query                                = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsing the already signed local PRG fixture.
		parse_str( $query, $_GET );

		ob_start();
		$controls->render_operation_notice();
		self::assertStringContainsString( '<p>Newer release 2.0.0-rc.1 available. Updates are off for this package.</p>', (string) ob_get_clean() );

		// A tampered version breaks the signature, so the signed notice does not render.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tampering with the parsed local fixture on purpose.
		$_GET['ran_booster_release_detail'] = '9.9.9';
		ob_start();
		$controls->render_operation_notice();
		self::assertStringNotContainsString( '9.9.9', (string) ob_get_clean() );
	}

	public function test_a_confirmed_check_releases_result_travels_as_a_flash_not_as_query_arguments(): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		$tracking                             = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$controls                             = ReleaseManagementFixture::controls( $tracking );
		$url                                  = $controls->process_admin_post_request( 'refresh', $this->request( 'refresh' ) );
		$query                                = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsing the already built local PRG fixture.
		parse_str( $query, $_GET );

		self::assertStringNotContainsString( 'ran_booster_release_', $url );
		self::assertStringNotContainsString( 'complete', strtolower( rawurldecode( $url ) ) );
		self::assertSame( array( 'page' => 'ran-booster-plugins' ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Asserting the parsed local PRG fixture.
		self::assertSame( array( 'release_check_current' ), $GLOBALS['ran_booster_feedback_test']['meta'][1][ \RAN\Admin\Feedback\FeedbackFlash::USER_META_KEY ]['codes'] );

		ob_start();
		$controls->render_operation_notice();
		self::assertSame( '', (string) ob_get_clean() );

		ob_start();
		\RAN\Admin\Feedback\FeedbackFlash::render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'data-feedback-key="release_check_current"', $html );
		self::assertStringContainsString( 'Release check complete. No newer eligible release was found.', $html );
		self::assertSame(
			array( array( 'refresh', 'plugin', 'example/example.php', 3, $this->nonce( 'refresh' ) ) ),
			$tracking->calls
		);
		self::assertSame( 2, $tracking->status_reads );
	}

	public function test_an_update_available_check_enqueues_the_update_code_and_no_redirect_arguments(): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		$tracking                             = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', update_available: true ) );
		$controls                             = ReleaseManagementFixture::controls( $tracking );

		$url = $controls->process_admin_post_request( 'refresh', $this->request( 'refresh' ) );

		self::assertStringNotContainsString( 'ran_booster_release_', $url );
		self::assertSame( array( 'release_check_update' ), $GLOBALS['ran_booster_feedback_test']['meta'][1][ \RAN\Admin\Feedback\FeedbackFlash::USER_META_KEY ]['codes'] );
		ob_start();
		\RAN\Admin\Feedback\FeedbackFlash::render();
		$html = (string) ob_get_clean();
		self::assertStringNotContainsString( 'No newer eligible release', $html );
		self::assertStringContainsString( 'Release check complete.', $html );
	}

	public function test_the_fallback_and_the_inline_status_end_with_the_same_outcome_sentence(): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		\RAN\Admin\Feedback\FeedbackFlash::enqueue( 'release_check_current' );
		ob_start();
		\RAN\Admin\Feedback\FeedbackFlash::render();
		$flash    = trim( (string) preg_replace( '/<[^>]*>/', '', (string) ob_get_clean() ) );
		$sentence = 'No newer eligible release was found.';

		self::assertStringEndsWith( $sentence, $flash );
		self::assertStringContainsString( $sentence . '</span>', $this->render_release_view( array() ) );
	}

	public function test_no_check_result_that_is_not_a_success_uses_the_flash(): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		$tracking                             = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', last_checked_at: '' ) );
		$controls                             = ReleaseManagementFixture::controls( $tracking );
		$warning                              = $controls->process_admin_post_request( 'refresh', $this->request( 'refresh' ) );

		self::assertSame( array(), $GLOBALS['ran_booster_feedback_test']['meta'] );
		$bad_nonce             = $this->request( 'refresh' );
		$bad_nonce['_wpnonce'] = 'wrong';
		$failure               = $controls->process_admin_post_request( 'refresh', $bad_nonce );

		self::assertStringContainsString( 'ran_booster_release_result=refresh_unchecked', $warning );
		self::assertStringContainsString( 'ran_booster_release_result=invalid_request', $failure );
		self::assertSame( array(), $GLOBALS['ran_booster_feedback_test']['meta'] );
	}

	/** @return array<string, array{string, string, string}> */
	public static function settings_operation_successes(): array {
		return array(
			'change track'     => array( 'change_channel', 'release_channel_changed', 'Release track changed. The installed package was not changed and Automatic was reset to Manual.' ),
			'return to branch' => array( 'return_to_branch', 'branch_restored', 'Switched to Branch. The installed files were kept. Review the Updates setting before enabling automatic branch deployments.' ),
		);
	}

	#[DataProvider( 'settings_operation_successes' )]
	public function test_a_confirmed_settings_operation_travels_as_a_flash_with_the_shared_copy( string $operation, string $code, string $copy ): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		$controls                             = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) );
		$request                              = $this->request( $operation );
		$request['release_channel']           = 'stable';

		$url = $controls->process_admin_post_request( $operation, $request );

		self::assertStringNotContainsString( 'ran_booster_release_', $url );
		self::assertSame( array( $code ), $GLOBALS['ran_booster_feedback_test']['meta'][1][ \RAN\Admin\Feedback\FeedbackFlash::USER_META_KEY ]['codes'] );
		ob_start();
		\RAN\Admin\Feedback\FeedbackFlash::render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'data-feedback-key="' . $code . '" data-intent="success" role="status">' . $copy . '</div>', $html );
		self::assertSame( $copy, \RAN\Admin\Feedback\FeedbackFlash::release_operation_success( $code ) );
	}

	/** @return array<string, array{string, string, string}> Source read back, shown text, absent text. */
	public static function switch_landings(): array {
		return array(
			'committed: read back on Releases'     => array( 'release_asset', 'Switched to releases. Version 1.0.0 is installed.', 'Booster hasn&#039;t confirmed' ),
			'refused or not yet committed: Branch' => array( 'branch', 'Booster hasn&#039;t confirmed the switch yet.', 'Switched to releases.' ),
		);
	}

	#[DataProvider( 'switch_landings' )]
	public function test_the_page_after_a_switch_says_enabled_only_when_it_reads_the_package_on_releases( string $source, string $shown, string $absent ): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		// The switch replied release_enabled; its source commits at the end of that request, or is refused there.
		$controls                   = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( $source, channel: 'stable', revision: 'release_asset' === $source ? 4 : 3 ) ) );
		$request                    = $this->request( 'enable' );
		$request['release_channel'] = 'stable';
		$request['confirm_switch']  = '1';

		$url = $controls->process_admin_post_request( 'enable', $request );

		self::assertSame( array(), $GLOBALS['ran_booster_feedback_test']['meta'] );
		self::assertStringContainsString( 'ran_booster_release_result=release_enabled', $url );
		self::assertStringNotContainsString( '#ran-booster-advanced-source-settings', $url );
		$query = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parsing the already signed local PRG fixture.
		parse_str( $query, $_GET );
		$_GET['page']    = 'ran-booster-plugins';
		$_GET['package'] = 'example/example.php';
		// The switch lands on top of the package's settings page, which reads the package's status for this result.
		$package = new PackageProjection( $source );
		ob_start();
		$controls->render_operation_notice();
		$top_notice = (string) ob_get_clean();
		self::assertStringContainsString( $shown, $top_notice );
		self::assertStringNotContainsString( $absent, $top_notice );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', $source, $package, $package->settings_url() );
		$page = (string) ob_get_clean();
		self::assertStringNotContainsString( $shown, $page );
	}

	public function test_a_failed_settings_operation_keeps_its_inline_result_and_sets_no_flash(): void {
		$GLOBALS['ran_booster_feedback_test'] = array( 'meta' => array() );
		$controls                             = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) );
		$request                              = $this->request( 'enable' );
		$request['_wpnonce']                  = 'wrong';

		$url = $controls->process_admin_post_request( 'enable', $request );

		self::assertStringContainsString( 'ran_booster_release_result=invalid_request', $url );
		self::assertSame( array(), $GLOBALS['ran_booster_feedback_test']['meta'] );
	}

	/** @return array<string, array{string, string, string, string, string}> */
	public static function failed_enable_reasons(): array {
		return array(
			'no published release'             => array( 'no_releases', '', 'No eligible releases were found in this search.', 'Review the release picker for skipped releases and the selected track, then use Check releases.', 'release_unavailable' ),
			'missing published digest'         => array( 'digest_missing', '', 'The release ZIP has no published digest.', 'Publish the release ZIP again so its digest is available, then use Check releases.', 'release_unavailable' ),
			'no package ZIP'                   => array( 'package_asset_missing', 'v0.4.0', 'Release v0.4.0 has no .zip package asset.', 'Attach exactly one .zip package to the release, then use Check releases.', 'release_unavailable' ),
			'more than one package ZIP'        => array( 'package_asset_ambiguous', 'v0.4.0', 'Release v0.4.0 has more than one .zip package asset.', 'Keep exactly one .zip package on the release, then use Check releases.', 'release_unavailable' ),
			'one ZIP, cause inside it'         => array( 'release_incompatible', 'v0.4.0', 'does not have exactly one package ZIP, or its header or Update URI does not match.', 'exactly one package ZIP asset', 'release_unavailable' ),
			'ZIP named without its tag'        => array( 'package_asset_missing', '', 'does not have exactly one package ZIP, or its header or Update URI does not match.', 'Make sure the release has exactly one package ZIP asset', 'release_unavailable' ),
			'folder or main file mismatch'     => array( 'release_identity_mismatch', 'wrong-dir/wrong.php', 'The ZIP’s folder or main file (wrong-dir/wrong.php) does not match this package.', 'Publish a ZIP whose folder and main file name match this package, then use Check releases.', 'invalid_release_assets' ),
			'identity mismatch without a name' => array( 'release_identity_mismatch', '', 'The published release assets could not be validated.', 'Review the release, repository access', 'invalid_release_assets' ),
			'no reason (source guard)'         => array( '', '', 'Booster could not confirm an eligible published release for this track.', 'make sure it has one package ZIP', 'release_unavailable' ),
		);
	}

	#[DataProvider( 'failed_enable_reasons' )]
	public function test_failed_enable_reason_survives_the_signed_redirect_and_selects_the_displayed_copy( string $reason, string $detail, string $message, string $action, string $code ): void {
		$html = $this->render_enable_failure( $reason, $url, $detail, $code );

		self::assertSame( '' !== $reason, str_contains( $url, 'ran_booster_release_reason=' . $reason ) );
		self::assertSame( '' === $reason, ! str_contains( $url, 'ran_booster_release_reason' ) );
		self::assertSame( '' !== $detail, str_contains( $url, 'ran_booster_release_detail=' ) );
		self::assertStringContainsString( $message, $html );
		self::assertStringContainsString( $action, $html );
		self::assertStringContainsString( '<code>' . $code . '</code>', $html );
		if ( '' !== $reason ) {
			self::assertStringNotContainsString( 'Booster could not confirm an eligible published release', $html );
		}
	}

	public function test_a_forged_swapped_added_removed_or_oversized_detail_is_never_displayed(): void {
		$this->render_enable_failure( 'package_asset_missing', $url, 'v0.4.0' );
		$signed = $this->query_of( $url );
		$this->render_enable_failure( 'package_asset_missing', $reason_only_url );
		$reason_only = $this->query_of( $reason_only_url );
		$this->render_enable_failure( '', $plain_url );
		$plain = $this->query_of( $plain_url );

		$forgeries = array(
			'swapped'          => array( 'ran_booster_release_detail' => 'v9.9.9' ) + $signed,
			'removed'          => array_diff_key( $signed, array( 'ran_booster_release_detail' => true ) ),
			'added'            => $reason_only + array( 'ran_booster_release_detail' => 'v0.4.0' ),
			'added, no reason' => $plain + array( 'ran_booster_release_detail' => 'v0.4.0' ),
			'oversize'         => array( 'ran_booster_release_detail' => str_repeat( 'a', 121 ) ) + $signed,
			'control char'     => array( 'ran_booster_release_detail' => "v0.4.0\n" ) + $signed,
			'non ASCII'        => array( 'ran_booster_release_detail' => "v0.4.\u{202E}0" ) + $signed,
			'array'            => array( 'ran_booster_release_detail' => array( 'v0.4.0' ) ) + $signed,
		);
		foreach ( $forgeries as $name => $query ) {
			$_GET     = $query;
			$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) );
			$package  = new PackageProjection();
			ob_start();
			$controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $package, $package->settings_url() );
			$html = (string) ob_get_clean();
			self::assertStringNotContainsString( 'v9.9.9', $html, $name );
			self::assertStringNotContainsString( 'has no .zip package asset', $html, $name );
			self::assertStringNotContainsString( 'v0.4.', $html, $name );
		}
	}

	public function test_a_detail_only_allows_characters_that_survive_a_redirect_query_string(): void {
		// WordPress add_query_arg does not encode new values and wp_safe_redirect strips characters outside a
		// fixed set, so a detail with + & # % " < > = ; \' $ would read back changed and fail the signature,
		// silently dropping the whole notice. Simulate both steps for every printable ASCII character.
		foreach ( range( 0x20, 0x7E ) as $ordinal ) {
			$value    = 'a' . chr( $ordinal ) . 'b';
			$location = str_replace( ' ', '%20', 'https://example.test/wp-admin/admin.php?k=' . $value );
			$location = (string) preg_replace( '/[^a-z0-9-~+_.?#=!&;,\/:%@$\|*\'()\[\]\\\\x80-\\xff]/i', '', $location );
			parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $parsed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
			if ( '' !== ReleaseTrackingPreflight::detail( $value ) ) {
				self::assertSame( $value, $parsed['k'] ?? null, 'The allowed character ' . chr( $ordinal ) . ' must survive the redirect unchanged.' );
			}
		}
		foreach ( array( 'v1.0.0+build.1', 'a&b', 'a#b', 'a%b', 'a"b', '<b>v1</b>', 'a=b', 'a;b', "a'b", 'a$b' ) as $unsafe ) {
			self::assertSame( '', ReleaseTrackingPreflight::detail( $unsafe ), $unsafe );
		}
		foreach ( array( 'v0.4.0', 'v1.0.0-rc.1', 'example/example.php', 'found 1.2.0, expected 1.2.3' ) as $safe ) {
			self::assertSame( $safe, ReleaseTrackingPreflight::detail( $safe ), $safe );
		}
	}

	public function test_a_result_cannot_carry_an_unsafe_detail(): void {
		foreach ( array( str_repeat( 'a', 121 ), "v1\n", "v1\u{200B}", 'vé' ) as $detail ) {
			try {
				ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.', 'package_asset_missing', $detail );
				self::fail( 'An unsafe detail was accepted.' );
			} catch ( \InvalidArgumentException ) {
				self::assertSame( '', ReleaseTrackingPreflight::detail( $detail ) );
			}
		}
		self::assertSame( 'v0.4.0', ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.', 'package_asset_missing', 'v0.4.0' )->detail() );
	}

	public function test_a_result_without_reason_or_detail_still_verifies_against_the_original_signed_payload(): void {
		$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) );
		$method   = new \ReflectionMethod( $controls, 'result_nonce_action' );
		$original = 'ran-booster-result-' . hash( 'sha256', (string) wp_json_encode( array( 'release_enabled', true, 'plugin', 'example/example.php', 'stable' ) ) );

		self::assertSame( $original, $method->invoke( $controls, 'release_enabled', true, 'plugin', 'example/example.php', 'stable' ) );
		self::assertNotSame( $original, $method->invoke( $controls, 'release_enabled', true, 'plugin', 'example/example.php', 'stable', '', 'v1' ) );
		self::assertNotSame(
			$method->invoke( $controls, 'release_enabled', true, 'plugin', 'example/example.php', 'stable', 'package_asset_missing' ),
			$method->invoke( $controls, 'release_enabled', true, 'plugin', 'example/example.php', 'stable', 'package_asset_missing', 'v1' )
		);
	}

	public function test_a_forged_added_removed_or_unknown_reason_is_never_displayed(): void {
		$this->render_enable_failure( 'release_incompatible', $url );
		$signed = $this->query_of( $url );
		$this->render_enable_failure( '', $plain_url );
		$plain = $this->query_of( $plain_url );

		$forgeries = array(
			'swapped'  => array( 'ran_booster_release_reason' => 'no_releases' ) + $signed,
			'removed'  => array_diff_key( $signed, array( 'ran_booster_release_reason' => true ) ),
			'added'    => $plain + array( 'ran_booster_release_reason' => 'release_incompatible' ),
			'unknown'  => array( 'ran_booster_release_reason' => 'bogus_reason' ) + $signed,
			'oversize' => array( 'ran_booster_release_reason' => str_repeat( 'a', 300 ) ) + $signed,
			'array'    => array( 'ran_booster_release_reason' => array( 'release_incompatible' ) ) + $signed,
		);
		foreach ( $forgeries as $name => $query ) {
			$_GET     = $query;
			$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) );
			$package  = new PackageProjection();
			ob_start();
			$controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $package, $package->settings_url() );
			$html = (string) ob_get_clean();
			self::assertStringNotContainsString( 'Booster could not confirm an eligible published release', $html, $name );
			self::assertStringNotContainsString( 'single package ZIP asset', $html, $name );
			self::assertStringNotContainsString( 'No published release was found', $html, $name );
		}
	}

	public function test_a_result_cannot_carry_a_reason_outside_the_preflight_allow_list(): void {
		$this->expectException( \InvalidArgumentException::class );
		ReleaseTrackingResult::failed( 'release_unavailable', 'Release tracking could not be enabled.', 'not_a_reason' );
	}

	public function test_disabled_and_forced_off_switch_bar_disables_use_releases_without_preflight(): void {
		$package          = new PackageProjection();
		$disabled_status  = ReleaseManagementFixture::status( 'branch', 'plugin', deployment_policy: 'disabled' );
		$disabled_control = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( $disabled_status ) );

		ob_start();
		$disabled_control->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$disabled_html = (string) ob_get_clean();
		self::assertStringContainsString( 'Updates are disabled for this package. Set Updates to Manual to switch.', $disabled_html );
		self::assertStringContainsString( 'data-ran-booster-use-releases-ready="false" aria-disabled="true"', $disabled_html );
		self::assertStringNotContainsString( 'data-ran-booster-switch-preflight-url', $disabled_html );
		self::assertStringNotContainsString( 'source transition controls are temporarily unavailable', $disabled_html );

		$manual_status      = ReleaseManagementFixture::status( 'branch', 'plugin', deployment_policy: 'manual' );
		$forced_off_control = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( $manual_status ), updates_forced_off: static fn (): bool => true );

		ob_start();
		$forced_off_control->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$forced_html = (string) ob_get_clean();
		self::assertStringContainsString( 'Updates are turned off by this site&#039;s configuration, so this package can&#039;t switch to releases.', $forced_html );
		self::assertStringContainsString( 'data-ran-booster-use-releases-ready="false" aria-disabled="true"', $forced_html );
		self::assertStringNotContainsString( 'data-ran-booster-switch-preflight-url', $forced_html );
		self::assertStringNotContainsString( 'source transition controls are temporarily unavailable', $forced_html );
	}

	public function test_ready_switch_bar_exposes_preflight_ajax_attributes_and_channel_nonces(): void {
		$package  = new PackageProjection();
		$status   = ReleaseManagementFixture::status( 'branch', 'plugin', deployment_policy: 'manual' );
		$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( $status ) );

		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-use-releases-ready="true" aria-disabled="false"', $html );
		self::assertStringContainsString( 'data-ran-booster-switch-preflight-url="https://example.test/wp-admin/admin-ajax.php"', $html );
		self::assertStringContainsString( 'data-ran-booster-switch-preflight-action="ran_booster_release_switch_preflight"', $html );
		if ( 1 !== preg_match( '/data-ran-booster-switch-preflight-nonces="([^"]+)"/', $html, $matches ) ) {
			self::fail( 'Expected preflight nonces attribute.' );
		}
		self::assertSame(
			array(
				'stable'     => 'nonce-for-release-tracking-preflight-plugin-example/example.php-3-stable',
				'prerelease' => 'nonce-for-release-tracking-preflight-plugin-example/example.php-3-prerelease',
			),
			json_decode( html_entity_decode( $matches[1], ENT_QUOTES ), true )
		);
	}

	public function test_switch_preflight_ajax_returns_versions_and_enforces_policy_hold_and_authority(): void {
		$preflight_request = function ( string $channel = 'stable', string $nonce_override = '' ): array {
			$action = 'release-tracking-preflight-plugin-example/example.php-3-' . $channel;
			return array(
				'expected_type'            => 'plugin',
				'expected_identifier'      => 'example/example.php',
				'expected_source_revision' => '3',
				'release_channel'          => $channel,
				'_wpnonce'                 => '' !== $nonce_override ? $nonce_override : 'nonce-for-' . $action,
			);
		};

		foreach ( array(
			'newer' => '2.0.0',
			'same'  => '1.0.0',
			'older' => '0.9.0',
		) as $relationship => $version ) {
			$tracking                 = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'branch', 'plugin' ) );
			$tracking->candidate_list = $this->switch_candidates( $version );
			$controls                 = ReleaseManagementFixture::controls( $tracking );
			$response                 = $controls->process_switch_preflight_request( $preflight_request( 'prerelease' ) );

			self::assertTrue( $response['successful'], $relationship );
			self::assertSame( 'ready', $response['code'] );
			self::assertSame( '1.0.0', $response['data']['installed_version'] );
			self::assertSame( $version, $response['data']['release_version'] );
			self::assertSame( 'rel-' . $version, $response['data']['release_identity'] );
			self::assertSame( 'v' . $version, $response['data']['release_tag'] );
			self::assertSame( $relationship, $response['data']['version_relationship'] );
			self::assertSame( 'prerelease', $response['data']['channel'] );
			self::assertNotEmpty( $response['data']['confirmed_release_nonce'] );

		}

		$unavailable          = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$unavailable_controls = ReleaseManagementFixture::controls( $unavailable );
		self::assertSame( 'unable_to_check', $unavailable_controls->process_switch_preflight_request( $preflight_request() )['code'] );
		$unavailable->anonymous_rate_limit_provider = 'github';
		$rate_limit                                 = $unavailable_controls->process_switch_preflight_request( $preflight_request() );
		self::assertSame( 'lookup_rate_limited', $rate_limit['code'] );
		self::assertStringContainsString( 'rate-limited this public release lookup', $rate_limit['data']['message'] );

		// Invalid nonce & missing capability.
		$controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) );
		self::assertSame( 'invalid_request', $controls->process_switch_preflight_request( $preflight_request( 'stable', 'wrong' ) )['code'] );
		$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'update_plugins' );
		self::assertSame( 'forbidden', $controls->process_switch_preflight_request( $preflight_request() )['code'] );
		$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array();

		// Disabled & Forced-off & Hold (same readable, same unreadable, other held) & Diagnostic.
		$disabled_controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'branch', 'plugin', deployment_policy: 'disabled' ) ) );
		$disabled_res      = $disabled_controls->process_switch_preflight_request( $preflight_request() );
		self::assertFalse( $disabled_res['successful'] );
		self::assertSame( 'policy_disabled', $disabled_res['code'] );
		self::assertSame( 'Updates are disabled for this package. Set Updates to Manual to switch.', $disabled_res['data']['message'] );

		$forced_res = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ), updates_forced_off: static fn (): bool => true )
			->process_switch_preflight_request( $preflight_request() );
		self::assertSame( 'policy_forced_off', $forced_res['code'] );
		self::assertStringContainsString( 'turned off by this site\'s configuration', $forced_res['data']['message'] );

		$readable_hold = ReleaseManagementFixture::controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ),
			native_hold: static fn (): array => array(
				'type'       => 'plugin',
				'identifier' => 'example/example.php',
				'readable'   => true,
				'name'       => 'Example Plugin',
				'url'        => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php#ran-booster-native-hold',
			)
		)->process_switch_preflight_request( $preflight_request() );
		self::assertSame( 'switch_held', $readable_hold['code'] );
		self::assertSame( '#ran-booster-native-hold', $readable_hold['data']['link_url'] );
		self::assertSame( 'release the hold', $readable_hold['data']['link_label'] );

		$unreadable_hold = ReleaseManagementFixture::controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ),
			native_hold: static fn (): array => array(
				'type'       => 'plugin',
				'identifier' => 'example/example.php',
				'readable'   => false,
				'name'       => 'Example Plugin',
				'url'        => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh#ran-booster-native-hold',
			)
		)->process_switch_preflight_request( $preflight_request() );
		self::assertSame( 'switch_held', $unreadable_hold['code'] );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh#ran-booster-native-hold', $unreadable_hold['data']['link_url'] );

		$other_hold = ReleaseManagementFixture::controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ),
			native_hold: static fn (): array => array(
				'type'       => 'plugin',
				'identifier' => 'other/other.php',
				'readable'   => true,
				'name'       => 'Other Plugin',
				'url'        => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=other%2Fother.php#ran-booster-native-hold',
			)
		)->process_switch_preflight_request( $preflight_request() );
		self::assertSame( 'switch_other_held', $other_hold['code'] );
		self::assertSame( 'Other Plugin', $other_hold['data']['link_label'] );
		self::assertStringContainsString( 'package=other%2Fother.php#ran-booster-native-hold', $other_hold['data']['link_url'] );

		$no_releases_tracking                 = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$no_releases_tracking->candidate_list = new RepositoryReleaseCandidateList( array() );
		$no_releases_res                      = ReleaseManagementFixture::controls( $no_releases_tracking )->process_switch_preflight_request( $preflight_request() );
		self::assertFalse( $no_releases_res['successful'] );
		self::assertSame( 'release_unavailable', $no_releases_res['code'] );
		self::assertSame( 'No eligible releases were found in this search.', $no_releases_res['data']['message'] );
	}

	private function switch_candidates( string ...$versions ): RepositoryReleaseCandidateList {
		require_once dirname( __DIR__, 3 ) . '/vendor/ran/wp-release-updater/src/Contract/ReleaseVersion.php';
		return new RepositoryReleaseCandidateList(
			array_map( static fn ( string $version ): RepositoryReleaseCandidate => new RepositoryReleaseCandidate( 'rel-' . $version, 'v' . $version, $version, false, '2026-06-01T00:00:00Z', array( 'plugin.zip' ) ), $versions )
		);
	}

	public function test_chooser_skips_metadata_incompatible_zip_count_without_inspecting_and_preserves_selected_identity(): void {
		$tracking                   = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$current                    = $this->switch_candidates( '1.0.0' )->candidates[0];
		$ambiguous                  = new RepositoryReleaseCandidate( 'rel-2', 'v2.0', '2.0', false, '2026-06-02T00:00:00Z', array( 'one.zip', 'two.zip' ) );
		$tracking->candidate_list   = new RepositoryReleaseCandidateList( array( $ambiguous, $current ) );
		$controls                   = ReleaseManagementFixture::controls( $tracking );
		$request                    = $this->request( 'enable' );
		$request['release_channel'] = 'stable';
		$ajax                       = $request;
		$ajax['_wpnonce']           = 'nonce-for-release-tracking-preflight-plugin-example/example.php-3-stable';
		$response                   = $controls->process_switch_preflight_request( $ajax );
		self::assertTrue( $response['successful'] );
		$data = $response['data'];
		self::assertSame( 'rel-1.0.0', $data['release_identity'] );
		self::assertArrayNotHasKey( 'matching_release', $data );
		self::assertStringContainsString( '2.0', $data['fallback_message'] );
		$tracking->candidate_list = $this->switch_candidates( '2.0.0', '1.0.0' );
		foreach ( array( 'identity', 'tag', 'version' ) as $field ) {
			$request[ 'confirmed_release_' . $field ] = $data[ 'release_' . $field ];
		}
		$request['confirmed_release_nonce']     = $data['confirmed_release_nonce'];
		$request['confirmed_installed_version'] = $data['confirmed_installed_version'];
		$request['confirm_switch']              = '1';
		$request['switch_release_choice']       = 'installed';
		$controls->process_admin_post_request( 'enable', $request );
		self::assertSame( array( 'list_candidates', 'enable' ), array_column( $tracking->calls, 0 ) );
		self::assertSame( 'rel-1.0.0', $tracking->calls[1][8]['identity'] );
	}

	public function test_native_switch_submits_one_exact_signed_choice_and_refuses_tampering(): void {
		foreach ( array(
			'installed' => '1.0.0',
			'latest'    => '2.0.0',
			'older'     => '0.9.0',
		) as $choice => $version ) {
			$tracking                 = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
			$tracking->candidate_list = 'older' === $choice ? $this->switch_candidates( $version ) : $this->switch_candidates( '1.0.0', '2.0.0' );
			$controls                 = ReleaseManagementFixture::controls( $tracking );
			$url                      = $controls->process_admin_post_request( 'enable', $this->request( 'enable' ) );
			$_GET                     = $this->query_of( $url );
			$_GET['page']             = 'ran-booster-plugins';
			$_GET['package']          = 'example/example.php';
			$package                  = new PackageProjection( 'branch' );
			ob_start();
			$controls->render_advanced_source_section( 'edit', 'plugin', 'release_asset', $package, $package->settings_url() );
			$html = (string) ob_get_clean();
			$dom  = new \DOMDocument();
			$dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
			$xpath = new \DOMXPath( $dom );
			$forms = $xpath->query( '//div[@data-ran-booster-switch-confirmation]//form' );
			self::assertNotFalse( $forms );
			self::assertCount( 'older' === $choice ? 1 : 2, $forms );
			self::assertSame( array( 'list_candidates' ), array_column( $tracking->calls, 0 ) );
			$fields = $xpath->query( '//div[@data-ran-booster-switch-confirmation]//form[input[@name="confirmed_release_version" and @value="' . $version . '"]]//input' );
			self::assertNotFalse( $fields );
			$request = array();
			foreach ( $fields as $field ) {
				if ( $field instanceof \DOMElement && 'checkbox' !== $field->getAttribute( 'type' ) ) {
					$request[ $field->getAttribute( 'name' ) ] = $field->getAttribute( 'value' );
				}
			}
			foreach ( array( 'confirmed_release_identity', 'confirmed_release_tag', 'confirmed_release_version', 'confirmed_release_nonce', 'confirmed_installed_version', 'confirmed_release_older', 'release_channel', 'expected_source_revision' ) as $key ) {
				$tampered         = $request;
				$tampered[ $key ] = 'changed';
				$controls->process_admin_post_request( 'enable', $tampered );
			}
			$GLOBALS['ran_booster_release_management_test_nonce_age'] = 2;
			$controls->process_admin_post_request( 'enable', $request );
			unset( $GLOBALS['ran_booster_release_management_test_nonce_age'] );
			if ( 'older' === $choice ) {
				$controls->process_admin_post_request( 'enable', $request );
				$request['confirm_older_release'] = '1';
			}
			self::assertSame( array( 'list_candidates' ), array_column( $tracking->calls, 0 ) );
			$controls->process_admin_post_request( 'enable', $request );
			self::assertSame( array( 'list_candidates', 'enable' ), array_column( $tracking->calls, 0 ) );
			self::assertSame(
				array(
					'identity'          => 'rel-' . $version,
					'tag'               => 'v' . $version,
					'version'           => $version,
					'installed_version' => '1.0.0',
				),
				$tracking->calls[1][8]
			);
			self::assertSame( 'older' === $choice ? 'rel-' . $version : '', $tracking->calls[1][6] );
		}
	}

	public function test_post_switch_top_of_page_outcomes_and_settled_states(): void {
		// A signed refusal before WordPress starts permits the unchanged notice.
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$identifier              = 'theme' === $type ? 'example-theme' : 'example/example.php';
			$tracking                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'branch', $type ) );
			$tracking->enable_result = ReleaseTrackingResult::failed( 'switch_refused', 'Install refused.' );
			$controls                = ReleaseManagementFixture::controls( $tracking );
			$nonce                   = 'nonce-for-release-tracking-enable-' . $type . '-' . $identifier . '-3';
			$url                     = $controls->process_admin_post_request(
				'enable',
				array(
					'expected_type'            => $type,
					'expected_identifier'      => $identifier,
					'expected_source_revision' => '3',
					'_wpnonce'                 => $nonce,
					'release_channel'          => 'stable',
					'confirm_switch'           => '1',
				) + $this->confirmed_release_fields( $type, $identifier, 'stable' )
			);
			self::assertStringContainsString( 'ran_booster_release_result=switch_refused', $url );
			self::assertStringNotContainsString( '#ran-booster-advanced-source-settings', $url );

			$_GET            = $this->query_of( $url );
			$_GET['page']    = 'theme' === $type ? 'ran-booster-themes' : 'ran-booster-plugins';
			$_GET['package'] = $identifier;
			ob_start();
			$controls->render_operation_notice();
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'Install couldn&#039;t complete before any files changed. This ' . $type . ' is still on Branch, version 1.0.0. Choose Use releases to try again.', $html );
		}

		// A Releases row alone cannot turn an uncertain result into success.
		$uncertain_tracking                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'branch', 'plugin' ) );
		$uncertain_tracking->enable_result = ReleaseTrackingResult::failed( 'management_state_uncertain', 'Uncertain.' );
		$uncertain_controls                = ReleaseManagementFixture::controls( $uncertain_tracking );
		$uncertain_url                     = $uncertain_controls->process_admin_post_request(
			'enable',
			$this->request( 'enable' ) + array(
				'release_channel' => 'stable',
				'confirm_switch'  => '1',
			)
		);
		self::assertStringContainsString( 'ran_booster_release_result=switch_uncertain', $uncertain_url );
		self::assertStringNotContainsString( '#ran-booster-advanced-source-settings', $uncertain_url );

		$_GET            = $this->query_of( $uncertain_url );
		$_GET['page']    = 'ran-booster-plugins';
		$_GET['package'] = 'example/example.php';
		ob_start();
		$released_tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', 'plugin', revision: 4 ) );
		ReleaseManagementFixture::controls( $released_tracking )->render_operation_notice();
		$settled_released = (string) ob_get_clean();
		self::assertStringContainsString( 'Booster hasn&#039;t confirmed the switch yet.', $settled_released );
		self::assertStringNotContainsString( 'Switched to releases.', $settled_released );

		$success_url   = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) )->process_admin_post_request(
			'enable',
			$this->request( 'enable' ) + array(
				'release_channel' => 'stable',
				'confirm_switch'  => '1',
			)
		);
		$success_query = $this->query_of( $success_url );
		self::assertSame( 'release_enabled', $success_query['ran_booster_release_result'] );
		self::assertSame( '3', $success_query['ran_booster_release_detail'] );
		$_GET = $success_query + array(
			'page'    => 'ran-booster-plugins',
			'package' => 'example/example.php',
		);
		ob_start();
		ReleaseManagementFixture::controls( $released_tracking )->render_operation_notice();
		self::assertStringContainsString( 'Switched to releases. Version 1.0.0 is installed.', (string) ob_get_clean() );
		$hold_read_failed = ReleaseManagementFixture::controls(
			$released_tracking,
			native_hold: static fn () => throw new \RuntimeException( 'Unavailable.' )
		);
		ob_start();
		$hold_read_failed->render_operation_notice();
		self::assertStringContainsString( 'Booster hasn&#039;t confirmed the switch yet.', (string) ob_get_clean() );
		$released_tracking->throw_on_status = true;
		ob_start();
		ReleaseManagementFixture::controls( $released_tracking )->render_operation_notice();
		self::assertStringContainsString( 'Booster hasn&#039;t confirmed the switch yet.', (string) ob_get_clean() );
		$superseded_tracking = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset', 'plugin', revision: 6 ) );
		ob_start();
		ReleaseManagementFixture::controls( $superseded_tracking )->render_operation_notice();
		self::assertStringContainsString( 'This switch result no longer matches the current package settings.', (string) ob_get_clean() );
		$_GET['ran_booster_release_detail'] = '5';
		ob_start();
		ReleaseManagementFixture::controls( $superseded_tracking )->render_operation_notice();
		self::assertStringNotContainsString( 'Switched to releases.', (string) ob_get_clean() );

		$_GET = $this->query_of( $uncertain_url ) + array(
			'page'    => 'ran-booster-plugins',
			'package' => 'example/example.php',
		);

		// switch_not_confirmed links to clean reload URL (no result args or hash fragment).
		$unconfirmed_controls = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'branch', 'plugin' ) ) );
		ob_start();
		$unconfirmed_controls->render_operation_notice();
		$unconfirmed_html = (string) ob_get_clean();
		self::assertStringContainsString( 'Booster hasn&#039;t confirmed the switch yet.', $unconfirmed_html );
		self::assertStringContainsString( '<a href="https://example.test/wp-admin/admin.php?page=ran-booster-plugins&amp;package=example/example.php">Reload this page</a>', rawurldecode( $unconfirmed_html ) );
		self::assertStringContainsString( 'is-warning', $unconfirmed_html );

		// switch_held (readable & unreadable) and switch_other_held.
		$held_readable = ReleaseManagementFixture::controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'branch', 'plugin' ) ),
			native_hold: static fn (): array => array(
				'type'       => 'plugin',
				'identifier' => 'example/example.php',
				'readable'   => true,
				'name'       => 'Example Plugin',
				'url'        => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php#ran-booster-native-hold',
			)
		);
		ob_start();
		$held_readable->render_operation_notice();
		$held_readable_html = (string) ob_get_clean();
		self::assertStringContainsString( 'Install couldn&#039;t complete. Check this plugin, then <a href="#ran-booster-native-hold">release the hold</a>.', $held_readable_html );

		$theme_tracking                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'branch', 'theme' ) );
		$theme_tracking->enable_result = ReleaseTrackingResult::failed( 'native_hold', 'Held.' );
		$held_unreadable               = ReleaseManagementFixture::controls(
			$theme_tracking,
			native_hold: static fn (): array => array(
				'type'       => 'theme',
				'identifier' => 'example-theme',
				'readable'   => false,
				'name'       => 'example-theme',
				'url'        => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh#ran-booster-native-hold',
			)
		);
		$held_theme_url                = $held_unreadable->process_admin_post_request(
			'enable',
			array(
				'expected_type'            => 'theme',
				'expected_identifier'      => 'example-theme',
				'expected_source_revision' => '3',
				'_wpnonce'                 => 'nonce-for-release-tracking-enable-theme-example-theme-3',
				'release_channel'          => 'stable',
				'confirm_switch'           => '1',
			) + $this->confirmed_release_fields( 'theme', 'example-theme', 'stable' )
		);
		$_GET                          = $this->query_of( $held_theme_url );
		$_GET['page']                  = 'ran-booster-themes';
		$_GET['package']               = 'example-theme';
		ob_start();
		$held_unreadable->render_operation_notice();
		$held_unreadable_html = (string) ob_get_clean();
		self::assertStringContainsString( 'Install couldn&#039;t complete. Check this theme, then <a href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=gh#ran-booster-native-hold">release the hold</a>.', $held_unreadable_html );

		$other_held_controls = ReleaseManagementFixture::controls(
			new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'branch', 'plugin' ) ),
			native_hold: static fn (): array => array(
				'type'       => 'plugin',
				'identifier' => 'other/other.php',
				'readable'   => true,
				'name'       => 'Other Plugin',
				'url'        => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=other%2Fother.php#ran-booster-native-hold',
			)
		);
		$_GET                = $this->query_of( $uncertain_url );
		$_GET['page']        = 'ran-booster-plugins';
		$_GET['package']     = 'example/example.php';
		ob_start();
		$other_held_controls->render_operation_notice();
		$other_held_html = (string) ob_get_clean();
		self::assertStringContainsString( 'GitHub installs are on hold: the last install of <a href="https://example.test/wp-admin/admin.php?page=ran-booster-plugins&amp;package=other%2Fother.php#ran-booster-native-hold">Other Plugin</a> couldn&#039;t complete.', $other_held_html );

		// Policy & older refusals.
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		ob_start();
		$display->render_operation_notice( 'release_older', false, 'plugin', 'example/example.php', 'stable' );
		$older_html = (string) ob_get_clean();
		self::assertStringContainsString( 'This release is older than what&#039;s installed.', $older_html );
		self::assertStringNotContainsString( 'Why this happened and how to fix it', $older_html );

		ob_start();
		$display->render_operation_notice( 'policy_disabled', false );
		self::assertStringContainsString( 'Updates are disabled for this package. Set Updates to Manual to switch.', (string) ob_get_clean() );

		ob_start();
		$display->render_operation_notice( 'policy_forced_off', false );
		self::assertStringContainsString( 'Updates are turned off by this site&#039;s configuration, so this package can&#039;t switch to releases.', (string) ob_get_clean() );
	}

	public function test_confirmed_switch_hx_location_scrolls_to_top_while_channel_change_preserves_scroll(): void {
		$controls                   = ReleaseManagementFixture::controls( new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ) );
		$_POST                      = $this->request( 'enable' ) + array(
			'release_channel' => 'stable',
			'confirm_switch'  => '1',
		);
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		try {
			$controls->handle_enable();
			self::fail( 'Expected the HX response to stop execution.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'hx-redirect', $exception->getMessage() );
		} finally {
			unset( $_SERVER['HTTP_HX_REQUEST'] );
		}
		$switch_location = json_decode( substr( (string) $GLOBALS['ran_booster_release_management_test_header'], strlen( 'HX-Location: ' ) ), true );
		self::assertIsArray( $switch_location );
		self::assertSame( 'outerHTML show:window:top', $switch_location['swap'] ?? null );

		$_POST                      = $this->request( 'change_channel' ) + array( 'release_channel' => 'prerelease' );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		try {
			$controls->handle_change_channel();
			self::fail( 'Expected the HX response to stop execution.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'hx-redirect', $exception->getMessage() );
		} finally {
			unset( $_SERVER['HTTP_HX_REQUEST'] );
		}
		$channel_location = json_decode( substr( (string) $GLOBALS['ran_booster_release_management_test_header'], strlen( 'HX-Location: ' ) ), true );
		self::assertIsArray( $channel_location );
		self::assertSame( 'outerHTML show:none', $channel_location['swap'] ?? null );
	}

	public function test_managed_uncertain_outcome_does_not_suggest_relinking(): void {
		$display = new \RAN\Admin\ReleaseManagement\ReleaseManagementDisplay();
		$status  = ReleaseManagementFixture::status( 'branch', 'plugin' );

		ob_start();
		$display->render_operation_notice( 'management_state_uncertain', false, 'plugin', 'example/example.php', 'stable', $status );
		$managed_html = (string) ob_get_clean();
		self::assertStringContainsString( 'Inspect the installed version, activation state and Booster management before retrying.', $managed_html );
		self::assertStringNotContainsString( 'Link an already installed plugin', $managed_html );

		ob_start();
		$display->render_operation_notice( 'installed_but_unmanaged', false, 'plugin', 'example/example.php', 'stable', $status );
		$unmanaged_html = (string) ob_get_clean();
		self::assertStringContainsString( 'before using Link an already installed plugin (or theme) or retrying.', $unmanaged_html );
	}

	/** @param-out string $url */
	private function render_enable_failure( string $reason, ?string &$url = null, string $detail = '', string $code = 'release_unavailable' ): string {
		$tracking                   = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$tracking->enable_result    = ReleaseTrackingResult::failed( $code, 'Published release assets could not be validated.', $reason, $detail );
		$controls                   = ReleaseManagementFixture::controls( $tracking );
		$request                    = $this->request( 'enable' );
		$request['release_channel'] = 'stable';
		$request['confirm_switch']  = '1';
		$url                        = $controls->process_admin_post_request( 'enable', $request );
		$_GET                       = $this->query_of( $url );
		$package                    = new PackageProjection();
		ob_start();
		$controls->render_advanced_source_section( 'edit', 'plugin', 'branch', $package, $package->settings_url() );

		return (string) ob_get_clean();
	}

	/** @return array<string, string> */
	private function query_of( string $url ): array {
		$query = (string) parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Local URL fixture.
		parse_str( $query, $parsed );
		$strings = array();
		foreach ( $parsed as $key => $value ) {
			$strings[ (string) $key ] = is_string( $value ) ? $value : '';
		}

		return $strings;
	}

	/** @return array<string, string> */
	private function request( string $operation ): array {
		return array(
			'expected_type'            => 'plugin',
			'expected_identifier'      => 'example/example.php',
			'expected_source_revision' => '3',
			'_wpnonce'                 => $this->nonce( $operation ),
		) + ( 'enable' === $operation ? $this->confirmed_release_fields( 'plugin', 'example/example.php', 'stable' ) : array() );
	}

	/** @return array<string,string> */
	private function confirmed_release_fields( string $type, string $identifier, string $channel ): array {
		$payload = \RAN\Admin\ReleaseManagement\wp_json_encode( array( $type, $identifier, 3, $channel, 'rel-2.0.0', 'v2.0.0', '2.0.0', false, '1.0.0' ) );
		return array(
			'confirmed_release_identity'  => 'rel-2.0.0',
			'confirmed_release_tag'       => 'v2.0.0',
			'confirmed_release_version'   => '2.0.0',
			'confirmed_release_older'     => '0',
			'confirmed_installed_version' => '1.0.0',
			'confirmed_release_nonce'     => \RAN\Admin\ReleaseManagement\wp_create_nonce( 'ran-booster-older-switch-' . hash( 'sha256', is_string( $payload ) ? $payload : '' ) ),
		);
	}

	private function nonce( string $operation ): string {
		return 'nonce-for-release-tracking-' . $operation . '-plugin-example/example.php-3';
	}
}
