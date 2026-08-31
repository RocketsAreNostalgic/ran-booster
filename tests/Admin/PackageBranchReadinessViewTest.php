<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentPolicy;

final class PackageBranchReadinessViewTest extends TestCase {

	#[DataProvider( 'sourceSettingsModeProvider' )]
	public function testSourceSettingsOnlyRenderBranchReadinessForSavedPackages( string $packageSourceMode, bool $expectsReadiness ): void {
		$packageMutationAvailable = true;
		$packageSourceChoices     = array(
			'branch' => array(
				'heading'           => 'Branch',
				'description'       => 'Deploy a saved repository branch.',
				'meta'              => 'Included with Booster',
				'url'               => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins',
				'disabled'          => false,
				'hydrated'          => true,
				'client_hydratable' => false,
			),
		);
		$packageFieldForm         = 'edit' === $packageSourceMode ? 'ran-booster-package-edit-form' : '';
		$packageFieldLayout       = 'grid';
		$packageSourceView        = 'branch';
		$showBranchSettings       = true;
		$releaseManaged           = false;
		$branchReadOnly           = false;
		$branchValue              = 'main';
		$subdirectoryValue        = '';
		$packageAdvancedSections  = array();
		$packageAdvancedSummary   = 'Branch · provider default';
		$packageAdvancedOpen      = false;
		$packageRepositoryReady   = true;
		$packageSource            = array();
		$packageView              = new class() {
			public function getType(): string {
				return 'plugin';
			}
		};

		if ( $expectsReadiness ) {
			$providerCode                 = 'gh';
			$settingsUrl                  = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
			$providerWebhookAvailable     = true;
			$deploymentPolicy             = DeploymentPolicy::MANUAL->value;
			$packageBranchReadiness       = null;
			$repositoryBranchCheckOutcome = null;
		}

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/source-settings.php';
		$html = (string) ob_get_clean();

		if ( $expectsReadiness ) {
			self::assertStringContainsString( 'id="ran-booster-branch-readiness"', $html );
			self::assertStringContainsString( '>Save settings and check</button>', $html );
		} else {
			self::assertStringNotContainsString( 'id="ran-booster-branch-readiness"', $html );
			self::assertStringNotContainsString( '>Save settings and check</button>', $html );
		}
	}

	/** @return array<string, array{string, bool}> */
	public static function sourceSettingsModeProvider(): array {
		return array(
			'new package'   => array( 'create', false ),
			'saved package' => array( 'edit', true ),
		);
	}

