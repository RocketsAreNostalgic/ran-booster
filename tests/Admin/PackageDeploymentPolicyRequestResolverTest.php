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

	public function test_automatic_policy_requires_webhook_capability_before_repository_resolution(): void {
		$provider = new class() implements RepositoryProvider {

			public int $resolve_calls = 0;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function get_provider_diagnostics(): ProviderDiagnostics {
				return new class() implements ProviderDiagnostics {
					public function diagnose( ProviderDiagnosticRequest $request ): array {
						return array();
					}
				};
			}

			public function resolve_repository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
				++$this->resolve_calls;

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

			public function prepare_archive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
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
			self::assertSame( 0, $provider->resolve_calls );
		}
	}

	public function test_resolver_defaults_missing_policy_to_manual(): void {
		$provider = new class() implements RepositoryProvider {

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'Fixture', 'https://example.test/', 'Owner' );
			}

			public function get_provider_diagnostics(): ProviderDiagnostics {
				return new class() implements ProviderDiagnostics {
					public function diagnose( ProviderDiagnosticRequest $request ): array {
						return array();
					}
				};
			}

			public function resolve_repository( \RAN\RepositoryProvider\RepositoryLookupRequest $request ): \RAN\RepositoryProvider\RepositoryDescriptor {
				return new \RAN\RepositoryProvider\RepositoryDescriptor(
					ProviderCode::parse( 'gh' ),
					$request->locator,
					'example',
					'repository-id',
					false,
					'main',
					$request->credential_id
				);
			}

			public function prepare_archive( \RAN\RepositoryProvider\ArchiveRequest $request ): \RAN\RepositoryProvider\PreparedArchive {
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
