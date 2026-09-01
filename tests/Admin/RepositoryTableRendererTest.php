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
				'detail_url'         => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&panel=repositories&repository=42',
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
		self::assertStringNotContainsString( 'ran-booster-repository-record__policies', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-record__status-badges', $html );
		self::assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		self::assertStringContainsString( 'Manage repository', $html );
		self::assertStringContainsString( 'repository=42', html_entity_decode( $html ) );
		self::assertSame( 1, substr_count( $html, 'class="button"' ) );
		self::assertStringNotContainsString( 'Manage webhook', $html );
		self::assertStringNotContainsString( 'Plugin settings', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-record__details', $html );
	}

	public function testItKeepsHistoricalWebhookEvidenceInline(): void {
		$html = $this->render(
			array(
				'provider_label' => 'GitHub',
				'repository'     => 'owner/repository',
				'historical'     => true,
				'review_url'     => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting&panel=activity',
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
				'actions'        => array(
					array(
						'label'         => 'Open GitHub repository',
						'type'          => 'link',
						'url'           => 'https://github.com/owner/repository',
						'external'      => true,
						'screen_reader' => 'owner/repository',
					),
				),
			)
		);

		self::assertStringContainsString( 'ran-booster-repository-record__details', $html );
		self::assertStringContainsString( 'Assisted hook status', $html );
		self::assertStringContainsString( 'No assisted hook recorded', $html );
		self::assertStringContainsString( 'Open GitHub repository', $html );
		self::assertStringNotContainsString( 'Review record', $html );
		self::assertStringNotContainsString( 'panel=activity', html_entity_decode( $html ) );
		self::assertStringNotContainsString( 'Manage repository', $html );
	}

	/** @param array<string, mixed> $row */
	private function render( array $row ): string {
		ob_start();
		( new RepositoryTableRenderer() )->render( 'managed-repositories-heading', array( $row ) );

		return (string) ob_get_clean();
	}
}
