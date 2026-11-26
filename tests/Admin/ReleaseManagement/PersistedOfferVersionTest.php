<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ReleaseManagement\ManagedReleaseBrowserOperations;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use RAN\WordPress\ManagedReleaseTargetRegistrar;

require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';

/**
 * The updater's own status only holds an offer in the request that ran its check, so the managed
 * release browser reads the offer WordPress persisted. Each test runs in its own process because it
 * defines the global WordPress transient function.
 */
final class PersistedOfferVersionTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_plugin_and_theme_offers_use_the_exact_target_in_their_separate_response_channels(): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		$GLOBALS['ran_booster_test_site_transients'] = array(
			'update_plugins' => (object) array(
				'response' => array(
					'example/example.php' => (object) array(
						'plugin'      => 'example/example.php',
						'new_version' => '3.0.0',
						'package'     => 'plugin-offer',
					),
					'example-theme'       => (object) array(
						'plugin'      => 'example-theme',
						'new_version' => '9.9.9',
						'package'     => 'other-plugin-offer',
					),
				),
			),
			'update_themes'  => (object) array(
				'response' => array(
					'example-theme' => array(
						'theme'       => 'example-theme',
						'new_version' => '2.0.0',
						'package'     => 'theme-offer',
					),
				),
			),
		);

		self::assertSame( '3.0.0', ManagedReleaseBrowserOperations::persisted_offer_version( ReleaseManagementFixture::status( 'release_asset', 'plugin' ) ) );
		self::assertSame( '2.0.0', ManagedReleaseBrowserOperations::persisted_offer_version( ReleaseManagementFixture::status( 'release_asset', 'theme' ) ) );
		$GLOBALS['ran_booster_test_site_transients']['update_plugins']->response['example/example.php'] = array(
			'plugin'  => 'example/example.php',
			'version' => '3.1.0',
			'package' => 'plugin-offer',
		);
		self::assertSame( '3.1.0', ManagedReleaseBrowserOperations::persisted_offer_version( ReleaseManagementFixture::status( 'release_asset', 'plugin' ) ) );
		self::assertSame( '', ManagedReleaseBrowserOperations::persisted_offer_version( ReleaseManagementFixture::status( 'branch' ) ) );
		self::assertSame( '', ManagedReleaseBrowserOperations::persisted_offer_version( ReleaseManagementFixture::status( 'release_asset', deployment_policy: 'disabled' ) ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_cached_offer_uses_the_selected_updaters_version_ordering(): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		$identifier = 'example/example.php';
		$entry      = (object) array(
			'plugin'      => $identifier,
			'new_version' => '1.2.0-dev',
			'package'     => 'plugin-offer',
		);
		$GLOBALS['ran_booster_test_site_transients']['update_plugins'] = (object) array( 'response' => array( $identifier => $entry ) );

		self::assertSame( '1.2.0-dev', ManagedReleaseTargetRegistrar::cached_offer_version( 'plugin', $identifier, '1.2.0-alpha' ) );
		$entry->new_version = '1.2.0-alpha';
		self::assertSame( '', ManagedReleaseTargetRegistrar::cached_offer_version( 'plugin', $identifier, '1.2.0-dev' ) );
		$entry->new_version = '1.0.0';
		self::assertSame( '', ManagedReleaseTargetRegistrar::cached_offer_version( 'plugin', $identifier, '1.0' ) );
		$entry->new_version = '3.0.0+unsupported';
		self::assertSame( '', ManagedReleaseTargetRegistrar::cached_offer_version( 'plugin', $identifier, '2.0.0' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_missing_stale_malformed_or_misaddressed_offers_are_unavailable(): void {
		require_once __DIR__ . '/PersistedOfferVersionWordPressFunctions.php';
		$valid  = array(
			'plugin'      => 'example/example.php',
			'new_version' => '3.0.0',
			'package'     => 'plugin-offer',
		);
		$status = ReleaseManagementFixture::status( 'release_asset', update_available: true );
		foreach ( array(
			'no package'        => array_diff_key( $valid, array( 'package' => true ) ),
			'empty package'     => array_replace( $valid, array( 'package' => '' ) ),
			'wrong package'     => array_replace( $valid, array( 'plugin' => 'other/other.php' ) ),
			'installed'         => array_replace( $valid, array( 'new_version' => '1.0.0' ) ),
			'older'             => array_replace( $valid, array( 'new_version' => '0.9.0' ) ),
			'malformed'         => array_replace( $valid, array( 'new_version' => 5 ) ),
			'malformed version' => array_replace( $valid, array( 'new_version' => '3.0.0 <invalid>' ) ),
		) as $case => $entry ) {
			$GLOBALS['ran_booster_test_site_transients']['update_plugins'] = (object) array( 'response' => array( 'example/example.php' => (object) $entry ) );
			self::assertSame( '', ManagedReleaseBrowserOperations::persisted_offer_version( $status ), $case );
		}
		foreach ( array( false, 'malformed', (object) array( 'response' => 'malformed' ), (object) array( 'no_update' => array( 'example/example.php' => (object) $valid ) ) ) as $transient ) {
			$GLOBALS['ran_booster_test_site_transients']['update_plugins'] = $transient;
			self::assertSame( '', ManagedReleaseBrowserOperations::persisted_offer_version( $status ) );
		}
	}
}
