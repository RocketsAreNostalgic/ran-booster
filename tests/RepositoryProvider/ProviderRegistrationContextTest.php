<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\InvalidProviderPolicy;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use Tests\RepositoryProvider\Support\ExternalFixtureProvider;

final class ProviderRegistrationContextTest extends TestCase {

	public function testContextExposesOnlyTheResolvedArtifactLimitAndResolvesLazily(): void {
		$resolutions = 0;
		$context     = new ProviderRegistrationContext(
			static function () use ( &$resolutions ): int {
				++$resolutions;

				return 52_428_800;
			},
		);
		$methods     = get_class_methods( ProviderRegistrationContext::class );
		if ( false === $methods ) {
			self::fail( 'ProviderRegistrationContext methods could not be inspected.' );
		}
		sort( $methods );

		self::assertSame( 0, $resolutions );
		self::assertSame( 52_428_800, $context->maximumArtifactBytes() );
		self::assertSame( 1, $resolutions );
		self::assertSame( array( '__construct', 'maximumArtifactBytes' ), $methods );
		self::assertSame( array(), ( new \ReflectionClass( ProviderRegistrationContext::class ) )->getProperties( \ReflectionProperty::IS_PUBLIC ) );
	}

	public function testContextDefersResolverFailureUntilPolicyIsRead(): void {
		$context = new ProviderRegistrationContext(
			static function (): int {
				throw new \InvalidArgumentException( 'invalid host policy' );
			},
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid host policy' );

		$context->maximumArtifactBytes();
	}

	public function testEveryOptedInCredentialBearingFactoryReceivesTheSameHostContext(): void {
		$context  = new ProviderRegistrationContext( static fn (): int => 67_108_864 );
		$observed = array();
		$registry = $this->registry( $context );

		$registry->registerWithCredentialStore(
			'fixture-one',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence,
				ProviderRegistrationContext $registrationContext
			) use ( &$observed ): ExternalFixtureProvider {
				unset( $deliveryEvidence );
				$observed[] = $registrationContext;

				return new ExternalFixtureProvider( 'fixture-one', $credentials );
			}
		);
		$registry->registerWithCredentialStore(
			'fixture-two',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence,
				ProviderRegistrationContext $registrationContext
			) use ( &$observed ): ExternalFixtureProvider {
				unset( $deliveryEvidence );
				$observed[] = $registrationContext;

				return new ExternalFixtureProvider( 'fixture-two', $credentials );
			}
		);

		self::assertCount( 2, $observed );
		self::assertSame( $context, $observed[0] );
		self::assertSame( $context, $observed[1] );
	}

	public function testApi11RejectsTwoArgumentFactoriesBeforeInvocation(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );
		$this->expectExceptionMessage( 'The provider factory does not implement the Provider API 11 registration signature.' );

		$registry->registerWithCredentialStore(
			'bb',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence
			): ExternalFixtureProvider {
				unset( $deliveryEvidence );

				return new ExternalFixtureProvider( 'bb', $credentials );
			}
		);
	}

	public function testApi11RejectsVariadicThirdParameter(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );
		$this->expectExceptionMessage( 'The provider factory does not implement the Provider API 11 registration signature.' );

		$registry->registerWithCredentialStore(
			'variadic',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence,
				mixed ...$additional
			): ExternalFixtureProvider {
				unset( $deliveryEvidence, $additional );

				return new ExternalFixtureProvider( 'variadic', $credentials );
			}
		);
	}

	public function testApi11RejectsByReferenceContextParameter(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );
		$this->expectExceptionMessage( 'The provider factory does not implement the Provider API 11 registration signature.' );

		$registry->registerWithCredentialStore(
			'by-reference',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $deliveryEvidence,
				ProviderRegistrationContext &$registrationContext
			): ExternalFixtureProvider {
				unset( $deliveryEvidence, $registrationContext );

				return new ExternalFixtureProvider( 'by-reference', $credentials );
			}
		);
	}

	public function testApi11RejectsCredentialRegistrationWithoutHostContext(): void {
		$credentials = new class() implements ProviderCredentialStore {
			public function credentialProfiles(): array {
				return array();
			}

			public function credentialMaterial( ?string $id = null ): ?array {
				unset( $id );
				return null;
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
		$registry = new ProviderRegistry(
			array(),
			new ProviderSecretPolicyCatalog(),
			static fn ( ProviderCode $code ): ProviderCredentialStore => $credentials,
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => $deliveryEvidence
		);

		$this->expectException( InvalidProviderPolicy::class );
		$this->expectExceptionMessage( 'The provider registration context is unavailable.' );

		$registry->registerWithCredentialStore(
			'fixture',
			static function (
				ProviderCredentialStore $providerCredentials,
				AuthenticatedWebhookDeliveryEvidenceReader $providerDeliveryEvidence,
				ProviderRegistrationContext $registrationContext
			) use ( $credentials ): ExternalFixtureProvider {
				unset( $providerCredentials, $providerDeliveryEvidence, $registrationContext );

				return new ExternalFixtureProvider( 'fixture', $credentials );
			}
		);
	}


	private function registry( ProviderRegistrationContext $context ): ProviderRegistry {
		$credentials      = new class() implements ProviderCredentialStore {
			public function credentialProfiles(): array {
				return array();
			}

			public function credentialMaterial( ?string $id = null ): ?array {
				unset( $id );

				return null;
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

		return new ProviderRegistry(
			array(),
			new ProviderSecretPolicyCatalog(),
			static fn ( ProviderCode $code ): ProviderCredentialStore => $credentials,
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => $deliveryEvidence,
			$context
		);
	}
}
