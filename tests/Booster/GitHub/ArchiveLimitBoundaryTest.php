<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once dirname( __DIR__, 2 ) . '/Support/NeutralReleaseUpdaterFixtures.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Booster\GitHub\GitHubReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReference;
use Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class ArchiveLimitBoundaryTest extends TestCase {
	public function testReleaseInspectionResolvesAndValidatesNonDefaultLimitLazily(): void {
		$reads     = 0;
		$registrar = new class() {
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
		$provider  = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$registrar,
			static function () use ( &$reads ): int {
				++$reads;

				return 1048576;
			}
		);
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );

		self::assertSame( 0, $reads );
		self::assertSame(
			'v2:' . str_repeat( 'b', 64 ),
			$provider->inspectRelease( 'plugin', $repository, '42', 'v1.2.3', 'stable' )->fingerprint
		);
		self::assertSame( 2, $reads );
		self::assertCount( 7, $registrar->arguments );
		self::assertSame( 1048576, $registrar->arguments[6] );
	}

	public function testDefaultReleaseSourceKeepsLegacyRegistrarCallShape(): void {
		$registrar = new class() {
			/** @var list<mixed> */
			public array $arguments = array();

			public function releases( mixed ...$arguments ): object {
				$this->arguments = $arguments;

				return new class() {
					/** @return array<string, mixed> */
					public function list(): array {
						return array(
							'ok'             => true,
							'code'           => 'releases_listed',
							'value'          => array( 'candidates' => array(), 'not_modified' => false ),
							'retry_after'    => null,
							'cleanup_status' => 'not_applicable',
						);
					}
				};
			}
		};
		$provider  = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader(),
			$registrar
		);
		$repository = new RepositoryReference( 'owner/example', '123456789', false, null );

		$provider->listReleaseCandidates( 'plugin', $repository, 'stable' );
		self::assertCount( 6, $registrar->arguments );
	}

	public function testNativeTargetOnlyAddsTheOptionalArgumentForNonDefaultLimit(): void {
		$nonDefault = new class() {
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
		$target     = new GitHubReleaseNativeTarget(
			$nonDefault,
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
		self::assertCount( 8, $nonDefault->arguments );
		self::assertSame( 1048576, $nonDefault->arguments[7] );

		$default = new class() {
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
			$default,
			'plugin',
			'/wordpress/wp-content/plugins/example/example.php',
			'owner/example',
			'42',
			null,
			'stable',
			'manual'
		);

		self::assertTrue( $target->register() );
		self::assertCount( 7, $default->arguments );
	}
}
