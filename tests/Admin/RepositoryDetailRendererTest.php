<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\RepositoryDetailRenderer;

final class RepositoryDetailRendererTest extends TestCase {
	public function test_detail_badges_preserve_exact_labels_and_map_only_known_tones(): void {
		$renderer = new RepositoryDetailRenderer();
		$render   = new \ReflectionMethod( $renderer, 'render_detail_value' );
		foreach ( array(
			'neutral' => 'none',
			'pending' => 'none',
			'ok'      => 'stable',
			'success' => 'stable',
			'warning' => 'medium',
			'error'   => 'high',
			'info'    => 'informational',
			'future'  => 'none',
		) as $tone => $intent ) {
			ob_start();
			$render->invoke(
				$renderer,
				array(
					'value' => 'Exact <status> "quoted"',
					'tone'  => $tone,
				)
			);
			$html = (string) ob_get_clean();
			self::assertStringContainsString( '<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-' . $intent . '-intent" data-ran-booster-badge data-ran-booster-badge-intent="' . $intent . '">Exact &lt;status&gt; &quot;quoted&quot;</span>', $html );
		}
	}

	public function test_status_renders_mixed_packages_and_integration_history_without_mutation_authority(): void {
		$row = array(
			'repository'        => 'owner/shared',
			'repository_url'    => 'https://github.com/owner/shared',
			'source_label'      => 'Conflicting sources',
			'source_key'        => 'mixed',
			'package_summaries' => array(
				array(
					'type'              => 'plugin',
					'identifier'        => 'owner/plugin.php',
					'display_name'      => 'Plugin',
					'settings_url'      => 'https://example.test/plugins',
					'source'            => 'branch',
					'source_revision'   => 2,
					'branch'            => 'main',
					'subdirectory'      => 'packages/plugin',
					'deployment_policy' => 'automatic',
				),
				array(
					'type'              => 'theme',
					'identifier'        => 'owner-theme',
					'display_name'      => 'Theme',
					'settings_url'      => 'https://example.test/themes',
					'source'            => 'release_asset',
					'source_revision'   => 4,
					'branch'            => '',
					'subdirectory'      => '',
					'deployment_policy' => 'manual',
				),
			),
			'details'           => array(
				array(
					'key'   => 'core:webhook-recorded-status',
					'label' => 'Recorded hook',
					'value' => 'Configured at last check',
				),
				array(
					'key'   => 'core:webhook-current-warning',
					'label' => 'Current local warning',
					'value' => 'Needs verification',
					'tone'  => 'warning',
				),
				array(
					'key'      => 'core:webhook-last-checked',
					'label'    => 'Last checked',
					'value'    => 'August 20, 2026',
					'datetime' => '2026-08-20T01:02:03Z',
				),
				array(
					'key'   => 'gh:release-automation-a',
					'label' => 'État du flux de publication',
					'value' => 'Ready to assess',
					'tone'  => 'ok',
				),
				array(
					'key'      => 'provider:custom-workflow-observation',
					'category' => 'release_workflow',
					'label'    => 'Flux de publication — owner/theme',
					'value'    => 'Configured',
				),
				array(
					'key'   => 'provider:legacy-release-workflow',
					'label' => 'Release automation — owner/legacy.php',
					'value' => 'Legacy',
				),
				array(
					'key'   => 'fixture:repository-access',
					'label' => 'Provider access',
					'value' => 'Configured',
				),
			),
			'actions'           => array(
				array(
					'key'   => 'gh:release-automation-a',
					'label' => 'Release automation: owner/plugin.php',
					'url'   => 'https://example.test/plugins?source_view=release_asset',
				),
			),
			'status_links'      => array(
				array(
					'label' => 'Review shared owner secret',
					'url'   => 'https://example.test/secrets',
				),
			),
		);

		$webhook_rendered = false;
		$release_rendered = false;
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			$row,
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'status',
			$this->view_urls(),
			$this->view_request_urls(),
			static function () use ( &$webhook_rendered ): bool {
				$webhook_rendered = true;
				echo '<div data-test-webhook></div>';

				return true;
			},
			static function () use ( &$release_rendered ): void {
					$release_rendered = true;
					echo '<div data-test-release></div>';
			}
		);
		$html = (string) ob_get_clean();

