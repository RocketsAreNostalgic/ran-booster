<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/Support/ProviderOwnedCapability.php';
require_once __DIR__ . '/Support/SecondProviderOwnedCapability.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Provider\ProviderCapability;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use ReflectionClass;
use Stringable;
use Tests\RepositoryProvider\Support\ProviderOwnedCapability;
use Tests\RepositoryProvider\Support\SecondProviderOwnedCapability;

final class ProviderCapabilityContractTest extends TestCase {

	public function testCapabilityHostExposesNoEnumerationOrDescriptorSurface(): void {
		$marker   = new ReflectionClass( ProviderCapability::class );
		$registry = new ReflectionClass( ProviderRegistry::class );

		self::assertSame( array(), $marker->getMethods() );
		self::assertFalse( $registry->hasMethod( 'capabilities' ) );
		self::assertFalse( $registry->hasMethod( 'supportsCapability' ) );
	}

	public function testProviderOwnedFacetsResolveToTheSameRegisteredAggregate(): void {
		$provider = $this->provider();
		$registry = new ProviderRegistry( array( $provider ) );

		$first  = $registry->requireCapability( 'facet-fixture', ProviderOwnedCapability::class );
		$second = $registry->requireCapability( 'facet-fixture', SecondProviderOwnedCapability::class );

		self::assertSame( $provider, $first );
		self::assertSame( $provider, $second );
		self::assertSame( 'first', $first->providerOwnedValue() );
		self::assertSame( 'second', $second->secondProviderOwnedValue() );
	}

	/** @return iterable<string, array{class-string|string}> */
	public static function unknownContracts(): iterable {
		yield 'bare capability marker' => array( ProviderCapability::class );
		yield 'loaded non-marker interface' => array( Stringable::class );
		yield 'base provider contract' => array( RepositoryProvider::class );
		yield 'concrete class' => array( ProviderMetadata::class );
		yield 'unloaded symbol' => array( __NAMESPACE__ . '\\MissingProviderCapability' );
	}

	#[DataProvider( 'unknownContracts' )]
	public function testUnknownContractsFailWithoutChangingTheRegistry( string $capability ): void {
		$provider = $this->provider();
		$registry = new ProviderRegistry( array( $provider ) );

		try {
			$registry->requireCapability( 'facet-fixture', $capability );
			self::fail( 'An unknown capability contract must be rejected.' );
		} catch ( UnsupportedProviderCapability $exception ) {
			self::assertSame( 'Unknown repository provider capability.', $exception->getMessage() );
			self::assertSame( $provider, $registry->get( 'facet-fixture' ) );
			self::assertSame( array( 'facet-fixture' => $provider ), $registry->all() );
		}
	}

	public function testValidAbsentFacetFailsUnsupportedWithoutChangingTheRegistry(): void {
		$provider = $this->provider();
		$registry = new ProviderRegistry( array( $provider ) );

		try {
			$registry->requireCapability( 'facet-fixture', RepositoryWebhookFitness::class );
			self::fail( 'A valid capability absent from the provider must be rejected.' );
		} catch ( UnsupportedProviderCapability $exception ) {
			self::assertSame( 'Repository provider does not support the requested capability.', $exception->getMessage() );
			self::assertSame( $provider, $registry->get( 'facet-fixture' ) );
			self::assertSame( array( 'facet-fixture' => $provider ), $registry->all() );
		}
	}

	private function provider(): RepositoryProvider&ProviderOwnedCapability&SecondProviderOwnedCapability {
		return new class() implements RepositoryProvider, ProviderOwnedCapability, SecondProviderOwnedCapability {
			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'facet-fixture' ), 'Facet fixture', 'https://example.test/', 'Owner' );
			}

			public function providerOwnedValue(): string {
				return 'first';
			}

			public function secondProviderOwnedValue(): string {
				return 'second';
			}
		};
	}
}
