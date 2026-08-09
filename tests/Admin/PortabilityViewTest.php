<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class PortabilityViewTest extends TestCase {

	public function testRendersTheTwoModesAndAnEmptyImportReview(): void {
		$html = $this->renderView();

		self::assertStringContainsString( '>Transporter<', $html );
		self::assertStringContainsString( 'Create a Transporter Blueprint', $html );
		self::assertStringContainsString( 'Open a Transporter Blueprint', $html );
		self::assertStringContainsString( 'What do you want to do on this site?', $html );
		self::assertStringNotContainsString( 'Preview-only beta', $html );
		self::assertSame( 2, substr_count( $html, 'data-portability-mode=' ) );
		self::assertStringContainsString( 'id="ran-booster-portability-export"', $html );
		self::assertStringContainsString( 'id="ran-booster-portability-import"', $html );
		self::assertMatchesRegularExpression( '/id="ran-booster-portability-export"[^>]+hidden>/', $html );
		self::assertMatchesRegularExpression( '/id="ran-booster-portability-import"[^>]+hidden>/', $html );
		self::assertStringContainsString( '>Repository credentials<', $html );
		self::assertStringContainsString( 'id="ran-booster-portability-export-review-heading" class="ran-booster-portability__review-title">Blueprint contents</h4>', $html );
		self::assertStringContainsString( 'id="ran-booster-portability-export-packages-heading" class="ran-booster-portability__subsection-title ran-booster-portability__packages-title">Packages</h5>', $html );
		self::assertStringContainsString( 'data-portability-export-select-all', $html );
		self::assertStringContainsString( 'name="packages[plugin][]" value="example/example.php" type="checkbox" checked', $html );
		self::assertStringContainsString( 'Example &lt;Plugin&gt;', $html );
		self::assertStringContainsString( 'example/example.php', $html );
		self::assertStringNotContainsString( 'name="include_credentials"', $html );
		self::assertStringContainsString( 'id="ran-booster-portability-export-credential-details" class="ran-booster-portability__credential-details"', $html );
		self::assertStringContainsString( 'name="password" type="password" minlength="20" maxlength="256"', $html );
		self::assertStringContainsString( 'name="password_confirmation" type="password" minlength="20" maxlength="256"', $html );
		self::assertStringContainsString( 'data-portability-export-form', $html );
		self::assertStringContainsString( 'data-portability-export-message role="alert" hidden', $html );
		self::assertStringContainsString( 'data-portability-export-message-text', $html );
		self::assertStringContainsString( 'data-portability-password', $html );
		self::assertStringContainsString( 'data-portability-password-confirmation', $html );
		self::assertStringContainsString( 'class="ran-booster-portability__password-actions"', $html );
		self::assertStringContainsString( 'data-portability-password-visibility', $html );
		self::assertStringContainsString( 'data-show-label="Show password"', $html );
		self::assertStringContainsString( 'data-hide-label="Hide password"', $html );
		self::assertStringContainsString( 'aria-label="Show password" aria-pressed="false" title="Show password"', $html );
		self::assertStringContainsString( 'type="button" class="button" data-portability-password-generate', $html );
		self::assertStringContainsString( 'data-portability-password-copy', $html );
		self::assertStringContainsString( 'data-copy-label="Copy password"', $html );
		self::assertStringContainsString( 'data-copied-label="Password copied"', $html );
		self::assertStringContainsString( 'aria-label="Copy password" title="Copy password" disabled', $html );
		self::assertStringContainsString( 'data-portability-password-copy-icon', $html );
		self::assertStringContainsString( 'data-portability-password-copy-success-icon', $html );
		self::assertStringContainsString( 'stroke-linecap="round"', $html );
		self::assertStringContainsString( 'focusable="false" hidden', $html );
		self::assertStringContainsString( 'class="screen-reader-text">Copy password</span>', $html );
		self::assertStringContainsString( 'Generate a secure 32-character password or enter at least 20 characters of your own.', $html );
		self::assertStringContainsString( 'data-portability-password-status', $html );
		self::assertStringContainsString( 'role="status" aria-live="polite" aria-atomic="true"', $html );
		self::assertStringContainsString( 'data-generated-message=', $html );
		self::assertStringContainsString( 'data-copied-message=', $html );
		self::assertStringContainsString( 'data-generation-failed-message=', $html );
		self::assertStringContainsString( 'data-copy-failed-message=', $html );
		self::assertStringContainsString( 'id="ran-booster-portability-password-validation"', $html );
		self::assertStringContainsString( 'class="notice notice-warning inline ran-booster-portability__password-validation"', $html );
		self::assertStringContainsString( 'data-required-message="Choose a Transporter Blueprint password before exporting credentials."', $html );
		self::assertStringContainsString( 'data-mismatch-message="The Transporter Blueprint passwords do not match. Nothing was exported."', $html );
		self::assertStringContainsString( 'role="alert" hidden', $html );
		self::assertStringContainsString( 'data-portability-password-validation-message', $html );
		self::assertStringContainsString( 'aria-describedby="ran-booster-portability-password-guidance ran-booster-portability-password-validation"', $html );
		self::assertStringContainsString( 'aria-describedby="ran-booster-portability-password-validation"', $html );
		self::assertStringContainsString( 'Only transfer this Transporter Blueprint between sites you control.', $html );
		self::assertStringContainsString( 'Passwords are never stored by Booster.', $html );
		self::assertStringContainsString( 'for="ran-booster-portability-file"', $html );
		self::assertStringContainsString( 'for="ran-booster-portability-import-password"', $html );
		self::assertStringContainsString( 'accept="application/zip,application/x-zip-compressed,.zip"', $html );
		self::assertStringContainsString( 'name="action" value="ran_booster_export_blueprint"', $html );
		self::assertStringContainsString( 'name="action" value="ran_booster_preview_blueprint"', $html );
		self::assertStringContainsString( 'data-portability-preview', $html );
		self::assertStringContainsString( 'data-portability-apply-nonce=', $html );
		self::assertStringContainsString( 'type="submit" class="button button-primary ran-booster-portability__progress-button" data-portability-preview-submit', $html );
		self::assertStringContainsString( 'data-idle-label="Review Transporter Blueprint"', $html );
		self::assertStringContainsString( 'data-busy-label="Reviewing Transporter Blueprint…"', $html );
		self::assertStringContainsString( '<span data-portability-preview-label>Review Transporter Blueprint</span>', $html );
		self::assertStringContainsString( 'type="button" class="button button-primary ran-booster-portability__progress-button" data-portability-apply', $html );
		self::assertStringContainsString( 'data-idle-label="Apply selected changes"', $html );
		self::assertStringContainsString( 'data-busy-label="Applying…" disabled', $html );
		self::assertStringContainsString( '<span data-portability-apply-label>Apply selected changes</span>', $html );
		self::assertStringContainsString( 'data-portability-apply-results', $html );
		self::assertStringNotContainsString( 'type="file" accept="application/zip,application/x-zip-compressed,.zip" disabled', $html );
		self::assertStringContainsString( 'Choose a Transporter Blueprint to review its packages.', $html );
		self::assertStringContainsString( 'Packages in this import review', $html );
		self::assertStringContainsString( '<h4 id="ran-booster-portability-review-heading" class="ran-booster-portability__review-title">Blueprint review</h4>', $html );
		self::assertStringContainsString( 'Review repository access decisions, package changes and any credential-only recovery proposed for this site.', $html );
		self::assertStringContainsString( '<h5 id="ran-booster-portability-packages-heading" class="ran-booster-portability__subsection-title ran-booster-portability__packages-title">Packages</h5>', $html );
		self::assertStringContainsString( 'Review the target state of each package and select the eligible changes you want to apply.', $html );
		self::assertStringContainsString( 'aria-labelledby="ran-booster-portability-packages-heading"', $html );
		self::assertStringContainsString( 'id="ran-booster-portability-review-progress" class="ran-booster-portability__review-progress htmx-indicator" role="status" aria-live="polite"', $html );
		self::assertStringContainsString( 'data-portability-review-error role="alert" hidden', $html );
		self::assertStringContainsString( 'id="ran-booster-portability-package-review" class="ran-booster-portability__table-scroll"', $html );
		self::assertStringContainsString( 'role="region"', $html );
		self::assertStringContainsString( 'tabindex="0"', $html );
		self::assertStringNotContainsString( 'Switch mode', $html );
		self::assertStringNotContainsString( 'Example preview', $html );
		self::assertStringNotContainsString( 'illustrative rows', $html );
		self::assertStringNotContainsString( 'event-calendar', $html );
		self::assertStringNotContainsString( 'member-directory', $html );
		self::assertStringNotContainsString( 'private-forms', $html );
		self::assertStringNotContainsString( 'harbour-journal', $html );
		self::assertStringNotContainsString( 'campaign-2026', $html );
		self::assertStringNotContainsString( 'application/json', $html );
		self::assertStringNotContainsString( 'credential_id', $html );
	}

	public function testExportRendersExplicitEligibleAndUnavailableCredentialChoicesWithoutSensitiveMetadata(): void {
		$html = $this->renderView(
			exportCredentialGroups: array(
				array(
					'code'        => 'gh',
					'label'       => 'GitHub',
					'credentials' => array(
						array(
							'id'            => 'eligible-profile',
							'label'         => 'Deployment <PAT>',
							'kind_label'    => 'Personal access token (classic)',
							'available'     => true,
							'reason'        => '',
							'destroy_on'    => null,
							'configuration' => 'configuration-canary',
							'secret'        => 'secret-canary',
							'expiry'        => 'expiry-canary',
							'packages'      => array(
								array(
									'index' => 0,
									'name'  => 'Example <Plugin>',
									'type'  => 'plugin',
								),
							),
						),
						array(
							'id'         => 'temporary-profile',
							'label'      => 'Temporary administrator token',
							'kind_label' => 'Fine-grained token',
							'available'  => false,
							'reason'     => 'self_destruct',
							'destroy_on' => '2026-08-31',
							'packages'   => array(
								array(
									'index' => 0,
									'name'  => 'Example <Plugin>',
									'type'  => 'plugin',
								),
							),
						),
						array(
							'id'         => 'constant',
							'label'      => 'Configuration credential',
							'kind_label' => 'Classic token',
							'available'  => false,
							'reason'     => 'configuration',
							'destroy_on' => null,
							'packages'   => array(
								array(
									'index' => 0,
									'name'  => 'Example <Plugin>',
									'type'  => 'plugin',
								),
							),
						),
						array(
							'id'         => 'unassociated-profile',
							'label'      => 'Unused repository token',
							'kind_label' => 'Fine-grained token',
							'available'  => false,
							'reason'     => 'unassociated',
							'destroy_on' => null,
							'packages'   => array(),
						),
					),
				),
			)
		);

		self::assertStringContainsString( 'name="credentials[gh][]" value="eligible-profile" data-portability-export-credential', $html );
		self::assertSame( 1, substr_count( $html, 'eligible-profile' ) );
		self::assertLessThan( strpos( $html, 'id="ran-booster-portability-export-packages-heading"' ), strpos( $html, 'id="ran-booster-portability-export-credentials-heading"' ) );
		self::assertStringContainsString( '<legend class="screen-reader-text">GitHub credential choices</legend>', $html );
		self::assertStringContainsString( 'ran-booster-portability__credential-row ran-booster-portability__credential-card', $html );
		self::assertStringNotContainsString( '>Available for transfer</h6>', $html );
		self::assertStringNotContainsString( '>Unavailable for transfer</h6>', $html );
		self::assertStringContainsString( 'ran-booster-portability__credential-card--unavailable', $html );
		self::assertLessThan( strpos( $html, 'Unused repository token' ), strpos( $html, 'Deployment &lt;PAT&gt;' ) );
		self::assertLessThan( strpos( $html, 'id="ran-booster-portability-export-credential-details"' ), strpos( $html, 'Unused repository token' ) );
		self::assertStringContainsString( 'ran-booster-portability__credential-name">Deployment &lt;PAT&gt;</strong>', $html );
		self::assertStringContainsString( 'ran-booster-tile__value">GitHub</span>', $html );
		self::assertStringContainsString( 'ran-booster-tile__value">Personal access token (classic)</span>', $html );
		self::assertStringContainsString( '<summary>Used by 1 package</summary>', $html );
		self::assertStringContainsString( 'Example &lt;Plugin&gt;', $html );
		self::assertStringContainsString( 'The provider permissions for this credential have not been assessed.', $html );
		self::assertStringContainsString( '<strong>Unavailable for transfer</strong>', $html );
		self::assertStringContainsString( 'Booster will automatically remove this saved credential on 2026-08-31', $html );
		self::assertStringContainsString( 'This credential is supplied by site configuration', $html );
		self::assertStringContainsString( 'Unused repository token', $html );
		self::assertStringContainsString( 'No Booster-managed plugin or theme uses this credential.', $html );
		self::assertStringContainsString( 'data-portability-export-summary', $html );
		self::assertStringNotContainsString( 'value="temporary-profile"', $html );
		self::assertStringNotContainsString( 'value="constant"', $html );
		self::assertStringNotContainsString( 'value="unassociated-profile"', $html );
		self::assertStringNotContainsString( 'configuration-canary', $html );
		self::assertStringNotContainsString( 'secret-canary', $html );
		self::assertStringNotContainsString( 'expiry-canary', $html );
	}

	public function testRendersAllActionsAndCredentialReconciliationFromSafeRows(): void {
		$html = $this->renderView(
			array(
				$this->row( 'Install me', 'plugin/install.php', 'install', 'Not installed' ),
				$this->row( 'Adopt me', 'plugin/adopt.php', 'adopt', 'Installed outside Booster' ),
				$this->row( 'Managed', 'plugin/managed.php', 'managed', 'Configuration matches' ),
				$this->row( 'Protected', 'theme-protected', 'protected', 'Managed differently' ),
				array(
					'name'       => '<Private & Forms>',
					'identifier' => 'private/<forms>.php',
					'type'       => 'Plugin',
					'action'     => 'blocked',
					'reason'     => 'Repository <access> required',
					'secret'     => 'view-secret-canary',
					'credential' => array(
						'choices'      => array(
							array(
								'id'     => 'classic-pat',
								'label'  => 'GitHub <Classic PAT>',
								'source' => 'file',
								'secret' => 'choice-secret-canary',
							),
						),
						'settings_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh',
						'secret'       => 'credential-secret-canary',
					),
				),
			)
		);

		self::assertStringContainsString( 'ran-booster-portability__status--install', $html );
		self::assertStringContainsString( 'ran-booster-portability__status--adopt', $html );
		self::assertStringContainsString( 'ran-booster-portability__status--managed', $html );
		self::assertStringContainsString( 'ran-booster-portability__status--protected', $html );
		self::assertStringContainsString( 'ran-booster-portability__status--blocked', $html );
		self::assertStringContainsString( 'data-portability-select-all', $html );
		self::assertSame( 2, substr_count( $html, 'data-portability-select value=' ) );
		self::assertStringContainsString( 'data-portability-select value="0" checked', $html );
		self::assertStringContainsString( 'data-portability-select value="1" checked', $html );
		self::assertStringNotContainsString( 'data-portability-adopt', $html );
		self::assertStringContainsString( 'Install package', $html );
		self::assertStringContainsString( 'Adopt with Booster', $html );
		self::assertStringContainsString( 'No change', $html );
		self::assertStringContainsString( 'Leave unchanged', $html );
		self::assertStringContainsString( 'Cannot apply', $html );
		self::assertStringNotContainsString( 'class="ran-booster-portability__reconciliation-row"', $html );
		self::assertStringContainsString( 'data-portability-row="0" data-portability-action="install"', $html );
		self::assertStringContainsString( 'data-portability-package-name="Install me"', $html );
		self::assertStringContainsString( 'data-portability-package-type="Plugin"', $html );
		self::assertStringContainsString( 'data-portability-package-identifier="plugin/install.php"', $html );
		self::assertStringContainsString( 'data-portability-package-name="&lt;Private &amp; Forms&gt;"', $html );
		self::assertStringContainsString( 'data-portability-package-identifier="private/&lt;forms&gt;.php"', $html );
		self::assertStringContainsString( '&lt;Private &amp; Forms&gt;', $html );
		self::assertStringContainsString( 'private/&lt;forms&gt;.php', $html );
		self::assertStringContainsString( 'Repository &lt;access&gt; required', $html );
		self::assertStringNotContainsString( 'view-secret-canary', $html );
		self::assertStringNotContainsString( 'choice-secret-canary', $html );
		self::assertStringNotContainsString( 'credential-secret-canary', $html );
	}

	public function testExportShowsEmptyAndUnavailableStatesWithoutAnEnabledDownload(): void {
		$empty = $this->renderView( array(), array() );

		self::assertStringContainsString( 'Booster is not managing any packages on this site yet.', $empty );
		self::assertMatchesRegularExpression( '/Download Transporter Blueprint<\\/button>/', $empty );
		self::assertStringContainsString( 'data-portability-export-submit disabled="disabled"', $empty );

		$unavailable = $this->renderView( array(), array(), true );

		self::assertStringContainsString( 'Booster could not load the managed package list.', $unavailable );
		self::assertStringContainsString( 'data-portability-export-submit disabled="disabled"', $unavailable );

		$credentialUnavailable = $this->renderView( exportCredentialsUnavailable: true );
		self::assertStringContainsString( 'You can still create a package-only Blueprint.', $credentialUnavailable );
		self::assertStringNotContainsString( 'data-portability-export-submit disabled="disabled"', $credentialUnavailable );
	}

	public function testCredentialReviewRequiresOneExplicitSafeDecision(): void {
		$row                       = $this->row( 'Private package', 'private/package.php', 'blocked', 'Credential required' );
		$row['credential_ordinal'] = 0;
		$html                      = $this->renderView(
			array( $row ),
			credentialRows: array(
				array(
					'ordinal'           => 0,
					'provider_label'    => 'GitHub <Cloud>',
					'label'             => 'Imported <PAT>',
					'kind'              => 'classic',
					'kind_label'        => 'Classic personal access token',
					'decision_required' => true,
					'proposed_count'    => 1,
					'unchanged_count'   => 1,
					'packages'          => array(
						array(
							'name' => 'Private package',
							'type' => 'Plugin',
						),
						array(
							'name' => 'Protected <Theme>',
							'type' => 'Theme',
						),
					),
					'action'            => null,
					'target_id'         => null,
					'target_choices'    => array(
						array(
							'id'     => 'saved-profile',
							'label'  => 'Saved <Profile>',
							'source' => 'file',
						),
					),
					'settings_url'      => 'https://example.test/settings',
					'secret'            => 'view-secret-canary',
					'configuration'     => 'configuration-canary',
					'source_id'         => 'source-id-canary',
				),
			)
		);

		self::assertStringContainsString( '<h5 id="ran-booster-portability-credentials-heading" class="ran-booster-portability__subsection-title ran-booster-portability__credentials-title">Repository credentials</h5>', $html );
		self::assertStringContainsString( '<strong>Before you continue:</strong>', $html );
		self::assertStringContainsString( 'Booster checks whether the credential can access each required repository, not what other permissions it has.', $html );
		self::assertStringContainsString( 'Importing a credential for an already-managed package does not change that package’s saved credential selection.', $html );
		self::assertStringContainsString( '<legend class="screen-reader-text">GitHub &lt;Cloud&gt; — Imported &lt;PAT&gt;</legend>', $html );
		self::assertStringContainsString( '<h6 class="ran-booster-portability__credential-name">Imported &lt;PAT&gt;</h6>', $html );
		self::assertStringContainsString( 'ran-booster-tile ran-booster-portability__credential-provider', $html );
		self::assertStringContainsString( 'ran-booster-tile__value">GitHub &lt;Cloud&gt;</span>', $html );
		self::assertStringContainsString( 'ran-booster-tile ran-booster-portability__credential-kind', $html );
		self::assertStringContainsString( 'ran-booster-tile__value">Classic personal access token</span>', $html );
		self::assertStringContainsString( '<summary>Used by 2 packages; 1 package may change</summary>', $html );
		self::assertStringContainsString( 'Protected &lt;Theme&gt;', $html );
		self::assertStringContainsString( 'Saved &lt;Profile&gt;', $html );
		self::assertStringContainsString( 'Manage repository credentials', $html );
		self::assertSame( 3, substr_count( $html, 'name="credential_decisions[0][action]"' ) );
		self::assertSame( 1, preg_match( '/<fieldset class="ran-booster-portability__credential-row ran-booster-portability__credential-card"[\s\S]+?<\/fieldset>/', $html, $card ) );
		self::assertSame( 1, substr_count( $card[0], 'value="import"' ) );
		self::assertSame( 1, substr_count( $card[0], 'value="target"' ) );
		self::assertSame( 1, substr_count( $card[0], 'value="leave"' ) );
		self::assertStringNotContainsString( ' checked=', $card[0] );
		self::assertSame( 3, substr_count( $card[0], 'data-portability-credential-refresh' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-trigger="change delay:150ms"' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-include="[data-portability-preview], [data-portability-credential-action]:checked, [data-portability-credential-target]:not(:disabled)"' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-encoding="multipart/form-data"' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-target="#ran-booster-portability-package-review"' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-select="#ran-booster-portability-package-review"' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-swap="outerHTML show:none"' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-sync="[data-portability-preview]:replace"' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-indicator="#ran-booster-portability-review-progress"' ) );
		self::assertStringContainsString( 'value="target" data-portability-credential-action aria-describedby=', $card[0] );
		self::assertStringNotContainsString( 'value="target" data-portability-credential-action data-portability-credential-refresh', $card[0] );
		self::assertStringNotContainsString( 'view-secret-canary', $html );
		self::assertStringNotContainsString( 'configuration-canary', $html );
		self::assertStringNotContainsString( 'source-id-canary', $html );
	}

	public function testCredentialReviewExplainsWhenOnlyProtectedPackagesRemainUnchanged(): void {
		$protected                       = $this->row( 'Protected Theme', 'protected-theme', 'protected', 'Managed differently' );
		$protected['credential_ordinal'] = 0;
		$html                            = $this->renderView(
			array( $protected ),
			credentialRows: array(
				array(
					'ordinal'           => 0,
					'provider_label'    => 'Provider <Cloud>',
					'label'             => 'Imported <Credential>',
					'kind'              => 'token',
					'decision_required' => false,
					'proposed_count'    => 0,
					'recovery_count'    => 0,
					'unchanged_count'   => 1,
					'packages'          => array(
						array(
							'name' => 'Protected Theme',
							'type' => 'Theme',
						),
					),
					'settings_url'      => 'https://example.test/settings',
					'secret'            => 'unchanged-secret-canary',
					'configuration'     => 'unchanged-configuration-canary',
					'source_id'         => 'unchanged-source-id-canary',
				),
			)
		);

		self::assertStringContainsString( 'This Blueprint includes repository credentials, but none are needed for package changes on this site.', $html );
		self::assertStringNotContainsString( 'Choose how this site should access repositories needed by the proposed package changes.', $html );
		self::assertStringNotContainsString( '<strong>Before you continue:</strong>', $html );
		self::assertStringContainsString( '<summary>Used by 1 package; all unchanged</summary>', $html );
		self::assertStringContainsString( '<strong>No action needed</strong>', $html );
		self::assertStringContainsString( 'No credential choice is needed because every affected package will remain unchanged.', $html );
		self::assertStringContainsString( 'Manage repository credentials', $html );
		self::assertStringNotContainsString( 'name="credential_decisions[0][action]"', $html );
		self::assertStringNotContainsString( 'data-portability-credential-refresh', $html );
		self::assertStringNotContainsString( 'unchanged-secret-canary', $html );
		self::assertStringNotContainsString( 'unchanged-configuration-canary', $html );
		self::assertStringNotContainsString( 'unchanged-source-id-canary', $html );
	}

	public function testManagedCredentialRecoveryIsSelectableWithoutAPackageChange(): void {
		$managed                        = $this->row( 'Managed <Plugin>', 'managed/plugin.php', 'managed', 'Configuration matches' );
		$managed['credential_ordinal']  = 0;
		$managed['credential_recovery'] = true;
		$html                           = $this->renderView(
			array( $managed ),
			credentialRows: array(
				array(
					'ordinal'           => 0,
					'provider_label'    => 'GitHub',
					'label'             => 'Recovered PAT',
					'kind'              => 'classic',
					'decision_required' => true,
					'proposed_count'    => 0,
					'recovery_count'    => 1,
					'unchanged_count'   => 1,
					'packages'          => array(
						array(
							'name' => 'Managed <Plugin>',
							'type' => 'Plugin',
						),
					),
					'action'            => 'import',
					'target_choices'    => array(
						array(
							'id'     => 'saved-profile',
							'label'  => 'Saved profile',
							'source' => 'file',
						),
					),
				),
			)
		);

		self::assertStringContainsString( 'credential recovery is available for 1 managed package', $html );
		self::assertStringContainsString( 'data-portability-action="managed"', $html );
		self::assertStringContainsString( 'data-portability-credential-recovery="true"', $html );
		self::assertStringContainsString( 'data-portability-select value="0" checked', $html );
		self::assertStringContainsString( 'Import credential only', $html );
		self::assertStringNotContainsString( 'value="target" data-portability-credential-action', $html );
		self::assertStringContainsString( 'data-portability-select-all aria-label="Select all actionable changes" checked="checked"', $html );
	}

	public function testReviewRendersTheCompleteBoundedBlueprint(): void {
		$rows = array_map(
			fn( int $index ): array => $this->row( 'Package ' . $index, 'package-' . $index . '/package.php', 'install', 'Ready' ),
			range( 0, 127 )
		);

		$html = $this->renderView( $rows );

		self::assertSame( 128, substr_count( $html, 'data-portability-row="' ) );
		self::assertStringContainsString( 'data-portability-row="127" data-portability-action="install"', $html );
	}

	public function testMigrationFlowHookIsZeroArgumentAndDiscardsPartialFailureOutput(): void {
		$argumentCount = null;
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_flows'] = array(
			static function () use ( &$argumentCount ): void {
				$argumentCount = func_num_args();
				echo '<p>Safe guidance</p>';
			},
		);

		try {
			$html = $this->renderView();
			self::assertSame( 0, $argumentCount );
			self::assertStringContainsString( '<p>Safe guidance</p>', $html );

			$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_flows'] = array(
				static function (): void {
					echo 'guidance-secret-canary';
					throw new \RuntimeException( 'fixture failure' );
				},
			);

			self::assertStringNotContainsString( 'guidance-secret-canary', $this->renderView() );
		} finally {
			unset( $GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_flows'] );
		}
	}

	public function testExtensionModeRendersWithTheChooserBeforeItsFlow(): void {
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_modes'] = array(
			static function (): void {
				echo '<button data-portability-mode="fixture" aria-controls="ran-booster-portability-fixture">Fixture migration</button>';
			},
		);
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_flows'] = array(
			static function (): void {
				echo '<section class="ran-booster-portability__flow" id="ran-booster-portability-fixture" hidden>Fixture flow</section>';
			},
		);

		try {
			$html = $this->renderView();
		} finally {
			unset(
				$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_modes'],
				$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_flows']
			);
		}

		$create  = strpos( $html, 'data-portability-mode="export"' );
		$open    = strpos( $html, 'data-portability-mode="import"' );
		$fixture = strpos( $html, 'data-portability-mode="fixture"' );
		$flow    = strpos( $html, 'id="ran-booster-portability-fixture"' );

		self::assertSame( 3, substr_count( $html, 'data-portability-mode=' ) );
		self::assertIsInt( $create );
		self::assertIsInt( $open );
		self::assertIsInt( $fixture );
		self::assertIsInt( $flow );
		self::assertLessThan( $open, $create );
		self::assertLessThan( $fixture, $open );
		self::assertLessThan( $flow, $fixture );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Review rows.
	 */
	private function renderView(
		array $rows = array(),
		?array $exportRows = null,
		bool $exportUnavailable = false,
		array $exportCredentialGroups = array(),
		bool $exportCredentialsUnavailable = false,
		array $credentialRows = array()
	): string {
		$portabilityReviewRows                   = $rows;
		$portabilityCredentialRows               = $credentialRows;
		$portabilityExportRows                   = $exportRows ?? array(
			array(
				'name'       => 'Example <Plugin>',
				'identifier' => 'example/example.php',
				'type'       => 'plugin',
			),
		);
		$portabilityExportUnavailable            = $exportUnavailable;
		$portabilityExportCredentialGroups       = $exportCredentialGroups;
		$portabilityExportCredentialsUnavailable = $exportCredentialsUnavailable;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/portability.php';

		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, string>
	 */
	private function row( string $name, string $identifier, string $action, string $reason ): array {
		return compact( 'name', 'identifier', 'action', 'reason' ) + array( 'type' => 'Plugin' );
	}
}
