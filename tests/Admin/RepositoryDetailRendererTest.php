<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\RepositoryDetailRenderer;

final class RepositoryDetailRendererTest extends TestCase {

	public function testItRendersMixedPackagesWithoutRepositoryMutationAuthority(): void {
		$row = array(
			'repository'        => 'owner/shared',
			'repository_url'    => 'https://github.com/owner/shared',
			'source_label'      => 'Mixed sources',
			'package_summaries_omitted' => 3,
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
					'label' => 'Release automation — owner/plugin.php',
					'value' => 'Ready to assess',
					'tone'  => 'ok',
				),
			),
			'actions'           => array(
				array(
					'key'   => 'gh:release-automation-a',
					'label' => 'Release automation: owner/plugin.php',
					'url'   => 'https://example.test/plugins?source_view=release_asset',
				),
			),
		);

		$webhookRendered = false;
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			$row,
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			static function () use ( &$webhookRendered ): void {
				$webhookRendered = true;
				echo '<div data-test-webhook></div>';
			}
		);
		$html = (string) ob_get_clean();

		self::assertTrue( $webhookRendered );
		self::assertStringContainsString( '2 packages shown; 3 more connected · Mixed sources', $html );
		self::assertStringContainsString( 'Branch · main · packages/plugin', $html );
		self::assertStringContainsString( 'Published releases', $html );
		self::assertStringContainsString( 'Ignores pushes', $html );
		self::assertStringContainsString( 'Plugin settings', $html );
		self::assertStringContainsString( 'Theme settings', $html );
		self::assertStringContainsString( '>Automatic<', $html );
		self::assertStringContainsString( '>Manual<', $html );
		self::assertStringContainsString( 'Release automation', $html );
		self::assertStringContainsString( 'This is local history, not live provider state.', $html );
		self::assertStringNotContainsString( 'name="repository_webhook_management_operation"', $html );
	}

	public function testReleaseOnlyRepositoryShowsDisabledWebhookContext(): void {
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
			null
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'No eligible Branch package', $html );
		self::assertStringContainsString( 'disabled aria-disabled="true"', $html );
		self::assertStringNotContainsString( 'GitHub', $html );
	}
}
