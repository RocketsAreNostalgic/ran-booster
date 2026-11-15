<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class PackageIndexNoticePlacementTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_test_translations']   = array();
		$GLOBALS['ran_booster_package_view_translations'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_admin_test_translations'], $GLOBALS['ran_booster_package_view_translations'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_query_double_preserves_nested_wordpress_query_semantics(): void {
		$url = add_query_arg(
			array(
				'return_s' => 'Rock%26x%3D1',
				'more'     => array( 'deep' => 'a%2Bb' ),
			),
			'https://example.test/wp-admin/admin.php?nested%5Ba%5D=a%26b&nested%5Bdeep%5D%5Bv%5D=one+two~&source=existing#view'
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?nested%5Ba%5D=a%26b&nested%5Bdeep%5D%5Bv%5D=one+two%7E&source=existing&return_s=Rock%26x%3D1&more%5Bdeep%5D=a%2Bb#view',
			$url
		);
	}

	public function test_structured_contention_info_notice_keeps_its_protected_activity_link(): void {
		$messages = array(
			array(
				'type'    => 'info',
				'code'    => 'ran_booster_deployment_active',
				'message' => 'A deployment is active. <a href="https://example.test/activity?attempt=42">Review activity</a>.',
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/notices.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice notice-info inline', $html );
		self::assertStringContainsString( 'attempt=42', $html );
		self::assertStringContainsString( 'Review activity', $html );
	}

	public function test_saved_package_marker_preserves_the_visible_notice_and_escapes_identity(): void {
		$messages = array(
			array(
				'type'          => 'success',
				'message'       => 'Plugin was successfully saved.',
				'saved_package' => 'example/"plugin.php',
				'saved_type'    => 'plugin',
			),
			array(
				'type'    => 'success',
				'message' => 'Plugin was successfully updated.',
			),
			array(
				'type'          => 'error',
				'message'       => 'Save failed.',
				'saved_package' => 'example/plugin.php',
				'saved_type'    => 'plugin',
			),
		);
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/notices.php';
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-package-saved' ) );
		self::assertSame( 2, substr_count( $html, 'data-ran-booster-package-success' ) );
		self::assertStringContainsString( 'data-ran-booster-saved-package="example/&quot;plugin.php" data-ran-booster-saved-type="plugin"', $html );
		self::assertStringContainsString( '<p>Plugin was successfully saved.</p>', $html );
		self::assertStringContainsString( '<p>Plugin was successfully updated.</p>', $html );
		self::assertStringContainsString( '<p>Save failed.</p>', $html );
	}

	/** @param list<array<string, mixed>> $queued */
	private function notices( array $queued ): string {
		$messages = $queued;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/notices.php';
		return (string) ob_get_clean();
	}

	public function test_a_confirmed_package_success_goes_to_the_host_as_its_only_static_result(): void {
		$html = $this->notices(
			array(
				array(
					'type'     => 'success',
					'message'  => 'Plugin was successfully installed.',
					'feedback' => 'package_install',
				),
			)
		);

		self::assertStringContainsString( '<div class="ran-booster-feedback" data-ran-booster-feedback>', $html );
		self::assertStringContainsString( 'data-feedback-key="package_install" data-intent="success" role="status">Plugin was successfully installed.</div>', $html );
		self::assertStringNotContainsString( 'class="notice', $html );
		self::assertSame( 1, substr_count( $html, 'Plugin was successfully installed.' ) );
	}

	public function test_a_warning_suppresses_the_success_snackbar_and_points_to_its_inline_record(): void {
		$html = $this->notices(
			array(
				array(
					'type'    => 'success',
					'message' => 'Plugin was successfully saved.',
				),
				array(
					'type'     => 'warning',
					'message'  => 'The provider is rate-limited or unavailable.',
					'feedback' => 'package_edit_unverified',
				),
			)
		);

		self::assertStringContainsString( '<p>Plugin was successfully saved.</p>', $html );
		self::assertStringNotContainsString( 'data-intent="success"', $html );
		self::assertStringContainsString( 'id="ran-booster-feedback-package-edit-unverified" class="notice notice-warning inline', $html );
		self::assertStringContainsString( 'data-feedback-key="package_edit_unverified" data-intent="warning" data-feedback-target="ran-booster-feedback-package-edit-unverified"', $html );

		$qualified = $this->notices(
			array(
				array(
					'type'     => 'success',
					'message'  => 'Changed 2 plugins to Enabled in WordPress.',
					'feedback' => 'bulk_plugin_state',
				),
				array(
					'type'    => 'error',
					'message' => 'Another failure.',
				),
			)
		);
		self::assertStringContainsString( 'notice notice-success inline', $qualified );
		self::assertStringNotContainsString( 'data-ran-booster-feedback', $qualified );
	}

	public function test_a_failure_stays_inline_and_never_becomes_a_success_item(): void {
		$html = $this->notices(
			array(
				array(
					'type'     => 'error',
					'message'  => 'A selected <b>package</b> changed.',
					'feedback' => 'bulk_error',
				),
			)
		);

		self::assertStringContainsString( 'id="ran-booster-feedback-bulk-error" class="notice notice-error inline', $html );
		self::assertStringContainsString( 'data-intent="error" data-feedback-target="ran-booster-feedback-bulk-error" role="alert">A selected package changed.</div>', $html );
		self::assertStringNotContainsString( 'data-intent="success"', $html );
	}

	public function test_a_message_without_a_closed_feedback_code_keeps_its_notice_and_adds_no_item(): void {
		$html = $this->notices(
			array(
				array(
					'type'     => 'success',
					'message'  => 'Plain result.',
					'feedback' => 'Not a closed code!',
				),
				array(
					'type'    => 'success',
					'message' => 'Another plain result.',
				),
			)
		);

		self::assertStringContainsString( '<p>Plain result.</p>', $html );
		self::assertStringContainsString( '<p>Another plain result.</p>', $html );
		self::assertStringNotContainsString( 'data-ran-booster-feedback', $html );
	}

	public function test_background_conditions_and_local_results_never_call_the_feedback_host(): void {
		$root = dirname( __DIR__, 2 );
		// R10: the stay-notice registrations never reach the host.
		foreach ( array( 'RAN/Booster.php', 'RAN/Admin/CoreSelfUpdateDevelopmentNotice.php', 'RAN/WordPress/ExtensionUpdates.php' ) as $file ) {
			self::assertStringNotContainsString( 'FeedbackFlash', (string) file_get_contents( $root . '/' . $file ), $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
		}
		// R9: Portability results and the package update progress region stay local.
		foreach ( array( 'assets/ran-booster-portability.js', 'assets/ran-booster-packages.js' ) as $file ) {
			self::assertStringNotContainsString( 'ranBoosterFeedback', (string) file_get_contents( $root . '/' . $file ), $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
		}
	}

	public function test_package_index_can_reorder_the_complete_managed_package_heading_without_changing_package_identity(): void {
		$GLOBALS['ran_booster_admin_test_translations']['ran-booster']['Managed %s'] = '%s administrés';
		$package_view              = PackagePagePresenter::plugin();
		$messages                  = array();
		$name                      = 'RAN Booster';
		$view                      = 'packages/index';
		$development_safety_notice = false;
		$packages                  = array();
		$package_providers         = array();
		$package_activity          = array(
			'items'       => array(),
			'unavailable' => false,
		);
		$tabs                      = array();

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Plugins administrés</h2>', $html );
		self::assertStringNotContainsString( '>Managed Plugins</h2>', $html );
		self::assertSame( 'plugin', $package_view->get_type() );
		self::assertSame( 'ran-booster-plugins', $package_view->get_page_slug() );
		self::assertStringContainsString( 'ran-booster-admin--packages', $html );
		self::assertStringContainsString( 'page=ran-booster-plugins-create', $html );
	}

	/** @return array<string, array{PackagePagePresenter, string}> */
	public static function package_types(): array {
		return array(
			'plugins' => array( PackagePagePresenter::plugin(), 'Managed Plugins' ),
			'themes'  => array( PackagePagePresenter::theme(), 'Managed Themes' ),
		);
	}

	#[DataProvider( 'package_types' )]
	public function test_package_index_places_notices_after_its_heading_and_description( PackagePagePresenter $package_view, string $heading ): void {
		$messages                  = array(
			array(
				'type'            => 'success',
				'message'         => 'Scoped package result.',
				'code'            => 'bulk_update_queue',
				'queued_updates'  => 2,
				'skipped_updates' => 1,
			),
		);
		$name                      = 'RAN Booster';
		$view                      = 'packages/index';
		$development_safety_notice = false;
		$packages                  = array();
		$package_providers         = array();
		$package_activity          = array(
			'items'       => array(),
			'unavailable' => false,
		);
		$tabs                      = array(
			array(
				'key'    => 'overview',
				'label'  => 'Overview',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster',
				'active' => false,
			),
			array(
				'key'    => 'portability',
				'label'  => 'Transporter',
				'url'    => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability',
				'active' => false,
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$html = (string) ob_get_clean();

		$masthead_position    = strpos( $html, 'Rockets Are Nostalgic' );
		$heading_position     = strpos( $html, $heading );
		$description_position = strpos( $html, 'Review package health, deploy saved branches and hand published releases to WordPress.' );
		$result_position      = strpos( $html, 'Scoped package result.' );
		$empty_state_position = strpos( $html, 'ran-booster-package-empty-state' );
		// The bulk update summary changes its intent in place: one icon template.
		self::assertSame( 1, substr_count( $html, 'id="ran-booster-notice-icons"' ) );

		foreach ( array( $masthead_position, $heading_position, $description_position, $result_position, $empty_state_position ) as $position ) {
			self::assertIsInt( $position );
		}
		self::assertTrue( $masthead_position < $heading_position );
		self::assertTrue( $heading_position < $description_position );
		self::assertTrue( $description_position < $result_position );
		self::assertSame( 1, substr_count( $html, 'Scoped package result.' ) );
		self::assertSame( 1, substr_count( $html, '<hr class="wp-header-end">' ) );
		$marker_position = strpos( $html, '<hr class="wp-header-end">' );
		self::assertIsInt( $marker_position );
		self::assertTrue( $marker_position < $masthead_position );
		self::assertSame( 1, substr_count( $html, 'class="ran-booster-package-intro"' ) );
		self::assertSame( 1, substr_count( $html, 'is-dismissible' ) );
		self::assertSame( 0, substr_count( $html, 'data-ran-booster-development-safety' ) );
		self::assertSame( 1, substr_count( $html, 'data-ran-booster-update-summary data-queued' ) );
		self::assertStringContainsString( 'data-queued="2" data-skipped="1"', $html );
		self::assertStringContainsString( 'data-ran-booster-update-summary-message', $html );
		self::assertStringNotContainsString( 'data-ran-booster-package-success', $html );
		self::assertStringNotContainsString( 'class="nav-tab-wrapper"', $html );
		self::assertMatchesRegularExpression( '/class="[^"]*\bran-admin-shell__navigation\b[^"]*"/', $html );
		self::assertStringContainsString( 'class="ran-admin-shell__logo"', $html );
		self::assertMatchesRegularExpression( '/>Overview(?:<span[^>]*><\/span>)?<\/a>/', $html );
		self::assertMatchesRegularExpression( '/>Plugins(?:<span[^>]*><\/span>)?<\/a>/', $html );
		self::assertMatchesRegularExpression( '/>Themes(?:<span[^>]*><\/span>)?<\/a>/', $html );
		self::assertStringNotContainsString( '>Transporter</a>', $html );
		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$document = new \DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath    = new \DOMXPath( $document );
		$selected = $xpath->query( '//nav//a[@aria-current="page"]' );
		self::assertNotFalse( $selected );
		self::assertCount( 1, $selected );
		$link = $selected->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $link );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=' . $package_view->get_page_slug(), $link->getAttribute( 'href' ) );
		self::assertSame( 'plugin' === $package_view->get_type() ? 'Plugins' : 'Themes', $xpath->evaluate( 'string(.)', $link ) );
	}

	#[DataProvider( 'package_types' )]
	public function test_package_recovery_places_each_refusal_notice_once_below_its_heading( PackagePagePresenter $package_view ): void {
		$messages                  = array(
			array(
				'type'    => 'error',
				'message' => 'Booster refused this package operation.',
			),
		);
		$name                      = 'RAN Booster';
		$view                      = 'packages/recovery';
		$development_safety_notice = false;
		$packages                  = array();
		$package_providers         = array();
		$package_activity          = array(
			'items'       => array(),
			'unavailable' => false,
		);
		$tabs                      = array();
		$recovery_identifier       = 'plugin' === $package_view->get_type() ? 'example-plugin/example.php' : 'example-theme';
		$recovery_name             = 'Refused package';
		$recovery_state            = 'blocked';
		$recovery_reason           = 'The supplied package management state cannot be used.';
		$recovery_blocker          = 'The requested operation was refused.';
		$recovery_confirmation     = null;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/base.php';
		$html = (string) ob_get_clean();

		$heading_position = strpos( $html, 'Booster can’t open settings for Refused package' );
		$refusal_position = strpos( $html, 'Booster refused this package operation.' );
		self::assertStringNotContainsString( 'id="ran-booster-notice-icons"', $html );

		foreach ( array( $heading_position, $refusal_position ) as $position ) {
			self::assertIsInt( $position );
		}
		self::assertTrue( $heading_position < $refusal_position );
		self::assertSame( 1, substr_count( $html, 'Booster refused this package operation.' ) );
		self::assertSame( 0, substr_count( $html, 'data-ran-booster-development-safety' ) );
	}
}
