<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Private registration spies belong with this host-boundary test.

use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\BoosterServiceProvider;
use RAN\Internal\CoreContainer;
use RAN\RepositoryProvider\Admin\ProviderNavigationPlacement;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\Booster\GitHub\CredentialPolicy as GitHubCredentialPolicy;
use RAN\Booster\GitHub\Diagnostics as GitHubDiagnostics;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\Booster\GitHub\WebhookPolicy as GitHubWebhookPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicySupplier;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
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

	public function testCoreRegistersTheBundledGitHubAggregateWithoutReadingCredentials(): void {
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
		) )->register( $container, $runtime );

		self::assertInstanceOf( RegistrationTrackingSecretsFile::class, $secrets );
		$provider = $container->make( ProviderRegistry::class )->get( 'gh' );
		$metadata = $provider->getMetadata();

		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertInstanceOf( CredentialValidator::class, $provider );
		self::assertInstanceOf( CredentialedPublicRepositoryBrowser::class, $provider );
		self::assertInstanceOf( ProviderCredentialPolicySupplier::class, $provider );
		self::assertInstanceOf( WebhookNormalizer::class, $provider );
		self::assertInstanceOf( RepositoryWebhookSettingsLink::class, $provider );
		self::assertInstanceOf( RepositoryWebhookFitness::class, $provider );
		self::assertInstanceOf( RepositoryWebhookManagement::class, $provider );
		self::assertInstanceOf( RepositoryReleaseMetadata::class, $provider );
		self::assertInstanceOf( GitHubDiagnostics::class, $provider->getProviderDiagnostics() );
		self::assertInstanceOf( GitHubCredentialPolicy::class, $provider->getCredentialPolicy() );
		self::assertInstanceOf( GitHubWebhookPolicy::class, $provider->getWebhookPolicy() );
		self::assertSame( 'gh', $metadata->code->value );
		self::assertSame( 'GitHub', $metadata->label );
		self::assertSame( 'https://github.com/', $metadata->repositoryUrlBase );
		self::assertSame( ProviderNavigationPlacement::GIT_HOST, $metadata->admin?->navigation?->group );
		self::assertSame( 100, $metadata->admin?->navigation?->slot );
		self::assertSame( 1, $secrets->credentialStoresIssued );
		self::assertSame( 0, $secrets->credentialStore->reads );
	}
}

final class RegistrationTrackingSecretsFile extends SecretsFile {
	public int $credentialStoresIssued = 0;
	public RegistrationTrackingCredentialStore $credentialStore;

	public function __construct( ProviderSecretPolicyCatalog $policies ) {
		parent::__construct( '/unused/github-registration-secrets.php', array(), $policies );
		$this->credentialStore = new RegistrationTrackingCredentialStore();
	}

	public function credentialsFor( ProviderCode|string $provider ): ProviderCredentialStore {
		unset( $provider );
		++$this->credentialStoresIssued;

		return $this->credentialStore;
	}
}

final class RegistrationTrackingCredentialStore implements ProviderCredentialStore {
	public int $reads = 0;

	public function credentialProfiles(): array {
		++$this->reads;
		return array();
	}

	public function credentialMaterial( ?string $id = null ): ?array {
		unset( $id );
		++$this->reads;
		return null;
	}

	public function hasWebhookProfile(): bool {
		++$this->reads;
		return false;
	}
}
