<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\PackageArtifactLimit;
use RAN\WordPress\ManagedReleaseUpdaterRegistrar;
use Tests\Support\RecordingReleaseUpdaterRuntime;

/** Proves the current release-updater registrar argument contract. */
final class ManagedReleaseUpdaterRegistrarTest extends TestCase {
	public function testDefaultNativeTargetLimitUsesEightArgumentUpdaterContract(): void {
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

		self::assertCount( 8, $runtime->arguments );
		self::assertSame( PackageArtifactLimit::DEFAULT_MAXIMUM_ARTIFACT_BYTES, $runtime->arguments[7] );
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
