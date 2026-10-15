<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

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
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
	define( 'ABSPATH', dirname( __DIR__ ) . '/fixtures/wordpress/' );
}

final class BuiltInGitHubRegistrationTest extends TestCase {

	public function test_core_composition_preserves_fresh_services_shared_stores_and_late_dependencies(): void {
		$container = new CoreContainer();
		$runtime   = new Booster( $container );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core composition requires the WordPress table prefix.
		$GLOBALS['wpdb'] = new \RAN\Tests\RepositoryProvider\HostSelectionDatabase();
		( new BoosterServiceProvider() )->register( $container, $runtime, ( require dirname( __DIR__, 2 ) . '/vendor/ran/wp-release-updater/bootstrap.php' ), $GLOBALS['wpdb']->basename );

		foreach ( array(
			\RAN\Admin\CredentialSelfDestructPurger::class,
			\RAN\Portability\ManagedPackageBlueprintExporter::class,
			\RAN\Portability\BlueprintReviewer::class,
			\RAN\Portability\BlueprintRepositoryVerifier::class,
			\RAN\Admin\PackageUpdateProgressController::class,
			\RAN\Deployment\WordPressWorkerWakeup::class,
			\RAN\Deployment\DeploymentWorker::class,
			\RAN\Admin\BulkPackageActionService::class,
			\RAN\Portability\PortabilityApplicationService::class,
			\RAN\Admin\PortabilityController::class,
		) as $type ) {
			$first = $container->make( $type );
			self::assertInstanceOf( $type, $first );
			self::assertNotSame( $first, $container->make( $type ), $type );
		}

		$selection = $container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class );
		$registry  = $container->make( ProviderRegistry::class );
		$secrets   = $container->make( SecretsFile::class );
		foreach ( array( array( $registry, 'github_selection_guard' ), array( $secrets, 'github_credential_access' ) ) as $binding ) {
			$guard = ( new \ReflectionProperty( $binding[0], $binding[1] ) )->getValue( $binding[0] );
			self::assertInstanceOf( \Closure::class, $guard );
			self::assertSame( $selection, ( new \ReflectionFunction( $guard ) )->getClosureThis() );
		}
		$verifier           = $container->make( \RAN\Portability\BlueprintRepositoryVerifier::class );
		$selection_property = new \ReflectionProperty( $verifier, 'selection' );
		self::assertSame( $selection, $selection_property->getValue( $verifier ) );
		self::assertSame( $selection, $selection_property->getValue( $verifier->for_preview() ) );
		self::assertSame( $secrets, ( new \ReflectionProperty( $verifier, 'secrets' ) )->getValue( $verifier ) );
		self::assertSame( $registry, ( new \ReflectionProperty( $verifier, 'providers' ) )->getValue( $verifier ) );
		$controller  = $container->make( \RAN\Admin\PortabilityController::class );
		$application = ( new \ReflectionProperty( $controller, 'application' ) )->getValue( $controller );
		self::assertInstanceOf( \RAN\Portability\PortabilityApplicationService::class, $application );
		$consumer_verifier = ( new \ReflectionProperty( $application, 'verifier' ) )->getValue( $application );
		self::assertInstanceOf( \RAN\Portability\BlueprintRepositoryVerifier::class, $consumer_verifier );
		self::assertSame( $selection, $selection_property->getValue( $consumer_verifier ) );

		$purger = $container->make( \RAN\Admin\CredentialSelfDestructPurger::class );
		self::assertInstanceOf( \RAN\Admin\CredentialSelfDestructPurger::class, $purger );
		$secrets_property = new \ReflectionProperty( $purger, 'secrets' );
		self::assertSame( $container->make( SecretsFile::class ), $secrets_property->getValue( $purger ) );
		$progress = $container->make( \RAN\Admin\PackageUpdateProgressController::class );
		$wakeup   = $container->make( \RAN\Deployment\WordPressWorkerWakeup::class );
		self::assertInstanceOf( \RAN\Admin\PackageUpdateProgressController::class, $progress );
		self::assertInstanceOf( \RAN\Deployment\WordPressWorkerWakeup::class, $wakeup );
		self::assertNotSame( ( new \ReflectionProperty( $progress, 'attempts' ) )->getValue( $progress ), ( new \ReflectionProperty( $wakeup, 'attempts' ) )->getValue( $wakeup ) );

