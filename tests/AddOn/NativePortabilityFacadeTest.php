<?php

declare(strict_types=1);

namespace Tests\AddOn;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\Portability\NativePortabilityFacade;
use RAN\AddOn\Portability\PortabilityApplyResult;
use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\AddOn\Portability\PortabilityReviewResult;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageOperationService;
use RAN\Plugin;
use RAN\Portability\BlueprintRepositoryVerifier;
use RAN\Portability\BlueprintReviewer;
use RAN\Portability\PortabilityApplicationService;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use ReflectionClass;
use Tests\Portability\TemporaryCredentialProvider;
use Tests\Support\NullLoggingFacade;

require_once __DIR__ . '/../Support/PackageOperationGlobalWordPressFunctions.php';
require_once __DIR__ . '/../Runtime/RuntimeSupportWordPressFunctions.php';

final class NativePortabilityFacadeTest extends TestCase {

	private ?TemporaryCredentialProvider $provider = null;

	protected function setUp(): void {
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_package_mutation_guard_multisite'] );
	}

	public function testCorePublishesTheExactFacadeAfterProviderSealingAndBeforeDashboardBinding(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local bootstrap contract.
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );

		self::assertIsString( $bootstrap );
		self::assertSame( 1, NativePortabilityFacade::API_VERSION );
		self::assertStringContainsString( "'RAN_BOOSTER_PORTABILITY_API_VERSION'", $bootstrap );
		self::assertStringContainsString(
			"do_action( 'ran_booster_portability_ready', \$portability, \$logging )",
			$bootstrap
		);
		$runtimeGate = strpos( $bootstrap, 'if ( ! $ran_booster_runtime_support->allowsManagedOperations() )' );
		$marker      = strpos( $bootstrap, "if ( ! defined( 'RAN_BOOSTER_PORTABILITY_API_VERSION' )" );
		self::assertIsInt( $runtimeGate );
		self::assertIsInt( $marker );
		self::assertStringContainsString( 'return;', substr( $bootstrap, $runtimeGate, $marker - $runtimeGate ) );
		self::assertLessThan(
			strpos( $bootstrap, "do_action( 'ran_booster_portability_ready'" ),
			strpos( $bootstrap, '$providerRegistry->seal()' )
		);
		self::assertLessThan(
			strpos( $bootstrap, '$ran_booster_instance->bind( Dashboard::class' ),
			strpos( $bootstrap, "do_action( 'ran_booster_portability_ready'" )
		);
	}

	public function testReviewUsesOneProviderResolutionAndNeverReturnsInstall(): void {
		$facade = $this->facade( true );
		$result = $facade->review( $this->candidate(), 'valid-nonce' );

		self::assertSame( PortabilityReviewResult::ADOPT, $result->action );
		self::assertSame( 1, count( $this->provider?->credentialIds ?? array() ) );

		$missing = $this->facade( false )->review( $this->candidate(), 'valid-nonce' );
		self::assertSame( PortabilityReviewResult::BLOCKED, $missing->action );
		self::assertSame( 'destination_conflict', $missing->reason );
	}

	public function testReviewAuthorizationFailsBeforeProviderAccess(): void {
		$facade = $this->facade( true, false, null, false );
		$result = $facade->review( $this->candidate(), 'valid-nonce' );

		self::assertSame( PortabilityReviewResult::BLOCKED, $result->action );
		self::assertSame( 'forbidden', $result->reason );
		self::assertSame( array(), $this->provider?->credentialIds );
	}

	public function testApplyTreatsOnlyAnExactDisabledManagedTargetAsVerified(): void {
		$exact  = $this->managedPlugin( false );
		$facade = $this->facade( true, true, $exact );
		$review = $facade->review( $this->candidate(), 'valid-nonce' );
		$result = $facade->apply( $this->candidate(), $review->fingerprint, 'valid-nonce' );

		self::assertSame( PortabilityReviewResult::MANAGED, $review->action );
		self::assertSame( PortabilityApplyResult::UNCHANGED, $result->status );
		self::assertTrue( $result->targetVerified );

		$manual = $this->facade( true, true, $this->managedPlugin( false, DeploymentPolicy::MANUAL ) )
			->review( $this->candidate(), 'valid-nonce' );
		self::assertSame( PortabilityReviewResult::PROTECTED, $manual->action );
	}

	public function testApplyRecomputesAndRejectsAChangedReview(): void {
		$facade = $this->facade( true, true, $this->managedPlugin( false ) );
		$review = $facade->review( $this->candidate(), 'valid-nonce' );
		$result = $facade->apply(
			$this->candidate( array( 'branch' => 'develop' ) ),
			$review->fingerprint,
			'valid-nonce'
		);

		self::assertSame( PortabilityApplyResult::BLOCKED, $result->status );
		self::assertSame( 'review_changed', $result->reason );
		self::assertFalse( $result->targetVerified );
	}

	public function testProviderPrivacyDriftCannotClaimManagedVerification(): void {
		$public  = $this->facade( true, true, $this->managedPlugin( false ) )
			->review( $this->candidate(), 'valid-nonce' );
		$private = $this->facade( true, true, $this->managedPlugin( true ), true, true )
			->review( $this->candidate( array( 'credentialId' => null ) ), 'valid-nonce' );

		self::assertSame( PortabilityReviewResult::MANAGED, $public->action );
		self::assertSame( PortabilityReviewResult::BLOCKED, $private->action );
		self::assertNotSame( $public->fingerprint, $private->fingerprint );
	}

	public function testFacadeSurfaceContainsNoPersistenceOrSourceCleanupAuthority(): void {
		$files  = glob( dirname( __DIR__, 2 ) . '/RAN/AddOn/Portability/*.php' );
		$source = implode(
			"\n",
			array_map(
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Focused local source contract inspection.
				static fn ( string $file ): string => (string) file_get_contents( $file ),
				is_array( $files ) ? $files : array()
			)
		);

		foreach ( array( 'update_option', 'add_option', 'file_put_contents', 'BlueprintArchive', 'prepare(', 'cancel(', 'cleanup' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
	}

	private function facade(
		bool $installed,
		bool $managed = false,
		?Plugin $managedPackage = null,
		bool $authorized = true,
		bool $providerPrivate = false
	): NativePortabilityFacade {
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'isInstalled' )->willReturn( $installed );
		$plugins->method( 'hasManagementRecord' )->willReturn( $managed );
		if ( null !== $managedPackage ) {
			$plugins->method( 'boosterPluginFromFile' )->willReturn( $managedPackage );
		}
		$themes->method( 'isInstalled' )->willReturn( false );
		$themes->method( 'hasManagementRecord' )->willReturn( false );

		$catalog        = new ProviderSecretPolicyCatalog();
		$secrets        = new SecretsFile( null, array(), $catalog );
		$this->provider = new TemporaryCredentialProvider(
			$secrets->credentialsFor( 'gh' ),
			0,
			'repository-id',
			$providerPrivate
		);
		$registry       = new ProviderRegistry( new NullLoggingFacade(), array( $this->provider ), $catalog );
		$service        = new PortabilityApplicationService(
			new BlueprintReviewer( $plugins, $themes ),
			new BlueprintRepositoryVerifier( $registry, $secrets ),
			( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$secrets
		);

		return new NativePortabilityFacade(
			$service,
			static fn ( string $type, bool $apply ): bool => $authorized,
			static fn ( string $nonce, string $action ): bool => 'valid-nonce' === $nonce
		);
	}

	/** @param array<string, mixed> $overrides */
	private function candidate( array $overrides = array() ): PortabilityCandidate {
		return new PortabilityCandidate(
			...array_merge(
				array(
					'type'         => 'plugin',
					'identifier'   => 'example/example.php',
					'displayName'  => 'Example',
					'providerCode' => 'gh',
					'repository'   => 'owner/repository',
					'branch'       => 'main',
					'subdirectory' => null,
					'credentialId' => null,
				),
				$overrides
			)
		);
	}

	private function managedPlugin(
		bool $private,
		DeploymentPolicy $policy = DeploymentPolicy::DISABLED
	): Plugin {
		$plugin = Plugin::fromWpArray(
			'example/example.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => '1.0.0',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => '',
			)
		);
		$plugin->setRepository( new ManagedRepository( 'gh', 'owner/repository', 'repository-id', 'main', $private ) );
		$plugin->setDeploymentPolicy( $policy );

		return $plugin;
	}
}
