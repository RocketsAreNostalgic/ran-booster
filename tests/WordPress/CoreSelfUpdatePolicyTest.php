<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\WordPress\CoreSelfUpdatePolicy;

#[CoversClass( CoreSelfUpdatePolicy::class )]
final class CoreSelfUpdatePolicyTest extends TestCase {

	// Test fixtures intentionally use direct temporary-file operations and PHP
	// JSON encoding so this pure policy remains independent from WordPress APIs.
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode

	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-self-update-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
	}

	protected function tearDown(): void {
		foreach ( array( '.git', 'composer.json', 'ran-booster-release.json', 'ran-booster.php' ) as $entry ) {
			$path = $this->directory . '/' . $entry;
			if ( is_file( $path ) || is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture cleanup.
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
				rmdir( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Disposable focused fixture cleanup.
		rmdir( $this->directory );
	}

	public function testAutoModeFailsClosedWithoutAnOfficialReleaseMarker(): void {
		$policy = CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' );

		self::assertFalse( $policy->allowsNativeDiscovery() );
		self::assertSame( 'auto', $policy->diagnostics()['requested_mode'] );
		self::assertSame( 'release_marker_missing_or_invalid', $policy->diagnostics()['reason'] );
	}

	public function testAutoModeAllowsAValidOfficialReleaseMarker(): void {
		$this->writeMarker( '1.2.3', str_repeat( 'a', 40 ) );

		$policy = CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' );

		self::assertTrue( $policy->allowsNativeDiscovery() );
		self::assertSame(
			array(
				'requested_mode' => 'auto',
				'effective_mode' => 'enabled',
				'reason'         => 'verified_release',
				'marker_version' => '1.2.3',
				'marker_commit'  => str_repeat( 'a', 40 ),
			),
			$policy->diagnostics()
		);
	}

	public function testSourceCheckoutWinsOverAnOtherwiseValidMarker(): void {
		$this->writeMarker( '1.2.3', str_repeat( 'b', 40 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable focused fixture setup.
		self::assertTrue( mkdir( $this->directory . '/.git', 0700 ) );

		$policy = CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' );

		self::assertFalse( $policy->allowsNativeDiscovery() );
		self::assertSame( 'source_checkout', $policy->diagnostics()['reason'] );
	}

	public function testComposerSourceMetadataDisablesAutoMode(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
		self::assertIsInt( file_put_contents( $this->directory . '/composer.json', "{}\n" ) );

		$policy = CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' );

		self::assertFalse( $policy->allowsNativeDiscovery() );
		self::assertSame( 'source_checkout', $policy->diagnostics()['reason'] );
	}

	public function testRejectsMismatchedMalformedAndExpandedMarkers(): void {
		foreach (
			array(
				array(
					'schema'         => 'ran-booster-core-release',
					'schema_version' => 1,
					'version'        => '9.9.9',
					'commit'         => str_repeat( 'a', 40 ),
				),
				array(
					'schema'         => 'ran-booster-core-release',
					'schema_version' => 1,
					'version'        => '1.2.3',
					'commit'         => 'not-a-commit',
				),
				array(
					'schema'         => 'ran-booster-core-release',
					'schema_version' => 1,
					'version'        => '1.2.3',
					'commit'         => str_repeat( 'a', 40 ),
					'extra'          => true,
				),
			) as $marker
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
			self::assertIsInt(
				file_put_contents(
					$this->directory . '/ran-booster-release.json',
					(string) json_encode( $marker )
				)
			);

			$policy = CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' );
			self::assertFalse( $policy->allowsNativeDiscovery() );
		}
	}

	public function testRejectsAnOversizedOrSymlinkedMarker(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
		self::assertIsInt(
			file_put_contents(
				$this->directory . '/ran-booster-release.json',
				str_repeat( 'x', 4097 )
			)
		);
		self::assertFalse(
			CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' )->allowsNativeDiscovery()
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture setup.
		unlink( $this->directory . '/ran-booster-release.json' );
		$target = $this->directory . '/marker-target.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
		self::assertIsInt( file_put_contents( $target, "{}\n" ) );
		self::assertTrue( symlink( $target, $this->directory . '/ran-booster-release.json' ) );
		self::assertFalse(
			CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' )->allowsNativeDiscovery()
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Disposable focused fixture cleanup.
		unlink( $target );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testEnabledOverrideAllowsDisposableUpdateTestingWithoutAMarker(): void {
		define( CoreSelfUpdatePolicy::CONFIGURATION, 'enabled' );

		$policy = CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' );

		self::assertTrue( $policy->allowsNativeDiscovery() );
		self::assertSame( 'configuration_enabled', $policy->diagnostics()['reason'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testDisabledOverrideWinsOverAValidMarker(): void {
		define( CoreSelfUpdatePolicy::CONFIGURATION, 'disabled' );
		$this->writeMarker( '1.2.3', str_repeat( 'c', 40 ) );

		$policy = CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' );

		self::assertFalse( $policy->allowsNativeDiscovery() );
		self::assertSame( 'configuration_disabled', $policy->diagnostics()['reason'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testInvalidOverrideFailsClosed(): void {
		define( CoreSelfUpdatePolicy::CONFIGURATION, 'development' );

		$policy = CoreSelfUpdatePolicy::detect( $this->pluginFile(), '1.2.3' );

		self::assertFalse( $policy->allowsNativeDiscovery() );
		self::assertSame( 'invalid', $policy->diagnostics()['requested_mode'] );
		self::assertSame( 'configuration_invalid', $policy->diagnostics()['reason'] );
	}

	private function pluginFile(): string {
		return $this->directory . '/ran-booster.php';
	}

	private function writeMarker( string $version, string $commit ): void {
		$marker = array(
			'schema'         => 'ran-booster-core-release',
			'schema_version' => 1,
			'version'        => $version,
			'commit'         => $commit,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable focused fixture setup.
		self::assertIsInt(
			file_put_contents(
				$this->directory . '/ran-booster-release.json',
				(string) json_encode( $marker )
			)
		);
	}

	// phpcs:enable WordPress.WP.AlternativeFunctions.json_encode_json_encode
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}
