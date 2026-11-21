<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';
require_once __DIR__ . '/WebhookManagement/Display/WebhookDisplayModelWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\WebhookCleanupContext;
use RAN\Deployment\DeploymentPolicy;

final class PackageBranchReadinessViewTest extends TestCase {

	#[DataProvider( 'source_settings_mode_provider' )]
	public function test_source_settings_only_render_branch_readiness_for_saved_packages( string $package_source_mode, bool $expects_readiness, string $package_type ): void {
		$package_mutation_available = true;
		$package_source_choices     = array(
			'branch' => array(
				'heading'           => 'Branch',
				'description'       => 'Deploy a saved repository branch.',
				'url'               => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins',
				'disabled'          => false,
				'hydrated'          => true,
				'client_hydratable' => false,
			),
		);
		$package_field_form         = 'edit' === $package_source_mode ? 'ran-booster-package-edit-form' : '';
		$package_field_layout       = 'grid';
		$package_source_view        = 'branch';
		$show_branch_settings       = true;
		$release_managed            = false;
		$branch_read_only           = false;
		$branch_value               = 'main';
		$subdirectory_value         = '';
		$package_advanced_sections  = array();
		$package_advanced_summary   = 'Branch · repository default';
		$package_advanced_open      = false;
		$package_repository_ready   = true;
		$package_source             = array();
		[ $package_view, $identifier_value, $repository_branch_check_args ] = self::check_branch_bindings( $package_type );

		if ( $expects_readiness ) {
			$provider_code                   = 'gh';
			$settings_url                    = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
			$provider_webhook_available      = true;
			$deployment_policy               = DeploymentPolicy::MANUAL->value;
			$package_branch_readiness        = null;
			$repository_branch_check_outcome = null;
		}

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/source-settings.php';
		$html = (string) ob_get_clean();

		if ( $expects_readiness ) {
			self::assertStringContainsString( 'id="ran-booster-branch-readiness"', $html );
			self::assertStringContainsString( '>Check branch</button>', $html );
		} else {
			self::assertStringNotContainsString( 'id="ran-booster-branch-readiness"', $html );
			self::assertStringNotContainsString( '>Check branch</button>', $html );
		}
	}

	/** @return array<string, array{string, bool, string}> */
	public static function source_settings_mode_provider(): array {
		return array(
			'new plugin'   => array( 'create', false, 'plugin' ),
			'saved plugin' => array( 'edit', true, 'plugin' ),
			'new theme'    => array( 'create', false, 'theme' ),
			'saved theme'  => array( 'edit', true, 'theme' ),
		);
	}

