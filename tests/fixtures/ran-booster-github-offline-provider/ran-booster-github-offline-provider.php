<?php
/**
 * Plugin Name: RAN Booster GitHub Offline Provider Fixture
 * Description: Disposable installed proof only. Supersedes bundled GitHub with an offline provider.
 * Version: 0.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Requires Plugins: ran-booster
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Proof scripts may already have loaded the source copy of the instrumentation class.
if ( ! class_exists( \RAN_Booster_GitHubOfflineProviderFixture\Fixture::class, false ) ) {
	require_once __DIR__ . '/src/Fixture.php';
}
\RAN_Booster_GitHubOfflineProviderFixture\Fixture::register( __DIR__ . '/src/Provider.php' );
