<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\WebhookCleanupContext;
use RAN\Deployment\DeploymentPolicy;

final class PackageBranchReadinessViewTest extends TestCase {

	#[DataProvider( 'source_settings_mode_provider' )]
	public function test_source_settings_only_render_branch_readiness_for_saved_packages( string $package_source_mode, bool $expects_readiness ): void {
		$package_mutation_available = true;
		$package_source_choices     = array(
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
		$package_field_form         = 'edit' === $package_source_mode ? 'ran-booster-package-edit-form' : '';
		$package_field_layout       = 'grid';
		$package_source_view        = 'branch';
		$show_branch_settings       = true;
		$release_managed            = false;
		$branch_read_only           = false;
		$branch_value               = 'main';
		$subdirectory_value         = '';
		$package_advanced_sections  = array();
		$package_advanced_summary   = 'Branch · provider default';
		$package_advanced_open      = false;
		$package_repository_ready   = true;
		$package_source             = array();
		$package_view               = new class() {
			public function get_type(): string {
				return 'plugin';
			}
		};

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
			self::assertStringContainsString( '>Save settings and check</button>', $html );
		} else {
			self::assertStringNotContainsString( 'id="ran-booster-branch-readiness"', $html );
			self::assertStringNotContainsString( '>Save settings and check</button>', $html );
		}
	}

	/** @return array<string, array{string, bool}> */
	public static function source_settings_mode_provider(): array {
		return array(
			'new package'   => array( 'create', false ),
			'saved package' => array( 'edit', true ),
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
		self::assertStringContainsString( 'name="ran_booster[check_repository_branch_after_save]"', $html );
		self::assertStringContainsString( '>Manage webhooks</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=gh&amp;panel=repositories&amp;repository=repo-42&amp;repository_view=branch"', $html );
		self::assertStringNotContainsString( 'repository=repo-42#', $html );
		$check_position  = strpos( $html, '>Save settings and check</button>' );
		$manage_position = strrpos( $html, '>Manage webhooks</a>' );
		self::assertIsInt( $check_position );
		self::assertIsInt( $manage_position );
		self::assertTrue( $check_position < $manage_position );
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

		self::assertStringContainsString( '<button type="button" class="button" disabled aria-disabled="true">Manage webhooks</button>', $html );
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

		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only handler promotes render warnings to exceptions.
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.Security.EscapeOutput.ExceptionNotEscaped

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

		self::assertStringNotContainsString( 'name="ran_booster[check_repository_branch_after_save]"', $html );
		self::assertStringContainsString( '<button type="button" class="button" disabled aria-disabled="true">Manage webhooks</button>', $html );
	}

	public function test_release_managed_branch_pane_retains_cleanup_without_branch_readiness_controls(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$package_mutation_available = true;
		$package_source_choices     = array(
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
		self::assertStringContainsString( '>Save settings and check</button>', $html );
	}

	#[DataProvider( 'subdirectory_checklist_provider' )]
	public function test_subdirectory_has_its_own_readiness_checklist_row( ?string $outcome, string $class, string $message ): void {
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

		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item ' . $class . '">\s*<span[^>]*><\/span>\s*<strong>Repository subdirectory<\/strong>/s', $html );
		self::assertStringContainsString( $message, $html );
	}

	/** @return array<string, array{string|null, string, string}> */
	public static function subdirectory_checklist_provider(): array {
		return array(
			'not checked'       => array( null, 'is-pending', 'The subdirectory <code>packages/example</code> will be checked when Booster prepares the deployment archive.' ),
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
		self::assertStringContainsString( 'Local webhook requirements need attention.', $html );
		self::assertStringContainsString( 'panel=repositories&amp;repository=repo-42&amp;repository_view=branch', $html );
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
		self::assertStringContainsString( '<button type="button" class="button" disabled aria-disabled="true">Manage webhooks</button>', $html );
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
		self::assertMatchesRegularExpression( '/<button type="button" class="button" disabled aria-disabled="true">Manage webhooks<\\/button>/', $html );
	}

	#[DataProvider( 'repository_branch_check_outcome_provider' )]
	public function test_saved_repository_state_reflects_the_explicit_remote_check( string $outcome, string $class, string $message ): void {
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

		self::assertMatchesRegularExpression( '/<li class="ran-booster-readiness-item ' . $class . '">\s*<span[^>]*><\/span>\s*<strong>Saved repository<\/strong>/s', $html );
		self::assertStringContainsString( $message, $html );
	}

	/** @return array<string, array{string, string, string}> */
	public static function repository_branch_check_outcome_provider(): array {
		return array(
			'verified'             => array( 'verified', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
			'unable to check'      => array( 'unable_to_check', 'is-warning', 'The branch <code>test</code> is saved, but access could not be verified.' ),
			'provider unavailable' => array( 'provider_unavailable', 'is-warning', 'The branch <code>test</code> is saved, but the provider is unavailable.' ),
			'subdirectory missing' => array( 'subdirectory_unavailable', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
			'subdirectory unknown' => array( 'subdirectory_unverified', 'is-ok', 'The branch <code>test</code> is accessible with the saved repository settings.' ),
		);
	}

	#[DataProvider( 'blocked_receiver_reason_provider' )]
	public function test_blocked_receiver_reasons_provide_bounded_diagnostics_guidance(
		string $reason_code,
		string $expected_message
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
		self::assertStringContainsString( '<button type="button" class="button" disabled aria-disabled="true">Manage webhooks</button>', $html );
		self::assertStringNotContainsString( '<a href=', $html );
		self::assertStringNotContainsString( 'Review Booster diagnostics', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
	}

	/** @return array<string, array{string, string}> */
	public static function blocked_receiver_reason_provider(): array {
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

	public function test_automatic_mode_shows_awarning_when_local_readiness_is_incomplete(): void {
		$provider_code              = 'gh';
		$settings_url               = 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php';
		$provider_webhook_available = true;
		$branch_value               = 'main';
		$deployment_policy          = DeploymentPolicy::AUTOMATIC->value;
		$is_package_edit            = true;
		$package_branch_readiness   = null;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/branch-readiness.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'ran-booster-badge--error', $html );
		self::assertSame( 1, substr_count( $html, '<h4 id="ran-booster-branch-readiness-heading">Branch readiness</h4>' ) );
		self::assertStringContainsString( 'Local webhook requirements need attention.', $html );
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
		self::assertMatchesRegularExpression( '/hx-push-url="[^"]*source_view=branch[^"]*#ran-booster-branch-readiness"/', $html );
		self::assertDoesNotMatchRegularExpression( '/hx-push-url="[^"]*ran_booster_repository_branch_check/', $html );
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
