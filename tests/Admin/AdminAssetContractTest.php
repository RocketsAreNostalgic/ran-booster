<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Booster;

final class AdminAssetContractTest extends TestCase {

	public function test_common_selectors_are_rooted_while_body_appended_picker_selectors_remain_available(): void {
		$css = $this->asset( 'ran-booster.css' );

		self::assertStringContainsString( '.ran-wp-ui-button.ran-booster-button--danger.is-solid', $css );
		self::assertStringContainsString( '#screen-meta-links', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-masthead', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-brand', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-credential-modal', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-secondary-nav', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-activity__details', $css );
		self::assertStringNotContainsString( 'ran-booster-package-activity', $css );
		self::assertStringContainsString(
			'border: 1px solid var(--ran-booster-border);',
			$css
		);
		self::assertStringContainsString(
			'--ran-booster-status-pending: var(--ran-booster-link-strong);',
			$css
		);
		self::assertStringContainsString(
			'--ran-booster-status-neutral: var(--ran-booster-text-muted);',
			$css
		);
		self::assertStringContainsString(
			'--ran-booster-color-neutral-300: #c3c4c7;',
			$css
		);
		self::assertStringContainsString(
			'--ran-booster-border: var(--ran-booster-color-neutral-300);',
			$css
		);
		self::assertStringContainsString(
			'--ran-booster-danger: var(--ran-booster-color-red-200);',
			$css
		);
		self::assertStringContainsString( ':root {', $css );
		self::assertStringContainsString(
			'--ran-booster-surface-subtle: var(--ran-booster-color-neutral-100);',
			$css
		);
		self::assertStringContainsString(
			'--ran-booster-surface-info: var(--ran-booster-color-blue-100);',
			$css
		);
		self::assertStringContainsString(
			'--ran-booster-danger-strong: var(--ran-booster-color-red-300);',
			$css
		);
		self::assertStringContainsString(
			'--ran-booster-text-success: var(--ran-booster-color-green-400);',
			$css
		);
		self::assertStringNotContainsString( '--ran-booster-color-neutral-25:', $css );
		self::assertStringNotContainsString( '--ran-booster-color-neutral-50:', $css );
		self::assertStringNotContainsString( '--ran-booster-color-blue-50:', $css );
		self::assertStringNotContainsString( '--ran-booster-color-blue-75:', $css );
		self::assertStringNotContainsString( '--ran-booster-color-green-600:', $css );
		self::assertStringContainsString( '--ran-booster-color-red-500: #a00;', $css );
		self::assertStringContainsString( '--ran-booster-color-red-600: #761c19;', $css );
		self::assertStringContainsString(
			'--ran-booster-table-cell-padding-block: var(--ran-booster-space-8);',
			$css
		);
		self::assertStringContainsString(
			'--ran-booster-overlay: var(--ran-booster-color-black-a60);',
			$css
		);
		self::assertSame( 1, substr_count( $css, 'rgb(0 0 0 / 60%)' ) );
		self::assertStringContainsString( '[data-ran-booster-badge]', $css );
		self::assertStringNotContainsString( '.ran-booster-badge--error', $css );
		self::assertStringNotContainsString( "\n.wp-core-ui .button-delete", $css );
		self::assertStringNotContainsString( "\n.notice-info {", $css );
		self::assertStringNotContainsString( '.ran-booster-admin .theme-screenshot .content', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-welcome-panel', $css );
		self::assertStringNotContainsString( "\n.ran-booster-credential-modal {", $css );
		self::assertStringNotContainsString( "\n.ran-booster-secondary-nav {", $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-activity__table', $css );

		self::assertStringContainsString( '.ran-booster-dialog {', $css );
		self::assertStringContainsString( "\n.ran-booster-repository-picker-modal {", $css );
		self::assertStringContainsString( "\n.ran-booster-repository-picker-portal {", $css );
		self::assertStringContainsString( "\nbody.ran-booster-repository-picker-open {", $css );
		self::assertStringContainsString( 'overflow: hidden;', $css );
	}

	public function test_admin_stylesheet_components_keep_cascade_order_and_native_button_scope(): void {
		self::assertSame(
			array(
				'00-foundations.css',
				'10-buttons.css',
				'15-enhanced-mutations.css',
				'20-repository-picker.css',
				'25-admin-primitives.css',
				'30-provider-cards.css',
				'40-tables-and-pills.css',
				'50-troubleshooting-and-activity.css',
				'55-extensions.css',
				'60-packages.css',
				'65-package-settings.css',
				'70-credential-dialog.css',
				'80-responsive.css',
			),
			$this->style_components()
		);

		$buttons = $this->asset( 'ran-booster/10-buttons.css' );

		self::assertStringContainsString( '.ran-wp-ui-button.ran-booster-button--danger.is-solid', $buttons );
		// The danger tone takes the design system's strong error colours so its
		// label keeps AA contrast at rest and in the active states.
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-button--danger\\.is-solid \\{\\s+--wp-ui-button-background-color: var\\(\\s+--wpds-color-background-interactive-error-strong\\s+\\);\\s+--wp-ui-button-background-color-active: var\\(\\s+--wpds-color-background-interactive-error-strong-active\\s+\\);/',
			$buttons
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-button--danger\\.is-outline \\{\\s+--wp-ui-button-border-color: var\\(--wpds-color-stroke-interactive-error\\);[\\s\\S]+--wp-ui-button-foreground-color: var\\(\\s+--wpds-color-foreground-interactive-error\\s+\\);/',
			$buttons
		);
		self::assertStringNotContainsString( 'var(--ran-booster-danger', $buttons );
		self::assertStringNotContainsString( '.button-delete', $buttons );
		self::assertStringContainsString( '.button-update-package.ran-booster-update-is-active', $buttons );
		self::assertStringNotContainsString( "\n.wp-core-ui .button-delete", $buttons );
	}

	public function test_extensions_styles_do_not_reimplement_the_word_press_plugin_card_layout(): void {
		$extensions = $this->asset( 'ran-booster/55-extensions.css' );
		$view       = $this->view( 'extensions.php' );

		self::assertStringContainsString( "array( 'class' => 'ran-booster-extension-card__badge' )", $view );
		self::assertStringNotContainsString( '.ran-booster-extension-card__badge', $extensions );
		self::assertStringContainsString( '.ran-booster-extension-details {', $extensions );
		self::assertStringContainsString( '.ran-booster-extension-details-host:target {', $extensions );
		self::assertStringNotContainsString( '#TB_', $extensions );
		self::assertStringNotContainsString( '__tabs', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card.plugin-card', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .plugin-card-top', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .plugin-icon', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .action-links', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .plugin-action-buttons', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .plugin-card-bottom', $extensions );
	}

	/**
	 * WordPress rings a focused `a:focus .plugin-icon` with a box-shadow that
	 * follows the icon's own radius, so the icon must carry the radius of the
	 * bundled logos (rx="8" at the 128px display size) for the ring to hug them.
	 */
	public function test_extension_logo_focus_ring_follows_the_logo_corner_radius(): void {
		$extensions = $this->asset( 'ran-booster/55-extensions.css' );

		if ( 1 !== preg_match( '/\n\.ran-booster-admin \.ran-booster-extensions__grid \.plugin-icon \{([^}]*)\}/', $extensions, $icon_rule ) ) {
			self::fail( 'The extension logo radius rule is missing.' );
		}
		self::assertStringContainsString( 'border-radius: var(--ran-booster-radius-card);', $icon_rule[1] );
		self::assertDoesNotMatchRegularExpression( '/outline|box-shadow|color/', $icon_rule[1], 'The logo rule sets radius only; ring colour and width stay with the core focus rule.' );

		$logos = glob( dirname( __DIR__, 2 ) . '/assets/extensions/*.svg' );
		self::assertIsArray( $logos );
		self::assertNotEmpty( $logos );
		foreach ( $logos as $logo ) {
			$svg = $this->asset( 'extensions/' . basename( $logo ) );
			self::assertStringContainsString( 'viewBox="0 0 128 128"', $svg, $logo );
			self::assertMatchesRegularExpression( '/<rect width="128" height="128" rx="8"/', $svg, $logo . ' corner radius must stay 8px at 128px.' );
		}
	}

	public function test_extension_action_text_links_keep_a_minimum_pointer_target(): void {
		$extensions = $this->asset( 'ran-booster/55-extensions.css' );

		if ( 1 !== preg_match( '/\.ran-booster-admin \.ran-booster-extensions__grid \.plugin-action-buttons a \{([^}]*)\}/', $extensions, $link_rule ) ) {
			self::fail( 'The extension action link rule is missing.' );
		}
		self::assertStringContainsString( 'min-block-size: var(--ran-booster-space-24);', $link_rule[1] );
	}

	public function test_package_list_filters_size_to_their_longest_option(): void {
		$packages = $this->asset( 'ran-booster/60-packages.css' );

		if ( 1 !== preg_match( '/\n\.ran-booster-admin \.ran-booster-package-list-filters select \{([^}]*)\}/', $packages, $filter_rule ) ) {
			self::fail( 'The package list filter select rule is missing.' );
		}
		self::assertStringContainsString( 'inline-size: auto;', $filter_rule[1] );
		self::assertStringNotContainsString( '130px', $filter_rule[1] );
	}

	public function test_activity_filters_share_one_row_with_a_truncating_subject_select(): void {
		$activity = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );
		$scope    = '.ran-booster-admin .ran-booster-activity .ran-booster-package-list-filters';

		if ( 1 !== preg_match( '/\n' . preg_quote( $scope, '/' ) . ' \{([^}]*)\}/', $activity, $row_rule ) ) {
			self::fail( 'The scoped Activity filter row rule is missing.' );
		}
		self::assertStringContainsString( 'flex-wrap: nowrap;', $row_rule[1] );
		self::assertStringContainsString( 'gap: var(--ran-booster-space-8);', $row_rule[1] );
		// Inside the collection toolbar the form must be allowed to shrink, or a long subject pushes the row past the frame.
		self::assertStringContainsString( 'flex: 1 1 auto;', $row_rule[1] );
		self::assertStringContainsString( 'min-inline-size: 0;', $row_rule[1] );
		self::assertStringContainsString( 'max-inline-size: 100%;', $row_rule[1] );

		$subject = '/\n\.ran-booster-admin\s+\.ran-booster-activity\s+\.ran-booster-package-list-filters\s+#ran-booster-activity-package \{([^}]*)\}/';
		if ( 1 !== preg_match( $subject, $activity, $subject_rule ) ) {
			self::fail( 'The Activity subject select rule is missing.' );
		}
		foreach ( array( 'flex: 1 1 0;', 'min-width: 0;', 'max-width: 100%;', 'overflow: hidden;', 'text-overflow: ellipsis;', 'white-space: nowrap;' ) as $declaration ) {
			self::assertStringContainsString( $declaration, $subject_rule[1] );
		}

		$narrow = '/@media screen and \(max-width: 782px\) \{\s*\.ran-booster-admin \.ran-booster-activity \.ran-booster-package-list-filters \{([^}]*)\}\s*\.ran-booster-admin\s+\.ran-booster-activity\s+\.ran-booster-package-list-filters\s+#ran-booster-activity-package \{([^}]*)\}/';
		if ( 1 !== preg_match( $narrow, $activity, $narrow_rules ) ) {
			self::fail( 'The 782px Activity filter wrap rule is missing.' );
		}
		self::assertStringContainsString( 'flex-wrap: wrap;', $narrow_rules[1] );
		self::assertStringContainsString( 'flex: 1 1 100%;', $narrow_rules[2] );
	}

	public function test_activity_header_keeps_the_shared_card_gap_above_the_collection_frame(): void {
		$activity = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );

		if ( 1 !== preg_match( '/\n\.ran-booster-admin \.ran-booster-activity__header \{([^}]*)\}/', $activity, $header_rule ) ) {
			self::fail( 'The Activity header rule is missing.' );
		}
		self::assertStringContainsString( 'margin-block-end: var(--ran-booster-card-gap);', $header_rule[1] );
		self::assertStringNotContainsString( 'px', $header_rule[1] );

		// The heading-to-description gap stays the shared heading gap.
		if ( 1 !== preg_match( '/\n\.ran-booster-admin \.ran-booster-activity__header p \{([^}]*)\}/', $activity, $paragraph_rule ) ) {
			self::fail( 'The Activity header description rule is missing.' );
		}
		self::assertStringContainsString( 'margin-block-start: var(--ran-booster-card-heading-gap);', $paragraph_rule[1] );
	}

	public function test_link_target_size_rule_leaves_button_styled_anchors_to_the_button_minimum(): void {
		$css = $this->asset( 'ran-booster.css' );

		if ( 1 !== preg_match( '/\.ran-booster-readiness-actions__links,[\s\S]*?\)\s*> a(:not\(\.ran-wp-ui-button\))?,[\s\S]*?\{([^}]*)\}/', $css, $rule ) ) {
			self::fail( 'The container link target size rule is missing.' );
		}
		self::assertSame( ':not(.ran-wp-ui-button)', $rule[1], 'Button-styled anchors keep the Button minimum height.' );
		self::assertStringContainsString( 'min-block-size: var(--ran-booster-space-24);', $rule[2] );
		self::assertDoesNotMatchRegularExpression( '/\.ran-booster-readiness-actions,[\s\S]*?\)\s*> a,/', $css );
	}

	public function test_link_choice_label_keeps_the_field_label_weight(): void {
		$css = $this->asset( 'ran-booster.css' );

		self::assertMatchesRegularExpression( '/\.ran-booster-settings-field\s*> label,[^{]*\{[^}]*color: var\(--ran-booster-text\);/', $css );
		$this->assert_general_field_label_rule( $css );
		self::assertDoesNotMatchRegularExpression( '/ran-booster-package-link-choice\s+\.ran-booster-settings-field\s*> label/', $css, 'The link choice label inherits the bold field label weight.' );
	}

	public function test_standalone_text_links_keep_a_24px_target(): void {
		$css = $this->asset( 'ran-booster.css' );

		if ( 1 !== preg_match( '/\.ran-booster-standalone-link-row,[\s\S]*?\)\s*> a,[\s\S]*?\{([^}]*)\}/', $css, $inline_rule ) ) {
			self::fail( 'Standalone link rows need a target size rule.' );
		}
		self::assertStringContainsString( 'display: inline-flex;', $inline_rule[1] );
		self::assertStringContainsString( 'min-block-size: var(--ran-booster-space-24);', $inline_rule[1] );
		if ( 1 !== preg_match( '/\.ran-booster-overview-package-links,[\s\S]*?\.ran-booster-overview-provider-link,[\s\S]*?\{([^}]*)\}/', $css, $block_rule ) ) {
			self::fail( 'Block link containers need a target size rule.' );
		}
		self::assertStringContainsString( 'min-block-size: var(--ran-booster-space-24);', $block_rule[1] );
		self::assertStringNotContainsString( 'display:', $block_rule[1] );

		// Rows that hold only a link carry the row class; links inside sentences do not.
		foreach (
			array(
				'views/base.php',
				'views/onboarding.php',
				'views/provider.php',
				'views/troubleshooting-installation.php',
				'RAN/Admin/Component/RepositoryDetailRenderer.php',
				'RAN/Admin/WebhookManagement/views/panel.php',
			) as $file
		) {
			self::assertStringContainsString( '<p class="ran-booster-standalone-link-row"><a href=', (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file ), $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
		}
	}

	public function test_table_sort_links_repository_names_and_switches_keep_a_24px_target(): void {
		$css = $this->asset( 'ran-booster.css' );

		if ( 1 !== preg_match( '/\.ran-booster-data-table > thead > tr > th > a \{[\s\S]*?\}|\.ran-booster-data-table > thead > tr > th > a,[\s\S]*?\{([^}]*)\}/', $css, $sort_rule ) ) {
			self::fail( 'Column sort links need a target size rule.' );
		}
		self::assertStringContainsString( 'display: inline-flex;', $sort_rule[0] );
		self::assertStringContainsString( 'min-block-size: var(--ran-booster-space-24);', $sort_rule[0] );
		self::assertMatchesRegularExpression( '/a\.ran-booster-repository-record__name \{\s*min-block-size: var\(--ran-booster-space-24\);/', $css );

		// The General switch keeps its visual size; its hit area is a 32px-tall pseudo-element.
		if ( 1 !== preg_match( '/button\[role="switch"\]::before \{([^}]*)\}/', $css, $switch_rule ) ) {
			self::fail( 'The General switch needs a hit area rule.' );
		}
		self::assertStringContainsString( 'inset-block: calc(-8px - var(--ran-booster-switch-border-width));', $switch_rule[1] );
	}

	public function test_package_operation_updates_select_fits_the_container_on_a_phone(): void {
		$css = $this->asset( 'ran-booster/65-package-settings.css' );

		if ( 1 !== preg_match( '/\.ran-booster-deployment-policy-row > select \{([^}]*)\}/', $css, $rule ) ) {
			self::fail( 'The Updates select needs its own rule.' );
		}
		self::assertStringContainsString( 'inline-size: 100%;', $rule[1] );
		self::assertStringContainsString( 'min-inline-size: 0;', $rule[1] );
		self::assertStringContainsString( 'padding-inline-start: var(--ran-booster-space-8);', $rule[1] );
		self::assertStringContainsString( 'text-overflow: ellipsis;', $rule[1], 'Ellipsis is the last resort for labels no phone can fit.' );
		self::assertStringNotContainsString( 'overflow: hidden', $rule[1] );
		self::assertMatchesRegularExpression( '/@media screen and \(max-width: 480px\) \{\s*\.ran-booster-admin\s+\.ran-booster-package-operation-settings\s+:is\(\s*\.ran-booster-settings-section__header,\s*\.ran-booster-settings-section__body\s*\) \{\s*padding-inline: var\(--ran-booster-space-16\);/', $css );
		// The option labels are product copy and are not shortened for the control.
		self::assertStringContainsString( "'Manual — update only when requested'", $this->view( 'packages/fields/deployment-policy.php' ) );
	}

	public function test_section_navigation_shows_a_css_only_scroll_affordance_where_it_overflows(): void {
		$css   = $this->asset( 'ran-booster/00-foundations.css' );
		$shell = $this->asset( 'ran-admin-shell.css' );

		// The Shell stylesheet is vendored and generated: the hint must not live there.
		self::assertStringNotContainsString( 'mask-image', $shell );
		self::assertStringNotContainsString( 'animation-timeline', $shell );

		self::assertStringContainsString( '@supports (animation-timeline: scroll()) {', $css );
		self::assertMatchesRegularExpression( '/@property --ran-booster-nav-fade-start \{\s*syntax: "<length>";\s*inherits: false;\s*initial-value: 0px;/', $css );
		self::assertMatchesRegularExpression( '/@property --ran-booster-nav-fade-end \{\s*syntax: "<length>";\s*inherits: false;\s*initial-value: 0px;/', $css, 'No overflow means no timeline and a zero-width fade.' );
		self::assertStringContainsString( 'animation-timeline: scroll(self inline), scroll(self inline);', $css );
		self::assertMatchesRegularExpression( '/animation-range:\s*0 24px,\s*calc\(100% - 24px\) 100%;/', $css );
		// Dimmed, never hidden: the partly visible item stays readable, and the hint takes no layout.
		self::assertMatchesRegularExpression( '/mask-image: linear-gradient\(\s*to right,\s*color-mix\(in srgb, black 35%, transparent\) 0,/', $css );
		self::assertMatchesRegularExpression( '/color-mix\(in srgb, black 35%, transparent\) 100%/', $css );
		self::assertStringNotContainsString( 'transition: mask', $css );
		// The scroll origin follows the writing direction.
		self::assertMatchesRegularExpression( '/\.ran-section-navigation:dir\(rtl\) \{\s*--ran-booster-nav-fade-left: var\(--ran-booster-nav-fade-end\);\s*--ran-booster-nav-fade-right: var\(--ran-booster-nav-fade-start\);/', $css );
		self::assertMatchesRegularExpression( '/@media \(forced-colors: active\) \{\s*\.ran-admin-shell__navigation\.ran-section-navigation \{\s*mask-image: none;/', $css );
	}

	public function test_status_pills_are_badges_and_the_legacy_tile_utilities_are_gone(): void {
		$debug      = $this->view( 'debug-capture.php' );
		$packages   = $this->view( 'packages/index.php' );
		$provider   = $this->view( 'provider.php' );
		$projection = $this->source( 'RAN/Admin/ProviderSettingsPresenter.php' );
		$component  = $this->asset( 'ran-booster/40-tables-and-pills.css' );
		$activity   = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );

		self::assertFileDoesNotExist( dirname( __DIR__, 2 ) . '/assets/ran-booster/35-status-utilities.css' );
		self::assertStringNotContainsString( 'notice notice-warning inline ran-booster-debug-capture__scope', $debug );
		self::assertStringContainsString( "StatusBadge::for_intent( (string) \$prominent_status['label'], (string) ( \$badge_intents[ \$prominent_status['tone'] ] ?? 'none' ), \$prominent_status_activity ? array( 'data-ran-booster-activity-badge' => '' ) : array() )", $packages );
		self::assertStringContainsString( 'Stored · Validity checked on use', $projection );
		self::assertStringContainsString( 'ran-booster-delete-credential-package-link', $provider );
		self::assertStringNotContainsString( '.ran-booster-badge', $component );
		self::assertStringContainsString( '[data-ran-booster-badge]', $component );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-deployment-state {', $activity );
	}

	public function test_callout_tones_use_semantic_backgrounds(): void {
		$onboarding      = $this->asset( 'ran-booster-onboarding.css' );
		$troubleshooting = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );

		// Unavailable credentials are neutral: no warning strip, and no grey fill on the card.
		self::assertStringNotContainsString( '.ran-booster-portability__credential-decision-state--unavailable', $onboarding );
		self::assertDoesNotMatchRegularExpression( '/credential-card--unavailable\s*\{[^}]*background:/', $onboarding );
		self::assertMatchesRegularExpression( '/\.ran-booster-portability__credential-reason\s*\{[^}]*font-size:\s*var\(--ran-booster-font-size-md\)/', $onboarding );
		// Controls on the grey panel use the stronger neutral so their borders reach 3:1.
		self::assertMatchesRegularExpression( '/\.ran-booster-portability__mode\s*\{[^}]*border:\s*1px solid var\(--ran-booster-color-neutral-450\)/', $onboarding );
		self::assertStringNotContainsString( 'ran-booster-troubleshooting__core-updates', $troubleshooting );
	}

	public function test_admin_primitives_own_shared_panel_and_action_row_contracts(): void {
		$primitives = $this->asset( 'ran-booster/25-admin-primitives.css' );
		$debug      = $this->view( 'debug-capture.php' );

		self::assertStringContainsString( '.ran-booster-admin .ran-booster-panel,', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-onboarding > .ran-booster-onboarding__storage,', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-portability > .ran-booster-portability__flow {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-action-row {', $primitives );
		self::assertStringContainsString( 'ran-booster-debug-capture__panel ran-booster-panel', $debug );
	}

	/**
	 * Every full-border rule must take its radius from the shared surface block,
	 * so a new framed box cannot ship square. Entries below are intentional.
	 */
	public function test_headings_take_their_size_from_the_shared_type_scale(): void {
		$allowed = array(
			'dashicons'                          => 'Icon glyph size, not text.',
			'ran-booster-dialog__close'          => 'Icon button glyph size.',
			'ran-booster-portability__mode-icon' => 'Icon glyph size.',
			'ran-booster-overview-package-count' => 'Stat numeral, not a heading.',
		);

		$offenders   = array();
		$stylesheets = glob( dirname( __DIR__, 2 ) . '/assets/{ran-booster/*.css,*.css}', GLOB_BRACE );
		foreach ( false === $stylesheets ? array() : $stylesheets as $file ) {
			// The foundations define the scale, and the notice compat sheet only loads where the design-system tokens are absent.
			if ( str_ends_with( $file, '00-foundations.css' ) || str_ends_with( $file, 'ran-booster-notice-compat.css' ) ) {
				continue;
			}
			$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
			if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				if ( 1 !== preg_match( '/font-size:\s*[0-9.]+(?:px|em|rem)\b/', $rule[2] ) ) {
					continue;
				}
				$covered = false;
				foreach ( array_keys( $allowed ) as $token ) {
					if ( str_contains( $rule[1], $token ) ) {
						$covered = true;
						break;
					}
				}
				if ( ! $covered ) {
					$offenders[] = basename( $file ) . ': ' . trim( (string) preg_replace( '/\s+/', ' ', $rule[1] ) );
				}
			}
		}

		self::assertSame( array(), $offenders, 'Every text size must use the --ran-booster-font-size-* tokens (the WordPress design-system 20 / 15 / 13 / 12 scale); only icon glyphs and the listed readouts may use a raw size.' );
	}

	public function test_every_framed_surface_takes_its_radius_from_the_shared_block(): void {
		$primitives = $this->asset( 'ran-booster/25-admin-primitives.css' );
		$start      = strpos( $primitives, '/* surface-radius:start */' );
		$end        = strpos( $primitives, '/* surface-radius:end */' );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$shared = substr( $primitives, $start, $end - $start );

		$allowed = array(
			'ran-booster-data-table'          => 'Table inside the rounded data-table-wrap.',
			'ran-booster-panel'               => 'Panel radius is declared with its card profile.',
			'ran-booster-card'                => 'Native Card fallback declares the card radius.',
			'ran-booster-summary-grid'        => 'Summary grid declares its own radius.',
			'ran-booster-ui-disclosure'       => 'Public Disclosure fallback uses the WordPress UI radius.',
			'ran-booster-provider-section'    => 'Provider sections are Cards with their own profile.',
			'ran-booster-onboarding__storage' => 'Declared with the panel profile.',
			'ran-booster-portability__flow'   => 'Declared with the panel profile.',
		);

		$offenders   = array();
		$stylesheets = glob( dirname( __DIR__, 2 ) . '/assets/{ran-booster/*.css,*.css}', GLOB_BRACE );
		foreach ( false === $stylesheets ? array() : $stylesheets as $file ) {
			$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
			if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				$body = $rule[2];
				if ( 1 !== preg_match( '/(?:^|[;\s])border:\s*(?!0|none)[^;]*\d+px/', $body ) || str_contains( $body, 'radius' ) ) {
					continue;
				}
				$selector = trim( (string) preg_replace( '/\s+/', ' ', $rule[1] ) );
				if ( preg_match_all( '/\.(ran-booster-(?!admin\b)[a-z0-9_-]+)/', $selector, $classes ) > 0 ) {
					$covered = false;
					foreach ( array_unique( $classes[1] ) as $class ) {
						if ( str_contains( $shared, '.' . $class ) || isset( $allowed[ $class ] ) ) {
							$covered = true;
							break;
						}
					}
					if ( $covered ) {
						continue;
					}
				}
				$offenders[] = basename( $file ) . ': ' . $selector;
			}
		}

		self::assertSame( array(), $offenders, 'These full-border rules have no radius. Add them to the shared surface-radius block or allow them with a reason.' );
	}

