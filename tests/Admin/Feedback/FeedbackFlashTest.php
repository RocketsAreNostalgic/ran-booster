<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\Feedback;

use PHPUnit\Framework\TestCase;
use RAN\Admin\Feedback\FeedbackFlash;

require_once dirname( __DIR__, 2 ) . '/Support/FeedbackWordPressFunctions.php';

final class FeedbackFlashTest extends TestCase {

	private const KEY = FeedbackFlash::USER_META_KEY;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_feedback_test'] = array(
			'user' => 1,
			'blog' => 1,
			'caps' => array( 'manage_options' ),
			'meta' => array(),
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_feedback_test'] );
	}

	public function test_enqueue_stores_closed_codes_only(): void {
		FeedbackFlash::enqueue( 'release_check_current' );

		$stored = $GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ];
		self::assertSame( array( 'site', 'at', 'codes' ), array_keys( $stored ) );
		self::assertSame( 1, $stored['site'] );
		self::assertIsInt( $stored['at'] );
		self::assertSame( array( 'release_check_current' ), $stored['codes'] );
	}

	public function test_nothing_but_known_codes_can_enter_storage(): void {
		FeedbackFlash::enqueue( '<script>alert(1)</script>', 'https://example.test/?token=secret', 'Release check complete.', '' );
		self::assertSame( array(), $this->meta() );

		FeedbackFlash::enqueue( 'unknown', 'release_check_update', 'release_check_update', 'release_check_current', 'a', 'b' );
		self::assertSame(
			array( 'release_check_update', 'release_check_current' ),
			$GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ]['codes']
		);
	}

	public function test_a_later_enqueue_replaces_an_unread_flash(): void {
		FeedbackFlash::enqueue( 'release_check_current' );
		FeedbackFlash::enqueue( 'release_check_update' );

		self::assertSame( array( 'release_check_update' ), $GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ]['codes'] );
	}

	public function test_render_prints_the_exact_static_fallback_and_deletes_the_flash_once(): void {
		FeedbackFlash::enqueue( 'release_check_current', 'release_check_update' );

		$first  = $this->render();
		$second = $this->render();

		self::assertSame(
			'<div class="ran-booster-feedback" data-ran-booster-feedback>'
			. '<div class="ran-booster-feedback__item" data-feedback-key="release_check_current" data-intent="success" role="status">Release check complete. No newer eligible release was found.</div>'
			. '<div class="ran-booster-feedback__item" data-feedback-key="release_check_update" data-intent="success" role="status">Release check complete.</div>'
			. '</div>',
			$first
		);
		self::assertSame( '', $second );
		self::assertSame( array(), $GLOBALS['ran_booster_feedback_test']['meta'][1] );
	}

	public function test_copy_is_one_whole_string_per_code(): void {
		FeedbackFlash::enqueue( 'release_check_current' );

		self::assertStringContainsString( '>Release check complete. No newer eligible release was found.</div>', $this->render() );
	}

	public function test_enqueue_records_the_current_site_and_keeps_one_of_each_code(): void {
		$GLOBALS['ran_booster_feedback_test']['blog'] = 7;
		FeedbackFlash::enqueue( 'release_check_update', 'release_check_update' );

		$stored = $this->meta()[1][ self::KEY ];
		self::assertSame( 7, $stored['site'] );
		self::assertSame( array( 'release_check_update' ), $stored['codes'] );
		self::assertNotSame( '', $this->render() );
	}

	public function test_translated_copy_is_escaped(): void {
		$GLOBALS['ran_booster_feedback_test']['translate'] = '<b onclick="x">&';
		FeedbackFlash::enqueue( 'release_check_update' );

		$html = $this->render();
		self::assertStringContainsString( '&lt;b onclick=&quot;x&quot;&gt;&amp;', $html );
		self::assertStringNotContainsString( '<b ', $html );
	}

	public function test_a_flash_at_exactly_the_ttl_is_still_shown(): void {
		FeedbackFlash::enqueue( 'release_check_current' );
		$GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ]['at'] = time() - 60;

		self::assertNotSame( '', $this->render() );
	}

	public function test_a_non_array_codes_value_is_dropped_without_a_warning(): void {
		$GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ] = array(
			'site'  => 1,
			'at'    => time(),
			'codes' => null,
		);

		$warnings = 0;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Count PHP warnings raised by malformed stored data.
		set_error_handler(
			static function () use ( &$warnings ): bool {
				++$warnings;

				return true;
			}
		);
		try {
			$html = $this->render();
		} finally {
			restore_error_handler();
		}

		self::assertSame( 0, $warnings );
		self::assertSame( '', $html );
		self::assertSame( array(), $this->meta()[1] );
	}

	public function test_two_tabs_consume_one_flash_once(): void {
		FeedbackFlash::enqueue( 'release_check_current' );
		// Both tabs read the row before either deletes it.
		$GLOBALS['ran_booster_feedback_test']['frozen'][1][ self::KEY ] = $GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ];

		$outputs = array( $this->render(), $this->render() );

		self::assertSame( 1, count( array_filter( $outputs, static fn ( string $html ): bool => '' !== $html ) ) );
	}

	public function test_an_expired_flash_prints_nothing_and_is_deleted(): void {
		FeedbackFlash::enqueue( 'release_check_current' );
		$GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ]['at'] = time() - 61;

		self::assertSame( '', $this->render() );
		self::assertSame( array(), $GLOBALS['ran_booster_feedback_test']['meta'][1] );

		FeedbackFlash::enqueue( 'release_check_current' );
		$GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ]['at'] = time() - 59;
		self::assertNotSame( '', $this->render() );
	}

	public function test_unknown_or_tampered_stored_values_are_dropped(): void {
		$at = time();
		foreach ( array(
			array(
				'site'  => 1,
				'at'    => $at,
				'codes' => array( 'unknown', 7, array( 'x' ), '<b>x</b>' ),
			),
			array(
				'site'  => 1,
				'at'    => (string) $at,
				'codes' => array( 'release_check_current' ),
			),
			array(
				'site'  => 1,
				'at'    => $at,
				'codes' => 'release_check_current',
			),
			'release_check_current',
		) as $tampered ) {
			$GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ] = $tampered;
			self::assertSame( '', $this->render() );
		}
	}

	public function test_only_closed_copy_is_printed_even_when_storage_carries_extra_keys(): void {
		$GLOBALS['ran_booster_feedback_test']['meta'][1][ self::KEY ] = array(
			'site'    => 1,
			'at'      => time(),
			'codes'   => array( 'release_check_update', 'unknown' ),
			'message' => '<img src=x onerror=alert(1)>',
			'url'     => 'https://example.test/?secret=1',
		);

		$html = $this->render();

		self::assertStringNotContainsString( 'img', $html );
		self::assertStringNotContainsString( 'secret', $html );
		self::assertStringNotContainsString( 'unknown', $html );
		self::assertSame( 1, substr_count( $html, 'ran-booster-feedback__item"' ) );
	}

	public function test_a_flash_is_private_to_the_user_who_produced_it(): void {
		FeedbackFlash::enqueue( 'release_check_current' );

		$GLOBALS['ran_booster_feedback_test']['user'] = 2;
		self::assertSame( '', $this->render() );
		self::assertArrayHasKey( self::KEY, $GLOBALS['ran_booster_feedback_test']['meta'][1] );

		$GLOBALS['ran_booster_feedback_test']['user'] = 1;
		self::assertNotSame( '', $this->render() );
	}

	public function test_a_user_without_the_admin_capability_gets_nothing_and_keeps_the_flash(): void {
		FeedbackFlash::enqueue( 'release_check_current' );

		$GLOBALS['ran_booster_feedback_test']['caps'] = array();
		self::assertSame( '', $this->render() );
		self::assertArrayHasKey( self::KEY, $GLOBALS['ran_booster_feedback_test']['meta'][1] );
	}

	public function test_a_logged_out_request_neither_stores_nor_prints(): void {
		$GLOBALS['ran_booster_feedback_test']['user'] = 0;

		FeedbackFlash::enqueue( 'release_check_current' );

		self::assertSame( array(), $GLOBALS['ran_booster_feedback_test']['meta'] );
		self::assertSame( '', $this->render() );
	}

	public function test_a_flash_belongs_to_the_site_that_produced_it(): void {
		FeedbackFlash::enqueue( 'release_check_current' );

		$GLOBALS['ran_booster_feedback_test']['blog'] = 2;
		self::assertSame( '', $this->render() );
		self::assertArrayHasKey( self::KEY, $GLOBALS['ran_booster_feedback_test']['meta'][1] );

		$GLOBALS['ran_booster_feedback_test']['blog'] = 1;
		self::assertNotSame( '', $this->render() );
	}

	/** @return array<int, array<string, mixed>> */
	private function meta(): array {
		return $GLOBALS['ran_booster_feedback_test']['meta'];
	}

	private function render(): string {
		ob_start();
		FeedbackFlash::render();

		return (string) ob_get_clean();
	}

	public function test_the_flash_is_rendered_only_by_the_booster_shell_view_after_the_shell_notices(): void {
		$root = dirname( __DIR__, 3 );
		$base = (string) file_get_contents( $root . '/views/base.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.

		self::assertSame( 1, substr_count( $base, 'FeedbackFlash::render()' ) );
		self::assertGreaterThan( strpos( $base, 'BoosterNoticeScope::render_shell_notices()' ), strpos( $base, 'FeedbackFlash::render()' ) );

		$callers = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/RAN', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension()
				&& str_contains( (string) file_get_contents( $file->getPathname() ), 'FeedbackFlash::render()' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
				$callers[] = $file->getFilename();
			}
		}
		self::assertSame( array(), $callers );
	}
}
