<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use RAN\WordPress\CoreSelfUpdateNativeTarget;

#[CoversClass( CoreSelfUpdateNativeTarget::class )]
final class CoreSelfUpdateNativeTargetTest extends TestCase {

	public function testDelegatesRegistrationRefreshAndBoundedPassiveStatus(): void {
		$updater = new class() {
			public int $registrations = 0;
			public int $refreshes = 0;

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
						'candidate_header_version'   => null,
						'candidate_tag'              => null,
						'candidate_validation_code' => null,
						'candidate_version'          => null,
						'failure_code'               => null,
						'installed_version'           => '1.0.0',
						'last_check'                  => 1_700_000_000,
						'offered_release_identity'    => 'release:42',
						'offered_version'             => '1.1.0',
						'relationship'                => 'newer',
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
		self::assertSame( '1.1.0', $status->offeredVersion );
		self::assertSame( 'newer', $status->versionRelationship );
		self::assertSame( 1_700_000_000, $status->lastCheck );
		self::assertSame( '', $status->failureCode );

		self::assertTrue( $target->refresh() );
		self::assertSame( 1, $updater->refreshes );
	}

	public function testPreservesExistingInactiveUpdaterDiagnosticCode(): void {
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
		self::assertSame( 'github_updater_runtime_environment_invalid', $status->failureCode );
	}

	public function testMalformedOrThrowingUpdaterStateFailsClosed(): void {
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

		self::assertSame( 'github_updater_status_unavailable', $target->status()->failureCode );
		self::assertFalse( $target->refresh() );

		$incompatible = new CoreSelfUpdateNativeTarget( new \stdClass() );
		self::assertFalse( $incompatible->register() );
		self::assertSame( 'github_updater_status_unavailable', $incompatible->status()->failureCode );
		self::assertFalse( $incompatible->refresh() );
	}
}