		foreach ( array( 'packages', 'integration-summary', 'activity', 'details' ) as $section ) {
			self::assertStringContainsString( 'data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-' . $section . '-heading"', $html );
		}
		self::assertSame( 4, substr_count( $html, 'data-ran-booster-card="provider"' ) );
		$integration = (string) strstr( $html, 'ran-booster-repository-integration-summary-heading' );
		$integration = substr( $integration, 0, (int) strpos( $integration, '</section>' ) );
		self::assertStringContainsString( '<p><a href="https://example.test/secrets">Review shared owner secret</a></p>', preg_replace( '/\s+/', ' ', str_replace( array( "\n", "\t" ), '', $integration ) ) ?? '' );
		self::assertStringNotContainsString( 'repository-detail__status-links', $html );
		self::assertFalse( $webhook_rendered );
		self::assertFalse( $release_rendered );
		self::assertStringContainsString( '2 packages · Conflicting sources', $html );
		self::assertStringContainsString( 'Conflicting sources.', $html );
		self::assertStringContainsString( 'data-ran-booster-provider-task-error role="alert" tabindex="-1" hidden data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p>', $html );
		self::assertStringContainsString( 'data-ran-booster-ui-notice-intent="warning"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><strong>Conflicting sources.</strong>', $html );
		self::assertStringContainsString( 'Review the package settings before using release workflow.', $html );
		self::assertStringContainsString( 'Branch · main · packages/plugin', $html );
		self::assertStringContainsString( '>Releases', $html );
		self::assertStringContainsString( 'Ignores pushes', $html );
		self::assertStringContainsString( 'Plugin settings', $html );
		self::assertStringContainsString( 'Theme settings', $html );
		self::assertStringContainsString( 'Integration status', $html );
		self::assertStringContainsString( '<caption class="screen-reader-text">Packages using this repository</caption>', $html );
		self::assertSame( 4, substr_count( $html, '<th scope="col">' ) );
		if ( 1 !== preg_match( '/<div class="ran-booster-settings-section__body">\s*<div class="ran-booster-data-table-wrap">\s*<table class="([^"]+)">([\s\S]*?)<\/table>\s*<\/div>\s*<\/div>/', $html, $m ) ) {
			self::fail( 'The packages table must be the only child of the shared data-table wrapper inside the section body.' );
		}
		$packages_table = $m[2];
		self::assertSame( array( 'ran-booster-data-table', 'ran-booster-data-table--rows', 'ran-booster-repository-detail__packages' ), explode( ' ', $m[1] ) );
		self::assertStringNotContainsString( 'widefat', $html );
		self::assertStringNotContainsString( 'striped', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-detail__table-wrap', $html );
		self::assertSame( 1, preg_match_all( '/<caption class="screen-reader-text">[^<]+<\/caption>/', $packages_table ) );
		self::assertSame( 4, preg_match_all( '/<th[\s>]/', $packages_table ) );
		self::assertSame( 1, substr_count( $packages_table, '<tbody>' ) );
		self::assertSame( 8, preg_match_all( '/<td[\s>]/', $packages_table ) );
		self::assertSame( 8, preg_match_all( '/<td data-label="(?:Package|Source|Updates|Settings)">/', $packages_table ) );
		self::assertStringContainsString( 'hx-target="#ran-booster-provider-profile-region"', $html );
		self::assertStringContainsString( 'hx-select="#ran-booster-provider-profile-region"', $html );
		self::assertStringContainsString( '<ul class="filter-links ran-section-navigation ran-section-navigation--thin">', $html );
		self::assertSame( 3, substr_count( $html, 'ran-booster-provider-task-tab ran-section-navigation__link' ) );
		self::assertSame( 3, substr_count( $html, '<a class="ran-wp-ui-box-sizing ran-wp-ui-reset-a ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-link is-unstyled ran-booster-provider-task-tab ' ) );
		self::assertSame( 2, substr_count( $html, 'ran-booster-provider-task-tab__source-indicator' ) );
		self::assertSame( 2, substr_count( $html, '<span class="screen-reader-text">In use</span>' ) );
		self::assertSame( 2, substr_count( $html, 'title="In use"' ) );
		self::assertStringContainsString( 'data-ran-booster-repository-view="status" aria-controls="ran-booster-provider-profile-region" aria-current="page"', $html );
		self::assertStringNotContainsString( 'Status is configured for this repository.', $html );
		self::assertMatchesRegularExpression( '#Branch demand</dt><dd>\s*<span[^>]*is-stable-intent[^>]*>1 package</span>#', $html );
		self::assertMatchesRegularExpression( '#Recorded release usage</dt><dd>\s*<span[^>]*is-stable-intent[^>]*>1 package</span>#', $html );
		self::assertStringNotContainsString( 'uses Branch', $html );
		self::assertStringNotContainsString( 'tracks Releases', $html );
		self::assertStringContainsString( 'État du flux de publication', $html );
		self::assertStringContainsString( '<h4 class="ran-booster-repository-detail__group-heading">Release workflow</h4>', $html );
		self::assertStringContainsString( 'Flux de publication — owner/theme', $html );
		self::assertStringContainsString( 'Release automation — owner/legacy.php', $html );
		$webhook_history_position = strpos( $html, 'Configured at last check' );
		$release_history_position = strpos( $html, '<h4 class="ran-booster-repository-detail__group-heading">Release workflow</h4>' );
		$legacy_history_position  = strpos( $html, 'Release automation — owner/legacy.php' );
		self::assertIsInt( $webhook_history_position );
		self::assertIsInt( $release_history_position );
		self::assertIsInt( $legacy_history_position );
		self::assertTrue( $webhook_history_position < $release_history_position );
		self::assertTrue( $release_history_position < strrpos( $html, 'État du flux de publication' ) );
		self::assertTrue( $release_history_position < strrpos( $html, 'Flux de publication — owner/theme' ) );
		self::assertTrue( $release_history_position < $legacy_history_position );
		self::assertStringNotContainsString( 'data-test-webhook', $html );
		self::assertStringNotContainsString( 'data-test-release', $html );
		self::assertStringNotContainsString( 'Git provider receiver', $html );
		self::assertStringNotContainsString( 'Receiver ready.', $html );
		self::assertStringContainsString( 'This is local history, not live Git provider state.', $html );
		self::assertStringContainsString( 'ran-wp-ui-badge is-medium-intent" data-ran-booster-badge data-ran-booster-badge-intent="medium"', $html );
		self::assertStringContainsString( '<time datetime="2026-08-20T01:02:03Z">August 20, 2026</time>', $html );
		self::assertStringContainsString( 'Repository details', $html );
		self::assertStringContainsString( 'Provider access', $html );
		self::assertLessThan( strpos( $html, 'Provider access' ), strpos( $html, 'Repository details' ) );
		self::assertStringNotContainsString( 'name="repository_webhook_management_operation"', $html );
	}

