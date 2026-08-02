<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Deployment\DeploymentPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\UnsupportedProviderCapability;

final class PackageDeploymentPolicyRequestResolverTest extends TestCase {

	public function testAutomaticPolicyRequiresWebhookCapabilityBeforeRepositoryResolution(): void {
		$provider = new class() implements RepositoryProvider {

			public int $resolveCalls = 0;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function getProviderDiagnostics(): ProviderDiagnostics {
				return new class() implements ProviderDiagnostics {
					public function diagnose( ProviderDiagnosticRequest $request ): array {
						return array();
					}
				};
			}

			public function resolveRepository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
				++$this->resolveCalls;

				return new \RAN\RepositoryProvider\RepositoryDescriptor(
					ProviderCode::parse( 'gh' ),
					'owner/example',
					'example',
					'repository-id',
					false,
					'main',
					null
				);
			}

			public function prepareArchive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
				throw new \RuntimeException( 'Archive preparation is not used by this test.' );
			}
		};
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );

		$this->expectException( UnsupportedProviderCapability::class );
		try {
			$resolver->resolve(
				array(
					'provider'          => 'gh',
					'repository'        => 'owner/example',
					'deployment_policy' => DeploymentPolicy::AUTOMATIC->value,
				)
			);
		} finally {
			self::assertSame( 0, $provider->resolveCalls );
		}
	}

	public function testResolverDefaultsMissingPolicyToManual(): void {
		$provider = new class() implements RepositoryProvider {

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function getProviderDiagnostics(): ProviderDiagnostics {
				return new class() implements ProviderDiagnostics {
					public function diagnose( ProviderDiagnosticRequest $request ): array {
						return array();
					}
				};
			}

			public function resolveRepository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
				return new \RAN\RepositoryProvider\RepositoryDescriptor(
					ProviderCode::parse( 'gh' ),
					$request->locator,
					'example',
					'repository-id',
					false,
					'main',
					$request->credentialId
				);
			}

			public function prepareArchive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
				throw new \RuntimeException( 'Archive preparation is not used by this test.' );
			}
		};
		$resolver = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );

		$result = $resolver->resolve(
			array(
				'provider'   => 'gh',
				'repository' => 'owner/example',
			)
		);

		self::assertSame( DeploymentPolicy::MANUAL->value, $result['deployment_policy'] );
	}
}
