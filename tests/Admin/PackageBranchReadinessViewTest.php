<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentPolicy;

final class PackageBranchReadinessViewTest extends TestCase {

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
				'repository'            => 'owner/example',
				'status'                => 'ready',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'repository',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Branch readiness', $html );
		self::assertStringContainsString( 'Manual deployments remain available when Push-to-Deploy is incomplete.', $html );
		self::assertStringContainsString( 'A repository-specific signing secret is saved.', $html );
		self::assertStringContainsString( 'Remote webhook', $html );
		self::assertStringContainsString( 'Booster cannot verify the remote webhook here.', $html );
		self::assertStringContainsString( 'Manage repository webhook', $html );
		self::assertStringContainsString( 'href="https://github.com/owner/example/settings/hooks"', $html );
		self::assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		self::assertStringNotContainsString( 'Manage signing secrets', $html );
		self::assertStringContainsString( 'Booster Activity', $html );
		self::assertStringContainsString( 'ran-booster-readiness-actions__links', $html );
		$checkPosition  = strpos( $html, '>Run readiness check</button>' );
		$managePosition = strpos( $html, '>Manage repository webhook</a>' );
		self::assertIsInt( $checkPosition );
		self::assertIsInt( $managePosition );
		self::assertTrue( $checkPosition < $managePosition );
		self::assertMatchesRegularExpression( '/<form\s+action="[^"]*admin\.php"[^>]*>.*name="ran_booster_branch_readiness_check" value="1".*<button[^>]*>Run readiness check<\/button>.*<\/form>/s', $html );
		self::assertStringContainsString( 'name="page" value="ran-booster-plugins"', $html );
		self::assertStringContainsString( 'name="package" value="example/example.php"', $html );
		self::assertStringContainsString( 'name="source_view" value="branch"', $html );
		self::assertStringContainsString( 'hx-get=', $html );
		self::assertStringContainsString( 'hx-target="#wpbody-content"', $html );
		self::assertStringContainsString( 'hx-select="#wpbody-content"', $html );
		self::assertStringContainsString( 'hx-swap="outerHTML show:#ran-booster-branch-readiness:top"', $html );
		self::assertStringContainsString( 'hx-push-url=', $html );
		self::assertStringContainsString( 'data-ran-booster-enhanced-mutation', $html );
		self::assertStringContainsString( 'data-ran-booster-error-target="#ran-booster-package-mutation-error"', $html );
		self::assertStringNotContainsString( 'data-ran-booster-package-mutation', $html );
		self::assertStringNotContainsString( 'remote webhook is configured', strtolower( $html ) );
		self::assertStringNotContainsString( 'ran-booster-badge--error', $html );
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
				'repository'            => 'owner/example',
				'status'                => 'blocked',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'none',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<strong>Saved branch</strong>', $html );
		self::assertStringContainsString( 'main is ready for manual deployments.', $html );
		self::assertStringContainsString( '<strong>Local receiver</strong>', $html );
		self::assertStringContainsString( 'This WordPress URL cannot receive provider webhooks.', $html );
		self::assertStringContainsString( 'Use a public HTTPS WordPress URL or a secure tunnel.', $html );
		self::assertStringContainsString( 'Manual deployments remain available.', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/options-general.php"', $html );
		self::assertStringContainsString( 'Review WordPress URLs', $html );
		self::assertStringContainsString( 'Manage signing secrets', $html );
		self::assertStringContainsString( 'view=secrets', $html );
		self::assertStringContainsString( 'Manage repository webhooks', $html );
		self::assertStringNotContainsString( 'The local webhook endpoint needs attention.', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
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

		self::assertStringContainsString( $expectedMessage, $html );
		self::assertStringContainsString( 'Manual deployments remain available.', $html );
		self::assertStringContainsString( 'Review Booster diagnostics', $html );
		self::assertStringContainsString(
			'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=troubleshooting"',
			$html
		);
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
		self::assertStringContainsString( 'Automatic branch deployments need attention', $html );
		self::assertStringContainsString( 'Local signing-secret status is unavailable', $html );
	}

	public function testCompletedReadinessCheckMarksFreshEvidenceAsToastableSuccess(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=example-theme';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::MANUAL->value;
		$packageBranchReadiness   = array(
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test-only preservation of the GET fixture.
		$previousGet                                = $_GET;
		$_GET['ran_booster_branch_readiness_check'] = '1';

		ob_start();
		try {
			require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
			$html = (string) ob_get_clean();
		} finally {
			$_GET = $previousGet;
		}

		self::assertStringContainsString( 'notice notice-success inline', $html );
		self::assertStringContainsString( 'data-ran-booster-package-success', $html );
		self::assertStringContainsString( 'Readiness check complete.', $html );
		self::assertStringContainsString( 'The current local readiness evidence is shown below.', $html );
		self::assertMatchesRegularExpression( '/hx-push-url="[^"]*source_view=branch[^"]*#ran-booster-branch-readiness"/', $html );
		self::assertDoesNotMatchRegularExpression( '/hx-push-url="[^"]*ran_booster_branch_readiness_check/', $html );
	}

	public function testCompletedReadinessCheckKeepsUnavailableEvidencePersistent(): void {
		$providerCode             = 'gh';
		$settingsUrl              = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$providerWebhookAvailable = true;
		$branchValue              = 'main';
		$deploymentPolicy         = DeploymentPolicy::AUTOMATIC->value;
		$packageBranchReadiness   = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test-only preservation of the GET fixture.
		$previousGet                                = $_GET;
		$_GET['ran_booster_branch_readiness_check'] = '1';

		ob_start();
		try {
			require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
			$html = (string) ob_get_clean();
		} finally {
			$_GET = $previousGet;
		}

		self::assertStringContainsString( 'notice notice-warning inline', $html );
		self::assertStringNotContainsString( 'data-ran-booster-package-success', $html );
		self::assertStringContainsString( 'Readiness check complete.', $html );
	}
}