	/**
	 * The tab dot shows only for a method that is in use, counted from the same package summaries as the
	 * Status view, and carries its meaning as hover text and screen-reader text.
	 */
	public function test_tab_dot_appears_only_for_the_method_in_use_with_an_accessible_name(): void {
		$cases = array(
			'branch only'  => array( array( 'branch' ), array( 'Branch' ) ),
			'release only' => array( array( 'release_asset' ), array( 'Releases' ) ),
			'mixed'        => array( array( 'branch', 'release_asset' ), array( 'Branch', 'Releases' ) ),
			'no summaries' => array( array(), array( 'Branch', 'Releases' ) ), // Falls back to the row's source_key.
		);
		foreach ( $cases as $name => $case ) {
			$summaries = array();
			foreach ( $case[0] as $source ) {
				$summaries[] = array(
					'type'            => 'plugin',
					'identifier'      => $source . '/plugin.php',
					'display_name'    => 'Plugin ' . $source,
					'settings_url'    => 'https://example.test/' . $source,
					'source'          => $source,
					'source_revision' => 1,
					'branch'          => 'branch' === $source ? 'main' : '',
					'subdirectory'    => '',
				);
			}
			ob_start();
			( new RepositoryDetailRenderer() )->render(
				array(
					'repository'        => 'owner/dot',
					'source_label'      => 'Branch',
					// A stale source_key must not decide: the package summaries do.
					'source_key'        => 'mixed',
					'package_summaries' => $summaries,
					'details'           => array(),
					'actions'           => array(),
				),
				'GitHub',
				'https://example.test/repositories',
				'https://example.test/activity',
				true,
				'Receiver ready.',
				'status',
				$this->view_urls(),
				$this->view_request_urls(),
				null,
				null
			);
			$html = (string) ob_get_clean();

			$tabs_with_dot = array();
			if ( 0 < preg_match_all( '/<a [^>]*data-ran-booster-repository-view="[a-z]+"[^>]*>(Status|Branch|Releases)(?:\s*<span class="ran-booster-provider-task-tab__source-indicator" title="In use" aria-hidden="true"><\/span><span class="screen-reader-text">In use<\/span>)?/', $html, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					if ( str_contains( $match[0], 'source-indicator' ) ) {
						$tabs_with_dot[] = $match[1];
					}
				}
			}
			self::assertSame( $case[1], $tabs_with_dot, $name );
			self::assertSame( count( $case[1] ), substr_count( $html, 'title="In use"' ), $name );
			self::assertSame( count( $case[1] ), substr_count( $html, '<span class="screen-reader-text">In use</span>' ), $name );
		}
	}

