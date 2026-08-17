<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Booster\GitHub\RepositoryBrowser as GitHubRepositoryBrowser;
use RAN\Booster\GitHub\WebhookNormalizer as GitHubWebhookNormalizer;
use RAN\Provider\ProviderCapability;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookProfileReader;
use RAN\RepositoryProvider\RepositoryBrowser;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\WebhookNormalizer;
use ReflectionClass;
use ReflectionMethod;

final class VendorConformanceTest extends TestCase {

	public function testModuleCompositionUsesOnlyDocumentedProviderInputs(): void {
		$browserParameter   = ( new ReflectionClass( GitHubRepositoryBrowser::class ) )
			->getConstructor()?->getParameters()[0] ?? null;
		$providerReflection = new ReflectionClass( GitHubProvider::class );
		$compositionMethod  = $providerReflection->getMethod( 'create' );
		$providerParameters = $compositionMethod->getParameters();
		$webhookParameter   = ( new ReflectionClass( GitHubWebhookNormalizer::class ) )
			->getConstructor()?->getParameters()[0] ?? null;

		self::assertNotNull( $browserParameter );
		self::assertCount( 2, $providerParameters );
		self::assertNotNull( $webhookParameter );
		self::assertSame( ProviderCredentialStore::class, (string) $browserParameter->getType() );
		self::assertSame( ProviderCredentialStore::class, (string) $providerParameters[0]->getType() );
		self::assertSame( AuthenticatedWebhookDeliveryEvidenceReader::class, (string) $providerParameters[1]->getType() );
		self::assertSame( RepositoryProvider::class, (string) $compositionMethod->getReturnType() );
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testOrdinaryProviderApiRegistrationPublishesTheCompleteGitHubContractWithoutCoreComposition(): void {
		self::assertFalse( class_exists( 'RAN\\BoosterServiceProvider', false ) );
		self::assertFalse( class_exists( 'RAN\\Internal\\CoreContainer', false ) );

		$credentials       = new class() implements ProviderCredentialStore {
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
		$deliveryEvidence  = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public int $reads = 0;

			public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
				++$this->reads;
				return null;
			}
		};
		$policies          = new ProviderSecretPolicyCatalog();
		$requestedStores   = array();
		$requestedEvidence = array();
		$registry          = new ProviderRegistry(
			array(),
			$policies,
			static function ( ProviderCode $code ) use ( $credentials, &$requestedStores ): ProviderCredentialStore {
				$requestedStores[] = $code->value;
				return $credentials;
			},
			static function ( ProviderCode $code ) use ( $deliveryEvidence, &$requestedEvidence ): AuthenticatedWebhookDeliveryEvidenceReader {
				$requestedEvidence[] = $code->value;
				return $deliveryEvidence;
			}
		);

		$registry->registerWithCredentialStore(
			'gh',
			static fn ( ProviderCredentialStore $store, AuthenticatedWebhookDeliveryEvidenceReader $evidence ): RepositoryProvider => GitHubProvider::create( $store, $evidence )
		);
		$registry->seal();

		$provider = $registry->get( 'gh' );
		$metadata = $provider->getMetadata();
		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertTrue( $registry->isSealed() );
		self::assertSame( array( 'gh' ), $requestedStores );
		self::assertSame( array( 'gh' ), $requestedEvidence );
		self::assertSame( 0, $credentials->reads );
		self::assertSame( 0, $deliveryEvidence->reads );
		self::assertSame( 'gh', $metadata->code->value );
		self::assertSame( 'GitHub', $metadata->label );
		self::assertSame( 'https://github.com/', $metadata->repositoryUrlBase );
		self::assertSame( 'Owner', $metadata->ownerLabel );
		self::assertSame( 'git-host', $metadata->admin?->navigation?->group );
		self::assertSame( 100, $metadata->admin?->navigation?->slot );
		self::assertSame( 'gh', $registry->administrationMetadata()[0]->code->value );

		foreach (
			array(
				CredentialValidator::class,
				RepositoryBrowser::class,
				CredentialedPublicRepositoryBrowser::class,
				ProviderCredentialPolicySupplier::class,
				WebhookNormalizer::class,
				RepositoryWebhookSettingsLink::class,
				RepositoryWebhookFitness::class,
				RepositoryWebhookManagement::class,
				RepositoryReleaseMetadata::class,
			) as $capability
		) {
			self::assertTrue( is_a( $capability, ProviderCapability::class, true ) );
			self::assertSame( $provider, $registry->requireCapability( 'gh', $capability ) );
		}
		$releaseMetadata = $registry->requireCapability( 'gh', RepositoryReleaseMetadata::class );
		$repository      = new RepositoryReference( 'owner/repository', '42', false, null );
		self::assertSame( 'https://github.com/owner/repository', $releaseMetadata->expectedUpdateUri( $repository ) );
		self::assertSame( 'https://github.com/owner/repository/releases/tag/v1.0.0%2Bbuild', $releaseMetadata->releaseDetailsUrl( $repository, 'v1.0.0+build' ) );
		self::assertSame( '', $releaseMetadata->releaseDetailsUrl( $repository, '' ) );
		self::assertSame( '', $releaseMetadata->expectedUpdateUri( new RepositoryReference( 'owner name/repository', '42', false, null ) ) );

		self::assertSame( $provider->getCredentialPolicy(), $policies->credentialPolicy( 'gh' ) );
		self::assertSame( $provider->getWebhookPolicy(), $policies->webhookPolicy( 'gh' ) );
		self::assertSame( array( 'RAN_BOOSTER_GITHUB_TOKEN' ), $policies->credentialPolicy( 'gh' )->getConstantNames() );
		self::assertSame( 'x-hub-signature-256', $policies->webhookPolicy( 'gh' )->getSignatureHeader() );

		self::assertFalse( class_exists( 'RAN\\BoosterServiceProvider', false ) );
		self::assertFalse( class_exists( 'RAN\\Internal\\CoreContainer', false ) );
	}
}
