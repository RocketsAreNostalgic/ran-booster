<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused authority fixtures stay beside their tests.

use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\CredentialRequestException;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\WebhookManagement\Display\WebhookHistory;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;
use RAN\Admin\WebhookManagement\Installation\InstallationStore;
use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

final class ManagedPackageWebhookAuthorityResolverTest extends TestCase {

	public function testItReturnsTheOnlyProviderOwnedStableRepositoryIdentity(): void {
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'Owner/Example', 'gh', 'repository-42' ) )
		);

		self::assertSame(
			'repository-42',
			$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' )
		);
	}

	public function testItAcceptsMultiplePackagesForTheSameProviderRepository(): void {
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', 'repository-42' ) ),
			array( AuthorityPackage::make( 'example-theme', 'owner/example', 'gh', 'repository-42' ) )
		);

		self::assertSame(
			'repository-42',
			$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' )
		);
	}

	public function testItRejectsConflictingStableIdentitiesForOneLocator(): void {
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', 'repository-42' ) ),
			array( AuthorityPackage::make( 'example-theme', 'owner/example', 'gh', 'repository-99' ) )
		);

		$this->expectException( CredentialRequestException::class );
		$this->expectExceptionMessage( 'exactly one stable provider identity' );
		$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' );
	}

	public function testItRejectsAMatchingPackageWithoutStableIdentity(): void {
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', null ) )
		);

		$this->expectException( CredentialRequestException::class );
		$this->expectExceptionMessage( 'does not have a stable repository identity' );
		$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' );
	}

	public function testItIgnoresOtherProvidersAndLocatorMismatches(): void {
		$resolver = $this->resolver(
			array(
				AuthorityPackage::make( 'plugin/other-provider.php', 'owner/example', 'bb', 'bb-42' ),
				AuthorityPackage::make( 'plugin/other-repository.php', 'owner/other', 'gh', 'repository-99' ),
			)
		);

		$this->expectException( CredentialRequestException::class );
		$this->expectExceptionMessage( 'exactly one stable provider identity' );
		$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' );
	}

	public function testItReturnsTheCanonicalManagedOwnerCaseInsensitively(): void {
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'ExampleOwner/example', 'gh', 'repository-42' ) )
		);

		self::assertSame( 'ExampleOwner', $resolver->resolveOwner( ProviderCode::parse( 'gh' ), 'exampleowner' ) );
	}

	public function testItRejectsOwnersWithoutAManagedRepository(): void {
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', 'repository-42' ) )
		);

		$this->expectException( CredentialRequestException::class );
		$this->expectExceptionMessage( 'owner from the managed repositories' );
		$resolver->resolveOwner( ProviderCode::parse( 'gh' ), 'other-owner' );
	}

	public function testItExcludesReleaseManagedPackagesFromRepositoryAndOwnerWebhookAuthority(): void {
		$package = AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', 'repository-42' );
		$package->setSource( PackageSource::RELEASE_ASSET, 2 );
		$resolver = $this->resolver( array( $package ) );

		foreach ( array( 'repository', 'owner' ) as $scope ) {
			try {
				if ( 'repository' === $scope ) {
					$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' );
				} else {
					$resolver->resolveOwner( ProviderCode::parse( 'gh' ), 'owner' );
				}
				self::fail( 'Release-managed packages must not establish branch webhook authority.' );
			} catch ( CredentialRequestException ) {
				self::assertTrue( true );
			}
		}
	}

	public function testExactPluginAndThemeHistoryReadsUseOnlyTheirExactRepositoryLookups(): void {
		$plugin  = AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', 'repository-42' );
		$theme   = AuthorityPackage::make( 'example-theme', 'owner/theme', 'gh', 'repository-43' );
		$history = new WebhookHistory(
			new ManagedPackageWebhookAuthorityResolver(
				new ExactAuthorityPluginRepository( array( 'plugin/example.php' => $plugin ) ),
				new ExactAuthorityThemeRepository( array( 'example-theme' => $theme ) )
			),
			new AuthorityInstallationStore(
				array(
					'repository-42' => $this->record( 'repository-42' ),
					'repository-43' => $this->record( 'repository-43' ),
				)
			)
		);

		self::assertSame(
			array(
				'provider_code'           => 'gh',
				'repository_id'           => 'repository-42',
				'recorded_status'         => 'needs_verification',
				'checked_at'              => '2026-08-20T01:02:03Z',
				'current_local_condition' => null,
				'historical_not_live'     => true,
			),
			$history->forPackage( 'plugin', 'plugin/example.php' )?->toArray()
		);
		self::assertSame( 'repository-43', $history->forPackage( 'theme', 'example-theme' )?->toArray()['repository_id'] );
		self::assertNull( $history->forPackage( 'plugin', 'missing/plugin.php' ) );
		self::assertNull( $history->forPackage( 'other', 'example-theme' ) );
	}

	private function record( string $repositoryId ): InstallationRecord {
		return new InstallationRecord( 'gh', $repositoryId, 'owner/example', '77', 'wh_0123456789abcdef01234567', 'repository', 1, 'created', 'https://hooks.example.test/webhook', 'needs_verification', '2026-08-20T01:02:03Z', '2026-08-20T01:02:03Z' );
	}

	/**
	 * @param list<AuthorityPackage> $plugins
	 * @param list<AuthorityPackage> $themes
	 */
	private function resolver( array $plugins, array $themes = array() ): ManagedPackageWebhookAuthorityResolver {
		return new ManagedPackageWebhookAuthorityResolver(
			new AuthorityPluginRepository( $plugins ),
			new AuthorityThemeRepository( $themes )
		);
	}
}

