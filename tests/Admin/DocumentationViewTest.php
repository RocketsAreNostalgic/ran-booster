<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once __DIR__ . '/../Support/DocumentationHookWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/DocumentationHookRenderer.php';

final class DocumentationViewTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_documentation_test_actions'] = array();
		$GLOBALS['ran_booster_documentation_test_filters'] = array();
		$GLOBALS['ran_booster_admin_view_filters']         = array();
		$GLOBALS['ran_booster_admin_test_translations']    = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_documentation_test_actions'], $GLOBALS['ran_booster_documentation_test_filters'], $GLOBALS['ran_booster_admin_view_filters'] );
	}

	public function testTranslatesTheGuidanceEyebrowWithoutChangingDocumentationNavigation(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Guidance'] = 'Conseils';

		$html = $this->renderView( $this->providerDocumentation() );

		self::assertStringContainsString( '<p class="ran-booster-eyebrow">Conseils</p>', $html );
		self::assertStringContainsString( 'class="ran-booster-documentation__index-link"', $html );
		self::assertStringContainsString( 'id="ran-booster-documentation-heading"', $html );
	}

	public function testRendersTenNativeDisclosureSectionsWithOnlyQuickStartOpen(): void {
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertStringContainsString( 'class="ran-booster-documentation ran-booster-documentation__layout"', $html );
		self::assertStringContainsString( 'class="ran-booster-page-shell ran-booster-panel ran-booster-documentation__main"', $html );
		self::assertStringContainsString( 'class="ran-booster-page-shell__header ran-booster-documentation__header"', $html );
		self::assertStringContainsString( 'class="ran-booster-page-shell__body"', $html );
		self::assertSame( 10, preg_match_all( '/<details\b/', $html ) );
		self::assertSame( 1, preg_match_all( '/<details\b[^>]*\bopen\b/', $html ) );
		self::assertMatchesRegularExpression( '/<details\b[^>]*\bopen\b[^>]*>\s*<summary>Quick start<\/summary>/', $html );
		self::assertMatchesRegularExpression( '/<summary>\s*GitHub credentials and access\s*<\/summary>/', $html );
		self::assertMatchesRegularExpression( '/<summary>\s*Bitbucket credentials and access\s*<\/summary>/', $html );
		self::assertStringContainsString( '<summary>Installing and managing packages</summary>', $html );
		self::assertStringContainsString( '<summary>Push-to-deploy</summary>', $html );
		self::assertStringContainsString( 'id="ran-booster-push-to-deploy"', $html );
		self::assertStringContainsString( 'configured separately on every target site', $html );
		self::assertStringContainsString( 'HMAC verification authorizes deployment after WordPress accepts the request; it is not DDoS protection', $html );
		self::assertStringContainsString( 'the pretty /wp-json/ route or the equivalent ?rest_route= query route', $html );
		self::assertStringContainsString( 'does not trust or parse Content-Length as transport protection', $html );
		self::assertStringContainsString( 'GitHub does not automatically redeliver failed deliveries', $html );
		self::assertStringContainsString( 'enable Request History before you need it', $html );
		self::assertStringContainsString( 'do not assume it remains stable across automatic attempts', $html );
		self::assertStringContainsString( 'Provider request ID shown on a webhook attempt in Booster Activity', $html );
		self::assertStringContainsString( 'Host, Origin, Referer, forwarded headers, reverse DNS, hidden paths and in-WordPress IP checks do not prove webhook identity', $html );
		self::assertStringContainsString( '<h3 id="ran-booster-webhook-cleanup">Retained webhook setup and cleanup</h3>', $html );
		self::assertStringContainsString( 'Switching a package to Published releases does not remove any existing remote webhook or local signing-secret setup', $html );
		self::assertStringContainsString( 'any branch-managed package using the same repository can continue to need the webhook', $html );
		self::assertStringContainsString( 'Remove the remote provider webhook first, then remove only a local signing secret that is no longer used', $html );
		self::assertStringContainsString( 'Never remove an owner-shared secret merely because one package changed source', $html );
		self::assertStringContainsString( 'the verified Remove action can remove the remote hook', $html );
		self::assertStringContainsString( 'then use Manage signing secrets to remove an unused local secret', $html );
		self::assertStringContainsString( 'GitHub webhook settings', $html );
		self::assertStringContainsString( 'Bitbucket webhook settings', $html );
		self::assertStringContainsString( '<summary>Move packages between sites</summary>', $html );
		self::assertStringContainsString( '<summary>Protect your files and know what actually moves</summary>', $html );
		self::assertStringContainsString( '<summary>Credential storage, retention and removal</summary>', $html );
		self::assertStringContainsString( 'id="ran-booster-credential-storage"', $html );
		self::assertStringContainsString( '<h3>Use a different storage location</h3>', $html );
		self::assertStringContainsString( "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', dirname( __DIR__ ) . '/private/ran-booster' );", $html );
		self::assertStringContainsString( "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', dirname( __DIR__ ) . '/private/ran-booster/secrets.json' );", $html );
		self::assertStringContainsString( 'Do not add group access as a workaround', $html );
		self::assertStringContainsString( 'dirname( __DIR__ ) is its parent', $html );
		self::assertStringContainsString( 'do not append public_html again', $html );
		self::assertStringContainsString( 'host-managed execute permission or ACL on an ancestor', $html );
		self::assertStringContainsString( 'move or restore secrets.json, secrets.json.lock and the matching database key together', $html );
		self::assertStringContainsString( '<summary>About RAN Booster</summary>', $html );
		self::assertLessThan( strpos( $html, 'GitHub credentials and access' ), strpos( $html, 'Move packages between sites' ) );
	}

	public function testRendersSanitizedProviderAndGlobalDocumentationSectionsInPlace(): void {
		$providerFilter =
			static function ( array $sections, string $documentationUrl, string $scope ): array {
				self::assertStringContainsString( 'tab=documentation', $documentationUrl );
				self::assertSame( 'site', $scope );
				$sections[] = array(
					'id'      => 'fixture-gh-guide',
					'summary' => 'GitHub fixture guide',
					'content' => '<p>Guide <strong>content</strong><script>alert(1)</script></p>',
				);

				return $sections;
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_after_provider_gh'][] = $providerFilter;
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_documentation_sections_after_provider_gh'][]         = $providerFilter;
		$globalFilter =
			static function ( array $sections ): array {
				$sections[] = array(
					'id'      => 'fixture-global-guide',
					'summary' => 'Project X guide',
					'content' => '<p>Project X content.</p>',
				);

				return $sections;
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_before_about'][] = $globalFilter;
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_documentation_sections_before_about'][]         = $globalFilter;
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertSame( 12, preg_match_all( '/<details\b/', $html ) );
		self::assertStringContainsString( 'id="fixture-gh-guide"', $html );
		self::assertStringContainsString( 'GitHub fixture guide', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'Project X guide', $html );
		self::assertLessThan( strpos( $html, 'About RAN Booster' ), strpos( $html, 'Project X guide' ) );
		self::assertLessThan( strpos( $html, 'Bitbucket credentials and access' ), strpos( $html, 'GitHub fixture guide' ) );
	}

	public function testRendersOneOrderedPageWideIndexForTopLevelDocumentationSections(): void {
		$callableCalls = 0;
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_after_provider_gh'][] =
			static function ( array $sections ) use ( &$callableCalls ): array {
				$sections[] = array(
					'id'      => 'addon-guide',
					'summary' => 'Add-on <guide>',
					'content' => static function () use ( &$callableCalls ): void {
						++$callableCalls;
						echo '<p>Add-on content.</p>';
					},
				);
				$sections[] = array(
					'id'      => 'addon-guide',
					'summary' => 'Duplicate guide',
					'content' => '<p>Ignored.</p>',
				);
				$sections[] = array(
					'id'      => 'ran-booster-about',
					'summary' => 'Conflicting guide',
					'content' => '<p>Ignored.</p>',
				);
				$sections[] = array(
					'id'      => 'ran-booster-documentation-provider-bb',
					'summary' => 'Provider conflict',
					'content' => '<p>Ignored.</p>',
				);
				$sections[] = array(
					'id'      => 'ran-booster-documentation-heading',
					'summary' => 'Documentation heading conflict',
					'content' => '<p>Ignored.</p>',
				);
				$sections[] = array(
					'id'      => 'ran-booster-documentation-index-heading',
					'summary' => 'Index heading conflict',
					'content' => '<p>Ignored.</p>',
				);
				$sections[] = array(
					'id'      => 'ran-booster-webhook-cleanup',
					'summary' => 'Webhook cleanup conflict',
					'content' => '<p>Ignored.</p>',
				);
				$sections[] = array(
					'id'      => 'ran-booster-documentation-webhook-gh',
					'summary' => 'Provider webhook conflict',
					'content' => '<p>Ignored.</p>',
				);
				$sections[] = array(
					'id'      => 'empty-guide',
					'summary' => 'Empty guide',
					'content' => '',
				);

				return $sections;
			};

		$html = $this->renderView( $this->providerDocumentation() );

		self::assertSame( 1, $callableCalls );
		self::assertStringContainsString( '<aside class="ran-booster-documentation__index ran-booster-panel" data-ran-booster-documentation-index>', $html );
		self::assertStringContainsString( '<h2 id="ran-booster-documentation-index-heading" class="ran-booster-documentation__index-heading">On this page</h2>', $html );
		self::assertStringContainsString( '<p class="ran-booster-tile ran-booster-documentation__search-hint"><span class="ran-booster-tile__label">Search this page with</span> <kbd>⌘F</kbd> <span>or</span> <kbd>Ctrl+F</kbd></p>', $html );
		self::assertSame( 1, preg_match_all( '/href="#addon-guide"/', $html ) );
		self::assertSame( 1, preg_match_all( '/id="addon-guide"/', $html ) );
		self::assertStringContainsString( 'Add-on &lt;guide&gt;', $html );
		self::assertStringNotContainsString( 'Duplicate guide', $html );
		self::assertStringNotContainsString( 'Conflicting guide', $html );
		self::assertStringNotContainsString( 'Provider conflict', $html );
		self::assertStringNotContainsString( 'Documentation heading conflict', $html );
		self::assertStringNotContainsString( 'Index heading conflict', $html );
		self::assertStringNotContainsString( 'Webhook cleanup conflict', $html );
		self::assertStringNotContainsString( 'Provider webhook conflict', $html );
		self::assertStringNotContainsString( 'href="#ran-booster-documentation-heading"', $html );
		self::assertStringNotContainsString( 'href="#ran-booster-documentation-index-heading"', $html );
		self::assertStringNotContainsString( 'href="#ran-booster-webhook-cleanup"', $html );
		self::assertStringNotContainsString( 'href="#ran-booster-documentation-webhook-gh"', $html );
		self::assertStringNotContainsString( 'Empty guide', $html );
		self::assertLessThan( strpos( $html, 'href="#addon-guide"' ), strpos( $html, 'href="#ran-booster-documentation-provider-gh"' ) );
		self::assertLessThan( strpos( $html, 'href="#ran-booster-documentation-provider-bb"' ), strpos( $html, 'href="#addon-guide"' ) );
		self::assertLessThan( strpos( $html, 'ran-booster-page-shell ran-booster-panel ran-booster-documentation' ), strpos( $html, 'data-ran-booster-documentation-index' ) );
	}

	public function testStatesTheCurrentSingleSiteSupportBoundaryWithoutBetaOrVersionJargon(): void {
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertStringContainsString( '<strong>Current limitation:</strong>', $html );
		self::assertStringContainsString( 'This plugin currently supports single-site WordPress installations only', $html );
		self::assertStringContainsString( 'Multisite and network activation are not yet supported', $html );
		self::assertStringNotContainsString( 'diagnostics SDK', $html );
		self::assertStringNotContainsString( 'Beta', $html );
		self::assertStringNotContainsString( 'beta', $html );
		self::assertStringNotContainsString( 'V1', $html );
	}

	public function testRendersActionableInternalAndProviderOwnedOfficialLinksSafely(): void {
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertStringContainsString( 'page=ran-booster-plugins-create', $html );
		self::assertStringContainsString( 'page=ran-booster-themes-create', $html );
		self::assertStringContainsString( 'page=ran-booster&amp;tab=troubleshooting', $html );
		self::assertStringContainsString( 'page=ran-booster&amp;tab=gh', $html );
		self::assertStringContainsString( 'page=ran-booster&amp;tab=bb', $html );
		self::assertStringContainsString( 'https://docs.github.com/tokens?mode=&quot;safe&quot;', $html );
		self::assertSame( substr_count( $html, 'target="_blank"' ), substr_count( $html, 'rel="noopener noreferrer"' ) );
		self::assertStringNotContainsString( 'canary-secret', $html );
	}

	public function testQuickStartProviderLinksHaveEqualButtonWeight(): void {
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertSame(
			1,
			preg_match(
				'/<ul class="ran-booster-documentation__inline-links">.*?<a class="button" href="[^"]+tab=gh">GitHub<\/a>.*?<a class="button" href="[^"]+tab=bb">Bitbucket<\/a>.*?<\/ul>/s',
				$html,
				$quickStartLinks
			)
		);
		self::assertSame( 2, substr_count( $quickStartLinks[0], 'class="button"' ) );
		self::assertStringNotContainsString( 'button-primary', $quickStartLinks[0] );
		self::assertLessThan( strpos( $html, 'Add private access only if needed.' ), strpos( $html, 'Install a package.' ) );
		self::assertStringContainsString( 'No credential is required.', $html );
	}

	public function testDocumentsProjectLineageWithoutOverstatingTheFork(): void {
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertStringContainsString( '<summary>About RAN Booster</summary>', $html );
		self::assertStringContainsString( '<h3>Where this came from</h3>', $html );
		self::assertStringContainsString( 'modified derivative of WP Pusher 3.0.13', $html );
		self::assertStringContainsString( 'WP Pusher was created by Peter Suhm', $html );
		self::assertStringContainsString( 'inherited source was distributed under GPLv2', $html );
		self::assertStringContainsString( 'We are grateful for Peter’s original work', $html );
		self::assertStringContainsString( 'changed ownership in 2021 and is a separate product', $html );
		self::assertStringContainsString( 'independent fork maintained by Rockets Are Nostalgic', $html );
		self::assertStringContainsString( 'not affiliated with or endorsed by Peter Suhm, WP Pusher or its owners', $html );
		self::assertStringContainsString( 'NOTICE.md and license.txt', $html );
		self::assertStringContainsString( '<h3>Why we built this</h3>', $html );
		self::assertStringContainsString( 'depended on a vendor-hosted licensing, OAuth and repository-picker service', $html );
		self::assertStringContainsString( 'Every credential, webhook and deployment decision now lives on your own site, not a third party', $html );
		self::assertStringContainsString( '<h3>What’s different</h3>', $html );
		self::assertStringContainsString( '<strong>Safety.</strong>', $html );
		self::assertStringContainsString( '<strong>Transporter.</strong>', $html );
		self::assertStringContainsString( '<strong>Independence &amp; Security.</strong>', $html );
		self::assertStringContainsString( 'the inherited codebase stored a private repository’s access token as a WordPress option in the database', $html );
		self::assertStringContainsString( 'Booster instead saves your repository credential in a file on your own site', $html );
		self::assertStringNotContainsString( 'Booster instead saves your personal access token', $html );
		self::assertStringContainsString( '<strong>Extensibility.</strong>', $html );
		self::assertStringContainsString( 'documented registration hook without modifying Booster itself', $html );
		self::assertStringContainsString( 'does not build, test or make untrusted repository code safe', $html );
		self::assertLessThan( strpos( $html, 'Why we built this' ), strpos( $html, 'Where this came from' ) );
		self::assertStringNotContainsString( 'abandoned', $html );
		self::assertStringNotContainsString( 'insecure', $html );
	}

	public function testEscapesProviderTextAndProvidesMissingSetupFallback(): void {
		$documentation   = $this->providerDocumentation();
		$documentation[] = array(
			'code'            => 'gl',
			'label'           => 'GitLab <script>alert(1)</script>',
			'setup_available' => false,
			'credentials'     => array(
				'summary' => 'Guidance <strong>pending</strong>.',
				'links'   => array(),
			),
			'webhook'         => null,
		);

		$html = $this->renderView( $documentation );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<strong>pending</strong>', $html );
		self::assertStringContainsString( 'GitLab &lt;script&gt;alert(1)&lt;/script&gt; credentials and access', $html );
		self::assertStringContainsString( 'Guidance &lt;strong&gt;pending&lt;/strong&gt;.', $html );
		self::assertStringContainsString( 'enter repository details manually', $html );
	}

	public function testStatesTheCredentialFileLocationRetentionAndRemovalContractAccurately(): void {
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertStringContainsString( '<code>RAN_BOOSTER_ENCRYPTED_SECRETS_DIR</code>', $html );
		self::assertStringContainsString( '<code>RAN_BOOSTER_ENCRYPTED_SECRETS_FILE</code>', $html );
		self::assertStringContainsString( "dirname( __DIR__ ) . '/private/ran-booster'", $html );
		self::assertStringContainsString( 'Raw unanchored relative strings are rejected', $html );
		self::assertStringContainsString( 'authenticated ciphertext in a private JSON file', $html );
		self::assertStringContainsString( 'private directory outside the public web root on durable local storage', $html );
		self::assertStringContainsString( 'directory must be owned by PHP, readable and writable by PHP, and mode 0700', $html );
		self::assertStringContainsString( '<code>ran_booster_secrets_key_v1</code>', $html );
		self::assertStringContainsString( 'neither the filesystem nor database alone contains usable credentials', $html );
		self::assertStringContainsString( 'matching encrypted file and database key from the same backup', $html );
		self::assertStringContainsString( 'Saved credentials use authenticated encryption', $html );
		self::assertStringContainsString( 'A plugin ZIP export or a database-only site migration cannot recreate the encrypted store', $html );
		self::assertStringContainsString( 're-encrypts them with the target site’s key', $html );
		self::assertStringContainsString( 'Constants, webhook secrets and credentials already saved on the target are never exported', $html );
		self::assertStringContainsString( 'the supported deployment constants instead', $html );
		self::assertStringContainsString( 'exclude the file from version control and from any release archive', $html );
		self::assertStringContainsString( 'never displays a saved secret back to you once it is stored', $html );
		self::assertStringContainsString( 'Deactivating Booster keeps all credentials, package settings and deployment history in place', $html );
		self::assertStringContainsString( 'Deleting Booster through WordPress is the permanent local cleanup action', $html );
		self::assertStringContainsString( 'removes both Booster custom tables, the encrypted credentials file and its separate database key', $html );
		self::assertStringContainsString( 'WordPress keeps the plugin files', $html );
		self::assertStringContainsString( 'export a password-protected Transporter Blueprint with the selected packages and any file-stored repository credentials they use', $html );
		self::assertStringContainsString( 'does not include webhook secrets, provider-side webhook registrations, deployment history or constants', $html );
		self::assertStringContainsString( 'starts with deployment Disabled', $html );
		self::assertStringContainsString( 'Uninstall cannot revoke provider credentials or remove remote webhooks', $html );
		self::assertStringContainsString( 'removes only the exact wp-config.php definition it created', $html );
		self::assertStringContainsString( 'remains under the site operator’s control', $html );
		self::assertStringNotContainsString( 'gitignored', $html );
		self::assertStringNotContainsString( 'sidecar', $html );
		self::assertStringNotContainsString( 'not encryption', $html );
	}

	public function testLeadsWithThePortabilityHappyPathAndDevelopmentSafety(): void {
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertStringContainsString( 'id="ran-booster-portability-guidance"', $html );
		self::assertStringContainsString( 'Minimum credential permissions', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster-transporter">Open Transporter</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster-transporter">Open migration tools</a>', $html );
		self::assertStringContainsString( 'Move managed packages without copying a development checkout', $html );
		self::assertStringContainsString( 'local uncommitted changes, and local-only', $html );
		self::assertStringContainsString( '<code>node_modules</code>', $html );
		self::assertStringContainsString( 'Before a database or environment move', $html );
		self::assertStringContainsString( 'Export a current Transporter Blueprint containing every managed package', $html );
		self::assertStringContainsString( 'Optionally include the selected packages’ file-stored repository credentials in the password-protected archive', $html );
		self::assertStringContainsString( 'Preview the ZIP successfully and retain a copy off-site', $html );
		self::assertStringContainsString( 'Keep the normal database and filesystem backup', $html );
		self::assertStringContainsString( 'inside the supported MySQL or MariaDB and InnoDB envelope are best effort', $html );
		self::assertStringContainsString( 'target database must still satisfy Booster’s requirements', $html );
		self::assertStringContainsString( 'Deployment attempts and delivery-replay history', $html );
		self::assertStringContainsString( 'webhook secrets and provider-side hooks', $html );
		self::assertStringContainsString( 'locks and worker state', $html );
		self::assertStringContainsString( 'source deployment policy remain target-local', $html );
		self::assertStringContainsString( 'administrator must deliberately choose a new target policy', $html );
		self::assertStringContainsString( 'Commit and push the exact branch, tag or commit', $html );
		self::assertStringContainsString( '<details id="ran-booster-wp-pusher-migration"', $html );
		self::assertStringContainsString( '<summary>Migrate from WP Pusher</summary>', $html );
		self::assertStringContainsString( 'temporary RAN Booster WP Pusher Migrator can adopt supported installed plugins and themes', $html );
		self::assertStringContainsString( 'WP Pusher credentials are not copied', $html );
		self::assertStringContainsString( 'Open Transporter and choose Migrate from WP Pusher', $html );
		self::assertStringContainsString( 'does not copy credentials, reinstall package files, enable deployments', $html );
		self::assertLessThan(
			strpos( $html, '<summary>Migrate from WP Pusher</summary>' ),
			strpos( $html, '<summary>Move packages between sites</summary>' )
		);
		self::assertStringContainsString( 'set it to Disabled first', $html );
		self::assertStringContainsString( '<strong>Disabled</strong>', $html );
		self::assertStringContainsString( '<strong>Manual</strong>', $html );
		self::assertStringContainsString( '<strong>Automatic</strong>', $html );
		self::assertStringContainsString( 'Booster never changes a package', $html );
		self::assertStringContainsString( 'WP_DEBUG is enabled', $html );
		self::assertStringContainsString( 'localhost or a loopback address', $html );
		self::assertStringContainsString( 'URL has a nonstandard port', $html );
		self::assertStringContainsString( 'it starts with deployment Disabled', $html );
		self::assertStringContainsString( 'Push-to-Deploy is always configured separately on each site', $html );
		self::assertStringContainsString( 'Use the matching provider tab as the source of truth', $html );
		self::assertStringContainsString( 'Follow that provider’s webhook instructions', $html );
		self::assertStringContainsString( 'only then deliberately set the installed package to Automatic', $html );
		self::assertStringNotContainsString( 'For GitHub, choose an owner-shared secret', $html );
		self::assertStringNotContainsString( 'Webhooks: Read and write permission', $html );
		self::assertStringNotContainsString( 'Assisted Hooks never enables Automatic deployment', $html );
		self::assertStringContainsString( 'Booster installs exactly what is committed to the repository ref you select', $html );
		self::assertStringContainsString( 'the repository', $html );
		self::assertStringContainsString( 'Include compiled or built assets and anything the package needs at runtime', $html );
		self::assertStringContainsString( 'is normally only needed to build the package, so it should usually be excluded from the ref', $html );
		self::assertStringContainsString( '<code>vendor</code>', $html );
		self::assertStringContainsString( 'Step-by-step: moving a package to another site', $html );
		self::assertStringContainsString( 'Choose which managed plugins and themes to include', $html );
		self::assertStringContainsString( 'select only the actions you want Booster to apply', $html );
		self::assertStringContainsString( 'Excluded and unchecked packages remain untouched', $html );
		self::assertStringContainsString( 'select Review Transporter Blueprint', $html );
		self::assertStringContainsString( 're-reads the ZIP and re-checks each package and repository', $html );
		self::assertStringContainsString( 'Installing or adopting a package associates the imported credential with that package', $html );
		self::assertStringContainsString( 'Credential-only recovery for an already-managed package imports the credential under a new target-local ID', $html );
		self::assertStringContainsString( 'assign the recovered credential through the package settings if needed', $html );
		self::assertStringContainsString( 'Do not overwrite an existing target site', $html );
		self::assertStringNotContainsString( 'transfer only the intended records', $html );
		self::assertStringNotContainsString( 'V1', $html );
		self::assertStringNotContainsString( 'file-backed', $html );
		self::assertStringNotContainsString( 'sidecar', $html );
		self::assertStringNotContainsString( 'eligible', $html );
	}

	public function testDescribesTheLivePortabilityWorkflow(): void {
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertStringContainsString( '<strong>Transporter workflow:</strong>', $html );
		self::assertStringContainsString( 'Reviewing a Transporter Blueprint never installs anything, changes package settings, or stores the uploaded file', $html );
		self::assertStringContainsString( '>Open Transporter</a>', $html );
		self::assertStringNotContainsString( 'Transporter preview:', $html );
	}

	public function testDocumentsTheEffectiveRepositoryArchivePolicyAndOverride(): void {
		$html = $this->renderView( $this->providerDocumentation() );

		self::assertStringContainsString( '<h3>Large repository archives</h3>', $html );
		self::assertStringContainsString( 'ZIP of the whole repository', $html );
		self::assertStringContainsString( '50 MiB compressed and 200 MiB expanded', $html );
		self::assertStringContainsString( 'Booster default', $html );
		self::assertStringContainsString( "define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 150 * 1024 * 1024 );", $html );
		self::assertStringContainsString( 'manual installs, manual and webhook updates, and package installation from a Transporter Blueprint', $html );
		self::assertStringContainsString( 'adopting an already-installed package do not download a repository archive', $html );
	}

	public function testStaticCopyUsesThePluginTextDomain(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture source is inspected without a WordPress runtime.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/views/documentation.php' );

		self::assertStringContainsString( "esc_html_e( 'Quick start', 'ran-booster' )", $source );
		self::assertStringContainsString( "__( '%s credentials and access', 'ran-booster' )", $source );
		self::assertStringContainsString( "esc_html_e( 'Move packages between sites', 'ran-booster' )", $source );
		self::assertStringContainsString( "esc_html_e( 'Migrate from WP Pusher', 'ran-booster' )", $source );
		self::assertStringContainsString( "esc_html_e( 'About RAN Booster', 'ran-booster' )", $source );
		self::assertStringContainsString( 'ran_booster_documentation_sections_after_provider_', $source );
		self::assertStringContainsString( 'ran_booster_documentation_sections_before_about', $source );
	}

	/**
	 * @param list<array<string, mixed>> $providerDocumentation Display-safe provider guidance.
	 */
	private function renderView( array $providerDocumentation ): string {
		$tabs               = array(
			array(
				'key'   => 'gh',
				'label' => 'GitHub',
				'url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh',
			),
			array(
				'key'   => 'bb',
				'label' => 'Bitbucket',
				'url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=bb',
			),
			array(
				'key'   => 'portability',
				'label' => 'Transporter',
				'url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster-transporter',
			),
			array(
				'key'   => 'troubleshooting',
				'label' => 'Troubleshooting',
				'url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting',
			),
		);
		$documentationUrl   = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation';
		$documentationScope = 'site';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/documentation.php';

		return (string) ob_get_clean();
	}

	/** @return list<array<string, mixed>> */
	private function providerDocumentation(): array {
		return array(
			array(
				'code'            => 'gh',
				'label'           => 'GitHub',
				'setup_available' => true,
				'credentials'     => array(
					'summary' => 'Public repositories need no token. Private repositories need Contents: Read.',
					'links'   => array(
						array(
							'label' => 'GitHub token guidance',
							'url'   => 'https://docs.github.com/tokens?mode="safe"',
						),
					),
				),
				'webhook'         => array(
					'location'                   => 'Repository Settings → Webhooks → Add webhook',
					'event'                      => 'Just the push event',
					'documentation_url'          => 'https://docs.github.com/webhooks',
					'delivery_documentation_url' => 'https://docs.github.com/deliveries',
				),
			),
			array(
				'code'            => 'bb',
				'label'           => 'Bitbucket',
				'setup_available' => true,
				'credentials'     => array(
					'summary' => 'Use a workspace-scoped API token with read:repository:bitbucket.',
					'links'   => array(
						array(
							'label' => 'Bitbucket API token guidance',
							'url'   => 'https://support.atlassian.com/api-tokens',
						),
					),
				),
				'webhook'         => array(
					'location'                   => 'Repository settings → Webhooks → Add webhook',
					'event'                      => 'Repository push',
					'documentation_url'          => 'https://support.atlassian.com/webhooks',
					'delivery_documentation_url' => 'https://support.atlassian.com/deliveries',
				),
			),
		);
	}
}
