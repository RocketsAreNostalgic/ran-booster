<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once __DIR__ . '/../Support/DocumentationHookWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/DocumentationHookRenderer.php';

final class DocumentationViewTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_documentation_test_actions'] = array();
		$GLOBALS['ran_booster_documentation_test_filters'] = array();
		$GLOBALS['ran_booster_admin_view_filters']         = array();
		$GLOBALS['ran_booster_admin_test_translations']    = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_documentation_test_actions'], $GLOBALS['ran_booster_documentation_test_filters'], $GLOBALS['ran_booster_admin_view_filters'] );
	}

	public function test_omits_redundant_page_label_without_changing_documentation_navigation(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Guidance'] = 'Conseils';

		$html = $this->render_view( $this->provider_documentation() );

		self::assertStringNotContainsString( '>Conseils</p>', $html );
		self::assertStringContainsString( 'class="ran-booster-documentation__index-link"', $html );
		self::assertStringContainsString( 'id="ran-booster-documentation-heading"', $html );
	}

	public function test_renders_eleven_native_disclosure_sections_with_only_quick_start_open(): void {
		$html = $this->render_view( $this->provider_documentation() );

		self::assertStringContainsString( 'class="ran-booster-documentation__layout"', $html );
		self::assertStringContainsString( 'class="ran-booster-documentation__main"', $html );
		self::assertStringContainsString( 'class="ran-booster-page-shell__header ran-booster-documentation__header"', $html );
		self::assertStringContainsString( 'class="ran-booster-page-shell__body"', $html );
		self::assertSame( 11, preg_match_all( '/<details\b/', $html ) );
		self::assertSame( 1, preg_match_all( '/<details\b[^>]*\bopen\b/', $html ) );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath          = new \DOMXPath( $document );
		$heading_panels = $xpath->query( '//*[@id="ran-booster-documentation-heading"]/ancestor::*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-panel ")]' );
		$quick_start    = $xpath->query( '//details[@id="ran-booster-quick-start"][contains(concat(" ", normalize-space(@class), " "), " ran-booster-panel ")][@open]' );
		$panels         = $xpath->query( '//details[@data-ran-booster-documentation-section][contains(concat(" ", normalize-space(@class), " "), " ran-booster-panel ")]' );
		self::assertInstanceOf( \DOMNodeList::class, $heading_panels );
		self::assertInstanceOf( \DOMNodeList::class, $quick_start );
		self::assertInstanceOf( \DOMNodeList::class, $panels );
		self::assertCount( 0, $heading_panels );
		self::assertCount( 1, $quick_start );
		self::assertCount( 11, $panels );
		foreach ( $panels as $panel ) {
			self::assertInstanceOf( \DOMElement::class, $panel );
			self::assertSame( 'ran-booster-documentation', $panel->getAttribute( 'name' ) );
			self::assertStringContainsString( 'ran-booster-disclosure', $panel->getAttribute( 'class' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(./div[contains(concat(" ", normalize-space(@class), " "), " ran-booster-disclosure__body ")])', $panel ) );
		}
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
		self::assertStringNotContainsString( 'GitHub does not automatically redeliver failed deliveries', $html );
		self::assertStringContainsString( 'enable Request History before you need it', $html );
		self::assertStringContainsString( 'do not assume it remains stable across automatic attempts', $html );
		self::assertStringContainsString( 'Git provider request ID shown on a webhook attempt in Booster Activity', $html );
		self::assertStringContainsString( 'Host, Origin, Referer, forwarded headers, reverse DNS, hidden paths and in-WordPress IP checks do not prove webhook identity', $html );
		self::assertStringContainsString( '<h3 id="ran-booster-webhook-cleanup">Retained webhook setup and cleanup</h3>', $html );
		self::assertStringContainsString( 'Switching a package to Published releases does not remove any existing remote webhook or local signing-secret setup', $html );
		self::assertStringContainsString( 'any branch-managed package using the same repository can continue to need the webhook', $html );
		self::assertStringContainsString( 'Remove the remote Git provider webhook first, then remove only a local signing secret that is no longer used', $html );
		self::assertStringContainsString( 'Never remove an owner-shared secret merely because one package changed source', $html );
		self::assertStringNotContainsString( 'the verified Remove action can remove the remote hook', $html );
		self::assertStringContainsString( 'then use Manage signing secrets to remove an unused local secret', $html );
		self::assertStringContainsString( 'GitHub webhook settings', $html );
		self::assertStringContainsString( 'Bitbucket webhook settings', $html );
		self::assertStringContainsString( '<summary>Move packages between sites</summary>', $html );
		self::assertStringContainsString( '<summary>Protect your files and know what actually moves</summary>', $html );
		self::assertStringContainsString( '<summary>Credential storage, retention and removal</summary>', $html );
		self::assertStringContainsString( 'id="ran-booster-credential-storage"', $html );
		self::assertStringContainsString( '<h3>Use a different storage location</h3>', $html );
		self::assertStringContainsString( "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', dirname( __DIR__ ) . '/private/ran-booster' );", $html );
		self::assertStringNotContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', $html );
		self::assertStringContainsString( 'Do not add group access as a workaround', $html );
		self::assertStringContainsString( 'dirname( __DIR__ ) is its parent', $html );
		self::assertStringContainsString( 'do not append public_html again', $html );
		self::assertStringContainsString( 'host-managed execute permission or ACL on an ancestor', $html );
		self::assertStringContainsString( 'move or restore secrets.json, secrets.json.lock and the matching database key together', $html );
		self::assertStringContainsString( '<summary>About RAN Booster</summary>', $html );
		self::assertLessThan( strpos( $html, 'GitHub credentials and access' ), strpos( $html, 'Move packages between sites' ) );
	}

	public function test_renders_sanitized_provider_and_global_documentation_sections_in_place(): void {
		$provider_documentation = $this->provider_documentation();
		$expected_metadata      = $provider_documentation[0]['metadata'];
		$received_metadata      = null;
		$provider_filter        =
			static function ( array $sections, string $documentation_url, string $scope, ?ProviderMetadata $provider_metadata ) use ( &$received_metadata ): array {
				self::assertStringContainsString( 'tab=documentation', $documentation_url );
				self::assertSame( 'site', $scope );
				$received_metadata = $provider_metadata;
				$sections[]        = array(
					'id'      => 'fixture-gh-guide',
					'summary' => 'GitHub fixture guide',
					'content' => '<p>Guide <strong>content</strong><script>alert(1)</script></p>',
				);

				return $sections;
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_after_provider_gh'][] = $provider_filter;
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_documentation_sections_after_provider_gh'][]         = $provider_filter;
		$global_filter =
			static function ( array $sections ): array {
				$sections[] = array(
					'id'      => 'fixture-global-guide',
					'summary' => 'Project X guide',
					'content' => '<p>Project X content.</p>',
				);

				return $sections;
			};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_before_about'][] = $global_filter;
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_documentation_sections_before_about'][]         = $global_filter;
		$html = $this->render_view( $provider_documentation );

		self::assertSame( 13, preg_match_all( '/<details\b/', $html ) );
		self::assertSame( 13, preg_match_all( '/<details\b[^>]*\bname="ran-booster-documentation"/', $html ) );
		self::assertStringContainsString( 'id="fixture-gh-guide"', $html );
		self::assertStringContainsString( 'GitHub fixture guide', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'Project X guide', $html );
		self::assertLessThan( strpos( $html, 'About RAN Booster' ), strpos( $html, 'Project X guide' ) );
		self::assertLessThan( strpos( $html, 'Bitbucket credentials and access' ), strpos( $html, 'GitHub fixture guide' ) );
		self::assertSame( $expected_metadata, $received_metadata );
	}


	public function test_only_first_requested_add_on_section_is_initially_open(): void {
		$provider_filter = static function ( array $sections ): array {
			$sections[] = array(
				'id'      => 'first-open',
				'summary' => 'First open',
				'content' => '<p>First.</p>',
				'open'    => true,
			);
			$sections[] = array(
				'id'      => 'second-open',
				'summary' => 'Second open',
				'content' => '<p>Second.</p>',
				'open'    => true,
			);
			return $sections;
		};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_after_provider_gh'][] = $provider_filter;
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_documentation_sections_after_provider_gh'][]         = $provider_filter;
		$html     = $this->render_view( $this->provider_documentation() );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		$open  = $xpath->query( '//details[@name="ran-booster-documentation"][@open]' );
		self::assertInstanceOf( \DOMNodeList::class, $open );
		self::assertCount( 1, $open );
		self::assertInstanceOf( \DOMElement::class, $open->item( 0 ) );
		self::assertSame( 'first-open', $open->item( 0 )->getAttribute( 'id' ) );
	}

	public function test_renders_one_ordered_page_wide_index_for_top_level_documentation_sections(): void {
		$callable_calls = 0;
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_after_provider_gh'][] =
			static function ( array $sections ) use ( &$callable_calls ): array {
				$sections[] = array(
					'id'      => 'addon-guide',
					'summary' => 'Add-on <guide>',
					'content' => static function () use ( &$callable_calls ): void {
						++$callable_calls;
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

		$html = $this->render_view( $this->provider_documentation() );

		self::assertSame( 1, $callable_calls );
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
		self::assertLessThan( strpos( $html, 'data-ran-booster-documentation-index' ), strpos( $html, 'id="ran-booster-documentation-heading"' ) );
	}

	public function test_keeps_quick_start_actionable_and_moves_unchanged_requirements_into_the_next_chapter(): void {
		$html     = $this->render_view( $this->provider_documentation() );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath        = new \DOMXPath( $document );
		$quick_start  = $document->getElementById( 'ran-booster-quick-start' );
		$requirements = $document->getElementById( 'ran-booster-requirements' );
		self::assertInstanceOf( \DOMElement::class, $quick_start );
		self::assertInstanceOf( \DOMElement::class, $requirements );
		self::assertSame( 'details', $xpath->evaluate( 'local-name(.)', $requirements ) );
		self::assertFalse( $requirements->hasAttribute( 'open' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(following-sibling::*[1][@id="ran-booster-requirements"])', $quick_start ) );
		self::assertSame( 3.0, $xpath->evaluate( 'count(div/ol/li)', $quick_start ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(div/p)', $quick_start ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(div/p/a[@href="#ran-booster-requirements"])', $quick_start ) );
		$quick_start_text  = $xpath->evaluate( 'string(.)', $quick_start );
		$requirements_text = $xpath->evaluate( 'string(.)', $requirements );
		self::assertIsString( $quick_start_text );
		self::assertIsString( $requirements_text );
		self::assertStringContainsString( 'Install a package.', $quick_start_text );
		self::assertStringContainsString( 'Add private access only if needed.', $quick_start_text );
		self::assertStringContainsString( 'Verify the result.', $quick_start_text );

		$prerequisites = array(
			'This plugin currently supports single-site WordPress installations only. Multisite and network activation are not yet supported.',
			'Use MySQL 8.0 or newer or MariaDB 10.11 or newer with InnoDB available. MySQL 8.4 LTS is recommended for production. SQLite, PostgreSQL and unverified database-translation drop-ins are not supported.',
			'If an active site moves outside that database envelope, Booster leaves its stored data unchanged and pauses package storage, Transporter and deployments. Restore a supported database, then use read-only Troubleshooting to confirm compatibility. For planned moves, keep the normal site backup and a current Transporter Blueprint; Booster does not convert custom tables between database engines.',
			'PHP 8.2 or newer is required, 8.4 LTS or newer recommended.',
		);
		foreach ( $prerequisites as $prerequisite ) {
			self::assertStringContainsString( $prerequisite, $requirements_text );
			self::assertStringNotContainsString( $prerequisite, $quick_start_text );
		}
	}

	public function test_navigation_matches_chapter_order_and_every_local_link_has_one_target(): void {
		$html     = $this->render_view( $this->provider_documentation() );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath         = new \DOMXPath( $document );
		$chapter_ids   = array();
		$index_targets = array();
		$chapters      = $xpath->query( '//details[@data-ran-booster-documentation-section]' );
		$index_links   = $xpath->query( '//aside//nav//a' );
		$local_links   = $xpath->query( '//a[starts-with(@href, "#")]' );
		self::assertInstanceOf( \DOMNodeList::class, $chapters );
		self::assertInstanceOf( \DOMNodeList::class, $index_links );
		self::assertInstanceOf( \DOMNodeList::class, $local_links );
		foreach ( $chapters as $chapter ) {
			self::assertInstanceOf( \DOMElement::class, $chapter );
			$chapter_ids[] = $chapter->getAttribute( 'id' );
		}
		foreach ( $index_links as $index_link ) {
			self::assertInstanceOf( \DOMElement::class, $index_link );
			$index_targets[] = substr( $index_link->getAttribute( 'href' ), 1 );
		}
		self::assertSame( $chapter_ids, $index_targets );
		self::assertSame( array( 'ran-booster-quick-start', 'ran-booster-requirements' ), array_slice( $chapter_ids, 0, 2 ) );
		foreach ( $local_links as $local_link ) {
			self::assertInstanceOf( \DOMElement::class, $local_link );
			$target_id = substr( $local_link->getAttribute( 'href' ), 1 );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="' . $target_id . '"])' ), $target_id );
		}
	}

	public function test_custom_chapters_preserve_order_fallback_and_safe_unique_anchors(): void {
		$calls = 0;
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_documentation_chapters'][]                      = static function ( array $chapters, string $url, string $scope ): array {
			self::assertStringContainsString( 'tab=documentation', $url );
			self::assertSame( 'site', $scope );
			return array_merge(
				$chapters,
				array(
					array(
						'id'    => 'fixture-first',
						'title' => 'First <chapter>',
					),
					array(
						'id'    => 'fixture-first',
						'title' => 'Duplicate title',
					),
					array(
						'id'    => 'fixture-second',
						'title' => 'Second chapter',
					),
					array(
						'id'    => 'fixture-empty',
						'title' => 'Empty chapter',
					),
					array(
						'id'    => 'provider-gh',
						'title' => 'Reserved title',
					),
					array(
						'id'    => 'bad id',
						'title' => 'Malformed title',
					),
					array(
						'id'    => 'fixture-control',
						'title' => "Control\ntitle",
					),
					array(
						'id'    => 'fixture-long',
						'title' => str_repeat( 'x', 201 ),
					),
					array(
						'id'    => 'core',
						'title' => 'Reserved core',
					),
				)
			);
		};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_before_about'][] = static function ( array $sections, string $url, string $scope, ?ProviderMetadata $metadata ) use ( &$calls ): array {
			self::assertNull( $metadata );
			self::assertSame( 'site', $scope );
			self::assertStringContainsString( 'tab=documentation', $url );
			$sections[] = array(
				'id'      => 'fixture-second-article',
				'summary' => 'Second article',
				'content' => '<p>Second.</p>',
				'chapter' => 'fixture-second',
				'open'    => true,
			);
			$sections[] = array(
				'id'      => 'fixture-first-article',
				'summary' => 'First article',
				'content' => static function () use ( &$calls ): void {
									++$calls;
									echo '<p>First.</p>';
				},
				'chapter' => 'fixture-first',
				'open'    => true,
			);
			$sections[] = array(
				'id'      => 'fixture-first-other',
				'summary' => 'Other article',
				'content' => '<p>Other.</p>',
				'chapter' => 'fixture-first',
			);
			$sections[] = array(
				'id'      => 'fixture-failed',
				'summary' => 'Failed article',
				'content' => static function (): void {
									throw new \RuntimeException( 'failure' );
				},
				'chapter' => 'fixture-first',
			);
			$sections[] = array(
				'id'      => 'ran-booster-documentation-chapter-fixture-first',
				'summary' => 'Collision article',
				'content' => '<p>Collision.</p>',
			);
			$sections[] = array(
				'id'      => 'long-fallback',
				'summary' => 'Long fallback',
				'content' => '<p>Available.</p>',
				'chapter' => 'fixture-long',
			);
			$sections[] = array(
				'id'      => 'provider-fallback',
				'summary' => 'Provider fallback',
				'content' => '<p>Available.</p>',
				'chapter' => 'provider-gh',
			);
			$sections[] = array(
				'id'      => 'control-fallback',
				'summary' => 'Control fallback',
				'content' => '<p>Available.</p>',
				'chapter' => 'fixture-control',
			);
			$sections[] = array(
				'id'      => 'reserved-fallback',
				'summary' => 'Reserved fallback',
				'content' => '<p>Available.</p>',
				'chapter' => 'core',
			);
			$sections[] = array(
				'id'      => 'fixture-fallback',
				'summary' => 'Fallback article',
				'content' => '<p>Fallback.</p>',
				'chapter' => 'fixture-missing',
			);
			return $sections;
		};
		$html = $this->render_view( $this->provider_documentation() );
		self::assertSame( 1, $calls );
		self::assertStringNotContainsString( '<chapter>', $html );
		foreach ( array( 'Duplicate title', 'Empty chapter', 'Reserved title', 'Malformed title' ) as $ignored ) {
			self::assertStringNotContainsString( $ignored, $html );
		}
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath       = new \DOMXPath( $document );
		$nav_groups  = $xpath->query( '//aside//nav//*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-documentation__chapters ")]/*[.//a]' );
		$body_groups = $xpath->query( '//section[contains(concat(" ", normalize-space(@class), " "), " ran-booster-documentation__chapter ")]' );
		self::assertInstanceOf( \DOMNodeList::class, $nav_groups );
		self::assertInstanceOf( \DOMNodeList::class, $body_groups );
		$nav = array();
		foreach ( $nav_groups as $group ) {
			self::assertInstanceOf( \DOMElement::class, $group );
			self::assertSame( 0.0, $xpath->evaluate( 'count(./h3//a | ./h3//button | ./h3//details)', $group ) );
			$title         = trim( (string) $xpath->evaluate( 'string(./h3)', $group ) );
			$nav[ $title ] = array();
			$links         = $xpath->query( './/a', $group );
			self::assertInstanceOf( \DOMNodeList::class, $links );
			foreach ( $links as $link ) {
				self::assertInstanceOf( \DOMElement::class, $link );
				$nav[ $title ][] = substr( $link->getAttribute( 'href' ), 1 );
			}
		}
		$body = array();
		foreach ( $body_groups as $group ) {
			self::assertInstanceOf( \DOMElement::class, $group );
			$title          = trim( (string) $xpath->evaluate( 'string(./h3)', $group ) );
			$body[ $title ] = array();
			$articles       = $xpath->query( './/details[@data-ran-booster-documentation-section]', $group );
			self::assertInstanceOf( \DOMNodeList::class, $articles );
			foreach ( $articles as $article ) {
				self::assertInstanceOf( \DOMElement::class, $article );
				$body[ $title ][] = $article->getAttribute( 'id' );
			}
			self::assertSame( 0.0, $xpath->evaluate( 'count(./h3//a | ./h3//button | ./h3//details)', $group ) );
		}
		self::assertSame( $body, $nav );
		self::assertSame( array( 'Booster Core concepts', 'GitHub', 'Bitbucket', 'First <chapter>', 'Second chapter', 'Reference' ), array_keys( $body ) );
		self::assertSame( array( 'fixture-first-article', 'fixture-first-other', 'fixture-failed' ), $body['First <chapter>'] );
		self::assertSame( array( 'fixture-second-article' ), $body['Second chapter'] );
		self::assertContains( 'fixture-fallback', $body['Reference'] );
		self::assertContains( 'control-fallback', $body['Reference'] );
		self::assertContains( 'long-fallback', $body['Reference'] );
		self::assertContains( 'provider-fallback', $body['Reference'] );
		self::assertContains( 'reserved-fallback', $body['Reference'] );
		self::assertSame( 'ran-booster-about', end( $body['Reference'] ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//details[@name="ran-booster-documentation"][@open][@id="fixture-first-article"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//details[@name="ran-booster-documentation"][@open])' ) );
		self::assertStringContainsString( 'An add-on guide is temporarily unavailable.', $html );
		$ids      = array();
		$id_nodes = $xpath->query( '//*[@id]' );
		self::assertInstanceOf( \DOMNodeList::class, $id_nodes );
		foreach ( $id_nodes as $node ) {
			self::assertInstanceOf( \DOMElement::class, $node );
			$id = $node->getAttribute( 'id' );
			self::assertArrayNotHasKey( $id, $ids, 'Duplicate ID: ' . $id );
			$ids[ $id ] = true;
		}
	}

	public function test_missing_or_deactivated_chapter_leaves_its_article_in_reference(): void {
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_documentation_chapters'][]                      = static fn ( array $chapters ): array => array_merge(
			$chapters,
			array(
				array(
					'id'    => 'fixture-guidance',
					'title' => 'Fixture guidance',
				),
			)
		);
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_before_about'][] = static function ( array $sections ): array {
			$sections[] = array(
				'id'      => 'surviving-guide',
				'summary' => 'Surviving guide',
				'content' => '<p>Still available.</p>',
				'chapter' => 'fixture-guidance',
			);
			return $sections;
		};
		$active = $this->render_view( array() );
		self::assertStringContainsString( 'Fixture guidance', $active );
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_documentation_chapters'] = array();
		$inactive = $this->render_view( array() );
		self::assertStringNotContainsString( 'Fixture guidance', $inactive );
		$document = new \DOMDocument();
		$document->loadHTML( $inactive, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//section[contains(concat(" ", normalize-space(@class), " "), " ran-booster-documentation__chapter ")][h3="Reference"]//details[@id="surviving-guide"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//aside//a[@href="#surviving-guide"])' ) );
	}

	public function test_provider_articles_stay_with_provider_and_win_duplicate_ids(): void {
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_documentation_chapters'][]                           = static fn ( array $chapters ): array => array_merge(
			$chapters,
			array(
				array(
					'id'    => 'fixture-guidance',
					'title' => 'Fixture guidance',
				),
			)
		);
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_after_provider_gh'][] = static function ( array $sections, string $url, string $scope ): array {
			self::assertStringContainsString( 'tab=documentation', $url );
			self::assertSame( 'site', $scope );
			$sections[] = array(
				'id'      => 'shared-guide',
				'summary' => 'Provider wins',
				'content' => '<p>Provider content.</p>',
				'chapter' => 'fixture-guidance',
			);
			return $sections;
		};
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_before_about'][]      = static function ( array $sections ): array {
			$sections[] = array(
				'id'      => 'shared-guide',
				'summary' => 'Duplicate loses',
				'content' => '<p>Duplicate.</p>',
				'chapter' => 'fixture-guidance',
			);
			$sections[] = array(
				'id'      => 'custom-guide',
				'summary' => 'Custom guide',
				'content' => '<p>Custom.</p>',
				'chapter' => 'fixture-guidance',
			);
			return $sections;
		};
		$html     = $this->render_view( $this->provider_documentation() );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//section[contains(concat(" ", normalize-space(@class), " "), " ran-booster-documentation__chapter ")][h3="GitHub"]//details[@id="shared-guide"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//details[@id="ran-booster-documentation-provider-gh"]/following-sibling::*[1][@id="shared-guide"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//details[@id="shared-guide"])' ) );
		self::assertStringNotContainsString( 'Duplicate loses', $html );
	}

	public function test_addon_contributions_cannot_take_the_requirements_anchor(): void {
		foreach ( array( 'ran_booster_documentation_sections_after_provider_gh', 'ran_booster_documentation_sections_before_about' ) as $hook ) {
			$GLOBALS['ran_booster_documentation_test_filters'][ $hook ][] =
				static function ( array $sections ): array {
					$sections[] = array(
						'id'      => 'ran-booster-requirements',
						'summary' => 'Conflicting requirements',
						'content' => '<p>Replacement prerequisites.</p>',
					);

					return $sections;
				};
		}
		$html = $this->render_view( $this->provider_documentation() );

		self::assertSame( 1, substr_count( $html, 'id="ran-booster-requirements"' ) );
		self::assertSame( 1, substr_count( $html, 'class="ran-booster-documentation__index-link" href="#ran-booster-requirements"' ) );
		self::assertStringNotContainsString( 'Conflicting requirements', $html );
		self::assertStringNotContainsString( 'Replacement prerequisites.', $html );
	}

	public function test_states_the_current_single_site_support_boundary_without_beta_or_version_jargon(): void {
		$html = $this->render_view( $this->provider_documentation() );

		self::assertStringContainsString( '<strong>Current limitation:</strong>', $html );
		self::assertStringContainsString( 'This plugin currently supports single-site WordPress installations only', $html );
		self::assertStringContainsString( 'Multisite and network activation are not yet supported', $html );
		self::assertStringNotContainsString( 'diagnostics SDK', $html );
		self::assertStringNotContainsString( 'Beta', $html );
		self::assertStringNotContainsString( 'beta', $html );
		self::assertStringNotContainsString( 'V1', $html );
	}

	public function test_renders_actionable_internal_and_provider_owned_official_links_safely(): void {
		$html = $this->render_view( $this->provider_documentation() );

		self::assertStringContainsString( 'page=ran-booster-plugins-create', $html );
		self::assertStringContainsString( 'page=ran-booster-themes-create', $html );
		self::assertStringContainsString( 'page=ran-booster&amp;tab=troubleshooting', $html );
		self::assertStringContainsString( 'page=ran-booster&amp;tab=gh', $html );
		self::assertStringContainsString( 'page=ran-booster&amp;tab=bb', $html );
		self::assertStringContainsString( 'https://docs.github.com/tokens?mode=&quot;safe&quot;', $html );
		self::assertSame( substr_count( $html, 'target="_blank"' ), substr_count( $html, 'rel="noopener noreferrer"' ) );
		self::assertStringNotContainsString( 'canary-secret', $html );
	}

	public function test_quick_start_provider_links_have_equal_button_weight(): void {
		$html = $this->render_view( $this->provider_documentation() );

		self::assertSame(
			1,
			preg_match(
				'/<ul class="ran-booster-documentation__inline-links">.*?<a class="button" href="[^"]+tab=gh">GitHub<\/a>.*?<a class="button" href="[^"]+tab=bb">Bitbucket<\/a>.*?<\/ul>/s',
				$html,
				$quick_start_links
			)
		);
		if ( ! isset( $quick_start_links[0] ) ) {
			self::fail( 'Expected the quick-start links in the rendered view.' );
		}
		self::assertSame( 2, substr_count( $quick_start_links[0], 'class="button"' ) );
		self::assertStringNotContainsString( 'button-primary', $quick_start_links[0] );
		self::assertLessThan( strpos( $html, 'Add private access only if needed.' ), strpos( $html, 'Install a package.' ) );
		self::assertStringContainsString( 'No credential is required.', $html );
	}

	public function test_documents_project_lineage_without_overstating_the_fork(): void {
		$html = $this->render_view( $this->provider_documentation() );

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

	public function test_escapes_provider_text_and_provides_missing_setup_fallback(): void {
		$documentation   = $this->provider_documentation();
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

		$html = $this->render_view( $documentation );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<strong>pending</strong>', $html );
		self::assertStringContainsString( 'GitLab &lt;script&gt;alert(1)&lt;/script&gt; credentials and access', $html );
		self::assertStringContainsString( 'Guidance &lt;strong&gt;pending&lt;/strong&gt;.', $html );
		self::assertStringContainsString( 'enter repository details manually', $html );
	}

	public function test_states_the_credential_file_location_retention_and_removal_contract_accurately(): void {
		$html = $this->render_view( $this->provider_documentation() );

		self::assertStringContainsString( '<code>RAN_BOOSTER_ENCRYPTED_SECRETS_DIR</code>', $html );
		self::assertStringNotContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', $html );
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
		self::assertStringContainsString( 'does not include webhook secrets, webhook registrations on the Git provider, deployment history or constants', $html );
		self::assertStringContainsString( 'starts with deployment Disabled', $html );
		self::assertStringContainsString( 'Uninstall cannot revoke Git provider credentials or remove remote webhooks', $html );
		self::assertStringContainsString( 'removes only the exact wp-config.php storage definition it created', $html );
		self::assertStringContainsString( 'remains under the site operator’s control', $html );
		self::assertStringNotContainsString( 'gitignored', $html );
		self::assertStringNotContainsString( 'sidecar', $html );
		self::assertStringNotContainsString( 'not encryption', $html );
	}

	public function test_leads_with_the_portability_happy_path_and_development_safety(): void {
		$html = $this->render_view( $this->provider_documentation() );

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
		self::assertStringContainsString( 'webhook secrets and hooks registered with the Git provider', $html );
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
		self::assertStringContainsString( 'Use the matching Git provider tab as the source of truth', $html );
		self::assertStringContainsString( 'Follow that Git provider’s webhook instructions', $html );
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

	public function test_describes_the_live_portability_workflow(): void {
		$html = $this->render_view( $this->provider_documentation() );

		self::assertStringContainsString( '<strong>Transporter workflow:</strong>', $html );
		self::assertStringContainsString( 'Reviewing a Transporter Blueprint never installs anything, changes package settings, or stores the uploaded file', $html );
		self::assertStringContainsString( '>Open Transporter</a>', $html );
		self::assertStringNotContainsString( 'Transporter preview:', $html );
	}

	public function test_documents_the_effective_repository_archive_policy_and_override(): void {
		$html = $this->render_view( $this->provider_documentation() );

		self::assertStringContainsString( '<h3>Large repository archives</h3>', $html );
		self::assertStringContainsString( 'ZIP of the whole repository', $html );
		self::assertStringContainsString( '50 MiB compressed and 200 MiB expanded', $html );
		self::assertStringContainsString( 'Booster default', $html );
		self::assertStringContainsString( "define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 150 * 1024 * 1024 );", $html );
		self::assertStringContainsString( 'manual installs, manual and webhook updates, and package installation from a Transporter Blueprint', $html );
		self::assertStringContainsString( 'adopting an already-installed package do not download a repository archive', $html );
	}

	public function test_static_copy_uses_the_plugin_text_domain(): void {
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
	 * @param list<array<string, mixed>> $provider_documentation Display-safe provider guidance.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The included production view reads these parameters as local template variables.
	private function render_view( array $provider_documentation ): string {
		$tabs                = array(
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
		$documentation_url   = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation';
		$documentation_scope = 'site';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/documentation.php';

		return (string) ob_get_clean();
	}

	/** @return list<array<string, mixed>> */
	private function provider_documentation(): array {
		return array(
			array(
				'metadata'        => new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ),
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
				'metadata'        => new ProviderMetadata( ProviderCode::parse( 'bb' ), 'Bitbucket', 'https://bitbucket.org/', 'Owner' ),
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
