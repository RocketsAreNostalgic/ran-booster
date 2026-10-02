<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\AdminStatusSummaryRenderer;

final class AdminStatusSummaryRendererTest extends TestCase {

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function states(): iterable {
		yield 'neutral' => array( AdminStatusSummaryRenderer::NEUTRAL );
		yield 'pending' => array( AdminStatusSummaryRenderer::PENDING );
		yield 'ready' => array( AdminStatusSummaryRenderer::READY );
		yield 'attention' => array( AdminStatusSummaryRenderer::ATTENTION );
	}

	#[DataProvider( 'states' )]
	public function test_it_renders_the_shared_structure_for_every_supported_state( string $state ): void {
		ob_start();
		( new AdminStatusSummaryRenderer() )->render(
			state: $state,
			heading: 'Status <heading>',
			description: 'Status & description',
			render_actions: static function (): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed component-slot fixture.
				echo '<button type="button" class="button">Act</button>';
			}
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'ran-booster-status-summary ran-booster-status-summary--' . $state, $html );
		self::assertStringContainsString( 'ran-booster-status-dot is-' . $state, $html );
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
			static function (): void {}
		);
	}
}
