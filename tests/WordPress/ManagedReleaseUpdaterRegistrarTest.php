<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\PackageArtifactLimit;
use RAN\WordPress\ManagedReleaseUpdaterRegistrar;
use RAN\Tests\Support\RecordingReleaseUpdaterRuntime;

/** Proves the current release-updater registrar argument contract. */
final class ManagedReleaseUpdaterRegistrarTest extends TestCase {
	public function test_default_native_target_limit_uses_eight_argument_updater_contract(): void {
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

	public function test_non_default_native_target_limit_uses_eight_argument_updater_contract(): void {
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

	public function test_descriptor_admission_is_unavailable_on_the_pinned_protocol_six_runtime(): void {
		$registrar = new ManagedReleaseUpdaterRegistrar( new RecordingReleaseUpdaterRuntime() );

		self::assertNull( $registrar->with_descriptor_admission( static fn (): bool => true ) );
	}

	public function test_descriptor_admission_wraps_the_protocol_seven_clone(): void {
		$runtime = new class() {
			public mixed $admission = null;

			public function with_descriptor_admission( callable $admission ): object {
				$this->admission = $admission;
				return new class() {};
			}
		};

		$admitted = ( new ManagedReleaseUpdaterRegistrar( $runtime ) )->with_descriptor_admission( static fn (): bool => true );

		self::assertInstanceOf( ManagedReleaseUpdaterRegistrar::class, $admitted );
		self::assertIsCallable( $runtime->admission );
	}
}
