<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

// Native temporary-plugin materialization proves a physically separate extension layout.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused Phase 5 fixture doubles belong with the proof.

require_once dirname( __DIR__ ) . '/Support/ExternalFixturePluginWordPressFunctions.php';
require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';
require_once __DIR__ . '/AuthenticatedPreparedArchiveWordPressFunctions.php';
require_once __DIR__ . '/BuiltInGitHubRegistrationWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Admin/Interaction/AdminInteractionWordPressFunctions.php';
require_once __DIR__ . '/GitHubExternalExtensionWordPressFunctions.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Booster;
use RAN\BoosterServiceProvider;
use RAN\BoosterGitHubProvider\V1\GitHubProvider;
use RAN\Internal\CoreContainer;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV2;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\Secrets\SecretsFile;
use RANBoosterGitHubProviderExtensionFixture\ReleaseUpdaterRegistrar;
use RuntimeException;

final class GitHubExternalExtensionParityTest extends TestCase {
	private const ARTIFACT_LIMIT = 67_108_864;

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testReleasedGitHubPackageComposesAsAPhysicallySeparateExternalPlugin(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 11 );
		define( 'RAN_BOOSTER_RUNTIME_MODE', 'single_site_supported' );
		$GLOBALS['ran_booster_external_fixture_actions'] = array();

		$extension = $this->materializeExternalExtension();

		try {
			$this->loadGenericFixture();
			require $extension . '/ran-booster-github-provider-extension.php';

			$registry  = $this->registry( self::ARTIFACT_LIMIT );
			$callbacks = $GLOBALS['ran_booster_external_fixture_actions']['ran_booster_register_providers'] ?? array();
			self::assertCount( 2, $callbacks );
			foreach ( $callbacks as $callback ) {
				$callback( $registry );
			}
			$registry->seal();

			self::assertSame( array( 'fixture-provider', 'gh' ), array_keys( $registry->all() ) );
			$provider = $registry->get( 'gh' );
			self::assertInstanceOf( GitHubProvider::class, $provider );

			$source = ( new \ReflectionClass( $provider ) )->getFileName();
			self::assertIsString( $source );
			self::assertStringStartsWith(
				$extension . '/vendor/ran/booster-github-provider/src/',
				str_replace( '\\', '/', $source )
			);

			$metadata = $provider->getMetadata();
			self::assertSame( 'gh', $metadata->code->value );
			self::assertSame( 'GitHub', $metadata->label );
			self::assertSame( 'https://github.com/', $metadata->repositoryUrlBase );

			foreach (
				array(
					CredentialedPublicRepositoryBrowser::class,
					RepositoryWebhookFitness::class,
					RepositoryWebhookManagement::class,
					RepositoryReleaseMetadata::class,
					RepositoryReleaseCandidateListing::class,
					RepositoryReleaseInspector::class,
					RepositoryReleaseAcquirer::class,
					RepositoryReleaseNativeTargets::class,
					RepositoryReleaseWorkflowManagementV2::class,
				) as $capability
			) {
				self::assertSame( $provider, $registry->requireCapability( 'gh', $capability ), $capability );
			}

			$this->proveAnonymousPublicBrowse( $registry );
			$this->proveArchiveComposition( $provider );
			$this->proveExternalReleaseLimit( $provider, $extension );
			$this->assertNoPrivateBoosterImports( $extension );
			$this->assertSelfUpdateIsProviderIndependent();
		} finally {
			$this->removeTree( $extension );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testBundledGitHubPassesTheSameConfiguredLimitToReleaseOperations(): void {
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', self::ARTIFACT_LIMIT );

		$updater   = new Phase5BundledReleaseUpdater();
		$container = new CoreContainer();
		$runtime   = new Booster( $container );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core composition requires the WordPress table prefix.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
		};

		( new BoosterServiceProvider(
			static fn ( ProviderSecretPolicyCatalog $policies ): SecretsFile => new Phase5SecretsFile( $policies )
		) )->register( $container, $runtime, $updater, 'ran-booster.php' );

		$provider = $container->make( ProviderRegistry::class )->get( 'gh' );
		try {
			$provider->listReleaseCandidates(
				'plugin',
				new RepositoryReference( 'RocketsAreNostalgic/example-plugin', '987654321', false, null ),
				'stable'
			);
			self::fail( 'The inert recorder must not produce a release listing.' );
		} catch ( RuntimeException ) {
			self::assertNotNull( $updater->releaseArguments );
		}

		self::assertSame(
			array(
				'github',
				'plugin',
				'RocketsAreNostalgic/example-plugin',
				'987654321',
				'stable',
				null,
				self::ARTIFACT_LIMIT,
			),
			$updater->releaseArguments
		);
	}

