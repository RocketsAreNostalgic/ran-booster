<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Markup contract for the opt-in busy label and announcement on slow package form actions. */
final class PackageBusyLabelContractTest extends TestCase {

	private const STATUS = 'role="status" aria-live="polite" data-ran-booster-submit-busy-status';

	/**
	 * Each row: file, a marker inside the button, expected busy label.
	 *
	 * @return iterable<string, array{string, string, string}>
	 */
	public static function action_provider(): iterable {
		yield 'recheck eligibility' => array( 'RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php', "'Recheck eligibility'", 'Rechecking…' );
		yield 'check releases' => array( 'RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php', "'Check releases'", 'Checking…' );
		yield 'use releases' => array( 'RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php', 'data-ran-booster-use-releases ', 'Switching…' );
		yield 'install published release' => array( 'RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php', 'data-ran-booster-release-install ', 'Installing…' );
		yield 'save and check' => array( 'views/packages/branch-readiness.php', "'Save settings and check'", 'Saving and checking…' );
		yield 'save settings' => array( 'views/packages/edit.php', 'data-ran-booster-package-settings-save', 'Saving…' );
		yield 'install' => array( 'views/packages/create.php', "__( 'Install %s'", 'Installing…' );
		yield 'bulk apply' => array( 'views/packages/index.php', "'Apply'", 'Applying…' );
		yield 'stop managing recovery' => array( 'views/packages/recovery.php', "'Stop managing %s'", 'Stopping…' );
		yield 'stop managing danger zone' => array( 'views/packages/danger-zone.php', "__( 'Stop managing %s'", 'Stopping…' );
		yield 'stop managing and delete' => array( 'views/packages/danger-zone.php', "'Stop managing and delete", 'Deleting…' );
	}

	#[DataProvider( 'action_provider' )]
	public function test_the_button_carries_a_busy_label_and_message( string $file, string $marker, string $busy_label ): void {
		$source = $this->source( $file );
		$button = $this->button_containing( $source, $marker );

		self::assertStringContainsString( "__( '" . $busy_label . "', 'ran-booster' )", $button, 'The busy label is translatable.' );
		self::assertStringContainsString( 'data-ran-booster-busy-label="', $button );
		self::assertStringContainsString( 'data-ran-booster-busy-message="', $button );
		self::assertStringEndsWith( '…', $busy_label );
	}

	/** @return iterable<string, array{string, int, bool}> */
	public static function view_provider(): iterable {
		yield 'edit' => array( 'views/packages/edit.php', 1, false );
		yield 'create' => array( 'views/packages/create.php', 1, true );
		yield 'bulk' => array( 'views/packages/index.php', 1, false );
		yield 'recovery' => array( 'views/packages/recovery.php', 1, true );
		yield 'danger zone' => array( 'views/packages/danger-zone.php', 2, true );
	}

	#[DataProvider( 'view_provider' )]
	public function test_the_view_announces_through_one_status_element_per_form( string $file, int $expected_status, bool $opt_in ): void {
		$source = $this->source( $file );

		self::assertSame( $expected_status, substr_count( $source, self::STATUS ), 'Each busy form has exactly one status element.' );
		$opted_in = preg_match( '/data-ran-booster-submit-busy(?![-\w])/', $source );
		self::assertSame( $opt_in ? 1 : 0, $opted_in, 'Only plain POST forms opt in; HTMX forms use the same attributes through the mutation path.' );
	}

	public function test_release_view_has_a_status_in_each_busy_form(): void {
		$source = $this->source( 'RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php' );

		// Recheck, Check releases, branch track form (literal) and managed track (shared template).
		self::assertSame( 4, substr_count( $source, 'role="status" aria-live="polite" data-ran-booster-submit-busy-status' ) );
		self::assertStringContainsString( "'branch' === \$mode", $source );
	}

	public function test_create_form_gives_each_install_button_its_own_label_and_status_once(): void {
		$source = $this->source( 'views/packages/create.php' );

		self::assertSame( 1, preg_match_all( '/data-ran-booster-submit-busy(?![-\w])/', $source ) );
		self::assertStringContainsString( 'Installing and preparing another.', $source );
		self::assertStringNotContainsString( 'disabled data-ran-booster-busy', $source );
		self::assertSame( 1, substr_count( $source, self::STATUS ) );
		$release = $this->source( 'RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php' );
		self::assertStringContainsString( 'Installing the published release.', $release );
	}

	public function test_danger_zone_keeps_confirmation_and_names_intact(): void {
		$source = $this->source( 'views/packages/danger-zone.php' );

		self::assertSame( 2, substr_count( $source, 'data-ran-booster-package-removal-confirm' ) );
		self::assertSame( 2, substr_count( $source, 'name="ran_booster[confirm_package_removal]" value="1" required' ) );
		self::assertSame( 2, substr_count( $source, 'disabled data-ran-booster-package-removal-submit' ) );
		self::assertSame( 2, substr_count( $source, 'data-ran-booster-native-submit data-ran-booster-submit-busy>' ) );
	}

	private function button_containing( string $source, string $marker ): string {
		$position = strpos( $source, $marker );
		if ( false === $position ) {
			self::fail( 'Marker not found: ' . $marker );
		}
		$start = strrpos( substr( $source, 0, $position ), '<button' );
		$end   = strpos( $source, '</button>', $position );
		if ( false === $start || false === $end ) {
			self::fail( 'No enclosing button for marker: ' . $marker );
		}
		return substr( $source, $start, $end - $start );
	}

	private function source( string $relative ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Focused local source contract.
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
	}
}
