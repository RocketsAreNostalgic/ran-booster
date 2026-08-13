<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';

// Direct local filesystem operations verify the provider-owned sidecar contract.
// phpcs:disable WordPress.WP.AlternativeFunctions, Generic.Files.OneObjectStructurePerFile.MultipleFound

use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Deployment\DeploymentPolicy;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\Admin\CredentialKindMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;
use RAN\RepositoryProvider\InvalidProviderCode;
use RAN\RepositoryProvider\InvalidProviderPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticBudgetExceeded;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use Tests\Secrets\SecretsFileTestFactory;
use Tests\Support\CredentialUsageDatabase;
use Tests\RepositoryProvider\Support\ExternalFixtureProvider;
use Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;

final class ProviderDiagnosticsContractTest extends TestCase {

	public function testNovelProviderSuppliesDiagnosticsAndSupportsTheManualPackagePath(): void {
		$path           = sys_get_temp_dir() . '/ran-booster-fixture-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$secretPolicies = new ProviderSecretPolicyCatalog();
		$secrets        = SecretsFileTestFactory::create( $path, array(), $secretPolicies );
		$provider       = null;
		$registry       = new ProviderRegistry(
			array(),
			$secretPolicies,
			static fn ( ProviderCode $code ): ProviderCredentialStore => $secrets->credentialsFor( $code ),
			static fn (): AuthenticatedWebhookDeliveryEvidenceReader => new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);

		$registry->registerWithCredentialStore(
			'fixture',
			static function ( ProviderCredentialStore $credentials ) use ( &$provider ): ExternalFixtureProvider {
				$provider = new ExternalFixtureProvider( 'fixture', $credentials );

				return $provider;
			}
		);
		self::assertInstanceOf( ExternalFixtureProvider::class, $provider );
		self::assertSame( 0, $provider->getClient()->getRequests(), 'Registration must not run provider diagnostics or call the provider client.' );
		$registry->seal();
		$credentialId = $secrets->saveCredential(
			'fixture',
			'fixture_primary',
			array(
				'label'         => 'Fixture primary',
				'kind'          => 'api-key',
				'configuration' => array( 'tenant' => 'ran-lab' ),
			),
			'fixture-secret-canary'
		);

		self::assertSame( $provider, $registry->get( 'fixture' ) );
		self::assertTrue( $registry->isSealed() );
		self::assertTrue( $provider->validateCredential( $credentialId )->isValid() );
		self::assertSame( 'ran-lab', $secrets->credentialProfiles( 'fixture' )[ $credentialId ]['configuration']['tenant'] );

		$resolved = ( new PackageRepositoryRequestResolver( $registry ) )->resolve(
			array(
				'provider'      => 'fixture',
				'repository'    => 'group/subgroup/package',
				'branch'        => '',
				'credential_id' => $credentialId,
			)
		);

		self::assertSame( 'fixture', $resolved['provider'] );
		self::assertSame( 'group/subgroup/package', $resolved['repository'] );
		self::assertSame( 'package', $resolved['package_slug'] );
		self::assertSame( 'fixture:group/subgroup/package', $resolved['provider_repository_id'] );
		self::assertSame( 'main', $resolved['branch'] );

		$packageSettings = ( new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader( new CredentialUsageDatabase(), 'wp_ran_booster_packages' ) ) )
			->buildPackageForm( 'fixture' );
		self::assertSame( 'fixture', $packageSettings['default_provider'] );
		self::assertSame( 'fixture', $packageSettings['providers'][0]['code'] );
		self::assertTrue( $packageSettings['providers'][0]['deploy'] );
		self::assertFalse( $packageSettings['providers'][0]['webhooks'] );
		self::assertSame(
			'admin.php?page=ran-booster&tab=fixture&view=credentials',
			$packageSettings['providers'][0]['credentials_url']
		);

		$reference = new RepositoryReference(
			$resolved['repository'],
			$resolved['provider_repository_id'],
			false,
			null
		);
		$archive   = $provider->prepareArchive( new ArchiveRequest( $reference, 'main' ) );

		$resolvedRef = sha1( "group/subgroup/package\0main" );
		self::assertSame( $resolvedRef, $archive->getResolvedRef() );
		self::assertSame( 'https://fixtures.example.test/group/subgroup/package/' . $resolvedRef . '.zip', $archive->getUrl() );
		self::assertNotInstanceOf( WebhookNormalizer::class, $provider );