	/**
	 * The shared tab strip is also used by the Edit source switcher, so the repository page markup is pinned byte for byte.
	 */
	public function test_repository_tab_strip_markup_is_pinned(): void {
		$summaries = array();
		foreach ( array( 'branch', 'release_asset' ) as $source ) {
			$summaries[] = array(
				'type'            => 'plugin',
				'identifier'      => $source . '/plugin.php',
				'display_name'    => 'Plugin ' . $source,
				'settings_url'    => 'https://example.test/' . $source,
				'source'          => $source,
				'source_revision' => 1,
				'branch'          => 'branch' === $source ? 'main' : '',
				'subdirectory'    => '',
			);
		}
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'        => 'owner/dot',
				'source_label'      => 'Branch',
				'source_key'        => 'mixed',
				'package_summaries' => $summaries,
				'details'           => array(),
				'actions'           => array(),
			),
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'branch',
			$this->view_urls(),
			$this->view_request_urls(),
			null,
			null
		);
		$html = (string) ob_get_clean();
		if ( 1 !== preg_match( '#<nav class="ran-booster-provider-task-tabs ran-booster-repository-detail__tabs".*?</nav>#s', $html, $strip ) ) {
			self::fail( 'The repository tab strip was not rendered.' );
		}
		$expected = file_get_contents( dirname( __DIR__ ) . '/fixtures/repository-tab-strip.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a repository fixture in a test.
		self::assertIsString( $expected );
		self::assertSame( $expected, $strip[0] . "\n" );
	}

	public function test_status_keeps_exact_source_counts_for_complete_package_inventory(): void {
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'        => 'owner/complete',
				'source_label'      => 'Mixed sources',
				'source_key'        => 'mixed',
				'package_summaries' => array(
					array(
						'type'              => 'plugin',
						'identifier'        => 'branch/plugin.php',
						'display_name'      => 'Branch plugin',
						'settings_url'      => 'https://example.test/branch',
						'source'            => 'branch',
						'source_revision'   => 1,
						'branch'            => 'main',
						'subdirectory'      => '',
						'deployment_policy' => 'manual',
					),
					array(
						'type'              => 'theme',
						'identifier'        => 'release-theme',
						'display_name'      => 'Release theme',
						'settings_url'      => 'https://example.test/release',
						'source'            => 'release_asset',
						'source_revision'   => 2,
						'branch'            => '',
						'subdirectory'      => '',
						'deployment_policy' => 'manual',
					),
				),
				'details'           => array(),
				'actions'           => array(),
			),
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'status',
			$this->view_urls(),
			$this->view_request_urls(),
			null,
			null
		);
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '#Branch demand</dt><dd>\s*<span[^>]*is-stable-intent[^>]*>1 package</span>#', $html );
		self::assertMatchesRegularExpression( '#Recorded release usage</dt><dd>\s*<span[^>]*is-stable-intent[^>]*>1 package</span>#', $html );
		self::assertStringNotContainsString( 'uses Branch', $html );
		self::assertStringNotContainsString( 'tracks Releases', $html );
		self::assertStringNotContainsString( 'Exact counts are unavailable while package inventory is incomplete.', $html );
	}

	public function test_branch_view_shows_webhook_guidance_without_asetup_control_when_unavailable(): void {
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'        => 'owner/releases',
				'repository_url'    => '',
				'source_label'      => 'Published releases',
				'package_summaries' => array(
					array(
						'type'              => 'theme',
						'identifier'        => 'theme',
						'display_name'      => 'Theme',
						'settings_url'      => 'https://example.test/theme',
						'source'            => 'release_asset',
						'source_revision'   => 1,
						'branch'            => '',
						'subdirectory'      => '',
						'deployment_policy' => 'manual',
					),
				),
				'details'           => array(),
				'actions'           => array(),
			),
			'Bitbucket',
			'https://example.test/repositories',
			'https://example.test/activity',
			false,
			'Receiver unavailable.',
			'branch',
			$this->view_urls(),
			$this->view_request_urls(),
			null,
			null
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'no Branch package currently uses this repository webhook', $html );
		self::assertSame( 1, substr_count( $html, 'class="ran-booster-settings-section ran-booster-repository-webhook-section ran-booster-card"' ) );
		self::assertStringContainsString( 'class="ran-booster-settings-section ran-booster-repository-webhook-section ran-booster-card"', $html );
		self::assertStringContainsString( '<h4 id="ran-booster-repository-webhook-setup-heading">Webhook setup</h4>', $html );
		self::assertStringNotContainsString( '<details', $html );
		self::assertStringNotContainsString( '>Set up</button>', $html );
		self::assertStringContainsString( 'Management history', $html );
		self::assertStringContainsString( 'Recorded hook status', $html );
		self::assertStringContainsString( 'Managed hook not yet set', $html );
		self::assertStringContainsString( 'None', $html );
		self::assertStringContainsString( 'Recorded hook profile', $html );
		self::assertStringContainsString( 'Last checked', $html );
		self::assertStringContainsString( 'Never', $html );
		self::assertStringContainsString( 'View repository activity', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
	}

	public function test_branch_view_does_not_render_core_release_workflow_actions(): void {
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'        => 'owner/branch',
				'source_label'      => 'Branch',
				'source_key'        => 'branch',
				'package_summaries' => array(),
				'details'           => array(),
				'actions'           => array(
					array(
						'key'      => 'core:release-workflow-deadbeefdeadbeef',
						'label'    => 'Assess release setup',
						'type'     => 'link',
						'url'      => 'https://example.test/releases',
						'disabled' => false,
					),
				),
			),
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'branch',
			$this->view_urls(),
			$this->view_request_urls(),
			null,
			null
		);
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( '>Assess release setup</a>', $html );
		self::assertStringNotContainsString( 'https://example.test/releases', $html );
	}

	public function test_incomplete_package_inventory_disables_repository_workflow_controls(): void {
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'                => 'owner/partial',
				'source_label'              => 'Branch',
				'source_key'                => 'branch',
				'package_summaries_omitted' => 1,
				'package_summaries'         => array(),
				'details'                   => array(),
				'actions'                   => array(),
			),
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'branch',
			$this->view_urls(),
			$this->view_request_urls(),
			static function (): bool {
				echo '<div data-test-webhook></div>';
				return true;
			},
			null
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Package inventory incomplete', $html );
		self::assertStringContainsString( 'data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-incomplete-inventory-heading"', $html );
		self::assertStringContainsString( 'Refresh repository inventory before using repository-wide workflow controls.', $html );
		self::assertStringContainsString( 'disabled aria-disabled="true">Manage webhook</button>', $html );
		self::assertStringNotContainsString( 'data-test-webhook', $html );
	}

	public function test_incomplete_package_inventory_uses_the_release_setup_label(): void {
		$release_rendered = false;
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'                => 'owner/partial-releases',
				'source_label'              => 'Published releases',
				'source_key'                => 'release_asset',
				'package_summaries_omitted' => 1,
				'package_summaries'         => array(),
				'details'                   => array(),
				'actions'                   => array(),
			),
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'releases',
			$this->view_urls(),
			$this->view_request_urls(),
			null,
			static function () use ( &$release_rendered ): void {
				$release_rendered = true;
				echo '<div data-test-release></div>';
			}
		);
		$html = (string) ob_get_clean();

		self::assertFalse( $release_rendered );
		self::assertStringContainsString( 'data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-incomplete-inventory-heading"', $html );
		self::assertStringContainsString( 'disabled aria-disabled="true">Assess release setup</button>', $html );
		self::assertStringNotContainsString( 'Assess release automation', $html );
		self::assertStringNotContainsString( 'data-test-release', $html );
	}

	public function test_branch_repository_falls_back_when_the_supported_webhook_panel_cannot_be_projected(): void {
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'          => 'owner/branch',
				'repository_url'      => '',
				'source_label'        => 'Branch',
				'has_branch_consumer' => true,
				'package_summaries'   => array(),
				'details'             => array(),
				'actions'             => array(),
			),
			'Fixture Forge',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'branch',
			$this->view_urls(),
			$this->view_request_urls(),
			static fn (): bool => false,
			null
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Repository webhook management is temporarily unavailable for this repository.', $html );
		self::assertStringContainsString( '<h3 id="ran-booster-repository-webhook-heading">Push-to-deploy</h3>', $html );
		self::assertStringContainsString( '<button type="button" class="ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-neutral is-outline" disabled aria-disabled="true">Manage repository webhook</button>', $html );
	}

	public function test_published_releases_view_uses_provider_panel_and_keeps_package_controls_linked(): void {
		$row              = array(
			'repository'        => 'owner/releases',
			'repository_url'    => 'https://github.com/owner/releases',
			'source_label'      => 'Published releases',
			'package_summaries' => array(
				array(
					'type'              => 'plugin',
					'identifier'        => 'owner/plugin.php',
					'display_name'      => 'Plugin',
					'settings_url'      => 'https://example.test/plugins',
					'source'            => 'release_asset',
					'source_revision'   => 3,
					'branch'            => '',
					'subdirectory'      => '',
					'deployment_policy' => 'manual',
				),
			),
			'details'           => array(
				array(
					'key'      => 'gh:release-automation-a',
					'label'    => 'Processo di pubblicazione',
					'value'    => 'Configured',
					'category' => 'release_workflow',
				),
			),
			'actions'           => array(),
		);
		$release_rendered = false;

		ob_start();
		( new RepositoryDetailRenderer() )->render(
			$row,
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'releases',
			$this->view_urls(),
			$this->view_request_urls(),
			null,
			static function () use ( &$release_rendered ): void {
				$release_rendered = true;
				echo '<section data-test-release>Exact package workflow controls</section>';
			}
		);
		$html = (string) ob_get_clean();

		self::assertTrue( $release_rendered );
		self::assertStringContainsString( 'data-ran-booster-repository-view="releases" aria-controls="ran-booster-provider-profile-region" aria-current="page"', $html );
		self::assertSame( 3, substr_count( $html, 'aria-controls="ran-booster-provider-profile-region"' ) );
		self::assertStringContainsString( 'data-test-release', $html );
		self::assertStringNotContainsString( 'Packages using this repository', $html );
		self::assertStringContainsString( '<h4 class="ran-booster-repository-detail__group-heading">Release workflow</h4>', $html );
		self::assertStringContainsString( 'Configured', $html );
	}

	public function test_unavailable_release_workflow_keeps_package_settings_links_without_afalse_action(): void {
		$row = array(
			'repository'        => 'owner/releases',
			'repository_url'    => '',
			'source_label'      => 'Published releases',
			'package_summaries' => array(
				array(
					'type'              => 'theme',
					'identifier'        => 'theme',
					'display_name'      => 'Theme',
					'settings_url'      => 'https://example.test/theme',
					'source'            => 'release_asset',
					'source_revision'   => 1,
					'branch'            => '',
					'subdirectory'      => '',
					'deployment_policy' => 'manual',
				),
			),
			'details'           => array(),
			'actions'           => array(),
		);

		ob_start();
		( new RepositoryDetailRenderer() )->render(
			$row,
			'Bitbucket',
			'https://example.test/repositories',
			'https://example.test/activity',
			false,
			'Receiver unavailable.',
			'releases',
			$this->view_urls(),
			$this->view_request_urls(),
			null,
			null
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<h3 id="ran-booster-repository-release-heading">Release publishing</h3>', $html );
		self::assertStringContainsString( 'Release workflow status is unavailable in this view.', $html );
		self::assertStringContainsString( 'Open Theme settings', $html );
		self::assertStringNotContainsString( 'Assess release setup', $html );
	}

	public function test_provider_actions_preserve_normalized_descriptions(): void {
		$row = array(
			'repository'        => 'owner/branch',
			'repository_url'    => 'https://example.test/owner/branch',
			'source_label'      => 'Branch',
			'source_key'        => 'branch',
			'package_summaries' => array(),
			'details'           => array(),
			'actions'           => array(
				array(
					'key'          => 'fixture:post',
					'label'        => 'Post',
					'type'         => 'post',
					'url'          => 'https://example.test/wp-admin/admin-post.php',
					'hidden'       => array(
						'action'   => 'fixture_action',
						'_wpnonce' => 'nonce',
					),
					'described_by' => 'post-help',
				),
				array(
					'key'          => 'fixture:disabled',
					'label'        => 'Disabled',
					'type'         => 'link',
					'url'          => '',
					'disabled'     => true,
					'described_by' => 'disabled-help',
				),
				array(
					'key'          => 'fixture:link',
					'label'        => 'Link',
					'type'         => 'link',
					'url'          => 'https://example.test/link',
					'described_by' => 'link-help',
				),
			),
		);
		ob_start();
		( new RepositoryDetailRenderer() )->render( $row, 'Third Provider', 'https://example.test/repositories', 'https://example.test/activity', true, '', 'branch', $this->view_urls(), $this->view_request_urls(), null, null );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-webhook-heading"', $html );
		if ( 1 !== preg_match( '/<a href="[^"]*" target="_blank" rel="noopener noreferrer">Open on Third Provider\s+(.*?)<\/a>/s', $html, $m ) ) {
			self::fail( 'The external repository header link is missing.' );
		}
		self::assertSame( '<span aria-hidden="true">↗</span><span class="screen-reader-text"> (opens in a new tab)</span>', $m[1] );
		self::assertStringContainsString( 'aria-describedby="post-help"', $html );
		self::assertStringContainsString( 'aria-describedby="disabled-help"', $html );
		self::assertStringContainsString( 'aria-describedby="link-help"', $html );
	}

	public function test_branch_repository_without_assisted_management_retains_manual_guidance_without_inventing_an_action(): void {
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'        => 'owner/branch',
				'repository_url'    => '',
				'source_label'      => 'Branch',
				'source_key'        => 'branch',
				'package_summaries' => array(),
				'details'           => array(),
				'actions'           => array(),
			),
			'Bitbucket',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'branch',
			$this->view_urls(),
			$this->view_request_urls(),
			null,
			null
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<h3 id="ran-booster-repository-webhook-heading">Push-to-deploy</h3>', $html );
		self::assertStringContainsString( 'Assisted webhook management is unavailable in this view.', $html );
		self::assertStringContainsString( 'Use the Git provider webhook settings when available.', $html );
		self::assertStringNotContainsString( 'no Branch package currently uses this repository webhook', $html );
		self::assertStringNotContainsString( 'Manage repository webhook', $html );
	}


	public function test_missing_management_and_receiver_readiness_are_independent(): void {
		foreach ( array( true, false ) as $ready ) {
			ob_start();
			( new RepositoryDetailRenderer() )->render(
				array(
					'repository'        => 'owner/branch',
					'source_key'        => 'branch',
					'package_summaries' => array(),
					'details'           => array(),
					'actions'           => array(),
				),
				'Fixture',
				'/repositories',
				'/activity',
				$ready,
				'',
				'branch',
				$this->view_urls(),
				$this->view_request_urls(),
				null,
				null
			);
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'Assisted webhook management is unavailable in this view.', $html );
			self::assertStringNotContainsString( 'unavailable until', $html );
			if ( ! $ready ) {
				self::assertStringContainsString( 'Receiver readiness has not been established.', $html );
			}
		}
	}

	public function test_throwing_release_callback_keeps_neutral_fallback(): void {
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'        => 'owner/release',
				'source_key'        => 'release_asset',
				'package_summaries' => array(),
				'details'           => array(),
				'actions'           => array(),
			),
			'Fixture',
			'/repositories',
			'/activity',
			true,
			'',
			'releases',
			$this->view_urls(),
			$this->view_request_urls(),
			null,
			static function (): void {
				throw new \RuntimeException( 'failure' );
			}
		);
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Release workflow status is unavailable in this view.', $html );
		self::assertStringNotContainsString( 'unavailable for this Git provider', $html );
	}

	public function test_bounded_fragments_preserve_preescaped_markup_and_escape_owned_row_values(): void {
		foreach ( array( 'branch', 'releases' ) as $view ) {
			$unsafe   = '<script>alert("fixture")</script>';
			$fragment = static function () use ( $unsafe ): bool {
				echo '<form method="post" hx-post="https://example.test/action" hx-target="#result"><input name="_wpnonce" value="fixture-nonce"><span>';
				echo esc_html( $unsafe );
				echo '</span></form>';
				return true;
			};
			ob_start();
			( new RepositoryDetailRenderer() )->render(
				array(
					'repository' => $unsafe,
					'source_key' => 'branch',
				),
				$unsafe,
				'',
				'',
				true,
				'',
				$view,
				$this->view_urls(),
				$this->view_request_urls(),
				'branch' === $view ? $fragment : null,
				'releases' === $view ? static function () use ( $fragment ): void {
					$fragment();
				} : null
			);
			$html = (string) ob_get_clean();
			self::assertStringNotContainsString( $unsafe, $html );
			self::assertStringContainsString( esc_html( $unsafe ), $html );
			self::assertStringContainsString( '<form method="post" hx-post="https://example.test/action" hx-target="#result">', $html );
			self::assertStringContainsString( '<input name="_wpnonce" value="fixture-nonce">', $html );
			self::assertSame( 1, substr_count( $html, 'data-ran-booster-card="provider"' ) );
			self::assertStringNotContainsString( '&lt;form', $html );
		}
	}

	public function test_empty_or_throwing_fragments_restore_buffers_and_show_bounded_fallbacks(): void {
		foreach ( array( 'branch', 'releases' ) as $view ) {
			foreach ( array( false, true ) as $throws ) {
				$fragment = static function () use ( $throws ): bool {
					if ( $throws ) {
						echo 'discarded partial fragment';
						ob_start();
						throw new \RuntimeException( 'private callback failure' );
					}
					echo '  ';
					return true;
				};
				$level    = ob_get_level();
				ob_start();
				try {
					( new RepositoryDetailRenderer() )->render(
						array(
							'repository' => 'owner/repository',
							'source_key' => 'branch',
						),
						'Fixture',
						'',
						'',
						true,
						'',
						$view,
						$this->view_urls(),
						$this->view_request_urls(),
						'branch' === $view ? $fragment : null,
						'releases' === $view ? static function () use ( $fragment ): void {
							$fragment();
						} : null
					);
					self::assertSame( $level + 1, ob_get_level() );
					$html = (string) ob_get_contents();
					self::assertStringNotContainsString( 'discarded partial fragment', $html );
					self::assertStringNotContainsString( 'private callback failure', $html );
					self::assertStringContainsString( 'branch' === $view ? 'Repository webhook management is temporarily unavailable' : 'Release workflow status is unavailable', $html );
					self::assertStringContainsString( 'data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-' . ( 'branch' === $view ? 'webhook' : 'release' ) . '-heading"', $html );
				} finally {
					while ( ob_get_level() > $level ) {
						ob_end_clean();
					}
				}
				self::assertSame( $level, ob_get_level() );
			}
		}
	}

	/** @return array<string, string> */
	private function view_urls(): array {
		return array(
			'status'   => 'https://example.test/repository?repository_view=status',
			'branch'   => 'https://example.test/repository?repository_view=branch',
			'releases' => 'https://example.test/repository?repository_view=releases',
		);
	}

	/** @return array<string, string> */
	private function view_request_urls(): array {
		return array_map( static fn ( string $url ): string => $url . '&ran_booster_provider_fragment=1', $this->view_urls() );
	}
}
