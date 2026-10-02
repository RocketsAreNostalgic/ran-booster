<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class DebugCaptureViewTest extends TestCase {

	public function test_inactive_state_explains_scope_and_offers_abounded_capture(): void {
		$html = $this->render_view(
			array(
				'state'         => 'inactive',
				'filename'      => '/private/site/ran-booster-debug.php',
				'absolute_path' => '/private/site/secret-location',
				'secret_canary' => 'inactive-secret-canary',
				'capture_until' => 'ignored-time',
				'delete_after'  => 'ignored-delete-time',
			)
		);

		self::assertStringContainsString( 'This temporary capture does not require or enable WP_DEBUG_LOG.', $html );
		self::assertStringContainsString( '<h3 id="ran-booster-debug-capture-heading">Logging</h3>', $html );
		self::assertStringContainsString( 'It omits PHP, WordPress, theme, and other-plugin messages.', $html );
		self::assertStringContainsString( '<code>ran-booster-debug.php</code>', $html );
		self::assertStringContainsString( 'Beside the credential sidecar', $html );
		self::assertStringContainsString( 'Starting a capture records Booster events for 60 minutes', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="manage-debug-capture"', $html );
		self::assertStringContainsString( 'name="ran_booster[operation]" value="start"', $html );
		self::assertStringContainsString( 'name="_wpnonce" value="ran-booster-manage-debug-capture"', $html );
		self::assertStringContainsString( 'id="ran-booster-debug-capture-region"', $html );
		self::assertStringContainsString( 'data-ran-booster-enhanced-mutation', $html );
		self::assertStringContainsString( 'hx-target="#ran-booster-debug-capture-region"', $html );
		self::assertStringContainsString( '>Start 60-minute capture</button>', $html );
		self::assertStringNotContainsString( 'name="ran_booster[operation]" value="stop"', $html );
		self::assertStringNotContainsString( 'name="ran_booster[operation]" value="delete"', $html );
		self::assertStringNotContainsString( '/private/site', $html );
		self::assertStringNotContainsString( 'inactive-secret-canary', $html );
		self::assertStringNotContainsString( 'ignored-time', $html );
		self::assertStringNotContainsString( 'ignored-delete-time', $html );
	}

	public function test_active_state_escapes_content_and_provides_refresh_stop_and_delete(): void {
		$html = $this->render_view(
			array(
				'state'         => 'active',
				'filename'      => 'ran-booster-debug.php',
				'capture_until' => '23 July <18:30>',
				'content'       => "[ran-booster] safe <event>\nnext & event",
				'secret_canary' => 'active-secret-canary',
			)
		);

		self::assertStringContainsString( '>Capture active</span>', $html );
		self::assertStringContainsString( 'until 23 July &lt;18:30&gt;.', $html );
		self::assertStringContainsString( 'for="ran-booster-debug-capture-content"', $html );
		self::assertStringContainsString( 'id="ran-booster-debug-capture-content"', $html );
		self::assertStringContainsString( 'readonly', $html );
		self::assertStringContainsString( '[ran-booster] safe &lt;event&gt;', $html );
		self::assertStringContainsString( 'next &amp; event', $html );
		self::assertStringContainsString( 'panel=debug-capture', $html );
		self::assertStringContainsString( 'class="ran-booster-button-group ran-booster-debug-capture__actions"', $html );
		self::assertStringContainsString( 'aria-label="Logging capture actions"', $html );
		self::assertStringContainsString( '>Refresh</a>', $html );
		self::assertStringContainsString( 'name="ran_booster[operation]" value="stop"', $html );
		self::assertStringContainsString( 'name="ran_booster[operation]" value="delete"', $html );
		self::assertStringNotContainsString( 'name="ran_booster[operation]" value="start"', $html );
		self::assertSame( 2, substr_count( $html, '<form' ) );
		self::assertSame( 2, preg_match_all( '/<form\b.*?<\/form>/s', $html, $forms ) );
		$delete_forms = array_values(
			array_filter(
				$forms[0],
				static fn ( string $form ): bool => str_contains( $form, 'value="delete"' )
			)
		);
		self::assertCount( 1, $delete_forms );
		self::assertStringContainsString( 'name="_wpnonce" value="ran-booster-manage-debug-capture"', $delete_forms[0] );
		self::assertStringNotContainsString( 'data-ran-booster-enhanced-mutation', $delete_forms[0] );
		self::assertStringNotContainsString( 'hx-', $delete_forms[0] );
		self::assertStringNotContainsString( '<event>', $html );
		self::assertStringNotContainsString( 'active-secret-canary', $html );
	}

	public function test_retained_state_shows_safe_content_and_offers_start_new_and_delete(): void {
		$html = $this->render_view(
			array(
				'state'        => 'retained',
				'filename'     => 'ran-booster-debug.php',
				'delete_after' => '24 July <18:30>',
				'content'      => "[ran-booster] first <event>\n[ran-booster] second & event",
			)
		);

		self::assertStringContainsString( '>Capture retained</span>', $html );
		self::assertStringContainsString( 'until 24 July &lt;18:30&gt;.', $html );
		self::assertStringContainsString( '[ran-booster] first &lt;event&gt;', $html );
		self::assertStringContainsString( '[ran-booster] second &amp; event', $html );
		self::assertStringContainsString( '>Start new capture</button>', $html );
		self::assertStringContainsString( 'name="ran_booster[operation]" value="start"', $html );
		self::assertStringContainsString( 'name="ran_booster[operation]" value="delete"', $html );
		self::assertStringNotContainsString( 'name="ran_booster[operation]" value="stop"', $html );
		self::assertSame( 2, substr_count( $html, '<form' ) );
	}

	public function test_unsafe_states_expose_no_payload_or_management_controls(): void {
		$states = array(
			'unavailable' => 'Temporary logging capture is unavailable because Booster cannot safely use its capture file location.',
			'malformed'   => 'The temporary capture file could not be read safely. Booster left it unchanged.',
		);

		foreach ( $states as $state => $message ) {
			$html = $this->render_view(
				array(
					'state'         => $state,
					'filename'      => 'ran-booster-debug.php',
					'content'       => 'unsafe-content-canary',
					'capture_until' => 'unsafe-time-canary',
					'delete_after'  => 'unsafe-delete-canary',
					'secret'        => 'unsafe-secret-canary',
				)
			);

			self::assertStringContainsString( $message, $html );
			self::assertStringNotContainsString( 'unsafe-content-canary', $html );
			self::assertStringNotContainsString( 'unsafe-time-canary', $html );
			self::assertStringNotContainsString( 'unsafe-delete-canary', $html );
			self::assertStringNotContainsString( 'unsafe-secret-canary', $html );
			self::assertStringNotContainsString( '<form', $html );
			self::assertStringNotContainsString( 'ran_booster[operation]', $html );
		}
	}

	/**
	 * @param array<string, mixed> $payload Debug capture view payload.
	 */
	private function render_view( array $payload ): string {
		$debug_capture        = $payload;
		$troubleshooting_base = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/debug-capture.php';

		return (string) ob_get_clean();
	}
}