		if ( is_file( $path ) ) {
			unlink( $path );
		}
		if ( is_file( $path . '.lock' ) ) {
			unlink( $path . '.lock' );
		}
	}

	public function testFixtureProviderDiagnosticsUseTheSameProviderClient(): void {
		$provider    = new ExternalFixtureProvider();
		$diagnostics = $provider->getProviderDiagnostics();
		$results     = $diagnostics->diagnose(
			new ProviderDiagnosticRequest( null, 'example/package' )
		);

		self::assertCount( 3, $results );
		self::assertSame( 'fixture.environment.ready', $results[0]->code );
		self::assertSame( 'fixture.credential.public', $results[1]->code );
		self::assertSame( 'fixture.repository.reachable', $results[2]->code );
		self::assertSame( 2, $provider->getClient()->getRequests() );
	}

	public function testMissingDiagnosticsAreRejectedBeforeRegistryMutation(): void {
		$provider = new class() {
			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					ProviderCode::parse( 'missing-diagnostics' ),
					'Missing diagnostics',
					'https://example.test/',
					'Owner'
				);
			}
		};
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( array(), $catalog );

		try {
			// @phpstan-ignore-next-line Deliberately prove the typed public boundary.
			$registry->register( $provider );
			self::fail( 'A provider without diagnostics must be rejected.' );
		} catch ( \TypeError ) {
			self::assertSame( array(), $registry->all() );
		}

		$valid = new ExternalFixtureProvider( 'missing-diagnostics' );
		$registry->register( $valid );
		self::assertSame( $valid, $registry->get( 'missing-diagnostics' ) );
		self::assertSame( 'missing-diagnostics', $catalog->credentialPolicy( 'missing-diagnostics' )->getProvider()->value );
	}

	public function testFailingDiagnosticsSupplierIsSafelyRejectedBeforeMutation(): void {
		$provider = new class() implements RepositoryProvider {
			use \Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'unsafe' ), 'Unsafe', 'https://example.test/', 'Owner' );
			}

			public function getProviderDiagnostics(): \RAN\RepositoryProvider\ProviderDiagnostics {
				throw new \RuntimeException( 'token-bearing-provider-error' );
			}
		};
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = new ProviderRegistry( array(), $catalog );

		try {
			$registry->register( $provider );
			self::fail( 'A failing diagnostics supplier must be rejected.' );
		} catch ( \LogicException $exception ) {
			self::assertNull( $exception->getPrevious() );
			self::assertStringNotContainsString( 'token-bearing', $exception->getMessage() );
			self::assertSame( array(), $registry->all() );
		}

		$valid = new ExternalFixtureProvider( 'unsafe' );
		$registry->register( $valid );
		self::assertSame( $valid, $registry->get( 'unsafe' ) );
		self::assertSame( 'unsafe', $catalog->credentialPolicy( 'unsafe' )->getProvider()->value );
	}

	public function testSelectedProviderDiagnosticsDoNotSweepTheSealedRegistry(): void {
		$selected = new ExternalFixtureProvider( 'selected' );
		$idle     = new ExternalFixtureProvider( 'idle' );
		$registry = new ProviderRegistry( array( $selected, $idle ) );
		$registry->seal();

		$provider = $registry->get( 'selected' );
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		$provider->getProviderDiagnostics()->diagnose( new ProviderDiagnosticRequest( null, 'example/package' ) );

		self::assertSame( 2, $selected->getClient()->getRequests() );
		self::assertSame( 0, $idle->getClient()->getRequests() );
	}

	public function testSealedRegistryRejectsLateRegistration(): void {
		$metadataCalls = 0;
		$provider      = new class( $metadataCalls ) implements RepositoryProvider {
			use \Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

			public function __construct( private int &$metadataCalls ) {
			}

			public function getMetadata(): ProviderMetadata {
				++$this->metadataCalls;

				return new ProviderMetadata( ProviderCode::parse( 'another-fixture' ), 'Another fixture', 'https://example.test/', 'Owner' );
			}

			public function getProviderDiagnostics(): ProviderDiagnostics {
				throw new \LogicException( 'Diagnostics must not be requested after sealing.' );
			}
		};
		$registry      = new ProviderRegistry( array( new ExternalFixtureProvider() ) );
		$registry->seal();

		try {
			$registry->register( $provider );
			self::fail( 'A sealed registry must reject registration.' );
		} catch ( \LogicException $exception ) {
			self::assertSame( 'Repository provider registration is closed.', $exception->getMessage() );
			self::assertSame( 0, $metadataCalls );
		}
	}

	/** @return array<string, array{string, string, class-string<\Throwable>, string}> */
	public static function reentrantRegistrationCallbacks(): array {
		$boundaries = array(
			'credential_store_factory'   => array( InvalidProviderPolicy::class, 'The provider credential-store factory returned an invalid store.' ),
			'delivery_evidence_factory'  => array( InvalidProviderPolicy::class, 'The provider delivery-evidence factory returned an invalid reader.' ),
			'provider_factory'           => array( InvalidProviderPolicy::class, 'The provider factory returned an invalid provider.' ),
			'metadata'                   => array( InvalidProviderPolicy::class, 'Repository provider metadata could not be supplied.' ),
			'diagnostics'                => array( LogicException::class, 'Repository provider diagnostics could not be supplied.' ),
			'credential_policy_getter'   => array( InvalidProviderPolicy::class, 'The provider credential policy is unavailable.' ),
			'webhook_policy_getter'      => array( InvalidProviderPolicy::class, 'The provider webhook policy is unavailable.' ),
			'credential_policy_identity' => array( InvalidProviderPolicy::class, 'The provider credential policy is unavailable.' ),
			'webhook_policy_identity'    => array( InvalidProviderPolicy::class, 'The provider webhook policy is unavailable.' ),
		);
		$cases      = array();

		foreach ( $boundaries as $boundary => $expected ) {
			foreach ( array( 'register', 'register_with_store', 'seal' ) as $operation ) {
				$cases[ $boundary . '/' . $operation ] = array( $boundary, $operation, $expected[0], $expected[1] );
			}
		}

		return $cases;
	}

	/** @param class-string<\Throwable> $expectedException */
	#[DataProvider( 'reentrantRegistrationCallbacks' )]
	public function testEveryRegistrationCallbackRejectsRegistryReentry(
		string $boundary,
		string $operation,
		string $expectedException,
		string $expectedMessage
	): void {
		$catalog  = new ProviderSecretPolicyCatalog();
		$registry = null;
		$callback = static function ( string $currentBoundary ) use ( &$registry, $boundary, $operation ): void {
			if ( $currentBoundary !== $boundary ) {
				return;
			}

			self::assertInstanceOf( ProviderRegistry::class, $registry );

			if ( 'register' === $operation ) {
				$registry->register( new ExternalFixtureProvider( 'nested' ) );
			} elseif ( 'register_with_store' === $operation ) {
				$registry->registerWithCredentialStore(
					'nested',
					static fn ( ProviderCredentialStore $store ): ExternalFixtureProvider => new ExternalFixtureProvider( 'nested', $store )
				);
			} else {
				$registry->seal();
			}
		};
		$registry = new ProviderRegistry(
			array(),
			$catalog,
			static function () use ( $callback ): ProviderCredentialStore {
				$callback( 'credential_store_factory' );

				return new RegistrationGuardCredentialStore();
			},
			static function () use ( $callback ): AuthenticatedWebhookDeliveryEvidenceReader {
				$callback( 'delivery_evidence_factory' );

				return new EmptyAuthenticatedWebhookDeliveryEvidenceReader();
			}
		);

		try {
			$registry->registerWithCredentialStore(
				'outer',
				static function ( ProviderCredentialStore $store ) use ( $callback ): RepositoryProvider {
					$callback( 'provider_factory' );

					return new RegistrationGuardProvider( 'outer', $callback );
				}
			);
			self::fail( 'A provider registration callback must not re-enter or seal the registry.' );
		} catch ( \Throwable $exception ) {
			self::assertInstanceOf( $expectedException, $exception );
			self::assertSame( $expectedMessage, $exception->getMessage() );
			self::assertSame( array(), $registry->all() );
			self::assertFalse( $registry->isSealed() );
			self::assertNull( $catalog->findCredentialPolicy( 'outer' ) );
			self::assertNull( $catalog->findCredentialPolicy( 'nested' ) );
			self::assertNull( $catalog->findWebhookPolicy( 'outer' ) );
			self::assertNull( $catalog->findWebhookPolicy( 'nested' ) );
		}

		$valid = new ExternalFixtureProvider( 'outer' );
		$registry->register( $valid );
		self::assertSame( $valid, $registry->get( 'outer' ) );
	}

	public function testDiagnosticRequestStopsBeforeAFirstCallBeyondItsLimit(): void {
		$now     = 100.0;
		$request = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			5.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);

		for ( $call = 1; $call <= 5; ++$call ) {
			self::assertSame( 5.0, $request->claimRemoteCall() );
		}

		try {
			$request->claimRemoteCall();
			self::fail( 'The sixth remote call must not start.' );
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::REMOTE_CALLS, $exception->getReason() );
			self::assertSame( 5, $request->getRemoteCalls() );
		}
	}

	public function testDiagnosticRequestStopsBeforeACallAfterItsDeadline(): void {
		$now     = 100.0;
		$request = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			5.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);
		$now     = 105.0;

		try {
			$request->claimRemoteCall();
			self::fail( 'A remote call after the deadline must not start.' );
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::DEADLINE, $exception->getReason() );
			self::assertSame( 0, $request->getRemoteCalls() );
		}
	}

	public function testDiagnosticRequestReturnsTheExactRemainingDeadlineForEachClaim(): void {
		$now     = 100.0;
		$request = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			10.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);

		self::assertSame( 10.0, $request->claimRemoteCall() );
		$now = 104.25;
		self::assertSame( 5.75, $request->claimRemoteCall() );
		self::assertSame( 2, $request->getRemoteCalls() );
	}

	public function testDiagnosticResultRejectsAnUnstableCode(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'Fixture Invalid Code',
			'Safe message.',
			'Safe remediation.'
		);
	}

	/** @return list<array{string, string}> */
	public static function unsafeDiagnosticText(): array {
		return array(
			array( 'Authorization: Bearer secret-value', 'Safe remediation.' ),
			array( 'Proxy-Authorization: Basic secret-value', 'Safe remediation.' ),
			array( 'Cookie: session=secret-value', 'Safe remediation.' ),
			array( 'Set-Cookie: session=secret-value', 'Safe remediation.' ),
			array( 'X-Hub-Signature-256: sha256=secret-value', 'Safe remediation.' ),
			array( 'X-API-Key: secret-value', 'Safe remediation.' ),
			array( 'X-Auth-Token: secret-value', 'Safe remediation.' ),
			array( 'Private-Token: secret-value', 'Safe remediation.' ),
			array( 'Bearer abcdefgh12345678', 'Safe remediation.' ),
			array( 'Basic abcdefgh12345678', 'Safe remediation.' ),
			array( 'Token ghp_abcdefgh12345678', 'Safe remediation.' ),
			array( 'Token github_pat_abcdefgh12345678', 'Safe remediation.' ),
			array( 'Token ATATT3abcdefgh12345678', 'Safe remediation.' ),
			array( 'Token glpat-abcdefgh12345678', 'Safe remediation.' ),
			array( '{"error":"unsafe response"}', 'Safe remediation.' ),
			array( '[unsafe response]', 'Safe remediation.' ),
			array( 'File /tmp is unavailable.', 'Safe remediation.' ),
			array( 'File /etc/passwd is unavailable.', 'Safe remediation.' ),
			array( 'File C:\\Users\\name is unavailable.', 'Safe remediation.' ),
			array( 'File \\\\server\\share\\file is unavailable.', 'Safe remediation.' ),
			array( 'See https://user:secret@example.test/path', 'Safe remediation.' ),
			array( 'Safe message.', '<a href="https://example.test/">Unsafe</a>' ),
		);
	}

	#[DataProvider( 'unsafeDiagnosticText' )]
	public function testDiagnosticResultRejectsUnsafeText( string $message, string $remediation ): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'fixture.safe_code',
			$message,
			$remediation
		);
	}

	public function testDiagnosticResultAllowsBenignOperationalTextAndRelativePackageLanguage(): void {
		$result = new ProviderDiagnosticResult(
			ProviderDiagnosticResult::WARNING,
			'fixture.benign',
			'WordPress uses the plugin/theme directory and the provider returned a safe status.',
			'Open provider settings and review repository access.'
		);

		self::assertSame( 'fixture.benign', $result->code );
	}

	public function testDiagnosticRequestAcceptsExactInputBoundaries(): void {
		$request = new ProviderDiagnosticRequest( str_repeat( 'a', 128 ), str_repeat( 'b', 512 ) );

		self::assertSame( 128, strlen( (string) $request->getCredentialId() ) );
		self::assertSame( 512, strlen( (string) $request->getRepository() ) );
	}

	public function testDiagnosticRequestPreservesProviderOwnedRepositoryLocatorBytes(): void {
		$locator = ' group/subgroup/package ';
		$request = new ProviderDiagnosticRequest( null, $locator );

		self::assertSame( $locator, $request->getRepository() );
	}

	/** @return list<array{string|null, string|null}> */
	public static function overlongDiagnosticInputProvider(): array {
		return array(
			array( str_repeat( 'a', 129 ), null ),
			array( null, str_repeat( 'b', 513 ) ),
		);
	}

	#[DataProvider( 'overlongDiagnosticInputProvider' )]
	public function testDiagnosticRequestRejectsInputsAboveExactBoundaries( ?string $credentialId, ?string $repository ): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticRequest( $credentialId, $repository );
	}

	public function testFirstFailedClaimReasonRemainsStickyAcrossLaterFailureKinds(): void {
		$now     = 0.0;
		$request = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			10.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);
		for ( $index = 0; $index < 5; ++$index ) {
			$request->claimRemoteCall();
		}
		try {
			$request->claimRemoteCall();
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::REMOTE_CALLS, $exception->getReason() );
		}
		$now = 11.0;
		try {
			$request->claimRemoteCall();
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::DEADLINE, $exception->getReason() );
		}
		self::assertSame( ProviderDiagnosticBudgetExceeded::REMOTE_CALLS, $request->getExhaustionReason() );

		$now      = 11.0;
		$request2 = new ProviderDiagnosticRequest(
			null,
			null,
			5,
			10.0,
			static function () use ( &$now ): float {
				return $now;
			}
		);
		$now      = 22.0;
		try {
			$request2->claimRemoteCall();
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			self::assertSame( ProviderDiagnosticBudgetExceeded::DEADLINE, $exception->getReason() );
		}
		$now = 11.0;
		for ( $index = 0; $index < 6; ++$index ) {
			try {
				$request2->claimRemoteCall();
			} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
				self::assertContains( $exception->getReason(), array( ProviderDiagnosticBudgetExceeded::REMOTE_CALLS, ProviderDiagnosticBudgetExceeded::DEADLINE ) );
			}
		}
		self::assertSame( ProviderDiagnosticBudgetExceeded::DEADLINE, $request2->getExhaustionReason() );
	}

	/** @return list<array{int, float}> */
	public static function invalidDiagnosticCeilings(): array {
		return array(
			array( 6, 10.0 ),
			array( 5, 10.1 ),
		);
	}

	#[DataProvider( 'invalidDiagnosticCeilings' )]
	public function testDiagnosticRequestRejectsCeilingsAboveTheContract( int $calls, float $seconds ): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticRequest( null, null, $calls, $seconds );
	}

	public function testProviderIdsAreOpenButStrictAndReserveAdminTabs(): void {
		self::assertSame( 'fixture', ProviderCode::parse( 'fixture' )->value );
		self::assertTrue( ProviderCode::parse( 'gh' )->equals( ProviderCode::parse( 'gh' ) ) );
		self::assertNotSame( ProviderCode::parse( 'gh' ), ProviderCode::parse( 'gh' ) );

		foreach ( array( 'Fixture', 'fixture!', 'overview', 'portability', 'documentation', 'troubleshooting' ) as $invalid ) {
			try {
				ProviderCode::parse( $invalid );
				self::fail( 'Expected provider ID rejection.' );
			} catch ( InvalidProviderCode ) {
				self::assertTrue( true );
			}
		}
	}

	public function testFixtureWithoutWebhooksFailsPushToDeployExplicitly(): void {
		$resolver = new PackageRepositoryRequestResolver(
			new ProviderRegistry( array( new ExternalFixtureProvider() ) )
		);

		$this->expectException( UnsupportedProviderCapability::class );

		$resolver->resolve(
			array(
				'provider'          => 'fixture',
				'repository'        => 'example/package',
				'deployment_policy' => DeploymentPolicy::AUTOMATIC->value,
			)
		);
	}

	public function testFixtureWithoutWebhooksCannotContributeWebhookReadiness(): void {
		$registry = new ProviderRegistry( array( new ExternalFixtureProvider() ) );

		$this->expectException( UnsupportedProviderCapability::class );

		$registry->requireCapability( 'fixture', WebhookNormalizer::class );
	}
}