	public function testViewReportsBoundedLocalEvidenceWithoutClaimingRemoteWebhookState(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::AUTOMATIC->value;
		$packageBranchReadiness   = array(
			'webhook_settings_url' => 'https://github.com/owner/example/settings/hooks',
			'site'                 => array(
				'status'       => 'ready',
				'reason_codes' => array(),
				'callback_url' => 'https://site.example/wp-json/ran-booster/v1/webhooks/gh',
			),
			'repository'           => array(
				'repository_id'         => 'repo-42',
				'repository'            => 'owner/example',
				'status'                => 'ready',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'repository',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Saved branch setup', $html );
		self::assertStringContainsString( 'id="ran-booster-branch-readiness-heading"', $html );
		self::assertStringContainsString( 'Review the requirements below.', $html );
		self::assertStringContainsString( 'Repository subdirectory', $html );
		self::assertStringContainsString( 'Root is used; no repository subdirectory is configured.', $html );
		self::assertStringContainsString( 'Webhook health', $html );
		self::assertStringContainsString( 'Local webhook requirements are ready.', $html );
		self::assertStringContainsString( 'Review repository webhook settings', $html );
		self::assertStringContainsString( 'panel=repositories&amp;repository=repo-42', $html );
		self::assertStringNotContainsString( '#ran-booster-managed-webhook-repositories-heading', $html );
		self::assertStringNotContainsString( 'Remote webhook', $html );
		self::assertStringNotContainsString( 'Signing secret', $html );
		self::assertStringNotContainsString( 'Local receiver', $html );
		self::assertStringNotContainsString( 'A repository-specific signing secret is saved.', $html );
		self::assertStringNotContainsString( 'href="https://github.com/owner/example/settings/hooks"', $html );
		self::assertStringNotContainsString( 'Manage signing secrets', $html );
		self::assertStringNotContainsString( 'Setup instructions', $html );
		self::assertStringNotContainsString( 'Booster Activity', $html );
		self::assertStringNotContainsString( 'ran-booster-readiness-actions__links', $html );
		self::assertStringContainsString( 'name="ran_booster[check_repository_branch_after_save]"', $html );
		self::assertStringContainsString( '>Review repository webhook settings</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=gh&amp;panel=repositories&amp;repository=repo-42"', $html );
		self::assertStringNotContainsString( 'repository=repo-42#', $html );
		$checkPosition  = strpos( $html, '>Save settings and check</button>' );
		$managePosition = strrpos( $html, '>Review repository webhook settings</a>' );
		self::assertIsInt( $checkPosition );
		self::assertIsInt( $managePosition );
		self::assertTrue( $checkPosition < $managePosition );
		self::assertStringContainsString( 'form="ran-booster-package-edit-form"', $html );
		self::assertStringContainsString( 'hx-post=', $html );
		self::assertStringContainsString( 'hx-post="/wp-admin/admin.php?', $html );
		self::assertStringNotContainsString( 'name="ran_booster_branch_readiness_check"', $html );
		self::assertStringNotContainsString( 'hx-get=', $html );
		self::assertStringContainsString( 'hx-target="#wpbody-content"', $html );
		self::assertStringContainsString( 'hx-select="#wpbody-content"', $html );
		self::assertStringContainsString( 'hx-swap="outerHTML show:#ran-booster-branch-readiness:top"', $html );
		self::assertStringContainsString( 'hx-push-url=', $html );
		self::assertStringContainsString( 'hx-push-url="/wp-admin/admin.php?', $html );
		self::assertStringContainsString( 'data-ran-booster-enhanced-mutation', $html );
		self::assertStringContainsString( 'id="ran-booster-repository-branch-check-error"', $html );
		self::assertStringContainsString( 'data-ran-booster-error-target="#ran-booster-repository-branch-check-error"', $html );
		self::assertStringContainsString( 'hx-include="#ran-booster-package-edit-form, [form=&quot;ran-booster-package-edit-form&quot;]"', $html );
		self::assertStringContainsString( 'data-ran-booster-relocate-rendered-error', $html );
		self::assertStringNotContainsString( 'data-ran-booster-error-target="#ran-booster-package-mutation-error"', $html );
		self::assertStringNotContainsString( 'data-ran-booster-package-mutation', $html );
		self::assertStringNotContainsString( 'remote webhook is configured', strtolower( $html ) );
		self::assertStringNotContainsString( 'ran-booster-badge--error', $html );
	}

	public function testMissingStableRepositoryIdentityDoesNotProvideANavigableWebhookRoute(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::MANUAL->value;
		$packageBranchReadiness   = array(
			'site'       => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository' => array(
				'reason_codes'          => array( 'repository_identity_unavailable' ),
				'local_secret_coverage' => 'unknown',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<button type="button" class="button" disabled aria-disabled="true">Review repository webhook settings<\\/button>/', $html );
		self::assertStringNotContainsString( 'href=', $html );
		self::assertStringNotContainsString( 'panel=repositories', $html );
	}

	#[DataProvider( 'subdirectoryChecklistProvider' )]
	public function testSubdirectoryHasItsOwnReadinessChecklistRow( ?string $outcome, string $class, string $message ): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::MANUAL->value;
		$savedSubdirectoryValue   = 'packages/example';
		$packageMutationAvailable = true;
		$packageBranchReadiness   = array(
			'webhook_settings_url' => 'https://github.com/owner/example/settings/hooks',
			'site'                 => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository'           => array(
				'repository_id'         => 'repo-42',
				'repository'            => 'owner/example',
				'status'                => 'ready',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'repository',
			),
		);
		if ( null !== $outcome ) {
			$repositoryBranchCheckOutcome = $outcome;
		}

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item ' . $class . '">\s*<span[^>]*><\/span>\s*<strong>Repository subdirectory<\/strong>/s', $html );
		self::assertStringContainsString( $message, $html );
	}

	/** @return array<string, array{string|null, string, string}> */
	public static function subdirectoryChecklistProvider(): array {
		return array(
			'not checked'       => array( null, 'is-pending', 'The subdirectory <code>packages/example</code> will be checked when Booster prepares the deployment archive.' ),
			'accessed'          => array( 'verified', 'is-ok', 'The subdirectory <code>packages/example</code> is accessible at this branch.' ),
			'not found'         => array( 'subdirectory_unavailable', 'is-warning', 'The subdirectory <code>packages/example</code> was not found at this branch.' ),
			'check unavailable' => array( 'subdirectory_unverified', 'is-warning', 'The subdirectory <code>packages/example</code> could not be checked.' ),
		);
	}

	public function testSiteReadinessDoesNotMislabelAValidRepositoryIdentity(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::MANUAL->value;
		$packageBranchReadiness   = array(
			'webhook_settings_url' => 'https://github.com/owner/example/settings/hooks',
			'site'                 => array(
				'status'       => 'blocked',
				'reason_codes' => array( 'callback_requires_public_https' ),
				'callback_url' => 'http://localhost/wp-json/ran-booster/v1/webhooks/gh',
			),
			'repository'           => array(
				'repository_id'         => 'repo-42',
				'repository'            => 'owner/example',
				'status'                => 'blocked',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'none',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<strong>Saved repository</strong>', $html );
		self::assertStringContainsString( 'The branch <code>main</code> is saved. The repository identity is available locally; repository access and this branch have not been checked.', $html );
		self::assertStringContainsString( '<strong>Webhook health</strong>', $html );
		self::assertStringContainsString( 'Local webhook requirements need attention.', $html );
		self::assertStringContainsString( 'panel=repositories&amp;repository=repo-42', $html );
		self::assertStringContainsString( 'Review repository webhook settings', $html );
		self::assertStringNotContainsString( 'Review WordPress URLs', $html );
		self::assertStringNotContainsString( 'Manage signing secrets', $html );
		self::assertStringNotContainsString( 'The local webhook endpoint needs attention.', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
	}

	public function testSavedBranchUsesLocalIdentityEvidenceWithoutClaimingBranchReadiness(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'test';
		$deploymentPolicy         = DeploymentPolicy::MANUAL->value;
		$packageBranchReadiness   = array(
			'site'       => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository' => array(
				'repository_id'         => 'repo-42',
				'repository'            => 'owner/example',
				'status'                => 'ready',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'repository',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Saved repository', $html );
		self::assertStringContainsString( 'The branch <code>test</code> is saved. The repository identity is available locally; repository access and this branch have not been checked.', $html );
		self::assertStringContainsString( 'Review the requirements below.', $html );
		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item is-pending">\s*<span[^>]*><\/span>\s*<strong>Saved repository<\/strong>/s', $html );
		self::assertStringNotContainsString( 'test is ready', $html );
		self::assertStringNotContainsString( 'ready for manual deployments', strtolower( $html ) );
	}

	public function testPublishedReleasesKeepsTheSavedRepositoryIdentityGreen(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::MANUAL->value;
		$releaseManaged           = true;
		$packageCurrentSource     = 'release_asset';
		$packageSourceView        = 'branch';
		$providerRepositoryId     = 'repo-42';
		$repositoryValue          = 'owner/example';
		$packageBranchReadiness   = null;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item is-ok">\s*<span[^>]*><\\/span>\s*<strong>Saved repository<\\/strong>/s', $html );
		self::assertStringContainsString( 'The branch <code>main</code> is saved. The repository identity is available locally; repository access and this branch have not been checked.', $html );
		self::assertStringContainsString( 'panel=repositories&amp;repository=repo-42', $html );
		self::assertStringContainsString( 'Pushes are ignored while this package uses Published releases.', $html );
		self::assertStringContainsString( '>View repository webhook status</a>', $html );
		self::assertStringNotContainsString( 'Manage webhooks</button>', $html );
	}

	public function testBranchPackageUsesItsPersistedIdentityWhenReadinessOmitsIt(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::MANUAL->value;
		$providerRepositoryId     = '1315521150';
		$repositoryValue          = 'owner/booster-fixture-plugin';
		$releaseManaged           = false;
		$packageBranchReadiness   = array(
			'site'       => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository' => array(
				'repository'            => 'owner/booster-fixture-plugin',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'unknown',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'panel=repositories&amp;repository=1315521150', $html );
		self::assertStringContainsString( '>Review repository webhook settings</a>', $html );
	}

	public function testBranchPackageDoesNotUsePersistedIdentityWhenReadinessReportsAConflict(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::MANUAL->value;
		$providerRepositoryId     = '1315521150';
		$repositoryValue          = 'owner/booster-fixture-plugin';
		$releaseManaged           = false;
		$packageBranchReadiness   = array(
			'site'       => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository' => array(
				'repository'            => 'owner/booster-fixture-plugin',
				'reason_codes'          => array( 'repository_identity_conflict' ),
				'local_secret_coverage' => 'unknown',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'panel=repositories', $html );
		self::assertMatchesRegularExpression( '/<button type="button" class="button" disabled aria-disabled="true">Review repository webhook settings<\\/button>/', $html );
	}

	#[DataProvider( 'repositoryBranchCheckOutcomeProvider' )]
	public function testSavedRepositoryStateReflectsTheExplicitRemoteCheck( string $outcome, string $class, string $message ): void {
		$providerCode                 = 'gh';
		$settingsUrl                  = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable     = true;
		$branchValue                  = 'test';
		$deploymentPolicy             = DeploymentPolicy::MANUAL->value;
		$repositoryBranchCheckOutcome = $outcome;
		$packageBranchReadiness       = array(
			'site'       => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository' => array(
				'repository_id' => 'repo-42',
				'reason_codes'  => array(),
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item ' . $class . '">\s*<span[^>]*><\/span>\s*<strong>Saved repository<\/strong>/s', $html );
		self::assertStringContainsString( $message, $html );
	}

	/** @return array<string, array{string, string, string}> */
	public static function repositoryBranchCheckOutcomeProvider(): array {
		return array(
			'verified'             => array( 'verified', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
			'unable to check'      => array( 'unable_to_check', 'is-warning', 'The branch <code>test</code> is saved, but repository access and this branch could not be verified.' ),
			'provider unavailable' => array( 'provider_unavailable', 'is-warning', 'The branch <code>test</code> is saved, but its provider is unavailable' ),
			'subdirectory missing' => array( 'subdirectory_unavailable', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
			'subdirectory unknown' => array( 'subdirectory_unverified', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
		);
	}

	#[DataProvider( 'blockedReceiverReasonProvider' )]
	public function testBlockedReceiverReasonsProvideBoundedDiagnosticsGuidance(
		string $reasonCode,
		string $expectedMessage
	): void {
		$providerCode             = 'bb';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::MANUAL->value;
		$packageBranchReadiness   = array(
			'webhook_settings_url' => 'https://bitbucket.org/workspace/example/admin/webhooks',
			'site'                 => array(
				'status'       => 'blocked',
				'reason_codes' => array( $reasonCode ),
				'callback_url' => 'https://site.example/wp-json/ran-booster/v1/webhooks/bb',
			),
			'repository'           => array(
				'repository'            => 'workspace/example',
				'status'                => 'ready',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'repository',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Local webhook requirements need attention.', $html );
		self::assertStringContainsString( 'Webhook health', $html );
		self::assertMatchesRegularExpression( '/<button type="button" class="button" disabled aria-disabled="true">Review repository webhook settings<\\/button>/', $html );
		self::assertStringNotContainsString( '<a href=', $html );
		self::assertStringNotContainsString( 'Review Booster diagnostics', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
	}

	/** @return array<string, array{string, string}> */
	public static function blockedReceiverReasonProvider(): array {
		return array(
			'database unavailable'         => array(
				'database_unavailable',
				'Booster could not access the local data required for Push-to-Deploy.',
			),
			'secrets storage unavailable'  => array(
				'secrets_storage_unavailable',
				'Booster could not access the saved signing setup required for Push-to-Deploy.',
			),
			'managed packages unavailable' => array(
				'managed_packages_unavailable',
				'Booster could not check the managed packages required for Push-to-Deploy.',
			),
			'unknown reason'               => array(
				'unrecognized_reason',
				'Booster could not confirm the local webhook receiver.',
			),
		);
	}

	public function testAutomaticModeShowsAWarningWhenLocalReadinessIsIncomplete(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::AUTOMATIC->value;
		$packageBranchReadiness   = null;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'ran-booster-badge--error', $html );
		self::assertStringContainsString( 'Automatic branch deployment setup needs attention', $html );
		self::assertStringContainsString( 'Local webhook requirements need attention.', $html );
	}

	public function testVerifiedRepositoryBranchCheckUsesOnlyTheGreenRepositoryRow(): void {
		$providerCode                 = 'gh';
		$settingsUrl                  = 'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=example-theme';
		$providerWebhookAvailable     = true;
		$branchValue                  = 'main';
		$deploymentPolicy             = DeploymentPolicy::MANUAL->value;
		$savedSubdirectoryValue       = '';
		$packageBranchReadiness       = array(
			'site'       => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository' => array(
				'status'                => 'ready',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'none',
			),
		);
		$repositoryBranchCheckOutcome = 'verified';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'notice notice-success inline', $html );
		self::assertSame( 0, substr_count( $html, 'data-ran-booster-repository-branch-check' ) );
		self::assertStringContainsString( 'The branch <code>main</code> is accessible with the saved repository settings.', $html );
		self::assertStringContainsString( 'Root is used; no repository subdirectory is configured.', $html );
		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item is-ok">\s*<span[^>]*><\/span>\s*<strong>Repository subdirectory<\/strong>/s', $html );
		self::assertStringNotContainsString( 'main is saved.', $html );
		self::assertStringNotContainsString( 'Local evidence refreshed.', $html );
		self::assertMatchesRegularExpression( '/hx-push-url="[^"]*source_view=branch[^"]*#ran-booster-branch-readiness"/', $html );
		self::assertDoesNotMatchRegularExpression( '/hx-push-url="[^"]*ran_booster_repository_branch_check/', $html );
	}

	public function testFailedRepositoryBranchCheckShowsOneTransientWarningWithoutClaimingReadiness(): void {
		$providerCode                 = 'gh';
		$settingsUrl                  = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable     = true;
		$branchValue                  = 'main';
		$deploymentPolicy             = DeploymentPolicy::AUTOMATIC->value;
		$packageBranchReadiness       = null;
		$repositoryBranchCheckOutcome = 'unable_to_check';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice notice-warning inline', $html );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-repository-branch-check' ) );
		self::assertSame( 1, substr_count( $html, 'id="ran-booster-repository-branch-check-error"' ) );
		self::assertMatchesRegularExpression(
			'/id="ran-booster-repository-branch-check-error"\s+class="notice notice-warning inline"\s+role="alert"\s+tabindex="-1"\s+data-ran-booster-repository-branch-check\s*>\s*<p>Booster could not access the saved repository and branch\. Check the branch name and repository access, then try again\.<\\/p><\\/div>/',
			$html
		);
		self::assertLessThan(
			strpos( $html, '>Save settings and check</button>' ),
			strpos( $html, 'id="ran-booster-repository-branch-check-error"' )
		);
		self::assertStringContainsString( 'Booster could not access the saved repository and branch. Check the branch name and repository access, then try again.', $html );
		self::assertStringNotContainsString( 'Local evidence refreshed.', $html );
		self::assertStringNotContainsString( 'were verified', $html );
	}
}
