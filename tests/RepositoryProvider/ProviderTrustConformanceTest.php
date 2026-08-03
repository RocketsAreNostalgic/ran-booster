<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\GitHub\RepositoryBrowser;
use RAN\RepositoryProvider\GitHubProvider;
use RAN\RepositoryProvider\GitHubWebhookNormalizer;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderWebhookProfileReader;
use ReflectionClass;
use Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;

final class ProviderTrustConformanceTest extends TestCase {

	public function testBuiltInGitHubDependsOnlyOnProviderBoundReaders(): void {
		$browserParameter  = ( new ReflectionClass( RepositoryBrowser::class ) )
			->getConstructor()?->getParameters()[0] ?? null;
		$providerParameter = ( new ReflectionClass( GitHubProvider::class ) )
			->getConstructor()?->getParameters()[0] ?? null;
		$webhookParameter  = ( new ReflectionClass( GitHubWebhookNormalizer::class ) )
			->getConstructor()?->getParameters()[0] ?? null;

		self::assertNotNull( $browserParameter );
		self::assertNotNull( $providerParameter );
		self::assertNotNull( $webhookParameter );
		self::assertSame( ProviderCredentialStore::class, (string) $browserParameter->getType() );
		self::assertSame( ProviderCredentialStore::class, (string) $providerParameter->getType() );
		self::assertSame( ProviderWebhookProfileReader::class, (string) $webhookParameter->getType() );
	}

	public function testGitHubConstructionPerformsNoCredentialOrWebhookRead(): void {
		$store    = new class() implements ProviderCredentialStore {
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
		$browser  = new RepositoryBrowser( $store );
		$webhooks = new GitHubWebhookNormalizer( $store, new EmptyAuthenticatedWebhookDeliveryEvidenceReader() );

		new GitHubProvider( $store, $browser, $webhooks );

		self::assertSame( 0, $store->reads );
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
			self::assertStringContainsString(
				'does not authenticate a third-party publisher',
				$source,
				$relativePath
			);
		}
	}
}