	public function testFixtureDeclaresNativeDependencyAndUsesOnlyPublicCompositionSurfaces(): void {
		$root       = dirname( __DIR__ ) . '/fixtures/ran-booster-github-provider-extension';
		$entrypoint = file_get_contents( $root . '/ran-booster-github-provider-extension.php' );
		$plugin     = file_get_contents( $root . '/src/Plugin.php' );

		self::assertIsString( $entrypoint );
		self::assertIsString( $plugin );
		self::assertStringContainsString( 'Requires Plugins: ran-booster', $entrypoint );
		self::assertStringContainsString( "require __DIR__ . '/vendor/autoload.php';", $entrypoint );
		self::assertStringContainsString( 'vendor/ran/wp-release-updater/bootstrap.php', $plugin );
		self::assertStringContainsString( "registerWithCredentialStore( 'gh', \$factory )", $plugin );
		self::assertStringNotContainsString( 'class_exists( ProviderRegistrationContext::class )', $plugin );
		self::assertStringContainsString( 'ProviderRegistrationContext $registrationContext', $plugin );
		self::assertMatchesRegularExpression(
			'/\$factory = static fn \([\s\S]*?ProviderCredentialStore \$credentials,[\s\S]*?AuthenticatedWebhookDeliveryEvidenceReader \$deliveryEvidence[\s\S]*?\): RepositoryProvider => GitHubProvider::create\([\s\S]*?\$registrar\s*\);/',
			$plugin
		);
		self::assertStringNotContainsString( 'CoreContainer', $plugin );

		$this->assertNoForbiddenNamespaceText( $root );
	}

