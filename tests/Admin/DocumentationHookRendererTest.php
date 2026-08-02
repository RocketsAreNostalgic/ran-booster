<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/DocumentationHookWordPressFunctions.php';
require_once __DIR__ . '/../Logging/LoggingWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/DocumentationHookRenderer.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RAN\Admin\DocumentationHookRenderer;

final class DocumentationHookRendererTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_documentation_test_actions'] = array();
		$GLOBALS['ran_booster_documentation_test_filters'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_documentation_test_actions'], $GLOBALS['ran_booster_documentation_test_filters'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testRendersAValidatedStructuredSectionThroughTheCoreDisclosureShell(): void {
		$received = array();
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_after_provider_gh'][] =
			static function ( array $sections, string $url, string $scope ) use ( &$received ): array {
				$received   = array( $url, $scope );
				$sections[] = array(
					'id'      => 'fixture-guide',
					'summary' => 'Fixture guide',
					'content' => static function (): void {
						echo '<h3>Vanilla heading</h3><p>Guide <strong>content</strong><script>unsafe()</script></p>';
					},
				);

				return $sections;
			};

		ob_start();
		( new DocumentationHookRenderer() )->renderSections(
			'ran_booster_documentation_sections_after_provider_gh',
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation',
			'network',
			'gh'
		);
		$html = (string) ob_get_clean();

		self::assertSame(
			array(
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation',
				'network',
			),
			$received
		);
		self::assertStringContainsString( '<details id="fixture-guide" class="ran-booster-documentation__section ran-booster-panel">', $html );
		self::assertStringContainsString( '<summary>Fixture guide</summary>', $html );
		self::assertStringContainsString( '<div class="ran-booster-documentation__content"><h3>Vanilla heading</h3><p>Guide <strong>content</strong></p></div>', $html );
		self::assertStringNotContainsString( '<script>', $html );
	}
}
