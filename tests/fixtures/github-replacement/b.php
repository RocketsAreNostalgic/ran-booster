<?php
/**
 * Plugin Name: Booster GitHub replacement fixture B
 * Description: Disposable installed proof only.
 * Version: 0.0.0
 * RAN Booster GitHub Replacement: 1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( \RAN_Booster_GitHubReplacementFixture\Fixture::class, false ) ) {
	require_once __DIR__ . '/fixture.php';
}
\RAN_Booster_GitHubReplacementFixture\Fixture::register( 'b' );