	private function proveAnonymousPublicBrowse( ProviderRegistry $registry ): void {
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response(
					array(
						'login' => 'RocketsAreNostalgic',
						'type'  => 'Organization',
					)
				),
				$this->response(
					array(
						array(
							'id'             => 1319710173,
							'full_name'      => 'RocketsAreNostalgic/ran-booster',
							'private'        => false,
							'default_branch' => 'main',
						),
					)
				),
			)
		);

		$browser = $registry->requireCapability( 'gh', CredentialedPublicRepositoryBrowser::class );
		$result  = $browser->browseRepositories( RepositoryBrowseRequest::publicOwner( 'RocketsAreNostalgic' ) );

		self::assertCount( 1, $result->repositories );
		self::assertSame( 'RocketsAreNostalgic/ran-booster', $result->repositories[0]->locator );
		foreach ( \RAN\BoosterGitHubProvider\V1\repository_resolver_http_requests() as $request ) {
			self::assertArrayNotHasKey( 'Authorization', $request['arguments']['headers'] );
		}
	}

	private function proveArchiveComposition( object $provider ): void {
		$commit = '0123456789abcdef0123456789abcdef01234567';
		\RAN\BoosterGitHubProvider\V1\repository_resolver_http_queue(
			array(
				$this->response(
					array(
						'id'             => 987654321,
						'full_name'      => 'RocketsAreNostalgic/example-plugin',
						'private'        => false,
						'default_branch' => 'main',
					)
				),
				array(
					'response' => array( 'code' => 200 ),
					'body'     => $commit,
				),
			)
		);

		$archive = $provider->prepareArchive(
			new ArchiveRequest(
				new RepositoryReference( 'RocketsAreNostalgic/example-plugin', '987654321', false, null ),
				'main'
			)
		);

		self::assertSame( $commit, $archive->getResolvedRef() );
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/' . $commit,
			$archive->getUrl()
		);
		$archive->cleanup();
	}

	private function proveExternalReleaseLimit( object $provider, string $extension ): void {
		$registrar = ( new \ReflectionProperty( GitHubProvider::class, 'registrar' ) )->getValue( $provider );
		self::assertInstanceOf( ReleaseUpdaterRegistrar::class, $registrar );
		self::assertSame(
			$extension . '/vendor/ran/wp-release-updater/bootstrap.php',
			str_replace( '\\', '/', $registrar->innerSource() )
		);

		try {
			$provider->listReleaseCandidates(
				'plugin',
				new RepositoryReference( 'RocketsAreNostalgic/example-plugin', '987654321', false, null ),
				'stable'
			);
			self::fail( 'The copied updater runtime is intentionally inactive in this unit fixture.' );
		} catch ( RuntimeException ) {
			self::assertNotNull( $registrar->releaseArguments() );
		}

		self::assertSame(
			array(
				'github',
				'plugin',
				'RocketsAreNostalgic/example-plugin',
				'987654321',
				'stable',
				null,
				self::ARTIFACT_LIMIT,
			),
			$registrar->releaseArguments()
		);
	}

	private function assertNoPrivateBoosterImports( string $extension ): void {
		$this->assertNoForbiddenNamespaceText( $extension . '/src' );
		$this->assertNoForbiddenNamespaceText( $extension . '/vendor/ran/booster-github-provider/src' );
	}

	private function assertNoForbiddenNamespaceText( string $directory ): void {
		$forbidden = array(
			'RAN\\Admin\\',
			'RAN\\Internal\\',
			'RAN\\Logging\\',
			'RAN\\Secrets\\',
			'RAN\\Storage\\',
			'RAN\\WordPress\\',
		);
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = file_get_contents( $file->getPathname() );
			self::assertIsString( $source );
			foreach ( $forbidden as $namespace ) {
				self::assertStringNotContainsString( $namespace, $source, $file->getPathname() );
			}
		}
	}

	private function assertSelfUpdateIsProviderIndependent(): void {
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );
		self::assertIsString( $bootstrap );
		$start = strpos( $bootstrap, 'if ( $ran_booster_self_update_policy->allowsNativeDiscovery() )' );
		$end   = strpos( $bootstrap, '$ran_booster_container->bind(', $start );
		self::assertIsInt( $start );
		self::assertIsInt( $end );
		$selfUpdate = substr( $bootstrap, $start, $end - $start );

		self::assertStringContainsString( 'ManagedReleaseUpdaterRegistrar::class )->plugin(', $selfUpdate );
		self::assertStringContainsString( 'new CoreSelfUpdateNativeTarget( $coreUpdater )', $selfUpdate );
		self::assertStringNotContainsString( 'ProviderRegistry', $selfUpdate );
		self::assertStringNotContainsString( 'GitHubProvider', $selfUpdate );
		self::assertStringNotContainsString( 'requireCapability', $selfUpdate );
	}

	private function registry( int $artifactLimit ): ProviderRegistry {
		$credentials = new Phase5CredentialStore();
		$delivery    = new Phase5DeliveryEvidenceReader();

		return new ProviderRegistry(
			array(),
			new ProviderSecretPolicyCatalog(),
			static fn ( ProviderCode $code ): ProviderCredentialStore => $credentials,
			static fn ( ProviderCode $code ): AuthenticatedWebhookDeliveryEvidenceReader => $delivery,
			new ProviderRegistrationContext( static fn (): int => $artifactLimit )
		);
	}

	private function loadGenericFixture(): void {
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-provider/ran-booster-fixture-provider.php';
	}

	private function materializeExternalExtension(): string {
		$root = sys_get_temp_dir() . '/ran-booster-github-extension-' . bin2hex( random_bytes( 6 ) );
		$this->copyTree(
			dirname( __DIR__ ) . '/fixtures/ran-booster-github-provider-extension',
			$root
		);

		$repositoryRoot = dirname( __DIR__, 2 );
		foreach ( array( 'booster-github-provider', 'wp-release-updater', 'updater-support' ) as $package ) {
			$this->copyTree(
				$repositoryRoot . '/vendor/ran/' . $package,
				$root . '/vendor/ran/' . $package
			);
		}

		$autoload = <<<'PHP'
<?php
declare(strict_types=1);

$prefixes = array(
	'RAN\\BoosterGitHubProvider\\V1\\' => __DIR__ . '/ran/booster-github-provider/src/',
	'RAN\\UpdaterSupport\\V1\\' => __DIR__ . '/ran/updater-support/src/',
);

spl_autoload_register(
	static function ( string $class ) use ( $prefixes ): void {
		foreach ( $prefixes as $prefix => $directory ) {
			if ( ! str_starts_with( $class, $prefix ) ) {
				continue;
			}
			$file = $directory . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
			return;
		}
	},
	true,
	true
);
PHP;
		self::assertNotFalse( file_put_contents( $root . '/vendor/autoload.php', $autoload ) );

		return $root;
	}

	private function copyTree( string $source, string $destination ): void {
		self::assertDirectoryExists( $source );
		self::assertTrue( is_dir( $destination ) || mkdir( $destination, 0700, true ) );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			self::assertFalse( $item->isLink(), 'Fixture packages must not contain symlinks.' );
			$relative = substr( $item->getPathname(), strlen( $source ) + 1 );
			$target   = $destination . '/' . $relative;
			if ( $item->isDir() ) {
				self::assertTrue( is_dir( $target ) || mkdir( $target, 0700, true ) );
			} else {
				self::assertTrue( copy( $item->getPathname(), $target ) );
			}
		}
	}

	private function removeTree( string $root ): void {
		if ( ! is_dir( $root ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $root );
	}

	/** @param array<string, mixed>|list<array<string, mixed>> $body */
	private function response( array $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}
}

