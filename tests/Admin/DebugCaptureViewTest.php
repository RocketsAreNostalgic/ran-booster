<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

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

		self::assertStringContainsString( 'This temporary capture records Booster events only. It does not require or enable <code>WP_DEBUG_LOG</code> and excludes PHP, WordPress, theme, and other-plugin messages.', $html );
		self::assertStringContainsString( '<summary>Logging details</summary>', $html );
		self::assertStringContainsString( 'define( &#039;WP_DEBUG_DISPLAY&#039;, false );', $html );
		self::assertStringNotContainsString( 'notice-warning', $html );
		self::assertStringContainsString( '<h3 id="ran-booster-debug-capture-heading">Logging</h3>', $html );
		self::assertStringContainsString( 'Booster event capture', $html );
		self::assertStringNotContainsString( 'Configured capture file:', $html );
		self::assertStringNotContainsString( 'No temporary logging capture is active.', $html );
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
		$this->assert_empty_capture_field( $html );
		$this->assert_capture_footer( $html, array( 'start' ) );
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
		self::assertStringContainsString( 'data-support-report-tools', $html );
		self::assertStringContainsString( 'data-support-report-copy aria-label="Copy captured events"', $html );
		self::assertStringContainsString( 'dashicons dashicons-clipboard', $html );
		self::assertStringContainsString( 'data-copied-message="Captured events copied to the clipboard."', $html );
		self::assertStringContainsString( 'data-copy-failed-message="Clipboard access failed. The captured events are selected; use your browser copy command."', $html );
		self::assertStringContainsString( '[ran-booster] safe &lt;event&gt;', $html );
		self::assertStringContainsString( 'next &amp; event', $html );
		self::assertStringContainsString( 'panel=debug-capture', $html );
		self::assertStringContainsString( 'class="ran-booster-debug-capture__primary-actions"', $html );
		self::assertStringContainsString( 'rows="10" readonly', $html );
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
		$this->assert_capture_footer( $html, array( 'stop', 'delete' ) );
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
		self::assertStringContainsString( 'data-support-report-copy aria-label="Copy captured events"', $html );
		self::assertStringContainsString( '>Start new capture</button>', $html );
		self::assertStringContainsString( 'Starting a new capture replaces any retained capture.', $html );
		self::assertStringContainsString( 'name="ran_booster[operation]" value="start"', $html );
		self::assertStringContainsString( 'name="ran_booster[operation]" value="delete"', $html );
		self::assertStringNotContainsString( 'name="ran_booster[operation]" value="stop"', $html );
		self::assertSame( 2, substr_count( $html, '<form' ) );
		$this->assert_capture_footer( $html, array( 'start', 'delete' ) );
	}


	public function test_empty_active_and_retained_captures_do_not_offer_copy_controls(): void {
		foreach ( array( 'active', 'retained' ) as $state ) {
			$html = $this->render_view(
				array(
					'state'   => $state,
					'content' => '',
				)
			);

			$this->assert_empty_capture_field( $html );
		}
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
			self::assertStringNotContainsString( 'Configured capture file:', $html );
			self::assertStringNotContainsString( 'unsafe-content-canary', $html );
			self::assertStringNotContainsString( 'unsafe-time-canary', $html );
			self::assertStringNotContainsString( 'unsafe-delete-canary', $html );
			self::assertStringNotContainsString( 'unsafe-secret-canary', $html );
			self::assertStringNotContainsString( '<form', $html );
			self::assertStringNotContainsString( 'ran_booster[operation]', $html );
			self::assertStringNotContainsString( 'ran-booster-debug-capture__footer', $html );
			$this->assert_empty_capture_field( $html );
		}
	}

	private function assert_empty_capture_field( string $html ): void {
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//textarea[@id="ran-booster-debug-capture-content" and @readonly and @rows="10" and @placeholder="No Booster events have been captured yet."])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//button[@data-support-report-copy and @disabled])' ) );
	}

	/** @param list<string> $operations */
	private function assert_capture_footer( string $html, array $operations ): void {
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new \DOMXPath( $document );
		self::assertSame( 0.0, $xpath->evaluate( 'count(//*[contains(@class,"ran-booster-debug-capture__header")]//form)' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count(//*[contains(@class,"ran-booster-debug-capture__footer") and @role="group"])' ) );
		foreach ( $operations as $operation ) {
			self::assertSame( 1.0, $xpath->evaluate( 'count(//*[contains(@class,"ran-booster-debug-capture__footer")]//button[@name="ran_booster[operation]" and @value="' . $operation . '"])' ) );
		}
		self::assertSame( count( $operations ), substr_count( $html, 'name="_wpnonce" value="ran-booster-manage-debug-capture"' ) );
		self::assertLessThan( strpos( $html, 'ran-booster-debug-capture__wordpress-help' ), strpos( $html, 'ran-booster-debug-capture__footer' ) );
	}

	public function test_logging_details_escape_configured_capture_path(): void {
		$html = $this->render_view(
			array(
				'state'           => 'inactive',
				'configured_path' => '/private/<site>/ran-booster-debug.php',
			)
		);
		self::assertStringContainsString( '<code>/private/&lt;site&gt;/ran-booster-debug.php</code>', $html );
		self::assertStringNotContainsString( '/private/<site>/', $html );
		self::assertStringContainsString( '<code>WP_DEBUG</code> and <code>WP_DEBUG_LOG</code>', $html );
		self::assertStringContainsString( '<code>.php</code> guard', $html );
	}

	public function test_mutation_error_stays_visible_and_escaped(): void {
		$html = $this->render_view( array( 'state' => 'inactive' ), 'Could not start <capture>.' );
		self::assertStringContainsString( 'role="alert" tabindex="-1"', $html );
		self::assertStringContainsString( 'Could not start &lt;capture&gt;.', $html );
		self::assertStringNotContainsString( 'Could not start <capture>', $html );
		self::assertStringContainsString( 'name="ran_booster[operation]" value="start"', $html );
	}

	/** @return array<string, array{bool|string|int, string, string}> */
	public static function debug_log_path_cases(): array {
		return array(
			'false'                  => array( false, '/test/content/debug.log', 'true' ),
			'zero string'            => array( '0', '/test/content/debug.log', 'true' ),
			'true'                   => array( true, '/test/content/debug.log', 'true' ),
			'true string'            => array( 'TrUe', '/test/content/debug.log', 'true' ),
			'one string'             => array( '1', '/test/content/debug.log', 'true' ),
			'custom'                 => array( "/logs/<test>'s.log", "/logs/<test>'s.log", "'/logs/<test>\\'s.log'" ),
			'false string is a path' => array( 'false', 'false', "'false'" ),
			'stderr stream'          => array( 'php://stderr', 'php://stderr', "'php://stderr'" ),
			'stdout stream'          => array( 'php://stdout', 'php://stdout', "'php://stdout'" ),
		);
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	#[\PHPUnit\Framework\Attributes\DataProvider( 'debug_log_path_cases' )]
	public function test_wordpress_debug_path_matches_core_configuration( bool|string|int $setting, string $path, string $example ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress-owned constants in isolated processes.
		define( 'WP_CONTENT_DIR', '/test/content' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress-owned constants in isolated processes.
		define( 'WP_DEBUG_LOG', $setting );
		$html = $this->render_view( array( 'state' => 'inactive' ) );
		self::assertStringContainsString( '<code>' . htmlspecialchars( $path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</code>', $html );
		self::assertStringContainsString( 'Log location when enabled:', $html );
		self::assertStringContainsString( htmlspecialchars( "define( 'WP_DEBUG_LOG', " . $example . ' );', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ), $html );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_url_style_debug_destination_does_not_expose_embedded_secrets(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress-owned constant in an isolated process.
		define( 'WP_DEBUG_LOG', 'https://user:secret@example.test/debug.log' );
		$html = $this->render_view( array( 'state' => 'inactive' ) );
		self::assertStringNotContainsString( 'secret', $html );
		self::assertStringContainsString( 'Keep the existing WP_DEBUG_LOG destination.', $html );
	}

	/**
	 * @param array<string, mixed> $payload Debug capture view payload.
	 */
	private function render_view( array $payload, ?string $error = null ): string {
		$debug_capture        = $payload;
		$debug_capture_error  = $error;
		$troubleshooting_base = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/debug-capture.php';

		return (string) ob_get_clean();
	}
}
