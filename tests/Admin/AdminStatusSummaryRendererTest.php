<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\AdminStatusSummaryRenderer;

final class AdminStatusSummaryRendererTest extends TestCase {

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function states(): iterable {
		yield 'neutral' => array( AdminStatusSummaryRenderer::NEUTRAL, 'none' );
		yield 'pending' => array( AdminStatusSummaryRenderer::PENDING, 'none' );
		yield 'ready' => array( AdminStatusSummaryRenderer::READY, 'stable' );
		yield 'attention' => array( AdminStatusSummaryRenderer::ATTENTION, 'medium' );
	}

	#[DataProvider( 'states' )]
	public function test_it_renders_the_shared_structure_for_every_supported_state( string $state, string $intent ): void {
		ob_start();
		( new AdminStatusSummaryRenderer() )->render(
			state: $state,
			heading: 'Status <heading>',
			description: 'Status & description',
			render_actions: static function (): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed component-slot fixture.
				echo '<button type="button" class="button">Act</button>';
			},
			status_label: 'State <label>'
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'ran-booster-status-summary ran-booster-status-summary--' . $state, $html );
		self::assertStringContainsString( '<p class="ran-booster-status-summary__heading"><strong>Status &lt;heading&gt;</strong> <span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-' . $intent . '-intent" data-ran-booster-badge data-ran-booster-badge-intent="' . $intent . '">State &lt;label&gt;</span></p>', $html );
		self::assertStringNotContainsString( 'ran-booster-status-dot', $html );
		self::assertStringContainsString( 'Status &lt;heading&gt;', $html );
		self::assertStringContainsString( 'Status &amp; description', $html );
		self::assertStringContainsString( '<button type="button" class="button">Act</button>', $html );
	}

	public function test_it_rejects_unsupported_states(): void {
		$this->expectException( InvalidArgumentException::class );

		( new AdminStatusSummaryRenderer() )->render(
			'warningish',
			'Heading',
			'Description',
			static function (): void {},
			'Label'
		);
	}

	public function test_it_rejects_an_empty_status_label(): void {
		$this->expectException( InvalidArgumentException::class );

		( new AdminStatusSummaryRenderer() )->render(
			AdminStatusSummaryRenderer::READY,
			'Heading',
			'Description',
			static function (): void {},
			' '
		);
	}
}
