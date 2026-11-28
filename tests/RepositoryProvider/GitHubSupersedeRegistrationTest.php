<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;

require_once __DIR__ . '/GitHubSupersedeWordPressFunctions.php';

/** A plugin supersedes the bundled GitHub module for the request; a failed or conflicting claim leaves GitHub unavailable. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GitHubSupersedeRegistrationTest extends TestCase {
	private int $bundled_calls = 0;

	private bool $bundled_fails = false;

	public function test_without_a_claim_core_registers_the_bundled_module_at_seal(): void {
		$registry = $this->registry();
		self::assertSame( 0, $this->bundled_calls );
		self::assertNull( $registry->github_supplier() );
		$registry->seal();
		self::assertSame( 1, $this->bundled_calls );
		self::assertSame( ProviderRegistry::GITHUB_BUNDLED, $registry->github_supplier() );
		self::assertSame( array(), $registry->github_claimants() );
		self::assertArrayHasKey( 'gh', $registry->metadata() );
	}

	public function test_a_plugin_claim_registers_in_place_of_the_bundled_module(): void {
		$provider                                  = $this->provider();
		$GLOBALS['ran_booster_supersede_provider'] = $provider;
		$registry                                  = $this->registry();
		$registry->supersede_github( self::alpha()['valid'] );
		$registry->seal();
		self::assertSame( 0, $this->bundled_calls );
		self::assertSame( 'alpha', $registry->github_supplier() );
		self::assertSame( array( 'alpha' ), $registry->github_claimants() );
		self::assertSame( $provider, $registry->get( 'gh' ) );
	}

	public function test_a_second_claim_is_a_conflict_and_neither_factory_runs(): void {
		$registry = $this->registry();
		$registry->supersede_github( self::alpha()['valid'] );
		$registry->supersede_github( require dirname( __DIR__ ) . '/fixtures/github-supersede/beta/factory.php' );
		$registry->seal();
		self::assertSame( ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT, $registry->github_supplier() );
		self::assertSame( array( 'alpha', 'beta' ), $registry->github_claimants() );
		$this->assert_unavailable( $registry );
	}

	/** @return iterable<string, array{string, list<string>}> */
	public static function failed_claims(): iterable {
		yield 'a factory defined outside the plugin directories' => array( 'outside', array() );
		yield 'a factory without the Provider API 15 signature' => array( 'legacy', array( 'alpha' ) );
		yield 'a factory that throws' => array( 'throws', array( 'alpha' ) );
		yield 'a provider that is not GitHub' => array( 'foreign', array( 'alpha' ) );
	}

	/** @param list<string> $claimants */
	#[DataProvider( 'failed_claims' )]
	public function test_a_failed_claim_leaves_github_unavailable_without_the_bundled_module( string $claim, array $claimants ): void {
		$GLOBALS['ran_booster_supersede_provider'] = match ( $claim ) {
			'throws' => null,
			'foreign' => $this->provider( 'https://gitlab.com/' ),
			default => $this->provider(),
		};
		$registry = $this->registry();
		$registry->supersede_github(
			match ( $claim ) {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The unattributed claim keeps the Provider API 15 factory signature so only attribution can fail; it must never run.
				'outside' => static fn ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $evidence, ProviderRegistrationContext $context ): never => throw new \LogicException( 'An unattributed claim must never run.' ),
				'legacy' => self::alpha()['legacy'],
				default => self::alpha()['valid'],
			}
		);
		$registry->seal();
		self::assertSame( ProviderRegistry::GITHUB_SUPERSEDE_FAILED, $registry->github_supplier() );
		self::assertSame( $claimants, $registry->github_claimants() );
		$this->assert_unavailable( $registry );
	}

	public function test_gh_is_registered_only_through_the_seam(): void {
		$registry = $this->registry();
		try {
			$registry->register_with_credential_store( 'gh', self::alpha()['valid'] );
			self::fail( 'A direct gh registration was accepted.' );
		} catch ( \LogicException $failure ) {
			self::assertSame( 'GitHub is registered by Core.', $failure->getMessage() );
		}
		$registry->seal();
		$this->expectException( \LogicException::class );
		$registry->supersede_github( self::alpha()['valid'] );
	}

	public function test_one_plugin_claiming_twice_is_a_conflict_that_names_it_once(): void {
		$registry = $this->registry();
		$registry->supersede_github( self::alpha()['valid'] );
		$registry->supersede_github( self::alpha()['valid'] );
		$registry->seal();
		self::assertSame( ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT, $registry->github_supplier() );
		self::assertSame( array( 'alpha' ), $registry->github_claimants() );
		$this->assert_unavailable( $registry );
	}

	public function test_a_bundled_module_that_fails_to_build_does_not_read_as_bundled(): void {
		$this->bundled_fails = true;
		$registry            = $this->registry();
		$registry->seal();
		self::assertSame( 1, $this->bundled_calls );
		self::assertNull( $registry->github_supplier() );
		self::assertArrayNotHasKey( 'gh', $registry->metadata() );
	}

	private function assert_unavailable( ProviderRegistry $registry ): void {
		self::assertSame( 0, $this->bundled_calls );
		self::assertArrayNotHasKey( 'gh', $registry->metadata() );
	}

	/** @return array{valid: \Closure, legacy: \Closure} */
	private static function alpha(): array {
		return require dirname( __DIR__ ) . '/fixtures/github-supersede/alpha/factory.php';
	}

	private function registry(): ProviderRegistry {
		$credentials = $this->createStub( ProviderCredentialStore::class );
		$evidence    = $this->createStub( AuthenticatedWebhookDeliveryEvidenceReader::class );
		$bundled     = $this->provider();
		return new ProviderRegistry(
			credential_store_factory: static fn (): ProviderCredentialStore => $credentials,
			delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => $evidence,
			bundled_github_factory: function ( ProviderCredentialStore $store, AuthenticatedWebhookDeliveryEvidenceReader $reader, ProviderRegistrationContext $context ) use ( $bundled ): RepositoryProvider {
				unset( $store, $reader, $context );
				++$this->bundled_calls;
				if ( $this->bundled_fails ) {
					throw new \RuntimeException( 'The bundled module failed to build.' );
				}
				return $bundled;
			}
		);
	}

	private function provider( string $url_base = 'https://github.com/' ): RepositoryProvider {
		$provider = $this->createStub( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', $url_base, 'Owner' ) );
		$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createStub( ProviderDiagnostics::class ) );
		return $provider;
	}
}
