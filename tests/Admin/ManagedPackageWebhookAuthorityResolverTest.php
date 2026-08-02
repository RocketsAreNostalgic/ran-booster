<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused authority fixtures stay beside their tests.

use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\CredentialRequestException;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
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
