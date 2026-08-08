<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttempt;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminActionNormalizer.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/ProviderRepositoryRowsNormalizer.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}

final class TroubleshootingViewTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_view_filters'] = array();
		$GLOBALS['ran_booster_admin_view_actions'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_admin_view_filters'],
			$GLOBALS['ran_booster_admin_view_actions']
		);
	}

	public function testLoggingFollowsDeploymentActivityInAccessibleSecondaryNavigation(): void {
		$troubleshootingPanel = 'debug-capture';
		$troubleshooting      = array();
		$debugCapture         = array(
			'state'    => 'inactive',
			'filename' => 'ran-booster-debug.php',
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/troubleshooting.php';
		$html = (string) ob_get_clean();

		$diagnosticsPosition = strpos( $html, '>Diagnostics</a>' );
		$activityPosition    = strpos( $html, '>Activity</a>' );
		$loggingPosition     = strpos( $html, '>Logging</a>' );

		self::assertIsInt( $diagnosticsPosition );
		self::assertIsInt( $activityPosition );
		self::assertIsInt( $loggingPosition );
		self::assertLessThan( $activityPosition, $diagnosticsPosition );
		self::assertLessThan( $loggingPosition, $activityPosition );
		self::assertStringContainsString( 'aria-label="Troubleshooting views"', $html );
		self::assertStringContainsString( 'panel=debug-capture" aria-current="page"', $html );
		self::assertStringContainsString( '<h3 id="ran-booster-debug-capture-heading">Logging</h3>', $html );
		self::assertStringContainsString( 'Start 60-minute capture', $html );
		self::assertStringNotContainsString( '>Debug capture</a>', $html );
		self::assertStringNotContainsString( 'name="ran_booster[action]" value="run-troubleshooting"', $html );
	}

	public function testCoreUpdateStatusKeepsFriendlyCopySeparateFromTechnicalDetails(): void {
		$troubleshooting = array(
			'providers'              => array( 'gh' => 'GitHub' ),
			'provider_locator_hints' => array(),
			'credentials'            => array(),
			'selected_provider'      => 'gh',
			'credential_id'          => '',
			'repository'             => '',
			'ran'                    => false,
			'results'                => array(),
			'partial'                => false,
			'partial_reason'         => null,
			'report'                 => '',
			'core_self_update'       => array(
				'requested_mode'   => 'auto',
				'effective_mode'   => 'disabled',
				'reason'           => 'source_checkout',
				'marker_version'   => null,
				'marker_commit'    => null,
				'updater_state'    => 'inactive',
				'updater_code'     => 'native_discovery_disabled',
				'selected_version' => '1.5.0-beta.9',
				'offered_version'  => null,
				'last_check'       => null,
				'next_check'       => null,
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/troubleshooting.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<strong>Core updates</strong>', $html );
		self::assertStringContainsString(
			'Core updates are off for this source installation. Booster will not check for or replace itself.',
			$html
		);
		self::assertStringContainsString( '<summary>Technical details</summary>', $html );
		self::assertStringContainsString( '<code>native_discovery_disabled</code>', $html );
		self::assertStringNotContainsString( 'cannot reach its configured GitHub release feed', $html );
	}

	public function testOfficialFeedFailureUsesNontechnicalTroubleshootingCopy(): void {
		$troubleshooting = array(
			'providers'              => array( 'gh' => 'GitHub' ),
			'provider_locator_hints' => array(),
			'credentials'            => array(),
			'selected_provider'      => 'gh',
			'credential_id'          => '',
			'repository'             => '',
			'ran'                    => false,
			'results'                => array(),
			'partial'                => false,
			'partial_reason'         => null,
			'report'                 => '',
			'core_self_update'       => array(
				'requested_mode'   => 'auto',
				'effective_mode'   => 'enabled',
				'reason'           => 'verified_release',
				'marker_version'   => '1.2.3',
				'marker_commit'    => str_repeat( 'a', 40 ),
				'updater_state'    => 'unavailable',
				'updater_code'     => 'github_updater_github_http_error',
				'selected_version' => '1.5.0-beta.9',
				'offered_version'  => null,
				'last_check'       => 1_700_000_000,
				'next_check'       => 1_700_000_900,
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/troubleshooting.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString(
			'RAN Booster could not check for Core updates. The installed version will keep working, and Booster will retry automatically.',
			$html
		);
		self::assertStringContainsString( '<code>github_updater_github_http_error</code>', $html );
		self::assertStringNotContainsString( 'install a verified release ZIP manually', $html );
	}

	public function testDeploymentActivityShowsTheRecordedFailureReason(): void {
		$attempt             = DeploymentAttempt::fromDatabase(
			array(
				'id'                      => 1,
				'correlation_id'          => str_repeat( 'a', 32 ),
				'source'                  => 'manual',
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'package_slug'            => 'example',
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'release_identity'        => null,
				'provider'                => 'gh',
				'provider_repository_id'  => 'repository-1',
				'requested_ref'           => 'main',
				'resolved_ref'            => null,
				'delivery_id'             => null,
				'delivery_digest'         => null,
				'state'                   => 'failed',
				'mutation_started_at'     => null,
				'outcome_code'            => 'provider_rate_limited',
				'request_json'            => '{"repository":"org/example","credential_id":null,"private":false,"configured_branch":"main","package_slug":"example","subdirectory":null,"deployment_policy":"manual","initiating_user_id":1}',
				'created_at'              => '2026-07-23 06:00:00',
				'finished_at'             => '2026-07-23 06:00:01',
			)
		);
		$deploymentActivity  = array(
			'items'                 => array( $attempt ),
			'unavailable'           => false,
			'has_cursor'            => false,
			'next_cursor'           => null,
			'package_settings_urls' => array(
				'plugin' => array(
					'example' => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
				),
			),
		);
		$troubleshootingBase = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/attempts/index.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<span role="columnheader">Project</span>', $html );
		self::assertStringContainsString( '<span role="columnheader">Source</span>', $html );
		self::assertStringContainsString( '<span role="columnheader">Activity</span>', $html );
		self::assertStringContainsString( '<span role="columnheader">Outcome</span>', $html );
		self::assertStringNotContainsString( '<span role="columnheader">Details</span>', $html );
		self::assertStringContainsString( 'data-label="Project"', $html );
		self::assertStringContainsString( 'data-label="Source"', $html );
		self::assertStringContainsString( 'data-label="Activity"', $html );
		self::assertStringContainsString( 'data-label="Outcome"', $html );
		self::assertStringContainsString( 'Review recent branch deployments.', $html );
		self::assertStringNotContainsString( 'Published release', $html );
		self::assertStringContainsString( '<dl class="ran-booster-activity__details">', $html );
		self::assertStringContainsString( '<dt>Failure reason</dt><dd>The repository provider rate limit was reached.', $html );
		self::assertStringContainsString( 'Wait for its quota to reset', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins&amp;package=plugin%2Fexample.php', $html );
		self::assertStringContainsString( 'Open plugin settings', $html );
	}

	public function testNeedsAttentionDetailShowsOriginAndProtectedResolutionConfirmation(): void {
		$attempt             = DeploymentAttempt::fromDatabase(
			array(
				'id'                      => 7,
				'correlation_id'          => str_repeat( 'b', 32 ),
				'source'                  => 'webhook',
				'operation'               => 'update',
				'package_type'            => 'theme',
				'package_slug'            => 'example-theme',
				'package_source'          => 'branch',
				'package_source_revision' => 2,
				'provider'                => 'gh',
				'provider_repository_id'  => 'repository-7',
				'requested_ref'           => 'main',
				'resolved_ref'            => null,
				'delivery_id'             => 'delivery-7',
				'delivery_digest'         => str_repeat( 'c', 64 ),
				'state'                   => 'needs_attention',
				'mutation_started_at'     => '2026-07-23 06:00:00',
				'outcome_code'            => 'interrupted',
				'request_json'            => '{"repository":"org/example-theme","credential_id":null,"private":false,"configured_branch":"main","package_slug":"example-theme","subdirectory":null,"deployment_policy":"automatic","initiating_user_id":null}',
				'created_at'              => '2026-07-23 06:00:00',
				'finished_at'             => '2026-07-23 06:00:01',
				'resolved_at'             => null,
				'resolved_by'             => null,
			)
		);
		$deploymentActivity  = array(
			'detail'                    => $attempt,
			'unavailable'               => false,
			'package_settings_urls'     => array(
				'theme' => array(
					'example-theme' => 'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=example-theme',
				),
			),
			'rejected_admission_events' => array(
				array(
					'attempt_id'  => 7,
					'actor_id'    => 3,
					'occurred_at' => '2026-07-27 15:30:00',
				),
			),
		);
		$troubleshootingBase = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/attempts/detail.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<dt>Origin</dt><dd>Repository webhook</dd>', $html );
		self::assertStringContainsString( '<dt>Provider request ID</dt><dd><code>delivery-7</code></dd>', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="ran-booster-resolve-needs-attention"', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="resolve-needs-attention"', $html );
		self::assertStringContainsString( 'name="ran_booster[attempt_id]" value="7"', $html );
		self::assertStringContainsString( 'name="ran_booster[correlation_id]" value="' . str_repeat( 'b', 32 ) . '"', $html );
		self::assertStringContainsString( 'name="ran_booster[confirm_reviewed]" value="1" required', $html );
		self::assertStringContainsString( 'Acknowledge historical uncertainty', $html );
		self::assertStringNotContainsString( 'Blocked retry requests', $html );
		self::assertStringContainsString( 'Back to Activity</a>', $html );
		self::assertStringNotContainsString( 'class="button" href="' . $troubleshootingBase . '&amp;panel=activity"', $html );
		self::assertStringContainsString( 'panel=activity', $html );
		self::assertStringContainsString( 'page=ran-booster-themes&amp;package=example-theme', $html );
		self::assertStringContainsString( 'Open theme settings', $html );
		self::assertStringNotContainsString( 'class="button button-primary" href="https://example.test/wp-admin/admin.php?page=ran-booster-themes', $html );
	}

	public function testEmptyDeploymentHistoryUsesTheFirstUseMessage(): void {
		$deploymentActivity = array(
			'items'       => array(),
			'unavailable' => false,
			'has_cursor'  => false,
			'next_cursor' => null,
		);

		$troubleshootingBase = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/attempts/index.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No activity has been recorded yet.', $html );
		self::assertStringNotContainsString( 'No older activity is available.', $html );
	}

	public function testHistoricalRestorationUncertaintyExplainsLaterVerifiedRecovery(): void {
		$uncertain           = DeploymentAttempt::fromDatabase(
			array(
				'id'                      => 7,
				'correlation_id'          => str_repeat( 'b', 32 ),
				'source'                  => 'manual',
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'package_slug'            => 'example',
				'package_source'          => 'branch',
				'package_source_revision' => 2,
				'provider'                => 'gh',
				'provider_repository_id'  => 'repository-7',
				'requested_ref'           => 'main',
				'resolved_ref'            => 'abc123',
				'delivery_id'             => null,
				'delivery_digest'         => null,
				'state'                   => 'needs_attention',
				'mutation_started_at'     => '2026-07-23 06:00:00',
				'outcome_code'            => 'restoration_uncertain',
				'request_json'            => '{"repository":"org/example","credential_id":null,"private":false,"configured_branch":"main","package_slug":"example","subdirectory":null,"deployment_policy":"manual","initiating_user_id":1}',
				'created_at'              => '2026-07-23 06:00:00',
				'finished_at'             => '2026-07-23 06:00:01',
				'resolved_at'             => null,
				'resolved_by'             => null,
			)
		);
		$laterSuccess        = DeploymentAttempt::fromDatabase(
			array(
				'id'                      => 8,
				'correlation_id'          => str_repeat( 'c', 32 ),
				'source'                  => 'manual',
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'package_slug'            => 'example',
				'package_source'          => 'branch',
				'package_source_revision' => 2,
				'provider'                => 'gh',
				'provider_repository_id'  => 'repository-7',
				'requested_ref'           => 'main',
				'resolved_ref'            => 'abc123',
				'delivery_id'             => null,
				'delivery_digest'         => null,
				'state'                   => 'succeeded',
				'mutation_started_at'     => '2026-07-23 06:05:00',
				'outcome_code'            => 'deployed',
				'request_json'            => '{"repository":"org/example","credential_id":null,"private":false,"configured_branch":"main","package_slug":"example","subdirectory":null,"deployment_policy":"manual","initiating_user_id":1}',
				'created_at'              => '2026-07-23 06:05:00',
				'finished_at'             => '2026-07-23 06:05:01',
			)
		);
		$deploymentActivity  = array(
			'detail'                 => $uncertain,
			'later_verified_attempt' => $laterSuccess,
			'unavailable'            => false,
		);
		$troubleshootingBase = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/attempts/detail.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Booster did not retain which final-state check could not be proved.', $html );
		self::assertStringContainsString( 'activity #8', $html );
		self::assertStringContainsString( 'Acknowledge historical uncertainty', $html );
		self::assertStringNotContainsString( 'Provider request ID', $html );
	}

	public function testActivityRendersBlockedRetriesAsTimestampOrderedFailures(): void {
		$attempt             = DeploymentAttempt::fromDatabase(
			array(
				'id'                      => 8,
				'correlation_id'          => str_repeat( 'a', 32 ),
				'source'                  => 'manual',
				'operation'               => 'update',
				'package_type'            => 'plugin',
				'package_slug'            => 'newer-deployment',
				'package_source'          => 'branch',
				'package_source_revision' => 1,
				'provider'                => 'gh',
				'provider_repository_id'  => 'repository-8',
				'requested_ref'           => 'main',
				'resolved_ref'            => null,
				'delivery_id'             => null,
				'delivery_digest'         => null,
				'state'                   => 'succeeded',
				'mutation_started_at'     => null,
				'outcome_code'            => 'deployed',
				'request_json'            => '{"repository":"org/newer-deployment","credential_id":null,"private":false,"configured_branch":"main","package_slug":"newer-deployment","subdirectory":null,"deployment_policy":"manual","initiating_user_id":1}',
				'created_at'              => '2026-07-27 15:31:00',
				'finished_at'             => '2026-07-27 15:31:01',
			)
		);
		$deploymentActivity  = array(
			'items'                     => array( $attempt ),
			'unavailable'               => false,
			'has_cursor'                => false,
			'next_cursor'               => null,
			'rejected_admission_events' => array(
				array(
					'attempt_id'     => 7,
					'correlation_id' => str_repeat( 'd', 32 ),
					'package_type'   => 'plugin',
					'package_slug'   => 'example',
					'actor_id'       => 3,
					'occurred_at'    => '2026-07-27 15:30:00',
				),
			),
			'package_settings_urls'     => array(
				'plugin' => array(
					'example' => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fexample.php',
				),
			),
		);
		$troubleshootingBase = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/attempts/index.php';
		$html = (string) ob_get_clean();

		$deploymentPosition = strpos( $html, 'newer-deployment' );
		$retryPosition      = strpos( $html, 'example' );

		self::assertIsInt( $deploymentPosition );
		self::assertIsInt( $retryPosition );
		self::assertLessThan( $retryPosition, $deploymentPosition );
		self::assertStringNotContainsString( 'Blocked retry requests', $html );
		self::assertStringNotContainsString( 'No activity has been recorded yet.', $html );
		self::assertStringContainsString( '>Reinstall</span>', $html );
		self::assertStringContainsString( '>Failed</span>', $html );
		self::assertStringContainsString( 'Branch deployment', $html );
		self::assertStringContainsString( 'This reinstall request was blocked because the linked prior deployment still needs review.', $html );
		self::assertStringContainsString( 'User #3', $html );
		self::assertStringContainsString( 'Review activity record', $html );
		self::assertStringContainsString( 'attempt=7', $html );
		self::assertStringContainsString( 'reference=' . str_repeat( 'd', 32 ), $html );
		self::assertStringContainsString( 'Open plugin settings', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins&amp;package=plugin%2Fexample.php', $html );
	}

	public function testExhaustedDeploymentHistoryOffersTheLatestPage(): void {
		$deploymentActivity = array(
			'items'       => array(),
			'unavailable' => false,
			'has_cursor'  => true,
			'next_cursor' => null,
		);

		$troubleshootingBase = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/attempts/index.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No older activity is available.', $html );
		self::assertStringContainsString( '>View latest activity</a>', $html );
		self::assertStringContainsString( 'panel=activity', $html );
		self::assertStringNotContainsString( 'No activity has been recorded yet.', $html );
	}

	public function testRendersProtectedProviderFormAccessibleResultsAndWhitelistReport(): void {
		$troubleshooting = array(
			'providers'         => array(
				'gh' => 'GitHub',
				'bb' => 'Bitbucket',
			),
			'credentials'       => array(
				'gh' => array(
					array(
						'id'    => 'github-private',
						'label' => 'GitHub private access',
					),
				),
				'bb' => array(
					array(
						'id'    => 'bitbucket-private',
						'label' => 'Bitbucket private access',
					),
				),
			),
			'selected_provider' => 'bb',
			'credential_id'     => 'bitbucket-private',
			'repository'        => 'workspace/repository',
			'ran'               => true,
			'partial'           => true,
			'partial_reason'    => 'remote_calls_exhausted',
			'report'            => "RAN Booster troubleshooting report\n[pass] local.runtime.ready",
			'results'           => array(
				array(
					'status'      => 'pass',
					'code'        => 'local.runtime.ready',
					'message'     => 'The runtime is ready.',
					'remediation' => 'No action is required.',
				),
				array(
					'status'      => 'pass',
					'code'        => 'bb.credential.valid',
					'message'     => 'Bitbucket accepted the selected credential.',
					'remediation' => 'No action is needed.',
				),
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/troubleshooting.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="ran_booster[action]" value="run-troubleshooting"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="ran-booster-run-troubleshooting"', $html );
		self::assertStringContainsString( '>Run full diagnostics</button>', $html );
		self::assertStringContainsString( 'value="bb" selected="selected"', $html );
		self::assertStringContainsString( 'class="ran-booster-troubleshooting__credential"', $html );
		self::assertStringNotContainsString( 'type="text" name="ran_booster[credential_id]"', $html );
		self::assertStringContainsString( '>No saved credential (check public access)</option>', $html );
		self::assertStringContainsString( '>Bitbucket private access</option>', $html );
		self::assertStringContainsString( 'data-provider="gh" disabled="disabled" hidden', $html );
		self::assertStringContainsString( 'maxlength="512"', $html );
		self::assertStringContainsString( '>Check credential or repository access (optional)</summary>', $html );
		self::assertStringContainsString( '<details class="ran-booster-troubleshooting__specific" open>', $html );
		self::assertStringContainsString( 'Start with a provider to run a general health check.', $html );
		self::assertStringContainsString( 'check only credential and repository access; they do not scope Push-to-Deploy delivery results.', $html );
		self::assertStringContainsString( 'This is not a personal access token;', $html );
		self::assertStringContainsString( 'placeholder="owner/repository (GitHub) or workspace/repository (Bitbucket)"', $html );
		self::assertStringContainsString( 'class="ran-booster-data-table-wrap ran-booster-troubleshooting__table-wrap"', $html );
		self::assertStringContainsString( 'class="widefat ran-booster-data-table ran-booster-data-table--rows ran-booster-troubleshooting__table"', $html );
		self::assertStringContainsString( 'class="ran-booster-badge ran-booster-badge--ok ran-booster-diagnostic-status ran-booster-diagnostic-status--pass"', $html );
		self::assertStringContainsString( 'data-label="Status"', $html );
		self::assertStringContainsString( 'data-label="Action"', $html );
		self::assertStringContainsString( 'aria-live="polite"', $html );
		self::assertStringContainsString( '<tbody class="ran-booster-troubleshooting__local-results">', $html );
		self::assertStringContainsString( '<tbody class="ran-booster-troubleshooting__provider-results">', $html );
		self::assertLessThan(
			strpos( $html, '<tbody class="ran-booster-troubleshooting__provider-results">' ),
			strpos( $html, '<tbody class="ran-booster-troubleshooting__local-results">' )
		);
		self::assertStringContainsString( 'for="ran-booster-troubleshooting-provider"', $html );
		self::assertStringContainsString( 'for="ran-booster-troubleshooting-credential"', $html );
		self::assertStringContainsString( 'for="ran-booster-troubleshooting-repository"', $html );
		self::assertStringContainsString( '<caption class="screen-reader-text">', $html );
		self::assertStringContainsString( 'The five-request provider budget was reached.', $html );
		self::assertStringContainsString( '<code>local.runtime.ready</code>', $html );
		self::assertStringContainsString( '<textarea', $html );
		self::assertStringContainsString( 'RAN Booster troubleshooting report', $html );
	}

	public function testEscapesEverySubmittedAndResultField(): void {
		$canary          = '<img src=x onerror=alert(1)>';
		$troubleshooting = array(
			'providers'         => array( 'gh" onclick="alert(1)' => $canary ),
			'credentials'       => array(
				'gh" onclick="alert(1)' => array(
					array(
						'id'    => $canary,
						'label' => $canary,
					),
				),
			),
			'selected_provider' => 'gh" onclick="alert(1)',
			'credential_id'     => $canary,
			'repository'        => $canary,
			'ran'               => true,
			'partial'           => false,
			'partial_reason'    => null,
			'report'            => $canary,
			'results'           => array(
				array(
					'status'      => 'pass',
					'code'        => $canary,
					'message'     => $canary,
					'remediation' => $canary,
				),
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/troubleshooting.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( $canary, $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		self::assertStringContainsString( 'gh&quot; onclick=&quot;alert(1)', $html );
	}

	public function testProviderSettingsOmitsProfileIdFromRoutineDisplay(): void {
		$profileCanary       = '<img src=x onerror=alert(1)>';
		$provider            = array(
			'code'             => 'fixture',
			'label'            => 'Fixture',
			'credential_kinds' => array(
				array(
					'code'               => 'fixture',
					'label'              => 'Fixture credential',
					'secret_label'       => 'Secret',
					'secret_placeholder' => '',
					'fields'             => array(),
				),
			),
			'webhook_scopes'   => array(),
			'capabilities'     => array(
				'package'     => true,
				'browse'      => true,
				'credentials' => false,
				'webhooks'    => false,
			),
		);
		$credential_profiles = array(
			array(
				'id'            => $profileCanary,
				'label'         => 'Fixture profile',
				'kind'          => 'fixture',
				'configuration' => array(),
				'configured'    => true,
				'source'        => 'constant',
				'editable'      => false,
			),
		);
		$webhook_profiles    = array();
		$secrets_path        = 'Deployment configuration';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( $profileCanary, $html );
		self::assertStringNotContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
	}

	public function testProviderSettingsOmitsUnsupportedCredentialAndWebhookControls(): void {
		$provider            = $this->providerWithoutOptionalSettings();
		$credential_profiles = array();
		$webhook_profiles    = array();
		$secrets_path        = '/safe/path';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'ran-booster-provider__header', $html );
		self::assertStringNotContainsString( 'id="ran-booster-access-tokens-heading"', $html );
		self::assertStringNotContainsString( '>Add credential</button>', $html );
		self::assertStringNotContainsString( 'data-credential-modal="access"', $html );
		self::assertStringNotContainsString( 'id="ran-booster-webhook-secrets-heading"', $html );
		self::assertStringNotContainsString( '>Add webhook secret</button>', $html );
		self::assertStringNotContainsString( 'data-credential-modal="webhook"', $html );
		self::assertStringNotContainsString( 'ran-booster-secrets-location', $html );
	}

	public function testProviderSettingsRequiresBothWebhookCapabilityAndScopes(): void {
		foreach (
			array(
				'capability-only' => array( true, array() ),
				'scopes-only'     => array( false, $this->webhookScopes() ),
			) as $case
		) {
			$provider                             = $this->providerWithoutOptionalSettings();
			$provider['capabilities']['webhooks'] = $case[0];
			$provider['webhook_scopes']           = $case[1];
			$credential_profiles                  = array();
			$webhook_profiles                     = array();
			$secrets_path                         = '/safe/path';

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/provider.php';
			$html = (string) ob_get_clean();

			self::assertStringNotContainsString( 'id="ran-booster-webhook-secrets-heading"', $html );
			self::assertStringNotContainsString( 'data-credential-modal="webhook"', $html );
		}
	}

	public function testProviderSettingsKeepsBuiltInCredentialAndWebhookControls(): void {
		$provider                             = $this->providerWithoutOptionalSettings();
		$provider['code']                     = 'gh';
		$provider['credential_kinds']         = array(
			array(
				'code'               => 'token',
				'label'              => 'Token',
				'secret_label'       => 'Token',
				'secret_placeholder' => '',
				'fields'             => array(),
			),
		);
		$provider['webhook_scopes']           = $this->webhookScopes();
		$provider['capabilities']['webhooks'] = true;
		$provider['webhook_setup']            = array(
			'location'                   => 'Repository Settings → Webhooks → Add webhook',
			'event'                      => 'Push event',
			'documentation_url'          => 'https://provider.example/webhooks',
			'delivery_documentation_url' => 'https://provider.example/deliveries',
		);
		$provider['webhook_assistance']       = $this->webhookAssistance();
		$credential_profiles                  = array();
		$webhook_profiles                     = array();
		$managed_webhook_repositories         = array(
			'available'    => true,
			'owners'       => array( 'owner' ),
			'repositories' => array(
				array(
					'target'               => 'owner/example',
					'repository_id'        => 'repo-42',
					'package_count'        => 1,
					'automatic_count'      => 0,
					'package_references'   => array( 'plugin/example.php' ),
					'deployment_policies'  => array(
						'automatic' => 0,
						'manual'    => 0,
						'disabled'  => 1,
					),
					'repository_url'       => 'https://github.com/owner/example',
					'webhook_settings_url' => 'https://github.com/owner/example/settings/hooks',
				),
			),
		);
		$webhook_assistance_readiness         = array(
			'site'         => array(
				'status'       => 'ready',
				'reason_codes' => array(),
				'callback_url' => 'https://example.test/wp-json/ran-booster/v1/webhooks/gh',
			),
			'repositories' => array(
				array(
					'provider_code'         => 'gh',
					'repository_id'         => 'repo-42',
					'repository'            => 'owner/example',
					'label'                 => 'owner/example',
					'package_references'    => array( 'plugin/example.php' ),
					'deployment_policies'   => array(
						'automatic' => 0,
						'manual'    => 0,
						'disabled'  => 1,
					),
					'status'                => 'ready',
					'reason_codes'          => array(),
					'local_secret_coverage' => 'none',
					'eligible'              => true,
				),
			),
		);
		$secrets_path                         = '/absolute/path-secret-canary/secrets.json';
		$providerTask                         = 'repositories';
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_provider_repository_assistance_active'][] =
			static fn ( bool $active ): bool => true;
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_provider_repository_rows'][]              =
			static function ( array $rows ): array {
				foreach ( $rows as &$row ) {
					if ( isset( $row['actions']['core:assisted-hooks'] ) ) {
						$row['actions']['core:assisted-hooks']['url']      = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&assisted_repository=repo-42';
						$row['actions']['core:assisted-hooks']['disabled'] = false;
					}
					$row['details'][] = array(
						'label' => 'Assisted hook status',
						'value' => 'No assisted hook recorded',
						'tone'  => 'warning',
					);
					$row['details'][] = array(
						'label' => 'Recorded hook profile',
						'value' => 'Assisted hook not yet set',
					);
					$row['details'][] = array(
						'label' => 'Last checked',
						'value' => 'Never',
					);
				}
				unset( $row );

				return $rows;
			};

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'ran-booster-page-shell ran-booster-provider', $html );
		self::assertStringContainsString( 'Repository access', $html );
		self::assertStringContainsString( 'Saved credentials provide access to private repositories entered manually.', $html );
		self::assertStringContainsString( '>Add credential</button>', $html );
		self::assertStringContainsString( 'data-credential-modal="access"', $html );
		self::assertStringNotContainsString( 'ran-booster-credential-table--access', $html );
		self::assertStringContainsString( 'id="ran-booster-push-to-deploy-heading" class="ran-booster-section__title">Push-to-Deploy</h3>', $html );
		self::assertStringContainsString( 'Booster verifies the webhook signature, matches the repository and branch', $html );
		self::assertStringContainsString( 'Webhook signing · No secret saved', $html );
		self::assertSame( 2, substr_count( $html, 'ran-booster-status-summary--pending' ) );
		self::assertSame( 2, substr_count( $html, 'ran-booster-status-dot is-pending' ) );
		self::assertStringNotContainsString( 'ran-booster-status-summary--attention', $html );
		self::assertStringContainsString( 'panel=status', $html );
		self::assertStringContainsString( 'panel=repositories', $html );
		self::assertStringContainsString( 'panel=setup', $html );
		self::assertStringContainsString( 'owner/example', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-list" role="list"', $html );
		self::assertStringContainsString( 'data-ran-booster-provider-repository', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-record__summary"', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-record__identity"', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-record__overview"', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-record__actions"', $html );
		self::assertStringNotContainsString( 'ran-booster-webhook-repositories__setup-link', $html );
		self::assertStringContainsString( 'Plugin · Branch · 1 package', $html );
		self::assertStringContainsString( 'href="https://github.com/owner/example/settings/hooks"', $html );
		self::assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		self::assertStringContainsString( 'opens repository in a new tab', $html );
		self::assertStringNotContainsString( 'Push-to-Deploy URL configured', $html );
		self::assertStringNotContainsString( 'data-ran-booster-assistance-site-notice', $html );
		self::assertStringNotContainsString( '1 repositories', $html );
		self::assertStringNotContainsString( '0 of 1 packages Automatic', $html );
		self::assertStringNotContainsString( 'Repository identified', $html );
		self::assertStringContainsString( 'No secret', $html );
		self::assertStringContainsString( 'Push-to-Deploy disabled; pushes are ignored.', $html );
		self::assertStringContainsString( 'ran-booster-repository-record__management-detail--warning">No secret</span>', $html );
		self::assertStringNotContainsString( '>1 managed package</span>', $html );
		self::assertStringContainsString( 'data-webhook-target-options="owner"', $html );
		self::assertStringContainsString( 'data-webhook-target-options="repository"', $html );
		self::assertStringContainsString( '<option value="owner">owner</option>', $html );
		self::assertStringContainsString( '<option value="owner/example">owner/example</option>', $html );
		self::assertStringContainsString( 'tab=gh&amp;assisted_repository=repo-42', $html );
		self::assertStringContainsString( 'class="button" href=', $html );
		self::assertStringContainsString( 'Assisted Hooks', $html );
		self::assertStringContainsString( 'Assisted Hooks is active.', $html );
		self::assertStringNotContainsString( 'Assisted Hooks add-on not active.', $html );
		self::assertStringContainsString( 'Fixture webhooks', $html );
		self::assertStringContainsString( 'Plugin settings', $html );
		self::assertStringContainsString( 'source_view=branch#ran-booster-branch-readiness', html_entity_decode( $html ) );
		self::assertStringNotContainsString( '>Manage webhook', $html );
		self::assertStringContainsString( '<span class="screen-reader-text">: plugin/example.php</span>', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-record__details"', $html );
		self::assertStringContainsString( 'Assisted hook status', $html );
		self::assertStringContainsString( 'Recorded hook profile', $html );
		self::assertStringContainsString( 'Last checked', $html );
		self::assertLessThan( strpos( $html, 'Fixture webhooks' ), strpos( $html, 'Assisted Hooks' ) );
		self::assertLessThan( strpos( $html, 'Plugin settings' ), strpos( $html, 'Fixture webhooks' ) );
		self::assertStringNotContainsString( '>Set up in GitHub</a>', $html );
		self::assertStringContainsString( '>Add webhook secret</button>', $html );
		self::assertStringContainsString( 'data-credential-modal="webhook"', $html );
		self::assertStringNotContainsString( 'ran-booster-credential-table--webhook', $html );
		self::assertStringContainsString( 'data-webhook-secret-tools', $html );
		self::assertStringContainsString( 'data-webhook-secret-generate', $html );
		self::assertStringNotContainsString( $secrets_path, $html );
		self::assertStringContainsString( 'data-webhook-secret-copy', $html );
		self::assertStringContainsString( 'data-webhook-secret-visibility', $html );
		self::assertStringContainsString( 'minlength="32" maxlength="512"', $html );
		self::assertStringContainsString( 'aria-controls="ran-booster-webhook-secret"', $html );
		self::assertStringContainsString( 'A secure 64-character webhook secret was generated.', $html );
		self::assertStringContainsString( 'Copy the secret before saving.', $html );
		self::assertStringContainsString( 'saving it here does not create or verify the remote webhook', $html );

		$providerTask = 'setup';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$setupHtml    = (string) ob_get_clean();
		$providerTask = 'repositories';

		self::assertStringContainsString( 'Webhook signatures authorize deployment; they do not protect your host from traffic.', $setupHtml );
		self::assertStringContainsString( 'unique generated repository secret', $setupHtml );
		self::assertStringContainsString( 'Provider request ID in Booster Activity', $setupHtml );
		self::assertStringContainsString( 'tab=documentation#ran-booster-push-to-deploy', html_entity_decode( $setupHtml ) );

		$_GET['repository'] = 'repo-42';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$repositoryHtml = (string) ob_get_clean();
		unset( $_GET['repository'] );

		self::assertStringContainsString( '>Repository webhook</h4>', $repositoryHtml );
		self::assertStringContainsString( 'Back to managed repositories', $repositoryHtml );
		self::assertStringContainsString( 'source_view=branch#ran-booster-branch-readiness', html_entity_decode( $repositoryHtml ) );
		self::assertStringNotContainsString( 'data-ran-booster-provider-repository-filter', $repositoryHtml );
		self::assertSame( 1, substr_count( $repositoryHtml, 'data-ran-booster-provider-repository' ) );
		self::assertStringContainsString( 'panel=repositories&amp;repository=repo-42&amp;add_webhook_secret=1', $repositoryHtml );
		self::assertStringContainsString( '>Add repository secret</a>', $repositoryHtml );
		self::assertStringNotContainsString( "\n\t\t\tManage webhook", $repositoryHtml );

		$_GET['repository'] = 'stale-repository';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$staleRepositoryHtml = (string) ob_get_clean();
		unset( $_GET['repository'] );

		self::assertStringContainsString( 'That managed repository is no longer available.', $staleRepositoryHtml );
		self::assertSame( 0, substr_count( $staleRepositoryHtml, 'data-ran-booster-provider-repository' ) );

		$GLOBALS['ran_booster_admin_view_filters'] = array();
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$withoutAddOnHtml = (string) ob_get_clean();
			self::assertStringContainsString( 'Assisted Hooks', $withoutAddOnHtml );
			self::assertStringContainsString( 'disabled aria-disabled="true"', $withoutAddOnHtml );
			self::assertStringNotContainsString( 'assisted_repository=', $withoutAddOnHtml );
			self::assertStringNotContainsString( 'ran-booster-repository-record__details', $withoutAddOnHtml );
			self::assertStringContainsString( 'Assisted Hooks add-on not active.', $withoutAddOnHtml );
		self::assertStringContainsString( 'Fixture webhooks', $withoutAddOnHtml );
		self::assertStringContainsString( 'Plugin settings', $withoutAddOnHtml );
	}

	public function testReleaseManagedRepositoryIsVisibleButExcludedFromWebhookComposition(): void {
		$provider                             = $this->providerWithoutOptionalSettings();
		$provider['code']                     = 'gh';
		$provider['webhook_scopes']           = $this->webhookScopes();
		$provider['capabilities']['webhooks'] = true;
		$provider['webhook_assistance']       = $this->webhookAssistance();
		$credential_profiles                  = array();
		$webhook_profiles                     = array();
		$managed_webhook_repositories         = array(
			'available'    => true,
			'owners'       => array(),
			'repositories' => array(),
		);
		$provider_repositories                = array(
			'available'    => true,
			'owners'       => array( 'owner' ),
			'repositories' => array(
				array(
					'target'               => 'owner/release-theme',
					'repository_id'        => 'release-repository-42',
					'source'               => 'release_asset',
					'package_count'        => 1,
					'automatic_count'      => 0,
					'package_references'   => array( 'release-theme' ),
					'deployment_policies'  => array(
						'automatic' => 0,
						'manual'    => 1,
						'disabled'  => 0,
					),
					'repository_url'       => 'https://github.com/owner/release-theme',
					'webhook_settings_url' => null,
					'retained_webhook'     => array(
						'evidence_available'        => true,
						'local_secret_coverage'     => 'repository',
						'branch_package_references' => array(),
					),
				),
			),
		);
		$webhook_assistance_readiness         = array(
			'site'         => array(
				'status'       => 'ready',
				'reason_codes' => array(),
				'callback_url' => 'https://example.test/wp-json/ran-booster/v1/webhooks/gh',
			),
			'repositories' => array(),
		);
		$secrets_path                         = '/safe/path';
		$providerTask                         = 'repositories';
		$capturedProjections                  = null;
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_provider_repository_assistance_active'][] =
			static fn ( bool $active ): bool => true;
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_provider_repository_rows'][]              =
			static function ( array $rows, string $providerCode, array $projections ) use ( &$capturedProjections ): array {
				unset( $providerCode );
				$capturedProjections = $projections;

				return $rows;
			};

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertSame( array(), $capturedProjections );
		self::assertStringContainsString( 'ran-booster-repository-record--release', $html );
		self::assertStringContainsString( 'Theme · Published release · 1 package', $html );
		self::assertStringContainsString( 'Published release', $html );
		self::assertStringContainsString( 'ran-booster-repository-record__management-detail--info">Push-to-Deploy unavailable</span>', $html );
		self::assertStringContainsString( 'Local signing setup is retained for an easier return to Branch.', $html );
		self::assertSame( 1, substr_count( $html, 'disabled aria-disabled="true"' ) );
		self::assertStringContainsString( 'Review webhook cleanup', $html );
		self::assertStringContainsString( 'webhook_cleanup=1#ran-booster-webhook-cleanup', html_entity_decode( $html ) );
		self::assertStringContainsString( 'Theme settings', $html );
		self::assertStringContainsString( 'page=ran-booster-themes&amp;package=release-theme', $html );
		self::assertStringNotContainsString( 'source_view=branch', $html );
		self::assertStringNotContainsString( '#ran-booster-branch-readiness', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-record__details', $html );

		$provider_repositories['repositories'][0]['retained_webhook']['local_secret_coverage'] = 'none';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$withoutEvidence = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Review webhook cleanup', $withoutEvidence );
		self::assertStringNotContainsString( 'No matching local signing setup is saved.', $withoutEvidence );
		self::assertStringNotContainsString( 'Local signing setup could not be checked.', $withoutEvidence );
		self::assertStringNotContainsString( 'This package ignores pushes.', $withoutEvidence );
		self::assertStringContainsString( 'Pushes are ignored.', $withoutEvidence );
		self::assertStringContainsString( 'id="ran-booster-provider-readiness-reason-0-release-source"', $withoutEvidence );
		self::assertStringContainsString( 'Published release', $withoutEvidence );
		self::assertStringContainsString( 'Push-to-Deploy unavailable', $withoutEvidence );
		self::assertStringContainsString( 'Fixture webhooks', $withoutEvidence );
	}

	public function testProviderSettingsExplainsBlockedLocalAssistedHookSetup(): void {
		$provider                             = $this->providerWithoutOptionalSettings();
		$provider['code']                     = 'gh';
		$provider['webhook_scopes']           = $this->webhookScopes();
		$provider['capabilities']['webhooks'] = true;
		$provider['webhook_setup']            = array(
			'location'                   => 'Repository Settings → Webhooks → Add webhook',
			'event'                      => 'Push event',
			'documentation_url'          => 'https://provider.example/webhooks',
			'delivery_documentation_url' => 'https://provider.example/deliveries',
		);
		$provider['webhook_assistance']       = $this->webhookAssistance();
		$credential_profiles                  = array();
		$webhook_profiles                     = array();
		$managed_webhook_repositories         = array(
			'available'    => true,
			'repositories' => array(
				array(
					'target'               => 'owner/example',
					'repository_id'        => 'repo-42',
					'package_count'        => 1,
					'automatic_count'      => 1,
					'package_references'   => array( 'plugin/example.php' ),
					'deployment_policies'  => array(
						'automatic' => 1,
						'manual'    => 0,
						'disabled'  => 0,
					),
					'repository_url'       => 'https://github.com/owner/example',
					'webhook_settings_url' => 'https://github.com/owner/example/settings/hooks',
				),
			),
		);
		$webhook_assistance_readiness         = array(
			'site'         => array(
				'status'       => 'blocked',
				'reason_codes' => array( 'callback_requires_public_https' ),
				'callback_url' => 'http://localhost:10014/wp-json/ran-booster/v1/webhooks/gh',
			),
			'repositories' => array(
				array(
					'provider_code'         => 'gh',
					'repository_id'         => null,
					'repository'            => 'owner/example',
					'deployment_policies'   => array(
						'automatic' => 1,
						'manual'    => 0,
						'disabled'  => 0,
					),
					'reason_codes'          => array( 'repository_identity_conflict' ),
					'local_secret_coverage' => 'shared',
					'eligible'              => false,
				),
			),
		);
		$secrets_path                         = '/safe/path';
		$providerTask                         = 'repositories';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Push-to-Deploy needs attention', $html );
		self::assertStringContainsString( 'data-ran-booster-assistance-site-notice', $html );
		self::assertSame( 1, substr_count( $html, 'This site uses a local URL, so providers cannot deliver webhooks to it.' ) );
			self::assertStringContainsString( 'Push-to-Deploy needs attention', $html );
		self::assertStringContainsString( 'Owner secret', $html );
		self::assertStringContainsString( 'Repository identity conflict', $html );
		self::assertStringNotContainsString( 'Managed packages for this repository disagree about its provider identity.', $html );
		self::assertStringContainsString( 'disabled aria-disabled="true"', $html );
		self::assertStringContainsString( 'aria-describedby="ran-booster-provider-assistance-description ran-booster-provider-readiness-reason-0 ran-booster-provider-readiness-reason-0-site"', $html );
		self::assertStringNotContainsString( 'assisted_repository=repo-42', $html );
	}

	public function testProviderSettingsShowsOnlyPathlessRecoveryGuidanceWhenStorageIsUnavailable(): void {
		$provider                             = $this->providerWithoutOptionalSettings();
		$provider['credential_kinds']         = array(
			array(
				'code'               => 'token',
				'label'              => 'Token',
				'secret_label'       => 'Token',
				'secret_placeholder' => '',
				'fields'             => array(),
			),
		);
		$provider['webhook_scopes']           = $this->webhookScopes();
		$provider['capabilities']['webhooks'] = true;
		$credential_profiles                  = array();
		$webhook_profiles                     = array();
		$secrets_storage_unavailable          = true;
		$webhook_assistance_readiness         = array(
			'site'         => array(
				'status'       => 'blocked',
				'reason_codes' => array( 'secrets_storage_unavailable' ),
				'callback_url' => 'https://example.test/wp-json/ran-booster/v1/webhooks/gh',
			),
			'repositories' => array(),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-provider-storage-notice', $html );
		self::assertStringContainsString( 'data-ran-booster-assistance-site-notice', $html );
		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'Encrypted credential storage must be healthy before Push-to-Deploy', $html );
		self::assertStringContainsString( 'Restore the matching sidecar and site key from the same backup', $html );
		self::assertStringNotContainsString( '+ Add credential', $html );
		self::assertStringNotContainsString( '+ Add webhook secret', $html );
		self::assertStringNotContainsString( 'data-credential-modal=', $html );
		self::assertStringNotContainsString( '/absolute/path-secret-canary', $html );
	}

	public function testProviderSettingsShowsOnePublicLookupDropdownWithoutAProfileCheckbox(): void {
		$provider                     = $this->providerWithoutOptionalSettings();
		$provider['credential_kinds'] = array(
			array(
				'code'               => 'token',
				'label'              => 'Token',
				'secret_label'       => 'Token',
				'secret_placeholder' => '',
				'fields'             => array(),
			),
		);
		$credential_profiles          = array(
			array(
				'id'                    => 'public_lookup',
				'label'                 => 'Public lookup',
				'kind'                  => 'token',
				'configuration'         => array(),
				'configured'            => true,
				'source'                => 'file',
				'editable'              => true,
				'public_lookup_default' => true,
				'usage'                 => array(
					'available' => true,
					'total'     => 0,
					'packages'  => array(),
				),
			),
		);
		$public_lookup_profile        = array(
			'configured_id' => 'public_lookup',
			'stale'         => false,
		);
		$webhook_profiles             = array();
		$secrets_path                 = '/safe/path';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'id="ran-booster-public-lookup-profile"' ) );
		self::assertStringNotContainsString( 'ran-booster-credential-table--access', $html );
		self::assertStringContainsString( '<h3 id="ran-booster-public-lookup-heading" class="ran-booster-section__title">Public repository lookup</h3>', $html );
		self::assertStringContainsString( 'id="ran-booster-public-lookup-profile-region"', $html );
		self::assertStringContainsString( 'data-ran-booster-admin-mutation-region="public-lookup-profile"', $html );
		self::assertStringContainsString( 'data-ran-booster-enhanced-mutation', $html );
		self::assertStringContainsString( 'hx-target="#ran-booster-public-lookup-profile-region"', $html );
		self::assertStringContainsString( 'hx-swap="outerHTML transition:true show:none"', $html );
		self::assertStringContainsString( 'id="ran-booster-public-lookup-profile-error"', $html );
		self::assertStringContainsString( 'reduce provider rate-limit interruptions', $html );
		self::assertStringContainsString( 'Lookup credential', $html );
		self::assertStringContainsString( '>Anonymous</option>', $html );
		self::assertStringContainsString( 'Public lookup (public_lookup)', $html );
		self::assertStringContainsString( 'dedicated, expiring, least-privilege credential', $html );
		self::assertStringContainsString( 'Credential guidance', $html );
		self::assertStringContainsString( 'Deleting it returns Fixture public lookup to Anonymous', $html );
		self::assertStringContainsString( 'ran-booster-credential-self-destruct', $html );
		self::assertStringNotContainsString( 'ran-booster-provider-disclosure ran-booster-public-lookup-profile', $html );

		$explanationPosition = strpos( $html, 'Choose Anonymous, or use a dedicated saved credential' );
		$controlsPosition    = strpos( $html, 'ran-booster-public-lookup-profile__controls' );
		$guidancePosition    = strpos( $html, 'ran-booster-public-lookup-profile__guidance' );
		self::assertIsInt( $explanationPosition );
		self::assertIsInt( $controlsPosition );
		self::assertIsInt( $guidancePosition );
		self::assertLessThan( $controlsPosition, $explanationPosition );
		self::assertLessThan( $guidancePosition, $controlsPosition );
	}

	public function testProviderSettingsShowsAStalePublicLookupPreference(): void {
		$provider                     = $this->providerWithoutOptionalSettings();
		$provider['credential_kinds'] = array(
			array(
				'code'               => 'token',
				'label'              => 'Token',
				'secret_label'       => 'Token',
				'secret_placeholder' => '',
				'fields'             => array(),
			),
		);
		$credential_profiles          = array();
		$public_lookup_profile        = array(
			'configured_id' => 'missing_profile',
			'stale'         => true,
		);
		$webhook_profiles             = array();
		$secrets_path                 = '/safe/path';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Missing profile (missing_profile)', $html );
		self::assertStringContainsString( 'configured public lookup profile is missing', $html );
	}

	public function testPublicLookupFragmentAcceptsTheProviderSettingsPayloadDirectly(): void {
		$provider                 = $this->providerWithoutOptionalSettings();
		$credential_profiles      = array();
		$public_lookup_profile    = array(
			'configured_id' => '',
			'stale'         => false,
		);
		$publicLookupProfileError = null;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider-public-lookup-profile.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'id="ran-booster-public-lookup-profile-region"', $html );
		self::assertStringContainsString( '>Anonymous</option>', $html );
		self::assertStringContainsString( 'hidden', $html );
	}

	public function testProviderSettingsShowUsageLinksAndCredentialDeletionModal(): void {
		$provider = $this->providerWithoutOptionalSettings();

		$provider['capabilities']['credentials'] = true;

		$provider['credential_kinds'] = array(
			array(
				'code'               => 'token',
				'label'              => 'Token',
				'secret_label'       => 'Token',
				'secret_placeholder' => '',
				'fields'             => array(),
			),
		);
		$credential_profiles          = array(
			array(
				'id'                    => 'profile_one',
				'label'                 => 'Profile one',
				'kind'                  => 'token',
				'configuration'         => array(),
				'configured'            => true,
				'source'                => 'file',
				'editable'              => true,
				'public_lookup_default' => true,
				'usage'                 => array(
					'available' => true,
					'total'     => 2,
					'packages'  => array(
						array(
							'type'       => 'plugin',
							'identifier' => 'example/example.php',
							'installed'  => true,
							'edit_url'   => 'admin.php?page=ran-booster-plugins&package=example%2Fexample.php',
						),
						array(
							'type'       => 'theme',
							'identifier' => 'missing-theme',
							'installed'  => false,
							'edit_url'   => null,
						),
					),
				),
			),
		);
		$webhook_profiles             = array();
		$secrets_path                 = '/safe/path';
		$providerView                 = 'credentials';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>2 packages</td>', $html );
		self::assertStringContainsString( 'Back to Fixture overview', $html );
		self::assertStringContainsString( 'id="ran-booster-provider-profile-region"', $html );
		self::assertStringContainsString( '<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title" data-ran-booster-provider-profile-focus tabindex="-1">Credentials</h2>', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="validate-access-profile"', $html );
		self::assertStringContainsString( 'data-ran-booster-enhanced-mutation', $html );
		self::assertStringContainsString( 'hx-target="#ran-booster-credential-validation-error-profile_one"', $html );
		self::assertStringContainsString( 'id="ran-booster-credential-validation-error-profile_one"', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins&amp;package=example%2Fexample.php', $html );
		self::assertStringContainsString( 'Theme: missing-theme', $html );
		self::assertStringContainsString( '(not installed)', $html );
		self::assertStringContainsString( 'ran-booster-open-delete-credential-modal', $html );
		self::assertStringContainsString( 'data-usage-total="2"', $html );
		self::assertStringContainsString( 'data-usage-listed="2"', $html );
		self::assertStringContainsString( 'data-usage-template="ran-booster-delete-credential-usage-0"', $html );
		self::assertStringContainsString( 'data-public-lookup-default="1"', $html );
		self::assertStringContainsString( 'aria-controls="ran-booster-delete-access-modal"', $html );
		self::assertStringContainsString( 'data-credential-delete-modal', $html );
		self::assertStringContainsString( 'aria-describedby="ran-booster-delete-access-modal-description"', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="delete-access-profile"', $html );
		self::assertStringContainsString( 'data-ran-booster-interaction-operation="core:save-access-profile"', $html );
		self::assertStringContainsString( 'data-ran-booster-interaction-operation="core:delete-access-profile"', $html );
		self::assertSame( 2, substr_count( $html, 'hx-target="#ran-booster-provider-profile-region"' ) );
		self::assertSame( 2, substr_count( $html, 'hx-select="#ran-booster-provider-profile-region"' ) );
		self::assertStringContainsString( 'data-ran-booster-error-target="#ran-booster-access-profile-error"', $html );
		self::assertStringContainsString( 'data-ran-booster-error-target="#ran-booster-delete-access-profile-error"', $html );
		self::assertStringContainsString( 'core:save-access-profile', html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );
		self::assertStringContainsString( 'Yes, delete credential', $html );
		self::assertStringContainsString( 'Deleting it returns Fixture public lookup to Anonymous', $html );
		self::assertStringContainsString( 'Connected packages', $html );
		self::assertStringContainsString( 'ran-booster-delete-credential-package-list', $html );
		self::assertMatchesRegularExpression(
			'/<template id="ran-booster-delete-credential-usage-0">[\s\S]+<a class="ran-booster-pill ran-booster-pill--label ran-booster-pill--info ran-booster-delete-credential-package-pill" href="admin\.php\?page=ran-booster-plugins&amp;package=example%2Fexample\.php">Plugin: example\/example\.php<\/a>/',
			$html
		);
		self::assertMatchesRegularExpression(
			'/<template id="ran-booster-delete-credential-usage-0">[\s\S]+ran-booster-delete-credential-package-pill--unavailable[\s\S]+Theme: missing-theme[\s\S]+\(not installed\)/',
			$html
		);
		self::assertStringNotContainsString( 'Before deleting a credential', $html );
		self::assertStringNotContainsString( 'Return here and delete the credential after its usage reaches zero.', $html );
	}

	public function testProviderSettingsBlockDeletionWhenUsageCannotBeVerified(): void {
		$provider                     = $this->providerWithoutOptionalSettings();
		$provider['credential_kinds'] = array(
			array(
				'code'               => 'token',
				'label'              => 'Token',
				'secret_label'       => 'Token',
				'secret_placeholder' => '',
				'fields'             => array(),
			),
		);
		$credential_profiles          = array(
			array(
				'id'            => 'profile_one',
				'label'         => 'Profile one',
				'kind'          => 'token',
				'configuration' => array(),
				'configured'    => true,
				'source'        => 'file',
				'editable'      => true,
				'usage'         => array(
					'available' => false,
					'total'     => null,
					'packages'  => array(),
				),
			),
		);
		$webhook_profiles             = array();
		$secrets_path                 = '/safe/path';
		$providerView                 = 'credentials';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Usage unavailable', $html );
		self::assertStringContainsString( 'disabled="disabled"', $html );
	}

	public function testProviderTaskNavigationSelectsStatusSetupAndEmptyRepositories(): void {
		$provider                             = $this->providerWithoutOptionalSettings();
		$provider['code']                     = 'bb';
		$provider['label']                    = 'Bitbucket';
		$provider['webhook_scopes']           = $this->webhookScopes();
		$provider['capabilities']['webhooks'] = true;
		$provider['webhook_setup']            = array(
			'location'                   => 'Repository settings → Webhooks',
			'event'                      => 'Repository push',
			'documentation_url'          => 'https://provider.example/bitbucket-webhooks',
			'delivery_documentation_url' => 'https://provider.example/bitbucket-deliveries',
		);
		$credential_profiles                  = array();
		$webhook_profiles                     = array();
		$managed_webhook_repositories         = array(
			'available'    => true,
			'repositories' => array(),
		);
		$webhook_assistance_readiness         = array(
			'site'         => array(
				'status'       => 'ready',
				'reason_codes' => array(),
				'callback_url' => 'https://example.test/wp-json/ran-booster/v1/webhooks/bb',
			),
			'repositories' => array(),
		);

		$providerTask = 'status';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$statusHtml = (string) ob_get_clean();

		self::assertStringContainsString( 'id="ran-booster-provider-tasks"', $statusHtml );
		self::assertStringContainsString( 'hx-target="#ran-booster-provider-task-panel"', $statusHtml );
		self::assertStringContainsString( 'hx-select="#ran-booster-provider-task-panel"', $statusHtml );
		self::assertStringContainsString( 'hx-swap="outerHTML transition:true show:none"', $statusHtml );
		self::assertStringContainsString( 'hx-push-url="true"', $statusHtml );
		self::assertStringContainsString( 'hx-history="false"', $statusHtml );
		self::assertStringContainsString( 'hx-sync="this:replace"', $statusHtml );
		self::assertSame( 5, substr_count( $statusHtml, 'hx-get="admin.php?page=ran-booster&amp;tab=bb&amp;panel=' ) );
		self::assertSame( 4, substr_count( $statusHtml, 'data-ran-booster-provider-task="' ) );
		self::assertSame( 3, substr_count( $statusHtml, 'hx-boost="true"' ) );
		self::assertStringContainsString( 'data-ran-booster-provider-task-progress', $statusHtml );
		self::assertStringContainsString( 'data-ran-booster-provider-task-error', $statusHtml );
		self::assertMatchesRegularExpression(
			'/<nav class="ran-booster-provider-task-tabs"[\s\S]+data-ran-booster-provider-task-progress[\s\S]+<\/nav>/',
			$statusHtml
		);
		self::assertMatchesRegularExpression(
			'/panel=status"[^>]+data-ran-booster-provider-task="status"[^>]+aria-current="page">Status<\/a>/',
			$statusHtml
		);
		self::assertStringContainsString( '<h4 id="ran-booster-provider-status-heading" class="ran-booster-section__title">Readiness overview</h4>', $statusHtml );
		self::assertStringNotContainsString( 'id="ran-booster-webhook-instructions-heading"', $statusHtml );

		$providerTask = 'setup';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$setupHtml = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $setupHtml, 'hx-boost="true"' ) );
		self::assertMatchesRegularExpression(
			'/panel=setup"[^>]+data-ran-booster-provider-task="setup"[^>]+aria-current="page">Webhook setup<\/a>/',
			$setupHtml
		);
		self::assertStringContainsString( '<h4 id="ran-booster-webhook-instructions-heading" class="ran-booster-section__title">Webhook setup</h4>', $setupHtml );
		self::assertStringContainsString( 'Create the Bitbucket webhook', $setupHtml );
		self::assertStringContainsString( 'Repository push', $setupHtml );
		self::assertStringNotContainsString( 'GitHub', $setupHtml );

		$providerTask = 'repositories';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$repositoriesHtml = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $repositoriesHtml, 'hx-boost="true"' ) );
		self::assertMatchesRegularExpression(
			'/panel=repositories"[^>]+data-ran-booster-provider-task="repositories"[^>]+aria-current="page">Repositories<\/a>/',
			$repositoriesHtml
		);
		self::assertStringContainsString( '<h4 id="ran-booster-managed-webhook-repositories-heading" class="ran-booster-section__title">Managed repositories</h4>', $repositoriesHtml );
		self::assertStringContainsString( 'No managed Bitbucket repositories are available yet.', $repositoriesHtml );
		self::assertStringContainsString( 'page=ran-booster-plugins-create&amp;provider=bb', $repositoriesHtml );
		self::assertStringContainsString( '>Install a plugin</a>', $repositoriesHtml );
		self::assertStringContainsString( 'page=ran-booster-themes-create&amp;provider=bb', $repositoriesHtml );
		self::assertStringContainsString( '>Install a theme</a>', $repositoriesHtml );
		self::assertStringNotContainsString( 'Assisted Hooks', $repositoriesHtml );
		self::assertStringNotContainsString( 'GitHub', $repositoriesHtml );
	}

	public function testWebhookSecretManagementUsesProviderNeutralInventoryAndActions(): void {
		$provider                             = $this->providerWithoutOptionalSettings();
		$provider['code']                     = 'bb';
		$provider['label']                    = 'Bitbucket';
		$provider['webhook_scopes']           = $this->webhookScopes();
		$provider['capabilities']['webhooks'] = true;
		$credential_profiles                  = array();
		$webhook_profiles                     = array(
			array(
				'id'         => 'workspace-hook',
				'label'      => 'Workspace hook',
				'scope'      => 'owner',
				'target'     => 'workspace',
				'configured' => true,
				'editable'   => true,
				'usage'      => array(
					'available'    => true,
					'total'        => 2,
					'repositories' => array( 'workspace/one', 'workspace/two' ),
				),
			),
		);
		$providerView                         = 'secrets';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Back to Bitbucket overview', $html );
		self::assertStringContainsString( 'id="ran-booster-provider-profile-region"', $html );
		self::assertStringContainsString( '<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title" data-ran-booster-provider-profile-focus tabindex="-1">Webhook secrets</h2>', $html );
		self::assertStringContainsString( 'Manage local signing material used to verify Bitbucket webhook deliveries.', $html );
		self::assertStringContainsString( '>Add webhook secret</button>', $html );
		self::assertStringContainsString( 'ran-booster-credential-table--webhook', $html );
		self::assertStringContainsString( '<strong>Workspace hook</strong>', $html );
		self::assertStringContainsString( 'Owner · workspace', $html );
		self::assertStringContainsString( '>2 packages</td>', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="delete-webhook-profile"', $html );
		self::assertStringContainsString( 'data-ran-booster-interaction-operation="core:save-webhook-profile"', $html );
		self::assertStringContainsString( 'data-ran-booster-interaction-operation="core:delete-webhook-profile"', $html );
		self::assertSame( 2, substr_count( $html, 'hx-target="#ran-booster-provider-profile-region"' ) );
		self::assertSame( 2, substr_count( $html, 'hx-select="#ran-booster-provider-profile-region"' ) );
		self::assertStringContainsString( 'data-ran-booster-error-target="#ran-booster-webhook-profile-error"', $html );
		self::assertStringContainsString( 'data-ran-booster-error-target="#ran-booster-delete-webhook-profile-error"', $html );
		self::assertStringContainsString( 'id="ran-booster-delete-webhook-profile-error"', $html );
		self::assertStringContainsString( 'Remote provider webhooks will not be removed.', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
	}

	/** @return array<string, mixed> */
	private function providerWithoutOptionalSettings(): array {
		return array(
			'code'             => 'fixture',
			'label'            => 'Fixture',
			'credential_kinds' => array(),
			'webhook_scopes'   => array(),
			'capabilities'     => array(
				'package'     => true,
				'browse'      => false,
				'credentials' => false,
				'webhooks'    => false,
			),
		);
	}

	/** @return array<string, string> */
	private function webhookAssistance(): array {
		return array(
			'action_key'           => 'core:assisted-hooks',
			'action_label'         => 'Assisted Hooks',
			'inactive_heading'     => 'Assisted Hooks add-on not active.',
			'inactive_description' => 'Activating the compatible add-on adds repository-level provisioning here.',
			'active_heading'       => 'Assisted Hooks is active.',
			'active_description'   => 'Repository status and assisted configuration actions are available below.',
		);
	}

	/** @return list<array<string, mixed>> */
	private function webhookScopes(): array {
		return array(
			array(
				'code'               => 'owner',
				'label'              => 'Owner',
				'requires_target'    => true,
				'target_label'       => 'Owner',
				'target_placeholder' => 'organization-or-user',
				'description'        => '',
			),
		);
	}
}
