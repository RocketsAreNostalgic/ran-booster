<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

/** Refs #987. The package page's in-place release, with the owner-approved wording. */
final class NativeHoldViewTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_test_capabilities'] );
	}

	public function test_a_finished_request_needs_only_the_check(): void {
		$html = $this->render( $this->hold( 'plugin', true ) );
		self::assertStringContainsString( 'GitHub installs are on a site-wide hold', $html );
		self::assertStringContainsString( 'data-ran-booster-ui-notice-intent="warning"', $html );
		self::assertStringContainsString( 'is-heading-md ran-wp-ui-notice__title', $html );
		self::assertStringContainsString( 'ran-booster-native-hold__checks', $html );
		self::assertStringContainsString( 'ran-booster-native-hold__actions', $html );
		self::assertStringContainsString( 'ran-wp-ui-notice__icon', $html );
		self::assertStringContainsString( 'The last install of this plugin couldn&#039;t complete. <a href="https://example.test/wp-admin/plugins.php?s=Example">Check that it works</a>, then release the hold.', $html );
		self::assertStringContainsString( 'name="ran_booster[confirmed_checked]" value="1" required><span>I&#039;ve checked this plugin.</span>', $html );
		self::assertStringNotContainsString( 'confirmed_stopped', $html );
		self::assertStringContainsString( 'value="release-github-hold"', $html );
		self::assertStringContainsString( 'ran-booster-release-github-hold-' . str_repeat( 'c', 64 ), $html );
		self::assertStringContainsString( 'Release hold</button>', $html );
	}

	public function test_a_killed_request_shows_when_it_started_and_needs_both_confirmations(): void {
		$html = $this->render( $this->hold( 'theme', false ) );
		self::assertStringContainsString( 'The last install of this theme started 12 mins ago and hasn&#039;t completed. Booster can&#039;t tell whether it&#039;s still running. <a href="https://example.test/wp-admin/plugins.php?s=Example">Check that the theme works</a>, then release the hold.', $html );
		self::assertStringContainsString( 'If it may still be running, <a href="https://example.test/wp-admin/admin.php?page=ran-booster-themes">reload this page later</a>.', $html );
		self::assertStringContainsString( 'I&#039;m sure the install is no longer running.', $html );
		self::assertStringContainsString( 'I&#039;ve checked this theme.', $html );
	}

	public function test_a_killed_request_without_a_start_time_omits_the_timing_phrase(): void {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$hold               = $this->hold( $type, false );
			$hold['started_at'] = null;
			$html               = $this->render( $hold );
			self::assertStringContainsString(
				'The last install of this ' . $type . ' hasn&#039;t completed. Booster can&#039;t tell whether it&#039;s still running. <a href="https://example.test/wp-admin/plugins.php?s=Example">Check that the ' . $type . ' works</a>, then release the hold.',
				$html
			);
			self::assertStringNotContainsString( 'started', $html );
			self::assertStringNotContainsString( 'ago', $html );
			self::assertStringNotContainsString( '1 min', $html );
			self::assertStringContainsString( 'reload this page later', $html );
			self::assertStringContainsString( 'name="ran_booster[confirmed_stopped]"', $html );
			self::assertStringContainsString( 'name="ran_booster[confirmed_checked]"', $html );
		}
	}

	/** @return iterable<string, array{string, string}> */
	public static function results(): iterable {
		yield 'lock held' => array( 'busy', 'Another update is holding the updater lock, so Booster can&#039;t continue yet. Try again when it has finished.' );
		yield 'other failure' => array( 'failed', 'Booster couldn&#039;t release the hold. <a href="https://example.test/wp-admin/admin.php?page=ran-booster-themes">Reload this page</a> and try again.' );
	}

	#[DataProvider( 'results' )]
	public function test_a_refused_release_says_why_beside_the_button( string $code, string $sentence ): void {
		$html = $this->render( $this->hold( 'theme', true ), $code );
		self::assertStringContainsString( $sentence, $html );
		self::assertGreaterThan( strpos( $html, 'Release hold</button>' ), strpos( $html, 'role="status"' ) );
	}

	public function test_a_released_hold_says_so_and_offers_nothing_else(): void {
		$html = $this->render( null, 'released' );
		self::assertStringContainsString( 'Hold released. GitHub installs can continue.', $html );
		self::assertStringNotContainsString( '<form', $html );
		self::assertSame( '', $this->render( null ) );
	}

	public function test_a_user_without_the_capability_gets_no_control(): void {
		$GLOBALS['ran_booster_test_capabilities'] = array(
			'manage_options'   => true,
			'activate_plugins' => false,
		);
		self::assertStringNotContainsString( '<form', $this->render( $this->hold( 'plugin', true ) ) );
	}

	/** @return array<string, mixed> */
	private function hold( string $type, bool $finished ): array {
		return array(
			'digest'     => str_repeat( 'c', 64 ),
			'type'       => $type,
			'identifier' => 'plugin' === $type ? 'example/example.php' : 'example',
			'finished'   => $finished,
			'started_at' => time() - 720,
			'url'        => 'https://example.test/wp-admin/admin.php?page=ran-booster-' . $type . 's#ran-booster-native-hold',
			'check_url'  => 'https://example.test/wp-admin/plugins.php?s=Example',
		);
	}

	/** @param array<string, mixed>|null $hold */
	private function render( ?array $hold, string $code = '' ): string {
		$GLOBALS['ran_booster_test_capabilities'] ??= array(
			'manage_options'   => true,
			'activate_plugins' => true,
		);
		$package_native_hold                        = $hold;
		$package_native_hold_result                 = '' === $code ? null : array(
			'code'   => $code,
			'folder' => '',
			'type'   => 'plugin',
		);
		ob_start();
		try {
			require dirname( __DIR__, 2 ) . '/views/packages/native-hold.php';
			return trim( (string) ob_get_contents() );
		} finally {
			ob_end_clean();
		}
	}
}
