<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Private registration spies belong with this host-boundary test.

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\BoosterServiceProvider;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
use RAN\Internal\CoreContainer;
use RAN\PackageArtifactLimit;
use RAN\RepositoryProvider\Admin\ProviderNavigationPlacement;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\BoosterGitHubProvider\V1\CredentialPolicy as GitHubCredentialPolicy;
use RAN\BoosterGitHubProvider\V1\Diagnostics as GitHubDiagnostics;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\BoosterGitHubProvider\V1\WebhookPolicy as GitHubWebhookPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;

require_once dirname( __DIR__ ) . '/Admin/Interaction/AdminInteractionWordPressFunctions.php';
require_once __DIR__ . '/BuiltInGitHubRegistrationWordPressFunctions.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}

final class BuiltInGitHubRegistrationTest extends TestCase {

	public function test_core_registers_the_bundled_git_hub_aggregate_without_reading_credentials(): void {
		$secrets   = null;
		$container = new CoreContainer();
		$runtime   = new Booster( $container );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core composition requires the WordPress table prefix.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
		};

		( new BoosterServiceProvider(
			static function ( ProviderSecretPolicyCatalog $policies ) use ( &$secrets ): RegistrationTrackingSecretsFile {
				$secrets = new RegistrationTrackingSecretsFile( $policies );

				return $secrets;
			}
		) )->register( $container, $runtime, new \stdClass(), 'ran-booster.php' );

		self::assertInstanceOf( RegistrationTrackingSecretsFile::class, $secrets );
		$provider = $container->make( ProviderRegistry::class )->get( 'gh' );
		$metadata = $provider->get_metadata();

		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertInstanceOf( CredentialValidator::class, $provider );
		self::assertInstanceOf( CredentialedPublicRepositoryBrowser::class, $provider );
		self::assertInstanceOf( ProviderCredentialPolicySupplier::class, $provider );
		self::assertInstanceOf( WebhookNormalizer::class, $provider );
		self::assertInstanceOf( RepositoryWebhookSettingsLink::class, $provider );
		self::assertInstanceOf( RepositoryWebhookFitness::class, $provider );
		self::assertInstanceOf( RepositoryWebhookManagement::class, $provider );
		self::assertInstanceOf( RepositoryReleaseAcquirer::class, $provider );
		self::assertInstanceOf( RepositoryReleaseCandidateListing::class, $provider );
		self::assertInstanceOf( RepositoryReleaseInspector::class, $provider );
		self::assertInstanceOf( RepositoryReleaseMetadata::class, $provider );
		self::assertInstanceOf( RepositoryReleaseNativeTargets::class, $provider );
		self::assertInstanceOf( GitHubDiagnostics::class, $provider->get_provider_diagnostics() );
		self::assertInstanceOf( GitHubCredentialPolicy::class, $provider->get_credential_policy() );
		self::assertInstanceOf( GitHubWebhookPolicy::class, $provider->get_webhook_policy() );
		self::assertSame( 'gh', $metadata->code->value );
		self::assertSame( 'GitHub', $metadata->label );
		self::assertSame( 'https://github.com/', $metadata->repository_url_base );
		self::assertSame( ProviderNavigationPlacement::GIT_HOST, $metadata->admin?->navigation?->group );
		self::assertSame( 100, $metadata->admin?->navigation?->slot );
		self::assertSame( 1, $secrets->credential_stores_issued );
		self::assertSame( 0, $secrets->credential_store->reads );

		$artifact_limit_supplier = ( new \ReflectionProperty( GitHubProvider::class, 'maximum_artifact_bytes' ) )->getValue( $provider );
		self::assertInstanceOf( \Closure::class, $artifact_limit_supplier );
		self::assertSame( PackageArtifactLimit::resolve(), $artifact_limit_supplier() );

		$first_controls  = $container->make( RepositoryWebhookManagementControls::class );
		$second_controls = $container->make( RepositoryWebhookManagementControls::class );
		self::assertSame( $first_controls, $second_controls );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_core_supplies_configured_artifact_limit_to_bundled_git_hub(): void {
		$configured_limit = 67_108_864;
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', $configured_limit );

		$container = new CoreContainer();
		$runtime   = new Booster( $container );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core composition requires the WordPress table prefix.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
		};

		( new BoosterServiceProvider() )->register( $container, $runtime, new \stdClass(), 'ran-booster.php' );

		$provider                = $container->make( ProviderRegistry::class )->get( 'gh' );
		$artifact_limit_supplier = ( new \ReflectionProperty( GitHubProvider::class, 'maximum_artifact_bytes' ) )->getValue( $provider );

		self::assertInstanceOf( \Closure::class, $artifact_limit_supplier );
		self::assertSame( $configured_limit, $artifact_limit_supplier() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_invalid_configured_artifact_limit_does_not_abort_core_registration(): void {
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 512 );

		$container = new CoreContainer();
		$runtime   = new Booster( $container );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core composition requires the WordPress table prefix.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
		};

		( new BoosterServiceProvider() )->register( $container, $runtime, new \stdClass(), 'ran-booster.php' );

		$provider                = $container->make( ProviderRegistry::class )->get( 'gh' );
		$artifact_limit_supplier = ( new \ReflectionProperty( GitHubProvider::class, 'maximum_artifact_bytes' ) )->getValue( $provider );

		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertInstanceOf( \Closure::class, $artifact_limit_supplier );

		$this->expectException( \InvalidArgumentException::class );
		$artifact_limit_supplier();
	}
}

final class RegistrationTrackingSecretsFile extends SecretsFile {
	public int $credential_stores_issued = 0;
	public RegistrationTrackingCredentialStore $credential_store;

	public function __construct( ProviderSecretPolicyCatalog $policies ) {
		parent::__construct( '/unused/github-registration-secrets.php', array(), $policies );
		$this->credential_store = new RegistrationTrackingCredentialStore();
	}

	public function credentials_for( ProviderCode|string $provider ): ProviderCredentialStore {
		unset( $provider );
		++$this->credential_stores_issued;

		return $this->credential_store;
	}
}

final class RegistrationTrackingCredentialStore implements ProviderCredentialStore {
	public int $reads = 0;

	public function credential_profiles(): array {
		++$this->reads;
		return array();
	}

	public function credential_material( ?string $id = null ): ?array {
		unset( $id );
		++$this->reads;
		return null;
	}

	public function has_webhook_profile(): bool {
		++$this->reads;
		return false;
	}
}
