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
		self::assertStringContainsString( 'data-portability-credential-toggle', $html );
		self::assertStringContainsString( 'Packages to include', $html );
		self::assertStringContainsString( 'data-portability-export-select-all', $html );
		self::assertStringContainsString( 'name="packages[plugin][]" value="example/example.php" type="checkbox" checked', $html );
		self::assertStringContainsString( 'Example &lt;Plugin&gt;', $html );
		self::assertStringContainsString( 'example/example.php', $html );
		self::assertStringContainsString( 'aria-controls="ran-booster-portability-export-credential-details"', $html );
		self::assertStringContainsString( 'id="ran-booster-portability-export-credential-details" class="ran-booster-portability__credential-details" hidden', $html );
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
		self::assertStringContainsString( 'class="ran-booster-portability__reconciliation-row"', $html );
		self::assertStringContainsString( 'data-portability-row="0" data-portability-action="install"', $html );
		self::assertStringContainsString( 'data-portability-package-name="Install me"', $html );
		self::assertStringContainsString( 'data-portability-package-type="Plugin"', $html );
		self::assertStringContainsString( 'data-portability-package-identifier="plugin/install.php"', $html );
		self::assertStringContainsString( 'data-portability-package-name="&lt;Private &amp; Forms&gt;"', $html );
		self::assertStringContainsString( 'data-portability-package-identifier="private/&lt;forms&gt;.php"', $html );
		self::assertStringContainsString( 'class="notice notice-warning inline"', $html );
		self::assertStringContainsString( 'for="ran-booster-portability-target-credential-4"', $html );
		self::assertStringContainsString( 'id="ran-booster-portability-target-credential-4"', $html );
		self::assertStringContainsString( 'reviews the Transporter Blueprint again automatically', $html );
		self::assertStringNotContainsString( 'Review Transporter Blueprint again', $html );
		self::assertStringContainsString( 'value="classic-pat"', $html );
		self::assertStringContainsString( 'GitHub &lt;Classic PAT&gt;', $html );
		self::assertStringContainsString( '&lt;Private &amp; Forms&gt;', $html );
		self::assertStringContainsString( 'private/&lt;forms&gt;.php', $html );
		self::assertStringContainsString( 'Repository &lt;access&gt; required', $html );
		self::assertStringContainsString( 'page=ran-booster&amp;tab=gh', $html );
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
	}

	public function testBlockedRowWithoutChoicesDirectsTheUserToCredentialSettings(): void {
		$row               = $this->row( 'Private package', 'private/package.php', 'blocked', 'Credential required' );
		$row['credential'] = array(
			'choices'      => array(),
			'settings_url' => 'https://example.test/settings',
		);

		$html = $this->renderView( array( $row ) );

		self::assertStringContainsString( 'No saved target credentials are available for this provider.', $html );
		self::assertStringContainsString( 'Manage repository credentials', $html );
		self::assertStringNotContainsString( '<select', $html );
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
	private function renderView( array $rows = array(), ?array $exportRows = null, bool $exportUnavailable = false ): string {
		$portabilityReviewRows        = $rows;
		$portabilityExportRows        = $exportRows ?? array(
			array(
				'name'       => 'Example <Plugin>',
				'identifier' => 'example/example.php',
				'type'       => 'plugin',
			),
		);
		$portabilityExportUnavailable = $exportUnavailable;

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