	/**
	 * Every corner radius names a shared token, so a raw length or the retired
	 * 3px surface radius cannot bring back an off-profile corner. Entries below
	 * are intentional.
	 */
	public function test_every_radius_value_comes_from_a_shared_token(): void {
		$allowed_files     = array(
			'ran-admin-shell.css'           => 'Standalone shell sheet with its own fallbacks; its button radius follows core buttons.',
			'ran-booster-notice-compat.css' => 'Loads only where the design-system tokens are absent, so it restates the Card radius as a length.',
		);
		$allowed_selectors = array(
			'ran-booster-status-dot' => 'Status dot circle, owned by the status indicator rules.',
		);
		$token_pattern     = '/var\(--ran-booster-radius-(?:card|inset|control|circle|pill)\)|var\(--wpds-border-radius-[a-z0-9]+\)|\b0\b|revert-layer|inherit/';

		$offenders   = array();
		$stylesheets = glob( dirname( __DIR__, 2 ) . '/assets/{ran-booster/*.css,*.css}', GLOB_BRACE );
		foreach ( false === $stylesheets ? array() : $stylesheets as $file ) {
			if ( isset( $allowed_files[ basename( $file ) ] ) ) {
				continue;
			}
			$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
			if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				if ( ! preg_match_all( '/(?<![\w-])border(?:-[a-z]+)*-radius\s*:\s*([^;]+)/', $rule[2], $values ) ) {
					continue;
				}
				$selector = trim( (string) preg_replace( '/\s+/', ' ', $rule[1] ) );
				foreach ( array_keys( $allowed_selectors ) as $token ) {
					if ( str_contains( $selector, $token ) ) {
						continue 2;
					}
				}
				foreach ( $values[1] as $value ) {
					if ( '' !== trim( (string) preg_replace( $token_pattern, '', $value ) ) ) {
						$offenders[] = basename( $file ) . ': ' . $selector . ' => ' . trim( $value );
					}
				}
			}
		}

