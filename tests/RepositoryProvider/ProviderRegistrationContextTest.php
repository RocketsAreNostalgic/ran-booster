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

	public function test_context_exposes_only_the_resolved_artifact_limit_and_resolves_lazily(): void {
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
		self::assertSame( 52_428_800, $context->maximum_artifact_bytes() );
		self::assertSame( 1, $resolutions );
		self::assertSame( array( '__construct', 'maximum_artifact_bytes' ), $methods );
		self::assertSame( array(), ( new \ReflectionClass( ProviderRegistrationContext::class ) )->getProperties( \ReflectionProperty::IS_PUBLIC ) );
	}

	public function test_context_defers_resolver_failure_until_policy_is_read(): void {
		$context = new ProviderRegistrationContext(
			static function (): int {
				throw new \InvalidArgumentException( 'invalid host policy' );
			},
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid host policy' );

		$context->maximum_artifact_bytes();
	}

	public function test_every_opted_in_credential_bearing_factory_receives_the_same_host_context(): void {
		$context  = new ProviderRegistrationContext( static fn (): int => 67_108_864 );
		$observed = array();
		$registry = $this->registry( $context );

		$registry->register_with_credential_store(
			'fixture-one',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				ProviderRegistrationContext $registration_context
			) use ( &$observed ): ExternalFixtureProvider {
				unset( $delivery_evidence );
				$observed[] = $registration_context;

				return new ExternalFixtureProvider( 'fixture-one', $credentials );
			}
		);
		$registry->register_with_credential_store(
			'fixture-two',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				ProviderRegistrationContext $registration_context
			) use ( &$observed ): ExternalFixtureProvider {
				unset( $delivery_evidence );
				$observed[] = $registration_context;

				return new ExternalFixtureProvider( 'fixture-two', $credentials );
			}
		);

		self::assertCount( 2, $observed );
		self::assertSame( $context, $observed[0] );
		self::assertSame( $context, $observed[1] );
	}

	public function test_api13_rejects_two_argument_factories_before_invocation(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );
		$this->expectExceptionMessage( 'The provider factory does not implement the Provider API 13 registration signature.' );

		$registry->register_with_credential_store(
			'bb',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence
			): ExternalFixtureProvider {
				unset( $delivery_evidence );

				return new ExternalFixtureProvider( 'bb', $credentials );
			}
		);
	}

	public function test_api13_rejects_variadic_third_parameter(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );
		$this->expectExceptionMessage( 'The provider factory does not implement the Provider API 13 registration signature.' );

		$registry->register_with_credential_store(
			'variadic',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				mixed ...$additional
			): ExternalFixtureProvider {
				unset( $delivery_evidence, $additional );

				return new ExternalFixtureProvider( 'variadic', $credentials );
			}
		);
	}

	public function test_api13_rejects_by_reference_context_parameter(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );
		$this->expectExceptionMessage( 'The provider factory does not implement the Provider API 13 registration signature.' );

		$registry->register_with_credential_store(
			'by-reference',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				ProviderRegistrationContext &$registration_context
			): ExternalFixtureProvider {
				unset( $delivery_evidence, $registration_context );

				return new ExternalFixtureProvider( 'by-reference', $credentials );
			}
		);
	}



	public function test_api13_rejects_nullable_registration_context(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );
		$this->expectExceptionMessage( 'The provider factory does not implement the Provider API 13 registration signature.' );

		$registry->register_with_credential_store(
			'nullable-context',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				?ProviderRegistrationContext $registration_context
			): ExternalFixtureProvider {
				unset( $delivery_evidence, $registration_context );

				return new ExternalFixtureProvider( 'nullable-context', $credentials );
			}
		);
	}

	public function test_api13_rejects_incorrect_first_parameter_type(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );

		$registry->register_with_credential_store(
			'wrong-credentials',
			static function (
				object $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				ProviderRegistrationContext $registration_context
			): ExternalFixtureProvider {
				unset( $delivery_evidence, $registration_context );
				assert( $credentials instanceof ProviderCredentialStore );

				return new ExternalFixtureProvider( 'wrong-credentials', $credentials );
			}
		);
	}

	public function test_api13_rejects_incorrect_second_parameter_type(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );

		$registry->register_with_credential_store(
			'wrong-evidence',
			static function (
				ProviderCredentialStore $credentials,
				object $delivery_evidence,
				ProviderRegistrationContext $registration_context
			): ExternalFixtureProvider {
				unset( $delivery_evidence, $registration_context );

				return new ExternalFixtureProvider( 'wrong-evidence', $credentials );
			}
		);
	}

	public function test_api13_rejects_additional_factory_parameters(): void {
		$registry = $this->registry( new ProviderRegistrationContext( static fn (): int => 52_428_800 ) );

		$this->expectException( InvalidProviderPolicy::class );

		$registry->register_with_credential_store(
			'extra-argument',
			static function (
				ProviderCredentialStore $credentials,
				AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence,
				ProviderRegistrationContext $registration_context,
				?string $extra = null
			): ExternalFixtureProvider {
				unset( $delivery_evidence, $registration_context, $extra );

				return new ExternalFixtureProvider( 'extra-argument', $credentials );
			}
		);
	}

	private function registry( ProviderRegistrationContext $context ): ProviderRegistry {
		$credentials       = new class() implements ProviderCredentialStore {
			public function credential_profiles(): array {
				return array();
			}

			public function credential_material( ?string $id = null ): ?array {
				unset( $id );

				return null;
			}

			public function has_webhook_profile(): bool {
				return false;
			}
		};
		$delivery_evidence = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
				return null;
			}
		};

		return new ProviderRegistry(
			array(),
			new ProviderSecretPolicyCatalog(),
			static fn ( ProviderCode $code ): ProviderCredentialStore => $credentials,
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => $delivery_evidence,
			$context
		);
	}
}
