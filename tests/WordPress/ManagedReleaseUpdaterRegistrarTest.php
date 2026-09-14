<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\PackageArtifactLimit;
use RAN\WordPress\ManagedReleaseUpdaterRegistrar;
use Tests\Support\RecordingReleaseUpdaterRuntime;

/** Proves updater-version compatibility remains a Booster host concern. */
final class ManagedReleaseUpdaterRegistrarTest extends TestCase {
	public function testDefaultNativeTargetLimitUsesSevenArgumentUpdaterContract(): void {
		$runtime   = new RecordingReleaseUpdaterRuntime();
		$registrar = new ManagedReleaseUpdaterRegistrar( $runtime );

		$registrar->plugin(
			'github',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			'stable',
			'manual',
			null,
			PackageArtifactLimit::DEFAULT_MAXIMUM_ARTIFACT_BYTES
		);

		self::assertCount( 7, $runtime->arguments );
	}

	public function testNonDefaultNativeTargetLimitUsesEightArgumentUpdaterContract(): void {
		$runtime   = new RecordingReleaseUpdaterRuntime();
		$registrar = new ManagedReleaseUpdaterRegistrar( $runtime );

		$registrar->plugin(
			'github',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			'stable',
			'manual',
			null,
			1048576
		);

		self::assertCount( 8, $runtime->arguments );
		self::assertSame( 1048576, $runtime->arguments[7] );
	}
}