final class Phase5CredentialStore implements ProviderCredentialStore {
	public function credentialProfiles(): array {
		return array();
	}

	public function credentialMaterial( ?string $id = null ): ?array {
		unset( $id );

		return null;
	}

	public function hasWebhookProfile(): bool {
		return false;
	}
}

final class Phase5DeliveryEvidenceReader implements AuthenticatedWebhookDeliveryEvidenceReader {
	public function latestAuthenticatedDelivery(): ?AuthenticatedWebhookDeliveryEvidence {
		return null;
	}
}

final class Phase5BundledReleaseUpdater {
	/** @var list<mixed>|null */
	public ?array $releaseArguments = null;

	public function releases( mixed ...$arguments ): object {
		$this->releaseArguments = $arguments;

		return new class() {
			/** @return array{ok:false,code:string,value:null,retry_after:null,cleanup_status:string} */
			public function list(): array {
				return array(
					'ok'             => false,
					'code'           => 'runtime_not_ready',
					'value'          => null,
					'retry_after'    => null,
					'cleanup_status' => 'not_applicable',
				);
			}
		};
	}
}

final class Phase5SecretsFile extends SecretsFile {
	private Phase5CredentialStore $credentials;

	public function __construct( ProviderSecretPolicyCatalog $policies ) {
		parent::__construct( '/unused/phase5-github-extension-secrets.php', array(), $policies );
		$this->credentials = new Phase5CredentialStore();
	}

	public function credentialsFor( ProviderCode|string $provider ): ProviderCredentialStore {
		unset( $provider );

		return $this->credentials;
	}
}