final readonly class RegistrationGuardCredentialStore implements ProviderCredentialStore {

	public function credentialProfiles(): array {
		return array();
	}

	public function credentialMaterial( ?string $id = null ): ?array {
		return null;
	}

	public function hasWebhookProfile(): bool {
		return false;
	}
}

final readonly class RegistrationGuardDiagnostics implements ProviderDiagnostics {

	public function diagnose( ProviderDiagnosticRequest $request ): array {
		return array();
	}
}

final readonly class RegistrationGuardCredentialPolicy implements ProviderCredentialPolicy {

	public function __construct( private ProviderCode $code, private Closure $callback ) {
	}

	public function getProvider(): ProviderCode {
		( $this->callback )( 'credential_policy_identity' );

		return $this->code;
	}

	public function normalizeCredential( array $metadata, mixed $secret ): array {
		throw new LogicException( 'Unused registration-guard test method.' );
	}

	public function getConstantNames(): array {
		return array();
	}

	public function credentialFromConstants( array $constants ): ?array {
		return null;
	}
}

final readonly class RegistrationGuardWebhookPolicy implements ProviderWebhookPolicy {

	public function __construct( private ProviderCode $code, private Closure $callback ) {
	}

	public function getProvider(): ProviderCode {
		( $this->callback )( 'webhook_policy_identity' );

		return $this->code;
	}

	public function getRetainedHeaders(): array {
		return array();
	}

	public function getSignatureHeader(): string {
		return 'x-fixture-signature';
	}

	public function normalizeWebhook( array $metadata, mixed $secret ): array {
		throw new LogicException( 'Unused registration-guard test method.' );
	}

	public function getConstantNames(): array {
		return array();
	}

	public function webhookFromConstants( array $constants ): ?array {
		return null;
	}

	public function authorizeWebhook(
		SignedWebhookVerification $verification,
		string $repositoryAuthorityId,
		string $repository
	): bool {
		return false;
	}

	public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
		return false;
	}
}