		$replacement = new SecretsFile( '/unused/composition-test.php' );
		$container->bind( SecretsFile::class, $replacement );
		$changed = $container->make( \RAN\Admin\CredentialSelfDestructPurger::class );
		self::assertInstanceOf( \RAN\Admin\CredentialSelfDestructPurger::class, $changed );
		self::assertSame( $replacement, $secrets_property->getValue( $changed ) );
		self::assertNotSame( $replacement, $secrets_property->getValue( $purger ) );

		$resolved = array();
		foreach ( array( \RAN\Storage\PluginRepository::class, \RAN\Storage\ThemeRepository::class ) as $type ) {
			$container->bind(
				$type,
				static function () use ( $type, &$resolved ): never {
					$resolved[] = $type;
					throw new \RuntimeException( 'Composition dependency unavailable: ' . $type );
				}
			);
		}
		try {
			$container->make( \RAN\Portability\BlueprintReviewer::class );
			self::fail( 'A dependency construction failure must propagate.' );
		} catch ( \RuntimeException $failure ) {
			self::assertSame( 'Composition dependency unavailable: ' . \RAN\Storage\PluginRepository::class, $failure->getMessage() );
			self::assertSame( array( \RAN\Storage\PluginRepository::class ), $resolved );
		}
	}

	public function test_activity_resolver_keeps_provider_repository_and_managed_subjects_distinct(): void {
		$container = new CoreContainer();
		$runtime   = new Booster( $container );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core composition requires the WordPress table prefix.
		$GLOBALS['wpdb'] = new \RAN\Tests\RepositoryProvider\HostSelectionDatabase();
		( new BoosterServiceProvider() )->register( $container, $runtime, ( require dirname( __DIR__, 2 ) . '/vendor/ran/wp-release-updater/bootstrap.php' ), $GLOBALS['wpdb']->basename );
		$container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class )->complete_collection();
		$container->make( ProviderRegistry::class )->seal();
		$reporter = $container->make( ProviderRegistry::class )->activity_reporter( 'gh' );
		$capture  = ( new \ReflectionProperty( \RAN\Activity\BoundActivityReporter::class, 'capture' ) )->getValue( $reporter );
		self::assertInstanceOf( \Closure::class, $capture );
		$provider = $capture( new \RAN\Activity\ActivityRequest( 'gh.inspect' ) );
		self::assertSame( 'provider', $provider['subject_type'] );
		self::assertSame( 'gh', $provider['subject_identity'] );
		self::assertNull( $provider['adoption_token'] );
		$repository = new \RAN\RepositoryProvider\RepositoryReference( 'owner/example', '123', false, null );
		$unmanaged  = $capture( new \RAN\Activity\ActivityRequest( 'gh.inspect', $repository ) );
		self::assertSame( 'repository', $unmanaged['subject_type'] );
		self::assertSame( '123', $unmanaged['subject_identity'] );
		self::assertNull( $unmanaged['adoption_token'] );
		self::assertNull( $capture( new \RAN\Activity\ActivityRequest( 'gh.inspect', new \RAN\RepositoryProvider\RepositoryReference( 'owner/unresolved', null, false, null ) ) ) );

		$package = $this->createMock( \RAN\Plugin::class );
		$package->method( 'get_provider_code' )->willReturn( 'gh' );
		$package->method( 'get_provider_repository_id' )->willReturn( '123' );
		$plugins = $this->createMock( \RAN\Storage\PluginRepository::class );
		$plugins->method( 'booster_plugin_from_file' )->with( 'example/example.php' )->willReturn( $package );
		$container->bind( \RAN\Storage\PluginRepository::class, $plugins );
		$token = \RAN\AdoptionIdentity::create( 'gh', '123' );
		$package->method( 'get_adoption_token' )->willReturn( $token );
		$context  = array(
			'subject_type'     => 'plugin',
			'subject_identity' => 'example/example.php',
			'adoption_token'   => $token,
			'context'          => 'source-and-access',
			'purpose'          => 'branch.check',
			'provenance'       => 'core',
		);
		$evidence = $this->createMock( \RAN\Admin\RepositoryBranchCheckEvidenceStore::class );
		$evidence->expects( self::once() )->method( 'activity_context' )->with( 'plugin', $package, 'branch.check' )->willReturn( $context );
		$container->bind( \RAN\Admin\RepositoryBranchCheckEvidenceStore::class, $evidence );
		self::assertSame( $context, $capture( new \RAN\Activity\ActivityRequest( 'gh.inspect', $repository, 'plugin', 'example/example.php' ) ) );
		self::assertNull( $capture( new \RAN\Activity\ActivityRequest( 'gh.inspect', new \RAN\RepositoryProvider\RepositoryReference( 'owner/other', '124', false, null ), 'plugin', 'example/example.php' ) ) );
	}

	public function test_core_registers_the_bundled_git_hub_aggregate_without_reading_credentials(): void {
		$secrets   = null;
		$container = new CoreContainer();
		$runtime   = new Booster( $container );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core composition requires the WordPress table prefix.
		$GLOBALS['wpdb'] = new \RAN\Tests\RepositoryProvider\HostSelectionDatabase();

		( new BoosterServiceProvider(
			static function ( ProviderSecretPolicyCatalog $policies, callable $guard ) use ( &$secrets ): RegistrationTrackingSecretsFile {
				$secrets = new RegistrationTrackingSecretsFile( $policies, $guard );

				return $secrets;
			}
		) )->register( $container, $runtime, ( require dirname( __DIR__, 2 ) . '/vendor/ran/wp-release-updater/bootstrap.php' ), $GLOBALS['wpdb']->basename );

		self::assertInstanceOf( RegistrationTrackingSecretsFile::class, $secrets );
		self::assertSame( array(), $container->make( ProviderRegistry::class )->all() );
		self::assertSame( 0, $secrets->credential_stores_issued );
		self::assertSame( 0, $secrets->credential_store->reads );
		self::assertNull( $container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class )->expectation() );
		$container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class )->complete_collection();
		$container->make( ProviderRegistry::class )->seal();
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
		self::assertSame( 100, $metadata->admin->navigation->slot );
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
		$GLOBALS['wpdb'] = new \RAN\Tests\RepositoryProvider\HostSelectionDatabase();

		( new BoosterServiceProvider() )->register( $container, $runtime, ( require dirname( __DIR__, 2 ) . '/vendor/ran/wp-release-updater/bootstrap.php' ), $GLOBALS['wpdb']->basename );

		$container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class )->complete_collection();
		$container->make( ProviderRegistry::class )->seal();
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
		$GLOBALS['wpdb'] = new \RAN\Tests\RepositoryProvider\HostSelectionDatabase();

		( new BoosterServiceProvider() )->register( $container, $runtime, ( require dirname( __DIR__, 2 ) . '/vendor/ran/wp-release-updater/bootstrap.php' ), $GLOBALS['wpdb']->basename );

		$container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class )->complete_collection();
		$container->make( ProviderRegistry::class )->seal();
		$provider                = $container->make( ProviderRegistry::class )->get( 'gh' );
		$artifact_limit_supplier = ( new \ReflectionProperty( GitHubProvider::class, 'maximum_artifact_bytes' ) )->getValue( $provider );

		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertInstanceOf( \Closure::class, $artifact_limit_supplier );

		$this->expectException( \InvalidArgumentException::class );
		$artifact_limit_supplier();
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class RegistrationTrackingSecretsFile extends SecretsFile {
	public int $credential_stores_issued = 0;
	public RegistrationTrackingCredentialStore $credential_store;

	public function __construct( ProviderSecretPolicyCatalog $policies, callable $guard ) {
		parent::__construct( '/unused/github-registration-secrets.php', array(), $policies, github_credential_access: $guard );
		$this->credential_store = new RegistrationTrackingCredentialStore();
	}

	public function credentials_for( ProviderCode|string $provider ): ProviderCredentialStore {
		unset( $provider );
		++$this->credential_stores_issued;

		return $this->credential_store;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
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
