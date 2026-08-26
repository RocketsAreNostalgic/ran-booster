<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Booster;

final class AdminAssetContractTest extends TestCase {

	public function testCommonSelectorsAreRootedWhileBodyAppendedPickerSelectorsRemainAvailable(): void {
		$css = $this->asset( 'ran-booster.css' );

		self::assertStringContainsString( '.wp-core-ui .ran-booster-admin .button-delete', $css );
		self::assertStringContainsString( '#screen-meta-links', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-masthead', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-brand', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-credential-modal', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-secondary-nav', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-activity__details', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-package-activity', $css );
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
		self::assertStringContainsString( '.ran-booster-badge--error,', $css );
		self::assertStringNotContainsString( "\n.wp-core-ui .button-delete", $css );
		self::assertStringNotContainsString( "\n.notice-info {", $css );
		self::assertStringNotContainsString( '.ran-booster-admin .theme-screenshot .content', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-welcome-panel', $css );
		self::assertStringNotContainsString( "\n.ran-booster-credential-modal {", $css );
		self::assertStringNotContainsString( "\n.ran-booster-secondary-nav {", $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-activity__table', $css );

		self::assertStringContainsString( '.ran-booster-dialog {', $css );
		self::assertStringContainsString( "\n.ran-booster-repository-picker__dialog {", $css );
		self::assertStringContainsString( "\nbody.ran-booster-repository-picker-open {", $css );
		self::assertStringContainsString( 'overflow: hidden;', $css );
	}

	public function testAdminStylesheetComponentsKeepCascadeOrderAndNativeButtonScope(): void {
		self::assertSame(
			array(
				'00-foundations.css',
				'10-buttons.css',
				'15-enhanced-mutations.css',
				'20-repository-picker.css',
				'25-admin-primitives.css',
				'30-provider-cards.css',
				'35-status-utilities.css',
				'40-tables-and-pills.css',
				'50-troubleshooting-and-activity.css',
				'55-extensions.css',
				'60-packages.css',
				'65-package-settings.css',
				'70-credential-dialog.css',
				'80-responsive.css',
			),
			$this->styleComponents()
		);

		$buttons = $this->asset( 'ran-booster/10-buttons.css' );

		self::assertStringContainsString( '.wp-core-ui .ran-booster-admin .button-delete', $buttons );
		self::assertStringContainsString( '.button-update-package.ran-booster-update-is-active', $buttons );
		self::assertStringNotContainsString( "\n.wp-core-ui .button-delete", $buttons );
	}

	public function testExtensionsStylesDoNotReimplementTheWordPressPluginCardLayout(): void {
		$extensions = $this->asset( 'ran-booster/55-extensions.css' );

		self::assertStringContainsString( '.ran-booster-extension-card__badge {', $extensions );
		self::assertStringContainsString( '.ran-booster-extension-details {', $extensions );
		self::assertStringContainsString( '.ran-booster-admin--extensions {', $extensions );
		self::assertStringContainsString( 'max-inline-size: 1400px;', $extensions );
		self::assertStringContainsString( 'width: calc(50% - 8px);', $extensions );
		self::assertStringContainsString( '@media screen and (max-width: 782px)', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card.plugin-card', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .plugin-card-top', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .plugin-icon', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .action-links', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .plugin-action-buttons', $extensions );
		self::assertStringNotContainsString( '.ran-booster-extension-card .plugin-card-bottom', $extensions );
	}

	public function testStatusUtilitiesOwnSharedPillAndTileContracts(): void {
		$utilities  = $this->asset( 'ran-booster/35-status-utilities.css' );
		$debug      = $this->view( 'debug-capture.php' );
		$packages   = $this->view( 'packages/index.php' );
		$provider   = $this->view( 'provider.php' );
		$projection = $this->source( 'RAN/Admin/ProviderSettingsPresenter.php' );
		$component  = $this->asset( 'ran-booster/40-tables-and-pills.css' );
		$activity   = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );

		self::assertStringContainsString( '.ran-booster-admin :is(.ran-booster-pill, .ran-booster-badge) {', $utilities );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-tile {', $utilities );
		self::assertStringContainsString( '.ran-booster-badge--pending', $utilities );
		self::assertStringContainsString( 'notice notice-warning inline ran-booster-debug-capture__scope', $debug );
		self::assertStringContainsString( 'ran-booster-badge ran-booster-badge--<?php echo esc_attr( $activityBadgeVariants[ $latestActivity[\'state\'] ] ?? \'neutral\' ); ?>', $packages );
		self::assertStringContainsString( 'Stored · Validity checked on use', $projection );
		self::assertStringContainsString( 'ran-booster-pill--label ran-booster-pill--info ran-booster-delete-credential-package-pill', $provider );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-badge {', $component );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-deployment-state {', $activity );
	}

	public function testCalloutTonesUseSemanticBackgrounds(): void {
		$onboarding      = $this->asset( 'ran-booster-onboarding.css' );
		$troubleshooting = $this->asset( 'ran-booster/50-troubleshooting-and-activity.css' );

		self::assertStringContainsString( '.ran-booster-portability__credential-decision-state--unavailable {', $onboarding );
		self::assertStringContainsString( 'background: var(--ran-booster-status-warning-background);', $onboarding );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-troubleshooting__core-updates.notice-info {', $troubleshooting );
		self::assertStringContainsString( 'background: var(--ran-booster-surface-info);', $troubleshooting );
	}

	public function testAdminPrimitivesOwnSharedEyebrowPanelAndActionRowContracts(): void {
		$primitives  = $this->asset( 'ran-booster/25-admin-primitives.css' );
		$provider    = $this->view( 'provider.php' );
		$portability = $this->view( 'portability.php' );
		$packages    = $this->view( 'packages/edit.php' );
		$debug       = $this->view( 'debug-capture.php' );
		$settings    = $this->asset( 'ran-booster/65-package-settings.css' );

		self::assertStringContainsString( '.ran-booster-admin .ran-booster-eyebrow {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-panel {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-action-row {', $primitives );
		self::assertStringContainsString( 'ran-booster-provider__eyebrow ran-booster-eyebrow', $provider );
		self::assertStringContainsString( 'ran-booster-portability__eyebrow ran-booster-eyebrow', $portability );
		self::assertStringContainsString( 'ran-booster-eyebrow ran-booster-eyebrow--compact', $packages );
		self::assertStringContainsString( 'ran-booster-debug-capture__panel ran-booster-panel', $debug );
		self::assertStringNotContainsString( '.ran-booster-package-summary__eyebrow {', $settings );
	}

	public function testPackageSummaryContainsLongCopyAndTruncatesOnlyTheRepositoryLink(): void {
		$settings = $this->asset( 'ran-booster/65-package-settings.css' );

		self::assertStringContainsString( ".ran-booster-package-summary {\n\tposition: sticky;\n\tinset-block-start: 54px;\n\tmin-inline-size: 0;", $settings );
		self::assertStringContainsString( ".ran-booster-package-summary > div {\n\tmin-inline-size: 0;", $settings );
		self::assertStringContainsString( ".ran-booster-package-summary__meta {\n\tmargin:", $settings );
		self::assertStringContainsString( 'overflow-wrap: anywhere;', $settings );
		self::assertStringContainsString( '> .ran-booster-repository-link {', $settings );
		self::assertStringContainsString( 'text-overflow: ellipsis;', $settings );
		self::assertStringContainsString( 'white-space: nowrap;', $settings );
	}

	public function testPackageSourceNavigationPushesCurrentSourceStatusBadgeInlineAndRightAligned(): void {
		$settings = $this->asset( 'ran-booster/65-package-settings.css' );

		self::assertStringContainsString( '.ran-booster-source-choice--navigation {', $settings );
		self::assertStringContainsString( "\tbox-sizing: border-box;\n\tmargin-block-end: -1px;\n\tdisplay: flex;\n\talign-items: center;", $settings );
		self::assertStringContainsString( '.ran-booster-source-choice--navigation > .ran-booster-source-choice__content {', $settings );
		self::assertStringContainsString( "\tmargin-inline-end: var(--ran-booster-space-10);\n\tmin-inline-size: 0;\n\tflex: 1 1 auto;", $settings );
		self::assertStringContainsString( '.ran-booster-source-choice__current-source {', $settings );
		self::assertStringContainsString( "\tmargin-inline-start: auto;\n\tflex: 0 0 auto;", $settings );
		self::assertStringContainsString( "\tpadding: 2px var(--ran-booster-space-8);\n\tborder: 1px solid var(--ran-booster-status-ok-border);", $settings );
		self::assertStringContainsString( "\tbackground: var(--ran-booster-status-ok-background);\n\tcolor: var(--ran-booster-status-ok);", $settings );
	}

	public function testRepositoryBranchCheckNoticeSitsFlushBesideTheAction(): void {
		$settings = $this->asset( 'ran-booster/65-package-settings.css' );

		self::assertStringContainsString(
			".ran-booster-readiness-actions > .notice {\n\tflex-basis: 100%;\n\tmargin: 0;",
			$settings
		);
	}

	public function testRepositoryWebhookReadinessIconsKeepTheSharedChecklistFootprint(): void {
		$webhookManagement = $this->asset( 'ran-booster-repository-webhook-management.css' );

		self::assertStringContainsString(
			".ran-booster-repository-webhook-readiness\n\t.ran-booster-readiness-icon {\n\tbox-sizing: content-box;",
			$webhookManagement
		);
		self::assertStringContainsString(
			".ran-booster-admin .ran-booster-webhook-steps {\n\tdisplay: grid;\n\tgrid-template-columns: repeat(4, minmax(0, 1fr));",
			$webhookManagement
		);
		self::assertStringContainsString(
			".ran-booster-admin .ran-booster-repository-release-lifecycle {\n\tgrid-template-columns: repeat(3, minmax(0, 1fr));",
			$webhookManagement
		);
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-webhook-step.is-ok > span {', $webhookManagement );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-webhook-step.is-warning > span {', $webhookManagement );
	}

	public function testRepositoryWebhookSetupKeepsItsFormFullWidth(): void {
		$webhookManagement = $this->asset( 'ran-booster-repository-webhook-management.css' );
		$controls          = $this->source( 'RAN/Admin/WebhookManagement/RepositoryWebhookManagementControls.php' );

		self::assertStringContainsString( 'class="ran-booster-readiness-panel ran-booster-repository-webhook-setup"', $controls );
		self::assertStringContainsString( 'class="ran-booster-readiness-panel__top"><div><h4 id="ran-booster-repository-webhook-setup-heading"', $controls );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-setup__body"', $controls );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-repository-webhook-setup__body {', $webhookManagement );
		self::assertStringContainsString( 'padding: var(--ran-booster-space-20);', $webhookManagement );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-repository-webhook-management__manage-link {', $webhookManagement );

		self::assertStringContainsString(
			".ran-booster-repository-webhook-setup\n\t.ran-booster-repository-webhook-management__layout {\n\tdisplay: grid;\n\tgrid-template-columns: minmax(0, 1fr);",
			$webhookManagement
		);
		self::assertStringContainsString(
			".ran-booster-repository-webhook-setup\n\t.ran-booster-repository-webhook-management__form {\n\tpadding: 0;",
			$webhookManagement
		);
		self::assertStringNotContainsString( 'ran-booster-public-lookup-profile__guidance', $webhookManagement );
		self::assertStringNotContainsString(
			'.ran-booster-public-lookup-profile__layout {\n\tgrid-template-columns: minmax(0, 1fr);',
			$webhookManagement
		);
	}

	public function testAdminPrimitivesOwnSharedHeadingsAndCredentialDialogChrome(): void {
		$primitives       = $this->asset( 'ran-booster/25-admin-primitives.css' );
		$onboarding       = $this->view( 'onboarding.php' );
		$provider         = $this->view( 'provider.php' );
		$portability      = $this->view( 'portability.php' );
		$documentation    = $this->view( 'documentation.php' );
		$troubleshooting  = $this->view( 'troubleshooting.php' );
		$modals           = $this->view( 'provider/modals.php' );
		$credentialStyles = $this->asset( 'ran-booster/70-credential-dialog.css' );
		$pickerScript     = $this->asset( 'ran-booster-repository-picker.js' );
		$pickerStyles     = $this->asset( 'ran-booster/20-repository-picker.css' );

		self::assertStringContainsString( '.ran-booster-admin .ran-booster-page-heading__title {', $primitives );
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
		self::assertStringContainsString( 'ran-booster-page-shell ran-booster-panel ran-booster-onboarding', $onboarding );
		self::assertStringContainsString( 'ran-booster-page-shell ran-booster-panel ran-booster-documentation', $documentation );
		self::assertStringContainsString( 'ran-booster-page-shell ran-booster-panel ran-booster-troubleshooting', $troubleshooting );
		self::assertStringContainsString( 'ran-booster-page-shell__body', $documentation );
		self::assertStringContainsString( 'ran-booster-page-shell__body', $troubleshooting );
		self::assertStringContainsString( 'ran-booster-section__title', $provider );
		self::assertStringContainsString( 'ran-booster-credential-modal ran-booster-dialog', $modals );
		self::assertStringContainsString( 'ran-booster-credential-modal__dialog ran-booster-dialog__surface', $modals );
		self::assertStringContainsString( 'ran-booster-dialog__header', $modals );
		self::assertStringNotContainsString( '.ran-booster-credential-modal {', $credentialStyles );
		self::assertStringNotContainsString( 'box-shadow: var(--ran-booster-shadow-modal);', $credentialStyles );
		self::assertStringContainsString( 'ran-booster-repository-picker ran-booster-dialog', $pickerScript );
		self::assertStringContainsString( 'ran-booster-repository-picker__dialog ran-booster-dialog__surface', $pickerScript );
		self::assertStringNotContainsString( '.ran-booster-repository-picker {', $pickerStyles );
	}

	public function testAdminStyleFoundationsAreRootScopedAndConstrainSharedLiterals(): void {
		$css          = $this->asset( 'ran-booster.css' );
		$componentCss = preg_replace( '/\\A[\\s\\S]*?:root \\{[\\s\\S]*?\\n\\}\\n/', '', $css );

		self::assertIsString( $componentCss );

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
		self::assertSame( 0, preg_match_all( '/(?:#[0-9a-f]{3,8}|rgb\\()/i', $componentCss ) );
		self::assertStringContainsString( '@media screen and (max-width: 480px)', $css );
		self::assertStringContainsString( '@media screen and (max-width: 782px)', $css );
		self::assertStringContainsString( '@media screen and (max-width: 1100px)', $css );
	}

	public function testSatelliteStylesUseSharedFoundationsAndLogicalProperties(): void {
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
		self::assertStringContainsString( '@media (prefers-reduced-motion: no-preference)', $documentation );
		self::assertStringContainsString( 'scroll-behavior: smooth;', $documentation );
		self::assertStringContainsString( '@media screen and (max-width: 600px)', $onboarding );
		self::assertStringContainsString( '@media screen and (max-width: 600px)', $documentation );
		self::assertStringContainsString( '@media screen and (max-width: 782px)', $onboarding );
		self::assertStringContainsString( 'class="ran-booster-footer"', $base );
		self::assertStringContainsString( "__( 'Copyright © %s', 'ran-booster' )", $base );
		self::assertStringNotContainsString( 'style="text-align: center;', $base );
	}

	public function testDocumentationPrintContractPreservesGuidanceAndRemovesAdminChrome(): void {
		$documentation = $this->asset( 'ran-booster-documentation.css' );
		$view          = $this->view( 'documentation.php' );

		self::assertStringContainsString( '@media print {', $documentation );
		self::assertStringContainsString( '#wpadminbar,', $documentation );
		self::assertStringContainsString( 'body.wp-admin .notice,', $documentation );
		self::assertStringContainsString( '.ran-admin-shell__navigation,', $documentation );
		self::assertStringNotContainsString( "\t.ran-booster-footer,\n", $documentation );
		self::assertStringContainsString( '[data-ran-booster-feedback-toast],', $documentation );
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
		self::assertStringContainsString( '.ran-booster-documentation__section:not([open]) > :not(summary)', $documentation );
		self::assertStringContainsString( '::-webkit-details-marker', $documentation );
		self::assertStringContainsString( 'break-inside: avoid-page;', $documentation );
		self::assertStringContainsString( 'ran-booster-documentation__section', $view );
		self::assertStringContainsString( 'ran-booster-documentation__content', $view );
	}

	public function testPickerAndCredentialDialogsRetainKeyboardFocusAndBodyLockContracts(): void {
		$credentialScript = $this->asset( 'ran-booster-secure-inputs.js' );
		$pickerScript     = $this->asset( 'ran-booster-repository-picker.js' );

		self::assertStringContainsString( 'document.body.appendChild(modal);', $pickerScript );
		self::assertGreaterThanOrEqual( 1, substr_count( $pickerScript, "event.key === 'Escape'" ) );
		self::assertStringContainsString( 'activeButton.focus();', $pickerScript );
		self::assertGreaterThanOrEqual( 2, substr_count( $pickerScript, 'trapFocus(' ) );
		self::assertMatchesRegularExpression(
			"/document\\.body\\.classList\\.add\\(\\s*'ran-booster-repository-picker-open'\\s*\\);/",
			$pickerScript
		);
		self::assertStringContainsString( 'activeCredentialButton.focus();', $credentialScript );
		self::assertGreaterThanOrEqual( 1, substr_count( $credentialScript, 'trapFocus(' ) );
		self::assertGreaterThanOrEqual( 2, substr_count( $credentialScript, "'ran-booster-repository-picker-open'" ) );
		self::assertStringContainsString( '.ran-booster-open-delete-credential-modal', $credentialScript );
		self::assertStringContainsString( 'populateDeleteCredentialModal(modal, button);', $credentialScript );
		self::assertStringContainsString( "modal.querySelector('[data-delete-credential-cancel]').focus();", $credentialScript );
		self::assertStringContainsString( 'confirmButton.disabled = !usageKnown || inUse;', $credentialScript );
		self::assertStringContainsString( "get('replace_credential')", $credentialScript );
		self::assertMatchesRegularExpression(
			"/button\\.getAttribute\\('data-id'\\)\\s*===\\s*requestedReplacement/",
			$credentialScript
		);
		self::assertStringContainsString( "querySelector('.ran-booster-secret-input')", $credentialScript );
		self::assertStringContainsString( "'ran-booster:provider-tasks-ready'", $credentialScript );
		self::assertStringContainsString( 'initWebhookUrlCopy(root);', $credentialScript );
		self::assertStringContainsString( 'initCredentialSettings();', $credentialScript );
	}

	public function testCredentialSecretFieldKeepsSavedStateSeparateFromTheEmptyNativeInput(): void {
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
		self::assertStringContainsString( 'Enter the provider expiry date, or choose an earlier one.', $modals );
		self::assertStringContainsString( 'A known provider expiry is the latest date allowed.', $modals );
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

	public function testWebhookSecretFieldKeepsManagedSuggestionsAndSavedStateSeparateFromBrowserAutofill(): void {
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

	public function testPortabilityApplyResultsUseWordPressNoticeStates(): void {
		$script = $this->asset( 'ran-booster-portability.js' );

		self::assertStringContainsString( "'notice inline notice-'", $script );
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

	public function testCredentialTableShapesOwnTheirDesktopColumnWidths(): void {
		$css        = $this->asset( 'ran-booster.css' );
		$view       = $this->view( 'provider.php' );
		$modals     = $this->view( 'provider/modals.php' );
		$renderer   = $this->source( 'RAN/Admin/Component/ProviderManagementTableRenderer.php' );
		$dashboard  = $this->source( 'RAN/Dashboard.php' );
		$projection = $this->source( 'RAN/Admin/ProviderSettingsPresenter.php' );

		self::assertStringContainsString( 'ProviderManagementTableRenderer', $dashboard );
		self::assertStringContainsString( 'ran-booster-credential-table--<?php echo esc_attr( $type ); ?>', $renderer );
		self::assertStringContainsString( "public const ACCESS  = 'access';", $renderer );
		self::assertStringContainsString( "public const WEBHOOK = 'webhook';", $renderer );
		self::assertStringContainsString( 'data-credential-delete-modal', $modals );
		self::assertStringContainsString( 'Yes, delete credential', $modals );
		self::assertStringContainsString( 'data-delete-credential-packages', $modals );
		self::assertStringContainsString( 'ran-booster-delete-credential-package-pill', $view );
		self::assertStringContainsString( 'ran_booster[expires_on]', $modals );
		self::assertStringContainsString( "profile['expiry_status']['badge_label']", $projection );
		self::assertStringContainsString( 'class="button button-delete ran-booster-open-delete-credential-modal"', $view );
		self::assertStringContainsString( 'class="button button-delete" data-confirm="<?php echo esc_attr( $profile[\'delete_confirmation\'] ); ?>"', $view );
		self::assertStringNotContainsString( 'Before deleting a credential', $modals );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-delete-credential-package-list {', $css );
		self::assertStringContainsString( 'max-block-size: 160px;', $css );
		self::assertStringContainsString( 'overflow-y: auto;', $css );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-admin \\.ran-booster-credential-row__label \\{[\\s\\S]+display: flex;[\\s\\S]+min-inline-size: 0;/',
			$css
		);
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-provider-empty-state {', $css );
		self::assertStringContainsString( '.ran-booster-credential-table--access th:nth-child(3)', $css );
		self::assertStringContainsString( '.ran-booster-credential-table--webhook th:nth-child(2)', $css );
		self::assertStringNotContainsString( '.ran-booster-credential-table th:nth-child(', $css );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-credential-table--access th:nth-child\\(1\\) \\{\\s+inline-size: 40%;\\s+\\}/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-credential-table--access th:nth-child\\(2\\) \\{\\s+inline-size: 35%;\\s+\\}/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-credential-table--access th:nth-child\\(3\\) \\{\\s+inline-size: 30%;\\s+\\}/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-admin \\.ran-booster-actions \\{[\\s\\S]+flex-wrap: nowrap;[\\s\\S]+justify-content: flex-end;[\\s\\S]+white-space: nowrap;/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-provider-management-table\\.ran-booster-credential-table--access[\\s\\S]+th:nth-child\\(1\\) \\{\\s+inline-size: 20%;\\s+\\}[\\s\\S]+th:nth-child\\(2\\) \\{\\s+inline-size: 12%;\\s+\\}[\\s\\S]+th:nth-child\\(3\\) \\{\\s+inline-size: 12%;\\s+\\}[\\s\\S]+th:nth-child\\(4\\) \\{\\s+inline-size: 12%;\\s+\\}[\\s\\S]+th:nth-child\\(5\\) \\{\\s+inline-size: 12%;\\s+\\}[\\s\\S]+th:nth-child\\(6\\) \\{\\s+inline-size: 32%;\\s+\\}/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-provider-management-table\\.ran-booster-credential-table--access[\\s\\S]+td\\.ran-booster-actions \\{\\s+flex-wrap: wrap;\\s+min-inline-size: 0;\\s+\\}/',
			$css
		);
		self::assertStringContainsString( 'td.ran-booster-actions {', $css );
		self::assertStringContainsString( 'inline-size: auto !important;', $css );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-provider-management__back \\{[\\s\\S]+display: block;[\\s\\S]+padding-inline: var\\(--ran-booster-space-20\\);/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-provider-list-controls \\{[\\s\\S]+flex-wrap: wrap;/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-provider-table-navigation \\{[\\s\\S]+flex-wrap: wrap;[\\s\\S]+padding-inline: var\\(--ran-booster-space-20\\);/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/@media screen and \\(max-width: 1100px\\)[\\s\\S]+\\.ran-booster-provider-management-table tr \\{[\\s\\S]+grid-template-columns: repeat\\(2, minmax\\(0, 1fr\\)\\);[\\s\\S]+\\.ran-booster-data-table\\.ran-booster-provider-management-table[\\s\\S]+td\\[data-label\\]::before \\{[\\s\\S]+content: attr\\(data-label\\);[\\s\\S]+td\\.ran-booster-actions \\{[\\s\\S]+flex-wrap: wrap;[\\s\\S]+white-space: normal;/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-data-table\\s+> tbody\\s+> tr\\s+> \\[data-label\\]::before,/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/@media screen and \\(max-width: 782px\\)[\\s\\S]+\\.ran-booster-webhook-url input \\{\\s+flex: 0 1 auto;\\s+inline-size: 100%;\\s+\\}[\\s\\S]+\\.ran-booster-credential-table td\\.ran-booster-actions \\{\\s+display: flex;\\s+flex-wrap: wrap;\\s+justify-content: flex-start;[\\s\\S]+white-space: normal;/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/@media screen and \\(max-width: 782px\\)[\\s\\S]+\\.ran-booster-provider \\.button \\{\\s+inline-size: auto;\\s+align-self: flex-start;\\s+text-align: center;\\s+\\}/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/@media screen and \\(max-width: 782px\\)[\\s\\S]+\\.ran-booster-provider-list-controls select \\{\\s+flex: 0 1 auto;\\s+inline-size: 100%;\\s+max-inline-size: none;/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-data-table\\.ran-booster-provider-management-table\\s+> tbody\\s+> tr\\s+> td\\.ran-booster-actions \\{\\s+display: flex;\\s+flex-wrap: wrap;/',
			$css
		);
		self::assertDoesNotMatchRegularExpression(
			'/@media screen and \\(max-width: 480px\\)[\\s\\S]+\\.ran-booster-provider-management__header > \\.button \\{\\s+align-self: stretch;\\s+inline-size: 100%;/',
			$css
		);
	}

	public function testProviderSettingsUseTheSharedShellAndProgressiveDisclosure(): void {
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
		self::assertStringContainsString( '$statusSummaryRenderer->render(', $view );
		self::assertSame( 2, substr_count( $view, '$statusSummaryRenderer->render(' ) );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-status-summary {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-status-dot.is-neutral {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-status-dot.is-pending {', $css );
		self::assertStringContainsString( 'ran-booster-webhook-endpoint', $view );
		self::assertStringContainsString( 'data-webhook-url-tools', $view );
		self::assertStringContainsString( 'ran-booster-provider__footer', $view );
		self::assertStringNotContainsString( 'core:webhook-management', $view );
		self::assertStringNotContainsString( 'Assisted Hooks', $view );
		self::assertStringNotContainsString( "'gh'", $view );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-page-shell {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-page-shell__header {', $primitives );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-provider-disclosure > summary:focus-visible', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-public-lookup-profile__layout {', $css );
		self::assertStringContainsString( 'grid-template-columns: minmax(0, 1fr) minmax(280px, 0.8fr);', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-provider-task-panel {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-provider-task-progress {', $css );
		self::assertStringContainsString( "document.addEventListener('htmx:afterSwap'", $script );
		self::assertStringContainsString( "'ran-booster:provider-tasks-ready'", $script );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-push-deploy__summary {', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-push-deploy__assistance-status {', $css );
		self::assertStringNotContainsString( '.ran-booster-admin .ran-booster-push-deploy__notice {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-repository-list {', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__overview', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__action-group {', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__actions {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-provider-management__header {', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-webhook-endpoint {', $css );
		self::assertStringContainsString( 'grid-template-columns: minmax(0, 1fr);', $css );
	}

	public function testManagedPackageTableControlsDesktopWidthsAndAllowsPackageIdentityToWrap(): void {
		$css        = $this->asset( 'ran-booster.css' );
		$packageCss = $this->asset( 'ran-booster/60-packages.css' );
		$script     = $this->asset( 'ran-booster-packages.js' );
		$base       = $this->view( 'base.php' );
		$view       = $this->view( 'packages/index.php' );

		self::assertStringContainsString( "'extensions'      => ' ran-booster-admin--extensions'", $base );
		self::assertStringContainsString( "'packages/index',", $base );
		self::assertStringContainsString( "'packages/create',", $base );
		self::assertStringContainsString( "'packages/edit'   => ' ran-booster-admin--packages'", $base );
		self::assertStringNotContainsString( "! str_starts_with( \$view, 'packages/' )", $base );
		self::assertStringContainsString( "'current' => ! empty( \$adminTab['active'] )", $base );
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
		self::assertStringContainsString( '.ran-booster-admin--packages {', $packageCss );
		self::assertStringContainsString( 'max-inline-size: 1400px;', $packageCss );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-package-table {', $css );
		self::assertStringContainsString( 'table-layout: fixed;', $css );
		self::assertStringContainsString(
			'.ran-booster-package-row__summary,',
			$css
		);
		self::assertStringContainsString( 'overflow-wrap: anywhere;', $css );
		self::assertStringContainsString( "ran-booster-package-row__repo {\n\tdisplay: block;", $css );
		self::assertStringContainsString( 'ran-booster-package-row__details-grid--branch', $view );
		self::assertStringContainsString( 'minmax(90px, 0.65fr)', $packageCss );
		self::assertStringContainsString( 'minmax(230px, 1.6fr)', $packageCss );
		self::assertStringContainsString( 'ran-booster-package-row__activity-summary', $view );
		self::assertStringContainsString( 'flex-wrap: nowrap;', $packageCss );
		self::assertStringNotContainsString( 'Last successful revision', $view );
		self::assertStringContainsString( 'rowspan="2"', $view );
		self::assertStringContainsString(
			'class="ran-booster-package-row ran-booster-package-row--primary<?php echo $wordPressPluginActive ? \' ran-booster-package-row--wordpress-active\' : \'\'; ?>"',
			$view
		);
		self::assertStringContainsString(
			'class="ran-booster-package-row ran-booster-package-row--details<?php echo $wordPressPluginActive ? \' ran-booster-package-row--wordpress-active\' : \'\'; ?>"',
			$view
		);
		self::assertStringContainsString( '<td colspan="3" class="ran-booster-package-row__details">', $view );
		self::assertStringContainsString( 'class="manage-column column-primary ran-booster-package-table__package-header"', $view );
		self::assertStringContainsString( 'class="manage-column ran-booster-package-table__actions-header"', $view );
		self::assertStringContainsString( 'ran-booster-package-row__identity', $view );
		self::assertStringContainsString( 'class="ran-booster-package-row__summary"', $view );
		self::assertStringContainsString( 'class="ran-booster-package-row__actions"', $view );
		self::assertStringContainsString( 'class="ran-booster-package-row__action-group"', $view );
		self::assertStringContainsString( 'class="button"><?php esc_html_e( \'Edit settings\'', $view );
		self::assertStringNotContainsString( "\$policyDisabled ? ' button-primary' : ''", $view );
		self::assertStringNotContainsString( 'class="ran-booster-meta__tiles"', $view );
		self::assertStringNotContainsString( 'class="ran-booster-meta__badges"', $view );
		self::assertMatchesRegularExpression(
			'/button button-primary button-update-package[\s\S]+data-ran-booster-update-button/',
			$view
		);
		self::assertStringContainsString( 'Reinstall', $view );
		self::assertStringContainsString( 'Deployment activity', $view );
		self::assertStringContainsString( '.ran-booster-package-table__actions-header', $css );
		self::assertStringContainsString( '@media screen and (max-width: 1100px)', $css );
		self::assertStringContainsString( '@media screen and (max-width: 782px)', $css );
		self::assertStringContainsString( '@media screen and (max-width: 480px)', $css );
		self::assertMatchesRegularExpression(
			'/\.ran-booster-package-list-search input\[type="search"\],\s+\.ran-booster-admin \.ran-booster-package-list-search \.button \{\s+min-block-size: 40px;\s+\}/',
			$packageCss
		);
		self::assertStringContainsString( 'line-height: 38px;', $packageCss );
		self::assertMatchesRegularExpression(
			'/@media screen and \(max-width: 1100px\) \{\s+\.ran-booster-admin \.ran-booster-package-list-controls \{\s+align-items: stretch;\s+flex-direction: column;/',
			$packageCss
		);
		self::assertMatchesRegularExpression(
			'/@media screen and \(min-width: 783px\) and \(max-width: 1200px\) \{[\s\S]+\.ran-booster-package-table__actions-header \{\s+inline-size: 180px;[\s\S]+\.ran-booster-package-row__action-group \{\s+align-items: stretch;\s+flex-direction: column;[\s\S]+\.ran-booster-package-row__action-group > \*,[\s\S]+inline-size: 100%;/',
			$packageCss
		);
		self::assertStringNotContainsString( '@media screen and (max-width: 1250px)', $packageCss );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-package-toolbar\\.tablenav\\.top \\.actions \\{\\s+display: flex;/',
			$packageCss
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-package-toolbar\\.tablenav\\.top\\s+\\.displaying-num \\{\\s+display: inline-block;/',
			$packageCss
		);
		self::assertStringContainsString( '.ran-booster-package-row__action-group {', $css );
		self::assertStringContainsString( 'ran-booster-package-row__state ran-booster-package-row__wordpress-state', $view );
		self::assertStringContainsString( 'ran-booster-package-row__state ran-booster-package-row__update-state', $view );
		self::assertStringContainsString( 'is-<?php echo esc_attr( $deploymentPolicy->value ); ?>', $view );
		self::assertStringContainsString( '.ran-booster-package-row__state-value::before {', $packageCss );
		self::assertStringContainsString( '.ran-booster-package-row__update-state.is-automatic {', $packageCss );
		self::assertStringContainsString( '.ran-booster-package-row__update-state.is-manual {', $packageCss );
		self::assertStringContainsString( 'rgba(var(--wp-admin-theme-color--rgb), 0.08);', $packageCss );
		self::assertStringContainsString( ".ran-booster-package-row--wordpress-active\n\t> .check-column,", $packageCss );
		self::assertStringContainsString( 'border-inline-start: 4px solid var(--wp-admin-theme-color);', $packageCss );
		self::assertStringContainsString( 'rgba(var(--wp-admin-theme-color--rgb), 0.12);', $packageCss );
		self::assertStringContainsString( 'justify-content: flex-end;', $css );
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-package-row__action-group \\{[\\s\\S]+?flex-wrap: nowrap;/',
			$packageCss
		);
		self::assertMatchesRegularExpression(
			'/\\.ran-booster-package-row__action-group\\s+\\.button-update-package \\{\\s+box-sizing: border-box;\\s+inline-size: 136px;/',
			$packageCss
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
		self::assertStringContainsString( 'data-package-source="<?php echo esc_attr( $package->getSource()->value ); ?>"', $view );
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
		self::assertStringNotContainsString( "\$policyDisabled ? 'inactive' : 'active'", $view );
	}

	public function testManagedPackageBulkControlsUseAnExternalFormAndAccessibleSelectionContract(): void {
		$css    = $this->asset( 'ran-booster.css' );
		$script = $this->asset( 'ran-booster-packages.js' );
		$view   = $this->view( 'packages/index.php' );

		self::assertStringContainsString( 'data-ran-booster-bulk-form', $view );
		self::assertStringContainsString( 'name="ran_booster[identifiers][]"', $view );
		self::assertStringContainsString( 'form="<?php echo esc_attr( $bulkFormId ); ?>"', $view );
		self::assertStringContainsString( 'data-ran-booster-select-all', $view );
		self::assertStringContainsString( 'data-ran-booster-selection-status', $view );
		self::assertStringContainsString( 'aria-live="polite"', $view );
		self::assertStringContainsString( 'class="screen-reader-text"', $view );
		self::assertStringNotContainsString( 'disabled( ! $bulkEligible )', $view );
		self::assertStringNotContainsString( '$bulkEligible =', $view );
		self::assertStringContainsString( '> .check-column,', $css );
		self::assertStringContainsString( 'inline-size: 40px;', $css );
		self::assertStringNotContainsString( '$policyDisabled ? \'inactive\' : \'active\'', $view );
		self::assertStringNotContainsString( '.ran-booster-package-row:nth-child(odd)', $css );
		self::assertStringContainsString( 'initBulkPackageControls();', $script );
		self::assertStringContainsString( 'initPackageUpdateProgress();', $script );
		self::assertStringContainsString( 'data-ran-booster-update-summary', $script );
		self::assertStringContainsString( '@keyframes ran-booster-update-stripes', $css );
		self::assertStringContainsString( 'min-inline-size: 136px;', $css );
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

	public function testPackageRemovalControlsRequireExplicitCheckboxConfirmation(): void {
		$css        = $this->asset( 'ran-booster.css' );
		$script     = $this->asset( 'ran-booster-packages.js' );
		$dangerZone = $this->view( 'packages/danger-zone.php' );
		$index      = $this->view( 'packages/index.php' );

		self::assertStringContainsString( 'initConfirmedPackageRemovals();', $script );
		self::assertStringContainsString( 'function initConfirmedPackageRemovals()', $script );
		self::assertStringContainsString( '[data-ran-booster-confirmed-package-removal]', $script );
		self::assertStringContainsString( '[data-ran-booster-package-removal-confirm]', $script );
		self::assertStringContainsString( '[data-ran-booster-package-removal-submit]', $script );
		self::assertStringContainsString( 'submit.disabled = !confirmation.checked;', $script );
		self::assertStringNotContainsString( "form.setAttribute('aria-busy', 'true');", $script );

		self::assertSame( 2, substr_count( $dangerZone, 'data-ran-booster-confirmed-package-removal' ) );
		self::assertStringContainsString( 'class="ran-booster-settings-disclosure ran-booster-package-danger-zone"', $dangerZone );
		self::assertStringContainsString( 'data-ran-booster-package-disclosure', $dangerZone );
		self::assertSame( 2, substr_count( $dangerZone, 'data-ran-booster-package-mutation' ) );
		self::assertSame( 2, substr_count( $dangerZone, 'data-ran-booster-native-submit' ) );
		self::assertSame( 0, substr_count( $dangerZone, 'hx-target="#wpbody-content"' ) );
		self::assertSame( 2, substr_count( $dangerZone, 'name="ran_booster[confirm_package_removal]" value="1" required' ) );
		self::assertSame( 2, substr_count( $dangerZone, 'name="ran_booster[expected_source_revision]"' ) );
		self::assertStringContainsString( "\$packageView->getAction( 'unlink' )", $dangerZone );
		self::assertStringContainsString( "\$packageView->getAction( 'unlink-delete' )", $dangerZone );
		self::assertStringContainsString( 'disabled data-ran-booster-package-removal-submit', $dangerZone );

		self::assertStringContainsString( '.ran-booster-package-danger-zone > summary {', $css );
		self::assertStringContainsString( 'grid-template-columns: 10px auto minmax(0, 1fr);', $css );
		self::assertStringContainsString( 'grid-column: 2;', $css );
		self::assertStringContainsString( '.ran-booster-package-danger-zone__actions {', $css );
		self::assertStringContainsString( 'grid-template-columns: repeat(2, minmax(0, 1fr));', $css );
		self::assertStringContainsString( '.ran-booster-package-danger-zone__actions .button {', $css );
		self::assertStringContainsString( 'inline-size: 100%;', $css );

		self::assertStringNotContainsString( "\$packageView->getAction( 'unlink' )", $index );
		self::assertStringNotContainsString( '$unlinkLabel', $index );
	}

	public function testPackageMutationsShareTheCoreHtmxFeedbackContract(): void {
		$index      = $this->view( 'packages/index.php' );
		$reinstall  = $this->view( 'packages/reinstall.php' );
		$dangerZone = $this->view( 'packages/danger-zone.php' );
		$notices    = $this->view( 'notices.php' );
		$renderer   = $this->source( 'RAN/Admin/Component/AdminActionRenderer.php' );
		$feedback   = $this->asset( 'ran-booster-enhanced-mutations.js' );
		$packages   = $this->asset( 'ran-booster-packages.js' );

		foreach ( array( $index, $reinstall, $dangerZone, $renderer ) as $markup ) {
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
		self::assertStringContainsString( 'consumeEnhancedSuccess()', $feedback );
		self::assertStringContainsString( 'history.replaceState', $feedback );
		self::assertStringNotContainsString( "header( 'HX-Replace-Url:", $this->source( 'RAN/Dashboard.php' ) );
		self::assertStringContainsString( 'captureInteractionState(form)', $feedback );
		self::assertStringContainsString( 'restoreInteractionState()', $feedback );
		self::assertStringContainsString( 'window.scrollTo(0, state.scrollY)', $feedback );
		self::assertStringNotContainsString( 'packageSourceScrollY', $packages );
		self::assertStringContainsString( 'status === 400', $feedback );
		self::assertStringContainsString( "event.detail?.target?.id === 'wpbody-content'", $packages );
	}

	public function testDeploymentActivityUsesCoLocatedAttemptRowDisclosures(): void {
		$css    = $this->asset( 'ran-booster.css' );
		$view   = $this->view( 'attempts/index.php' );
		$detail = $this->view( 'attempts/detail.php' );

		self::assertStringContainsString( 'ran-booster-attempt-table', $view );
		self::assertStringContainsString( 'ran-booster-attempt-list', $view );
		self::assertStringContainsString( 'ran-booster-attempt-row__details', $view );
		self::assertStringContainsString( 'ran-booster-data-table-wrap ran-booster-attempt-table', $view );
		self::assertStringContainsString( 'ran-booster-badge ran-booster-badge--', $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Time\'', $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Project\'', $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Source\'', $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Activity\'', $view );
		self::assertStringContainsString( 'data-label="<?php esc_attr_e( \'Outcome\'', $view );
		self::assertStringContainsString( 'ran-booster-activity__details', $view );
		self::assertStringContainsString( 'DeploymentOutcomeMessage::forCode', $view );
		self::assertStringContainsString( 'DeploymentOutcomeMessage::forCode', $detail );
		self::assertStringContainsString( "<summary><?php esc_html_e( 'View details', 'ran-booster' ); ?></summary>", $view );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-attempt-row__details', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-attempt-row:nth-child(odd)', $css );
		self::assertStringContainsString( '--ran-booster-table-cell-padding:', $css );
		self::assertStringContainsString( '.ran-booster-admin .ran-booster-data-table-wrap', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__summary', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__identity', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__overview', $css );
		self::assertStringContainsString( '.ran-booster-repository-record__actions', $css );
	}

	private function asset( string $file ): string {
		if ( 'ran-booster.css' !== $file ) {
			return $this->rawAsset( $file );
		}

		return implode(
			"\n",
			array_map(
				fn ( string $component ): string => $this->rawAsset( 'ran-booster/' . $component ),
				$this->styleComponents()
			)
		);
	}

	private function rawAsset( string $file ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Tests inspect local source assets without HTTP.
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/assets/' . $file );

		self::assertIsString( $contents );

		return $contents;
	}

	/** @return list<string> */
	private function styleComponents(): array {
		$componentList = ( new \ReflectionClass( Booster::class ) )->getReflectionConstant( 'ADMIN_STYLE_COMPONENTS' )?->getValue();

		self::assertIsArray( $componentList );

		return $componentList;
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