		self::assertSame( array(), $offenders, 'Every border radius must use --ran-booster-radius-card, -inset, -control, -circle or -pill (or a --wpds-border-radius token). Raw lengths and the retired surface radius are allowed only with a reason.' );
	}

	/**
	 * Status pills are one static public Badge rendered through StatusBadge. The
	 * retired pill classes and the fallback/mount pair must not return.
	 */
	public function test_status_pills_use_the_public_badge_and_no_retired_pill_classes_remain(): void {
		$root     = dirname( __DIR__, 2 );
		$offences = array();
		$patterns = array(
			'/views/*.php',
			'/views/*/*.php',
			'/assets/ran-booster/*.css',
			'/assets/*.css',
			'/assets/*.js',
			'/assets/*/*.js',
			'/RAN/Admin/*.php',
			'/RAN/Admin/*/*.php',
		);
		foreach ( $patterns as $pattern ) {
			$matched = glob( $root . $pattern );
			foreach ( false === $matched ? array() : $matched as $file ) {
				$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
				foreach ( array( 'ran-booster-pill', 'advanced-source-summary__badge', 'data-ran-booster-ui-badge', 'data-ran-booster-static-badge', 'ran-booster-tile', 'ran-booster-status-dot', 'ran-booster-package-row__state-value' ) as $retired ) {
					if ( str_contains( $source, $retired ) ) {
						$offences[] = basename( $file ) . ': retired ' . $retired;
					}
				}
				// The retired dot-and-text status class, not the --ran-booster-status-* tokens.
				if ( 1 === preg_match( '/(?<![\w-])ran-booster-status(?:--[a-z]+)?(?![\w-])/', $source ) ) {
					$offences[] = basename( $file ) . ': retired ran-booster-status dot class';
				}
				if ( ! str_ends_with( $file, '.php' ) ) {
					continue;
				}
				// Tone strings such as ran-booster-badge--error are presenter data, never a class on an element.
				if ( 1 === preg_match( '/(?<![\w-])ran-booster-badge(?![\w-])/', $source ) ) {
					$offences[] = basename( $file ) . ': ran-booster-badge pill class, use StatusBadge';
				}
			}
		}

		self::assertSame( array(), $offences );

		$badge = $this->source( 'RAN/Admin/Component/StatusBadge.php' );
		self::assertStringContainsString( "'ran-wp-ui-text is-body-sm ran-wp-ui-badge is-' . \$intent . '-intent'", $badge );
		self::assertStringContainsString( 'data-ran-booster-badge data-ran-booster-badge-intent=', $badge );
		self::assertStringNotContainsString( 'data-ran-booster-ui-badge', $badge );
	}

	/**
	 * Status and label pills are the public Badge only. No stylesheet draws its own
	 * pill or status dot, and no rule repaints the Badge. Round shapes that are not
	 * status labels are listed with the reason they are not a pill.
	 */
	public function test_no_stylesheet_draws_a_custom_pill_or_repaints_the_badge(): void {
		$allowed     = array(
			'ran-booster-readiness-icon'           => 'Checklist step marker: a symbol beside a heading and sentence, not a label.',
			'ran-booster-webhook-step'             => 'Numbered webhook setup step marker beside a heading and sentence.',
			'ran-booster-source-choice__radio'     => 'Native-looking radio control inside a source choice.',
			'ran-booster-settings__switch-control' => 'Fallback for the public Switch control.',
			'ran-booster-provider-task-tab__source-indicator' => 'Decoration on a navigation tab; the link carries screen-reader text.',
			'ran-admin-shell__version'             => 'Vendored admin shell, generated from its own package; change it upstream.',
		);
		$pill_radius = '/(?:^|[;\s])border(?:-[a-z]+)*-radius:\s*(?:[^;]*\b(?:999|9999|100)px|[^;]*\b50rem|50%|100%|var\(--ran-booster-radius-(?:pill|circle|badge)\))/';
		$repaint     = '/(?:^|[;\s])(?:background|border|color|padding|font)(?:-[a-z]+)*:/';

		$offenders   = array();
		$stylesheets = glob( dirname( __DIR__, 2 ) . '/assets/{ran-booster/*.css,*.css}', GLOB_BRACE );
		foreach ( false === $stylesheets ? array() : $stylesheets as $file ) {
			$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
			if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				$selector = trim( (string) preg_replace( '/\s+/', ' ', $rule[1] ) );
				if ( ( str_contains( $selector, 'data-ran-booster-badge' ) || str_contains( $selector, 'ran-wp-ui-badge' ) ) && 1 === preg_match( $repaint, $rule[2] ) ) {
					$offenders[] = basename( $file ) . ': repaints the Badge: ' . $selector;
				}
				if ( 1 !== preg_match( $pill_radius, $rule[2] ) ) {
					continue;
				}
				$covered = false;
				if ( preg_match_all( '/\.(ran-[a-z0-9_-]+)/', $selector, $classes ) > 0 ) {
					foreach ( $classes[1] as $class ) {
						if ( isset( $allowed[ $class ] ) ) {
							$covered = true;
							break;
						}
					}
				}
				if ( ! $covered ) {
					$offenders[] = basename( $file ) . ': pill or dot shape: ' . $selector;
				}
			}
		}

		self::assertSame( array(), $offenders, 'Status and label pills are the public Badge only. Use StatusBadge, or allow a non-label shape here with a reason.' );
	}

	public function test_disclosure_hosts_own_their_margins_and_prominent_rules_stay_scoped(): void {
		$css = $this->asset( 'ran-booster/25-admin-primitives.css' );
		self::assertStringContainsString( '> details[data-ran-booster-ui-disclosure-fallback] {' . "\n\t" . 'margin: 0;', $css );
		foreach ( array( 'troubleshooting-logging', 'troubleshooting-evidence', 'package-webhook-setup', 'provider-webhook-setup', 'package-advanced-settings', 'package-release-track', 'package-danger-zone', 'repository-webhook-setup', 'repository-release-readiness', 'repository-release-workflow' ) as $kind ) {
			self::assertStringContainsString( 'data-ran-booster-ui-disclosure="' . $kind . '"', $css, $kind );
		}
		// A prominent header rule must name the host's own disclosure, never every nested one.
		self::assertSame( 0, preg_match( '/density="prominent"\]\s+\.ran-booster-ui-disclosure__header/', $css ) );
	}

	/**
	 * The Card's trailing-block reset zeroes the last child's bottom margin at
	 * any depth, so space below or above a nested disclosure must come from
	 * container padding or the following host's top margin (#765, #746).
	 */
	public function test_nested_disclosure_gaps_survive_the_card_trailing_block_reset(): void {
		$activity   = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );
		$responsive = $this->asset( 'ran-booster/80-responsive.css' );
		$primitives = $this->asset( 'ran-booster/25-admin-primitives.css' );

		// The Activity details cell, not its details host, owns the inset below the disclosure.
		self::assertMatchesRegularExpression( '/\.ran-booster-attempt-row__details-cell \{[^}]*\n\tpadding-block: 0 var\(--ran-booster-space-12\);/', $activity );
		self::assertDoesNotMatchRegularExpression( '/\.ran-booster-attempt-row__details \{[^}]*margin-block/', $activity );
		self::assertDoesNotMatchRegularExpression( '/\.ran-booster-attempt-row__details-cell \{\s*padding: 0;/', $responsive );

		// The support report disclosure keeps the logging disclosure's gap after its action.
		self::assertMatchesRegularExpression( '/\[data-ran-booster-ui-disclosure="troubleshooting-evidence"\]\s*\)\s*\{\s*margin-block-start: var\(--ran-booster-space-16\);/', $primitives );
	}

	/**
	 * A page subtitle sits one Card gap above the first Card on every page, and
	 * a Card heading sits one heading gap above its first content (#746).
	 */
	public function test_installation_entries_are_single_cards_with_a_clear_section_gap(): void {
		$css = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );
		// The entries are the Cards: the nested inner panel frame and its divider rules are gone.
		self::assertStringNotContainsString( 'ran-booster-troubleshooting__installation-card', $css );
		self::assertStringNotContainsString( 'ran-booster-troubleshooting__card-heading', $css );
		// The static header mirrors the Disclosure frame, so an entry with nothing to expand is not a bare row.
		self::assertMatchesRegularExpression( '/__installation-entry--static\s+\.ran-booster-troubleshooting__entry-header \{[^}]*border: var\(--wpds-border-width-xs\) solid\s+var\(--wpds-color-stroke-surface-neutral\);[^}]*border-radius: var\(--wpds-border-radius-lg\);/', $css );
		// The badge never wraps and the open-state divider stays the shared Disclosure header rule.
		self::assertMatchesRegularExpression( '/__entry-meta\s*\) \{[^}]*flex: none;[^}]*white-space: nowrap;/', $css );
		self::assertMatchesRegularExpression( '/\.ran-booster-ui-disclosure\[data-open\]\s*> \.ran-booster-ui-disclosure__header,[^{]*> summary \{\s*box-shadow: 0 1px 0 var\(--ran-booster-divider\);/', $this->asset( 'ran-booster/25-admin-primitives.css' ) );
	}

	public function test_installation_headings_and_warning_use_the_shared_divider_and_status_tokens(): void {
		$primitives = $this->asset( 'ran-booster/25-admin-primitives.css' );
		$activity   = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );
		// The ruled heading reuses the Settings group divider colour, thickness and 8px gap.
		self::assertMatchesRegularExpression( '/\.ran-booster-section__title--ruled \{\s*display: flex;\s*align-items: center;\s*gap: var\(--ran-booster-space-8\);\s*\}/', $primitives );
		self::assertMatchesRegularExpression( '/\.ran-booster-section__title--ruled::after \{[^}]*flex: 1 1 auto;[^}]*border-block-start: 1px solid var\(--ran-booster-divider\);/', $primitives );
		// The header warning uses the existing warning status colour and no new colour.
		self::assertMatchesRegularExpression( '/__entry-warning \{[^}]*color: var\(--ran-booster-status-warning\);/', $activity );
		self::assertDoesNotMatchRegularExpression( '/__entry-warning \{[^}]*#[0-9a-f]{3,6}/i', $activity );
		// The static h3 or h4 title resets the WordPress heading margin and size.
		self::assertMatchesRegularExpression( '/:is\(h3, h4\)\.ran-booster-troubleshooting__entry-title \{[^}]*margin: 0;[^}]*font: inherit;/', $activity );
		// The spacing above and below the Providers heading is unchanged.
		self::assertStringContainsString( 'var(--ran-booster-card-gap) + var(--ran-booster-space-8)', $activity );
		self::assertStringContainsString( 'margin-block-start: var(--ran-booster-card-heading-gap);', $activity );
	}

	public function test_installation_header_padding_is_constant_and_the_divider_sits_below_the_warning_row(): void {
		$activity = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );
		// The toggle row and the native summary keep 16px vertical padding closed and open, scoped to Installation sections.
		self::assertMatchesRegularExpression( '/\[data-ran-booster-ui-disclosure="installation-section"\],\s*\[data-ran-booster-ui-disclosure="settings-card"\]\s*\)\s*> \*\s*> \.ran-booster-ui-disclosure\s*> \.ran-booster-ui-disclosure__header\s*> \[role="button"\],[^{]*> summary \{\s*padding-block: 16px;\s*\}/', $activity );
		// The warning row carries 16px bottom padding and sits outside the toggle.
		self::assertMatchesRegularExpression( '/__entry-warning \{[^}]*display: block;[^}]*padding: 0 16px 16px;/', $activity );
		// With a warning the open header drops its shadow and the warning row carries the divider; no border adds height.
		self::assertMatchesRegularExpression( '/\[data-ran-booster-ui-disclosure-warned\]\s*\.ran-booster-ui-disclosure\[data-open\]\s*> \.ran-booster-ui-disclosure__header \{\s*box-shadow: none;\s*\}/', $activity );
		self::assertMatchesRegularExpression( '/\.ran-booster-ui-disclosure\[data-open\]\s*\.ran-booster-troubleshooting__entry-warning \{\s*box-shadow: 0 1px 0 var\(--ran-booster-divider\);\s*\}/', $activity );
		self::assertDoesNotMatchRegularExpression( '/__entry-warning \{[^}]*border/', $activity );
		// The closed state has no divider rule: every divider selector is gated on the open state.
		self::assertSame( 0, preg_match( '/__entry-warning[^{]*\{[^}]*box-shadow/', (string) preg_replace( '/\[data-open\][^{]*\{[^}]*\}/', '', $activity ) ) );
		// Entries without a warning keep the shared divider on the header or summary.
		self::assertMatchesRegularExpression( '/\.ran-booster-ui-disclosure\[data-open\]\s*> \.ran-booster-ui-disclosure__header,[^{]*> summary \{\s*box-shadow: 0 1px 0 var\(--ran-booster-divider\);/', $this->asset( 'ran-booster/25-admin-primitives.css' ) );
	}

	public function test_page_and_card_heading_gaps_use_the_shared_spacing_tokens(): void {
		$owners = array(
			array( 'ran-booster/25-admin-primitives.css', '\n\.ran-booster-admin \.ran-booster-page-shell__header \{', 'padding-block: 0 var(--ran-booster-card-gap);' ),
			array( 'ran-booster/25-admin-primitives.css', '\n\t> \.ran-booster-page-shell__header \{', 'margin-block-end: var(--ran-booster-card-gap);' ),
			array( 'ran-booster/60-packages.css', '(?:^|\n)\.ran-booster-admin \.ran-booster-package-intro \{', 'margin-block-end: var(--ran-booster-card-gap);' ),
			array( 'ran-booster/65-package-settings.css', '\n\t\+ \.ran-booster-package-settings \{', 'margin-block-start: var(--ran-booster-card-gap);' ),
			array( 'ran-booster/50-troubleshooting-and-activity.css', '\n\.ran-booster-admin \.ran-booster-secondary-nav \{', 'margin-block: var(--ran-booster-space-18) var(--ran-booster-card-gap);' ),
			array( 'ran-booster/30-provider-cards.css', '\n\.ran-booster-admin \.ran-booster-provider-management__header \{', 'margin-block-end: var(--ran-booster-card-gap);' ),
			array( 'ran-booster-onboarding.css', '\n\n\.ran-booster-admin \.ran-booster-portability__flow \{', 'margin-block-start: var(--ran-booster-card-gap);' ),
			array( 'ran-booster-onboarding.css', '\n\.ran-booster-admin \.ran-booster-portability__import-fields \+ \.notice \{', 'margin-block-start: var(--ran-booster-space-16);' ),
			array( 'ran-booster-onboarding.css', '\n\.ran-booster-admin\n\t\[data-ran-booster-ui-disclosure="transporter-blueprint-requirements"\] \{', 'margin-block-end: var(--ran-booster-space-16);' ),
			array( 'ran-booster/25-admin-primitives.css', '\n\t\+ noscript\n\t> p:first-child \{', 'margin-block-start: var(--ran-booster-card-heading-gap);' ),
			array( 'ran-booster/65-package-settings.css', '\n\.ran-booster-admin \.ran-booster-settings__card \{', 'margin-block: 0 var(--ran-booster-card-gap);' ),
			array( 'ran-booster/50-troubleshooting-and-activity.css', '\n\t\+ \.ran-booster-troubleshooting__installation-entry \{', 'margin-block-start: var(--ran-booster-card-heading-gap);' ),
			array( 'ran-booster/50-troubleshooting-and-activity.css', '\n\t\.ran-booster-troubleshooting__installation-entry\n\t\+ \.ran-booster-troubleshooting__installation-entry \{', 'margin-block-start: var(--ran-booster-card-gap);' ),
			array( 'ran-booster/50-troubleshooting-and-activity.css', '\n\.ran-booster-admin \[aria-labelledby="ran-booster-components-heading"\] \{', 'var(--ran-booster-card-gap) + var(--ran-booster-space-8)' ),
			array( 'ran-booster-onboarding.css', '\n\.ran-booster-admin \.ran-booster-debug-capture__panel \{', 'margin-block-start: var(--ran-booster-card-heading-gap);' ),
			array( 'ran-booster-onboarding.css', '\n\.ran-booster-admin \.ran-booster-portability__mode-options \{', 'margin-block-start: var(--ran-booster-card-heading-gap);' ),
			array( 'ran-booster-onboarding.css', '\n\.ran-booster-admin \.ran-booster-portability__flow-header > p \{', 'margin-block-start: 0;' ),
		);
		foreach ( $owners as $owner ) {
			if ( 1 !== preg_match( '/' . $owner[1] . '([^}]*)\}/', $this->asset( $owner[0] ), $m ) ) {
				self::fail( $owner[0] . ': spacing owner ' . stripslashes( $owner[1] ) . ' is missing.' );
			}
			self::assertStringContainsString( $owner[2], $m[1], $owner[0] );
			self::assertDoesNotMatchRegularExpression( '/(?:margin|padding)[a-z-]*:[^;]*space-20/', $m[1], $owner[0] );
		}

		// The Logging heading no longer carries the paragraph's 16px bottom margin.
		self::assertStringNotContainsString( '.ran-booster-debug-capture > h3', $this->asset( 'ran-booster-onboarding.css' ) );

		// Bare Card headings take the shared title role, so the WordPress h3 margin never applies.
		foreach ( array(
			'troubleshooting-installation.php' => array( 'ran-booster-installation-heading', 'ran-booster-components-heading' ),
			'debug-capture.php'                => array( 'ran-booster-debug-capture-heading' ),
			'portability.php'                  => array( 'ran-booster-portability-mode-heading' ),
		) as $view => $ids ) {
			foreach ( $ids as $id ) {
				self::assertMatchesRegularExpression( '/<h3 id="' . $id . '" class="ran-booster-section__title( ran-booster-section__title--ruled)?"/', $this->view( $view ), $view . ' ' . $id );
			}
		}
	}

	/**
	 * Repository detail headings sit one heading gap above what they introduce,
	 * and its Cards, columns and tab row sit one Card gap apart (#746).
	 */
	public function test_repository_detail_spacing_uses_the_shared_heading_and_card_gaps(): void {
		$cards = 'ran-booster/30-provider-cards.css';
		$rows  = array(
			array( $cards, '\n\.ran-booster-admin \.ran-booster-repository-detail__header \{\n\tmargin-block-end:', ' var(--ran-booster-card-gap);' ),
			array( $cards, '\n\.ran-booster-admin \.ran-booster-repository-detail__tabs \{', 'margin-block: 0 var(--ran-booster-card-gap);' ),
			array( $cards, '\n\.ran-booster-admin \.ran-booster-repository-detail__header p \{', 'margin-block: var(--ran-booster-card-heading-gap) 0;' ),
			array( $cards, '\n\.ran-booster-admin \.ran-booster-repository-detail__layout \{', 'gap: var(--ran-booster-card-gap);' ),
			array( $cards, '\n\.ran-booster-admin\n\t\.ran-booster-repository-detail__main\n\t> \.ran-booster-settings-section \{', 'margin-block-end: var(--ran-booster-card-gap);' ),
			array( $cards, '\n\.ran-booster-admin\n\t\.ran-booster-repository-detail\n\t\.ran-booster-settings-section__header\n\tp,\n\.ran-booster-admin\n\t\.ran-booster-repository-detail\n\t\.ran-booster-readiness-panel__top\n\tp \{', 'margin-block: var(--ran-booster-card-heading-gap) 0;' ),
			array( $cards, '\n\.ran-booster-admin\n\t\.ran-booster-repository-detail\n\t\.ran-booster-settings-section__body\n\t> h4 \{', 'margin-block-end: var(--ran-booster-card-heading-gap);' ),
			array( $cards, '\n\.ran-booster-admin\n\t\.ran-booster-repository-detail\n\t> \.ran-booster-settings-section \{', 'margin-block-start: var(--ran-booster-card-gap);' ),
			array( $cards, '\n\.ran-booster-admin\n\t\.ran-booster-repository-detail\n\th4\n\t\+ \.ran-booster-repository-detail__facts \{', 'margin-block-start: 0;' ),
			array( 'ran-booster/80-responsive.css', '\n\t\.ran-booster-admin \.ran-booster-repository-detail__tabs \{', 'margin-block-end: var(--ran-booster-card-gap);' ),
		);
		foreach ( $rows as $row ) {
			if ( 1 !== preg_match( '/' . $row[1] . '([^}]*)\}/', $this->asset( $row[0] ), $m ) ) {
				self::fail( $row[0] . ': repository detail spacing owner ' . stripslashes( $row[1] ) . ' is missing.' );
			}
			self::assertStringContainsString( $row[2], $m[1], $row[0] );
		}

		// The retired 2px, 6px, 16px, 18px and 22px literals no longer set these gaps.
		self::assertDoesNotMatchRegularExpression( '/\.ran-booster-repository-detail__layout \{[^}]*space-22/', $this->asset( $cards ) );
		self::assertDoesNotMatchRegularExpression( '/\.ran-booster-repository-detail__header p \{[^}]*space-6/', $this->asset( $cards ) );
	}

	public function test_framed_fact_grids_share_one_summary_grid_profile(): void {
		$css = $this->asset( 'ran-booster/25-admin-primitives.css' );
		foreach ( array(
			'provider.php'      => 2,
			'packages/edit.php' => 1,
		) as $view => $expected ) {
			self::assertSame( $expected, substr_count( $this->view( $view ), 'ran-booster-summary-grid' ) - substr_count( $this->view( $view ), 'ran-booster-summary-grid__label' ) - substr_count( $this->view( $view ), 'ran-booster-summary-grid--standalone' ), $view );
		}
		self::assertStringContainsString( 'gap: 1px;', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-summary-grid > div {', $css );
		foreach ( array( 'repository-integration-status', 'readiness-overview' ) as $legacy ) {
			foreach ( array( '30-provider-cards.css', '80-responsive.css' ) as $file ) {
				self::assertStringNotContainsString( $legacy . ' > div', $this->asset( 'ran-booster/' . $file ) );
			}
		}
	}

	public function test_package_summary_contains_long_copy_and_truncates_only_the_repository_link(): void {
		$settings = $this->asset( 'ran-booster/65-package-settings.css' );

		self::assertStringContainsString( ".ran-booster-package-summary {\n\tposition: sticky;\n\tinset-block-start: 54px;\n\tmin-inline-size: 0;", $settings );
		self::assertStringContainsString( ".ran-booster-package-summary > div {\n\tmin-inline-size: 0;", $settings );
		self::assertStringContainsString( ".ran-booster-package-summary__meta {\n\tmargin:", $settings );
		self::assertStringContainsString( 'overflow-wrap: anywhere;', $settings );
		self::assertStringContainsString( '> .ran-booster-repository-link {', $settings );
		self::assertStringContainsString( 'text-overflow: ellipsis;', $settings );
		self::assertStringContainsString( 'white-space: nowrap;', $settings );
	}

	public function test_edit_source_tabs_reuse_the_repository_tab_strip_and_in_use_dot(): void {
		$settings = $this->asset( 'ran-booster/65-package-settings.css' );
		$provider = $this->asset( 'ran-booster/30-provider-cards.css' );
		$view     = $this->source( 'views/packages/source-choices.php' );
		$tabs     = $this->source( 'RAN/Admin/Component/SectionTabs.php' );

		// One definition of the strip classes and the dot, used by both screens.
		self::assertStringContainsString( "'ran-booster-provider-task-tabs'", $tabs );
		self::assertStringContainsString( "'filter-links ran-section-navigation ran-section-navigation--thin'", $tabs );
		self::assertStringContainsString( 'ran-booster-provider-task-tab ran-section-navigation__link', $tabs );
		self::assertStringContainsString( 'SectionTabs::TAB_CLASS', $view );
		self::assertStringContainsString( 'SectionTabs::print_in_use_marker()', $view );
		self::assertStringContainsString( 'SectionTabs::TAB_CLASS', $this->source( 'RAN/Admin/Component/RepositoryDetailRenderer.php' ) );
		self::assertStringContainsString( 'SectionTabs::print_in_use_marker()', $this->source( 'RAN/Admin/Component/RepositoryDetailRenderer.php' ) );

		// The tab strip and dot are styled once, in the repository stylesheet; Edit adds only button resets.
		self::assertSame( 1, substr_count( $provider, '.ran-booster-provider-task-tab__source-indicator {' ) );
		self::assertSame( 1, substr_count( $provider, '.ran-booster-provider-task-tabs {' ) );
		self::assertStringNotContainsString( 'source-indicator', $settings );
		self::assertStringNotContainsString( 'nav-tab', $settings );
		self::assertStringNotContainsString( 'current-source', $settings );
		self::assertStringNotContainsString( 'StatusBadge', $view );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-source-choices--navigation {', $settings );
		self::assertStringContainsString( '.ran-booster-admin button.ran-booster-source-choice--navigation {', $settings );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-source-choice--navigation.is-disabled {', $settings );
		self::assertStringNotContainsString( 'var(--ran-booster-status-ok-background)', $settings );
	}

	private function assert_general_field_label_rule( string $css ): void {
		if ( 1 !== preg_match( '/:is\(\.ran-booster-admin, \.ran-booster-repository-picker-portal\)\s+:is\(\s*label:not\(([^{]*?)\),\s*\.ran-booster-settings-field > label,\s*\.ran-booster-credential-modal__form label\s*\)\s*\{([^}]*)\}/', $css, $m ) ) {
			self::fail( 'The shared form field label rule is missing.' );
		}
		self::assertStringContainsString( 'font-weight: var(--ran-booster-font-weight-semibold);', $m[2] );
		self::assertStringContainsString( 'font-size: var(--ran-booster-font-size-md);', $m[2] );
		// The documented exception list: option labels, table row names and screen reader labels only.
		$exceptions = preg_split( '/,(?![^()]*\))/', $m[1] );
		if ( false === $exceptions ) {
			self::fail( 'The label exception list could not be split.' );
		}
		self::assertSame(
			array( '.screen-reader-text', '.ran-booster-portability__credential-selection', 'td > label', ':has(input[type="checkbox"], input[type="radio"])' ),
			array_map( 'trim', $exceptions )
		);
	}

	public function test_every_form_field_label_shares_one_weight_and_size_rule(): void {
		$css = $this->asset( 'ran-booster.css' );
		$this->assert_general_field_label_rule( $css );

		// Per-label rules add layout only; none re-declares the label weight.
		foreach ( array(
			'ran-booster/65-package-settings.css'  => '/\.ran-booster-settings-field > label,[^{]*\{([^}]*)\}/',
			'ran-booster/20-repository-picker.css' => '/\.ran-booster-repository-picker__public-search > label,[^{]*\{([^}]*)\}/',
			'ran-booster/30-provider-cards.css'    => '/\.ran-booster-provider-receiver__field > label \{([^}]*)\}/',
			'ran-booster/50-troubleshooting-and-activity.css' => '/\.ran-booster-troubleshooting__fields label \{([^}]*)\}/',
			'ran-booster/70-credential-dialog.css' => '/\.ran-booster-credential-modal__form label \{([^}]*)\}/',
		) as $file => $pattern ) {
			if ( 1 !== preg_match( $pattern, $this->asset( $file ), $rule ) ) {
				self::fail( 'Missing label layout rule in ' . $file );
			}
			self::assertStringNotContainsString( 'font-weight', $rule[1], $file );
		}
	}

	public function test_no_component_restyles_a_form_label_outside_the_shared_rule(): void {
		// Selectors that style a label element or a label-named field class may add layout only.
		$allowed = array(
			// Option and toggle labels keep their own deliberate weight.
			'.ran-booster-admin .ran-booster-package-danger-zone__actions label',
			'.ran-booster-admin .ran-booster-settings__switch-label',
			// Paragraph (not a label element) that names a button group.
			'.ran-booster-admin .ran-booster-repository-webhook-management p.ran-booster-public-lookup-profile__label',
		);
		$files   = array_merge(
			(array) glob( dirname( __DIR__, 2 ) . '/assets/*.css' ),
			(array) glob( dirname( __DIR__, 2 ) . '/assets/ran-booster/*.css' )
		);
		$checked = 0;
		foreach ( $files as $file ) {
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', $this->raw_asset( str_replace( dirname( __DIR__, 2 ) . '/assets/', '', (string) $file ) ) );
			if ( 1 > preg_match_all( '/([^{}@;]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				$selector = (string) preg_replace( '/\s+/', ' ', trim( $rule[1] ) );
				if ( 1 !== preg_match( '/(?<![\w.#-])label(?![\w-])|(?:public-lookup-profile|field|form)__label/', $selector ) ) {
					continue;
				}
				if ( 1 !== preg_match( '/(?:^|;|\s)(font-weight|font-size)\s*:/', $rule[2] ) ) {
					continue;
				}
				if ( str_contains( $selector, ':is( label:not(' ) || in_array( $selector, $allowed, true ) ) {
					continue;
				}
				++$checked;
				self::fail( basename( (string) $file ) . ' restyles a label font outside the shared label rule: ' . $selector );
			}
		}
		self::assertSame( 0, $checked );
	}

	public function test_package_operation_card_stacks_select_description_notice_guidance_then_actions(): void {
		$css  = $this->asset( 'ran-booster/65-package-settings.css' );
		$rule = static function ( string $css, string $selector ): string {
			if ( 1 !== preg_match( '/' . str_replace( ' ', '\s+', $selector ) . '\s*\{([^}]*)\}/', $css, $m ) ) {
				self::fail( 'Missing CSS rule ' . stripslashes( $selector ) );
			}
			return $m[1];
		};

		// The card body is the query container for the narrow wrap.
		self::assertStringContainsString( 'container-type: inline-size;', $rule( $css, '\.ran-booster-admin \.ran-booster-package-operation-settings \.ran-booster-settings-section__body' ) );

		// The Updates label shares the field label layout; its weight comes from the one shared label rule.
		if ( 1 !== preg_match( '/\.ran-booster-settings-field > label,\s*\.ran-booster-admin \.ran-booster-deployment-policy-group > label \{([^}]*)\}/', $css, $label ) ) {
			self::fail( 'The Updates label must share the field label rule.' );
		}
		self::assertStringNotContainsString( 'font-weight', $label[1] );

		// Group, notice, guidance and actions are 12px apart; the notice adds no margin of its own.
		$field = $rule( $css, '\.ran-booster-admin \.ran-booster-deployment-policy-field--operation' );
		self::assertStringContainsString( 'display: grid;', $field );
		self::assertStringContainsString( 'gap: var(--ran-booster-space-12);', $field );
		self::assertStringContainsString( 'margin: 0;', $rule( $css, '\.ran-booster-admin \.ran-booster-deployment-policy-field--operation > \.notice' ) );
		self::assertStringContainsString( 'margin: 0;', $rule( $css, '\.ran-booster-admin\n\t\.ran-booster-deployment-policy-field--operation\n\t> \.description' ) );

		// The description is tucked 4px under the select and spans the full group width.
		$description = $rule( $css, '\.ran-booster-admin \.ran-booster-deployment-policy-group > \.description' );
		self::assertStringContainsString( 'margin-block: var(--ran-booster-space-4) 0;', $description );
		self::assertStringContainsString( 'max-inline-size: none;', $description );

		// The select owns its row at 40px; the old select-plus-action row is gone.
		self::assertStringContainsString( 'min-block-size: 40px;', $rule( $css, '\.ran-booster-admin \.ran-booster-deployment-policy-row > select' ) );
		self::assertStringNotContainsString( 'deployment-policy-row--with-action', $css );
		self::assertStringNotContainsString( 'package-operation-settings__body--split', $css );

		// Buttons sit side by side at equal widths, 8px apart.
		$actions = $rule( $css, '\.ran-booster-admin \.ran-booster-package-operation-settings__actions' );
		self::assertStringContainsString( 'display: flex;', $actions );
		self::assertStringContainsString( 'flex-wrap: nowrap;', $actions );
		self::assertStringContainsString( 'gap: var(--ran-booster-space-8);', $actions );
		self::assertStringNotContainsString( 'justify-content: flex-end', $actions );
		$button = $rule( $css, '\.ran-booster-admin\n\t\.ran-booster-package-operation-settings__actions\n\t\.ran-wp-ui-button' );
		self::assertStringContainsString( 'flex: 1 1 0;', $button );
		self::assertStringContainsString( 'min-block-size: 40px;', $button );

		// Narrow card: the buttons stack at full width.
		if ( 1 !== preg_match( '/@container \(max-width: 36rem\) \{(.*?)\n\}/s', $css, $narrow ) ) {
			self::fail( 'The Package operation narrow container query is missing.' );
		}
		self::assertMatchesRegularExpression( '/__actions \{\s*flex-direction: column;/', $narrow[1] );
		self::assertMatchesRegularExpression( '/\.ran-wp-ui-button \{\s*flex: 0 0 auto;\s*inline-size: 100%;/', $narrow[1] );

		// The Release track card no longer carries a footer wrapper for Update now.
		self::assertStringNotContainsString( 'managed-release-browser__footer', $this->asset( 'ran-booster-release-management.css' ) );
	}

	public function test_first_settings_group_drops_only_its_divider_inside_the_settings_card_body(): void {
		$css = $this->asset( 'ran-booster/65-package-settings.css' );
		if ( 1 !== preg_match( '/\n\t\.ran-booster-settings__group:first-of-type \{([^}]*)\}/', $css, $m ) ) {
			self::fail( 'The first settings group rule is missing.' );
		}
		// The Card header already draws a divider, so the first group sits flush with the body without its own rule.
		self::assertStringContainsString( 'margin-block-start: 0;', $m[1] );
		self::assertStringContainsString( 'padding-block-start: 0;', $m[1] );
		self::assertStringContainsString( 'border-block-start: 0;', $m[1] );
		self::assertStringNotContainsString( 'display', $m[1] );
		self::assertStringNotContainsString( 'font', $m[1] );
		// It is scoped to the Settings Card body, never to every Card (the old content-wide first-of-type rule removed other pages' dividers).
		self::assertMatchesRegularExpression( '/\.ran-booster-admin\s*\.ran-booster-settings__card-body\s*> \[data-ran-booster-general-scope\]:nth-child\(\s*1 of \[data-ran-booster-general-scope\]\s*\)\s*\.ran-booster-settings__group:first-of-type \{/', $css );
		self::assertStringNotContainsString( '.ran-booster-card__content > [data-ran-booster-general-scope]', str_replace( array( "\n", "\t" ), ' ', $css ) );
		// Later groups keep the shared divider rule.
		if ( 1 !== preg_match( '/\n\.ran-booster-admin \.ran-booster-settings__group \{([^}]*)\}/', $css, $group ) ) {
			self::fail( 'The shared settings group rule is missing.' );
		}
		self::assertStringContainsString( 'border-block-start: 1px solid var(--ran-booster-divider);', $group[1] );
	}

	public function test_settings_cards_share_one_inline_inset_for_header_body_and_footer(): void {
		$css = $this->asset( 'ran-booster/65-package-settings.css' );
		// One declaration owns the value; nothing else in the Settings Card rules spells 24px.
		self::assertSame( 1, preg_match_all( '/--ran-booster-settings-card-inset: var\(--ran-booster-space-24\);/', $css ) );
		self::assertMatchesRegularExpression( '/\.ran-booster-settings__card \{\s*--ran-booster-settings-card-inset:/', $css );
		// Header (mounted button and native summary, plus the body-less header row) use the token as inline padding only.
		if ( 1 !== preg_match( '/\[role="button"\],[^{]*> summary,[^{]*\.ran-booster-troubleshooting__entry-row \{([^}]*)\}/', $css, $header ) ) {
			self::fail( 'The Settings Card header inset rule is missing.' );
		}
		self::assertStringContainsString( 'padding-inline: var(--ran-booster-settings-card-inset);', $header[1] );
		self::assertStringNotContainsString( 'padding-block', $header[1] );
		self::assertDoesNotMatchRegularExpression( '/padding: /', $header[1] );
		// The Installation Cards keep their own header padding: nothing in the Settings rules targets them.
		self::assertStringNotContainsString( 'installation-section', $header[0] );
		// Body and footer use the same token.
		self::assertStringContainsString( 'padding: var(--ran-booster-space-16) var(--ran-booster-settings-card-inset);', $css );
		self::assertStringContainsString( 'margin-inline: calc(-1 * var(--ran-booster-settings-card-inset));', $css );
		if ( 1 !== preg_match( '/\.ran-booster-settings__card-body--footed \{\s*padding-block-end: 0;/', $css ) ) {
			self::fail( 'The footed body rule is missing.' );
		}
		// The vertical header padding is still the shared 16px.
		self::assertStringContainsString( 'padding-block: 16px;', $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' ) );
	}

	public function test_storage_facts_inline_short_rows_and_align_the_note_with_the_field_text(): void {
		$css = $this->asset( 'ran-booster-onboarding.css' );
		if ( 1 !== preg_match( '/__storage-fact--inline \{([^}]*)\}/', $css, $inline ) ) {
			self::fail( 'The inline storage fact rule is missing.' );
		}
		self::assertStringContainsString( 'display: flex;', $inline[1] );
		self::assertStringContainsString( 'align-items: baseline;', $inline[1] );
		self::assertStringContainsString( 'gap: var(--ran-booster-space-12);', $inline[1] );
		self::assertMatchesRegularExpression( '/__storage-fact--inline\s*> :is\(dt, dd\) \{\s*margin: 0;/', $css );
		// The note's inset is the field's own padding plus border, from the same custom properties the field uses.
		self::assertMatchesRegularExpression( '/--ran-booster-storage-field-inset: calc\(\s*var\(--ran-booster-storage-field-padding-inline\) \+\s*var\(--ran-booster-storage-field-border\)\s*\);/', $css );
		self::assertMatchesRegularExpression( '/padding-inline: var\(\s*--ran-booster-storage-field-padding-inline,/', $css );
		self::assertStringContainsString( 'border: var(--ran-booster-storage-field-border, 1px) solid', $css );
		// WordPress core gives code a 1px inline margin; the field resets it so the note's inset is exactly padding plus border.
		self::assertMatchesRegularExpression( '/__storage-facts code \{\s*display: block;\s*margin-inline: 0;/', $css );
		if ( 1 !== preg_match( '/__storage-facts-note \{([^}]*)\}/', $css, $note ) ) {
			self::fail( 'The storage facts note rule is missing.' );
		}
		self::assertStringContainsString( 'margin-block: var(--ran-booster-space-6) 0;', $note[1] );
		self::assertStringContainsString( 'margin-inline: var(--ran-booster-storage-field-inset) 0;', $note[1] );
		// Only the path rows stay stacked, so the view marks the short rows rather than the long ones.
		self::assertSame( 2, substr_count( $this->view( 'settings-storage.php' ), 'ran-booster-onboarding__storage-fact--inline' ) );
	}

	public function test_narrow_card_headers_give_the_strapline_the_full_header_width(): void {
		$css = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );
		self::assertMatchesRegularExpression( '/\.ran-booster-troubleshooting__installation-entry \{\s*container-type: inline-size;/', $css );
		if ( 1 !== preg_match( '/@container \(max-width: 40rem\) \{(.*?)\n\}\n/s', $css, $narrow ) ) {
			self::fail( 'The narrow Card header container query is missing.' );
		}
		$narrow = $narrow[1];
		// The mounted button, the native summary and the body-less row become one grid.
		self::assertMatchesRegularExpression( '/\[role="button"\],[^{]*> summary,[^{]*__entry-row \{[^}]*display: grid;[^}]*grid-template-columns: minmax\(0, 1fr\) auto auto;/', $narrow );
		// The wrappers stop generating boxes so title, badge, chevron and strapline are the grid items.
		self::assertMatchesRegularExpression( '/:first-child,[^{]*> \[aria-hidden="true"\],[^{]*__entry-heading \{\s*display: contents;/', $narrow );
		self::assertMatchesRegularExpression( '/__entry-title \{\s*grid-row: 1;\s*grid-column: 1;/', $narrow );
		self::assertMatchesRegularExpression( '/__entry-meta\s*\) \{\s*grid-row: 1;\s*grid-column: 2;/', $narrow );
		self::assertMatchesRegularExpression( '/> :last-child \{\s*grid-row: 1;\s*grid-column: 3;/', $narrow );
		// The strapline spans every column on its own row under the title, badge and chevron.
		self::assertMatchesRegularExpression( '/__entry-description \{\s*grid-row: 2;\s*grid-column: 1 \/ -1;/', $narrow );
		// The padding belongs to the header rules (16px Installation, 24px Settings), never to the narrow rule.
		self::assertStringNotContainsString( 'padding', $narrow );
		// The wide layout and the markup stay as they were: no viewport media query carries these rules.
		self::assertDoesNotMatchRegularExpression( '/@media[^{]*\{[^@]*display: contents/', $css );
	}

	public function test_settings_cards_leave_no_orphaned_panel_card_or_storage_details_rules(): void {
		$settings   = $this->asset( 'ran-booster/65-package-settings.css' );
		$onboarding = $this->asset( 'ran-booster-onboarding.css' );
		self::assertStringNotContainsString( '.ran-booster-settings > .ran-booster-panel', $settings );
		self::assertStringNotContainsString( '.ran-booster-settings__general > .ran-booster-panel', $settings );
		self::assertStringNotContainsString( 'data-ran-booster-card="settings"', $settings );
		self::assertStringNotContainsString( 'data-ran-booster-card="settings"', $onboarding );
		self::assertStringNotContainsString( 'ran-booster-onboarding__storage-details', $onboarding );
		self::assertStringNotContainsString( 'aria-labelledby="ran-booster-storage"', $onboarding );
	}

	public function test_settings_cards_reuse_the_disclosure_header_divider_and_own_a_footer_band(): void {
		$settings   = $this->asset( 'ran-booster/65-package-settings.css' );
		$primitives = $this->asset( 'ran-booster/25-admin-primitives.css' );
		// Header: the prominent shared Disclosure header, 16px padding closed and open (see the Installation contract above).
		self::assertMatchesRegularExpression( '/\[data-ran-booster-ui-disclosure-density="prominent"\]\s*> \*\s*> \.ran-booster-ui-disclosure\s*> \.ran-booster-ui-disclosure__header\s*> \[role="button"\] \{\s*padding: 12px 16px;/', $primitives );
		// Divider under the header only while open, from the shared rule for every Disclosure (no Settings copy).
		self::assertMatchesRegularExpression( '/\.ran-booster-ui-disclosure\[data-open\]\s*> \.ran-booster-ui-disclosure__header,[^{]*> summary \{\s*box-shadow: 0 1px 0 var\(--ran-booster-divider\);/', $primitives );
		self::assertStringNotContainsString( 'settings-card', $primitives );
		// Body inset and the Card gap.
		self::assertMatchesRegularExpression( '/\.ran-booster-settings__card \{[^}]*margin-block: 0 var\(--ran-booster-card-gap\);/', $settings );
		// An open body keeps 16px above its content, the shared 32px Card block-end inset below it and 24px at the sides.
		self::assertMatchesRegularExpression( '/\.ran-booster-settings__card-body\.ran-booster-disclosure__body \{\s*padding-block: var\(--ran-booster-space-16\)\s*var\(--ran-booster-card-inset-block-end\);\s*padding-inline: var\(--ran-booster-settings-card-inset\);/', $settings );
		self::assertMatchesRegularExpression( '/\.ran-booster-settings__card-body:not\(\s*\.ran-booster-settings__card-body--footed\s*\)\s*> :last-child\s*\.ran-booster-settings__group:last-of-type \{\s*margin-block-end: 0;/', $settings );
		// Footer: full-width band, 1px divider above, 16px 24px padding, action at the start; it bleeds through the body inset
		// and the footed body drops its bottom padding so the band meets the Card edge.
		if ( 1 !== preg_match( '/\.ran-booster-settings__save-actions\.ran-booster-settings__card-footer \{([^}]*)\}/', $settings, $footer ) ) {
			self::fail( 'The Settings Card footer rule is missing.' );
		}
		self::assertStringContainsString( 'display: flex;', $footer[1] );
		self::assertStringContainsString( 'justify-content: flex-start;', $footer[1] );
		self::assertStringContainsString( 'margin-inline: calc(-1 * var(--ran-booster-settings-card-inset));', $footer[1] );
		self::assertStringContainsString( 'padding: var(--ran-booster-space-16) var(--ran-booster-settings-card-inset);', $footer[1] );
		self::assertStringContainsString( 'border-block-start: 1px solid var(--ran-booster-divider);', $footer[1] );
		self::assertMatchesRegularExpression( '/\.ran-booster-settings__card-body--footed \{\s*padding-block-end: 0;/', $settings );
		// The footer lives in the body, so a collapsed Card hides it: no rule may force it visible or take it out of the panel.
		self::assertStringNotContainsString( 'card-footer[hidden]', $settings );
		self::assertDoesNotMatchRegularExpression( '/card-footer[^{]*\{[^}]*(position: (fixed|sticky)|display: contents)/', $settings );
		// Native fallback: the same body and footer sit inside the details element, which hides them when closed.
		$view = $this->view( 'settings-general.php' );
		self::assertMatchesRegularExpression( '/<\/fieldset><div class="ran-booster-settings__save-actions ran-booster-settings__card-footer" data-ran-booster-card-footer><button id="ran-booster-save-numeric-bottom"/', $view );
	}

	/**
	 * Page and Card action groups finish their surface at the start edge (action placement standard in the
	 * composition contract). Dialog footers, table row groups and toolbars are deliberately not listed here.
	 */
	public function test_page_and_card_action_groups_are_start_aligned(): void {
		$groups = array(
			'ran-booster-settings__card-footer',
		);

		$css         = '';
		$stylesheets = glob( dirname( __DIR__, 2 ) . '/assets/{ran-booster/*.css,*.css}', GLOB_BRACE );
		foreach ( false === $stylesheets ? array() : $stylesheets as $file ) {
			if ( str_contains( $file, 'portability' ) ) {
				continue;
			}
			$css .= "\n" . (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
		}
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER );

		foreach ( $groups as $group ) {
			$declared_start = false;
			foreach ( $rules as $rule ) {
				$targets_group = false;
				foreach ( explode( ',', $rule[1] ) as $part ) {
					if ( 1 === preg_match( '/\.' . preg_quote( $group, '/' ) . '(?:\.[\w-]+)*\s*$/', $part ) ) {
						$targets_group = true;
					}
				}
				if ( ! $targets_group ) {
					continue;
				}
				if ( str_contains( $rule[2], 'justify-content: flex-start;' ) ) {
					$declared_start = true;
				}
				self::assertDoesNotMatchRegularExpression( '/justify-content:\s*(?!flex-start)[a-z-]+;/', $rule[2], $group . ' must not re-align its actions away from the start.' );
				self::assertDoesNotMatchRegularExpression( '/(float:\s*(right|inline-end)|text-align:\s*(right|end)|margin-(left|inline-start):\s*auto)/', $rule[2], $group . ' must not push its actions to the end.' );
			}
			self::assertTrue( $declared_start, $group . ' must declare justify-content: flex-start.' );
		}
	}

	public function test_source_tab_strip_keeps_the_same_gap_above_every_pane(): void {
		$css = $this->asset( 'ran-booster/65-package-settings.css' );
		// Hidden inactive panes sit between the strip and the visible pane, so the rule must use the general sibling combinator.
		self::assertStringNotContainsString( '--navigation\n\t+ .ran-booster-package-source-pane', $css );
		self::assertMatchesRegularExpression( '/--navigation\s*~ \.ran-booster-package-source-pane \{\s*margin-block-start: 0;/', $css );
		self::assertMatchesRegularExpression( '/--navigation\s*~ \.ran-booster-release-pane \{\s*padding-block-start: 0;/', $css );
		self::assertMatchesRegularExpression( '/--navigation\s*~ \.ran-booster-release-pane\s*> \.ran-booster-package-source-pane__description \{\s*margin-block-start: var\(--ran-booster-space-16\);/', $css );
	}

	public function test_package_source_repository_guidance_is_a_shared_static_notice(): void {
		$view     = $this->source( 'views/packages/source-choices.php' );
		$settings = $this->asset( 'ran-booster/65-package-settings.css' );

		self::assertStringContainsString( '<div class="notice notice-info inline ran-booster-package-source__guidance ran-wp-ui-notice ran-wp-ui-box-sizing is-info" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="info" data-ran-booster-source-repository-guidance', $view );
		self::assertStringContainsString( "\\RAN\\Admin\\Component\\NoticeIcon::print_intent( 'info' )", $view );
		self::assertStringNotContainsString( '<p class="ran-booster-package-source__guidance"', $view );
		$hook_start = (int) strpos( $view, 'data-ran-booster-source-repository-guidance' );
		$notice     = substr( $view, $hook_start, (int) strpos( $view, "\n", $hook_start ) - $hook_start );
		self::assertStringNotContainsString( 'role=', $notice );
		self::assertStringNotContainsString( 'aria-live', $notice );
		$rule_start = (int) strpos( $settings, '.ran-booster-admin .ran-booster-package-source__guidance {' );
		$rule       = substr( $settings, $rule_start, (int) strpos( $settings, '}', $rule_start ) - $rule_start );
		self::assertStringNotContainsString( 'border-inline-start', $rule );
		self::assertStringNotContainsString( 'background', $rule );
	}

	public function test_notice_disclosures_take_a_full_width_row_below_the_icon_and_message(): void {
		$css = $this->asset( 'ran-booster/25-admin-primitives.css' );

		if ( 1 !== preg_match( '/\.ran-wp-ui-notice > \[data-ran-booster-ui-disclosure\] \{([^}]*)\}/', $css, $rule ) ) {
			self::fail( 'The notice disclosure row rule is missing.' );
		}
		// Row two, spanning every column of the Notice grid, so the icon column is not left empty beside it.
		self::assertStringContainsString( 'grid-column: 1 / -1;', $rule[1] );
		self::assertStringContainsString( 'grid-row: 2;', $rule[1] );
		self::assertStringContainsString( 'min-inline-size: 0;', $rule[1] );
		// The gap above comes from the shared spacing token, never a literal, and the rule adds no padding, border or colour.
		self::assertStringContainsString( 'margin-block-start: var(--wpds-dimension-gap-md);', $rule[1] );
		self::assertStringNotContainsString( 'padding', $rule[1] );
		self::assertStringNotContainsString( 'color', $rule[1] );
		self::assertStringNotContainsString( 'border', $rule[1] );
		self::assertDoesNotMatchRegularExpression( '/\.ran-wp-ui-notice__description\s*>\s*\[data-ran-booster-ui-disclosure\]/', $css );
	}

	public function test_notice_views_print_their_disclosure_after_the_icon(): void {
		$display = $this->source( 'RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php' );

		// Each failure notice closes its message column, prints the icon, then the disclosure, then closes the notice.
		self::assertSame( 1, preg_match_all( '/<\/div><\?php [^;]*print_intent\( \'warning\' \); \?>\s*<\?php \$this->render_failure_help\( \$failure_code, \'\', \$status_reason \); \?><\/div>/', $display ) );
		self::assertSame( 1, preg_match_all( '/print_intent\( substr\( \$tone, 7 \) \); \?>\s*<\?php if \( ! \$successful && \$this->is_diagnostic_code\( \$code \) \) \{ \?>\s*<\?php \$this->render_failure_help\( \$code, \$message \); \?>\s*<\?php \} \?>\s*<\/div>/', $display ) );
	}

	public function test_repository_branch_check_notice_sits_flush_beside_the_action(): void {
		$settings = $this->asset( 'ran-booster/65-package-settings.css' );

		self::assertStringContainsString(
			".ran-booster-readiness-actions > .notice {\n\tflex-basis: 100%;\n\tmargin: 0;",
			$settings
		);
	}

	public function test_repository_status_markers_keep_the_shared_checklist_footprint(): void {
		$foundations        = $this->asset( 'ran-booster/00-foundations.css' );
		$package_settings   = $this->asset( 'ran-booster/65-package-settings.css' );
		$webhook_management = $this->asset( 'ran-booster-repository-webhook-management.css' );

		self::assertStringContainsString( '--ran-booster-status-marker-size: 18px;', $foundations );
		self::assertStringContainsString( '--ran-booster-status-marker-border-width: 2px;', $foundations );
		self::assertStringContainsString( '--ran-booster-status-marker-font-size: 12px;', $foundations );
		self::assertStringContainsString(
			".ran-booster-admin .ran-booster-readiness-icon {\n\tdisplay: grid;\n\tbox-sizing: content-box;\n\tinline-size: var(--ran-booster-status-marker-size);",
			$package_settings
		);
		self::assertStringContainsString( 'min-inline-size: var(--ran-booster-status-marker-size);', $package_settings );
		self::assertStringContainsString( 'aspect-ratio: 1;', $package_settings );
		self::assertStringContainsString( 'font-variant-numeric: tabular-nums;', $package_settings );
		self::assertSame(
			2,
			substr_count( $package_settings, 'var(--ran-booster-status-marker-size) +' )
		);
		self::assertStringNotContainsString( 'grid-template-columns: 20px', $package_settings );
		self::assertStringNotContainsString( '.ran-booster-repository-webhook-readiness', $webhook_management );
		self::assertStringContainsString(
			".ran-booster-repository-release-readiness\n\t.ran-booster-readiness-icon {\n\tbox-sizing: content-box;",
			$webhook_management
		);
		// Steps reflow by the Card body's width, never by an auto-fit wrap that orphans a step.
		self::assertStringNotContainsString( 'auto-fit', $webhook_management );
		self::assertStringNotContainsString( '--ran-booster-step-min-inline-size', $webhook_management );
		self::assertMatchesRegularExpression( '/\.ran-booster-repository-release-section\s*\)\s*>\s*\.ran-wp-ui-card\s*\.ran-booster-settings-section__body\s*\{\s*container-type: inline-size;/', $webhook_management );
		self::assertMatchesRegularExpression( '/\.ran-booster-webhook-steps \{[^}]*grid-template-columns: minmax\(0, 1fr\);/', $webhook_management );
		self::assertMatchesRegularExpression( '/@container \(min-width: 440px\) \{\s*\.ran-booster-admin \.ran-booster-webhook-steps \{\s*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/', $webhook_management );
		self::assertMatchesRegularExpression( '/@container \(min-width: 440px\)[^@]*\.ran-booster-repository-release-lifecycle \{\s*grid-auto-flow: column;[^}]*grid-auto-columns: minmax\(0, 1fr\);/', $webhook_management );
		self::assertMatchesRegularExpression( '/@container \(min-width: 868px\) \{[^@]*:not\(\s*\.ran-booster-repository-release-lifecycle\s*\)\s*\{\s*grid-template-columns: repeat\(4, minmax\(0, 1fr\)\);/', $webhook_management );
		// Each step's state is a glyph in its marker, drawn like the readiness icons; an optional step never set up is neutral.
		foreach ( array(
			'is-ok'      => '✓',
			'is-warning' => '!',
			'is-pending' => '?',
			'is-neutral' => '–',
		) as $state => $glyph ) {
			self::assertStringContainsString( ".ran-booster-admin .ran-booster-webhook-step.{$state} > span::after {\n\tcontent: \"{$glyph}\";", $webhook_management, $state );
		}
		self::assertStringContainsString( ".ran-booster-webhook-step.is-neutral > span {\n\tcolor: var(--ran-booster-text-muted);", $webhook_management );
		self::assertDoesNotMatchRegularExpression( '/max-width: 782px\)\s*\{\s*\.ran-booster-admin \.ran-booster-webhook-steps/', $webhook_management );
		self::assertStringContainsString( "grid-template-rows: auto 1fr;\n\talign-content: start;", $webhook_management );
		// WordPress gives every li a 6px bottom margin; without a reset all but the last step in a row are shorter.
		self::assertMatchesRegularExpression( '/\.ran-booster-webhook-step\s*\{[^}]*\n\tmargin:\s*0;/', $webhook_management );
		// Two-tier publishing: the header badge sits one heading gap after its title, the delivery line and the Push-to-deploy tier sit one card gap apart, and the tier heading uses the shared heading size and gap.
		self::assertMatchesRegularExpression( '/\.ran-booster-repository-publishing__heading\s*\{[^}]*display: flex;[^}]*gap: var\(--ran-booster-space-8\);/', $webhook_management );
		self::assertMatchesRegularExpression( '/\.ran-booster-repository-publishing__row\s*\{[^}]*display: flex;[^}]*justify-content: space-between;/', $webhook_management );
		self::assertStringNotContainsString( 'ran-booster-repository-publishing__line', $webhook_management );
		self::assertMatchesRegularExpression( '/\.ran-booster-repository-publishing__subheading\s*\{[^}]*gap: var\(--ran-booster-space-8\);[^}]*margin: 0 0 var\(--ran-booster-space-8\);[^}]*font-size: var\(--ran-booster-font-size-lg\);/', $webhook_management );
		// The webhook notices wrapper owns the gap between a notice and the steps below it.
		self::assertMatchesRegularExpression( '/\.ran-booster-repository-webhook-management__notices\s*\{[^}]*margin-block:\s*0 var\(--ran-booster-space-16\)/', $webhook_management );
		// The operation result sits exactly one section gap (16px) under the Webhook group, with no extra margin from the notice itself.
		self::assertMatchesRegularExpression( '/\.ran-booster-repository-webhook-management__result\s*\{[^}]*margin-block:\s*var\(--ran-booster-space-16\) 0;/', $webhook_management );
		self::assertMatchesRegularExpression( '/\.ran-booster-repository-webhook-management__result \.notice\s*\{\s*margin:\s*0;/', $webhook_management );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-webhook-step.is-ok > span {', $webhook_management );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-webhook-step.is-warning > span {', $webhook_management );
		self::assertStringContainsString(
			".ran-booster-admin .ran-booster-webhook-step {\n\tdisplay: grid;\n\tgrid-template-columns:",
			$webhook_management
		);
		self::assertStringContainsString(
			'var(--ran-booster-status-marker-size)',
			$webhook_management
		);
		self::assertStringContainsString(
			".ran-booster-admin .ran-booster-webhook-step > span {\n\tdisplay: grid;\n\tgrid-row: span 2;\n\tbox-sizing: content-box;\n\tinline-size: var(--ran-booster-status-marker-size);",
			$webhook_management
		);
		self::assertStringContainsString( 'max-block-size: var(--ran-booster-status-marker-size);', $webhook_management );
		self::assertStringContainsString( 'line-height: 1;', $webhook_management );
		// Repository disclosures use the standard disclosure border; no nested-panel reset may strip it.
		$provider_cards = $this->asset( 'ran-booster/30-provider-cards.css' );
		foreach ( array( 'repository-webhook-setup', 'repository-release-readiness', 'repository-release-automation' ) as $class ) {
			self::assertStringNotContainsString( '.ran-booster-' . $class, $provider_cards, $class );
		}
		// Disclosures after each other sit one Card gap apart; the step list owns the gap above the first.
		self::assertMatchesRegularExpression( '/\+ \[data-ran-booster-ui-disclosure\] \{\s*margin-block-start: var\(--ran-booster-card-gap\);/', $this->asset( 'ran-booster/25-admin-primitives.css' ) );
		self::assertMatchesRegularExpression( '/\.ran-booster-webhook-steps \{[^}]*margin: 0 0 var\(--ran-booster-card-gap\);/', $webhook_management );
		// The release workflow badge keeps the heading gap from its title.
		self::assertMatchesRegularExpression( '/\[data-ran-booster-ui-disclosure="repository-release-workflow"\]\s*\.ran-booster-ui-disclosure__meta \{\s*margin-inline-start: var\(--ran-booster-space-8\);/', $this->asset( 'ran-booster/25-admin-primitives.css' ) );
	}

	public function test_repository_webhook_setup_keeps_its_form_full_width(): void {
		$webhook_management = $this->asset( 'ran-booster-repository-webhook-management.css' );
		$controls           = $this->source( 'RAN/Admin/WebhookManagement/RepositoryWebhookManagementControls.php' );

		self::assertStringContainsString( 'data-ran-booster-ui-disclosure="repository-webhook-setup"', $controls );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-setup ran-booster-disclosure', $controls );
		self::assertStringContainsString( 'ran-booster-repository-webhook-setup__body ran-booster-disclosure__body', $controls );
		self::assertStringNotContainsString( 'ran-booster-readiness-panel', $controls );
		self::assertStringNotContainsString( 'ran-booster-repository-webhook-management__manage-link', $webhook_management );

		// The Disclosure replaces the native details element, so the layout must not depend on its class.
		self::assertStringNotContainsString( '.ran-booster-repository-webhook-setup\n', $webhook_management );
		self::assertMatchesRegularExpression(
			'/\.ran-booster-repository-webhook-management\s+\.ran-booster-repository-webhook-management__layout \{\s*display: block;\s*padding: 0;\s*border-block-start: 0;\s*\}/',
			$webhook_management
		);
		self::assertMatchesRegularExpression(
			'/\.ran-booster-repository-webhook-management\s+\.ran-booster-repository-webhook-management__form \{\s*display: grid;\s*grid-template-columns: minmax\(0, 1fr\);\s*gap: var\(--ran-booster-space-20\);\s*padding: 0;\s*\}/',
			$webhook_management
		);
		// The tier strapline: no top margin (the sub-heading owns the 8px gap), a 16px gap before the steps.
		self::assertMatchesRegularExpression( '/\.ran-booster-repository-publishing__tier\s*> \.ran-booster-repository-publishing__strapline \{\s*margin-block: 0 var\(--ran-booster-space-16\);/', $webhook_management );
		self::assertStringNotContainsString( 'Sets up this repository', $controls );
		self::assertStringContainsString( 'ran-booster-section__description ran-booster-repository-publishing__strapline', $controls );
		// An empty error slot is a grid item and must not add a gap above the first field.
		self::assertMatchesRegularExpression( '/> #repository-webhook-management-error:empty \{\s*display: none;/', $webhook_management );
		self::assertStringContainsString( 'id="repository-webhook-management-error"', $this->source( 'RAN/Admin/WebhookManagement/views/panel.php' ) );
		// The select fills the row beside its button; help text spans the body; label, control and help sit on the 8px token.
		self::assertMatchesRegularExpression( '/__select-action\s+select \{\s*flex: 1 1 0;\s*min-inline-size: 0;\s*inline-size: auto;\s*max-inline-size: none;/', $webhook_management );
		self::assertMatchesRegularExpression( '/__select-action\s+\.ran-wp-ui-button \{\s*flex: 0 0 auto;\s*white-space: nowrap;/', $webhook_management );
		self::assertMatchesRegularExpression( '/__field\s+\.description \{\s*display: block;\s*max-inline-size: none;\s*margin-block: var\(--ran-booster-space-8\) 0;/', $webhook_management );
		// The management field labels use the shared label rule and gap; only the Webhook group paragraph restates the label look.
		self::assertDoesNotMatchRegularExpression( '/[^p]\.ran-booster-public-lookup-profile__label \{[^}]*(margin-block-end|font-weight)/', $webhook_management );
		self::assertMatchesRegularExpression( '/\.ran-booster-repository-webhook-management\s+p\.ran-booster-public-lookup-profile__label \{\s*margin-block-start: 0;\s*color: var\(--ran-booster-text\);\s*font-weight: 600;\s*\}/', $webhook_management );
		$edit_label = $this->asset( 'ran-booster/65-package-settings.css' );
		self::assertMatchesRegularExpression( '/\.ran-booster-settings-field > label,[^{]*\{[^}]*color: var\(--ran-booster-text\);/', $edit_label );
		// Every shared public-lookup-profile override is scoped under the management root.
		preg_match_all( '/[^{}]*public-lookup-profile[^{}]*\{/', $webhook_management, $selectors );
		self::assertNotEmpty( $selectors[0] );
		foreach ( $selectors[0] as $selector ) {
			self::assertStringContainsString( '.ran-booster-repository-webhook-management', $selector );
		}
		// The other screen's shared rules are untouched and unaware of the management screen.
		$cards = $this->asset( 'ran-booster/30-provider-cards.css' );
		self::assertStringNotContainsString( 'repository-webhook-management', $cards );
		self::assertStringContainsString( ".ran-booster-public-lookup-profile__label {\n\tdisplay: block;\n\tmargin-block-end: var(--ran-booster-field-label-gap);", $cards );
		self::assertStringContainsString( ".ran-booster-public-lookup-profile__form,\n.ran-booster-admin .ran-booster-public-lookup-profile__guidance {\n\tmin-inline-size: 0;\n\tpadding-block: var(--ran-booster-space-16);\n\tpadding-inline: var(--ran-booster-space-20);", $cards );
		self::assertStringContainsString( ".ran-booster-public-lookup-profile__layout {\n\tdisplay: grid;\n\tgrid-template-columns: minmax(0, 1fr) minmax(280px, 0.8fr);", $cards );
		self::assertStringNotContainsString( 'repository-webhook-management', $this->asset( 'ran-booster/80-responsive.css' ) );
		self::assertStringNotContainsString( 'ran-booster-public-lookup-profile__guidance', $webhook_management );
		self::assertStringNotContainsString(
			'.ran-booster-public-lookup-profile__layout {\n\tgrid-template-columns: minmax(0, 1fr);',
			$webhook_management
		);
	}

	public function test_package_page_identity_uses_shared_roles_without_framing_installation_forms(): void {
		foreach ( array( 'packages/index.php', 'packages/create.php', 'packages/edit.php', 'packages/recovery.php' ) as $path ) {
			$view = $this->view( $path );
			self::assertStringContainsString( 'ran-booster-page-heading__title', $view, $path );
			self::assertStringNotContainsString( 'ran-booster-page-shell', $view, $path );
			if ( ! in_array( $path, array( 'packages/create.php', 'packages/edit.php' ), true ) ) {
				self::assertStringContainsString( 'ran-booster-page-heading__description', $view, $path );
			}
		}
	}

	public function test_admin_primitives_own_shared_headings_and_credential_dialog_chrome(): void {
		$primitives        = $this->asset( 'ran-booster/25-admin-primitives.css' );
		$onboarding        = $this->view( 'onboarding.php' );
		$provider          = $this->view( 'provider.php' );
		$portability       = $this->view( 'portability.php' );
		$documentation     = $this->view( 'documentation.php' );
		$troubleshooting   = $this->view( 'troubleshooting.php' );
		$modals            = $this->view( 'provider/modals.php' );
		$credential_styles = $this->asset( 'ran-booster/70-credential-dialog.css' );
		$picker_script     = $this->asset( 'ran-booster-repository-picker.js' );
		$picker_styles     = $this->asset( 'ran-booster/20-repository-picker.css' );

		self::assertStringContainsString( '.ran-booster-admin h2.ran-booster-page-heading__title {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-page-shell {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-page-shell__header {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-page-shell__body {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-section__title {', $primitives );
		self::assertStringContainsString( '.ran-booster-dialog__surface {', $primitives );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-dialog {', $primitives );
		self::assertStringContainsString( 'ran-booster-page-heading__title', $provider );
		self::assertStringContainsString( 'ran-booster-page-heading__title', $portability );
		self::assertStringContainsString( 'ran-booster-page-heading__title', $documentation );
		self::assertStringContainsString( 'ran-booster-page-heading__title', $troubleshooting );
		self::assertStringContainsString( 'ran-booster-page-shell ran-booster-onboarding', $onboarding );
		self::assertStringContainsString( 'ran-booster-page-shell ran-booster-documentation', $documentation );
		self::assertStringContainsString( 'ran-booster-page-shell ran-booster-troubleshooting', $troubleshooting );
		self::assertStringContainsString( 'ran-booster-page-shell__body', $documentation );
		self::assertStringContainsString( 'ran-booster-page-shell__body', $troubleshooting );
		self::assertStringContainsString( 'ran-booster-section__title', $provider );
		self::assertStringContainsString( 'ran-booster-credential-modal ran-booster-dialog', $modals );
		self::assertStringContainsString( 'ran-booster-credential-modal__dialog ran-booster-dialog__surface', $modals );
		self::assertStringContainsString( 'ran-booster-dialog__header', $modals );
		self::assertStringNotContainsString( '.ran-booster-credential-modal {', $credential_styles );
		self::assertStringNotContainsString( 'box-shadow: var(--ran-booster-shadow-modal);', $credential_styles );
		self::assertStringContainsString( "'ran-booster-repository-picker-modal'", $picker_script );
		self::assertStringContainsString( "'ran-booster-repository-picker-portal'", $picker_script );
		self::assertStringContainsString( 'dialog.Popup', $picker_script );
		self::assertStringContainsString( "'%s to add a search credential for more reliable repository lookup.'", $picker_script );
		self::assertStringContainsString( 'credentialsLink.replaceWith(', $picker_script );
		self::assertStringNotContainsString( "'to add a search credential for more reliable repository lookup.'", $picker_script );
		self::assertStringNotContainsString( '.ran-booster-repository-picker {', $picker_styles );
	}

	public function test_admin_style_foundations_are_root_scoped_and_constrain_shared_literals(): void {
		$css           = $this->asset( 'ran-booster.css' );
		$component_css = preg_replace( '/\\A[\\s\\S]*?:root \\{[\\s\\S]*?\\n\\}\\n/', '', $css );

		self::assertIsString( $component_css );

		self::assertStringContainsString( ':root {', $css );
		self::assertStringContainsString( '--ran-booster-layer-modal: 100000;', $css );
		self::assertStringContainsString( '--ran-booster-shadow-surface:', $css );
		self::assertStringContainsString( '--ran-booster-shadow-modal:', $css );
		self::assertStringContainsString( '--ran-booster-shadow-delete-button:', $css );
		self::assertStringContainsString( '--ran-booster-danger-strong:', $css );
		self::assertStringContainsString( '--ran-booster-danger-hover-border:', $css );
		self::assertStringNotContainsString( '--ran-booster-surface-muted:', $css );
		self::assertStringNotContainsString( '--ran-booster-code-surface:', $css );
		self::assertStringNotContainsString( '--ran-booster-surface-info-hover:', $css );
		self::assertStringNotContainsString( '--ran-booster-danger-border:', $css );
		self::assertStringNotContainsString( '--ran-booster-danger-hover:', $css );
		self::assertSame( 1, substr_count( $css, '#fff' ) );
		self::assertSame( 0, preg_match_all( '/(?:#[0-9a-f]{3,8}|rgb\\()/i', $component_css ) );
		self::assertStringContainsString( '@media screen and (max-width: 480px)', $css );
		self::assertStringContainsString( '@media screen and (max-width: 782px)', $css );
		self::assertStringContainsString( '@media screen and (max-width: 1100px)', $css );
	}

	public function test_satellite_styles_use_shared_foundations_and_logical_properties(): void {
		$onboarding    = $this->asset( 'ran-booster-onboarding.css' );
		$documentation = $this->asset( 'ran-booster-documentation.css' );
		$primitives    = $this->asset( 'ran-booster/25-admin-primitives.css' );
		$base          = $this->view( 'base.php' );

		self::assertSame( 0, preg_match_all( '/(?:#[0-9a-f]{3,8}|rgb\\()/i', $onboarding ) );
		self::assertSame( 0, preg_match_all( '/(?:#[0-9a-f]{3,8}|rgb\\()/i', $documentation ) );
		self::assertStringNotContainsString( 'var(--ran-booster-shadow-surface)', $onboarding );
		self::assertStringContainsString( 'var(--ran-booster-shadow-surface)', $primitives );
		self::assertStringContainsString( 'border-inline-start:', $onboarding );
		self::assertStringContainsString( 'margin-inline-start:', $documentation );
		self::assertStringContainsString( '@media screen and (max-width: 600px)', $documentation );
		self::assertStringContainsString( '@media screen and (max-width: 782px)', $onboarding );
		self::assertStringContainsString( 'class="ran-booster-footer"', $base );
		self::assertStringContainsString( "__( 'Copyright © %s', 'ran-booster' )", $base );
		self::assertStringNotContainsString( 'style="text-align: center;', $base );
	}

	public function test_booster_footer_stays_in_normal_flow_beside_the_wordpress_footer(): void {
		$cards = $this->asset( 'ran-booster/30-provider-cards.css' );
		if ( 1 !== preg_match( '/\.ran-booster-admin \.ran-booster-footer \{([^}]*)\}/', $cards, $rule ) ) {
			self::fail( 'The Booster footer rule is missing.' );
		}
		// The footer is an in-flow block of the page wrapper: no offsets or removal from flow.
		self::assertStringContainsString( 'position: relative;', $rule[1] );
		self::assertStringContainsString( 'margin-inline: 0;', $rule[1] );
		self::assertSame( 0, preg_match( '/position:\s*(?:absolute|fixed|sticky)|(?:inset|top|bottom|left|right|transform|display:\s*none)/', $rule[1] ) );
		// Nothing outside print styles repositions or hides WordPress's own footer.
		$sources = glob( dirname( __DIR__, 2 ) . '/assets/ran-booster/*.css' );
		self::assertIsArray( $sources );
		foreach ( $sources as $file ) {
			self::assertDoesNotMatchRegularExpression( '/#wpfooter\s*[,{]/', $this->raw_asset( 'ran-booster/' . basename( $file ) ), basename( $file ) );
		}
		// The footer is the last block inside the page wrapper, so a view that leaves a container open or closed cannot detach it.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Tests inspect local source views without HTTP.
		$base = file_get_contents( dirname( __DIR__, 2 ) . '/views/base.php' );
		self::assertIsString( $base );
		self::assertSame( 1, preg_match( '/<div class="ran-booster-footer">.*<\/p>\s*<\/div>\s*<\/div>\s*<\?php/s', $base ) );
	}

	public function test_documentation_chapters_outrank_their_disclosures_and_articles(): void {
		$documentation = $this->asset( 'ran-booster-documentation.css' );

		if ( 1 !== preg_match( '/\.ran-booster-documentation__chapter > h3 \{([^}]*)\}/', $documentation, $m ) ) {
			self::fail( 'The Documentation chapter heading rule is missing.' );
		}
		self::assertStringContainsString( 'font-size: var(--ran-booster-font-size-lg);', $m[1] );
		self::assertStringContainsString( 'font-weight: var(--ran-booster-font-weight-semibold);', $m[1] );
		if ( 1 !== preg_match( '/\.ran-booster-documentation__section\.ran-booster-disclosure\s+> summary \{([^}]*)\}/', $documentation, $m ) ) {
			self::fail( 'The Documentation disclosure fallback summary rule is missing.' );
		}
		// The fallback matches the compact public header: 13px at medium weight.
		self::assertStringContainsString( 'font-size: var(--ran-booster-font-size-md);', $m[1] );
		self::assertStringContainsString( 'font-weight: var(--ran-booster-font-weight-medium);', $m[1] );
		if ( 1 !== preg_match( '/\.ran-booster-documentation__content :is\(h3, h4\) \{([^}]*)\}/', $documentation, $m ) ) {
			self::fail( 'The Documentation article heading rule is missing.' );
		}
		self::assertStringContainsString( 'font-size: var(--ran-booster-font-size-md);', $m[1] );
		self::assertStringNotContainsString( 'text-transform', $documentation );
	}

	public function test_documentation_smooth_scrolling_is_scoped_and_respects_reduced_motion(): void {
		$documentation = $this->asset( 'ran-booster-documentation.css' );

		// The document scrolls, so the root element owns scroll-behavior, only on Documentation and only when motion is welcome.
		self::assertMatchesRegularExpression(
			'/@media \(prefers-reduced-motion: no-preference\) \{\s+html:has\(\.ran-booster-admin \.ran-booster-documentation\) \{\s+scroll-behavior: smooth;\s+\}\s+\}/',
			$documentation
		);
		self::assertSame( 1, substr_count( $documentation, 'scroll-behavior:' ) );
	}

	public function test_documentation_print_contract_preserves_guidance_and_removes_admin_chrome(): void {
		$documentation = $this->asset( 'ran-booster-documentation.css' );
		$view          = $this->view( 'documentation.php' );

		self::assertStringContainsString( '@media print {', $documentation );
		self::assertStringContainsString( '#wpadminbar,', $documentation );
		self::assertStringContainsString( 'body.wp-admin .notice,', $documentation );
		self::assertStringContainsString( '.ran-admin-shell__navigation,', $documentation );
		self::assertStringNotContainsString( "\t.ran-booster-footer,\n", $documentation );
		self::assertStringNotContainsString( '[data-ran-booster-feedback-toast]', $documentation );
		self::assertMatchesRegularExpression(
			'/@media print \{[\s\S]+\.ran-booster-admin \.ran-booster-footer \{\s+display: block;\s+margin: 18pt 0 0;\s+padding: 8pt 0 0;\s+border-block-start: 1pt solid CanvasText;/',
			$documentation
		);
		self::assertMatchesRegularExpression(
			'/@media print \{[\s\S]+\.ran-booster-admin \.ran-booster-documentation__index \{\s+display: block;\s+grid-area: auto;\s+position: static;/',
			$documentation
		);
		self::assertStringContainsString( 'color-scheme: only light;', $documentation );
		self::assertStringContainsString( 'background: Canvas !important;', $documentation );
		self::assertMatchesRegularExpression(
			'/@media print \{[\s\S]+\.ran-booster-admin \.ran-booster-documentation__layout \{\s+display: block;\s+grid-template-areas: none;\s+grid-template-columns: none;/',
			$documentation
		);
		self::assertStringContainsString( '.ran-booster-documentation__section.ran-booster-disclosure:not([open])', $documentation );
		self::assertStringContainsString( '::-webkit-details-marker', $documentation );
		self::assertStringContainsString( 'break-inside: avoid-page;', $documentation );
		self::assertStringContainsString( 'ran-booster-documentation__section', $view );
		self::assertStringContainsString( 'ran-booster-documentation__content', $view );
	}

	public function test_documentation_code_blocks_are_keyboard_reachable_named_regions(): void {
		$documentation = $this->asset( 'ran-booster-documentation.css' );
		$view          = $this->view( 'documentation.php' );

		$blocks = preg_match_all( '/<pre\b(.*?\?>")>/', $view, $matches );
		self::assertGreaterThan( 0, $blocks );
		$labels = array();
		foreach ( $matches[1] as $attributes ) {
			self::assertStringContainsString( 'tabindex="0"', $attributes );
			self::assertStringContainsString( 'role="region"', $attributes );
			if ( 1 !== preg_match( '/aria-label="<\?php esc_attr_e\( \'(Code example[^\']*)\', \'ran-booster\' \); \?>"/', $attributes, $label ) ) {
				self::fail( 'Every documentation code block needs a translatable Code example label.' );
			}
			$labels[] = $label[1];
		}
		self::assertSame( $labels, array_values( array_unique( $labels ) ) );
		self::assertMatchesRegularExpression(
			'/\.ran-booster-documentation__content pre:focus-visible \{\s+outline: 2px solid var\(--ran-booster-color-blue-300\);\s+outline-offset: 2px;/',
			$documentation
		);
	}

	public function test_every_public_disclosure_shares_one_open_header_divider(): void {
		$css           = $this->asset( 'ran-booster.css' );
		$documentation = $this->asset( 'ran-booster-documentation.css' );

		// One shadow, outside the box model, for every public kind and its native fallback.
		self::assertMatchesRegularExpression(
			'/@media screen \{\s+\.ran-booster-admin\s+\[data-ran-booster-ui-disclosure\]\s+\.ran-booster-ui-disclosure\[data-open\]\s+> \.ran-booster-ui-disclosure__header,\s+\.ran-booster-admin\s+\[data-ran-booster-ui-disclosure\]\s+> details\[data-ran-booster-ui-disclosure-fallback\]\[open\]\s+> summary \{\s+box-shadow: 0 1px 0 var\(--ran-booster-divider\);/',
			$css
		);
		// Documentation no longer carries a private mounted copy, so no kind can drift from the shared divider.
		self::assertDoesNotMatchRegularExpression(
			'/\[data-ran-booster-ui-disclosure="documentation"\]\s+\.ran-booster-ui-disclosure\[data-open\]/',
			$documentation
		);
		self::assertMatchesRegularExpression(
			'/\.ran-booster-documentation__section\.ran-booster-disclosure\[open\]\s+> summary \{\s+border-block-end: 0;\s+box-shadow: 0 1px 0 var\(--ran-booster-divider\);/',
			$documentation
		);
	}

	public function test_picker_and_credential_dialogs_retain_keyboard_focus_and_body_lock_contracts(): void {
		$credential_script = $this->asset( 'ran-booster-secure-inputs.js' );
		$picker_script     = $this->asset( 'ran-booster-repository-picker.js' );

		// The public Dialog portals the picker and owns Escape and focus containment.
		self::assertStringContainsString( 'portal: createElement(dialog.Portal', $picker_script );
		self::assertStringContainsString( 'container: portalContainer', $picker_script );
		self::assertMatchesRegularExpression( '/onOpenChange\\(open\\) \\{\\s*if \\(!open\\) \\{\\s*closePicker\\(\\);/', $picker_script );
		self::assertStringContainsString( 'initialFocus()', $picker_script );
		self::assertStringContainsString( 'finalFocus()', $picker_script );
		self::assertStringNotContainsString( 'trapFocus(', $picker_script );
		self::assertMatchesRegularExpression(
			"/document\\.body\\.classList\\.add\\(\\s*'ran-booster-repository-picker-open'\\s*\\);/",
			$picker_script
		);
		self::assertStringContainsString( 'activeCredentialButton.focus();', $credential_script );
		self::assertGreaterThanOrEqual( 1, substr_count( $credential_script, 'trapFocus(' ) );
		self::assertGreaterThanOrEqual( 2, substr_count( $credential_script, "'ran-booster-repository-picker-open'" ) );
		self::assertStringContainsString( '.ran-booster-open-delete-credential-modal', $credential_script );
		self::assertStringContainsString( 'populateDeleteCredentialModal(modal, button);', $credential_script );
		self::assertStringContainsString( "modal.querySelector('[data-delete-credential-cancel]').focus();", $credential_script );
		self::assertStringContainsString( 'confirmButton.disabled = !usageKnown || inUse;', $credential_script );
		self::assertStringContainsString( "get('replace_credential')", $credential_script );
		self::assertMatchesRegularExpression(
			"/button\\.getAttribute\\('data-id'\\)\\s*===\\s*requestedReplacement/",
			$credential_script
		);
		self::assertStringContainsString( "querySelector('.ran-booster-secret-input')", $credential_script );
		self::assertStringContainsString( "'ran-booster:provider-tasks-ready'", $credential_script );
		self::assertStringContainsString( 'initWebhookUrlCopy(root);', $credential_script );
		self::assertStringContainsString( 'initCredentialSettings();', $credential_script );
	}

	public function test_credential_secret_field_keeps_saved_state_separate_from_the_empty_native_input(): void {
		$modals = $this->view( 'provider/modals.php' );
		$script = $this->asset( 'ran-booster-secure-inputs.js' );
		$styles = $this->asset( 'ran-booster-onboarding.css' );
		$dialog = $this->asset( 'ran-booster/70-credential-dialog.css' );

		self::assertStringContainsString( 'class="ran-booster-credential-modal__form" autocomplete="off"', $modals );
		self::assertStringContainsString( 'id="ran-booster-access-secret" type="password"', $modals );
		self::assertStringContainsString( 'autocomplete="one-time-code" autocapitalize="none" spellcheck="false"', $modals );
		self::assertStringContainsString( 'A credential secret is already saved. Leave this field unchanged to keep it, or enter a replacement.', $modals );
		self::assertStringContainsString( "data-show-label=\"<?php esc_attr_e( 'Show credential secret'", $modals );
		self::assertStringContainsString( "data-hide-label=\"<?php esc_attr_e( 'Hide credential secret'", $modals );
		self::assertStringContainsString( 'data-access-secret-visibility', $modals );
		self::assertStringContainsString( 'aria-describedby="ran-booster-access-secret-help"', $modals );
		self::assertStringContainsString( 'class="screen-reader-text ran-booster-secret-help"', $modals );
		self::assertStringContainsString( 'hidden disabled><span class="dashicons dashicons-visibility" data-access-secret-visibility-icon', $modals );
		self::assertStringNotContainsString( 'value="••••', $modals );
		self::assertStringNotContainsString( 'autocomplete="new-password"', substr( $modals, 0, (int) strpos( $modals, 'data-credential-modal="webhook"' ) ) );
		self::assertStringContainsString( "secretInput.dataset.saved === 'true'", $script );
		self::assertStringContainsString( "? '••••••••••'", $script );
		self::assertStringContainsString( "visibility.hidden = input.value === '';", $script );
		self::assertStringContainsString( "input.type = showing ? 'text' : 'password';", $script );
		self::assertStringNotContainsString( 'Example token format:', $script );
		self::assertStringContainsString( '.ran-booster-portability__password-visibility[hidden]', $styles );
		self::assertStringContainsString( 'ran-booster-credential-modal__field-row', $modals );
		self::assertStringContainsString( 'Expiry / removal date', $modals );
		self::assertStringContainsString( 'Enter the Git provider expiry date, or choose an earlier one.', $modals );
		self::assertStringContainsString( 'A known Git provider expiry is the latest date allowed.', $modals );
		self::assertStringContainsString( 'Automatically remove this saved credential after this date', $modals );
		self::assertStringNotContainsString( 'ran_booster[destroy_on]', $modals );
		self::assertStringNotContainsString( 'ran-booster-credential-destroy-date', $modals );
		self::assertStringContainsString( "const input = form.elements['ran_booster[expires_on]'];", $script );
		self::assertStringContainsString( "button.getAttribute('data-destroy-on') ||", $script );
		self::assertStringContainsString( "button.getAttribute('data-provider-expires-on') || ''", $script );
		self::assertStringContainsString( 'expiryInput.dataset.originalValue = expiryInput.value;', $script );
		self::assertStringContainsString( "expiryInput.dataset.replacementStarted = 'true';", $script );
		self::assertStringContainsString( 'data-provider-expires-on=', $this->view( 'provider.php' ) );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-credential-modal__field-row {', $dialog );
		self::assertStringContainsString( 'grid-template-columns: minmax(0, 1fr) minmax(180px, 0.7fr);', $dialog );
		self::assertStringContainsString( "\$kind['short_label'] ?? \$kind['label']", $modals );
	}

	public function test_webhook_secret_field_keeps_managed_suggestions_and_saved_state_separate_from_browser_autofill(): void {
		$modals = $this->view( 'provider/modals.php' );
		$script = $this->asset( 'ran-booster-secure-inputs.js' );

		self::assertStringContainsString( 'data-credential-modal="webhook"', $modals );
		self::assertStringContainsString( 'class="ran-booster-credential-modal__form" autocomplete="off"', substr( $modals, (int) strpos( $modals, 'data-credential-modal="webhook"' ) ) );
		self::assertStringContainsString( 'name="ran_booster[target]" class="regular-text" data-webhook-target', $modals );
		self::assertStringContainsString( 'data-webhook-target-options="owner"', $modals );
		self::assertStringContainsString( 'data-webhook-target-options="repository"', $modals );
		self::assertStringContainsString( 'No managed owners available', $modals );
		self::assertStringContainsString( 'No managed repositories available', $modals );
		self::assertStringNotContainsString( '<datalist', $modals );
		self::assertStringContainsString( 'id="ran-booster-webhook-secret" type="password"', $modals );
		self::assertStringContainsString( 'autocomplete="off" autocapitalize="none" spellcheck="false"', $modals );
		self::assertStringContainsString( "data-add-placeholder=\"<?php esc_attr_e( 'Long random secret'", $modals );
		self::assertStringContainsString( 'data-webhook-secret-visibility', $modals );
		self::assertStringContainsString( 'hidden disabled><span class="dashicons dashicons-visibility" data-webhook-secret-visibility-icon', $modals );
		self::assertStringNotContainsString( 'value="••••', $modals );
		self::assertStringContainsString( 'Booster will not reveal a saved secret again', $modals );
		self::assertStringContainsString( "secretInput.dataset.saved = isEdit ? 'true' : 'false';", $script );
		self::assertStringContainsString( "? '••••••••••'", $script );
		self::assertStringContainsString( "visibility.hidden = secret.value === '';", $script );
	}

	public function test_portability_apply_results_use_word_press_notice_states(): void {
		$script = $this->asset( 'ran-booster-portability.js' );

		self::assertStringContainsString( 'window.ranBoosterNotice.create(resultType', $script );
		self::assertStringNotContainsString( "'notice inline notice-'", $script );
		self::assertStringContainsString( "result.status === 'failed'", $script );
		self::assertStringContainsString( "result.status === 'skipped'", $script );
		self::assertStringContainsString( 'initPortabilityExportDownload();', $script );
		self::assertStringContainsString( 'initPortabilityPreview();', $script );
		self::assertStringContainsString( 'initPortabilityExportSelection();', $script );
		self::assertStringContainsString( 'initPortabilityModeChooser();', $script );
		self::assertStringContainsString( 'initPortabilityExportSelection();', $script );
		self::assertStringContainsString( '^#ran-booster-portability-([a-z0-9-]+)$', $script );
		self::assertStringContainsString( 'selectMode(requestedMode);', $script );
	}

	public function test_portability_details_bodies_use_one_compact_heading_rhythm(): void {
		$onboarding = $this->asset( 'ran-booster-onboarding.css' );
		$gap        = '[^}]*';

		// Headings sit one section gap below prior content and the shared heading gap above their content; no core h6 margins.
		self::assertMatchesRegularExpression( '/\.ran-booster-portability__package-details h6 \{' . $gap . 'margin-block:\s*var\(--ran-booster-space-12\)\s*var\(--ran-booster-card-heading-gap\);/', $onboarding );
		self::assertMatchesRegularExpression( '/\.ran-booster-portability__package-details h6 \+ :is\(p, ul\) \{\s*margin-block-start:\s*0;/', $onboarding );
		self::assertMatchesRegularExpression( '/p:has\(\+ ul\) \{\s*margin-block-end:\s*0;/', $onboarding );
		self::assertMatchesRegularExpression( '/__details-body p \+ ul \{\s*margin-block-start:\s*var\(--ran-booster-card-heading-gap\);/', $onboarding );
		// The 16px body padding owns the first and last edges.
		self::assertMatchesRegularExpression( '/__details-body\s*>\s*:first-child:is\(h6, p\),[^{]*__details-body\s*>\s*section:first-child\s*>\s*:first-child\s*\{\s*margin-block-start:\s*0;/', $onboarding );
		self::assertMatchesRegularExpression( '/__details-body\s*>\s*section:last-child\s*>\s*:last-child\s*\{\s*margin-block-end:\s*0;/', $onboarding );
	}

	public function test_credential_table_shapes_own_their_desktop_column_widths(): void {
		$css        = $this->asset( 'ran-booster.css' );
		$view       = $this->view( 'provider.php' );
		$modals     = $this->view( 'provider/modals.php' );
		$renderer   = $this->source( 'RAN/Admin/Component/ProviderManagementTableRenderer.php' );
		$dashboard  = $this->source( 'RAN/Dashboard.php' );
		$projection = $this->source( 'RAN/Admin/ProviderSettingsPresenter.php' );

		self::assertStringContainsString( 'ProviderManagementTableRenderer', $dashboard );
		self::assertStringContainsString( 'ran-booster-provider-management-table--<?php echo esc_attr( $type ); ?>', $renderer );
		self::assertStringContainsString( "public const ACCESS  = 'access';", $renderer );
		self::assertStringContainsString( "public const WEBHOOK = 'webhook';", $renderer );
		self::assertStringContainsString( 'data-credential-delete-modal', $modals );
		self::assertStringContainsString( 'Yes, delete credential', $modals );
		self::assertStringContainsString( 'data-delete-credential-packages', $modals );
		self::assertStringContainsString( 'ran-booster-delete-credential-package-link', $view );
		self::assertStringContainsString( 'ran_booster[expires_on]', $modals );
		self::assertStringContainsString( "profile['expiry_status']['badge_label']", $projection );
		self::assertStringContainsString( 'ButtonClasses::for( \'danger\', \'solid\' ) . \' ran-booster-open-delete-credential-modal\' ); ?>"', $view );
		self::assertStringContainsString( 'ButtonClasses::for( \'danger\', \'solid\' ) ); ?>" data-confirm="<?php echo esc_attr( $profile[\'delete_confirmation\'] ); ?>"', $view );
		self::assertStringNotContainsString( 'Before deleting a credential', $modals );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-delete-credential-package-list {', $css );
		self::assertStringContainsString( 'max-block-size: 160px;', $css );
		self::assertStringContainsString( 'overflow-y: auto;', $css );
		// Retired provider-page classes with no rendered consumer keep no rules.
		foreach ( array( 'ran-booster-credential-row__label', 'ran-booster-provider-empty-state', 'ran-booster-provider-management__back' ) as $retired ) {
			self::assertStringNotContainsString( $retired, $css );
		}
		self::assertStringContainsString( '.ran-booster-provider-management-table--access', $css );
		self::assertStringContainsString( '.ran-booster-provider-management-table--webhook', $css );
		// The provider tables use the shared data-table frame; the retired
		// credential-table class keeps no rules of its own.
		self::assertStringNotContainsString( 'ran-booster-credential-table', $css );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-admin \\.ran-booster-actions \\{[\\s\\S]+flex-wrap: nowrap;[\\s\\S]+justify-content: flex-end;[\\s\\S]+white-space: nowrap;/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-provider-management-table--access[\\s\\S]+th:nth-child\\(1\\) \\{\\s+inline-size: 20%;\\s+\\}[\\s\\S]+th:nth-child\\(2\\) \\{\\s+inline-size: 12%;\\s+\\}[\\s\\S]+th:nth-child\\(3\\) \\{\\s+inline-size: 12%;\\s+\\}[\\s\\S]+th:nth-child\\(4\\) \\{\\s+inline-size: 12%;\\s+\\}[\\s\\S]+th:nth-child\\(5\\) \\{\\s+inline-size: 12%;\\s+\\}[\\s\\S]+th:nth-child\\(6\\) \\{\\s+inline-size: 32%;\\s+\\}/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-provider-management-table--access\\s+\\.ran-booster-actions \\{\\s+flex-wrap: wrap;\\s+min-inline-size: 0;\\s+\\}/',
			$css
		);
		self::assertStringContainsString( 'inline-size: auto !important;', $css );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-admin \\.ran-booster-data-table > thead > tr > th \\{\\s+border-block-end: 1px solid var\\(--ran-booster-border\\);\\s+background: transparent;\\s+color: var\\(--ran-booster-text\\);\\s+font-size: var\\(--ran-booster-font-size-md\\);\\s+font-weight: 600;\\s+text-align: start;\\s+\\}/',
			$css
		);
		self::assertDoesNotMatchRegularExpression( '/provider-management-table\\s+> thead\\s+> tr\\s+> th \\{\\s+(?:background|font-weight):/', $css );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-admin \\.ran-booster-data-table > tbody > tr > th \\{\\s+font-weight: inherit;\\s+text-align: start;\\s+\\}/',
			$css
		);
		// Provider records centre their cells like the Activity table; the
		// shared rows default stays top-aligned for other tables.
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-data-table\\.ran-booster-provider-management-table\\s+> tbody\\s+> tr\\s+> td \\{\\s+vertical-align: middle;\\s+\\}/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-admin \\.ran-booster-data-table th,\\s+\\.ran-booster-admin \\.ran-booster-data-table td \\{[^}]+vertical-align: top;/',
			$css
		);
		// Provider records are row groups: the group owns the stripe and the
		// divider, and a hidden result row stays hidden in the stacked layout.
		foreach (
			array(
				'/provider-management-table\\s+> tbody \\{\\s+border-block-end: 1px solid var\\(--ran-booster-divider\\);\\s+\\}/',
				'/provider-management-table\\s+> tbody:last-child \\{\\s+border-block-end: 0;\\s+\\}/',
				'/provider-management-table\\s+> tbody\\s+\\+ tbody\\s+> tr:first-child\\s+> \\* \\{\\s+border-block-start: 0;\\s+\\}/',
				'/provider-management-table\\s+> tbody:nth-of-type\\(odd\\)\\s+> tr\\s+> \\* \\{\\s+background: var\\(--ran-booster-surface-subtle\\);\\s+\\}/',
				'/provider-management-table\\s+> tbody:nth-of-type\\(even\\)\\s+> tr\\s+> \\* \\{\\s+background: transparent;\\s+\\}/',
				'/\\.ran-booster-admin \\.ran-booster-provider-management-table__result\\[hidden\\] \\{\\s+display: none;\\s+\\}/',
				'/> \\.ran-booster-provider-management-table__result-cell \\{\\s+padding-block: 0 var\\(--ran-booster-space-12\\);\\s+\\}/',
			) as $record_rule
		) {
			self::assertMatchesRegularExpression( $record_rule, $css );
		}
		// Grouped row-per-record tables restart the stripe in every group.
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-data-table--rows\\s+> tbody:nth-of-type\\(even\\)\\s+> tr:nth-child\\(even\\)\\s+> \\* \\{\\s+background: transparent;\\s+\\}/',
			$css
		);
		foreach ( array( 'ran-booster-provider-management__header', 'ran-booster-provider-list-controls' ) as $flat_class ) {
			self::assertSame( 1, preg_match( '/\\.' . preg_quote( $flat_class, '/' ) . ' \\{([^}]+)\\}/', $css, $flat_rule ) );
			$flat_body = $flat_rule[1] ?? '';
			self::assertNotSame( '', $flat_body );
			self::assertStringNotContainsString( 'background:', $flat_body );
			self::assertStringNotContainsString( 'border:', $flat_body );
			self::assertStringNotContainsString( 'padding:', $flat_body );
		}
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-provider-list-controls \\{[\\s\\S]+flex-wrap: wrap;/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-provider-table-navigation \\{[\\s\\S]+flex-wrap: wrap;[\\s\\S]+padding-inline: var\\(--ran-booster-space-(?:20|24)\\);/',
			$css
		);
		// The real table persists down to the 782px shared mobile breakpoint, not the old 1100px one.
		$responsive = $this->asset( 'ran-booster/80-responsive.css' );
		self::assertDoesNotMatchRegularExpression(
			'/@media screen and \\(max-width: (?:1100|960|900)px\\) \\{(?:(?!\\n\\}\\n)[\\s\\S])*provider-management-table\\s+> tbody\\s+> tr \\{/',
			$responsive
		);
		self::assertMatchesRegularExpression(
			'/@media screen and \\(min-width: 783px\\) and \\(max-width: 1100px\\) \\{[\\s\\S]+provider-management-table--access\\s+th:nth-child\\(6\\) \\{\\s+inline-size: 28%;[\\s\\S]+padding-inline: var\\(--ran-booster-space-8\\);\\s+overflow-wrap: break-word;/',
			$responsive
		);
		// Stacked records come from the shared data-table rules at 782px; the
		// provider tables keep only the actions cell out of the label grid and
		// let each record group own its single divider.
		self::assertDoesNotMatchRegularExpression( '/provider-management-table\\s+> thead \\{/', $responsive );
		if ( 1 !== preg_match( '/@media screen and \\(max-width: 782px\\) \\{\\n((?:(?!\\n\\}\\n)[\\s\\S])*)\\n\\}\\n/', $responsive, $stacked ) ) {
			self::fail( 'The provider tables need their 782px stacking rules.' );
		}
		foreach (
			array(
				'/provider-management-table\\s+> tbody\\s+\\+ tbody \\{\\s+border-block-start: 0;\\s+\\}/',
				'/provider-management-table\\s+> tbody\\s+> tr \\{\\s+border-block-end: 0;\\s+\\}/',
				'/\\.ran-booster-data-table\\s+\\.ran-booster-provider-management-table__result-cell \\{\\s+display: flow-root;\\s+\\}/',
			) as $stacked_rule
		) {
			self::assertMatchesRegularExpression( $stacked_rule, $stacked[1] );
		}
		self::assertDoesNotMatchRegularExpression( '/provider-management-table[\\s\\S]{0,80}td\\[data-label\\]/', $responsive );
		self::assertMatchesRegularExpression(
			'/@media screen and \\(max-width: 782px\\) \\{\\s+\\.ran-booster-admin\\s+\\.ran-booster-data-table\\.ran-booster-provider-management-table\\s+> tbody\\s+> tr\\s+> \\.ran-booster-provider-management-table__actions \\{\\s+display: block;\\s+\\}[\\s\\S]+__actions::before \\{\\s+content: none;\\s+\\}[\\s\\S]+\\.ran-booster-provider-management-table__actions\\s+> \\.ran-booster-actions \\{\\s+flex-wrap: wrap;\\s+justify-content: flex-start;\\s+white-space: normal;\\s+\\}/',
			$responsive
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-admin \\.ran-booster-data-table > tbody > tr > \\*,\\s+\\.ran-booster-admin \\.ran-booster-data-table--rows > tbody > tr \\+ tr > \\* \\{\\s+border: 0;\\s+\\}/',
			$responsive
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-admin \\.ran-booster-data-table > tbody > tr > \\[data-label\\] > \\* \\{\\s+grid-column: 2;\\s+justify-self: start;\\s+\\}/',
			$responsive
		);
		self::assertStringNotContainsString( 'padding: var(--ran-booster-space-14) var(--ran-booster-space-16);', $responsive );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-data-table\\s+> tbody\\s+> tr\\s+> \\[data-label\\]::before \\{/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-webhook-url input \\{\\s+min-inline-size: 0;\\s+inline-size: 100%;\\s+max-inline-size: 100%;/',
			$css
		);
		self::assertStringNotContainsString( '.ran-booster-credential-table td.ran-booster-actions {', $css );
		self::assertStringNotContainsString( '.ran-booster-credential-table thead', $css );
		self::assertStringNotContainsString( 'ran-booster-package-row__content', $css );
		self::assertStringNotContainsString( 'ran-booster-package-row__secondary', $css );
		self::assertMatchesRegularExpression(
			'/@media screen and \\(max-width: 782px\\)[\\s\\S]+\\.ran-booster-provider :is\\(\\.button, \\.ran-wp-ui-button\\) \\{\\s+inline-size: auto;\\s+align-self: flex-start;\\s+text-align: center;\\s+\\}/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/@media screen and \\(max-width: 782px\\)[\\s\\S]+\\.ran-booster-provider-list-controls select \\{\\s+flex: 0 1 auto;\\s+inline-size: 100%;\\s+max-inline-size: none;/',
			$css
		);
		self::assertDoesNotMatchRegularExpression(
			'/@media screen and \\(max-width: 480px\\)[\\s\\S]+\\.ran-booster-provider-management__header > \\.button \\{\\s+align-self: stretch;\\s+inline-size: 100%;/',
			$css
		);
	}

	public function test_provider_settings_use_the_shared_shell_and_progressive_disclosure(): void {
		$css        = $this->asset( 'ran-booster.css' );
		$onboarding = $this->asset( 'ran-booster-onboarding.css' );
		$primitives = $this->asset( 'ran-booster/25-admin-primitives.css' );
		$view       = $this->view( 'provider.php' ) . $this->view( 'provider-public-lookup-profile.php' );
		$script     = $this->asset( 'ran-booster.js' );
		$renderer   = $this->source( 'RAN/Admin/Component/RepositoryTableRenderer.php' );
		$dashboard  = $this->source( 'RAN/Dashboard.php' );

		self::assertStringContainsString( 'ran-booster-page-shell ran-booster-provider', $view );
		self::assertStringContainsString( 'ran-booster-provider__header', $view );
		self::assertStringContainsString( 'ran-booster-provider-section', $view );
		self::assertStringContainsString( 'ran-booster-public-lookup-profile__panel', $view );
		self::assertStringContainsString( 'ran-booster-public-lookup-profile__layout', $view );
		self::assertStringContainsString( 'ran-booster-public-lookup-profile__guidance', $view );
		self::assertStringNotContainsString( 'ran-booster-push-deploy__assistance-status', $view );
		self::assertStringContainsString( 'ran-booster-push-deploy__notice', $view );
		self::assertStringContainsString( 'id="ran-booster-provider-tasks"', $view );
		self::assertStringContainsString( 'hx-history="false"', $view );
		self::assertStringContainsString( 'hx-sync="this:replace"', $view );
		self::assertStringContainsString( 'aria-controls="ran-booster-provider-task-panel"', $view );
		self::assertStringContainsString( 'ran-booster-provider-task-tabs', $view );
		self::assertStringContainsString( 'ran-booster-provider-disclosure', $view );
		self::assertStringContainsString( 'ran-booster-provider-repository-tools', $view );
		self::assertStringContainsString( 'RepositoryTableRenderer', $dashboard );
		self::assertStringContainsString( 'ran-booster-repository-list', $renderer );
		self::assertStringContainsString( 'class="ran-booster-repository-record', $renderer );
		self::assertStringContainsString( 'ran-booster-repository-record--release', $renderer );
		self::assertStringContainsString( 'ran-booster-repository-record__summary', $renderer );
		self::assertStringContainsString( 'ran-booster-repository-record__identity', $renderer );
		self::assertStringContainsString( 'ran-booster-repository-record__overview', $renderer );
		self::assertStringContainsString( 'ran-booster-repository-record__management-detail', $renderer );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-repository-record--release {', $css );
		self::assertStringContainsString( 'background: var(--ran-booster-surface-info);', $css );
		self::assertStringContainsString( 'ran-booster-repository-record__action-group', $renderer );
		self::assertStringContainsString( 'ran-booster-repository-record__actions', $renderer );
		self::assertStringContainsString( 'AdminStatusSummaryRenderer', $dashboard );
		self::assertStringContainsString( '$status_summary_renderer->render(', $view );
		self::assertSame( 2, substr_count( $view, '$status_summary_renderer->render(' ) );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-status-summary {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-status-summary__heading {', $css );
		self::assertStringNotContainsString( 'ran-booster-status-dot', $css );
		self::assertStringContainsString( 'ran-booster-webhook-endpoint', $view );
		self::assertStringContainsString( 'data-webhook-url-tools', $view );
		self::assertStringNotContainsString( 'ran-booster-provider__footer', $view );
		self::assertStringNotContainsString( 'core:webhook-management', $view );
		self::assertStringNotContainsString( 'Assisted Hooks', $view );
		self::assertStringNotContainsString( "'gh'", $view );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-page-shell {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-page-shell__header {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-disclosure > summary:focus-visible', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-public-lookup-profile__layout {', $css );
		self::assertStringContainsString( 'grid-template-columns: minmax(0, 1fr) minmax(280px, 0.8fr);', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-provider-task-panel {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-provider-task-progress {', $css );
		self::assertStringContainsString( 'ran-booster-provider-receiver', $view );
		self::assertStringNotContainsString( 'ran-booster-provider-automated-setup', $css );
		// The receiver Card pads its content and bleeds the tab strip to both borders, keeping the tab inset.
		self::assertMatchesRegularExpression( '/\.ran-booster-provider-receiver\.ran-booster-settings-section\[data-ran-booster-card="provider"\]\.ran-booster-card\s+> \.ran-booster-card__surface\s+> \.ran-booster-card__content \{\s+padding: var\(--ran-booster-space-20\) var\(--ran-booster-receiver-inset\)\s+var\(--ran-booster-space-24\);/', $css );
		self::assertMatchesRegularExpression( '/\.ran-booster-provider-receiver__tabs \{\s+margin-block: 0 var\(--ran-booster-space-20\);\s+margin-inline: calc\(-1 \* var\(--ran-booster-receiver-inset\)\);\s+padding-inline: var\(--ran-booster-receiver-inset\);/', $css );
		self::assertStringNotContainsString( 'ran-booster-provider-task-actions', $css );
		self::assertStringContainsString( "document.addEventListener('htmx:afterSwap'", $script );
		self::assertStringContainsString( "'ran-booster:provider-tasks-ready'", $script );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-push-deploy__summary {', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-push-deploy__assistance-status {', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-push-deploy__notice {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-repository-list {', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__overview', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__action-group {', $css );
		// Repository row actions keep the shared Button height; no 44px override.
		self::assertDoesNotMatchRegularExpression( '/repository-record__action-group :is\(a, button\) \{[^}]*min-block-size/', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__actions {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-provider-management__header {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-webhook-endpoint > div:first-child {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-summary-grid {', $this->asset( 'ran-booster/25-admin-primitives.css' ) );
		self::assertStringContainsString( 'grid-template-columns: minmax(0, 1fr);', $css );
	}

	public function test_package_heading_action_reuses_the_provider_heading_row(): void {
		$view = $this->view( 'packages/index.php' );
		$css  = $this->asset( 'ran-booster.css' );
		$pkg  = $this->asset( 'ran-booster/60-packages.css' );

		// Markup: one header row holding the h2 first and the action link second.
		self::assertSame( 1, preg_match( '/<header class="ran-booster-action-row ran-booster-provider-management__header ran-booster-package-heading-row">\s*<h2 class="wp-heading-inline ran-booster-package-heading[^"]*">.*?<\/h2>\s*<\?php if \( \$package_list_total > 0 \) \{ \?>\s*<a class="page-title-action" href="<\?php echo esc_url\( \$install_another_url \); \?>">.*?<\/a>\s*<\?php \} \?>\s*<\/header>/s', $view ) );

		// The shared provider row owns the end alignment and the narrow stacking.
		self::assertMatchesRegularExpression( '/\.ran-booster-provider-management__header \{[^}]*justify-content: space-between;/', $css );
		self::assertMatchesRegularExpression( '/@media screen and \(max-width: 782px\)[\s\S]+\.ran-booster-provider-management__header,[\s\S]+?flex-direction: column;/', $css );

		// The package row only centres the line and drops the row margin; the action's core offsets are neutralised.
		self::assertMatchesRegularExpression( '/\.ran-booster-package-heading-row \{\s+align-items: center;\s+margin-block-end: 0;\s+\}/', $pkg );
		self::assertMatchesRegularExpression( '/\.ran-booster-package-heading-row \.page-title-action \{\s+position: static;\s+margin: 0;\s+\}/', $pkg );

		// The old inline placement rules are gone.
		self::assertStringNotContainsString( '.ran-booster-package-heading + .page-title-action', $css );
		self::assertDoesNotMatchRegularExpression( '/h2\.ran-booster-package-heading \{/', $css );
	}

	public function test_managed_package_table_controls_desktop_widths_and_allows_package_identity_to_wrap(): void {
		$css         = $this->asset( 'ran-booster.css' );
		$package_css = $this->asset( 'ran-booster/60-packages.css' );
		$script      = $this->asset( 'ran-booster-packages.js' );
		$base        = $this->view( 'base.php' );
		$view        = $this->view( 'packages/index.php' );

		self::assertStringContainsString( "'extensions'      => ' ran-booster-admin--extensions ran-booster-admin--wide-collection'", $base );
		self::assertStringContainsString( "'packages/index'  => ' ran-booster-admin--packages ran-booster-admin--wide-collection'", $base );
		self::assertStringContainsString( "'packages/create',", $base );
		self::assertStringContainsString( "'packages/edit'   => ' ran-booster-admin--packages'", $base );
		self::assertStringNotContainsString( "! str_starts_with( \$view, 'packages/' )", $base );
		self::assertStringContainsString( "'current' => ! empty( \$admin_tab['active'] )", $base );
		self::assertStringContainsString( 'ran-booster-package-table', $view );
		self::assertStringContainsString( 'ran-booster-package-row__name', $view );
		self::assertStringContainsString( 'ran-booster-package-row__repo', $view );
		self::assertStringContainsString( 'ran-booster-package-row__management', $view );
		self::assertStringContainsString( 'ran-booster-package-row__status-line', $view );
		self::assertStringContainsString( 'ran-booster-package-row__details-grid', $view );
		self::assertStringContainsString( 'wp-heading-inline ran-booster-package-heading', $view );
		self::assertStringContainsString( 'class="page-title-action"', $view );
		self::assertStringContainsString( 'class="ran-booster-package-list-filters"', $view );
		self::assertStringContainsString( 'class="ran-booster-package-list-search search-form"', $view );
		self::assertStringContainsString( 'class="tablenav top ran-booster-package-toolbar"', $view );
		self::assertStringContainsString( 'class="alignleft actions bulkactions ran-booster-bulk-actions"', $view );
		self::assertStringContainsString( 'class="tablenav-pages one-page"', $view );
		self::assertStringContainsString( 'class="displaying-num"', $view );
		self::assertStringNotContainsString( 'ran-booster-package-row__facts', $view );
		self::assertStringNotContainsString( 'style="width: 100%;"', $view );
		self::assertStringContainsString( '--ran-booster-width-standard: 1050px;', $css );
		self::assertStringContainsString( '--ran-booster-width-wide: 1400px;', $css );
		self::assertStringContainsString( 'max-inline-size: var(--ran-booster-width-standard);', $css );
		self::assertStringContainsString( '.ran-booster-admin--wide-collection {', $css );
		self::assertStringContainsString( 'max-inline-size: var(--ran-booster-width-wide);', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-package-table {', $css );
		self::assertStringContainsString( 'table-layout: fixed;', $css );
		self::assertStringContainsString(
			'.ran-booster-package-row__summary,',
			$css
		);
		self::assertStringContainsString( 'overflow-wrap: anywhere;', $css );
		self::assertStringContainsString( "ran-booster-package-row__repo {\n\tdisplay: block;", $css );
		self::assertStringContainsString( 'ran-booster-package-row__details-grid--branch', $view );
		self::assertStringContainsString( 'minmax(90px, 0.65fr)', $package_css );
		self::assertStringContainsString( 'minmax(230px, 1.6fr)', $package_css );
		self::assertStringContainsString( 'ran-booster-package-row__activity-summary', $view );
		self::assertStringContainsString( 'flex-wrap: nowrap;', $package_css );
		self::assertStringNotContainsString( 'Last successful revision', $view );
		self::assertStringContainsString( 'rowspan="2"', $view );
		self::assertStringContainsString(
			'class="ran-booster-package-row ran-booster-package-row--primary<?php echo $word_press_plugin_active ? \' ran-booster-package-row--wordpress-active\' : \'\'; ?>"',
			$view
		);
		self::assertStringContainsString(
			'class="ran-booster-package-row ran-booster-package-row--details<?php echo $word_press_plugin_active ? \' ran-booster-package-row--wordpress-active\' : \'\'; ?>"',
			$view
		);
		self::assertStringContainsString( '<td colspan="3" class="ran-booster-package-row__details">', $view );
		self::assertStringContainsString( 'class="manage-column column-primary ran-booster-package-table__package-header"', $view );
		self::assertStringContainsString( 'class="manage-column ran-booster-package-table__actions-header"', $view );
		self::assertStringContainsString( 'ran-booster-package-row__identity', $view );
		self::assertStringContainsString( 'class="ran-booster-package-row__summary"', $view );
		self::assertStringContainsString( 'class="ran-booster-package-row__actions"', $view );
		self::assertStringContainsString( 'class="ran-booster-package-row__action-group"', $view );
		self::assertStringContainsString( 'ButtonClasses::secondary( false, true ) ); ?>"><?php esc_html_e( \'Edit settings\'', $view );
		self::assertStringNotContainsString( "\$policy_disabled ? ' button-primary' : ''", $view );
		self::assertStringNotContainsString( 'class="ran-booster-meta__tiles"', $view );
		self::assertStringNotContainsString( 'class="ran-booster-meta__badges"', $view );
		self::assertMatchesRegularExpression(
			'/ButtonClasses::primary\(\) \. \' button-update-package\'[\s\S]+data-ran-booster-update-button/',
			$view
		);
		self::assertStringContainsString( 'Reinstall', $view );
		self::assertStringContainsString( 'Deployment activity', $view );
		self::assertStringContainsString( '.ran-booster-package-table__actions-header', $css );
		self::assertStringContainsString( '@media screen and (max-width: 1100px)', $css );
		self::assertStringContainsString( '@media screen and (max-width: 782px)', $css );
		self::assertStringContainsString( '@media screen and (max-width: 480px)', $css );
		self::assertMatchesRegularExpression(
			'/\.ran-booster-package-list-search input\[type="search"\] \{[^}]*min-block-size: 40px;\s+\}/',
			$package_css
		);
		self::assertStringNotContainsString( 'line-height: 38px;', $package_css );
		self::assertMatchesRegularExpression(
			'/@media screen and \(max-width: 1100px\) \{\s+\.ran-booster-admin \.ran-booster-package-list-controls \{\s+align-items: stretch;\s+flex-direction: column;/',
			$package_css
		);
		self::assertMatchesRegularExpression(
			'/@media screen and \(min-width: 783px\) and \(max-width: 1200px\) \{[\s\S]+\.ran-booster-package-table__actions-header \{\s+inline-size: 180px;[\s\S]+\.ran-booster-package-row__action-group \{\s+align-items: stretch;\s+flex-direction: column;[\s\S]+\.ran-booster-package-row__action-group > \*,[\s\S]+inline-size: 100%;/',
			$package_css
		);
		self::assertStringNotContainsString( '@media screen and (max-width: 1250px)', $package_css );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-package-toolbar\\.tablenav\\.top \\.actions \\{\\s+display: flex;/',
			$package_css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-package-toolbar\\.tablenav\\.top\\s+\\.displaying-num \\{\\s+display: inline-block;/',
			$package_css
		);
		self::assertStringContainsString( '.ran-booster-package-row__action-group {', $css );
		self::assertStringContainsString( 'ran-booster-package-row__state ran-booster-package-row__wordpress-state', $view );
		self::assertStringContainsString( 'ran-booster-package-row__state ran-booster-package-row__update-state', $view );
		self::assertStringContainsString( 'StatusBadge::print_intent( $automation_state_label, $automation_state_intent );', $view );
		self::assertStringNotContainsString( 'ran-booster-package-row__state-value', $view . $package_css );
		self::assertStringNotContainsString( 'rgba(var(--wp-admin-theme-color--rgb)', $package_css );
		self::assertStringContainsString( 'border-inline-start: 3px solid var(--wpds-color-stroke-interactive-brand);', $package_css );
		self::assertStringContainsString( 'justify-content: flex-end;', $css );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-package-row__action-group \\{[\\s\\S]+?flex-wrap: nowrap;/',
			$package_css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-package-row__action-group\\s+\\.button-update-package \\{\\s+box-sizing: border-box;\\s+inline-size: 136px;/',
			$package_css
		);
		self::assertStringContainsString( 'box-sizing: border-box;', $css );
		self::assertStringContainsString(
			'gap: var(--ran-booster-space-8);',
			$css
		);
		self::assertStringContainsString( "'needs_attention' => 'error'", $view );
		self::assertStringContainsString( "'running'         => 'pending'", $view );
		self::assertStringContainsString( "'succeeded'       => 'ok'", $view );
		self::assertStringContainsString( 'data-ran-booster-package-progress', $view );
		self::assertStringContainsString( 'data-package-source="<?php echo esc_attr( $package->get_source()->value ); ?>"', $view );
		self::assertStringContainsString(
			'[data-ran-booster-package-progress][data-package-source="branch"]',
			$script
		);
		self::assertStringContainsString( 'data-ran-booster-update-button', $view );
		self::assertStringContainsString( 'data-ran-booster-package-mutation', $view );
		self::assertStringContainsString( "'hx-target': '#wpbody-content'", $script );
		self::assertStringContainsString( "'hx-select': '#wpbody-content'", $script );
		self::assertStringContainsString( 'data-reinstall-confirm-message=', $view );
		self::assertStringContainsString( 'data-reinstall-confirm-singular=', $view );
		self::assertStringContainsString( 'data-reinstall-confirm-plural=', $view );
		self::assertStringContainsString( 'confirmPackageReinstall(this)', $script );
		self::assertStringNotContainsString( "label.textContent = 'Working…';", $script );
		self::assertStringContainsString( 'ran-booster-update-is-active', $view );
		self::assertStringNotContainsString( 'data-ran-booster-update-icon', $view );
		self::assertStringNotContainsString( 'data-ran-booster-update-spinner', $view );
		self::assertStringContainsString( 'data-ran-booster-activity-badge', $view );
		self::assertStringContainsString( 'data-ran-booster-activity-state', $view );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-package-intro {', $css );
		self::assertStringContainsString( 'gap: var(--ran-booster-space-16);', $css );
		self::assertStringContainsString( 'margin-block-end: var(--ran-booster-space-18);', $css );
		self::assertStringContainsString( "esc_html_e( 'Reinstall selected branches', 'ran-booster' )", $view );
		self::assertStringContainsString( "esc_html_e( 'Set updates: Automatic', 'ran-booster' )", $view );
		self::assertStringNotContainsString( 'Set policy:', $view );
		self::assertStringNotContainsString( "\$policy_disabled ? 'inactive' : 'active'", $view );
	}

	public function test_managed_package_bulk_controls_use_an_external_form_and_accessible_selection_contract(): void {
		$css    = $this->asset( 'ran-booster.css' );
		$script = $this->asset( 'ran-booster-packages.js' );
		$view   = $this->view( 'packages/index.php' );

		self::assertStringContainsString( 'data-ran-booster-bulk-form', $view );
		self::assertStringContainsString( 'name="ran_booster[identifiers][]"', $view );
		self::assertStringContainsString( 'form="<?php echo esc_attr( $bulk_form_id ); ?>"', $view );
		self::assertStringContainsString( 'data-ran-booster-select-all', $view );
		self::assertStringContainsString( 'data-ran-booster-selection-status', $view );
		self::assertStringContainsString( 'aria-live="polite"', $view );
		self::assertStringContainsString( 'class="screen-reader-text"', $view );
		self::assertStringNotContainsString( 'disabled( ! $bulkEligible )', $view );
		self::assertStringNotContainsString( '$bulkEligible =', $view );
		self::assertStringContainsString( '> .check-column,', $css );
		self::assertStringContainsString( 'inline-size: 40px;', $css );
		self::assertStringNotContainsString( '$policy_disabled ? \'inactive\' : \'active\'', $view );
		self::assertStringNotContainsString( '.ran-booster-package-row:nth-child(odd)', $css );
		self::assertStringContainsString( 'initBulkPackageControls();', $script );
		self::assertStringContainsString( 'initPackageUpdateProgress();', $script );
		self::assertStringContainsString( 'data-ran-booster-update-summary', $script );
		self::assertStringContainsString( '@keyframes ran-booster-update-stripes', $css );
		self::assertStringNotContainsString( 'min-inline-size: 136px;', $css );
		self::assertStringContainsString( 'ran-booster-update-is-active::before', $css );
		self::assertStringContainsString(
			'var(--ran-booster-strong-divider) 8px',
			$css
		);
		self::assertStringContainsString(
			'var(--ran-booster-divider) 16px',
			$css
		);
		self::assertStringContainsString( 'opacity: 0.1;', $css );
		self::assertStringContainsString( '.ran-booster-portability__progress-button.ran-booster-update-is-active::before', $css );
		self::assertStringContainsString( '[data-portability-preview-label]', $css );
		self::assertStringContainsString( '[data-portability-apply-label]', $css );
		self::assertStringContainsString( 'grid-template-areas: "label";', $css );
		self::assertStringContainsString( 'grid-area: label;', $css );
		self::assertStringContainsString( 'content: attr(data-busy-label);', $css );
		self::assertStringContainsString( 'visibility: hidden;', $css );
		self::assertStringContainsString( '.ran-booster-portability__progress-button.ran-booster-update-is-active::after', $css );
		self::assertStringContainsString( 'content: attr(data-idle-label);', $css );
		self::assertStringNotContainsString( ".ran-booster-portability__progress-button {\n\tmin-inline-size:", $css );
		self::assertStringNotContainsString( ".prop('disabled', true)", $script );
		self::assertStringContainsString( 'selectAll.indeterminate', $script );
		self::assertStringNotContainsString( "form.setAttribute('aria-busy', 'true');", $script );
	}

	public function test_package_removal_controls_require_explicit_checkbox_confirmation(): void {
		$css         = $this->asset( 'ran-booster.css' );
		$script      = $this->asset( 'ran-booster-packages.js' );
		$danger_zone = $this->view( 'packages/danger-zone.php' );
		$index       = $this->view( 'packages/index.php' );

		self::assertStringContainsString( 'initConfirmedPackageRemovals();', $script );
		self::assertStringContainsString( 'function initConfirmedPackageRemovals()', $script );
		self::assertStringContainsString( '[data-ran-booster-confirmed-package-removal]', $script );
		self::assertStringContainsString( '[data-ran-booster-package-removal-confirm]', $script );
		self::assertStringContainsString( '[data-ran-booster-package-removal-submit]', $script );
		self::assertStringContainsString( 'submit.disabled = !confirmation.checked;', $script );
		self::assertStringNotContainsString( "form.setAttribute('aria-busy', 'true');", $script );

		self::assertSame( 2, substr_count( $danger_zone, 'data-ran-booster-confirmed-package-removal' ) );
		self::assertStringContainsString( 'class="ran-booster-settings-disclosure ran-booster-package-danger-zone ran-booster-disclosure"', $danger_zone );
		self::assertStringContainsString( 'ran-booster-disclosure__body', $danger_zone );
		self::assertStringContainsString( 'data-ran-booster-package-disclosure', $danger_zone );
		self::assertSame( 2, substr_count( $danger_zone, 'data-ran-booster-package-mutation' ) );
		self::assertSame( 2, substr_count( $danger_zone, 'data-ran-booster-native-submit' ) );
		self::assertSame( 0, substr_count( $danger_zone, 'hx-target="#wpbody-content"' ) );
		self::assertSame( 2, substr_count( $danger_zone, 'name="ran_booster[confirm_package_removal]" value="1" required' ) );
		self::assertSame( 2, substr_count( $danger_zone, 'name="ran_booster[expected_source_revision]"' ) );
		self::assertStringContainsString( "\$package_view->get_action( 'unlink' )", $danger_zone );
		self::assertStringContainsString( "\$package_view->get_action( 'unlink-delete' )", $danger_zone );
		self::assertStringContainsString( 'disabled data-ran-booster-package-removal-submit', $danger_zone );

		self::assertStringContainsString( 'grid-column: 2;', $css );
		self::assertStringContainsString( '.ran-booster-package-danger-zone {', $css );
		self::assertStringContainsString( 'border-color: var(--ran-booster-status-error-border);', $css );
		self::assertStringContainsString( '.ran-booster-package-danger-zone__actions {', $css );
		self::assertStringContainsString( 'grid-template-columns: repeat(2, minmax(0, 1fr));', $css );
		self::assertStringContainsString( '.ran-booster-package-danger-zone__actions .ran-wp-ui-button {', $css );
		self::assertStringContainsString( 'inline-size: 100%;', $css );

		self::assertStringNotContainsString( "\$package_view->get_action( 'unlink' )", $index );
		self::assertStringNotContainsString( '$unlinkLabel', $index );
	}

	public function test_package_mutations_share_the_core_htmx_feedback_contract(): void {
		$index       = $this->view( 'packages/index.php' );
		$reinstall   = $this->view( 'packages/reinstall.php' );
		$danger_zone = $this->view( 'packages/danger-zone.php' );
		$notices     = $this->view( 'notices.php' );
		$renderer    = $this->source( 'RAN/Admin/Component/AdminActionRenderer.php' );
		$feedback    = $this->asset( 'ran-booster-enhanced-mutations.js' );
		$packages    = $this->asset( 'ran-booster-packages.js' );

		foreach ( array( $index, $reinstall, $danger_zone, $renderer ) as $markup ) {
			self::assertStringContainsString( 'data-ran-booster-package-mutation', $markup );
		}
		self::assertStringContainsString( 'data-ran-booster-enhanced-mutation', $renderer );
		self::assertStringContainsString( 'hx-target="#wpbody-content"', $renderer );
		self::assertStringContainsString( 'hx-select="#wpbody-content"', $renderer );
		self::assertStringContainsString( 'hx-swap="outerHTML show:none"', $renderer );
		self::assertStringContainsString( 'hx-sync="this:drop"', $renderer );
		self::assertStringContainsString( 'action="<?php echo esc_url( $action[\'url\'] ); ?>"', $renderer );
		self::assertStringContainsString( 'hx-post="<?php echo esc_url( wp_make_link_relative( $action[\'url\'] ) ); ?>"', $renderer );
		self::assertStringContainsString( "'data-ran-booster-enhanced-mutation': ''", $packages );
		self::assertStringContainsString( "'hx-target': '#wpbody-content'", $packages );
		self::assertStringContainsString( "'hx-select': '#wpbody-content'", $packages );
		self::assertStringContainsString( "'hx-swap': 'outerHTML show:none'", $packages );
		self::assertStringContainsString( "'hx-sync': 'this:drop'", $packages );

		self::assertStringContainsString( 'data-ran-booster-package-success', $notices );
		self::assertStringContainsString( 'cleanPackageResultUrl()', $feedback );
		self::assertStringContainsString( 'history.replaceState', $feedback );
		self::assertStringNotContainsString( "header( 'HX-Replace-Url:", $this->source( 'RAN/Dashboard.php' ) );
		self::assertStringContainsString( 'captureInteractionState(form, event.detail.xhr)', $feedback );
		self::assertStringContainsString( 'restoreInteractionState()', $feedback );
		self::assertStringContainsString( 'window.scrollTo(0, state.scrollY)', $feedback );
		self::assertStringNotContainsString( 'packageSourceScrollY', $packages );
		self::assertStringContainsString( 'status === 400', $feedback );
		self::assertStringContainsString( "event.detail?.target?.id === 'wpbody-content'", $packages );
	}

	public function test_deployment_activity_uses_co_located_attempt_row_disclosures(): void {
		$css    = $this->asset( 'ran-booster.css' );
		$view   = $this->view( 'attempts/index.php' );
		$detail = $this->view( 'attempts/detail.php' );

		self::assertStringContainsString( 'ran-booster-attempt-table', $view );
		self::assertStringContainsString( 'ran-booster-attempt-list', $view );
		self::assertStringContainsString( 'ran-booster-attempt-row__details', $view );
		self::assertStringContainsString( 'ran-booster-data-table-wrap ran-booster-attempt-table', $view );
		self::assertStringContainsString( 'StatusBadge::for_intent(', $view );
		self::assertStringContainsString( "'class' => 'ran-booster-deployment-state ran-booster-deployment-state--'", $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Time\'', $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Subject\'', $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Source\'', $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Activity\'', $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Outcome\'', $view );
		self::assertStringContainsString( 'ran-booster-activity__details', $view );
		self::assertStringContainsString( 'DeploymentOutcomeMessage::for_code', $view );
		self::assertStringContainsString( 'DeploymentOutcomeMessage::for_code', $detail );
		self::assertStringContainsString( "<summary aria-label=\"<?php echo esc_attr( \$details_label ); ?>\"><?php esc_html_e( 'View details', 'ran-booster' ); ?></summary>", $view );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-attempt-row__details', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-attempt-list > tbody:nth-of-type(odd) > tr > *', $css );
		self::assertStringContainsString( '--ran-booster-table-cell-padding:', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-data-table-wrap', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__summary', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__identity', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__overview', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__actions', $css );
	}

	/**
	 * The managed package table keeps its native list-table structure and only
	 * gains the shared collection frame: one wrapper around the whole table,
	 * stripes per package rather than per row, and an accent bar instead of a
	 * full-row tint for packages that are active in WordPress.
	 */
	public function test_collection_frame_encloses_toolbar_table_and_footer_with_one_border(): void {
		$css = (string) preg_replace( '/\s+/', ' ', $this->asset( 'ran-booster/40-tables-and-pills.css' ) );

		self::assertMatchesRegularExpression( '/\.ran-booster-admin \.ran-booster-collection \{[^}]*border: 1px solid var\(--ran-booster-border\);[^}]*border-radius: var\(--ran-booster-radius-inset\);/', $css );
		self::assertMatchesRegularExpression( '/\.ran-booster-collection > \.ran-booster-collection__toolbar \{[^}]*padding: var\(--ran-booster-space-12\) var\(--ran-booster-space-16\);[^}]*border-block-end: 1px solid var\(--ran-booster-border\);[^}]*background: var\(--ran-booster-surface-subtle\);/', $css );
		self::assertMatchesRegularExpression( '/\.ran-booster-admin\.ran-booster-admin \.ran-booster-collection > :is\(\.ran-booster-data-table-wrap, \.ran-booster-repository-list\) \{ border: 0; border-radius: 0; \}/', $css );
		self::assertStringNotContainsString( 'overflow: hidden', (string) preg_replace( '/.*\.ran-booster-collection \{([^}]*)\}.*/', '$1', $css ) );
	}

	public function test_package_table_sits_in_the_shared_collection_frame_and_keeps_its_structure(): void {
		$view       = $this->view( 'packages/index.php' );
		$packages   = $this->asset( 'ran-booster/60-packages.css' );
		$primitives = $this->asset( 'ran-booster/25-admin-primitives.css' );

		if ( 1 !== preg_match( '#<div class="ran-booster-data-table-wrap ran-booster-package-table-wrap">\n(<table class="wp-list-table widefat plugins ran-booster-package-table"[\s\S]*?</table>)\n</div>\n<\?php \} \?>\n</div></div></div>#', $view, $m ) ) {
			self::fail( 'The package table must sit directly inside one shared data-table wrapper that closes with the table.' );
		}
		$table = $m[1];

		self::assertSame( 1, substr_count( $view, 'ran-booster-data-table-wrap' ) );
		self::assertStringContainsString( '<caption class="screen-reader-text">', $table );
		self::assertStringContainsString( '<tbody id="the-list">', $table );
		self::assertStringContainsString( '<th scope="row" rowspan="2" class="check-column">', $table );
		self::assertStringContainsString( '<td colspan="3" class="ran-booster-package-row__details">', $table );
		self::assertStringContainsString( 'data-ran-booster-select-all', $table );
		self::assertStringContainsString( 'data-ran-booster-package-checkbox', $table );
		self::assertStringNotContainsString( 'ran-booster-data-table ', $table );
		self::assertStringNotContainsString( 'ran-booster-data-table--rows', $table );

		if ( 1 !== preg_match( '#/\* surface-radius:start \*/([\s\S]*?)/\* surface-radius:end \*/#', $primitives, $radius ) ) {
			self::fail( 'The shared surface-radius block is missing.' );
		}
		self::assertStringContainsString( '.ran-booster-data-table-wrap,', $radius[1] );

		self::assertStringContainsString( '.ran-booster-admin .ran-booster-package-table > thead > tr > th {', $packages );
		self::assertMatchesRegularExpression( '/\.ran-booster-package-row--primary:nth-child\(\s+odd of \.ran-booster-package-row--primary\s+\)\s+\+ \.ran-booster-package-row--details\s+> \* \{\s+background: var\(--ran-booster-surface-subtle\);/', $packages );
		self::assertStringNotContainsString( '.ran-booster-package-row--primary:hover', $packages );

		$stylesheets = glob( dirname( __DIR__, 2 ) . '/assets/ran-booster/*.css' );
		if ( false === $stylesheets ) {
			self::fail( 'The Booster stylesheets could not be listed.' );
		}
		$tinted = array();
		foreach ( $stylesheets as $file ) {
			$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
			if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				if ( str_contains( $rule[1], 'ran-booster-package-row--wordpress-active' ) && 1 === preg_match( '/(?:^|[;\s])background(?:-color)?\s*:/', $rule[2] ) ) {
					$tinted[] = basename( $file ) . ': ' . trim( (string) preg_replace( '/\s+/', ' ', $rule[1] ) );
				}
			}
		}
		self::assertSame( array(), $tinted, 'Active packages keep the package stripe; mark them with the accent bar, not a row tint.' );
	}

	public function test_package_table_has_a_caption_and_page_size_selects_submit_without_inline_handlers(): void {
		$view   = $this->view( 'packages/index.php' );
		$script = $this->asset( 'ran-booster.js' );

		self::assertMatchesRegularExpression( '/<table class="wp-list-table.*\n\s*<caption class="screen-reader-text">/', $view );
		self::assertStringContainsString( 'select[data-ran-booster-auto-submit]', $script );
		self::assertStringContainsString( 'select.form.requestSubmit()', $script );
	}

	/**
	 * Package identity must stay the scan anchor of its row (#759): the details
	 * disclosure header may never be larger than the package name, and must be
	 * lighter. This reads the relationship through the foundation tokens, so a
	 * token change cannot silently invert the hierarchy.
	 */
	public function test_package_row_disclosure_header_never_outranks_the_package_name(): void {
		$foundations = $this->asset( 'ran-booster/00-foundations.css' );
		$packages    = $this->asset( 'ran-booster/60-packages.css' );
		$primitives  = $this->asset( 'ran-booster/25-admin-primitives.css' );

		$scale = array(
			'xl' => 20,
			'lg' => 15,
			'md' => 13,
			'sm' => 12,
		);

		$size = static function ( string $token ) use ( $foundations, $scale ): int {
			if ( 1 !== preg_match( '/' . preg_quote( $token, '/' ) . ':\s*var\(\s*--wpds-typography-font-size-(xl|lg|md|sm)\s*\)/', $foundations, $m ) ) {
				self::fail( $token );
			}

			return $scale[ $m[1] ];
		};

		$weight = static function ( string $token ) use ( $foundations ): int {
			if ( 1 !== preg_match( '/' . preg_quote( $token, '/' ) . ':\s*(?:(\d+)|var\(\s*--wpds-typography-font-weight-emphasis\s*\))/', $foundations, $m ) ) {
				self::fail( $token );
			}

			return isset( $m[1] ) ? (int) $m[1] : 600;
		};

		$declared = static function ( string $css, string $selector_fragment, string $property, string $value_pattern ): string {
			$pattern = '/' . $selector_fragment . '[^{]*\{[^}]*' . $property . ':\s*(' . $value_pattern . ')/s';
			if ( 1 !== preg_match( $pattern, $css, $m ) ) {
				self::fail( $selector_fragment . ' ' . $property );
			}

			return $m[1];
		};

		$name_size_token = $declared( $packages, '\.ran-booster-package-row__title\s', 'font-size', 'var\(--ran-booster-font-size-[a-z]+\)' );
		$name_weight     = $declared( $packages, '\.ran-booster-package-row__name', 'font-weight', 'var\(--ran-booster-font-weight-[a-z]+\)' );
		$head_size_token = $declared( $primitives, '\.ran-booster-admin \.ran-booster-ui-disclosure__header > \[role="button"\]', 'font-size', 'var\(--ran-booster-font-size-[a-z]+\)' );
		$head_weight     = $declared( $primitives, '\.ran-booster-admin \.ran-booster-ui-disclosure__header > \[role="button"\]', 'font-weight', 'var\(--ran-booster-font-weight-[a-z]+\)' );

		$extract = static fn ( string $value ): string => (string) preg_replace( '/^var\((--[a-z-]+)\)$/', '$1', $value );

		self::assertGreaterThanOrEqual( $size( $extract( $head_size_token ) ), $size( $extract( $name_size_token ) ) );
		self::assertGreaterThan( $weight( $extract( $head_weight ) ), $weight( $extract( $name_weight ) ) );

		// Package-row disclosures use the generic compact header unchanged: no per-page size, weight or padding override.
		self::assertStringNotContainsString( 'package-details"]', $packages );
	}

	public function test_converted_markup_draws_buttons_with_the_static_button_and_never_core_classes(): void {
		$root  = dirname( __DIR__, 2 );
		$files = glob( $root . '/assets/*.js' );
		self::assertIsArray( $files );
		foreach ( array( 'views', 'RAN' ) as $directory ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $directory, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		$scanned = 0;
		foreach ( $files as $path ) {
			$file = substr( $path, strlen( $root ) + 1 );
			if ( isset( self::NATIVE_BUTTON_FILES[ $file ] ) ) {
				continue;
			}
			self::assertSame( array(), $this->core_button_classes( $this->source( $file ) ), $file );
			++$scanned;
		}
		self::assertGreaterThan( 100, $scanned );

		foreach ( array_keys( self::NATIVE_BUTTON_FILES ) as $file ) {
			self::assertNotSame( array(), $this->core_button_classes( $this->source( $file ) ), $file . ' no longer needs its allowlist entry.' );
		}
	}

	/**
	 * Files that still draw WordPress core buttons, with the reason.
	 *
	 * @var array<string, string>
	 */
	private const NATIVE_BUTTON_FILES = array(
		'views/generated/ran-admin-shell.php'    => 'Generated Shell header actions, styled by ran-admin-shell.css; they move with the Shell.',
		'RAN/Admin/CredentialExpiryNotice.php'   => 'Global notice that renders on core screens, where the static Button stylesheet is not loaded.',
		'RAN/Admin/DeploymentAdminPresenter.php' => 'Global notice that renders on core screens, where the static Button stylesheet is not loaded.',
	);

	/**
	 * Elements that keep a WordPress core button class on purpose, keyed by a
	 * class that identifies them, with the reason.
	 *
	 * @var array<string, string>
	 */
	private const NATIVE_BUTTON_ALLOWLIST = array(
		'ran-booster-copy-value__button'       => 'Icon-only copy control attached to a read-only field; stays bespoke like the in-input password tools.',
		'ran-booster-portability__icon-button' => 'Icon-only copy control inside the password and secret tools; stays bespoke.',
		'data-ran-booster-ui-button-fallback'  => 'No-JS fallback for the Skip onboarding button, which is still mounted as the public Button.',
		'ran-booster-release-track-option'     => 'Segmented release track control (current track, switch, radio label); deferred until segmented controls convert.',
	);

	/**
	 * Returns each class attribute or class string that carries a WordPress core
	 * button class. Core's .button rules are unlayered and would outrank the
	 * static Button's layered rules on the same element.
	 *
	 * @return list<string>
	 */
	private function core_button_classes( string $source ): array {
		$found = array();
		$token = '(?<![\w<\/-])button(?:-primary|-secondary|-delete|-link-delete|-disabled)?(?![\w-])';
		// A class attribute match runs to the end of its tag, so the allowlist can name any attribute.
		preg_match_all( '/class=(["\'])(?:(?!\1).)*?' . $token . '(?:(?!\1).)*\1[^>]*/', $source, $attributes );
		preg_match_all( '/(?:className\s*=|classList\.\w+\(|\?|(?<!type):|\.)\s*[\'"][^\'"]*' . $token . '|[\'"][^\'"]*(?<![\w-])button-(?:primary|secondary|delete|link-delete|disabled)(?![\w-])[^\'"]*/', $source, $strings );
		$strings = array_filter( $strings[0], static fn ( string $candidate ): bool => 1 !== preg_match( '/type\s*[=:]/', $candidate ) );
		foreach ( array_merge( $attributes[0], $strings ) as $match ) {
			foreach ( array_keys( self::NATIVE_BUTTON_ALLOWLIST ) as $allowed ) {
				if ( str_contains( $match, $allowed ) ) {
					continue 2;
				}
			}
			$found[] = $match;
		}

		return $found;
	}

	/**
	 * The Advanced settings summary stacks on narrow screens through the shared
	 * prominent Disclosure header: it wraps, then the status line takes its own
	 * start-aligned row while the chevron stays on the title row.
	 */
	public function test_advanced_settings_summary_stacks_on_narrow_screens(): void {
		$primitives = $this->asset( 'ran-booster/25-admin-primitives.css' );

		if ( 1 !== preg_match( '/prominent"\]\s*> \*\s*> \.ran-booster-ui-disclosure\s*> \.ran-booster-ui-disclosure__header\s*> \[role="button"\]\s*> :first-child \{([^}]*)\}/', $primitives, $header ) ) {
			self::fail( 'The prominent Disclosure header content rule is missing.' );
		}
		self::assertStringContainsString( 'flex-wrap: wrap;', $header[1] );

		if ( 1 !== preg_match( '/@media screen and \(max-width: 480px\) \{\s*\.ran-booster-admin\s*\[data-ran-booster-ui-disclosure="package-advanced-settings"\]\s*> \*\s*> \.ran-booster-ui-disclosure\s*> \.ran-booster-ui-disclosure__header\s*\.ran-booster-ui-disclosure__meta \{([^}]*)\}(.*?)\n\}/s', $primitives, $narrow ) ) {
			self::fail( 'The narrow Advanced settings summary rule is missing.' );
		}
		self::assertStringContainsString( 'flex: 1 1 100%;', $narrow[1] );
		self::assertStringContainsString( 'margin-inline: 0;', $narrow[1] );
		// The native fallback keeps its chevron on the title row with a two-column grid.
		self::assertStringContainsString( 'grid-template-columns: minmax(0, 1fr) auto;', $narrow[2] );
		self::assertStringContainsString( 'grid-column: 1 / -1;', $narrow[2] );
		self::assertStringContainsString( 'grid-row: 1;', $narrow[2] );
		self::assertDoesNotMatchRegularExpression( '/margin-(?:left|right)\s*:/', $narrow[0] );
	}

	/**
	 * The inactive Branch settings card is de-emphasised with the muted text
	 * colour, not whole-card opacity, so its text and links keep 4.5:1.
	 */
	public function test_inactive_branch_settings_keep_text_contrast(): void {
		$settings = $this->asset( 'ran-booster/65-package-settings.css' );

		if ( 1 !== preg_match( '/\.ran-booster-branch-settings\.is-inactive\s*:is\(label, h4, strong\) \{([^}]*)\}/', $settings, $text ) ) {
			self::fail( 'The inactive Branch settings text rule is missing.' );
		}
		self::assertStringContainsString( 'color: var(--ran-booster-text-muted);', $text[1] );
		self::assertDoesNotMatchRegularExpression( '/\.ran-booster-branch-settings\.is-inactive\s*\{[^}]*opacity/', $settings );
		self::assertMatchesRegularExpression( '/\.ran-booster-branch-settings\.is-inactive\s*\.ran-booster-readiness-icon \{\s*opacity: 0\.58;/', $settings );
	}

	public function test_de_emphasised_text_uses_a_colour_token_not_element_opacity(): void {
		$release = $this->asset( 'ran-booster-release-management.css' );
		$webhook = $this->asset( 'ran-booster-repository-webhook-management.css' );

		self::assertSame( 1, preg_match( '/\.ran-booster-release-candidate:has\(input:disabled\)\s*\{([^}]*)\}/', $release, $disabled ) ? 1 : 0 );
		self::assertStringNotContainsString( 'opacity', $disabled[1] ?? '' );
		self::assertStringContainsString( 'color: var(--ran-booster-text-subtle)', $disabled[1] ?? '' );

		// The lifecycle container itself carries no opacity (it dimmed every step's text); only the decorative markers do.
		self::assertSame( 0, preg_match( '/\.ran-booster-repository-webhook-lifecycle\.is-inactive\s*\{[^}]*opacity/', $webhook ) );
		self::assertSame( 1, preg_match( '/\.is-inactive\s+\.ran-booster-webhook-step\s+:is\(strong, p\)\s*\{\s*color: var\(--ran-booster-text-subtle\);/', $webhook ) );
	}

	public function test_form_controls_share_one_border_token_and_components_do_not_override_it(): void {
		$foundations = $this->raw_asset( 'ran-booster/00-foundations.css' );
		self::assertSame( 1, preg_match( '/--ran-booster-control-border:\s*var\(--ran-booster-color-neutral-450\);/', $foundations ), 'The control border token must resolve to #767676 (4.5:1 on white, 4:1 on the grey page).' );
		self::assertStringContainsString( '--ran-booster-color-neutral-450: #767676;', $foundations );

		$primitives = $this->raw_asset( 'ran-booster/25-admin-primitives.css' );
		self::assertSame( 1, preg_match( '/\.ran-booster-admin\s*:is\(\s*select,\s*textarea,\s*input:is\([^)]*\[type="text"\][^)]*\[type="search"\][^)]*\)\s*\):not\(:disabled, :focus\)\s*\{\s*border-color: var\(--ran-booster-control-border\);\s*\}/', $primitives ), 'The shared control border rule is missing.' );

		$offenders   = array();
		$stylesheets = glob( dirname( __DIR__, 2 ) . '/assets/{ran-booster/*.css,*.css}', GLOB_BRACE );
		foreach ( false === $stylesheets ? array() : $stylesheets as $file ) {
			if ( str_ends_with( $file, '25-admin-primitives.css' ) || str_ends_with( $file, '00-foundations.css' ) || str_ends_with( $file, 'ran-booster-notice-compat.css' ) ) {
				continue;
			}
			$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
			$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
			if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $rules as $rule ) {
				$selector = (string) preg_replace( '/\s+/', ' ', trim( $rule[1] ) );
				// Native text controls only: checkbox, radio and switch rules, ::file-selector-button, and :has() wrappers are not text-control borders.
				if ( 1 !== preg_match( '/(?:^|[\s,>])(?:select|textarea|input\[type="(?:text|search|password|url|email|number)"\])(?=[\s,:{]|$)/', $selector ) || str_contains( $selector, ':has(' ) || str_contains( $selector, 'switch' ) ) {
					continue;
				}
				if ( 1 === preg_match( '/(?:^|;|\s)border(?:-color)?:\s*(?!0\b|none)[^;]*;?/', $rule[2], $border ) && ! str_contains( $border[0], 'var(--ran-booster-control-border)' ) && 1 !== preg_match( '/var\(--wpds-color-stroke-(?:interactive-neutral-(?:active|disabled)|focus)\)/', $border[0] ) ) {
					$offenders[] = basename( $file ) . ': ' . $selector . ' => ' . trim( $border[0] );
				}
			}
		}

		self::assertSame( array(), $offenders, 'Text controls take their border colour from the shared --ran-booster-control-border rule, not from per-component overrides.' );
	}

	private function asset( string $file ): string {
		if ( 'ran-booster.css' !== $file ) {
			return $this->raw_asset( $file );
		}

		return implode(
			"\n",
			array_map(
				fn ( string $component ): string => $this->raw_asset( 'ran-booster/' . $component ),
				$this->style_components()
			)
		);
	}

	private function raw_asset( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Tests inspect local source assets without HTTP.
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/assets/' . $file );

		self::assertIsString( $contents );

		return $contents;
	}

	/** @return list<string> */
	private function style_components(): array {
		$constant = ( new \ReflectionClass( Booster::class ) )->getReflectionConstant( 'ADMIN_STYLE_COMPONENTS' );
		self::assertNotFalse( $constant );
		$component_list = $constant->getValue();

		self::assertIsArray( $component_list );

		if ( ! array_is_list( $component_list ) ) {
			self::fail( 'Style components must be a list.' );
		}
		foreach ( $component_list as $component ) {
			self::assertIsString( $component );
		}
		return $component_list;
	}

	private function view( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Tests inspect local view source without HTTP.
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/views/' . $file );

		self::assertIsString( $contents );

		return $contents;
	}

	private function source( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Tests inspect local source without HTTP.
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );

		self::assertIsString( $contents );

		return $contents;
	}
}
