<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\CoreSelfUpdateDevelopmentNotice;
use RAN\WordPress\CoreSelfUpdatePolicy;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class AdminIndexViewTest extends TestCase {

	public function test_nozzle_artwork_is_the_expected_release_asset(): void {
		$root  = dirname( __DIR__, 2 );
		$asset = $root . '/assets/booster-nozzle-10364e-cream-transparent.svg';
		self::assertFileExists( $asset );
		self::assertSame( 'c2552a033fb2b62e5151755a746de0be56942430fba8bc501443739155fddbac', hash_file( 'sha256', $asset ) );
		$manifest = file( $root . '/release-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- Inspect the release manifest in this local contract test.
		self::assertIsArray( $manifest );
		self::assertContains( 'assets', $manifest );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_admin_view_year'],
			$GLOBALS['ran_booster_admin_view_plugin_headers'],
			$GLOBALS['ran_booster_package_view_multisite']
		);
	}

	public function test_static_page_renders_scoped_root_labelled_navigation_and_one_page_heading(): void {
		$GLOBALS['ran_booster_admin_view_year']           = '2042';
		$GLOBALS['ran_booster_admin_view_plugin_headers'] = array(
			'author'     => 'Header Author',
			'author_uri' => 'https://example.test/header-author',
		);
		$messages                  = array();
		$name                      = 'RAN Booster';
		$view                      = 'index';
		$development_safety_notice = false;

		$core_self_update_development_notice = new CoreSelfUpdateDevelopmentNotice(
			CoreSelfUpdatePolicy::detect( dirname( __DIR__, 2 ) . '/ran-booster.php', '0.1.0-alpha.23' ),
			'toplevel_page_ran-booster'
		);

		$tab      = 'documentation';
		$tab_view = 'documentation.php';
		$tabs     = array(
			array(
				'key'    => 'overview',
				'label'  => 'Overview',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview',
				'active' => false,
			),
			array(
				'key'    => 'gh',
				'label'  => 'GitHub',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh',
				'active' => false,
			),
			array(
				'key'    => 'bb',
				'label'  => 'Bitbucket',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=bb',
				'active' => false,
			),
			array(
				'key'    => 'portability',
				'label'  => 'Transporter',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability',
				'active' => false,
			),
			array(
				'key'    => 'documentation',
				'label'  => 'Documentation',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation',
				'active' => true,
			),
			array(
				'key'    => 'troubleshooting',
				'label'  => 'Troubleshooting',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting',
				'active' => false,
			),
		);
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<div class="wrap ran-booster-admin" data-ran-booster-shell-navigation>', $html );
		self::assertStringNotContainsString( 'data-ran-booster-feedback-toast', $html );
		self::assertSame( 1, preg_match_all( '/<h1\b/', $html ) );
		self::assertMatchesRegularExpression(
			'~<a class="ran-admin-shell__identity" href="https://example\.test/wp-admin/admin\.php\?page=ran-booster">\s*<img class="ran-admin-shell__logo"[^>]*>\s*<div class="ran-admin-shell__copy">\s*<h1 class="ran-admin-shell__title">Booster</h1>\s*<p class="ran-admin-shell__strapline">Rockets Are Nostalgic</p>\s*</div>\s*</a>~',
			$html
		);
		self::assertStringContainsString( 'class="ran-admin-shell__logo"', $html );
		self::assertStringContainsString( 'assets/booster-nozzle-10364e-cream-transparent.svg" width="32" height="32" alt="" aria-hidden="true"', $html );
		self::assertStringContainsString( '<header class="ran-admin-shell ran-admin-shell--inverse">', $html );
		self::assertSame( 1, substr_count( $html, '<hr class="wp-header-end">' ) );
		self::assertStringContainsString( '<nav class="ran-admin-shell__navigation ran-section-navigation ran-section-navigation--thick" aria-label="RAN Booster sections">', $html );
		self::assertStringNotContainsString( 'nav-tab-wrapper', $html );
		self::assertStringNotContainsString( 'class="nav-tab', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster-plugins">Plugins</a>', $html );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster-themes">Themes</a>', $html );
		self::assertStringNotContainsString( '>Transporter</a>', $html );
		self::assertStringNotContainsString( '>Troubleshooting</a>', $html );
		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		self::assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=documentation" aria-current="page">Documentation', $html );
		self::assertStringNotContainsString( 'aria-current="false"', $html );
		self::assertStringNotContainsString( 'ran-admin-shell__breadcrumbs', $html );
		self::assertStringContainsString( 'id="ran-booster-documentation-heading"', $html );
		self::assertStringNotContainsString( 'Set up GitHub deployments', $html );
		self::assertStringContainsString( 'Rockets Are Nostalgic', $html );
		self::assertStringContainsString( 'Install from a public repository first. Add private access and Push-to-Deploy only when you need them.', $html );
		self::assertStringNotContainsString( 'Prepare your site', $html );
		self::assertStringNotContainsString( 'data-ran-booster-development-safety', $html );
		$strapline_position   = strpos( $html, 'Rockets Are Nostalgic' );
		$navigation_position  = strpos( $html, '<nav class="ran-admin-shell__navigation ran-section-navigation ran-section-navigation--thick"' );
		$core_notice_position = strpos( $html, 'data-ran-booster-core-development-notice' );
		$wrap_position        = strpos( $html, '<div class="wrap ran-booster-admin" data-ran-booster-shell-navigation>' );
		$marker_position      = strpos( $html, '<hr class="wp-header-end">' );
		foreach ( array( $strapline_position, $navigation_position, $core_notice_position, $wrap_position, $marker_position ) as $position ) {
			self::assertIsInt( $position );
		}
		self::assertTrue( $strapline_position < $navigation_position );
		self::assertTrue( $navigation_position < $wrap_position );
		self::assertTrue( $marker_position < $strapline_position );
		self::assertTrue( $marker_position < strpos( $html, '<header class="ran-admin-shell' ) );
		self::assertTrue( $wrap_position < $core_notice_position );
		self::assertStringContainsString( '<div class="ran-admin-shell-notices"><hr class="wp-header-end"></div>', $html );
		self::assertSame( 1, substr_count( $html, 'id="ran-booster-core-development-notice-slot"' ) );
		self::assertStringNotContainsString( 'hx-swap-oob', $html );
		self::assertStringContainsString( 'Copyright © 2042', $html );
		self::assertStringContainsString(
			'<a href="https://example.test/header-author">Header Author</a>',
			$html
		);

		foreach (
			array(
				'  0.1.0-beta.39  '   => 'Booster 0.1.0-beta.39',
				'1.2.3-rc.1+build.42' => 'Booster 1.2.3-rc.1+build.42',
				'<script>&'           => 'Booster &lt;script&gt;&amp;',
			) as $version => $expected
		) {
			$GLOBALS['ran_booster_admin_view_plugin_headers']['version'] = $version;
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/base.php';
			$version_html = (string) ob_get_clean();
			self::assertStringContainsString( $expected, $version_html );
			self::assertMatchesRegularExpression( '/Header Author<\/a>\s*\|\s*Booster /', $version_html );
		}

		foreach ( array( null, '', " \t " ) as $version ) {
			$GLOBALS['ran_booster_admin_view_plugin_headers'] = array( 'author' => 'Fallback Author' );
			if ( null !== $version ) {
				$GLOBALS['ran_booster_admin_view_plugin_headers']['version'] = $version;
			}
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/base.php';
			$without_version = (string) ob_get_clean();
			self::assertMatchesRegularExpression( '/Copyright © 2042\s+Fallback Author\s*<\/p>/', $without_version );
		}

		$GLOBALS['ran_booster_admin_view_plugin_headers'] = array(
			'author'     => 'Header Author',
			'author_uri' => 'https://example.test/header-author',
		);

		$development_safety_notice = false;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$without_notice = (string) ob_get_clean();

		self::assertStringNotContainsString( '<strong>Development safety:</strong>', $without_notice );

		$tab      = 'overview';
		$tab_view = 'onboarding.php';
		foreach ( $tabs as &$admin_tab ) {
			$admin_tab['active'] = 'overview' === $admin_tab['key'];
		}
		unset( $admin_tab );
		$onboarding                          = array(
			'provider_links'      => array(),
			'install_plugin_url'  => '/plugins',
			'install_theme_url'   => '/themes',
			'portability_url'     => '/portability',
			'documentation_url'   => '/documentation',
			'troubleshooting_url' => '/troubleshooting',
		);
		$core_self_update_development_notice = new CoreSelfUpdateDevelopmentNotice(
			CoreSelfUpdatePolicy::detect( dirname( __DIR__, 2 ) . '/ran-booster.php', '0.1.0-alpha.23' ),
			'toplevel_page_ran-booster'
		);
		$local_development_overview          = true;
		$development_safety_notice           = true;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$overview = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $overview, 'data-ran-booster-development-safety' ) );
		self::assertStringContainsString( 'data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="info"><div data-ran-booster-ui-notice-content>', $overview );
		self::assertStringContainsString( 'data-ran-booster-notice-state="local-development-v2"', $overview );
		self::assertStringContainsString( 'class="notice-dismiss" hidden', $overview );
		self::assertStringContainsString( 'Booster’s own updates are disabled to protect this source checkout.', $overview );
		self::assertStringNotContainsString( 'data-ran-booster-core-development-notice>', $overview );
		self::assertStringContainsString( 'tab=documentation#ran-booster-file-protection', $overview );
		$development_safety_notice = false;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$acknowledged = (string) ob_get_clean();
		self::assertStringNotContainsString( 'data-ran-booster-development-safety', $acknowledged );
		self::assertStringNotContainsString( 'data-ran-booster-core-development-notice>', $acknowledged );
		self::assertSame( 1, preg_match_all( '/<h1\b/', $overview ) );
		self::assertSame( 1, substr_count( $overview, 'aria-current="page"' ) );
		self::assertMatchesRegularExpression( '/>Overview(?:<span[^>]*><\/span>)?<\/a>/', $overview );
		self::assertStringContainsString( '<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title">Prepare your site</h2>', $overview );
	}

	public function test_troubleshooting_keeps_top_level_destinations_and_its_peer_navigation(): void {
		$view            = 'index';
		$tab             = 'troubleshooting';
		$tab_view        = 'troubleshooting.php';
		$messages        = array();
		$troubleshooting = array( 'providers' => array() );

		$panels = array( 'installation', 'diagnostics', 'activity', 'debug-capture', 'support-report', 'unknown' );
		foreach ( array( false, true ) as $network ) {
			$GLOBALS['ran_booster_package_view_multisite'] = $network;
			$admin_base                                    = $network ? 'https://example.test/wp-admin/network/admin.php' : 'https://example.test/wp-admin/admin.php';

			$tabs = array();
			foreach ( array(
				'overview'        => 'Overview',
				'gh'              => 'GitHub',
				'portability'     => 'Transporter',
				'documentation'   => 'Documentation',
				'troubleshooting' => 'Troubleshooting',
			) as $key => $label ) {
				$tabs[] = array(
					'key'    => $key,
					'label'  => $label,
					'url'    => $admin_base . '?page=ran-booster&tab=' . $key,
					'active' => 'troubleshooting' === $key,
				);
			}
			foreach ( $panels as $panel ) {
				$troubleshooting_panel = $panel;
				ob_start();
				require dirname( __DIR__, 2 ) . '/views/base.php';
				$html = (string) ob_get_clean();
				$dom  = new \DOMDocument();
				@$dom->loadHTML( $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The HTML5 view contains elements unsupported by the legacy DOM parser.
				$xpath = new \DOMXPath( $dom );
				self::assertSame( 0.0, $xpath->evaluate( 'count(//ol[@class="ran-admin-shell__breadcrumbs"])' ) );
				self::assertSame( 1.0, $xpath->evaluate( 'count(//header/nav[@aria-label="RAN Booster sections"])' ) );
				self::assertSame( 5.0, $xpath->evaluate( 'count(//header/nav[@aria-label="RAN Booster sections"]/a)' ) );
				self::assertSame( 0.0, $xpath->evaluate( 'count(//header/nav[@aria-label="RAN Booster sections"]/a[@aria-current])' ) );
				foreach ( array(
					'Overview'      => '?page=ran-booster&tab=overview',
					'GitHub'        => '?page=ran-booster&tab=gh',
					'Plugins'       => '?page=ran-booster-plugins',
					'Themes'        => '?page=ran-booster-themes',
					'Documentation' => '?page=ran-booster&tab=documentation',
				) as $label => $query ) {
					self::assertSame( $admin_base . $query, $xpath->evaluate( 'string(//header/nav[@aria-label="RAN Booster sections"]/a[text()="' . $label . '"]/@href)' ) );
				}
				self::assertSame( 5.0, $xpath->evaluate( 'count(//nav[@aria-label="Troubleshooting views"]/a)' ) );
				self::assertSame( 1.0, $xpath->evaluate( 'count(//nav[@aria-label="Troubleshooting views"]/a[@aria-current="page"])' ) );
				$current = 'unknown' === $panel ? 'installation' : $panel;
				self::assertSame( $admin_base . '?page=ran-booster&tab=troubleshooting&panel=' . $current, $xpath->evaluate( 'string(//nav[@aria-label="Troubleshooting views"]/a[@aria-current="page"]/@href)' ) );
				self::assertSame( 0.0, $xpath->evaluate( 'count(//nav[@aria-label="Troubleshooting views"]//*[@role="tab"])' ) );
			}
		}
	}

	public function test_provider_collections_use_the_actual_provider_overview_as_breadcrumb_parent(): void {
		$view                               = 'index';
		$tab_view                           = 'provider.php';
		$messages                           = array();
		$provider_management_table_renderer = new \RAN\Admin\Component\ProviderManagementTableRenderer();
		$presenter                          = ( new \ReflectionClass( \RAN\Admin\ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor();
		foreach ( array(
			'gh'      => 'GitHub',
			'bb'      => 'Bitbucket',
			'fixture' => 'Forge & <Fixture>',
		) as $tab => $provider_label ) {
			$tabs     = array();
			$provider = array(
				'code'             => $tab,
				'label'            => $provider_label,
				'credential_kinds' => array(),
				'webhook_scopes'   => array(),
				'capabilities'     => array(
					'package'     => true,
					'browse'      => false,
					'credentials' => false,
					'webhooks'    => false,
				),
			);
			foreach ( array(
				'credentials' => 'Manage Credentials',
				'secrets'     => 'Manage Webhook Secrets',
			) as $provider_view => $current_label ) {
				$data = array(
					'provider'            => $provider,
					'provider_view'       => $provider_view,
					'provider_list_state' => array(
						'search'   => '',
						'kind'     => '',
						'scope'    => '',
						'status'   => '',
						'orderby'  => 'name',
						'order'    => 'asc',
						'paged'    => 1,
						'per_page' => 20,
					),
				);
				$data = array_merge( $data, $presenter->build_profile_list_projection( $data ), ( new \RAN\Admin\ProviderRepositoryRowsNormalizer() )->project_page( $data ) );
				// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Fixed fixture locals mirror the real Dashboard provider presentation.
				extract( $data );
				ob_start();
				require dirname( __DIR__, 2 ) . '/views/base.php';
				$html = (string) ob_get_clean();
				$dom  = new \DOMDocument();
				@$dom->loadHTML( $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Legacy DOM parser does not recognize HTML5 view elements.
				$xpath = new \DOMXPath( $dom );
				self::assertSame( $provider_label, $xpath->evaluate( 'string(//ol[@class="ran-admin-shell__breadcrumbs"]//a)' ) );
				self::assertSame( $data['overview_url'], $xpath->evaluate( 'string(//ol[@class="ran-admin-shell__breadcrumbs"]//a/@href)' ) );
				self::assertSame( $current_label, $xpath->evaluate( 'string(//ol[@class="ran-admin-shell__breadcrumbs"]//span[@aria-current="page"])' ) );
				self::assertSame( 1.0, $xpath->evaluate( 'count(//ol[@class="ran-admin-shell__breadcrumbs"]//a)' ) );
				self::assertSame( 0.0, $xpath->evaluate( 'count(//ol[@class="ran-admin-shell__breadcrumbs"]//a[@aria-current])' ) );
				self::assertSame( 'credentials' === $provider_view ? 'Credentials' : 'Webhook secrets', $xpath->evaluate( 'string(//*[@id="ran-booster-provider-heading"])' ) );
			}
			$tab_view = 'documentation.php';
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/base.php';
			$documentation_html = (string) ob_get_clean();
			self::assertStringNotContainsString( 'ran-admin-shell__breadcrumbs', $documentation_html );
			$tab_view = 'provider.php';

		}
	}

	public function test_repository_details_use_the_provider_repository_list_as_breadcrumb_parent(): void {
		$view                               = 'index';
		$tab_view                           = 'provider.php';
		$messages                           = array();
		$provider_management_table_renderer = new \RAN\Admin\Component\ProviderManagementTableRenderer();
		$repository_detail_renderer         = new \RAN\Admin\Component\RepositoryDetailRenderer();
		$webhook_management                 = null;
		$presenter                          = ( new \ReflectionClass( \RAN\Admin\ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor();
		foreach ( array(
			'gh'      => 'GitHub',
			'fixture' => 'Forge & <Fixture>',
		) as $tab => $provider_label ) {
			$provider = array(
				'code'             => $tab,
				'label'            => $provider_label,
				'credential_kinds' => array(),
				'webhook_scopes'   => array(),
				'capabilities'     => array(
					'package'     => true,
					'browse'      => false,
					'credentials' => false,
					'webhooks'    => false,
				),
			);
			foreach ( array(
				'found'       => array( 'owner/repository', true ),
				'unavailable' => array( 'Repository unavailable', false ),
			) as $case => list( $current_label, $has_row ) ) {
				$data = array(
					'provider'                => $provider,
					'provider_view'           => 'overview',
					'provider_task'           => 'repositories',
					'requested_repository_id' => 'repo-42',
					'provider_list_state'     => array(
						'search'   => '',
						'kind'     => '',
						'scope'    => '',
						'status'   => '',
						'orderby'  => 'name',
						'order'    => 'asc',
						'paged'    => 1,
						'per_page' => 20,
					),
				);
				$data = array_merge( $data, $presenter->build_profile_list_projection( $data ), ( new \RAN\Admin\ProviderRepositoryRowsNormalizer() )->project_page( $data ) );
				if ( $has_row ) {
					$data['selected_repository_row'] = array(
						'repository'    => 'owner/repository',
						'repository_id' => 'repo-42',
						'source_label'  => 'Branch',
						'source_key'    => 'branch',
					);
				}
				// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Fixed fixture locals mirror the real Dashboard provider presentation.
				extract( $data );
				ob_start();
				require dirname( __DIR__, 2 ) . '/views/base.php';
				$html = (string) ob_get_clean();
				$dom  = new \DOMDocument();
				@$dom->loadHTML( $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Legacy DOM parser does not recognize HTML5 view elements.
				$xpath = new \DOMXPath( $dom );
				$crumb = '//ol[@class="ran-admin-shell__breadcrumbs"]';
				self::assertSame( 2.0, $xpath->evaluate( 'count(' . $crumb . '//a)' ), $case );
				self::assertSame( $provider_label, $xpath->evaluate( 'string((' . $crumb . '//a)[1])' ), $case );
				self::assertSame( $data['overview_url'], $xpath->evaluate( 'string((' . $crumb . '//a)[1]/@href)' ), $case );
				self::assertSame( 'Repositories', $xpath->evaluate( 'string((' . $crumb . '//a)[2])' ), $case );
				self::assertSame( $data['repository_list_url'], $xpath->evaluate( 'string((' . $crumb . '//a)[2]/@href)' ), $case );
				self::assertSame( $current_label, $xpath->evaluate( 'string(' . $crumb . '//span[@aria-current="page"])' ), $case );
				self::assertSame( 0.0, $xpath->evaluate( 'count(' . $crumb . '//a[@aria-current])' ), $case );
				self::assertStringNotContainsString( 'Back to repositories', $html, $case );
				self::assertStringNotContainsString( 'ran-booster-repository-detail__back', $html, $case );
			}

			$data['requested_repository_id'] = '';
			$data                            = array_merge( $data, ( new \RAN\Admin\ProviderRepositoryRowsNormalizer() )->project_page( $data ) );
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Fixed fixture locals mirror the real Dashboard provider presentation.
			extract( $data );
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/base.php';
			$list_html = (string) ob_get_clean();
			self::assertStringNotContainsString( 'ran-admin-shell__breadcrumbs', $list_html );
		}
	}

	public function test_package_recovery_uses_the_managed_list_as_breadcrumb_parent(): void {
		$view     = 'packages/recovery';
		$messages = array();
		foreach ( array( \RAN\Admin\PackagePagePresenter::plugin(), \RAN\Admin\PackagePagePresenter::theme() ) as $package_view ) {
			$package_return_list   = array(
				's'        => 'release',
				'provider' => 'gh',
				'paged'    => '2',
			);
			$recovery_identifier   = 'example/example.php';
			$recovery_name         = 'Example <package>';
			$recovery_state        = 'blocked';
			$recovery_reason       = 'A safely bounded explanation.';
			$recovery_blocker      = null;
			$recovery_confirmation = null;
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Fixed fixture locals mirror the real Dashboard package presentation.
			extract( compact( 'package_view', 'package_return_list', 'recovery_identifier', 'recovery_name', 'recovery_state', 'recovery_reason', 'recovery_blocker', 'recovery_confirmation' ) );
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/base.php';
			$html = (string) ob_get_clean();
			$dom  = new \DOMDocument();
			@$dom->loadHTML( $html ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Legacy DOM parser does not recognize HTML5 view elements.
			$xpath = new \DOMXPath( $dom );
			$crumb = '//ol[@class="ran-admin-shell__breadcrumbs"]';
			$type  = $package_view->get_type();
			self::assertSame( 1.0, $xpath->evaluate( 'count(' . $crumb . '//a)' ), $type );
			self::assertSame( $package_view->get_plural_label(), $xpath->evaluate( 'string(' . $crumb . '//a)' ), $type );
			self::assertStringContainsString( 'page=' . $package_view->get_page_slug(), $xpath->evaluate( 'string(' . $crumb . '//a/@href)' ), $type );
			foreach ( array( 's=release', 'provider=gh', 'paged=2' ) as $context ) {
				self::assertStringContainsString( $context, $xpath->evaluate( 'string(' . $crumb . '//a/@href)' ), $type );
			}
			self::assertSame( $recovery_name, $xpath->evaluate( 'string(' . $crumb . '//span[@aria-current="page"])' ), $type );
			self::assertStringNotContainsString( 'Back to Managed', $html, $type );
			self::assertStringNotContainsString( 'ran-booster-package-settings__back', $html, $type );
		}
	}
}