final readonly class RegistrationGuardProvider implements RepositoryProvider, ProviderCredentialPolicySupplier, WebhookNormalizer {

	use \Tests\RepositoryProvider\Support\SuppliesProviderManualCapabilities;

	private ProviderCode $code;

	public function __construct( string $code, private Closure $callback ) {
		$this->code = ProviderCode::parse( $code );
	}

	public function getMetadata(): ProviderMetadata {
		( $this->callback )( 'metadata' );

		return new ProviderMetadata(
			$this->code,
			'Registration guard',
			'https://example.test/',
			'Owner',
			new ProviderAdminMetadata(
				array( new CredentialKindMetadata( 'api-key', 'API key', 'API key' ) ),
				array( new WebhookScopeMetadata( 'owner', 'Owner', true, 'Owner' ) )
			)
		);
	}

	public function getProviderDiagnostics(): ProviderDiagnostics {
		( $this->callback )( 'diagnostics' );

		return new RegistrationGuardDiagnostics();
	}

	public function getCredentialPolicy(): ProviderCredentialPolicy {
		( $this->callback )( 'credential_policy_getter' );

		return new RegistrationGuardCredentialPolicy( $this->code, $this->callback );
	}

	public function getWebhookPolicy(): ProviderWebhookPolicy {
		( $this->callback )( 'webhook_policy_getter' );

		return new RegistrationGuardWebhookPolicy( $this->code, $this->callback );
	}

	public function diagnoseWebhookReadiness(): ProviderDiagnosticResult {
		throw new LogicException( 'Unused registration-guard test method.' );
	}

	public function normalizeWebhook( WebhookRequest $request ): WebhookEnvelope {
		return WebhookEnvelope::ignored();
	}
}
