<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ReleaseManagement\ManagedReleaseBrowserOperations;

/**
 * The updater's own status only holds an offer in the request that ran its check, so the managed
 * release browser reads the offer WordPress persisted. Each test runs in its own process because it
 * defines the global WordPress transient function.
 */
final class PersistedOfferVersionTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_plugin_offer_is_read_from_the_update_plugins_transient(): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		$GLOBALS['ran_booster_test_site_transients']['update_plugins'] = (object) array(
			'response' => array(
				'example/example.php' => (object) array( 'new_version' => '3.0.0' ),
				'other/other.php'     => (object) array( 'new_version' => '9.9.9' ),
			),
		);

		self::assertSame( '3.0.0', ManagedReleaseBrowserOperations::persisted_offer_version( 'plugin', 'example/example.php' ) );
		self::assertSame( '', ManagedReleaseBrowserOperations::persisted_offer_version( 'plugin', 'missing/missing.php' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_theme_offer_is_read_from_the_update_themes_transient_and_never_the_plugin_one(): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		$GLOBALS['ran_booster_test_site_transients'] = array(
			'update_themes'  => (object) array( 'response' => array( 'example-theme' => array( 'new_version' => '2.0.0' ) ) ),
			'update_plugins' => (object) array( 'response' => array( 'example-theme' => (object) array( 'new_version' => '7.0.0' ) ) ),
		);

		self::assertSame( '2.0.0', ManagedReleaseBrowserOperations::persisted_offer_version( 'theme', 'example-theme' ) );
		self::assertSame( '7.0.0', ManagedReleaseBrowserOperations::persisted_offer_version( 'plugin', 'example-theme' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_updaters_version_key_is_accepted_and_malformed_transients_read_as_no_offer(): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		$GLOBALS['ran_booster_test_site_transients']['update_plugins'] = (object) array( 'response' => array( 'example/example.php' => (object) array( 'version' => '3.1.0' ) ) );
		self::assertSame( '3.1.0', ManagedReleaseBrowserOperations::persisted_offer_version( 'plugin', 'example/example.php' ) );

		foreach ( array( false, 'not-an-object', (object) array( 'response' => 'nope' ), (object) array( 'response' => array( 'example/example.php' => (object) array( 'new_version' => 5 ) ) ) ) as $malformed ) {
			$GLOBALS['ran_booster_test_site_transients']['update_plugins'] = $malformed;
			self::assertSame( '', ManagedReleaseBrowserOperations::persisted_offer_version( 'plugin', 'example/example.php' ) );
		}
	}
}
