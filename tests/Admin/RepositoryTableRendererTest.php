<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\RepositoryTableRenderer;

final class RepositoryTableRendererTest extends TestCase {
	public function test_historical_badges_preserve_exact_labels_and_only_map_known_tones(): void {
		foreach ( array(
			'neutral' => 'none',
			'pending' => 'none',
			'ok'      => 'stable',
			'success' => 'stable',
			'warning' => 'medium',
			'error'   => 'high',
			'info'    => 'informational',
			'future'  => '',
		) as $tone => $intent ) {
			$html = $this->render(
				array(
					'repository' => 'owner/history',
					'historical' => true,
					'details'    => array(
						array(
							'label' => 'Recorded fact',
							'value' => 'Exact <status> "quoted"',
							'tone'  => $tone,
						),
					),
				)
			);
			// An unknown tone keeps the exact label and renders as a neutral Badge.
			$intent = '' === $intent ? 'none' : $intent;
			self::assertStringContainsString( '<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-' . $intent . '-intent" data-ran-booster-badge data-ran-booster-badge-intent="' . $intent . '">Exact &lt;status&gt; &quot;quoted&quot;</span>', $html );
		}
		// An empty recorded fact never takes the tone's intent; it stays a neutral, empty Badge.
		$empty = $this->render(
			array(
				'historical' => true,
				'details'    => array(
					array(
						'label' => 'Empty recorded fact',
						'value' => '',
						'tone'  => 'warning',
					),
				),
			)
		);
		self::assertStringNotContainsString( 'is-medium-intent', $empty );
		self::assertStringContainsString( 'is-none-intent" data-ran-booster-badge data-ran-booster-badge-intent="none"></span>', $empty );
	}

	public function test_it_owns_the_complete_common_repository_markup(): void {
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
				'consequence_id'     => 'reason-id',
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
		self::assertStringContainsString( '<p id="reason-id">Push-to-Deploy disabled; pushes are ignored.</p>', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-record__policies', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-record__status-badges', $html );
		self::assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		self::assertStringContainsString( 'Manage repository', $html );
		self::assertStringContainsString( 'repository=42', html_entity_decode( $html ) );
		self::assertSame( 1, substr_count( $html, 'class="ran-wp-ui-reset-a ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-outline"' ) );
		self::assertStringNotContainsString( 'class="button', $html );
		self::assertStringNotContainsString( 'Manage webhook', $html );
		self::assertStringNotContainsString( 'Plugin settings', $html );
		self::assertStringNotContainsString( 'ran-booster-repository-record__details', $html );
	}

	public function test_it_renders_normalized_historical_link_evidence_inline(): void {
		$html = $this->render(
			array(
				'provider_label' => 'GitHub',
				'repository'     => 'owner/repository',
				'historical'     => true,
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
						'key'           => 'github:open-repository',
						'label'         => 'Open GitHub repository',
						'type'          => 'link',
						'url'           => 'https://github.com/owner/repository',
						'hidden'        => array(),
						'disabled'      => false,
						'external'      => true,
						'described_by'  => '',
						'screen_reader' => 'owner/repository',
					),
				),
			)
		);

		self::assertStringContainsString( 'ran-booster-repository-record__details', $html );
		self::assertStringContainsString( 'Assisted hook status', $html );
		self::assertStringContainsString( 'No assisted hook recorded', $html );
		if ( 1 !== preg_match( '/<a href="https:\/\/github\.com\/owner\/repository" target="_blank" rel="noopener noreferrer">(.*?)<\/a>/s', $html, $m ) ) {
			self::fail( 'The external repository link is missing.' );
		}
		// An external destination is a text link marked with a decorative arrow, not a Button.
		self::assertStringContainsString( 'Open GitHub repository', $m[1] );
		self::assertStringContainsString( '<span class="screen-reader-text">: owner/repository</span>', $m[1] );
		self::assertStringContainsString( '<span aria-hidden="true">↗</span><span class="screen-reader-text"> (opens in a new tab)</span>', $m[1] );
		self::assertStringNotContainsString( 'ran-wp-ui-button', $html );
		self::assertStringNotContainsString( '<form ', $html );
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
