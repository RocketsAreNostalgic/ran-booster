<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Booster\GitHub\GitHubReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReference;
use RuntimeException;
use Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\Booster\GitHub\Support\NeutralReleaseUpdaterFixtures;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

/** Proves GitHub consumes host/updater archive policy without owning it. */
final class ArchiveLimitBoundaryTest extends TestCase {
	protected function setUp(): void {
		NeutralReleaseUpdaterFixtures::reset();
	}

	public function testReleaseInspectionResolvesAndValidatesSuppliedLimitLazily(): void {
		$limitReads = 0;
		$registrar  = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function releases( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					/** @return array<string, mixed> */
					public function inspect( string $releaseIdentity, string $tag ): array {
						return array(
							'ok'             => true,
							'code'           => 'release_inspected',
							'value'          => array(
								'release_identity'       => $releaseIdentity,
								'tag'                    => $tag,
								'version'                => '1.2.3',
								'commit_identity'        => str_repeat( 'a', 40 ),
								'package_root'           => 'example',
								'main_file'              => 'example.php',
								'fingerprint'            => 'v2:' . str_repeat( 'b', 64 ),
								'target_type'            => 'plugin',
								'channel'                => 'stable',
								'canonical_update_uri'   => 'https://github.com/owner/example',
								'repository_locator'     => 'owner/example',
								'repository_identity'    => '123456789',
								'maximum_artifact_bytes' => 1048576,
							),
							'retry_after'    => null,
							'cleanup_status' => 'complete',
						);
					}
				};
			}
		};
		$provider   = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$registrar,
			static function () use ( &$limitReads ): int {
				++$limitReads;

				return 1048576;
			}
		);
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );

		self::assertSame( 0, $limitReads );
		self::assertSame(
			'v2:' . str_repeat( 'b', 64 ),
			$provider->inspectRelease( 'plugin', $repository, '42', 'v1.2.3', 'stable' )->fingerprint
		);
		self::assertSame( 2, $limitReads );
		self::assertCount( 7, $registrar->arguments );
		self::assertSame( 1048576, $registrar->arguments[6] );
	}

	public function testReleaseSourceWithoutHostLimitUsesUpdaterOwnedDefaultContract(): void {
		$registrar  = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function maximumArtifactBytes(): int {
				throw new \LogicException( 'GitHub must not discover host policy from the updater registrar.' );
			}

			public function releases(
				mixed $provider,
				mixed $packageType,
				mixed $repository,
				mixed $repositoryId,
				mixed $channel,
				mixed $accessToken
			): object {
				$this->arguments = array( $provider, $packageType, $repository, $repositoryId, $channel, $accessToken );

				return new class() {
					/** @return array<string, mixed> */
					public function list(): array {
						return array(
							'ok'             => true,
							'code'           => 'releases_listed',
							'value'          => array(
								'candidates'   => array(),
								'not_modified' => false,
							),
							'retry_after'    => null,
							'cleanup_status' => 'not_applicable',
						);
					}
				};
			}
		};
		$provider   = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$registrar
		);
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );

		$provider->listReleaseCandidates( 'plugin', $repository, 'stable' );
		self::assertCount( 6, $registrar->arguments );
	}

	public function testUnavailableReleaseRuntimeFailsOnlyAtReleaseBoundary(): void {
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			new class() {
				public function releases( mixed ...$arguments ): object {
					unset( $arguments );

					return new class() {};
				}
			}
		);

		self::assertSame( 'gh', $provider->getMetadata()->code->value );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionCode( 503 );
		$provider->listReleaseCandidates(
			'plugin',
			new RepositoryReference( 'owner/example', '123456789', false, null ),
			'stable'
		);
	}

	public function testNativeTargetForwardsSuppliedHostLimit(): void {
		$runtime = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function plugin( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					public function register(): bool {
						return true;
					}
				};
			}
		};
		$target  = new GitHubReleaseNativeTarget(
			$runtime,
			'plugin',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			null,
			'stable',
			'manual',
			static fn (): int => 1048576
		);

		self::assertTrue( $target->register() );
		self::assertCount( 8, $runtime->arguments );
		self::assertSame( 1048576, $runtime->arguments[7] );
	}

	public function testDirectNativeTargetWithoutHostLimitUsesUpdaterOwnedDefaultContract(): void {
		$runtime = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function plugin( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					public function register(): bool {
						return true;
					}
				};
			}
		};
		$target  = new GitHubReleaseNativeTarget(
			$runtime,
			'plugin',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			null,
			'stable',
			'manual'
		);

		self::assertTrue( $target->register() );
		self::assertCount( 7, $runtime->arguments );
	}

	public function testProviderCreatedNativeTargetWithoutHostLimitUsesUpdaterOwnedDefaultContract(): void {
		$runtime  = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function plugin( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					public function register(): bool {
						return true;
					}
				};
			}
		};
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$runtime
		);
		$target   = $provider->createNativeTarget(
			'plugin',
			new RepositoryReference( 'owner/example', '42', false, null ),
			'/wordpress/wp-content/plugins/example/example.php',
			'example',
			'example/example.php',
			'stable',
			'manual'
		);

		self::assertTrue( $target->register() );
		self::assertCount( 7, $runtime->arguments );
	}

	public function testGitHubReleaseAdaptersDoNotOwnBoosterDefaultLiteral(): void {
		foreach ( array( GitHubProvider::class, GitHubReleaseNativeTarget::class ) as $class ) {
			$file = ( new \ReflectionClass( $class ) )->getFileName();
			self::assertIsString( $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- source-level ownership regression assertion.
			$source = file_get_contents( $file );
			self::assertIsString( $source );
			self::assertStringNotContainsString( '52428800', $source );
			self::assertStringNotContainsString( 'DEFAULT_MAXIMUM_ARTIFACT_BYTES', $source );
		}
	}
}
