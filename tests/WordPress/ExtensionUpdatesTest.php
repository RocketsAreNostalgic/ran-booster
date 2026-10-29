<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WordPress\ExtensionUpdates;
use RAN\WordPress\ManagedReleaseUpdaterRegistrar;

final class ExtensionUpdatesTest extends TestCase {
	#[RunInSeparateProcess]
	public function test_registers_compatible_active_and_inactive_catalogue_extensions_through_one_path(): void {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', '/tmp/wp-plugins' );
		}
		$runtime = new class() {
			/** @var list<array<array-key, mixed>> */
			public array $plugins = array();
			/** @var list<callable> */
			public array $admissions = array();
			public function with_descriptor_admission( callable $admission ): object {
				$this->admissions[] = $admission;
				return new class( function ( array $arguments ): void {
$this->plugins[] = $arguments; } ) {
					public function __construct( private \Closure $record ) {}
					public function plugin( mixed ...$arguments ): object {
						( $this->record )( $arguments );
						return new class() { public function register(): bool {
								return true;
						} };
					}
				};
			}
		};
		$updates = new ExtensionUpdates(
			new ManagedReleaseUpdaterRegistrar( $runtime ),
			static fn ( string $plugin ): bool => '' === $plugin && false,
			fn (): array => array( $this->extension( 'Active' ), $this->extension( 'Installed, inactive' ) )
		);

		$updates->register();

		self::assertCount( 2, $runtime->plugins );
		self::assertSame( 'manual', $runtime->plugins[0][5] );
		self::assertSame( 'manual', $runtime->plugins[1][5] );
		self::assertTrue( ( $runtime->admissions[0] )( $this->descriptor() ) );
		self::assertFalse( ( $runtime->admissions[0] )( $this->descriptor( version: '1.2.3-beta.2' ) ) );
		self::assertFalse( ( $runtime->admissions[0] )( $this->descriptor( digest: str_repeat( 'b', 64 ) ) ) );
	}

	#[RunInSeparateProcess]
	public function test_descriptor_admission_rechecks_the_current_catalogue_entry(): void {
		$runtime = new class() {
			public mixed $admission = null;
			public function with_descriptor_admission( callable $admission ): object {
				$this->admission = $admission;
				return new class() { public function plugin( mixed ...$arguments ): object {
						unset( $arguments );
						return new class() { public function register(): bool {
								return true;
						} };
				} };
			}
		};
		$calls   = 0;
		$updates = new ExtensionUpdates(
			new ManagedReleaseUpdaterRegistrar( $runtime ),
			static fn ( string $plugin ): bool => '' === $plugin && false,
			function () use ( &$calls ): array {
				++$calls;
				$extension               = $this->extension( 'Active' );
				$extension['compatible'] = 1 === $calls;
				return array( $extension );
			}
		);

		$updates->register();

		self::assertFalse( ( $runtime->admission )( $this->descriptor() ) );
	}

	public function test_does_not_register_an_incompatible_or_owned_catalogue_extension(): void {
		$runtime                    = new class() {
			public int $registrations = 0;
			public function with_descriptor_admission( callable $admission ): object {
				unset( $admission );
				return new class( function (): void {
++$this->registrations; } ) {
					public function __construct( private \Closure $record ) {}
					public function plugin( mixed ...$arguments ): object {
						unset( $arguments );
						( $this->record )();
						return new class() { public function register(): bool {
								return true;
						} };
					}
				};
			}
		};
		$incompatible               = $this->extension( 'Active' );
		$incompatible['compatible'] = false;
		$updates                    = new ExtensionUpdates(
			new ManagedReleaseUpdaterRegistrar( $runtime ),
			static fn ( string $plugin ): bool => '' !== $plugin,
			static fn (): array => array( $incompatible )
		);

		$updates->register();

		self::assertSame( 0, $runtime->registrations );
	}

	/** @return array<string, mixed> */
	private function extension( string $state ): array {
		return array(
			'plugin'               => 'example/example.php',
			'release_version'      => '1.2.3-beta.1',
			'asset_sha256'         => str_repeat( 'a', 64 ),
			'repository'           => 'owner/example',
			'repository_id'        => '42',
			'canonical_update_uri' => 'https://github.com/owner/example',
			'compatible'           => true,
			'state'                => $state,
		);
	}

	private function descriptor( string $version = '1.2.3-beta.1', string $digest = '' ): IdentityDescriptor {
		require_once dirname( __DIR__, 2 ) . '/vendor/ran/wp-release-updater/runtime.php';
		$digest = '' === $digest ? str_repeat( 'a', 64 ) : $digest;
		return IdentityDescriptor::create(
			array(
				'artifact_filename'          => 'example.zip',
				'artifact_identity'          => 'asset-1',
				'artifact_sha256'            => $digest,
				'artifact_size'              => 100,
				'assurance_facts'            => array_fill_keys( array( 'exact_artifact_identity', 'exact_commit_identity', 'exact_reacquisition_supported', 'exact_release_identity', 'provenance_verified', 'publication_immutable', 'repository_identity_stable', 'trusted_digest_source' ), true ),
				'canonical_update_uri'       => 'https://github.com/owner/example',
				'channel'                    => 'prerelease',
				'commit_identity'            => 'commit-1',
				'installed_package_identity' => 'example/example.php',
				'prerelease'                 => true,
				'provider_code'              => 'github',
				'release_identity'           => 'release-1',
				'repository_identity'        => '42',
				'repository_locator'         => 'owner/example',
				'tag'                        => 'v' . $version,
				'target_type'                => 'plugin',
				'version'                    => $version,
			)
		);
	}
}
