<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class OnboardingViewTest extends TestCase {

	public function testLeadsWithPublicInstallationBeforeOptionalAccessAndAutomation(): void {
		$html = $this->renderView();

		self::assertStringContainsString(
			'<section class="ran-booster-page-shell ran-booster-panel ran-booster-onboarding"',
			$html
		);
		self::assertStringContainsString(
			'<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title">Start with a repository</h2>',
			$html
		);
		self::assertSame( 2, preg_match_all( '/<section class="ran-booster-onboarding__column"/', $html ) );

		$install = strpos( $html, 'Install a plugin' );
		$private = strpos( $html, 'Private repository access' );
		$move    = strpos( $html, 'Move or automate later' );

		self::assertIsInt( $install );
		self::assertIsInt( $private );
		self::assertIsInt( $move );
		self::assertLessThan( $private, $install );
		self::assertLessThan( $move, $private );
		self::assertStringContainsString( 'Public repositories do not need access tokens or webhooks.', $html );
		self::assertStringContainsString( 'Install and manage custom plugins and themes from supported Git repositories; private access and Push-to-Deploy are optional.', $html );
		self::assertStringContainsString( 'Packages begin in Manual mode.', $html );
	}

	public function testRendersProviderPackageAndHelpDestinations(): void {
		$html = $this->renderView();

		self::assertStringContainsString( '>Add GitHub access</a>', $html );
		self::assertStringContainsString( '>Add Bitbucket access</a>', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins-create', $html );
		self::assertStringContainsString( '>Install a plugin</a>', $html );
		self::assertStringContainsString( 'page=ran-booster-themes-create', $html );
		self::assertStringContainsString( '>Install a theme</a>', $html );
		self::assertStringContainsString( '>Move an existing Booster setup</a>', $html );
		self::assertStringContainsString( '>Read the documentation</a>', $html );
		self::assertStringContainsString( '>Open troubleshooting</a>', $html );
		self::assertStringContainsString(
			'page=ran-booster&amp;tab=documentation#ran-booster-credential-storage">Learn how Booster manages credentials and keys</a>',
			$this->renderStorageStatus( 'storage_healthy', 'automatic' )
		);
	}

	public function testEscapesProviderLabelsAndEveryUrl(): void {
		$html = $this->renderView(
			array(
				array(
					'label' => 'GitLab <script>alert(1)</script>',
					'url'   => 'https://example.test/?unsafe="value"',
				),
			)
		);

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'Add GitLab &lt;script&gt;alert(1)&lt;/script&gt; access', $html );
		self::assertStringContainsString( 'unsafe=&quot;value&quot;', $html );
	}

	public function testProvidesAProviderFallbackAndUsesThePluginTextDomain(): void {
		$html = $this->renderView( array() );

		self::assertStringContainsString( 'Provider settings will appear here when an integration is available.', $html );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture source is inspected without a WordPress runtime.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/views/onboarding.php' );

		self::assertStringContainsString(
			"esc_html_e( 'Start with a repository', 'ran-booster' )",
			$source
		);
		self::assertStringContainsString( "__( 'Add %s access', 'ran-booster' )", $source );
		self::assertStringNotContainsString( 'credential_profiles', $source );
		self::assertStringNotContainsString( 'webhook_profiles', $source );
	}

	public function testRendersProtectedOneClickSetupAndEscapedManualFallback(): void {
		$html = $this->renderView(
			null,
			array(
				'status'              => 'setup_available',
				'message'             => 'Booster can create secure encrypted secrets storage.',
				'candidate_path'      => '/private/<canary>/secrets.json',
				'path_source'         => 'automatic',
				'can_provision'       => true,
				'action_url'          => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
				'manual_preflight'    => 'Verify that existing components are not symbolic links.',
				'directory_commands'  => array(
					"mkdir -p -- '/private/<canary>'",
				),
				'config_alternatives' => array(
					'define' => "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '/private/<canary>/secrets.json' );",
					'wp_cli' => "wp --path='/wordpress' config set RAN_BOOSTER_ENCRYPTED_SECRETS_FILE '/private/<canary>/secrets.json' --type=constant",
				),
			)
		);

		self::assertStringContainsString( 'name="ran_booster[action]" value="create-secure-storage"', $html );
		self::assertStringContainsString( 'value="ran-booster-create-secure-storage"', $html );
		self::assertStringContainsString( 'Create secure storage', $html );
		self::assertStringContainsString( 'class="ran-booster-onboarding__storage-details" open', $html );
		self::assertStringContainsString( '/private/&lt;canary&gt;/secrets.json', $html );
		self::assertStringContainsString( 'Recommended location', $html );
		self::assertStringContainsString( 'Booster selected this private location automatically', $html );
		self::assertStringContainsString( 'Set up manually instead', $html );
		self::assertStringContainsString( 'absolute private directory outside the public web root on durable local storage', $html );
		self::assertStringContainsString( 'owned by PHP, readable and writable by PHP, and mode 0700', $html );
		self::assertStringNotContainsString( '<canary>', $html );
	}

	public function testConfiguredStorageRendersStatusPathAndSourceWithoutSetupControls(): void {
		$html = $this->renderView(
			null,
			array(
				'status'              => 'path_configured',
				'message'             => 'The private storage path is configured.',
				'candidate_path'      => '/private/canary/secrets.json',
				'path_source'         => 'automatic',
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => null,
				'directory_commands'  => array(),
				'config_alternatives' => null,
			)
		);

		self::assertStringContainsString( 'The private storage path is configured.', $html );
		self::assertStringContainsString( 'data-ran-booster-storage-status="path_configured"', $html );
		self::assertStringContainsString( '>Path configured</span>', $html );
		self::assertStringContainsString( 'Storage file', $html );
		self::assertStringContainsString( '/private/canary/secrets.json', $html );
		self::assertStringContainsString( 'Path selection', $html );
		self::assertStringContainsString( 'Booster default', $html );
		self::assertStringContainsString( 'created when you save the first credential.', $html );
		self::assertStringContainsString( 'class="ran-booster-onboarding__storage-details">', $html );
		self::assertStringNotContainsString( 'Create secure storage', $html );
		self::assertStringNotContainsString( 'Manual setup instructions', $html );
	}

	public function testHealthyAndBrokenStorageUseTruthfulStatuses(): void {
		$healthy = $this->renderStorageStatus(
			'storage_healthy',
			'manual',
			'Encrypted secrets storage is configured and authenticated.'
		);
		self::assertStringContainsString( '>Storage healthy</span>', $healthy );
		self::assertStringContainsString( 'Custom wp-config.php path', $healthy );
		self::assertStringContainsString( 'WordPress stores the encryption key separately.', $healthy );
		self::assertMatchesRegularExpression(
			'/<h3 id="ran-booster-onboarding-storage-heading">Secure credential storage<\/h3>.*>Storage healthy<\/span>\s*<\/div>\s*<p>Encrypted secrets storage is configured and authenticated\.<\/p>\s*<details class="ran-booster-onboarding__storage-details">/s',
			$healthy
		);
		$details = strpos( $healthy, 'class="ran-booster-onboarding__storage-details"' );
		$path    = strpos( $healthy, 'Storage file' );
		$source  = strpos( $healthy, 'Path selection' );
		$connect = strpos( $healthy, 'Connect a provider' );
		$move    = strpos( $healthy, 'Move or automate later' );
		$storage = strpos( $healthy, 'Secure credential storage' );
		self::assertIsInt( $details );
		self::assertIsInt( $path );
		self::assertIsInt( $source );
		self::assertIsInt( $connect );
		self::assertIsInt( $move );
		self::assertIsInt( $storage );
		self::assertLessThan( $path, $details );
		self::assertLessThan( $source, $details );
		self::assertLessThan( $storage, $connect );
		self::assertLessThan( $storage, $move );

		$broken = $this->renderStorageStatus( 'storage_needs_attention', 'manual' );
		self::assertStringContainsString( '>Storage needs attention</span>', $broken );
		self::assertStringContainsString( 'class="ran-booster-onboarding__storage-details" open', $broken );
		self::assertStringNotContainsString( 'WordPress stores the encryption key separately.', $broken );
		self::assertStringContainsString( 'Use a different storage location', $broken );
		self::assertStringContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $broken );
		self::assertStringContainsString( "dirname( __DIR__ ) . '/private/ran-booster'", $broken );
		self::assertStringContainsString( "dirname( __DIR__ ) . '/private/ran-booster/secrets.json'", $broken );
		self::assertStringContainsString( 'getenv', $broken );
		self::assertStringContainsString( 'data-ran-booster-storage-reason="storage_needs_attention"', $broken );
	}

	public function testManualRequiredStorageOpensItsInstructions(): void {
		$html = $this->renderView(
			null,
			array(
				'status'              => 'manual_required',
				'message'             => 'The WordPress configuration must be updated manually.',
				'candidate_path'      => '/private/canary/secrets.json',
				'path_source'         => null,
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => 'Verify the private directory.',
				'directory_commands'  => array( "install -d '/private/canary'" ),
				'config_alternatives' => array(
					'define' => "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '/private/canary/secrets.json' );",
					'wp_cli' => '',
				),
			)
		);

		self::assertStringContainsString( 'data-ran-booster-storage-status="manual_required"', $html );
		self::assertStringContainsString( 'Storage location', $html );
		self::assertStringContainsString( 'class="ran-booster-onboarding__storage-details" open', $html );
		self::assertStringContainsString( 'class="ran-booster-onboarding__storage-manual" open', $html );
		self::assertStringContainsString( 'Manual setup instructions', $html );
	}

	public function testManualRequiredWithoutCandidateStillShowsOverrideInstructions(): void {
		$html = $this->renderView(
			null,
			array(
				'status'              => 'manual_required',
				'reason_code'         => 'configured_path_unsafe',
				'message'             => 'The configured encrypted secrets path is not a verified private location.',
				'candidate_path'      => null,
				'path_source'         => null,
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => null,
				'directory_commands'  => array(),
				'config_alternatives' => null,
			)
		);

		self::assertStringContainsString( 'Set a storage location manually', $html );
		self::assertStringContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $html );
		self::assertStringContainsString( 'data-ran-booster-storage-reason="configured_path_unsafe"', $html );
	}

	private function renderStorageStatus(
		string $status,
		string $source,
		string $message = 'Pathless storage status.'
	): string {
		return $this->renderView(
			null,
			array(
				'status'              => $status,
				'reason_code'         => $status,
				'message'             => $message,
				'candidate_path'      => '/private/canary/secrets.json',
				'path_source'         => $source,
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => null,
				'directory_commands'  => array(),
				'config_alternatives' => null,
			)
		);
	}

	public function testStoragePanelHasItsOwnStyles(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local asset contract inspection.
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/ran-booster-onboarding.css' );

		self::assertStringContainsString( '.ran-booster-onboarding__storage {', $css );
	}

	public function testMigrationPromptIsBoundedAndFollowsThePrimarySetup(): void {
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] = array(
			static function (): void {
				echo '<section class="fixture-migration">Migration available</section>';
			},
		);

		try {
			$html = $this->renderView();
		} finally {
			unset( $GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] );
		}

		$primary   = strpos( $html, 'Move or automate later' );
		$migration = strpos( $html, 'Migration available' );
		self::assertIsInt( $primary );
		self::assertIsInt( $migration );
		self::assertLessThan( $migration, $primary );

		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] = array(
			static function (): void {
				echo 'overview-secret-canary';
				throw new \RuntimeException( 'fixture failure' );
			},
		);
		try {
			self::assertStringNotContainsString( 'overview-secret-canary', $this->renderView() );
		} finally {
			unset( $GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] );
		}
	}

	/**
	 * @param list<array{label: string, url: string}>|null $providerLinks Provider settings links.
	 * @param array<string, mixed>|null $secretsStorage Protected storage setup payload.
	 */
	private function renderView( ?array $providerLinks = null, ?array $secretsStorage = null ): string {
		$onboarding = array(
			'provider_links'      => $providerLinks ?? array(
				array(
					'label' => 'GitHub',
					'url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh',
				),
				array(
					'label' => 'Bitbucket',
					'url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=bb',
				),
			),
			'install_plugin_url'  => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins-create',
			'install_theme_url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster-themes-create',
			'portability_url'     => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability',
			'documentation_url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation',
			'troubleshooting_url' => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting',
		);
		if ( null !== $secretsStorage ) {
			$onboarding['secrets_storage'] = $secretsStorage;
		}

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/onboarding.php';

		return (string) ob_get_clean();
	}
}
