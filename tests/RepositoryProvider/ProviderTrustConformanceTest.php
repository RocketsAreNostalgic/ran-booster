<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Booster\GitHub\RepositoryBrowser;
use RAN\Booster\GitHub\WebhookNormalizer as GitHubWebhookNormalizer;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderWebhookProfileReader;
use ReflectionClass;
use ReflectionMethod;

final class ProviderTrustConformanceTest extends TestCase {

	public function testBuiltInGitHubDependsOnlyOnProviderBoundReaders(): void {
		$browserParameter   = ( new ReflectionClass( RepositoryBrowser::class ) )
			->getConstructor()?->getParameters()[0] ?? null;
		$providerParameters = ( new ReflectionClass( GitHubProvider::class ) )
			->getMethod( 'create' )->getParameters();
		$providerReflection = new ReflectionClass( GitHubProvider::class );
		$compositionMethod  = $providerReflection->getMethod( 'create' );
		$webhookParameter   = ( new ReflectionClass( GitHubWebhookNormalizer::class ) )
			->getConstructor()?->getParameters()[0] ?? null;

		self::assertNotNull( $browserParameter );
		self::assertCount( 2, $providerParameters );
		self::assertNotNull( $webhookParameter );
		self::assertSame( ProviderCredentialStore::class, (string) $browserParameter->getType() );
		self::assertSame( ProviderCredentialStore::class, (string) $providerParameters[0]->getType() );
		self::assertSame( AuthenticatedWebhookDeliveryEvidenceReader::class, (string) $providerParameters[1]->getType() );
		self::assertSame( \RAN\RepositoryProvider\RepositoryProvider::class, (string) $compositionMethod->getReturnType() );
		self::assertTrue( $compositionMethod->isPublic() );
		self::assertTrue( $compositionMethod->isStatic() );
		self::assertTrue( $providerReflection->getConstructor()?->isPrivate() );
		self::assertSame(
			array( 'create' ),
			array_values(
				array_map(
					static fn ( ReflectionMethod $method ): string => $method->getName(),
					array_filter(
						$providerReflection->getMethods( ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC ),
						static fn ( ReflectionMethod $method ): bool => $method->isPublic()
							&& $method->isStatic()
							&& GitHubProvider::class === $method->getDeclaringClass()->getName()
					)
				)
			)
		);
		self::assertSame( ProviderWebhookProfileReader::class, (string) $webhookParameter->getType() );
	}

	public function testGitHubConstructionPerformsNoCredentialOrWebhookRead(): void {
		$store            = new class() implements ProviderCredentialStore {
			public int $reads = 0;

			public function credentialProfiles(): array {
				++$this->reads;

				return array();
			}

			public function credentialMaterial( ?string $id = null ): ?array {
				unset( $id );
				++$this->reads;

				return null;
			}

			public function hasWebhookProfile(): bool {
				++$this->reads;

				return false;
			}
		};
		$deliveryEvidence = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public int $reads = 0;

			public function latestAuthenticatedDelivery( ProviderCode $provider ): ?AuthenticatedWebhookDeliveryEvidence {
				unset( $provider );
				++$this->reads;

				return null;
			}
		};
		GitHubProvider::create( $store, $deliveryEvidence );

		self::assertSame( 0, $store->reads );
		self::assertSame( 0, $deliveryEvidence->reads );
	}

	public function testCredentialSurfacesDiscloseTheProviderTrustDecision(): void {
		$root = dirname( __DIR__, 2 );
		foreach (
			array(
				'views/provider.php',
				'views/provider/modals.php',
				'views/packages/fields/credential.php',
				'views/provider-public-lookup-profile.php',
				'views/portability-review.php',
				'views/troubleshooting.php',
			) as $relativePath
		) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source inspection is the contract under test.
			$source = file_get_contents( $root . '/' . $relativePath );
			self::assertIsString( $source );
			if ( 'views/provider.php' === $relativePath ) {
				self::assertStringContainsString( '$providerTrustDescription', $source );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source inspection is the contract under test.
				$source = file_get_contents( $root . '/RAN/Admin/ProviderSettingsPresenter.php' );
				self::assertIsString( $source );
			}
			self::assertStringContainsString(
				'does not authenticate a third-party publisher',
				$source,
				$relativePath
			);
		}
	}
}
