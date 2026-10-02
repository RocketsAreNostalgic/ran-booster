<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\WordPress\CoreSelfUpdateNativeTarget;

#[CoversClass( CoreSelfUpdateNativeTarget::class )]
final class CoreSelfUpdateNativeTargetTest extends TestCase {

	public function test_delegates_registration_refresh_and_bounded_passive_status(): void {
		$updater = new class() {
			public int $registrations = 0;
			public int $refreshes     = 0;

			public function register(): bool {
				++$this->registrations;

				return true;
			}

			/** @return array<string, mixed> */
			public function status(): array {
				return array(
					'state'                => 'active',
					'declaration_accepted' => true,
					'hooks_registered'     => true,
					'code'                 => 'target_active',
					'native'               => array(
						'candidate_header_version'  => null,
						'candidate_tag'             => null,
						'candidate_validation_code' => null,
						'candidate_version'         => null,
						'failure_code'              => null,
						'installed_version'         => '1.0.0',
						'last_check'                => 1_700_000_000,
						'offered_release_identity'  => 'release:42',
						'offered_version'           => '1.1.0',
						'relationship'              => 'newer',
					),
				);
			}

			public function refresh(): bool {
				++$this->refreshes;

				return true;
			}
		};
		$target  = new CoreSelfUpdateNativeTarget( $updater );

		self::assertTrue( $target->register() );
		self::assertTrue( $target->register() );
		self::assertSame( 2, $updater->registrations );

		$status = $target->status();
		self::assertInstanceOf( RepositoryReleaseNativeTargetStatus::class, $status );
		self::assertTrue( $status->active );
		self::assertSame( '1.1.0', $status->offered_version );
		self::assertSame( 'newer', $status->version_relationship );
		self::assertSame( 1_700_000_000, $status->last_check );
		self::assertSame( '', $status->failure_code );

		self::assertTrue( $target->refresh() );
		self::assertSame( 1, $updater->refreshes );
	}

	public function test_preserves_existing_inactive_updater_diagnostic_code(): void {
		$updater = new class() {
			public function register(): bool {
				return true;
			}

			/** @return array<string, mixed> */
			public function status(): array {
				return array(
					'state'                => 'inactive',
					'declaration_accepted' => true,
					'hooks_registered'     => false,
					'code'                 => 'runtime_environment_invalid',
					'native'               => null,
				);
			}

			public function refresh(): bool {
				return false;
			}
		};

		$status = ( new CoreSelfUpdateNativeTarget( $updater ) )->status();

		self::assertFalse( $status->active );
		self::assertSame( 'github_updater_runtime_environment_invalid', $status->failure_code );
	}

	public function test_rejects_incomplete_release_and_candidate_identity_tuples(): void {
		$updater = new class() {
			/** @var array<string, mixed> */
			public array $native = array(
				'candidate_header_version'  => null,
				'candidate_tag'             => null,
				'candidate_validation_code' => null,
				'candidate_version'         => null,
				'failure_code'              => null,
				'installed_version'         => '1.0.0',
				'last_check'                => 1_700_000_000,
				'offered_release_identity'  => null,
				'offered_version'           => '1.1.0',
				'relationship'              => 'newer',
			);

			public function register(): bool {
				return true;
			}

			/** @return array<string, mixed> */
			public function status(): array {
				return array(
					'state'                => 'active',
					'declaration_accepted' => true,
					'hooks_registered'     => true,
					'code'                 => 'target_active',
					'native'               => $this->native,
				);
			}

			public function refresh(): bool {
				return false;
			}
		};
		$target  = new CoreSelfUpdateNativeTarget( $updater );

		$status = $target->status();
		self::assertFalse( $status->active );
		self::assertSame( 'github_updater_status_unavailable', $status->failure_code );

		$updater->native['offered_version'] = null;
		$updater->native['candidate_tag']   = 'v1.1.0';
		$status                             = $target->status();
		self::assertFalse( $status->active );
		self::assertSame( 'github_updater_status_unavailable', $status->failure_code );

		$updater->native['candidate_validation_code'] = 'archive_identity_verified';
		$updater->native['candidate_version']         = '1.1.0';
		$status                                       = $target->status();
		self::assertFalse( $status->active );
		self::assertSame( 'github_updater_status_unavailable', $status->failure_code );
	}

	public function test_malformed_or_throwing_updater_state_fails_closed(): void {
		$malformed = new class() {
			public function register(): bool {
				return true;
			}

			/** @return array<string, mixed> */
			public function status(): array {
				return array( 'state' => 'active' );
			}

			public function refresh(): bool {
				throw new \RuntimeException( 'sensitive failure' );
			}
		};
		$target    = new CoreSelfUpdateNativeTarget( $malformed );

		self::assertSame( 'github_updater_status_unavailable', $target->status()->failure_code );
		self::assertFalse( $target->refresh() );

		$incompatible = new CoreSelfUpdateNativeTarget( new \stdClass() );
		self::assertFalse( $incompatible->register() );
		self::assertSame( 'github_updater_status_unavailable', $incompatible->status()->failure_code );
		self::assertFalse( $incompatible->refresh() );
	}
}
