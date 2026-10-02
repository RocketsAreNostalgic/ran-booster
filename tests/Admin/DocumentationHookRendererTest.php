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
	public function test_renders_avalidated_structured_section_through_the_core_disclosure_shell(): void {
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
		( new DocumentationHookRenderer() )->render_sections(
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
		self::assertStringContainsString( '<details id="fixture-guide" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section>', $html );
		self::assertStringContainsString( '<summary>Fixture guide</summary>', $html );
		self::assertStringContainsString( '<div class="ran-booster-documentation__content"><h3>Vanilla heading</h3><p>Guide <strong>content</strong></p></div>', $html );
		self::assertStringNotContainsString( '<script>', $html );
	}

	public function test_strips_nested_ids_from_sanitized_add_on_content(): void {
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_before_about'][] =
			static function ( array $sections ): array {
				$sections[] = array(
					'id'      => 'safe-guide',
					'summary' => 'Safe guide',
					'content' => '<h3 id="core-heading">Guide</h3><p id=unquoted-id>Safe <strong id=\'quoted-id\'>content</strong><script>unsafe()</script></p>',
				);

				return $sections;
			};

		ob_start();
		( new DocumentationHookRenderer() )->render_sections(
			'ran_booster_documentation_sections_before_about',
			'https://example.test/documentation',
			'site'
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<details id="safe-guide"', $html );
		self::assertStringContainsString( '<h3>Guide</h3><p>Safe <strong>content</strong></p>', $html );
		self::assertStringNotContainsString( 'core-heading', $html );
		self::assertStringNotContainsString( 'unquoted-id', $html );
		self::assertStringNotContainsString( 'quoted-id', $html );
		self::assertStringNotContainsString( '<script>', $html );
	}

	public function test_prepares_only_renderable_sections_and_resolves_callable_content_once(): void {
		$filter_calls   = 0;
		$callable_calls = 0;
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_before_about'][] =
			static function ( array $sections ) use ( &$filter_calls, &$callable_calls ): array {
				++$filter_calls;
				$sections[] = array(
					'id'      => 'resolved-guide',
					'summary' => 'Resolved guide',
					'content' => static function () use ( &$callable_calls ): void {
						++$callable_calls;
						echo '<p>Resolved once.</p>';
					},
				);
				$sections[] = array(
					'id'      => 'empty-guide',
					'summary' => 'Empty guide',
					'content' => ' ',
				);
				$sections[] = array(
					'id'      => 'invalid id',
					'summary' => 'Invalid guide',
					'content' => '<p>Ignored.</p>',
				);

				return $sections;
			};

		$sections = ( new DocumentationHookRenderer() )->prepare_sections(
			'ran_booster_documentation_sections_before_about',
			'https://example.test/documentation',
			'site'
		);

		self::assertSame( 1, $filter_calls );
		self::assertSame( 1, $callable_calls );
		self::assertSame( array( 'resolved-guide' ), array_column( $sections, 'id' ) );
		self::assertSame( '<p>Resolved once.</p>', $sections[0]['content'] );
	}

	public function test_callable_failure_keeps_the_original_stable_id_and_summary(): void {
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_documentation_sections_before_about'][] =
			static function ( array $sections ): array {
				$sections[] = array(
					'id'      => 'failed-guide',
					'summary' => 'Failed <guide>',
					'content' => static function (): void {
						throw new \RuntimeException( 'Fixture failure' );
					},
				);

				return $sections;
			};

		$renderer = new DocumentationHookRenderer();
		$sections = $renderer->prepare_sections( 'ran_booster_documentation_sections_before_about', 'https://example.test/documentation', 'site' );

		ob_start();
		$renderer->render_prepared_sections( $sections );
		$html = (string) ob_get_clean();

		self::assertSame( array( 'failed-guide' ), array_column( $sections, 'id' ) );
		self::assertStringContainsString( '<summary>Failed &lt;guide&gt;</summary>', $html );
		self::assertStringContainsString( 'An add-on guide is temporarily unavailable. Check plugin compatibility and the error log.', $html );
	}
}
