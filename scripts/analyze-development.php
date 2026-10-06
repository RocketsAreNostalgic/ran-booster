<?php

declare(strict_types=1);

use PHPStan\DependencyInjection\ContainerFactory;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$ran_booster_analysis_status = ( static function (): int {
	$root      = dirname( __DIR__ );
	$config    = $root . '/phpstan-development.neon';
	$container = ( new ContainerFactory( $root ) )->create( $root . '/.phpunit.cache/test-analysis', array( $config ), array() );
	$files     = $container->getService( 'fileFinderAnalyse' )->findFiles( array( $root . '/scripts', $root . '/tests' ) )->getFiles();
	sort( $files );
	$profiles = array();
	foreach ( $files as $file ) {
		$isolated               = preg_match( '~^' . preg_quote( $root, '~' ) . '/tests/(?:WordPress|fixtures|Integration)/~', $file );
		$profile                = $isolated ? 'phpstan-integration.neon' : 'phpstan-development.neon';
		$profiles[ $profile ][] = $file;
	}
	$arguments = array_slice( $GLOBALS['argv'] ?? array(), 1 );
	if ( array( '--list' ) === $arguments ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emit machine-readable CLI selection evidence without HTML escaping.
		echo json_encode( $profiles, JSON_THROW_ON_ERROR ) . "\n";
		return 0;
	}
	if ( array() !== $arguments || array() === $files ) {
		return 2;
	}
	$status = 0;
	foreach ( $profiles as $profile => $selected ) {
		foreach ( $selected as $file ) {
			$config = $root . '/' . $profile;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Invoke the locked analyzer separately for each isolated executable fixture world; never execute fixture source.
			$process = proc_open(
				array( PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $config, '--no-progress', '--memory-limit=1G', $file ),
				array( STDIN, STDOUT, STDERR ),
				$pipes,
				$root
			);
			if ( ! is_resource( $process ) ) {
				return 2;
			}
			if ( 0 !== proc_close( $process ) ) {
				$status = 1;
			}
		}
	}
	return $status;
} )();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The closure returns only an integer CLI status; exit emits no response body.
exit( $ran_booster_analysis_status );
