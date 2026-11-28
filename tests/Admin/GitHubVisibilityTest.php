<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Admin\GitHubVisibility;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Settings\GeneralPreferenceStore;
use RAN\Tests\Admin\Support\GitHubSupplierState;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GitHubVisibilityTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle signature.
	protected function setUp(): void {
		require __DIR__ . '/GitHubVisibilityWordPressFunctions.php';
		require __DIR__ . '/GitHubVisibilityConnection.php';
	}

	public function test_missing_local_admission_sources_fail_visible(): void {
		$visibility = new GitHubVisibility( new GeneralPreferenceStore(), connection: new GitHubVisibilityConnection() );

		self::assertSame(
			array(
				'hidden'   => false,
				'eligible' => false,
				'reason'   => 'provider_registry_unknown',
			),
			$visibility->projection()
		);
	}

	public function test_only_the_bundled_supplier_can_be_hidden(): void {
		$visibility = static function ( string $supplier ): GitHubVisibility {
			$providers = GitHubSupplierState::apply( new ProviderRegistry( array( new ExternalFixtureProvider( 'gh' ), new ExternalFixtureProvider( 'bb' ) ) ), $supplier, array( 'alpha' ) );
			$providers->seal();
			return new GitHubVisibility( new GeneralPreferenceStore(), $providers, new GitHubVisibilityConnection() );
		};

		self::assertSame(
			array(
				'hidden'   => false,
				'eligible' => true,
				'reason'   => 'not_requested',
			),
			$visibility( ProviderRegistry::GITHUB_BUNDLED )->projection()
		);
		foreach ( array( 'alpha', ProviderRegistry::GITHUB_SUPERSEDE_FAILED, ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT ) as $supplier ) {
			self::assertSame(
				array(
					'hidden'   => false,
					'eligible' => false,
					'reason'   => 'active_github_replacement',
				),
				$visibility( $supplier )->projection(),
				$supplier
			);
		}
	}

	public function test_github_stays_visible_when_its_bundled_module_failed_to_build(): void {
		$credentials = $this->createStub( ProviderCredentialStore::class );
		$evidence    = $this->createStub( AuthenticatedWebhookDeliveryEvidenceReader::class );
		$providers   = new ProviderRegistry(
			array( new ExternalFixtureProvider( 'bb' ) ),
			credential_store_factory: static fn (): ProviderCredentialStore => $credentials,
			delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => $evidence,
			bundled_github_factory: static function ( ProviderCredentialStore $store, AuthenticatedWebhookDeliveryEvidenceReader $reader, ProviderRegistrationContext $context ): RepositoryProvider {
				unset( $store, $reader, $context );
				throw new RuntimeException( 'The bundled module failed to build.' );
			}
		);
		$providers->seal();

		self::assertNull( $providers->github_supplier() );
		self::assertSame(
			array(
				'hidden'   => false,
				'eligible' => false,
				'reason'   => 'provider_registry_unknown',
			),
			( new GitHubVisibility( new GeneralPreferenceStore(), $providers, new GitHubVisibilityConnection() ) )->projection()
		);
	}

	public function test_management_admission_locks_the_exact_github_provider_range_before_save_work(): void {
		$connection = new GitHubVisibilityConnection();
		$visibility = new GitHubVisibility( new GeneralPreferenceStore(), connection: $connection );
		$result     = $visibility->with_management_admission_lock( static fn (): string => 'saved' );

		self::assertSame( 'saved', $result );
		self::assertSame( array( "SELECT id FROM `wp_ran_booster_packages` WHERE provider = 'gh' FOR UPDATE" ), $connection->queries );
	}

	public function test_management_admission_refuses_unknown_inventory_before_save_work(): void {
		$connection            = new GitHubVisibilityConnection();
		$connection->fail_read = true;
		$visibility            = new GitHubVisibility( new GeneralPreferenceStore(), connection: $connection );
		$called                = false;

		$this->expectException( RuntimeException::class );
		try {
			$visibility->with_management_admission_lock(
				static function () use ( &$called ): void {
					$called = true;
				}
			);
		} finally {
			self::assertFalse( $called );
		}
	}
}
