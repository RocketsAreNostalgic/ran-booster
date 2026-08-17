<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use Closure;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Booster\GitHub\GitHubReleaseNativeTarget;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;

final class NativeTargetsTest extends TestCase {
	protected function tearDown(): void {
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'packageFactory' ) )->setValue( null, null );
	}

	public function testPrivateCredentialsRemainLazyAndPublicTargetsRemainCredentialFree(): void {
		$calls   = array();
		$updater = new class() {
			public function register(): void {
			}

			/** @return array<string, bool|string> */
			public function diagnostics(): array {
				return array(
					'registered'       => true,
					'selection_fixed'  => true,
					'selected_version' => 'test-runtime',
					'state'            => 'idle',
				);
			}

			public function refresh(): bool {
				return true;
			}
		};
		$factory = static function ( mixed ...$options ) use ( &$calls, $updater ): object {
			$calls[] = $options;

			return $updater;
		};
		( new \ReflectionProperty( GitHubReleaseNativeTarget::class, 'packageFactory' ) )->setValue( null, Closure::fromCallable( $factory ) );
		$credentials      = new class() implements ProviderCredentialStore {
			public int $reads = 0;

			public function credentialProfiles(): array {
				return array();
			}

			public function credentialMaterial( ?string $id = null ): ?array {
				++$this->reads;

				return array( 'secret' => 'github_pat_current' );
			}

			public function hasWebhookProfile(): bool {
				return false;
			}
		};
		$deliveryEvidence = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
				return null;
			}
		};
		$provider         = GitHubProvider::create( $credentials, $deliveryEvidence );
		self::assertInstanceOf( RepositoryReleaseNativeTargets::class, $provider );

		$privateTarget = $provider->createNativeTarget(
			'plugin',
			new RepositoryReference( 'owner/private', '42', true, 'profile_1' ),
			'/wordpress/wp-content/plugins/example/example.php',
			'example',
			'example/example.php',
			'stable',
			'manual'
		);
		self::assertSame( 0, $credentials->reads );
		self::assertTrue( $privateTarget->register() );
		self::assertSame( 0, $credentials->reads );
		self::assertIsCallable( $calls[0]['accessToken'] );
		self::assertSame( 'github_pat_current', $calls[0]['accessToken']() );
		self::assertSame( 1, $credentials->reads );

		$publicTarget = $provider->createNativeTarget(
			'theme',
			new RepositoryReference( 'owner/public', '43', false, null ),
			'/wordpress/wp-content/themes/example/style.css',
			'example',
			'example-theme',
			'prerelease',
			'automatic'
		);
		self::assertTrue( $publicTarget->register() );
		self::assertNull( $calls[1]['accessToken'] );
		self::assertSame( 'theme', $calls[1]['targetType'] );
		self::assertSame( 'example-theme', $calls[1]['stylesheet'] );
	}

	public function testTargetNormalizesPassiveStatusAndPreservesFalseRefresh(): void {
		$updater = new class() {
			public function register(): void {
			}

			/** @return array<string, mixed> */
			public function diagnostics(): array {
				return array(
					'registered'           => true,
					'selection_fixed'      => true,
					'selected_version'     => 'test-runtime',
					'state'                => 'error',
					'code'                 => 'github_updater_http_error',
					'offered_version'      => '2.0.0',
					'version_relationship' => 'newer',
					'last_check'           => 1_700_000_000,
					'next_check'           => 1_700_003_600,
					'candidate_validation' => array(
						'code'                   => 'release_identity_verified',
						'release_tag'            => 'v2.0.0',
						'release_version'        => '2.0.0',
						'package_header_version' => '2.0.0',
					),
				);
			}

			public function refresh(): bool {
				return false;
			}
		};
		$target  = new GitHubReleaseNativeTarget(
			'plugin',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			'example',
			'example/example.php',
			null,
			'stable',
			'manual',
			static fn ( mixed ...$options ): object => $updater
		);
		self::assertTrue( $target->register() );
		$status = $target->status();
		self::assertTrue( $status->active );
		self::assertSame( '2.0.0', $status->offeredVersion );
		self::assertSame( 'github_updater_http_error', $status->failureCode );
		self::assertSame( 'release_identity_verified', $status->candidateCode );
		self::assertFalse( $target->refresh() );
	}
}
