<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\RepositoryDetailRenderer;

final class RepositoryDetailRendererTest extends TestCase {

	public function testStatusRendersMixedPackagesAndIntegrationHistoryWithoutMutationAuthority(): void {
		$row = array(
			'repository'                => 'owner/shared',
			'repository_url'            => 'https://github.com/owner/shared',
			'source_label'              => 'Mixed sources',
			'source_key'                => 'mixed',
			'package_summaries_omitted' => 3,
			'package_summaries'         => array(
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
			'details'                   => array(
				array(
					'key'   => 'core:webhook-recorded-status',
					'label' => 'Recorded hook',
					'value' => 'Configured at last check',
				),
				array(
					'key'   => 'gh:release-automation-a',
					'label' => 'Provider workflow detail',
					'value' => 'Ready to assess',
					'tone'  => 'ok',
				),
				array(
					'key'   => 'provider:release-workflow',
					'label' => 'Release workflow — owner/theme',
					'value' => 'Configured',
				),
				array(
					'key'   => 'provider:legacy-release-workflow',
					'label' => 'Release automation — owner/legacy.php',
					'value' => 'Legacy',
				),
			),
			'actions'                   => array(
				array(
					'key'      => 'fixture:provider-webhooks',
					'label'    => 'Open fixture webhooks',
					'type'     => 'link',
					'url'      => 'https://example.test/provider-webhooks',
					'external' => true,
				),
				array(
					'key'   => 'gh:release-automation-a',
					'label' => 'Release automation: owner/plugin.php',
					'url'   => 'https://example.test/plugins?source_view=release_asset',
				),
			),
		);

		$webhookRendered = false;
		$releaseRendered = false;
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			$row,
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'status',
			$this->viewUrls(),
			$this->viewRequestUrls(),
			static function () use ( &$webhookRendered ): void {
					$webhookRendered = true;
					echo '<div data-test-webhook></div>';
			},
			static function () use ( &$releaseRendered ): void {
					$releaseRendered = true;
					echo '<div data-test-release></div>';
			}
		);
		$html = (string) ob_get_clean();

		self::assertFalse( $webhookRendered );
		self::assertFalse( $releaseRendered );
		self::assertStringContainsString( '2 packages shown; 3 more connected · Mixed sources', $html );
		self::assertStringContainsString( 'Branch · main · packages/plugin', $html );
		self::assertStringContainsString( '<dt>Releases</dt>', $html );
		self::assertStringContainsString( '>Releases', $html );
		self::assertStringContainsString( 'Ignores pushes', $html );
		self::assertStringContainsString( 'Plugin settings', $html );
		self::assertStringContainsString( 'Theme settings', $html );
		self::assertStringContainsString( '>Automatic<', $html );
		self::assertStringContainsString( '>Manual<', $html );
		self::assertStringContainsString( 'Integration status', $html );
		self::assertStringContainsString( 'hx-target="#ran-booster-provider-profile-region"', $html );
		self::assertStringContainsString( 'hx-select="#ran-booster-provider-profile-region"', $html );
		self::assertSame( 2, substr_count( $html, 'ran-booster-provider-task-tab__source-indicator' ) );
		self::assertSame( 2, substr_count( $html, 'Active for one or more packages in this repository.' ) );
		self::assertStringContainsString( 'data-ran-booster-repository-view="status" aria-controls="ran-booster-provider-task-panel" aria-current="page"', $html );
		self::assertStringNotContainsString( 'Status is configured for this repository.', $html );
		self::assertStringContainsString( '1 package uses Branch', $html );
		self::assertStringContainsString( '1 package tracks Releases', $html );
		self::assertStringContainsString( 'Provider workflow detail', $html );
		self::assertStringContainsString( '<h4>Release workflow</h4>', $html );
		self::assertStringContainsString( 'Release workflow — owner/theme', $html );
		self::assertStringContainsString( 'Release automation — owner/legacy.php', $html );
		$webhookHistoryPosition = strpos( $html, 'Configured at last check' );
		$releaseHistoryPosition = strpos( $html, '<h4>Release workflow</h4>' );
		self::assertIsInt( $webhookHistoryPosition );
		self::assertIsInt( $releaseHistoryPosition );
		self::assertTrue( $webhookHistoryPosition < $releaseHistoryPosition );
		self::assertTrue( $releaseHistoryPosition < strrpos( $html, 'Provider workflow detail' ) );
		self::assertTrue( $releaseHistoryPosition < strpos( $html, 'Release workflow — owner/theme' ) );
		self::assertTrue( $releaseHistoryPosition < strpos( $html, 'Release automation — owner/legacy.php' ) );
		self::assertStringNotContainsString( 'data-test-webhook', $html );
		self::assertStringNotContainsString( 'data-test-release', $html );
		self::assertStringNotContainsString( 'Provider receiver', $html );
		self::assertStringNotContainsString( 'Receiver ready.', $html );
		self::assertStringContainsString( 'This is local history, not live provider state.', $html );
		self::assertStringNotContainsString( 'name="repository_webhook_management_operation"', $html );
	}

