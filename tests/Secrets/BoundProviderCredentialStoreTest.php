<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\InvalidProviderPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Secrets\SecretsFile;
use RuntimeException;

/** A provider's bound store hands over credential material only under that provider's registered policy (#1348). */
final class BoundProviderCredentialStoreTest extends TestCase {
	private const METADATA = array(
		'label'         => 'Stored credential',
		'kind'          => 'token',
		'configuration' => array(),
	);

	private string $directory;

	private ProviderSecretPolicyCatalog $policies;

	private SecretsFile $secrets;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-bound-store-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable sidecar directory owned by this fixture.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->policies = new ProviderSecretPolicyCatalog();
		$this->secrets  = SecretsFileTestFactory::create( $this->directory . '/secrets.json', array(), $this->policies );
		// Saved while no policy is registered, as an import or an earlier provider version could leave it.
		$this->secrets->save_credential( 'alpha', 'stored', self::METADATA, 'stored-secret' );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		foreach ( array( '/secrets.json', '/secrets.json.lock' ) as $file ) {
			if ( is_file( $this->directory . $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable files owned by this fixture.
				unlink( $this->directory . $file );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the disposable directory owned by this fixture.
		rmdir( $this->directory );
	}

	public function test_a_code_without_a_credential_policy_gets_no_stored_or_temporary_material(): void {
		$store = $this->secrets->credentials_for( 'alpha' );
		self::assertTrue( self::refused( static fn (): ?array => $store->credential_material( 'stored' ) ) );
		self::assertTrue(
			$this->secrets->with_temporary_credential(
				'alpha',
				self::METADATA,
				'temporary-secret',
				static fn ( string $id ): bool => self::refused( static fn (): ?array => $store->credential_material( $id ) )
			)
		);
		// Core's own reads stay outside this boundary, so an unavailable provider's credential can still be exported.
		self::assertSame( 'stored-secret', $this->secrets->credential_material( 'alpha', 'stored' )['secret'] ?? null );
	}

	public function test_a_registered_policy_releases_material_it_has_normalized(): void {
		$this->policies->register( ProviderCode::parse( 'alpha' ), self::policy(), null );
		$material = $this->secrets->credentials_for( 'alpha' )->credential_material( 'stored' );
		self::assertSame( 'stored-secret', $material['secret'] ?? null );
		self::assertSame( 'alpha', $material['provider'] ?? null );
	}

	public function test_a_store_captured_by_a_refused_registration_stays_refused(): void {
		$captured = null;
		$evidence = $this->createStub( AuthenticatedWebhookDeliveryEvidenceReader::class );
		$provider = $this->createStub( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'beta' ), 'Beta', 'https://beta.example/', 'Owner' ) );
		$registry = new ProviderRegistry(
			array(),
			$this->policies,
			fn ( ProviderCode $code ): ProviderCredentialStore => $this->secrets->credentials_for( $code ),
			static fn (): AuthenticatedWebhookDeliveryEvidenceReader => $evidence
		);
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The factory keeps the exact Provider API signature the registry checks; only the store is captured.
		$factory = static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $reader, ProviderRegistrationContext $context ) use ( &$captured, $provider ): RepositoryProvider {
			$captured = $credentials;
			return $provider;
		};
		// The returned provider's code does not match the claimed code, so registration is refused.
		self::assertTrue( self::refused( static fn () => $registry->register_with_credential_store( 'alpha', $factory ), InvalidProviderPolicy::class ) );
		self::assertInstanceOf( ProviderCredentialStore::class, $captured );
		self::assertTrue( self::refused( static fn (): ?array => $captured->credential_material( 'stored' ) ) );
	}

	/**
	 * @param callable(): mixed                $read
	 * @param class-string<RuntimeException> $expected
	 */
	private static function refused( callable $read, string $expected = RuntimeException::class ): bool {
		try {
			$read();
		} catch ( RuntimeException $failure ) {
			return $failure instanceof $expected;
		}
		return false;
	}

	private static function policy(): ProviderCredentialPolicy {
		return new class() implements ProviderCredentialPolicy {
			public function get_provider(): ProviderCode {
				return ProviderCode::parse( 'alpha' );
			}

			public function normalize_credential( array $metadata, mixed $secret ): array {
				return array(
					'label'         => $metadata['label'],
					'kind'          => $metadata['kind'],
					'configuration' => $metadata['configuration'],
					'secret'        => $secret,
				);
			}

			public function get_constant_names(): array {
				return array();
			}

			public function credential_from_constants( array $constants ): ?array {
				unset( $constants );
				return null;
			}
		};
	}
}
