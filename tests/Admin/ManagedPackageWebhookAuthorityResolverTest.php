<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

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

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';

final class ManagedPackageWebhookAuthorityResolverTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_repository_admin_translations'] );
	}

	public function test_it_returns_the_only_provider_owned_stable_repository_identity(): void {
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'Owner/Example', 'gh', 'repository-42' ) )
		);

		self::assertSame(
			'repository-42',
			$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' )
		);
	}

	public function test_it_accepts_multiple_packages_for_the_same_provider_repository(): void {
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', 'repository-42' ) ),
			array( AuthorityPackage::make( 'example-theme', 'owner/example', 'gh', 'repository-42' ) )
		);

		self::assertSame(
			'repository-42',
			$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' )
		);
	}

	public function test_it_rejects_conflicting_stable_identities_for_one_locator(): void {
		$GLOBALS['ran_booster_repository_admin_translations']['ran-booster']['Choose a managed repository with exactly one stable provider identity before creating a repository-scoped webhook secret.'] = 'Identité unique traduite.';
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', 'repository-42' ) ),
			array( AuthorityPackage::make( 'example-theme', 'owner/example', 'gh', 'repository-99' ) )
		);

		$this->expectException( CredentialRequestException::class );
		$this->expectExceptionMessage( 'Identité unique traduite.' );
		$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' );
	}

	public function test_it_rejects_amatching_package_without_stable_identity(): void {
		$GLOBALS['ran_booster_repository_admin_translations']['ran-booster']['This managed package does not have a stable repository identity. Re-save its repository settings before creating a repository-scoped webhook secret.'] = 'Identité traduite.';
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', null ) )
		);

		$this->expectException( CredentialRequestException::class );
		$this->expectExceptionMessage( 'Identité traduite.' );
		$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' );
	}

	public function test_it_ignores_other_providers_and_locator_mismatches(): void {
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

	public function test_it_returns_the_canonical_managed_owner_case_insensitively(): void {
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'ExampleOwner/example', 'gh', 'repository-42' ) )
		);

		self::assertSame( 'ExampleOwner', $resolver->resolve_owner( ProviderCode::parse( 'gh' ), 'exampleowner' ) );
	}

	public function test_it_rejects_owners_without_amanaged_repository(): void {
		$GLOBALS['ran_booster_repository_admin_translations']['ran-booster']['Choose an account owner from the managed repositories before creating an owner-scoped webhook secret.'] = 'Propriétaire traduit.';
		$resolver = $this->resolver(
			array( AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', 'repository-42' ) )
		);

		$this->expectException( CredentialRequestException::class );
		$this->expectExceptionMessage( 'Propriétaire traduit.' );
		$resolver->resolve_owner( ProviderCode::parse( 'gh' ), 'other-owner' );
	}

	public function test_it_excludes_release_managed_packages_from_repository_and_owner_webhook_authority(): void {
		$package = AuthorityPackage::make( 'plugin/example.php', 'owner/example', 'gh', 'repository-42' );
		$package->set_source( PackageSource::RELEASE_ASSET, 2 );
		$resolver = $this->resolver( array( $package ) );

		foreach ( array( 'repository', 'owner' ) as $scope ) {
			try {
				if ( 'repository' === $scope ) {
					$resolver->resolve( ProviderCode::parse( 'gh' ), new AuthorityWebhookPolicy(), 'owner/example' );
				} else {
					$resolver->resolve_owner( ProviderCode::parse( 'gh' ), 'owner' );
				}
				self::fail( 'Release-managed packages must not establish branch webhook authority.' );
			} catch ( CredentialRequestException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_exact_plugin_and_theme_history_reads_use_only_their_exact_repository_lookups(): void {
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
			$history->for_package( 'plugin', 'plugin/example.php' )?->to_array()
		);
		self::assertSame( 'repository-43', $history->for_package( 'theme', 'example-theme' )?->to_array()['repository_id'] );
		self::assertNull( $history->for_package( 'plugin', 'missing/plugin.php' ) );
		self::assertNull( $history->for_package( 'other', 'example-theme' ) );
	}

	private function record( string $repository_id ): InstallationRecord {
		return new InstallationRecord( 'gh', $repository_id, 'owner/example', '77', 'credential_1', 'wh_0123456789abcdef01234567', 'repository', 1, 'created', 'https://hooks.example.test/webhook', 'needs_verification', '2026-08-20T01:02:03Z', '2026-08-20T01:02:03Z' );
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

	private function __construct( private readonly string $identifier, private readonly ?string $authority_id ) {
	}

	public static function make(
		string $identifier,
		string $locator,
		string $provider,
		?string $authority_id
	): self {
		$package = new self( $identifier, $authority_id );
		$package->set_repository( new ManagedRepository( $provider, $locator, $authority_id ?? 'missing-for-test', 'main' ) );

		return $package;
	}

	public function get_provider_repository_id(): ?string {
		return $this->authority_id;
	}

	public function get_identifier(): mixed {
		return $this->identifier;
	}
}

final class AuthorityPluginRepository extends PluginRepository {

	/** @param list<AuthorityPackage> $packages */
	public function __construct( private readonly array $packages ) {
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_plugins retains the production method contract; these inputs do not affect this controlled result.
	public function all_deployment_plugins( ?\RAN\PackageSource $source = null ): array {
		return $this->packages;
	}
}

final class AuthorityThemeRepository extends ThemeRepository {

	/** @param list<AuthorityPackage> $packages */
	public function __construct( private readonly array $packages ) {
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_themes retains the production method contract; these inputs do not affect this controlled result.
	public function all_deployment_themes( ?\RAN\PackageSource $source = null ): array {
		return $this->packages;
	}
}

final class ExactAuthorityPluginRepository extends PluginRepository {
	/** @param array<string, AuthorityPackage> $packages */
	public function __construct( private readonly array $packages ) {}

	public function booster_plugin_from_file( $file ): AuthorityPackage {
		if ( ! is_string( $file ) || ! isset( $this->packages[ $file ] ) ) {
			throw new \RuntimeException( 'Exact plugin lookup did not match.' );
		}

		return $this->packages[ $file ];
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_plugins retains the production method contract; these inputs do not affect this controlled result.
	public function all_deployment_plugins( ?\RAN\PackageSource $source = null ): array {
		throw new \LogicException( 'History must not scan plugin collections.' );
	}
}

final class ExactAuthorityThemeRepository extends ThemeRepository {
	/** @param array<string, AuthorityPackage> $packages */
	public function __construct( private readonly array $packages ) {}

	public function booster_theme_from_stylesheet( $stylesheet ): AuthorityPackage {
		if ( ! is_string( $stylesheet ) || ! isset( $this->packages[ $stylesheet ] ) ) {
			throw new \RuntimeException( 'Exact theme lookup did not match.' );
		}

		return $this->packages[ $stylesheet ];
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of all_deployment_themes retains the production method contract; these inputs do not affect this controlled result.
	public function all_deployment_themes( ?\RAN\PackageSource $source = null ): array {
		throw new \LogicException( 'History must not scan theme collections.' );
	}
}

final class AuthorityInstallationStore implements InstallationStore {
	/** @param array<string, InstallationRecord> $records */
	public function __construct( private readonly array $records ) {}

	public function all(): array {
		throw new \LogicException( 'Exact history reads must not scan installation records.' );
	}

	public function find( string $provider_code, string $repository_id ): ?InstallationRecord {
		return 'gh' === $provider_code ? ( $this->records[ $repository_id ] ?? null ) : null;
	}

	public function save_if_current( InstallationRecord $record, ?InstallationRecord $expected ): string {
		throw new \LogicException( 'History is read-only.' );
	}

	public function delete_if_current( string $provider_code, string $repository_id, ?InstallationRecord $expected ): string {
		throw new \LogicException( 'History is read-only.' );
	}
}

final readonly class AuthorityWebhookPolicy implements ProviderWebhookPolicy {

	public function get_provider(): ProviderCode {
		return ProviderCode::parse( 'gh' );
	}

	public function get_retained_headers(): array {
		return array( 'x-signature' );
	}

	public function get_signature_header(): string {
		return 'x-signature';
	}

	public function normalize_webhook( array $metadata, mixed $secret ): array {
		throw new \LogicException( 'Webhook normalization is not used by this test.' );
	}

	public function get_constant_names(): array {
		return array();
	}

	public function webhook_from_constants( array $constants ): ?array {
		return null;
	}

	public function authorize_webhook(
		SignedWebhookVerification $verification,
		string $repository_authority_id,
		string $repository
	): bool {
		return false;
	}

	public function repository_target_matches( string $target, string $repository_locator ): bool {
		return 0 === strcasecmp( trim( $target, '/' ), trim( $repository_locator, '/' ) );
	}
}
