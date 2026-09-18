<?php
/**
 * Plugin Name: RAN Booster GitHub Provider Extension Fixture
 * Description: Test-only external wrapper for the released GitHub provider package.
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

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/ReleaseUpdaterRegistrar.php';
require __DIR__ . '/src/Plugin.php';

\RANBoosterGitHubProviderExtensionFixture\Plugin::boot();
