<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

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
use RAN\Tests\RepositoryProvider\Support\ProviderOwnedCapability;
use RAN\Tests\RepositoryProvider\Support\SecondProviderOwnedCapability;

final class ProviderCapabilityContractTest extends TestCase {

	public function test_capability_host_exposes_no_enumeration_or_descriptor_surface(): void {
		$marker   = new ReflectionClass( ProviderCapability::class );
		$registry = new ReflectionClass( ProviderRegistry::class );

		self::assertSame( array(), $marker->getMethods() );
		self::assertFalse( $registry->hasMethod( 'capabilities' ) );
		self::assertFalse( $registry->hasMethod( 'supportsCapability' ) );
		self::assertFalse( $registry->hasMethod( 'capabilityDescriptors' ) );
		self::assertFalse( $registry->hasMethod( 'describeCapabilities' ) );
		self::assertFalse( $registry->hasMethod( 'dispatchCapability' ) );
		self::assertFalse( $registry->hasMethod( 'executeCapability' ) );
	}

	public function test_provider_owned_facets_resolve_to_the_same_registered_aggregate(): void {
		$provider = $this->provider();
		$registry = new ProviderRegistry( array( $provider ) );

		$first  = $registry->require_capability( 'facet-fixture', ProviderOwnedCapability::class );
		$second = $registry->require_capability( 'facet-fixture', SecondProviderOwnedCapability::class );

		self::assertSame( $provider, $first );
		self::assertSame( $provider, $second );
		self::assertSame( 'first', $first->provider_owned_value() );
		self::assertSame( 'second', $second->second_provider_owned_value() );
	}

	/** @return iterable<string, array{class-string|string}> */
	public static function unknown_contracts(): iterable {
		yield 'bare capability marker' => array( ProviderCapability::class );
		yield 'loaded non-marker interface' => array( Stringable::class );
		yield 'base provider contract' => array( RepositoryProvider::class );
		yield 'concrete class' => array( ProviderMetadata::class );
		yield 'unloaded symbol' => array( __NAMESPACE__ . '\\MissingProviderCapability' );
	}

	#[DataProvider( 'unknown_contracts' )]
	public function test_unknown_contracts_fail_without_changing_the_registry( string $capability ): void {
		$provider = $this->provider();
		$registry = new ProviderRegistry( array( $provider ) );

		try {
			$registry->require_capability( 'facet-fixture', $capability );
			self::fail( 'An unknown capability contract must be rejected.' );
		} catch ( UnsupportedProviderCapability $exception ) {
			self::assertSame( 'Unknown repository provider capability.', $exception->getMessage() );
			self::assertSame( $provider, $registry->get( 'facet-fixture' ) );
			self::assertSame( array( 'facet-fixture' => $provider ), $registry->all() );
		}
	}

	public function test_valid_absent_facet_fails_unsupported_without_changing_the_registry(): void {
		$provider = $this->provider();
		$registry = new ProviderRegistry( array( $provider ) );

		try {
			$registry->require_capability( 'facet-fixture', RepositoryWebhookFitness::class );
			self::fail( 'A valid capability absent from the provider must be rejected.' );
		} catch ( UnsupportedProviderCapability $exception ) {
			self::assertSame( 'Repository provider does not support the requested capability.', $exception->getMessage() );
			self::assertSame( $provider, $registry->get( 'facet-fixture' ) );
			self::assertSame( array( 'facet-fixture' => $provider ), $registry->all() );
		}
	}

	private function provider(): RepositoryProvider&ProviderOwnedCapability&SecondProviderOwnedCapability {
		return new class() implements RepositoryProvider, ProviderOwnedCapability, SecondProviderOwnedCapability {
			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'facet-fixture' ), 'Facet fixture', 'https://example.test/', 'Owner' );
			}

			public function provider_owned_value(): string {
				return 'first';
			}

			public function second_provider_owned_value(): string {
				return 'second';
			}
		};
	}
}
