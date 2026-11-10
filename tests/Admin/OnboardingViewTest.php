<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class OnboardingViewTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_test_translations'] = array();
	}

	public function test_omits_redundant_page_label_without_changing_the_onboarding_destinations(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Ignition'] = 'Allumage';

		$html = $this->render_view();

		self::assertStringNotContainsString( '>Allumage</p>', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins-create', $html );
		self::assertStringContainsString( 'page=ran-booster-themes-create', $html );
	}

	public function test_guidance_places_storage_before_provider_credentials_and_installation(): void {
		$html = $this->render_view();

		self::assertStringContainsString(
			'<section class="ran-booster-page-shell ran-booster-onboarding ran-booster-onboarding--setup"',
			$html
		);
		self::assertStringContainsString(
			'<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title">Prepare your site</h2>',
			$html
		);
		self::assertStringContainsString( '1. Secure credential storage', $html );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath          = new \DOMXPath( $document );
		$heading_panels = $xpath->query( '//*[@id="ran-booster-onboarding-heading"]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-panel ")]' );
		self::assertInstanceOf( \DOMNodeList::class, $heading_panels );
		self::assertCount( 0, $heading_panels );
		$cards = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-overview-grid ")]/*' );
		self::assertInstanceOf( \DOMNodeList::class, $cards );
		self::assertCount( 4, $cards );
		foreach ( array( '1. Secure credential storage', '2. Choose a Git provider', '3. Import or migrate (optional)', '4. Install and manage packages' ) as $index => $label ) {
			$card = $cards->item( $index );
			self::assertInstanceOf( \DOMElement::class, $card );
			self::assertSame( 1.0, $xpath->evaluate( 'count(self::section)', $card ) );
			self::assertSame( 'overview', $card->getAttribute( 'data-ran-booster-card' ) );
			self::assertTrue( $card->hasAttribute( 'class' ) && in_array( 'ran-booster-card', explode( ' ', $card->getAttribute( 'class' ) ), true ) );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode exposes this native property.
			self::assertStringContainsString( $label, $card->textContent );
		}

		$install = strpos( $html, 'Install a plugin' );
		$private = strpos( $html, '2. Choose a Git provider' );
		$move    = strpos( $html, '3. Import or migrate (optional)' );

		self::assertIsInt( $install );
		self::assertIsInt( $private );
		self::assertIsInt( $move );
		self::assertLessThan( $install, $private );
		self::assertLessThan( $move, $private );
		self::assertStringContainsString( 'Public repositories do not need credentials or webhooks.', $html );
		self::assertStringContainsString( 'Add credentials on the provider’s page if needed.', $html );
	}

	public function test_renders_provider_package_and_help_destinations(): void {
		$html = $this->render_view();

		self::assertStringContainsString( '>GitHub</a>', $html );
		self::assertStringContainsString( '>Bitbucket</a>', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins-create', $html );
		self::assertStringContainsString( '>Install a plugin</a>', $html );
		self::assertStringContainsString( 'page=ran-booster-themes-create', $html );
		self::assertStringContainsString( '>Install a theme</a>', $html );
		self::assertStringContainsString( '>Transporter</a>', $html );
		self::assertStringContainsString( '>Browse extensions</a>', $html );
		self::assertStringContainsString( '>Install another provider</a>', $html );
		self::assertStringContainsString(
			'page=ran-booster&amp;tab=documentation#ran-booster-credential-storage">Learn how Booster manages credentials and keys</a>',
			$this->render_storage_status( 'storage_healthy', 'automatic' )
		);
			self::assertStringContainsString(
				'page=ran-booster&amp;tab=documentation#ran-booster-manual-storage-override">Manual storage override</a>',
				$this->render_storage_status( 'storage_healthy', 'automatic' )
			);
	}

	public function test_escapes_provider_labels_and_every_url(): void {
		$html = $this->render_view(
			array(
				array(
					'label' => 'GitLab <script>alert(1)</script>',
					'url'   => 'https://example.test/?unsafe="value"',
				),
			)
		);

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'GitLab &lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringContainsString( 'unsafe=&quot;value&quot;', $html );
	}

	public function test_provides_aprovider_fallback_and_uses_the_plugin_text_domain(): void {
		$html = $this->render_view( array() );

		self::assertStringContainsString( 'Install another provider', $html );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture source is inspected without a WordPress runtime.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/views/onboarding.php' );

		self::assertStringContainsString(
			"esc_html_e( 'Prepare your site', 'ran-booster' )",
			$source
		);
		self::assertStringContainsString( "esc_html_e( 'Install another provider', 'ran-booster' )", $source );
		self::assertStringNotContainsString( 'credential_profiles', $source );
		self::assertStringNotContainsString( 'webhook_profiles', $source );
	}

	public function test_renders_protected_one_click_setup_and_escaped_manual_fallback(): void {
		$html = $this->render_view(
			null,
			array(
				'status'              => 'setup_available',
				'message'             => 'Booster can create secure encrypted secrets storage.',
				'candidate_path'      => '/private/<canary>/ran-booster-store.json',
				'path_source'         => 'automatic',
				'can_provision'       => true,
				'action_url'          => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
				'manual_preflight'    => 'Verify that existing components are not symbolic links.',
				'directory_commands'  => array(
					"mkdir -p -- '/private/<canary>'",
					"test ! -L '/private/</textarea><script>'",
				),
				'config_alternatives' => array(
					'define' => "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '/private/<canary>' );",
					'wp_cli' => "wp --path='/wordpress' config set RAN_BOOSTER_ENCRYPTED_SECRETS_DIR '/private/<canary>' --type=constant",
				),
			)
		);

		self::assertStringContainsString( 'name="ran_booster[action]" value="create-secure-storage"', $html );
		self::assertStringContainsString( 'value="ran-booster-create-secure-storage"', $html );
		self::assertStringContainsString( 'Set up default storage', $html );
		self::assertStringNotContainsString( 'ran-booster-onboarding__storage-details', $html );
		self::assertStringNotContainsString( '/private/&lt;canary&gt;/ran-booster-store.json', $html );
		self::assertStringNotContainsString( '<dt>Storage directory</dt>', $html );
		self::assertStringNotContainsString( '<dt>Storage file</dt>', $html );
		self::assertStringNotContainsString( '<dt>Configuration constant</dt>', $html );
		self::assertStringContainsString( 'Booster checks a private location', $html );
		$xpath = $this->storage_xpath( $html );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//section[@aria-labelledby="ran-booster-storage"]//form[input[@name="ran_booster[action]" and @value="create-secure-storage"] and not(ancestor::details)])' ) );
		self::assertSame( 2.0, $xpath->evaluate( 'count(//section[@aria-labelledby="ran-booster-storage"]/div[@class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"]/div[@class="ran-booster-card__content"]/div[@class="ran-booster-onboarding__storage-manual" and @data-ran-booster-ui-disclosure="settings-storage" and @data-ran-booster-ui-disclosure-density="compact"]/details[@data-ran-booster-ui-disclosure-fallback and contains(@class,"storage-manual") and div[@data-ran-booster-ui-disclosure-content]]//ol/li)' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//section[@aria-labelledby="ran-booster-storage"]//div[@class="ran-booster-onboarding__storage-manual" and @data-ran-booster-ui-disclosure="settings-storage" and @data-ran-booster-ui-disclosure-density="compact"]/details[@data-ran-booster-ui-disclosure-fallback and contains(@class,"storage-manual") and div[@data-ran-booster-ui-disclosure-content]]/ancestor::details)' ) );
		self::assertStringContainsString( 'Custom setup instructions', $html );
		self::assertStringContainsString( 'absolute private directory outside the public web root on durable local storage', $html );
		self::assertStringContainsString( 'owned by PHP, readable and writable by PHP, and mode 0700', $html );
		self::assertStringNotContainsString( '<canary>', $html );
		self::assertStringContainsString( 'readonly aria-label="Directory command 1">mkdir -p -- &#039;/private/&lt;canary&gt;&#039;</textarea>', $html );
		self::assertStringContainsString( 'readonly aria-label="Directory command 2">test ! -L &#039;/private/&lt;/textarea&gt;&lt;script&gt;&#039;</textarea>', $html );
		self::assertMatchesRegularExpression( '~<ol>\s*<li>\s*<p>Create the owner-only private directories:</p>.*?</li>\s*<li>\s*<p>Then choose one configuration method, not both:</p>~s', $html );
		self::assertStringContainsString( 'aria-label="Copy directory command 1"', $html );
		self::assertStringContainsString( 'aria-label="Copy directory command 2"', $html );
		self::assertStringContainsString( 'readonly aria-label="wp-config.php line">' . esc_textarea( "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '/private/<canary>' );" ) . '</textarea>', $html );
		self::assertStringContainsString( 'readonly aria-label="WP-CLI command">' . esc_textarea( "wp --path='/wordpress' config set RAN_BOOSTER_ENCRYPTED_SECRETS_DIR '/private/<canary>' --type=constant" ) . '</textarea>', $html );
		self::assertStringContainsString( 'aria-label="Copy wp-config.php line"', $html );
		self::assertStringContainsString( 'aria-label="Copy WP-CLI command"', $html );
		self::assertStringContainsString( 'data-copy-failed-message="Clipboard access failed. The text is selected; use your browser copy command."', $html );
		self::assertStringNotContainsString( '<script>', $html );
	}

	public function test_configured_storage_renders_status_path_and_source_without_setup_controls(): void {
		$html = $this->render_view(
			null,
			array(
				'status'              => 'path_configured',
				'message'             => 'The private storage path is configured.',
				'candidate_path'      => '/private/canary/ran-booster-store.json',
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
		self::assertStringContainsString( '/private/canary/ran-booster-store.json', $html );
		self::assertStringContainsString( 'aria-label="Copy storage directory"', $html );
		self::assertStringContainsString( 'aria-label="Copy storage file"', $html );
		self::assertStringContainsString( 'Path selection', $html );
		self::assertStringContainsString( 'Booster default', $html );
		self::assertStringContainsString( 'created when you save the first credential.', $html );
		self::assertStringContainsString( 'class="ran-booster-onboarding__storage-details ran-booster-disclosure">', $html );
		self::assertStringNotContainsString( 'Set up default storage', $html );
		self::assertStringNotContainsString( 'Manual setup instructions', $html );
	}

	public function test_renders_apathless_token_bound_storage_adoption_form(): void {
		$token = str_repeat( 'b', 64 );
		$html  = $this->render_view(
			null,
			array(
				'status'              => 'storage_needs_attention',
				'reason_code'         => 'storage_directory_unavailable',
				'message'             => 'Current storage is missing.',
				'candidate_path'      => '/private/.ran-booster/0123456789abcdef/ran-booster-store.json',
				'path_source'         => 'automatic',
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => null,
				'directory_commands'  => array(),
				'config_alternatives' => null,
				'recovery'            => array(
					'state'               => 'available',
					'message'             => 'Authenticated prior storage found.',
					'candidate_path'      => '/private/.ran-booster/abcdef0123456789/ran-booster-store.json',
					'candidate_directory' => '/private/.ran-booster/abcdef0123456789',
					'token'               => $token,
					'can_adopt'           => true,
				),
			)
		);

		self::assertStringContainsString( 'Existing Booster storage found', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="adopt-secure-storage"', $html );
		self::assertSame( 1.0, $this->storage_xpath( $html )->evaluate( 'count(//section[@aria-labelledby="ran-booster-storage"]//form[input[@value="adopt-secure-storage"] and not(ancestor::details)])' ) );
		self::assertStringContainsString( 'value="ran-booster-adopt-secure-storage"', $html );
		self::assertStringContainsString( 'name="ran_booster[recovery_token]" value="' . $token . '"', $html );
		self::assertStringNotContainsString( 'name="ran_booster[candidate_path]"', $html );
		self::assertStringContainsString( 'current registered Git provider credential shape', $html );
	}

	public function test_renders_an_explicit_typed_orphaned_key_reset_with_permanent_loss_warning(): void {
		$html = $this->render_view(
			null,
			array(
				'status'              => 'storage_needs_attention',
				'reason_code'         => 'storage_file_missing',
				'message'             => 'The database key exists but the file is missing.',
				'candidate_path'      => '/private/.ran-booster/0123456789abcdef/ran-booster-store.json',
				'path_source'         => 'automatic',
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => null,
				'directory_commands'  => array(),
				'config_alternatives' => null,
				'recovery'            => array(
					'state'               => 'reset_available',
					'message'             => 'An orphaned database key was found.',
					'candidate_path'      => null,
					'candidate_directory' => null,
					'token'               => null,
					'can_adopt'           => false,
					'can_reset'           => true,
					'reset_confirmation'  => 'RESET STORAGE',
				),
			)
		);

		self::assertStringContainsString( 'Start over with empty credential storage', $html );
		self::assertStringContainsString( 'Permanent reset:', $html );
		self::assertStringContainsString( 'webhook secret', $html );
		self::assertStringContainsString( 'not a complete credential backup', $html );
		self::assertStringContainsString( 'credential-only recovery for an already-managed package', $html );
		self::assertStringContainsString( 'never contains webhook secrets', $html );
		self::assertStringContainsString( 'Reset credential storage', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="reset-empty-storage"', $html );
		self::assertSame( 1.0, $this->storage_xpath( $html )->evaluate( 'count(//section[@aria-labelledby="ran-booster-storage"]//form[input[@value="reset-empty-storage"] and not(ancestor::details)])' ) );
		self::assertStringContainsString( 'value="ran-booster-reset-empty-storage"', $html );
		self::assertStringContainsString( 'name="ran_booster[reset_confirmation]"', $html );
		self::assertStringContainsString( 'pattern="RESET STORAGE"', $html );
		self::assertStringNotContainsString( 'adopt-secure-storage', $html );
	}

	public function test_renders_the_same_restore_first_escape_hatch_when_the_database_key_is_missing(): void {
		$html = $this->render_view(
			null,
			array(
				'status'              => 'storage_needs_attention',
				'reason_code'         => 'storage_key_missing',
				'message'             => 'The encrypted file exists but its database key is missing.',
				'candidate_path'      => '/private/.ran-booster/0123456789abcdef/ran-booster-store.json',
				'path_source'         => 'automatic',
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => null,
				'directory_commands'  => array(),
				'config_alternatives' => null,
				'recovery'            => array(
					'state'               => 'reset_available',
					'message'             => 'Orphaned encrypted storage was found.',
					'candidate_path'      => null,
					'candidate_directory' => null,
					'token'               => null,
					'can_adopt'           => false,
					'can_reset'           => true,
					'reset_confirmation'  => 'RESET STORAGE',
				),
			)
		);

		self::assertStringContainsString( 'data-ran-booster-storage-reason="storage_key_missing"', $html );
		self::assertStringContainsString( 'Restore the matching ran-booster-store.json and database key from the same backup', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="reset-empty-storage"', $html );
		self::assertStringContainsString( 'Reset credential storage', $html );
	}

	public function test_healthy_and_broken_storage_use_truthful_statuses(): void {
		$healthy = $this->render_storage_status(
			'storage_healthy',
			'manual',
			'Encrypted secrets storage is configured and authenticated.'
		);
		self::assertStringContainsString( '>Storage healthy</span>', $healthy );
		self::assertStringContainsString( '<dd><code class="ran-booster-onboarding__storage-inline">Configured path</code></dd>', $healthy );
		self::assertStringNotContainsString( 'Custom wp-config.php path', $healthy );
		self::assertMatchesRegularExpression( '/<dt>Configuration constant<\/dt><dd>\s*<code class="ran-booster-onboarding__storage-inline">Not configured<\/code>\s*<\/dd>/', $healthy );
		self::assertStringContainsString( 'WordPress stores the encryption key separately.', $healthy );
		self::assertSame( 1.0, $this->storage_xpath( $healthy )->evaluate( 'count(//section[@aria-labelledby="ran-booster-storage"]/div[@class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"]/div[@class="ran-booster-card__content"]/div[@class="ran-booster-onboarding__storage-details" and @data-ran-booster-ui-disclosure="settings-storage" and @data-ran-booster-ui-disclosure-density="compact"]/details[@data-ran-booster-ui-disclosure-fallback and not(@open) and summary="Storage details"]/div[@data-ran-booster-ui-disclosure-content])' ) );
		self::assertMatchesRegularExpression(
			'/<h3 id="ran-booster-storage">Secure credential storage<\/h3>.*>Storage healthy<\/span>.*?<\/div>\s*<p>Encrypted secrets storage is configured and authenticated\.<\/p>\s*<div class="ran-booster-onboarding__storage-details" data-ran-booster-ui-disclosure="settings-storage" data-ran-booster-ui-disclosure-density="compact">\s*<details data-ran-booster-ui-disclosure-fallback class="ran-booster-onboarding__storage-details ran-booster-disclosure">/s',
			$healthy
		);
		$details = strpos( $healthy, 'class="ran-booster-onboarding__storage-details ran-booster-disclosure"' );
		$path    = strpos( $healthy, 'Storage file' );
		$source  = strpos( $healthy, 'Path selection' );
		$storage = strpos( $healthy, 'Secure credential storage' );
		self::assertIsInt( $details );
		self::assertIsInt( $path );
		self::assertIsInt( $source );
		self::assertIsInt( $storage );
		self::assertLessThan( $path, $details );
		self::assertLessThan( $source, $details );

		$broken = $this->render_storage_status( 'storage_needs_attention', 'manual' );
		self::assertStringContainsString( '>Storage needs attention</span>', $broken );
		self::assertSame( 2.0, $this->storage_xpath( $broken )->evaluate( 'count(//section[@aria-labelledby="ran-booster-storage"]/div[@class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"]/div[@class="ran-booster-card__content"]/div[@data-ran-booster-ui-disclosure="settings-storage"]/details[@data-ran-booster-ui-disclosure-fallback and @open]/div[@data-ran-booster-ui-disclosure-content])' ) );
		self::assertStringContainsString( 'class="ran-booster-onboarding__storage-details ran-booster-disclosure" open', $broken );
		self::assertStringNotContainsString( 'WordPress stores the encryption key separately.', $broken );
		self::assertStringContainsString( 'Use a different storage location', $broken );
		self::assertStringContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $broken );
		self::assertStringContainsString( 'dirname( __DIR__ ) . &#039;/private/ran-booster&#039;', $broken );
		self::assertStringContainsString( 'aria-label="Copy example wp-config.php line"', $broken );
		self::assertStringContainsString( 'aria-label="Copy example WP-CLI command"', $broken );
		self::assertStringContainsString( 'aria-label="Copy environment-managed wp-config.php bridge"', $broken );
		self::assertStringContainsString( "\tdefine( &#039;RAN_BOOSTER_ENCRYPTED_SECRETS_DIR&#039;, \$ran_booster_secrets_dir );\n}", $broken );
		self::assertStringContainsString( 'if ( is_string( $ran_booster_secrets_dir ) &amp;&amp; &#039;&#039; !== trim( $ran_booster_secrets_dir ) )', $broken );
		self::assertStringContainsString( 'Define this directory constant in wp-config.php before WordPress loads plugins.', $broken );
		self::assertStringNotContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', $broken );
		self::assertStringContainsString( 'getenv', $broken );
		self::assertStringContainsString( 'data-ran-booster-storage-reason="storage_needs_attention"', $broken );
		self::assertStringContainsString( '<strong>Diagnostic code:</strong> <code>storage_needs_attention</code>', $broken );
	}

	public function test_manual_required_storage_opens_its_instructions(): void {
		$html = $this->render_view(
			null,
			array(
				'status'              => 'manual_required',
				'message'             => 'The WordPress configuration must be updated manually.',
				'candidate_path'      => '/private/canary/ran-booster-store.json',
				'path_source'         => null,
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => 'Verify the private directory.',
				'directory_commands'  => array( "install -d '/private/canary'" ),
				'config_alternatives' => array(
					'define' => "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '/private/canary' );",
					'wp_cli' => '',
				),
			)
		);

		self::assertStringContainsString( 'data-ran-booster-storage-status="manual_required"', $html );
		self::assertStringNotContainsString( 'ran-booster-onboarding__storage-details', $html );
		self::assertStringNotContainsString( '<dt>Storage directory</dt>', $html );
		self::assertStringNotContainsString( '<dt>Storage file</dt>', $html );
		self::assertStringContainsString( 'class="ran-booster-onboarding__storage-manual ran-booster-disclosure" open', $html );
		self::assertSame( 1.0, $this->storage_xpath( $html )->evaluate( 'count(//section[@aria-labelledby="ran-booster-storage"]/div[@class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"]/div[@class="ran-booster-card__content"]/div[@class="ran-booster-onboarding__storage-manual" and @data-ran-booster-ui-disclosure="settings-storage" and @data-ran-booster-ui-disclosure-density="compact"]/details[@data-ran-booster-ui-disclosure-fallback and @open and contains(@class,"storage-manual") and div[@data-ran-booster-ui-disclosure-content]])' ) );
		self::assertStringContainsString( 'Manual setup instructions', $html );
	}

	public function test_location_unavailable_lists_escaped_discarded_candidates_and_reasons(): void {
		$html = $this->render_view(
			null,
			array(
				'status'               => 'manual_required',
				'reason_code'          => 'location_unavailable',
				'message'              => 'Booster could not determine a safe private storage location.',
				'candidate_path'       => null,
				'path_source'          => null,
				'discarded_candidates' => array(
					array(
						'directory' => '/var/www/<account>/.ran-booster/0123456789abcdef',
						'code'      => 'php_accessible_group_writable_ancestor',
						'reason'    => 'PHP can modify the blocking component.',
						'component' => '/var/www/<shared>',
					),
				),
				'can_provision'        => false,
				'action_url'           => '/admin',
				'manual_preflight'     => null,
				'directory_commands'   => array(),
				'config_alternatives'  => null,
			)
		);

		self::assertStringContainsString( 'Booster could not determine a safe private storage location.', $html );
		self::assertStringContainsString( 'location_unavailable', $html );
		self::assertStringContainsString( 'Set a storage location manually', $html );
		self::assertSame( 1.0, $this->storage_xpath( $html )->evaluate( 'count(//section[@aria-labelledby="ran-booster-storage"]/div[@class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"]/div[@class="ran-booster-card__content"]/div[@class="ran-booster-onboarding__storage-discarded" and @data-ran-booster-ui-disclosure="settings-storage" and @data-ran-booster-ui-disclosure-density="compact"]/details[@data-ran-booster-ui-disclosure-fallback and div[@data-ran-booster-ui-disclosure-content] and summary[normalize-space(.)="Why automatic setup is unavailable"] and not(@open)])' ) );
		self::assertStringNotContainsString( 'ran-booster-onboarding__storage-details', $html );
		self::assertStringContainsString( '/var/www/&lt;account&gt;/.ran-booster/0123456789abcdef', $html );
		self::assertStringContainsString( 'php_accessible_group_writable_ancestor', $html );
		self::assertStringContainsString( 'PHP can modify the blocking component.', $html );
		self::assertStringContainsString( '/var/www/&lt;shared&gt;', $html );
		self::assertStringNotContainsString( '/var/www/<account>', $html );
	}

	public function test_unsafe_configured_path_shows_rejected_directory_file_and_override_instructions(): void {
		$html = $this->render_view(
			null,
			array(
				'status'              => 'storage_needs_attention',
				'reason_code'         => 'configured_path_unsafe',
				'message'             => 'Choose a private location outside the public web root.',
				'candidate_path'      => '/var/www/site/public_html/ran-booster/ran-booster-store.json',
				'candidate_directory' => '/var/www/site/public_html/ran-booster',
				'path_source'         => 'manual',
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => null,
				'directory_commands'  => array(),
				'config_alternatives' => null,
			)
		);

		self::assertStringContainsString( 'Use a different storage location', $html );
		self::assertStringContainsString( '<dt>Storage directory</dt>', $html );
		self::assertStringContainsString( '/var/www/site/public_html/ran-booster</code>', $html );
		self::assertStringContainsString( '<dt>Storage file</dt>', $html );
		self::assertStringContainsString( '/var/www/site/public_html/ran-booster/ran-booster-store.json</code>', $html );
		self::assertStringContainsString( 'Configured path', $html );
		self::assertStringContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $html );
		self::assertStringContainsString( 'data-ran-booster-storage-reason="configured_path_unsafe"', $html );
	}

	public function test_storage_path_selection_shows_unknown_provenance_without_a_claim(): void {
		$html = $this->render_storage_status( 'path_configured', 'unknown' );

		self::assertStringContainsString( '<dt>Path selection</dt>', $html );
		self::assertStringContainsString( '<dd><code class="ran-booster-onboarding__storage-inline">Unknown</code></dd>', $html );
		self::assertStringNotContainsString( 'Booster default', $html );
		self::assertStringNotContainsString( 'Configured path', $html );
	}

	public function test_storage_file_constant_is_named_only_when_it_is_defined(): void {
		define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '/private/canary/legacy-store.json' );
		$html = $this->render_view(
			null,
			array(
				'status'              => 'manual_required',
				'reason_code'         => 'configured_path_invalid',
				'message'             => 'The encrypted secrets path constant is not a valid absolute path.',
				'candidate_path'      => null,
				'path_source'         => null,
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => null,
				'directory_commands'  => array(),
				'config_alternatives' => null,
			)
		);

		self::assertStringContainsString( 'Remove any existing RAN_BOOSTER_ENCRYPTED_SECRETS_FILE definition, then define this directory constant', $html );
		self::assertStringNotContainsString( '<p>Define this directory constant', $html );
		self::assertStringNotContainsString( "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE'", $html );
	}

	public function test_dashboard_reports_unknown_counts_and_escapes_bounded_activity(): void {
		$records              = array_fill(
			0,
			6,
			array(
				'operation' => '<operation>',
				'status'    => '<status>',
				'sort_at'   => '<time>',
			)
		);
		$records[0]['status'] = 'failed';
		$html                 = $this->render_view(
			null,
			null,
			array(
				'view'         => 'dashboard',
				'inventory'    => array(
					'plugins'  => 0,
					'themes'   => null,
					'disabled' => null,
					'complete' => false,
				),
				'activity'     => array(
					'available' => true,
					'records'   => $records,
				),
				'plugins_url'  => '/plugins',
				'themes_url'   => '/themes',
				'activity_url' => '/activity',
			)
		);
		$document             = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		$cards = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-overview-grid ")]/*' );
		self::assertInstanceOf( \DOMNodeList::class, $cards );
		self::assertCount( 4, $cards );
		foreach ( array( 'Recent Activity', 'Managed packages', 'Git providers', 'Secure credential storage' ) as $index => $label ) {
			$card = $cards->item( $index );
			self::assertInstanceOf( \DOMNode::class, $card );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode exposes this native property.
			self::assertStringContainsString( $label, $card->textContent );
		}
		$attention = $xpath->query( '//*[@id="ran-booster-overview-attention"]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-overview-grid ")]' );
		self::assertInstanceOf( \DOMNodeList::class, $attention );
		self::assertCount( 0, $attention );
		self::assertLessThan( strpos( $html, 'Recent Activity' ), strpos( $html, 'Needs attention' ) );
		self::assertLessThan( strpos( $html, 'id="ran-booster-onboarding-storage-heading"' ), strpos( $html, 'Recent Activity' ) );
		self::assertLessThan( strpos( $html, 'id="ran-booster-onboarding-storage-heading"' ), strpos( $html, 'Git providers' ) );
		self::assertLessThan( strpos( $html, 'Git providers' ), strpos( $html, 'Managed packages' ) );
		self::assertSame( '0 managed', trim( (string) $xpath->evaluate( 'string(//div[@role="group" and @aria-label="Plugins"]/p/span)' ) ) );
		self::assertSame( 'Unknown', trim( (string) $xpath->evaluate( 'string(//div[@role="group" and @aria-label="Themes"]/p/span)' ) ) );
		self::assertStringContainsString( 'is-none-intent', $html );
		self::assertStringNotContainsString( 'id="ran-booster-overview-expiry"', $html );
		self::assertSame( 5, substr_count( $html, '&lt;operation&gt;' ) );
		self::assertStringNotContainsString( '<operation>', $html );
		self::assertStringNotContainsString( '&lt;status&gt;', $html );
		self::assertStringContainsString( 'data-ran-booster-badge-intent="high">Failed</span>', $html );
		self::assertStringContainsString( 'is-none-intent', $html );
		self::assertStringContainsString( '&lt;time&gt;', $html );
		self::assertStringContainsString( '<strong>&lt;operation&gt;</strong><span aria-hidden="true"> · </span><span class="ran-booster-overview-activity__time">&lt;time&gt;</span>', $html );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath   = new \DOMXPath( $document );
		$actions = $xpath->query( '//div[@role="group" and (@aria-label="Plugins" or @aria-label="Themes")]//a' );
		self::assertInstanceOf( \DOMNodeList::class, $actions );
		$action_labels = array();
		foreach ( $actions as $action ) {
			self::assertInstanceOf( \DOMElement::class, $action );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM owns textContent.
			$action_labels[] = trim( $action->textContent );
		}
		self::assertSame( array( 'Manage plugins', 'Install a plugin', 'Manage themes', 'Install a theme' ), $action_labels );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//div[@role="group" and @aria-label="Plugins"][div/a[1]="Manage plugins" and div/a[2]="Install a plugin"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//div[@role="group" and @aria-label="Themes"][div/a[1]="Manage themes" and div/a[2]="Install a theme"])' ) );
		self::assertStringNotContainsString( 'This changes the view for all site administrators.', $html );
		self::assertStringContainsString( 'value="onboarding"', $html );
		self::assertStringContainsString( 'ran-booster-overview-presentation', $html );
	}

	public function test_package_groups_preserve_inventory_and_destinations(): void {
		$cases = array(
			array(
				array(
					'plugins'  => 0,
					'themes'   => 0,
					'disabled' => 0,
					'complete' => true,
				),
				array( '0', '0', '0' ),
			),
			array(
				array(
					'plugins'  => 12,
					'themes'   => 3,
					'disabled' => 2,
					'complete' => true,
				),
				array( '12', '3', '2' ),
			),
			array(
				array(
					'plugins'  => null,
					'themes'   => null,
					'disabled' => null,
					'complete' => false,
				),
				array( 'Unknown', 'Unknown', 'Unknown' ),
			),
			array(
				array(
					'plugins'  => 2,
					'themes'   => null,
					'disabled' => null,
					'complete' => false,
				),
				array( '2', 'Unknown', 'Unknown' ),
			),
			array(
				array(
					'plugins'  => null,
					'themes'   => 3,
					'disabled' => null,
					'complete' => false,
				),
				array( 'Unknown', '3', 'Unknown' ),
			),
		);
		foreach ( $cases as list( $inventory, $expected ) ) {
			$html     = $this->render_view(
				null,
				null,
				array(
					'view'         => 'dashboard',
					'inventory'    => $inventory,
					'plugins_url'  => '/plugins',
					'themes_url'   => '/themes',
					'activity_url' => '/activity',
				)
			);
			$document = new \DOMDocument();
			$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
			$xpath = new \DOMXPath( $document );
			foreach ( array( 'Plugins', 'Themes' ) as $index => $label ) {
				$group = '//div[@role="group" and @aria-label="' . $label . '"]';
				self::assertSame( 1.0, $xpath->evaluate( 'count(' . $group . ')' ) );
				self::assertSame( $label, trim( (string) $xpath->evaluate( 'string(' . $group . '/p/strong)' ) ) );
				$count = 'Unknown' === $expected[ $index ] ? 'Unknown' : $expected[ $index ] . ' managed';
				self::assertSame( $count, trim( (string) $xpath->evaluate( 'string(' . $group . '/p/span)' ) ) );
			}
			self::assertSame( 'Updates disabled: ' . $expected[2], trim( (string) $xpath->evaluate( 'string(//*[@aria-labelledby="ran-booster-overview-packages"]/div[@class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"]/div[@class="ran-booster-card__content"]/p[contains(concat(" ", normalize-space(@class), " "), " ran-booster-overview-package-policy ")])' ) ) );
			self::assertSame( '/plugins', $xpath->evaluate( 'string(//div[@role="group" and @aria-label="Plugins"]/div/a[1]/@href)' ) );
			self::assertSame( 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins-create', $xpath->evaluate( 'string(//div[@role="group" and @aria-label="Plugins"]/div/a[2]/@href)' ) );
			self::assertSame( '/themes', $xpath->evaluate( 'string(//div[@role="group" and @aria-label="Themes"]/div/a[1]/@href)' ) );
			self::assertSame( 'https://example.test/wp-admin/admin.php?page=ran-booster-themes-create', $xpath->evaluate( 'string(//div[@role="group" and @aria-label="Themes"]/div/a[2]/@href)' ) );
		}
	}

	public function test_both_headers_keep_one_protected_native_view_switch(): void {
		foreach ( array( 'onboarding', 'dashboard' ) as $view ) {
			$extra    = array(
				'view'         => $view,
				'overview_url' => '/overview?keep=route',
				'plugins_url'  => '/plugins',
				'themes_url'   => '/themes',
				'activity_url' => '/activity',
			);
			$html     = $this->render_view( null, null, $extra );
			$document = new \DOMDocument();
			$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
			$xpath = new \DOMXPath( $document );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//input[@name="ran_booster[action]" and @value="overview-presentation"])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//header/form[@method="post" and @action="/overview?keep=route"][input[@name="_wpnonce" and @value="ran-booster-overview-presentation"]])' ) );
			$target = 'onboarding' === $view ? 'dashboard' : 'onboarding';
			self::assertSame( 1.0, $xpath->evaluate( 'count(//header/form/input[@name="ran_booster[presentation]" and @value="' . $target . '"])' ) );
			$label = 'onboarding' === $view ? 'Skip onboarding' : 'Return to onboarding';
			self::assertSame( 1.0, $xpath->evaluate( 'count(//header/form/span[@data-ran-booster-ui-button="overview-presentation"])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//header/form/span/button[@type="submit" and @class="button" and @data-ran-booster-ui-button-fallback and text()="' . $label . '"])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//header/form/span/span[@data-ran-booster-ui-button-mount and not(node())])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//header/form//button)' ) );
			$heading     = 'onboarding' === $view ? 'Prepare your site' : 'Overview';
			$description = 'onboarding' === $view ? 'Public repositories do not need credentials or webhooks.' : 'Review recent activity, managed packages and your site’s configuration.';
			self::assertSame( 1.0, $xpath->evaluate( 'count(//header/div/h2[@id="ran-booster-onboarding-heading" and @class="ran-booster-page-heading__title" and text()="' . $heading . '"]/following-sibling::p[@class="ran-booster-page-heading__description" ])' ) );
			self::assertStringContainsString( $description, $html );
			self::assertStringNotContainsString( 'ran-booster-onboarding__presentation-control', $html );
			$GLOBALS['ran_booster_test_capabilities']['manage_options'] = false;
			try {
				self::assertStringNotContainsString( 'overview-presentation', $this->render_view( null, null, $extra ) );
			} finally {
				unset( $GLOBALS['ran_booster_test_capabilities']['manage_options'] );
			}
		}
	}

	public function test_presentation_controls_and_activity_availability(): void {
		$extra = array(
			'view'         => 'dashboard',
			'plugins_url'  => '/plugins',
			'themes_url'   => '/themes',
			'activity_url' => '/activity',
		);
		$html  = $this->render_view( null, null, $extra );
		self::assertStringContainsString( 'Activity is unavailable.', $html );
		$extra['activity'] = array(
			'available' => true,
			'records'   => array(),
		);
		self::assertStringContainsString( 'No recent activity.', $this->render_view( null, null, $extra ) );
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = false;
		try {
			self::assertStringNotContainsString( 'overview-presentation', $this->render_view( null, null, $extra ) );
		} finally {
			unset( $GLOBALS['ran_booster_test_capabilities']['manage_options'] );
		}
		self::assertStringContainsString( 'value="dashboard"', $this->render_view() );
	}

	public function test_both_presentations_keep_only_settings_storage_handoff_and_migration_prompt(): void {
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] = array(
			static function (): void {
				echo 'migration-marker'; },
		);
		try {
			foreach ( array( 'onboarding', 'dashboard' ) as $view ) {
				$html = $this->render_view(
					null,
					null,
					array(
						'view'            => $view,
						'plugins_url'     => '/plugins',
						'themes_url'      => '/themes',
						'activity_url'    => '/activity',
						'storage_summary' => array(
							'status'  => 'storage_healthy',
							'message' => 'Storage ready.',
							'url'     => '/settings#ran-booster-storage',
						),
						'secrets_storage' => array(
							'candidate_path'      => '/private/canary',
							'config_alternatives' => array( 'CANARY' ),
							'recovery_token'      => 'canary',
						),
					)
				);
				self::assertSame( 0, substr_count( $html, 'create-secure-storage' ) );
				self::assertSame( 0, substr_count( $html, 'adopt-secure-storage' ) );
				self::assertSame( 0, substr_count( $html, 'reset-empty-storage' ) );
				self::assertStringNotContainsString( 'value="ran-booster-create-secure-storage"', $html );
				self::assertStringNotContainsString( 'value="ran-booster-adopt-secure-storage"', $html );
				self::assertStringNotContainsString( 'value="ran-booster-reset-empty-storage"', $html );
				self::assertStringContainsString( 'value="ran-booster-overview-presentation"', $html );
				self::assertStringNotContainsString( '/private/canary', $html );
				self::assertStringNotContainsString( 'CANARY', $html );
				self::assertSame( 1, substr_count( $html, 'id="ran-booster-onboarding-storage-heading"' ) );
				self::assertSame( 1, substr_count( $html, 'migration-marker' ) );
				self::assertStringContainsString( 'href="/settings#ran-booster-storage"', $html );
				if ( 'onboarding' === $view ) {
					$storage     = strpos( $html, 'Secure credential storage' );
					$provider    = strpos( $html, '2. Choose a Git provider' );
					$install     = strpos( $html, '4. Install and manage packages' );
					$credentials = strpos( $html, 'Add credentials on the provider’s page if needed.' );
					self::assertIsInt( $storage );
					self::assertIsInt( $provider );
					self::assertIsInt( $install );
					self::assertIsInt( $credentials );
					self::assertLessThan( $provider, $storage );
					self::assertLessThan( $install, $credentials );
				}
			}
		} finally {
			unset( $GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] );
		}
	}

	/** @return array<string,mixed> */
	private function storage_extra( string $status, bool $can_provision, string $view = 'onboarding' ): array {
		return array(
			'view'            => $view,
			'storage_summary' => array(
				'status'        => $status,
				'message'       => 'Pathless storage status.',
				'url'           => '/settings#ran-booster-storage',
				'can_provision' => $can_provision,
				'action_url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
			),
		);
	}

	public function test_onboarding_storage_card_offers_default_storage_setup_when_available(): void {
		$html  = $this->render_view( null, null, $this->storage_extra( 'setup_available', true ) );
		$xpath = $this->storage_xpath( $html );
		self::assertSame( 1, substr_count( $html, 'Set up default storage' ) );
		self::assertSame( 1, substr_count( $html, 'value="create-secure-storage"' ) );
		self::assertSame( 0, substr_count( $html, 'adopt-secure-storage' ) );
		self::assertSame( 0, substr_count( $html, 'reset-empty-storage' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//section[@aria-labelledby="ran-booster-onboarding-storage-heading"]//form[@method="post" and @action="https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview"]//button[@type="submit"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//section[@aria-labelledby="ran-booster-onboarding-storage-heading"]//form//input[@name="ran_booster[action]" and @value="create-secure-storage"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//section[@aria-labelledby="ran-booster-onboarding-storage-heading"]//a[@href="/settings#ran-booster-storage"])' ) );
		self::assertStringNotContainsString( '/private', $html );
	}

	public function test_onboarding_storage_card_has_no_setup_button_unless_setup_is_available(): void {
		foreach ( array( 'path_configured', 'storage_healthy', 'pending_verification', 'manual_required', 'storage_needs_attention', 'unsupported' ) as $status ) {
			$html = $this->render_view( null, null, $this->storage_extra( $status, false ) );
			self::assertSame( 0, substr_count( $html, 'Set up default storage' ), $status );
			self::assertSame( 0, substr_count( $html, 'create-secure-storage' ), $status );
			self::assertSame( 0, substr_count( $html, 'ran-booster-create-secure-storage' ), $status );
		}
	}

	public function test_failed_or_pending_setup_never_renders_a_premature_success_state(): void {
		foreach ( array( 'pending_verification', 'manual_required' ) as $status ) {
			$html  = $this->render_view( null, null, $this->storage_extra( $status, false ) );
			$xpath = $this->storage_xpath( $html );
			self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@data-ran-booster-storage-status="storage_healthy" or @data-ran-booster-storage-status="path_configured"])' ), $status );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@data-ran-booster-storage-status="' . $status . '"])' ), $status );
		}
	}

	public function test_dashboard_presentation_never_renders_the_setup_form_even_when_available(): void {
		$html = $this->render_view(
			null,
			null,
			array_merge(
				$this->storage_extra( 'setup_available', true, 'dashboard' ),
				array(
					'plugins_url'  => '/plugins',
					'themes_url'   => '/themes',
					'activity_url' => '/activity',
				)
			)
		);
		self::assertSame( 0, substr_count( $html, 'Set up default storage' ) );
		self::assertSame( 0, substr_count( $html, 'create-secure-storage' ) );
	}

	public function test_attention_is_visible_in_both_presentations_for_incomplete_inventory_and_storage_failure(): void {
		foreach ( array( 'onboarding', 'dashboard' ) as $view ) {
			$extra = array(
				'view'            => $view,
				'inventory'       => array(
					'plugins'  => null,
					'themes'   => 0,
					'disabled' => null,
					'complete' => false,
				),
				'storage_summary' => array(
					'status'  => 'storage_needs_attention',
					'message' => '<storage failure>',
				),
				'plugins_url'     => '/plugins',
				'themes_url'      => '/themes',
				'activity_url'    => '/activity',
			);
			$html  = $this->render_view( null, null, $extra );
			self::assertStringContainsString( 'inventory is incomplete or unavailable', $html );
			self::assertStringContainsString( '&lt;storage failure&gt;', $html );
			self::assertStringContainsString( 'Review storage', $html );
			$xpath = $this->storage_xpath( $html );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//section[@aria-labelledby="ran-booster-overview-attention" and @data-ran-booster-card="overview" and contains(concat(" ", normalize-space(@class), " "), " ran-booster-card ")]/div[@class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"]/div[@class="ran-booster-card__content"]/h3[@id="ran-booster-overview-attention"])' ) );
		}
	}

	public function test_attention_card_is_absent_when_no_attention_is_needed(): void {
		$xpath = $this->storage_xpath( $this->render_view( null, null, array( 'storage_summary' => array( 'status' => 'storage_healthy' ) ) ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//section[@aria-labelledby="ran-booster-overview-attention"])' ) );
	}

	public function test_missing_storage_status_is_unavailable_even_with_complete_inventory(): void {
		foreach ( array( 'onboarding', 'dashboard' ) as $view ) {
			$extra = array(
				'view'            => $view,
				'inventory'       => array(
					'plugins'  => 0,
					'themes'   => 0,
					'disabled' => 0,
					'complete' => true,
				),
				'storage_summary' => null,
				'plugins_url'     => '/plugins',
				'themes_url'      => '/themes',
				'activity_url'    => '/activity',
			);
			$html  = $this->render_view( null, null, $extra );
			self::assertStringContainsString( 'Secure credential storage status is unavailable.', $html );
			self::assertStringContainsString( 'id="ran-booster-onboarding-storage-heading"', $html );
			self::assertStringContainsString( 'Review storage', $html );
			$extra['storage_summary'] = array( 'message' => 'No status' );
			self::assertStringContainsString( 'Secure credential storage status is unavailable.', $this->render_view( null, null, $extra ) );
		}
	}

	public function test_provider_admission_and_selection_remediation_use_shared_facts(): void {
		foreach ( array( 'onboarding', 'dashboard' ) as $view ) {
			$extra = array(
				'view'               => $view,
				'plugins_url'        => '/plugins',
				'themes_url'         => '/themes',
				'activity_url'       => '/activity',
				'provider_summaries' => array(
					array(
						'code'      => 'gh',
						'label'     => '<GitHub>',
						'url'       => '/github',
						'admitted'  => false,
						'selection' => 'conflict',
					),
					array(
						'code'      => 'bb',
						'label'     => 'Bitbucket',
						'url'       => '/bitbucket',
						'admitted'  => true,
						'selection' => null,
					),
					array(
						'code'      => 'other',
						'label'     => 'Other',
						'url'       => '/other',
						'admitted'  => null,
						'selection' => null,
					),
				),
			);
			$html  = $this->render_view( null, null, $extra );
			// A conflict is reported as a conflict, not as a refused admission.
			self::assertStringContainsString( '&lt;GitHub&gt;</a>: Provider selection conflict', $html );
			self::assertStringNotContainsString( 'Provider is not admitted.', $html );
			self::assertStringNotContainsString( '<GitHub>', $html );
			$extra['provider_summaries'][0]['selection'] = null;
			self::assertStringContainsString( '&lt;GitHub&gt;</a>: Provider is not admitted.', $this->render_view( null, null, $extra ) );
			$extra['provider_summaries'][0]['selection'] = 'conflict';
			if ( 'dashboard' === $view ) {
				self::assertStringContainsString( 'Provider source unknown', $html );
				self::assertStringNotContainsString( 'data-ran-booster-badge-intent="stable">Admitted</span>', $html );
				self::assertStringNotContainsString( 'Repository access has not been checked here.', $html );
			}
			$extra['provider_summaries'][0]['admitted'] = true;
			$html                                       = $this->render_view( null, null, $extra );
			self::assertStringContainsString( '&lt;GitHub&gt;</a>: Provider selection conflict', $html );
			$extra['provider_summaries'][0]['selection'] = null;
			$html                                        = $this->render_view( null, null, $extra );
			self::assertStringNotContainsString( '&lt;GitHub&gt;</a>:', $html );
			if ( 'dashboard' === $view ) {
				self::assertStringContainsString( 'Provider source unknown', $html );
			}
		}
	}

	public function test_detected_replacement_awaiting_approval_names_the_remaining_step(): void {
		$detected = 'A custom GitHub provider was detected. Review and approve it to enable GitHub operations.';
		foreach ( array( 'onboarding', 'dashboard' ) as $view ) {
			$extra = array(
				'view'               => $view,
				'plugins_url'        => '/plugins',
				'themes_url'         => '/themes',
				'activity_url'       => '/activity',
				'provider_summaries' => array(
					array(
						'code'                 => 'gh',
						'label'                => 'GitHub',
						'url'                  => '/github',
						'admitted'             => false,
						'selection'            => 'decision_required',
						'replacement_detected' => true,
					),
				),
			);
			$html  = $this->render_view( null, null, $extra );
			self::assertStringContainsString( '<a href="/github">GitHub</a>: ' . $detected, $html );
			self::assertStringNotContainsString( 'Provider is not admitted.', $html );
			// Without a detected compatible replacement the state keeps its generic wording.
			foreach ( array(
				array( 'decision_required', false, 'Provider selection needs a decision' ),
				array( 'incompatible', true, 'Provider selection is incompatible' ),
				array( 'missing', true, 'Selected provider is missing' ),
				array( 'conflict', true, 'Provider selection conflict' ),
				array( 'storage_unavailable', true, 'Provider selection storage is unavailable' ),
				array( 'recovery_required', true, 'Provider selection needs recovery' ),
			) as list( $selection, $detected_flag, $label ) ) {
				$extra['provider_summaries'][0]['selection']            = $selection;
				$extra['provider_summaries'][0]['replacement_detected'] = $detected_flag && 'decision_required' === $selection;
				$html = $this->render_view( null, null, $extra );
				self::assertStringContainsString( '<a href="/github">GitHub</a>: ' . $label, $html, $selection );
				self::assertStringNotContainsString( $detected, $html, $selection );
			}
		}
	}

	public function test_dashboard_shows_selected_provider_origin_without_operational_claims(): void {
		$extra = array(
			'view'               => 'dashboard',
			'plugins_url'        => '/plugins',
			'themes_url'         => '/themes',
			'activity_url'       => '/activity',
			'provider_summaries' => array(
				array(
					'code'        => 'gh',
					'label'       => 'GitHub',
					'url'         => '/github',
					'admitted'    => true,
					'selection'   => 'available',
					'source_kind' => 'bundled',
					'source_name' => null,
				),
				array(
					'code'        => 'bb',
					'label'       => 'Bitbucket',
					'url'         => '/bitbucket',
					'admitted'    => true,
					'selection'   => null,
					'source_kind' => 'extension',
					'source_name' => 'Bitbucket Cloud',
				),
			),
		);
		$html  = $this->render_view( null, null, $extra );
		self::assertStringContainsString( 'Included with Booster', $html );
		self::assertSame( 1, substr_count( $html, '<strong>Bitbucket Cloud</strong>' ) );
		self::assertStringContainsString( 'Provider extension', $html );
		self::assertStringNotContainsString( 'Custom provider', $html );
		$extra['provider_summaries'][1]['source_name'] = '<Custom integration>';
		$html = $this->render_view( null, null, $extra );
		self::assertStringContainsString( 'Provider extension: &lt;Custom integration&gt;', $html );
		self::assertStringNotContainsString( '<Custom integration>', $html );
		self::assertStringNotContainsString( 'Rockets Are Nostalgic', $html );
		$extra['provider_summaries'][1]['source_name'] = 'Bitbucket Cloud';

		$extra['provider_summaries'][0]['source_kind']                                    = 'replacement';
		$extra['provider_summaries'][0]['source_name']                                    = '<Selected Plugin>';
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Custom provider'] = 'Custom <provider> & "partner"';
		$html = $this->render_view( null, null, $extra );
		self::assertStringContainsString( 'Provider extension: &lt;Selected Plugin&gt;', $html );
		self::assertStringContainsString( 'Custom &lt;provider&gt; &amp; &quot;partner&quot;</span>', $html );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		$hosts = $xpath->query( '//section[@aria-labelledby="ran-booster-overview-providers"]//span[@data-ran-booster-badge]' );
		self::assertInstanceOf( \DOMNodeList::class, $hosts );
		self::assertCount( 1, $hosts );
		$host = $hosts->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $host );
		self::assertSame( 'none', $host->getAttribute( 'data-ran-booster-badge-intent' ) );
		self::assertSame( 'ran-wp-ui-text is-body-sm ran-wp-ui-badge is-none-intent', $host->getAttribute( 'class' ) );
		self::assertSame( 0.0, $xpath->evaluate( 'count(./*)', $host ) );
		self::assertSame( 'Custom <provider> & "partner"', $xpath->evaluate( 'string(.)', $host ) );
		self::assertStringNotContainsString( '<Selected Plugin>', $html );
		$extra['provider_summaries'][0]['source_kind'] = null;
		$extra['provider_summaries'][0]['source_name'] = null;
		$html = $this->render_view( null, null, $extra );
		self::assertStringContainsString( 'Provider source unknown', $html );
		self::assertStringNotContainsString( 'Custom &lt;provider&gt;', $html );
	}

	public function test_expiry_reminders_are_shared_with_attention_and_dashboard(): void {
		$item = array(
			'provider'             => 'gh',
			'provider_label'       => '<GitHub>',
			'label'                => '<credential>',
			'badge_label'          => '<Expires soon>',
			'stage'                => 'warning',
			'effective_expires_at' => '<2026-11-02>',
			'source'               => '<provider>',
			'observed_at'          => '<2026-10-31>',
			'url'                  => '/credentials',
		);
		foreach ( array( 'onboarding', 'dashboard' ) as $view ) {
			$html = $this->render_view(
				null,
				null,
				array(
					'view'              => $view,
					'plugins_url'       => '/plugins',
					'themes_url'        => '/themes',
					'activity_url'      => '/activity',
					'credential_expiry' => array(
						'status' => 'captured',
						'total'  => 6,
						'items'  => array_fill( 0, 5, $item ),
					),
				)
			);
			self::assertStringContainsString( 'href="/credentials"', $html );
			self::assertStringContainsString( '&lt;Expires soon&gt;', $html );
			self::assertStringNotContainsString( '<credential>', $html );
			self::assertStringNotContainsString( 'Current credential expiry is unknown', $html );
			self::assertSame( 5, substr_count( $html, '&lt;credential&gt;' ) );
			if ( 'dashboard' === $view ) {
				self::assertStringContainsString( 'Showing 5 of 6 actionable expiry reminders.', $html );
				self::assertStringNotContainsString( '&lt;2026-11-02&gt;', $html );
				self::assertStringNotContainsString( '&lt;provider&gt;', $html );
				self::assertStringNotContainsString( '&lt;2026-10-31&gt;', $html );
			}
		}
	}

	public function test_expiry_snapshot_states_are_distinct(): void {
		$extra = array(
			'view'         => 'dashboard',
			'plugins_url'  => '/plugins',
			'themes_url'   => '/themes',
			'activity_url' => '/activity',
		);
		self::assertStringNotContainsString( 'Current credential expiry is unknown', $this->render_view( null, null, $extra ) );
		$extra['credential_expiry'] = array(
			'status' => 'unavailable',
			'total'  => null,
			'items'  => array(),
		);
		$html                       = $this->render_view( null, null, $extra );
		self::assertStringContainsString( 'Credential expiry reminders are unavailable', $html );
		self::assertStringNotContainsString( 'Current credential expiry is unknown', $html );
		$extra['credential_expiry'] = array(
			'status' => 'captured',
			'total'  => 0,
			'items'  => array(),
		);
		$html                       = $this->render_view( null, null, $extra );
		self::assertStringNotContainsString( 'id="ran-booster-overview-expiry"', $html );
		self::assertStringNotContainsString( 'Current credential expiry is unknown', $html );
		$extra['credential_expiry'] = array(
			'status' => 'captured',
			'total'  => 1,
			'items'  => array(
				array(
					'provider'             => 'gh',
					'provider_label'       => 'GitHub',
					'label'                => 'Manual credential',
					'badge_label'          => 'Expires soon',
					'stage'                => 'warning',
					'effective_expires_at' => '2026-11-02',
					'source'               => 'manual',
					'observed_at'          => null,
					'url'                  => '/credentials',
				),
			),
		);
		$html                       = $this->render_view( null, null, $extra );
		self::assertStringNotContainsString( 'Source: manual', $html );
		self::assertStringNotContainsString( 'Observed:', $html );
	}

	public function test_storage_badge_projects_each_status_on_both_surfaces(): void {
		$cases = array(
			'path_configured'         => array( 'Path configured', 'none', 'neutral' ),
			'storage_healthy'         => array( 'Storage healthy', 'stable', 'ok' ),
			'storage_needs_attention' => array( 'Storage needs attention', 'medium', 'warning' ),
			'setup_available'         => array( 'Setup available', 'medium', 'warning' ),
			'manual_required'         => array( 'Needs attention', 'medium', 'warning' ),
			'unsupported'             => array( 'Unavailable', 'high', 'error' ),
			'pending_verification'    => array( 'Verification pending', 'informational', 'pending' ),
			'unrecognized'            => array( 'Status unknown', 'none', 'neutral' ),
			''                        => array( 'Status unknown', 'none', 'neutral' ),
		);
		foreach ( $cases as $status => $expected ) {
			foreach ( array( 'overview', 'settings' ) as $surface ) {
				$html  = 'overview' === $surface
					? $this->render_view( null, null, array( 'storage_summary' => array( 'status' => $status ) ) )
					: $this->render_storage_status( $status, 'automatic' );
				$xpath = $this->storage_xpath( $html );
				$hosts = $xpath->query( '//span[@data-ran-booster-storage-status]' );
				self::assertInstanceOf( \DOMNodeList::class, $hosts );
				self::assertCount( 1, $hosts );
				$host = $hosts->item( 0 );
				self::assertInstanceOf( \DOMElement::class, $host );
				self::assertSame( 4, $host->attributes->length );
				self::assertTrue( $host->hasAttribute( 'data-ran-booster-badge' ) );
				self::assertSame( $status, $host->getAttribute( 'data-ran-booster-storage-status' ) );
				self::assertSame( $expected[0], $xpath->evaluate( 'string(.)', $host ) );
				self::assertSame( $expected[1], $host->getAttribute( 'data-ran-booster-badge-intent' ) );
				self::assertSame( 'ran-wp-ui-text is-body-sm ran-wp-ui-badge is-' . $expected[1] . '-intent', $host->getAttribute( 'class' ) );
				self::assertSame( 0.0, $xpath->evaluate( 'count(./*)', $host ) );
				self::assertSame( 0.0, $xpath->evaluate( 'count(.//*[@role or @aria-live] | self::*[@role or @aria-live])', $host ) );
				self::assertStringNotContainsString( 'canary', $xpath->evaluate( 'string(.)', $host ) );
			}
		}
		foreach ( array(
			$this->render_view( null, null, array( 'storage_summary' => array() ) ),
			$this->render_view(
				null,
				array(
					'message'             => 'Missing status',
					'can_provision'       => false,
					'config_alternatives' => null,
				)
			),
		) as $html ) {
			$xpath = $this->storage_xpath( $html );
			self::assertSame( 'Status unknown', $xpath->evaluate( 'string(//span[@data-ran-booster-storage-status])' ) );
			self::assertSame( 'none', $xpath->evaluate( 'string(//span[@data-ran-booster-storage-status]/@data-ran-booster-badge-intent)' ) );
		}
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Status unknown'] = 'Unknown <storage> & "quoted"';
		foreach ( array( $this->render_view(), $this->render_storage_status( '', 'automatic' ) ) as $html ) {
			$xpath = $this->storage_xpath( $html );
			self::assertSame( 'Unknown <storage> & "quoted"', $xpath->evaluate( 'string(//span[@data-ran-booster-storage-status])' ) );
		}
	}

	public function test_expiry_badges_preserve_labels_and_render_static_badges(): void {
		foreach ( array(
			'expired' => 'high',
			'urgent'  => 'high',
			'warning' => 'medium',
		) as $stage => $intent ) {
			$item  = array(
				'provider'             => 'gh',
				'provider_label'       => 'GitHub',
				'label'                => 'Credential',
				'badge_label'          => 'Expires <soon> & "quoted"',
				'stage'                => $stage,
				'effective_expires_at' => 'private-date',
				'source'               => 'private-source',
				'observed_at'          => null,
				'url'                  => '/credentials',
			);
			$html  = $this->render_view(
				null,
				null,
				array(
					'credential_expiry' => array(
						'status' => 'captured',
						'total'  => 1,
						'items'  => array( $item ),
					),
				)
			);
			$xpath = $this->storage_xpath( $html );
			$hosts = $xpath->query( '//a[@href="/credentials"]/following-sibling::span[@data-ran-booster-badge]' );
			self::assertInstanceOf( \DOMNodeList::class, $hosts );
			self::assertCount( 1, $hosts );
			$host = $hosts->item( 0 );
			self::assertInstanceOf( \DOMElement::class, $host );
			self::assertSame( 3, $host->attributes->length );
			self::assertSame( $intent, $host->getAttribute( 'data-ran-booster-badge-intent' ) );
			self::assertSame( 'ran-wp-ui-text is-body-sm ran-wp-ui-badge is-' . $intent . '-intent', $host->getAttribute( 'class' ) );
			self::assertSame( $item['badge_label'], $xpath->evaluate( 'string(.)', $host ) );
			self::assertSame( 0.0, $xpath->evaluate( 'count(./*)', $host ) );
			self::assertSame( 0.0, $xpath->evaluate( 'count(.//*[@role or @aria-live] | self::*[@role or @aria-live])', $host ) );
			self::assertStringNotContainsString( 'private-', $xpath->evaluate( 'string(.)', $host ) );
		}
	}

	private function storage_xpath( string $html ): \DOMXPath {
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		return new \DOMXPath( $document );
	}

	public function test_unconfirmed_storage_states_do_not_expose_candidate_facts(): void {
		foreach ( array( 'pending_verification', 'unsupported' ) as $status ) {
			$html = $this->render_storage_status( $status, 'automatic' );
			self::assertStringNotContainsString( 'ran-booster-onboarding__storage-details', $html );
			self::assertStringNotContainsString( '/private/canary/ran-booster-store.json', $html );
			self::assertStringNotContainsString( 'Set up default storage', $html );
		}
	}

	private function render_storage_status(
		string $status,
		string $source,
		string $message = 'Pathless storage status.'
	): string {
		return $this->render_view(
			null,
			array(
				'status'              => $status,
				'reason_code'         => $status,
				'message'             => $message,
				'candidate_path'      => '/private/canary/ran-booster-store.json',
				'path_source'         => $source,
				'can_provision'       => false,
				'action_url'          => '/admin',
				'manual_preflight'    => null,
				'directory_commands'  => array(),
				'config_alternatives' => null,
			)
		);
	}

	public function test_storage_panel_has_its_own_styles(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local asset contract inspection.
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/ran-booster-onboarding.css' );

		self::assertStringContainsString( '.ran-booster-onboarding__storage {', $css );
	}

	public function test_migration_prompt_is_bounded_and_follows_the_primary_setup(): void {
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] = array(
			static function (): void {
				echo '<section class="ran-booster-onboarding__storage fixture-migration">Migration available</section>';
			},
		);

		try {
			$html = $this->render_view();
		} finally {
			unset( $GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] );
		}

		$primary   = strpos( $html, '3. Import or migrate (optional)' );
		$migration = strpos( $html, 'Migration available' );
		self::assertIsInt( $primary );
		self::assertIsInt( $migration );
		self::assertLessThan( $migration, $primary );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath  = new \DOMXPath( $document );
		$prompt = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-onboarding ")]/*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-onboarding__storage ") and contains(concat(" ", normalize-space(@class), " "), " fixture-migration ")]' );
		self::assertInstanceOf( \DOMNodeList::class, $prompt );
		self::assertCount( 1, $prompt );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//*[contains(concat(" ", normalize-space(@class), " "), " fixture-migration ")]/ancestor-or-self::*[@data-ran-booster-card])' ) );

		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] = array(
			static function (): void {
				echo 'overview-secret-canary';
				throw new \RuntimeException( 'fixture failure' );
			},
		);
		try {
			self::assertStringNotContainsString( 'overview-secret-canary', $this->render_view() );
		} finally {
			unset( $GLOBALS['ran_booster_admin_view_actions']['ran_booster_overview_render_migration_prompt'] );
		}
	}

	/**
	 * @param list<array{label: string, url: string}>|null $provider_links Provider settings links.
	 * @param array<string, mixed>|null $secrets_storage Protected storage setup payload.
	 * @param array<string, mixed> $extra Additional Overview fixture payload.
	 */
	private function render_view( ?array $provider_links = null, ?array $secrets_storage = null, array $extra = array() ): string {
		$onboarding = array(
			'provider_links'      => $provider_links ?? array(
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
		$onboarding = array_merge( $onboarding, $extra );
		if ( null !== $secrets_storage ) {
			$onboarding['secrets_storage'] = $secrets_storage;
		}

		ob_start();
		if ( null !== $secrets_storage ) {
			$credential_storage_documentation_url = $onboarding['documentation_url'] . '#ran-booster-credential-storage';
			require dirname( __DIR__, 2 ) . '/views/settings-storage.php';
		} else {
			require dirname( __DIR__, 2 ) . '/views/onboarding.php';
		}

		return (string) ob_get_clean();
	}
}
