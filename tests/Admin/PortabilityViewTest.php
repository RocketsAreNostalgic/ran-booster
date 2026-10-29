<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class PortabilityViewTest extends TestCase {

	public function test_renders_the_two_modes_and_an_empty_import_review(): void {
		$html = $this->render_view();

		self::assertStringContainsString( '>Transporter: Booster migration tools</h2>', $html );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath          = new \DOMXPath( $document );
		$heading_panels = $xpath->query( '//*[@id="ran-booster-portability-heading"]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-panel ")]' );
		$export_warning = $xpath->query( '//*[@id="ran-booster-portability-export"][contains(concat(" ", normalize-space(@class), " "), " ran-booster-panel ")]//*[contains(text(), "Configuration export does not verify installed files")]' );
		self::assertInstanceOf( \DOMNodeList::class, $heading_panels );
		self::assertInstanceOf( \DOMNodeList::class, $export_warning );
		self::assertCount( 0, $heading_panels );
		self::assertCount( 1, $export_warning );
		$export_disclosure = $xpath->query( '//*[@id="ran-booster-portability-export"]//header/details[contains(concat(" ", normalize-space(@class), " "), " ran-booster-disclosure ")]' );
		$export_limits     = $xpath->query( '//*[@id="ran-booster-portability-export"]//header/details/summary[contains(text(), "Export limits")]/following-sibling::div/ul/li' );
		$full_backup_copy  = $xpath->query( '//*[@id="ran-booster-portability-export"]//header/p[contains(text(), "This is not a full backup.")]' );
		$deletion_notice   = $xpath->query( '//*[@id="ran-booster-portability-export"]//header//*[contains(concat(" ", normalize-space(@class), " "), " notice ")]' );
		self::assertInstanceOf( \DOMNodeList::class, $export_disclosure );
		self::assertInstanceOf( \DOMNodeList::class, $export_limits );
		self::assertInstanceOf( \DOMNodeList::class, $full_backup_copy );
		self::assertInstanceOf( \DOMNodeList::class, $deletion_notice );
		self::assertCount( 1, $export_disclosure );
		self::assertCount( 4, $export_limits );
		self::assertCount( 1, $full_backup_copy );
		self::assertCount( 0, $deletion_notice );
		self::assertFalse( $export_disclosure->item( 0 )->hasAttribute( 'open' ) );
		self::assertStringContainsString( 'Known installed requirements are observations.', $export_limits->item( 0 )->textContent );
		self::assertStringContainsString( 'Configuration export does not verify installed files', $export_limits->item( 1 )->textContent );
		self::assertStringContainsString( 'Webhook secrets and deployment history are excluded.', $export_limits->item( 2 )->textContent );
		self::assertStringContainsString( 'Deleting Booster removes its saved local credentials and records.', $export_limits->item( 3 )->textContent );
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
		self::assertStringContainsString( 'Choose repository access, then review the changes below.', $html );
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

	public function test_export_renders_explicit_eligible_and_unavailable_credential_choices_without_sensitive_metadata(): void {
		$html = $this->render_view(
			export_credential_groups: array(
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
		self::assertStringContainsString( 'ran-booster-portability__credential-decision-state--unavailable', $html );
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

	public function test_closed_package_details_hide_background_checks_but_keep_cautions_visible(): void {
		$rows = array();
		foreach ( array(
			'not_declared' => 'unmanaged',
			'failed'       => 'different',
			'not_supplied' => 'unavailable',
			'not_checked'  => 'matching',
		) as $check => $comparison ) {
			$rows[] = $this->row( 'Package', 'package/plugin.php', 'adopt', 'Action reason' ) + array(
				'requirements' => array(
					'basis'        => 'installed_headers',
					'checks'       => array(
						'requires_php'       => $check,
						'requires_wordpress' => 'not_declared',
					),
					'dependencies' => array( 'requires_php' => 'missing' ),
				),
				'comparison'   => array(
					'state'       => $comparison,
					'differences' => array(),
				),
			);
		}
		$document = new \DOMDocument();
		$document->loadHTML( '<?xml encoding="UTF-8">' . $this->render_view( $rows ), LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		foreach ( array_keys( $rows ) as $index ) {
			$row     = '//tr[@data-portability-row="' . $index . '"]';
			$details = '//tr[@data-portability-details-for="' . $index . '"]/td[@colspan="4"]/details[not(@open)]';
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $details . ')' ) );
			self::assertSame( 0.0, $xpath->evaluate( 'count(' . $row . '//details)' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $details . '//li/strong[.="WordPress minimum"])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $details . '//li[strong="WordPress minimum"]/code[.="No minimum declared"])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $details . '/summary[.="Package details"])' ) );
			self::assertSame( 0.0, $xpath->evaluate( 'count(' . $row . '//li[not(ancestor::details)][contains(.,"No minimum declared")])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $details . '//li[contains(.,"WordPress minimum: No minimum declared")])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $details . '//p[contains(.,"Booster has not checked whether the installed files match")])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $details . '//p[contains(.,"The Blueprint does not specify an exact version or commit")])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $row . '//span[not(ancestor::details)][.="Action reason"])' ) );
			// A dependency slug may match a requirement key; both cautions must survive.
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $row . '//li[not(ancestor::details)][.="requires_php: Not installed or included in the proposed set"])' ) );
		}
		foreach ( array(
			1 => 'Declared minimum not met',
			2 => 'Observation not supplied',
			3 => 'Not checked',
		) as $index => $label ) {
			self::assertSame( 1.0, $xpath->evaluate( 'count(//tr[@data-portability-row="' . $index . '"]//li[not(ancestor::details)][.="PHP minimum: ' . $label . '"])' ) );
		}
		foreach ( array(
			1 => 'This site’s Booster settings differ from the Blueprint.',
			2 => 'Booster could not compare this site’s settings with the Blueprint.',
		) as $index => $label ) {
			self::assertSame( 1.0, $xpath->evaluate( 'count(//tr[@data-portability-row="' . $index . '"]//p[not(ancestor::details)][.="' . $label . '"])' ) );
		}
	}

	public function test_renders_all_actions_and_credential_reconciliation_from_safe_rows(): void {
		$html = $this->render_view(
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

		self::assertStringContainsString( 'ran-booster-pill ran-booster-pill--ok', $html );
		self::assertStringContainsString( 'ran-booster-pill ran-booster-pill--info', $html );
		self::assertStringContainsString( 'ran-booster-pill ran-booster-pill--warning', $html );
		self::assertStringContainsString( 'ran-booster-pill ran-booster-pill--error', $html );
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

	public function test_package_only_credentials_are_explicit_and_survive_a_successful_review(): void {
		$row               = $this->row( 'Private package', 'private/package.php', 'install', 'Ready' );
		$row['credential'] = array(
			'choices'      => array(
				array(
					'id'     => 'saved-profile',
					'label'  => 'Saved <PAT>',
					'source' => 'file',
				),
			),
			'selected_id'  => 'saved-profile',
			'settings_url' => 'https://example.test/settings',
		);

		$html = $this->render_view( array( $row ) );
		self::assertStringContainsString( 'for="ran-booster-portability-target-0"', $html );
		self::assertStringContainsString( 'name="target_credentials[0]" data-portability-target-credential data-portability-row="0"', $html );
		self::assertStringContainsString( '<option value="">Choose a saved credential</option>', $html );
		self::assertStringContainsString( '<option value="saved-profile" selected="selected">Saved &lt;PAT&gt;</option>', $html );
		self::assertStringContainsString( 'Manage repository credentials', $html );

		$row['credential']['choices']     = array_map(
			static fn( int $index ): array => array(
				'id'    => 'profile-' . $index,
				'label' => 'Profile ' . $index,
			),
			range( 0, 60 )
		);
		$row['credential']['selected_id'] = 'profile-60';
		$html                             = $this->render_view( array( $row ) );
		self::assertStringContainsString( '<option value="profile-60" selected="selected">Profile 60</option>', $html );
		self::assertStringNotContainsString( 'Previously selected credential is unavailable.', $html );
		self::assertSame( 50, substr_count( $html, '<option value="profile-' ) );

		unset( $row['credential']['selected_id'] );
		self::assertStringNotContainsString( 'selected="selected"', $this->render_view( array( $row ) ) );

		$row['credential']['choices'] = array();
		$html                         = $this->render_view( array( $row ) );
		self::assertStringContainsString( 'No saved credentials are available for this provider.', $html );
		self::assertStringContainsString( 'Manage repository credentials', $html );
		self::assertStringNotContainsString( 'data-portability-target-credential', $html );

		$row['credential_ordinal'] = 0;
		self::assertStringNotContainsString( 'No saved credentials are available for this provider.', $this->render_view( array( $row ) ) );
	}

	public function test_export_shows_empty_and_unavailable_states_without_an_enabled_download(): void {
		$empty = $this->render_view( array(), array() );

		self::assertStringContainsString( 'Booster is not managing any packages on this site yet.', $empty );
		self::assertMatchesRegularExpression( '/Download Transporter Blueprint<\\/button>/', $empty );
		self::assertStringContainsString( 'data-portability-export-submit disabled="disabled"', $empty );

		$unavailable = $this->render_view( array(), array(), true );

		self::assertStringContainsString( 'Booster could not load the managed package list.', $unavailable );
		self::assertStringContainsString( 'data-portability-export-submit disabled="disabled"', $unavailable );

		$credential_unavailable = $this->render_view( export_credentials_unavailable: true );
		self::assertStringContainsString( 'You can still create a package-only Blueprint.', $credential_unavailable );
		self::assertStringNotContainsString( 'data-portability-export-submit disabled="disabled"', $credential_unavailable );
	}

	public function test_credential_review_requires_one_explicit_safe_decision(): void {
		$row                       = $this->row( 'Private package', 'private/package.php', 'blocked', 'Credential required' );
		$row['credential_ordinal'] = 0;
		$html                      = $this->render_view(
			array( $row ),
			credential_rows: array(
				array(
					'ordinal'           => 0,
					'provider_label'    => 'GitHub <Cloud>',
					'label'             => 'Imported <PAT>',
					'kind'              => 'classic',
					'kind_label'        => 'Classic personal access token',
					'decision_required' => true,
					'proposed_count'    => 1,
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
		self::assertStringContainsString( 'For each required repository, Booster checks access using only your choice.', $html );
		self::assertStringContainsString( 'Broader credential permissions are unassessed; the active provider handles repository access, and Booster does not authenticate a third-party publisher.', $html );
		self::assertStringContainsString( 'It does not change an already-managed package’s saved credential selection.', $html );
		self::assertStringContainsString( 'Leave these packages unchanged', $html );
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
		self::assertTrue( array_key_exists( 0, $card ) );
		self::assertSame( 1, substr_count( $card[0], 'value="import"' ) );
		self::assertSame( 1, substr_count( $card[0], 'value="target"' ) );
		self::assertSame( 1, substr_count( $card[0], 'value="leave"' ) );
		self::assertStringNotContainsString( ' checked=', $card[0] );
		self::assertSame( 3, substr_count( $card[0], 'data-portability-credential-refresh' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-trigger="change delay:150ms"' ) );
		self::assertSame( 3, substr_count( $card[0], 'hx-include="[data-portability-preview], [data-portability-credential-action]:checked, [data-portability-credential-target]:not(:disabled), [data-portability-target-credential]"' ) );
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

	public function test_credential_review_explains_when_only_protected_packages_remain_unchanged(): void {
		$protected                       = $this->row( 'Protected Theme', 'protected-theme', 'protected', 'Managed differently' );
		$protected['credential_ordinal'] = 0;
		$html                            = $this->render_view(
			array( $protected ),
			credential_rows: array(
				array(
					'ordinal'           => 0,
					'provider_label'    => 'Provider <Cloud>',
					'label'             => 'Imported <Credential>',
					'kind'              => 'token',
					'decision_required' => false,
					'proposed_count'    => 0,
					'recovery_count'    => 0,
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
		self::assertStringContainsString( 'These packages remain unchanged; no credential is imported.', $html );
		self::assertStringContainsString( 'Manage repository credentials', $html );
		self::assertStringNotContainsString( 'name="credential_decisions[0][action]"', $html );
		self::assertStringNotContainsString( 'data-portability-credential-refresh', $html );
		self::assertStringNotContainsString( 'unchanged-secret-canary', $html );
		self::assertStringNotContainsString( 'unchanged-configuration-canary', $html );
		self::assertStringNotContainsString( 'unchanged-source-id-canary', $html );
	}

	public function test_managed_credential_recovery_is_selectable_without_apackage_change(): void {
		$managed                        = $this->row( 'Managed <Plugin>', 'managed/plugin.php', 'managed', 'Configuration matches' );
		$managed['credential_ordinal']  = 0;
		$managed['credential_recovery'] = true;
		$html                           = $this->render_view(
			array( $managed ),
			credential_rows: array(
				array(
					'ordinal'           => 0,
					'provider_label'    => 'GitHub',
					'label'             => 'Recovered PAT',
					'kind'              => 'classic',
					'decision_required' => true,
					'proposed_count'    => 0,
					'recovery_count'    => 1,
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

	public function test_review_renders_the_complete_bounded_blueprint(): void {
		$rows = array_map(
			fn( int $index ): array => $this->row( 'Package ' . $index, 'package-' . $index . '/package.php', 'install', 'Ready' ),
			range( 0, 127 )
		);

		$html = $this->render_view( $rows );

		self::assertSame( 128, substr_count( $html, 'data-portability-row="' ) );
		self::assertStringContainsString( 'data-portability-row="127" data-portability-action="install"', $html );
	}

	public function test_migration_flow_hook_is_zero_argument_and_discards_partial_failure_output(): void {
		$argument_count = null;
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_flows'] = array(
			static function () use ( &$argument_count ): void {
				$argument_count = func_num_args();
				echo '<p>Safe guidance</p>';
			},
		);

		try {
			$html = $this->render_view();
			self::assertSame( 0, $argument_count );
			self::assertStringContainsString( '<p>Safe guidance</p>', $html );

			$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_flows'] = array(
				static function (): void {
					echo 'guidance-secret-canary';
					throw new \RuntimeException( 'fixture failure' );
				},
			);

			self::assertStringNotContainsString( 'guidance-secret-canary', $this->render_view() );
		} finally {
			unset( $GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_flows'] );
		}
	}

	public function test_extension_mode_renders_with_the_chooser_before_its_flow(): void {
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_modes'] = array(
			static function (): void {
				echo '<button data-portability-mode="fixture" aria-controls="ran-booster-portability-fixture">Fixture migration</button>';
			},
		);
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_portability_render_migration_flows'] = array(
			static function (): void {
				echo '<section class="ran-booster-portability__flow fixture-migration" id="ran-booster-portability-fixture" hidden>Fixture flow</section>';
			},
		);

		try {
			$html = $this->render_view();
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
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath          = new \DOMXPath( $document );
		$migration_flow = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-portability ")]/*[@id="ran-booster-portability-fixture" and @hidden and contains(concat(" ", normalize-space(@class), " "), " ran-booster-portability__flow ")]' );
		self::assertInstanceOf( \DOMNodeList::class, $migration_flow );
		self::assertCount( 1, $migration_flow );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Review rows.
	 * @param list<array<string, mixed>>|null $export_rows Export rows.
	 * @param list<array<string, mixed>> $export_credential_groups Credential groups.
	 * @param list<array<string, mixed>> $credential_rows Credential review rows.
	 */
	private function render_view(
		array $rows = array(),
		?array $export_rows = null,
		bool $export_unavailable = false,
		array $export_credential_groups = array(),
		bool $export_credentials_unavailable = false,
		array $credential_rows = array()
	): string {
		$portability_review_rows                    = $rows;
		$portability_credential_rows                = $credential_rows;
		$portability_export_rows                    = $export_rows ?? array(
			array(
				'name'       => 'Example <Plugin>',
				'identifier' => 'example/example.php',
				'type'       => 'plugin',
			),
		);
		$portability_export_unavailable             = $export_unavailable;
		$portability_export_credential_groups       = $export_credential_groups;
		$portability_export_credentials_unavailable = $export_credentials_unavailable;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/portability.php';

		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, string>
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- compact() reads all four parameters to construct the expected row model.
	private function row( string $name, string $identifier, string $action, string $reason ): array {
		return compact( 'name', 'identifier', 'action', 'reason' ) + array( 'type' => 'Plugin' );
	}
}