	public function testBranchViewShowsDisabledWebhookContextForReleaseOnlyRepository(): void {
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
				'actions'           => array(
					array(
						'key'      => 'fixture:provider-webhooks',
						'label'    => 'Open fixture webhooks',
						'type'     => 'link',
						'url'      => 'https://example.test/provider-webhooks',
						'external' => true,
					),
				),
			),
			'Bitbucket',
			'https://example.test/repositories',
			'https://example.test/activity',
			false,
			'Receiver unavailable.',
			'branch',
			$this->viewUrls(),
			$this->viewRequestUrls(),
			null,
			null
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'no Branch package currently uses this repository webhook', $html );
		self::assertSame( 1, substr_count( $html, 'class="ran-booster-settings-section ran-booster-repository-webhook-section"' ) );
		self::assertStringContainsString( 'class="ran-booster-repository-webhook-setup"', $html );
		self::assertStringContainsString( 'class="ran-booster-settings-section ran-booster-repository-webhook-section"', $html );
		self::assertStringContainsString( 'Webhook setup', $html );
		self::assertStringNotContainsString( '<details', $html );
		self::assertStringContainsString( 'disabled aria-disabled="true"', $html );
		self::assertStringContainsString( '>Set up webhook</button>', $html );
		self::assertStringContainsString( 'Open fixture webhooks', $html );
		self::assertStringContainsString( 'Management history', $html );
		self::assertStringContainsString( 'Recorded hook status', $html );
		self::assertStringContainsString( 'Managed hook not yet set', $html );
		self::assertStringContainsString( 'No historical observation', $html );
		self::assertStringContainsString( 'Recorded hook profile', $html );
		self::assertStringContainsString( 'Last checked', $html );
		self::assertStringContainsString( 'Never', $html );
		self::assertStringContainsString( 'View repository activity', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
	}

	public function testPublishedReleasesViewUsesProviderPanelAndKeepsPackageControlsLinked(): void {
		$row             = array(
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
					'key'   => 'gh:release-automation-a',
					'label' => 'Release automation',
					'value' => 'Configured',
				),
			),
			'actions'           => array(),
		);
		$releaseRendered = false;

		ob_start();
		( new RepositoryDetailRenderer() )->render(
			$row,
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			'releases',
			$this->viewUrls(),
			$this->viewRequestUrls(),
			null,
			static function () use ( &$releaseRendered ): void {
				$releaseRendered = true;
				echo '<section data-test-release>Exact package workflow controls</section>';
			}
		);
		$html = (string) ob_get_clean();

		self::assertTrue( $releaseRendered );
		self::assertStringContainsString( 'data-ran-booster-repository-view="releases" aria-controls="ran-booster-provider-task-panel" aria-current="page"', $html );
		self::assertStringContainsString( 'data-test-release', $html );
		self::assertStringNotContainsString( 'Packages using this repository', $html );
		self::assertStringContainsString( '<h4>Release workflow</h4>', $html );
		self::assertStringContainsString( 'Configured', $html );
	}

	public function testUnsupportedProviderKeepsReleaseControlsVisibleButDisabled(): void {
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
			$this->viewUrls(),
			$this->viewRequestUrls(),
			null,
			null
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<h3 id="ran-booster-repository-release-heading">Release publishing</h3>', $html );
		self::assertStringContainsString( 'Release workflow setup is unavailable for this repository provider.', $html );
		self::assertStringContainsString( 'Open Theme settings', $html );
		self::assertStringContainsString( 'disabled aria-disabled="true"', $html );
		self::assertStringContainsString( 'Assess release setup', $html );
	}

	/** @return array<string, string> */
	private function viewUrls(): array {
		return array(
			'status'   => 'https://example.test/repository?repository_view=status',
			'branch'   => 'https://example.test/repository?repository_view=branch',
			'releases' => 'https://example.test/repository?repository_view=releases',
		);
	}

	/** @return array<string, string> */
	private function viewRequestUrls(): array {
		return array_map( static fn ( string $url ): string => $url . '&ran_booster_provider_fragment=1', $this->viewUrls() );
	}
}
