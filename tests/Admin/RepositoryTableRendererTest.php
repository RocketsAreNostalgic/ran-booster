<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\RepositoryTableRenderer;

final class RepositoryTableRendererTest extends TestCase {

	public function testItOwnsTheCompleteCommonRepositoryMarkup(): void {
		$html = $this->render(
			array(
				'provider_label'     => 'GitHub',
				'repository'         => 'owner/<unsafe>',
				'repository_url'     => 'https://github.com/owner/repository',
				'package_type_label' => 'Plugin',
				'source_label'       => 'Branch',
				'management_label'   => 'Disabled',
				'management_detail'  => 'Owner secret',
				'management_tone'    => 'ok',
				'consequence'        => 'Push-to-Deploy disabled; pushes are ignored.',
				'types'              => array(
					array(
						'label' => 'Plugin',
						'tone'  => 'pending',
					),
				),
				'policies'           => array(
					array(
						'label' => 'Disabled',
						'tone'  => 'neutral',
					),
				),
				'package_references' => array( 'first/plugin.php', 'second/plugin.php' ),
				'statuses'           => array(
					array(
						'label' => 'Owner secret',
						'tone'  => 'ok',
					),
				),
				'status_links'       => array(
					array(
						'label'  => 'Set webhook secret',
						'url'    => 'https://example.test/secret',
						'modal'  => 'webhook',
						'scope'  => 'repository',
						'target' => 'owner/repository',
					),
				),
				'actions'            => array(
					array(
						'label'        => 'Manage webhook',
						'disabled'     => true,
						'described_by' => 'reason-id',
					),
					array(
						'label'    => 'GitHub Hooks',
						'url'      => 'https://github.com/owner/repository/settings/hooks',
						'external' => true,
					),
					array(
						'label'         => 'Plugin settings',
						'url'           => 'https://example.test/settings',
						'screen_reader' => 'first/plugin.php',
					),
				),
			)
		);

		self::assertStringContainsString( 'aria-labelledby="managed-repositories-heading"', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-list" role="list"', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-record"', $html );
		self::assertStringContainsString( 'role="listitem"', $html );
		self::assertStringContainsString( 'data-ran-booster-provider-repository', $html );
		self::assertStringContainsString( 'owner/&lt;unsafe&gt;', $html );
		self::assertStringContainsString( 'Plugin · Branch · 2 packages', $html );
		self::assertStringContainsString( 'Disabled', $html );
		self::assertStringContainsString( 'ran-booster-repository-record__management-detail--ok">Owner secret</span>', $html );
		self::assertStringContainsString( '<p>Push-to-Deploy disabled; pushes are ignored.</p>', $html );
		self::assertStringContainsString( 'class="ran-booster-repository-record__status-links"', $html );
		self::assertStringContainsString( 'href="https://example.test/secret">Set webhook secret</a>', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-record__policies', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-record__status-badges', $html );
		self::assertStringContainsString( '2 packages use this repository', $html );
		self::assertStringContainsString( '<code>first/plugin.php</code>', $html );
		self::assertStringContainsString( 'disabled aria-disabled="true" aria-describedby="reason-id"', $html );
		self::assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		self::assertStringContainsString( '<span class="screen-reader-text">: first/plugin.php</span>', $html );
		self::assertLessThan( strpos( $html, 'GitHub Hooks' ), strpos( $html, 'Manage webhook' ) );
		self::assertLessThan( strpos( $html, 'Plugin settings' ), strpos( $html, 'GitHub Hooks' ) );
		self::assertStringNotContainsString( 'ran-booster-repository-record__details', $html );
	}

	public function testItAddsOnlyStructuredOptionalDetails(): void {
		$html = $this->render(
			array(
				'provider_label' => 'GitHub',
				'repository'     => 'owner/repository',
				'details'        => array(
					array(
						'label' => 'Assisted hook status',
						'value' => 'No assisted hook recorded',
						'tone'  => 'warning',
					),
					array(
						'label' => 'Recorded hook profile',
						'value' => 'Assisted hook not yet set',
					),
					array(
						'label'    => 'Last checked',
						'value'    => 'Never',
						'datetime' => '',
					),
				),
			)
		);

		self::assertSame( 1, substr_count( $html, 'class="ran-booster-repository-record__details"' ) );
		self::assertStringContainsString( 'ran-booster-badge--warning">No assisted hook recorded</span>', $html );
		self::assertStringContainsString( '<strong>Recorded hook profile</strong>', $html );
		self::assertStringContainsString( '<span>Assisted hook not yet set</span>', $html );
		self::assertStringContainsString( '<strong>Last checked</strong>', $html );
		self::assertStringContainsString( '<span>Never</span>', $html );
	}

	public function testItRendersReleaseManagementAsInertWithoutHidingPackageSettings(): void {
		$html = $this->render(
			array(
				'repository'         => 'owner/release-managed',
				'repository_url'     => 'https://github.com/owner/release-managed',
				'repository_id'      => 'release-42',
				'package_type_label' => 'Theme',
				'source_key'         => 'release_asset',
				'source_label'       => 'Published release',
				'management_label'   => 'Published release',
				'management_detail'  => 'Push-to-Deploy unavailable',
				'management_tone'    => 'info',
				'consequence'        => 'Managed by Published releases. Switch to Branch in package settings to enable webhooks.',
				'consequence_id'     => 'release-source-reason',
				'package_references' => array( 'release-theme' ),
				'actions'            => array(
					array(
						'key'          => 'core:webhook-management',
						'label'        => 'Manage webhook',
						'disabled'     => true,
						'described_by' => 'release-source-reason',
					),
					array(
						'key'          => 'core:provider-webhooks',
						'label'        => 'GitHub webhooks',
						'disabled'     => true,
						'described_by' => 'release-source-reason',
					),
					array(
						'key'      => 'core:package-release',
						'label'    => 'Theme settings',
						'url'      => 'https://example.test/theme-settings',
						'disabled' => false,
					),
				),
			)
		);

		self::assertStringContainsString( 'ran-booster-repository-record--release', $html );
		self::assertStringContainsString( 'Theme · Published release · 1 package', $html );
		self::assertStringContainsString( 'ran-booster-repository-record__management-detail--info">Push-to-Deploy unavailable</span>', $html );
		self::assertStringContainsString( 'id="release-source-reason"', $html );
		self::assertSame( 2, substr_count( $html, 'disabled aria-disabled="true" aria-describedby="release-source-reason"' ) );
		self::assertStringContainsString( 'class="button" href="https://example.test/theme-settings"', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-record__details', $html );
	}

	public function testItConsolidatesMultiplePackageSettingsIntoOneControl(): void {
		$html = $this->render(
			array(
				'repository'         => 'owner/shared',
				'package_references' => array( 'plugin/one.php', 'plugin/two.php' ),
				'actions'            => array(
					array(
						'key'      => 'core:package-one',
						'label'    => 'Plugin settings',
						'url'      => 'https://example.test/settings-one',
						'disabled' => false,
					),
					array(
						'key'      => 'core:package-two',
						'label'    => 'Plugin settings',
						'url'      => 'https://example.test/settings-two',
						'disabled' => false,
					),
				),
			)
		);

		self::assertStringContainsString( 'class="ran-booster-repository-record__settings-menu"', $html );
		self::assertStringContainsString( '<summary class="button">Package settings</summary>', $html );
		self::assertStringContainsString( 'href="https://example.test/settings-one"', $html );
		self::assertStringContainsString( 'href="https://example.test/settings-two"', $html );
	}

	public function testItKeepsUnavailableReleaseAutomationNavigationEnabled(): void {
		$html = $this->render(
			array(
				'repository' => 'owner/repository',
				'details'    => array(
					array(
						'label' => 'Release automation',
						'value' => 'Unavailable',
						'tone'  => 'warning',
					),
				),
				'actions'    => array(
					array(
						'key'           => 'gh:release-automation-example',
						'label'         => 'Release automation',
						'url'           => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php&source_view=release_asset#ran-booster-advanced-source-settings',
						'disabled'      => false,
						'screen_reader' => 'example/example.php',
					),
				),
			)
		);

		self::assertStringContainsString( 'ran-booster-badge--warning">Unavailable</span>', $html );
		self::assertStringContainsString( 'class="button" href="https://example.test/wp-admin/admin.php?page=ran-booster-plugins&amp;package=example%2Fexample.php&amp;source_view=release_asset#ran-booster-advanced-source-settings"', $html );
		self::assertStringNotContainsString( 'disabled aria-disabled="true"', $html );
	}

	/** @param array<string, mixed> $row */
	private function render( array $row ): string {
		ob_start();
		( new RepositoryTableRenderer() )->render( 'managed-repositories-heading', array( $row ) );

		return (string) ob_get_clean();
	}
}