final class AuthorityPackage extends AbstractPackage {

	private function __construct( private readonly string $identifier, private readonly ?string $authorityId ) {
	}

	public static function make(
		string $identifier,
		string $locator,
		string $provider,
		?string $authorityId
	): self {
		$package = new self( $identifier, $authorityId );
		$package->setRepository( new ManagedRepository( $provider, $locator, $authorityId ?? 'missing-for-test', 'main' ) );

		return $package;
	}

	public function getProviderRepositoryId(): ?string {
		return $this->authorityId;
	}

	public function getIdentifier(): mixed {
		return $this->identifier;
	}
}

final class AuthorityPluginRepository extends PluginRepository {

	/** @param list<AuthorityPackage> $packages */
	public function __construct( private readonly array $packages ) {
	}

	public function allDeploymentPlugins( ?\RAN\PackageSource $source = null ): array {
		return $this->packages;
	}
}

final class AuthorityThemeRepository extends ThemeRepository {

	/** @param list<AuthorityPackage> $packages */
	public function __construct( private readonly array $packages ) {
	}

	public function allDeploymentThemes( ?\RAN\PackageSource $source = null ): array {
		return $this->packages;
	}
}

final class ExactAuthorityPluginRepository extends PluginRepository {
	/** @param array<string, AuthorityPackage> $packages */
	public function __construct( private readonly array $packages ) {}

	public function boosterPluginFromFile( $file ): AuthorityPackage {
		if ( ! is_string( $file ) || ! isset( $this->packages[ $file ] ) ) {
			throw new \RuntimeException( 'Exact plugin lookup did not match.' );
		}

		return $this->packages[ $file ];
	}

	public function allDeploymentPlugins( ?\RAN\PackageSource $source = null ): array {
		throw new \LogicException( 'History must not scan plugin collections.' );
	}
}

final class ExactAuthorityThemeRepository extends ThemeRepository {
	/** @param array<string, AuthorityPackage> $packages */
	public function __construct( private readonly array $packages ) {}

	public function boosterThemeFromStylesheet( $stylesheet ): AuthorityPackage {
		if ( ! is_string( $stylesheet ) || ! isset( $this->packages[ $stylesheet ] ) ) {
			throw new \RuntimeException( 'Exact theme lookup did not match.' );
		}

		return $this->packages[ $stylesheet ];
	}

	public function allDeploymentThemes( ?\RAN\PackageSource $source = null ): array {
		throw new \LogicException( 'History must not scan theme collections.' );
	}
}

final class AuthorityInstallationStore implements InstallationStore {
	/** @param array<string, InstallationRecord> $records */
	public function __construct( private readonly array $records ) {}

	public function all(): array {
		throw new \LogicException( 'Exact history reads must not scan installation records.' );
	}

	public function find( string $providerCode, string $repositoryId ): ?InstallationRecord {
		return 'gh' === $providerCode ? ( $this->records[ $repositoryId ] ?? null ) : null;
	}

	public function saveIfCurrent( InstallationRecord $record, ?InstallationRecord $expected ): string {
		throw new \LogicException( 'History is read-only.' );
	}

	public function deleteIfCurrent( string $providerCode, string $repositoryId, ?InstallationRecord $expected ): string {
		throw new \LogicException( 'History is read-only.' );
	}
}

final readonly class AuthorityWebhookPolicy implements ProviderWebhookPolicy {

	public function getProvider(): ProviderCode {
		return ProviderCode::parse( 'gh' );
	}

	public function getRetainedHeaders(): array {
		return array( 'x-signature' );
	}

	public function getSignatureHeader(): string {
		return 'x-signature';
	}

	public function normalizeWebhook( array $metadata, mixed $secret ): array {
		throw new \LogicException( 'Webhook normalization is not used by this test.' );
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
		return 0 === strcasecmp( trim( $target, '/' ), trim( $repositoryLocator, '/' ) );
	}
}