	public function test_view_reports_bounded_local_evidence_without_claiming_remote_webhook_state(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::AUTOMATIC->value;
		$is_package_edit            = true;
		$package_branch_readiness   = array(
			'retained'             => false,
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
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		[ $package_view, $identifier_value, $repository_branch_check_args ] = self::check_branch_bindings();

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, '<h4 id="ran-booster-branch-readiness-heading">Branch readiness</h4>' ) );
		self::assertStringContainsString( 'aria-labelledby="ran-booster-branch-readiness-heading"', $html );
		self::assertStringContainsString( 'Repository subdirectory', $html );
		self::assertStringContainsString( 'Repository root (no subdirectory).', $html );
		self::assertStringContainsString( 'Webhook health', $html );
		self::assertStringContainsString( 'Local webhook requirements are ready.', $html );
		self::assertStringContainsString( 'Manage webhooks', $html );
		self::assertStringContainsString( 'panel=repositories&amp;repository=repo-42&amp;repository_view=branch', $html );
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
		self::assertStringContainsString( '>Manage webhooks</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=gh&amp;panel=repositories&amp;repository=repo-42&amp;repository_view=branch"', $html );
		self::assertStringNotContainsString( 'repository=repo-42#', $html );
		$check_position  = strpos( $html, '>Check branch</button>' );
		$manage_position = strrpos( $html, '>Manage webhooks</a>' );
		self::assertIsInt( $check_position );
		self::assertIsInt( $manage_position );
		self::assertTrue( $check_position < $manage_position );
		self::assertStringNotContainsString( 'form="ran-booster-package-edit-form"', $html );
		self::assertStringNotContainsString( 'hx-post=', $html );
		self::assertStringNotContainsString( 'hx-get=', $html );
		self::assertStringNotContainsString( 'data-ran-booster-enhanced-mutation', $html );
		self::assertStringContainsString( 'id="ran-booster-repository-branch-check-error"', $html );
		self::assertStringNotContainsString( 'data-ran-booster-error-target=', $html );
		self::assertStringNotContainsString( 'data-ran-booster-package-mutation', $html );
		self::assertStringNotContainsString( 'remote webhook is configured', strtolower( $html ) );
		self::assertStringNotContainsString( 'is-high-intent', $html );
	}

	public function test_missing_stable_repository_identity_does_not_provide_anavigable_webhook_route(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$is_package_edit            = true;
		$package_branch_readiness   = array(
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

		self::assertStringContainsString( '<button type="button" class="ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-outline" disabled aria-disabled="true">Manage webhooks</button>', $html );
		self::assertStringNotContainsString( 'href=', $html );
		self::assertStringNotContainsString( 'panel=repositories', $html );
	}

	public function test_missing_package_edit_context_defaults_to_non_editable_without_warnings(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$package_mutation_available = true;
		$package_branch_readiness   = array(
			'site'       => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository' => array(
				'reason_codes'          => array( 'repository_identity_unavailable' ),
				'local_secret_coverage' => 'unknown',
			),
		);
		$buffer_level               = ob_get_level();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only handler promotes render warnings to exceptions.
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);

		try {
			$html = ( static function () use ( $provider_code, $settings_url, $provider_webhook_available, $branch_value, $deployment_policy, $package_mutation_available, $package_branch_readiness ): string {
				ob_start();
				require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';

				return (string) ob_get_clean();
			} )();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}

			restore_error_handler();
		}

		self::assertStringNotContainsString( '>Check branch</button>', $html );
		self::assertStringContainsString( '<button type="button" class="ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-outline" disabled aria-disabled="true">Manage webhooks</button>', $html );
	}

	public function test_release_managed_branch_pane_retains_cleanup_without_branch_readiness_controls(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$package_mutation_available = true;
		$package_source_choices     = array(
			'branch' => array(
				'heading'           => 'Branch',
				'description'       => 'Deploy a saved repository branch.',
				'url'               => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins',
				'disabled'          => false,
				'hydrated'          => true,
				'client_hydratable' => false,
			),
		);
		$package_source_mode        = 'edit';
		$package_field_layout       = 'grid';
		$package_source_view        = 'branch';
		$show_branch_settings       = true;
		$release_managed            = true;
		$branch_read_only           = true;
		$branch_value               = 'main';
		$subdirectory_value         = '';
		$package_advanced_sections  = array();
		$package_advanced_summary   = 'Published releases · Active';
		$package_advanced_open      = false;
		$package_repository_ready   = true;
		$package_source             = array();
		$package_view               = new class() {
			public function get_type(): string {
				return 'plugin';
			}
		};
		$package_webhook_cleanup    = array(
			'context' => new WebhookCleanupContext(
				'plugin',
				'example/example.php',
				'gh',
				'101',
				'example/example',
				'repository',
				true,
				true,
				array(),
				'https://example.test/webhooks',
				'https://example.test/secrets',
				'https://example.test/docs',
				'https://example.test/settings'
			),
			'actions' => array(),
		);
		$package_branch_readiness   = array(
			'retained'   => true,
			'site'       => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository' => array(
				'repository_id'         => '101',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'repository',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/source-settings.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Inactive Branch deployment settings', $html );
		self::assertStringContainsString( 'id="ran-booster-branch-readiness"', $html );
		self::assertStringContainsString( 'Pushes are ignored while Releases is active.', $html );
		self::assertSame( 1, substr_count( $html, '<h4 id="ran-booster-branch-readiness-heading">Branch readiness</h4>' ) );
		self::assertStringContainsString( 'A repository-specific signing secret is saved.', $html );
		self::assertStringContainsString( 'The site exposes a structurally valid HTTPS webhook endpoint.', $html );
		self::assertStringNotContainsString( '>Needs attention</span>', $html );
		self::assertStringNotContainsString( '>Check branch</button>', $html, 'A release-managed package gets no check values, so no Check branch.' );
	}

	#[DataProvider( 'subdirectory_checklist_provider' )]
	public function test_subdirectory_has_its_own_readiness_checklist_row( ?string $outcome, string $css_class, string $message ): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$saved_subdirectory_value   = 'packages/example';
		$package_mutation_available = true;
		$is_package_edit            = true;
		$package_branch_readiness   = array(
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
			$repository_branch_check_outcome = $outcome;
		}

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item ' . $css_class . '">\s*<span[^>]*><\/span>\s*<strong>Repository subdirectory<\/strong>/s', $html );
		self::assertStringContainsString( $message, $html );
	}

	/** @return array<string, array{string|null, string, string}> */
	public static function subdirectory_checklist_provider(): array {
		return array(
			'not checked'       => array( null, 'is-pending', 'The subdirectory <code>packages/example</code> will be checked when Booster prepares the deployment archive.' ),
			'deferred'          => array( 'subdirectory_deferred', 'is-pending', 'The subdirectory <code>packages/example</code> will be checked when Booster prepares the deployment archive.' ),
			'accessed'          => array( 'verified', 'is-ok', 'The subdirectory <code>packages/example</code> is accessible at this branch.' ),
			'not found'         => array( 'subdirectory_unavailable', 'is-warning', 'The subdirectory <code>packages/example</code> was not found at this branch.' ),
			'check unavailable' => array( 'subdirectory_unverified', 'is-warning', 'The subdirectory <code>packages/example</code> could not be checked.' ),
		);
	}

	public function test_site_readiness_does_not_mislabel_avalid_repository_identity(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$is_package_edit            = true;
		$package_branch_readiness   = array(
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
		self::assertStringContainsString( 'The branch <code>main</code> is saved. Access has not been checked.', $html );
		self::assertStringContainsString( '<strong>Webhook health</strong>', $html );
		self::assertStringContainsString( 'Local <a href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=gh&amp;panel=repositories&amp;repository=repo-42&amp;repository_view=branch">webhook requirements</a> need attention.', $html );
		self::assertStringContainsString( 'Manage webhooks', $html );
		self::assertStringNotContainsString( 'Review WordPress URLs', $html );
		self::assertStringNotContainsString( 'Manage signing secrets', $html );
		self::assertStringNotContainsString( 'The local webhook endpoint needs attention.', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
	}

	public function test_saved_branch_uses_local_identity_evidence_without_claiming_branch_readiness(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'test';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$is_package_edit            = true;
		$package_branch_readiness   = array(
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
		self::assertStringContainsString( 'The branch <code>test</code> is saved. Access has not been checked.', $html );
		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item is-pending">\s*<span[^>]*><\/span>\s*<strong>Saved repository<\/strong>/s', $html );
		self::assertStringNotContainsString( 'test is ready', $html );
		self::assertStringNotContainsString( 'ready for manual deployments', strtolower( $html ) );
	}

	public function test_published_releases_keeps_the_saved_repository_identity_green(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$is_package_edit            = true;
		$release_managed            = true;
		$package_current_source     = 'release_asset';
		$package_source_view        = 'branch';
		$provider_repository_id     = 'repo-42';
		$repository_value           = 'owner/example';
		$package_branch_readiness   = null;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item is-ok">\s*<span[^>]*><\\/span>\s*<strong>Saved repository<\\/strong>/s', $html );
		self::assertStringContainsString( 'The branch <code>main</code> is saved. Access has not been checked.', $html );
		self::assertStringContainsString( 'panel=repositories&amp;repository=repo-42&amp;repository_view=branch', $html );
		self::assertStringContainsString( 'Pushes are ignored while Releases is active.', $html );
		self::assertStringContainsString( '>Manage webhooks</a>', $html );
		self::assertStringNotContainsString( 'Manage webhooks</button>', $html );
	}

	public function test_branch_package_uses_its_persisted_identity_when_readiness_omits_it(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$provider_repository_id     = '1315521150';
		$repository_value           = 'owner/booster-fixture-plugin';
		$release_managed            = false;
		$is_package_edit            = true;
		$package_branch_readiness   = array(
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

		self::assertStringContainsString( 'panel=repositories&amp;repository=1315521150&amp;repository_view=branch', $html );
		self::assertStringContainsString( '>Manage webhooks</a>', $html );
	}

	public function test_branch_package_does_not_use_persisted_identity_when_readiness_reports_aconflict(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$provider_repository_id     = '1315521150';
		$repository_value           = 'owner/booster-fixture-plugin';
		$release_managed            = false;
		$is_package_edit            = true;
		$package_branch_readiness   = array(
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
		self::assertStringContainsString( '<button type="button" class="ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-outline" disabled aria-disabled="true">Manage webhooks</button>', $html );
	}

	public function test_branch_package_does_not_use_persisted_identity_when_repository_locator_is_invalid(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$provider_repository_id     = '1315521150';
		$repository_value           = 'owner/booster-fixture-plugin';
		$release_managed            = false;
		$package_branch_readiness   = array(
			'site'       => array(
				'status'       => 'ready',
				'reason_codes' => array(),
			),
			'repository' => array(
				'repository'            => 'owner/booster-fixture-plugin',
				'reason_codes'          => array( 'repository_locator_invalid' ),
				'local_secret_coverage' => 'unknown',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'panel=repositories', $html );
		self::assertMatchesRegularExpression( '/<button type="button" class="ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-outline" disabled aria-disabled="true">Manage webhooks<\\/button>/', $html );
	}

	#[DataProvider( 'repository_branch_check_outcome_provider' )]
	public function test_saved_repository_state_reflects_the_explicit_remote_check( string $outcome, string $css_class, string $message ): void {
		$provider_code                   = 'gh';
		$settings_url                    = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available      = true;
		$branch_value                    = 'test';
		$deployment_policy               = DeploymentPolicy::MANUAL->value;
		$is_package_edit                 = true;
		$repository_branch_check_outcome = $outcome;
		$package_branch_readiness        = array(
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

		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item ' . $css_class . '">\s*<span[^>]*><\/span>\s*<strong>Saved repository<\/strong>/s', $html );
		self::assertStringContainsString( $message, $html );
	}

	/** @return array<string, array{string, string, string}> */
	public static function repository_branch_check_outcome_provider(): array {
		return array(
			'verified'              => array( 'verified', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
			'unable to check'       => array( 'unable_to_check', 'is-warning', 'The branch <code>test</code> is saved, but access could not be verified.' ),
			'provider unavailable'  => array( 'provider_unavailable', 'is-warning', 'The branch <code>test</code> is saved, but the Git provider is unavailable.' ),
			'subdirectory missing'  => array( 'subdirectory_unavailable', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
			'subdirectory unknown'  => array( 'subdirectory_unverified', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
			'subdirectory deferred' => array( 'subdirectory_deferred', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
		);
	}

	#[DataProvider( 'blocked_receiver_reason_provider' )]
	public function test_blocked_receiver_reasons_provide_bounded_diagnostics_guidance(
		string $reason_code
	): void {
		$provider_code              = 'bb';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::MANUAL->value;
		$is_package_edit            = true;
		$package_branch_readiness   = array(
			'webhook_settings_url' => 'https://bitbucket.org/workspace/example/admin/webhooks',
			'site'                 => array(
				'status'       => 'blocked',
				'reason_codes' => array( $reason_code ),
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
		self::assertStringContainsString( '<button type="button" class="ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-outline" disabled aria-disabled="true">Manage webhooks</button>', $html );
		self::assertStringNotContainsString( '<a href=', $html );
		self::assertStringNotContainsString( 'Review Booster diagnostics', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
	}

	/**
	 * @param string|list<string> $reason_codes
	 * @param array<string, bool> $capabilities
	 */
	#[DataProvider( 'retained_receiver_reason_provider' )]
	public function test_retained_receiver_row_states_the_shared_reason_with_one_link_to_its_fix( string|array $reason_codes, string $pattern, array $capabilities = array(), bool $multisite = false ): void {
		$provider_code                                 = 'gh';
		$settings_url                                  = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available                    = true;
		$branch_value                                  = 'main';
		$deployment_policy                             = DeploymentPolicy::MANUAL->value;
		$is_package_edit                               = true;
		$package_branch_readiness                      = array(
			'retained'   => true,
			'site'       => array(
				'status'       => 'blocked',
				'reason_codes' => (array) $reason_codes,
			),
			'repository' => array(
				'repository_id'         => 'repo-42',
				'reason_codes'          => array(),
				'local_secret_coverage' => 'none',
			),
		);
		$GLOBALS['ran_booster_package_view_multisite'] = $multisite;
		$GLOBALS['ran_booster_webhook_display_test_multisite']    = $multisite;
		$GLOBALS['ran_booster_webhook_display_test_capabilities'] = $capabilities + array( 'manage_options' => true );
		$GLOBALS['ran_booster_webhook_display_test_blog_id']      = 7;

		ob_start();
		try {
			require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		} finally {
			$html = (string) ob_get_clean();
			unset( $GLOBALS['ran_booster_package_view_multisite'], $GLOBALS['ran_booster_webhook_display_test_multisite'], $GLOBALS['ran_booster_webhook_display_test_capabilities'], $GLOBALS['ran_booster_webhook_display_test_blog_id'] );
		}

		self::assertMatchesRegularExpression( $pattern, $html );
		self::assertStringNotContainsString( 'Manual deployments remain available', $html );
		self::assertStringNotContainsString( 'Review WordPress URLs', $html );
		self::assertStringNotContainsString( 'Review Booster diagnostics', $html );
		// The signing-secret link sits inside its sentence and leads to the repository's webhook setup, network-aware.
		$base = $multisite ? 'https://example.test/wp-admin/network/' : 'https://example.test/wp-admin/';
		self::assertStringContainsString( 'No matching local <a href="' . $base . 'admin.php?page=ran-booster&amp;tab=gh&amp;panel=repositories&amp;repository=repo-42&amp;repository_view=branch">signing secret</a> is saved.', $html );
		self::assertStringNotContainsString( 'Manage signing secrets', $html );
	}

	/** @return array<string, array{0:string|list<string>,1:string,2?:array<string,bool>,3?:bool}> */
	public static function retained_receiver_reason_provider(): array {
		return array(
			'public HTTPS, linked'                => array( 'callback_requires_public_https', '~This site needs a <a href="https://example\.test/wp-admin/options-general\.php">public HTTPS URL</a> to receive webhooks; manual updates still work\.~' ),
			'public HTTPS, no manage_options'     => array( 'callback_requires_public_https', '~This site needs a public HTTPS URL to receive webhooks; manual updates still work\.\s*</span>~', array( 'manage_options' => false ) ),
			'public HTTPS, multisite'             => array( 'callback_requires_public_https', '~This site needs a <a href="https://example\.test/wp-admin/network/site-info\.php\?id=7">public HTTPS URL</a> to receive~', array( 'manage_sites' => true ), true ),
			'database, no manual claim'           => array( 'database_unavailable', '~Webhook setup is paused while Booster&#039;s <a href="https://example\.test/wp-admin/admin\.php\?page=ran-booster&amp;tab=troubleshooting">database storage</a> needs attention\.\s*</span>~' ),
			'database, multisite'                 => array( 'database_unavailable', '~<a href="https://example\.test/wp-admin/network/admin\.php\?page=ran-booster&amp;tab=troubleshooting">database storage</a>~', array(), true ),
			'secure storage'                      => array( 'secrets_storage_unavailable', '~Webhook setup is paused while <a href="https://example\.test/wp-admin/admin\.php\?page=ran-booster-settings#ran-booster-storage">secure credential storage</a> is unavailable; public repositories still update manually\.~' ),
			'package inventory, no manual claim'  => array( 'managed_packages_unavailable', '~Webhook setup is paused because Booster couldn&#039;t read its <a href="https://example\.test/wp-admin/admin\.php\?page=ran-booster-plugins">managed packages</a>\.\s*</span>~' ),
			'address and database, database wins' => array( array( 'callback_requires_public_https', 'database_unavailable' ), '~<strong>Local receiver</strong>\\s*<span>\\s*Webhook setup is paused while Booster&#039;s <a href="[^"]*">database storage</a> needs attention\\.\\s*</span>~' ),
			'unconfirmed, unlinked'               => array( 'unrecognized_reason', '~<strong>Local receiver</strong>\s*<span>\s*Webhook setup is unavailable until Booster can confirm this managed repository\.\s*</span>~' ),
		);
	}

	/** @return array<string, array{string}> */
	public static function blocked_receiver_reason_provider(): array {
		return array(
			'database unavailable'         => array(
				'database_unavailable',
			),
			'secrets storage unavailable'  => array(
				'secrets_storage_unavailable',
			),
			'managed packages unavailable' => array(
				'managed_packages_unavailable',
			),
			'unknown reason'               => array(
				'unrecognized_reason',
			),
		);
	}

	public function test_automatic_mode_shows_awarning_when_local_readiness_is_incomplete(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::AUTOMATIC->value;
		$is_package_edit            = true;
		$package_branch_readiness   = null;

		$GLOBALS['ran_booster_package_view_translations']['ran-booster']['Needs attention'] = 'Attention & "review"';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		unset( $GLOBALS['ran_booster_package_view_translations'] );
		self::assertStringContainsString( '<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-high-intent" data-ran-booster-badge data-ran-booster-badge-intent="high">Attention &amp; &quot;review&quot;</span>', $html );
		self::assertSame( 1, substr_count( $html, '<h4 id="ran-booster-branch-readiness-heading">Branch readiness</h4>' ) );
		self::assertStringContainsString( 'Local webhook requirements need attention.', $html );
	}

	#[DataProvider( 'branch_notice_states' )]
	public function test_branch_notice_preserves_server_state_and_action( ?string $outcome, string $message, string $package_type ): void {
		$provider_code                   = 'gh';
		$settings_url                    = 'https://example.test/wp-admin/admin.php?page=ran-booster-' . $package_type . 's&package=example';
		$provider_webhook_available      = true;
		$branch_value                    = 'main';
		$deployment_policy               = DeploymentPolicy::MANUAL->value;
		$is_package_edit                 = true;
		$package_branch_readiness        = null;
		$repository_branch_check_outcome = $outcome;
		$package_mutation_available      = false; // A paused or unavailable source still offers Check branch.
		$package_return_args             = array( 'return_type' => $package_type );
		[ $package_view, $identifier_value, $repository_branch_check_args ] = self::check_branch_bindings( $package_type );
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		if ( in_array( $outcome, array( 'branch_unavailable', 'branch_redirected' ), true ) ) {
			self::assertStringNotContainsString( 'is accessible with the saved repository settings.', $html );
			self::assertStringNotContainsString( 'was accessible at the last check.', $html );
		}

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			self::assertTrue( $document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' ) );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$xpath  = new \DOMXPath( $document );
		$notice = $document->getElementById( 'ran-booster-repository-branch-check-error' );
		self::assertInstanceOf( \DOMElement::class, $notice );
		self::assertTrue( $notice->hasAttribute( 'data-ran-booster-ui-notice' ) );
		self::assertSame( '' !== $message ? 'warning' : 'error', $notice->getAttribute( 'data-ran-booster-ui-notice-intent' ) );
		self::assertSame( '' === $message, $notice->hasAttribute( 'hidden' ) );
		self::assertSame( '' !== $message, $notice->hasAttribute( 'data-ran-booster-repository-branch-check' ) );
		self::assertSame( 'alert', $notice->getAttribute( 'role' ) );
		self::assertSame( '-1', $notice->getAttribute( 'tabindex' ) );
		$intent = '' !== $message ? 'warning' : 'error';
		self::assertSame( 'notice notice-' . $intent . ' inline ran-wp-ui-notice ran-wp-ui-box-sizing is-' . $intent, $notice->getAttribute( 'class' ) );
		$primary = $xpath->query( './div[@class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description"][@data-ran-booster-ui-notice-content]/p[@data-ran-booster-ui-notice-primary]', $notice );
		$icon    = $xpath->query( './div[@data-ran-booster-ui-notice-content]/following-sibling::*[1][local-name()="svg"][@class="ran-wp-ui-notice__icon"][@aria-hidden="true"]', $notice );
		self::assertNotFalse( $icon );
		self::assertCount( 1, $icon );
		self::assertNotFalse( $primary );
		self::assertCount( 1, $primary );
		$paragraph = $primary->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $paragraph );
		self::assertSame( '<p data-ran-booster-ui-notice-primary>' . $message . '</p>', $document->saveHTML( $paragraph ) );
		// Check branch is its own GET form back to this page: it posts nothing to the save form.
		$forms = $xpath->query( '//form[@method="get"][@data-ran-booster-submit-busy][button[@type="submit"][normalize-space()="Check branch"]]' );
		self::assertNotFalse( $forms );
		self::assertCount( 1, $forms );
		$form = $forms->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $form );
		$button = $form->getElementsByTagName( 'button' )->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $button );
		self::assertSame( 'https://example.test/wp-admin/admin.php#ran-booster-branch-readiness', $form->getAttribute( 'action' ) );
		self::assertFalse( $button->hasAttribute( 'form' ) || $button->hasAttribute( 'name' ) || $button->hasAttribute( 'disabled' ) );
		self::assertStringContainsString( 'data-ran-booster-busy-label="Checking…"', $html );
		self::assertSame( 'Checking the branch. This can take a few seconds.', $button->getAttribute( 'data-ran-booster-busy-message' ) );
		$fields = array();
		$hidden = $xpath->query( './input[@type="hidden"]', $form );
		self::assertNotFalse( $hidden );
		foreach ( $hidden as $field ) {
			self::assertInstanceOf( \DOMElement::class, $field );
			$fields[ $field->getAttribute( 'name' ) ] = $field->getAttribute( 'value' );
		}
		self::assertSame(
			array(
				'page'                                 => 'ran-booster-' . $package_type . 's',
				'package'                              => $identifier_value,
				'source_view'                          => 'branch',
				'ran_booster_repository_branch_check'  => '0123456789abcdef',
				'_ran_booster_repository_branch_nonce' => 'check-nonce',
				'return_type'                          => $package_type,
			),
			$fields
		);
	}

	/** @return array{\RAN\Admin\PackagePagePresenter, string, array{ran_booster_repository_branch_check: string, _ran_booster_repository_branch_nonce: string}} */
	private static function check_branch_bindings( string $type = 'plugin' ): array {
		return array(
			'plugin' === $type ? \RAN\Admin\PackagePagePresenter::plugin() : \RAN\Admin\PackagePagePresenter::theme(),
			'plugin' === $type ? 'example/example.php' : 'example',
			array(
				'ran_booster_repository_branch_check'  => '0123456789abcdef',
				'_ran_booster_repository_branch_nonce' => 'check-nonce',
			),
		);
	}

	/** @return iterable<string, array{?string, string, string}> */
	public static function branch_notice_states(): iterable {
		$states = array(
			'branch_unavailable'       => 'The saved branch could not be found. Check the branch name on the provider.',
			'branch_redirected'        => 'The branch lookup was redirected. The branch may have been renamed or the repository moved. Check the current name on the provider.',
			'provider_unavailable'     => 'The saved Git provider is unavailable, so Booster could not check the repository and branch.',
			'unable_to_check'          => 'Booster could not access the saved repository and branch. Check the branch name and repository access, then try again.',
			'subdirectory_unavailable' => 'The saved branch is accessible, but Booster could not find the configured subdirectory. Check the path and try again.',
			'subdirectory_unverified'  => 'The saved branch is accessible, but Booster could not check the configured subdirectory. Try again later.',
			'verified'                 => '',
			'subdirectory_deferred'    => '',
			'unknown'                  => '',
		);
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( $states as $outcome => $message ) {
				yield $type . ' ' . $outcome => array( $outcome, $message, $type );
			}
			yield $type . ' null' => array( null, '', $type );
		}
	}

	public function test_verified_repository_branch_check_uses_only_the_green_repository_row(): void {
		$provider_code                   = 'gh';
		$settings_url                    = 'https://example.test/wp-admin/admin.php?page=ran-booster-themes&package=example-theme';
		$provider_webhook_available      = true;
		$branch_value                    = 'main';
		$deployment_policy               = DeploymentPolicy::MANUAL->value;
		$is_package_edit                 = true;
		$saved_subdirectory_value        = '';
		$package_branch_readiness        = array(
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
		$repository_branch_check_outcome = 'verified';
		[ $package_view, $identifier_value, $repository_branch_check_args ] = self::check_branch_bindings( 'theme' );

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'notice notice-success inline', $html );
		self::assertSame( 0, substr_count( $html, 'data-ran-booster-repository-branch-check' ) );
		self::assertStringContainsString( 'The branch <code>main</code> is accessible with the saved repository settings.', $html );
		self::assertStringContainsString( 'Repository root (no subdirectory).', $html );
		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item is-ok">\s*<span[^>]*><\/span>\s*<strong>Repository subdirectory<\/strong>/s', $html );
		self::assertStringNotContainsString( 'main is saved.', $html );
		self::assertStringNotContainsString( 'Local evidence refreshed.', $html );
		self::assertStringContainsString( '<input type="hidden" name="source_view" value="branch">', $html );
		self::assertStringNotContainsString( 'hx-push-url=', $html );
	}

	public function test_failed_repository_branch_check_shows_one_transient_warning_without_claiming_readiness(): void {
		$provider_code                   = 'gh';
		$settings_url                    = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available      = true;
		$branch_value                    = 'main';
		$deployment_policy               = DeploymentPolicy::AUTOMATIC->value;
		$is_package_edit                 = true;
		$package_branch_readiness        = null;
		$repository_branch_check_outcome = 'unable_to_check';
		[ $package_view, $identifier_value, $repository_branch_check_args ] = self::check_branch_bindings();

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning', $html );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-repository-branch-check' ) );
		self::assertSame( 1, substr_count( $html, 'id="ran-booster-repository-branch-check-error"' ) );
		self::assertMatchesRegularExpression(
			'/id="ran-booster-repository-branch-check-error"\s+class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning"\s+data-ran-booster-ui-notice\s+data-ran-booster-ui-notice-intent="warning"\s+role="alert"\s+tabindex="-1"\s+data-ran-booster-repository-branch-check\s*>\s*<div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>\s*<p data-ran-booster-ui-notice-primary>Booster could not access the saved repository and branch\. Check the branch name and repository access, then try again\.<\/p>\s*<\/div>\s*<svg class="ran-wp-ui-notice__icon"[^>]*aria-hidden="true"[^>]*>(?:<path [^>]*><\/path>)+<\/svg>\s*<\/div>/',
			$html
		);
		self::assertLessThan(
			strpos( $html, '>Check branch</button>' ),
			strpos( $html, 'id="ran-booster-repository-branch-check-error"' )
		);
		self::assertStringContainsString( 'Booster could not access the saved repository and branch. Check the branch name and repository access, then try again.', $html );
		self::assertStringNotContainsString( 'Local evidence refreshed.', $html );
		self::assertStringNotContainsString( 'were verified', $html );
	}
}
