<?php

declare(strict_types=1);

namespace RAN\Tests\AddOn;

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
use RAN\Tests\Portability\TemporaryCredentialProvider;

require_once __DIR__ . '/../Support/PackageOperationGlobalWordPressFunctions.php';
require_once __DIR__ . '/../Runtime/RuntimeSupportWordPressFunctions.php';

final class NativePortabilityFacadeTest extends TestCase {

	private ?TemporaryCredentialProvider $provider = null;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this method name.

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_package_mutation_guard_multisite'] = false;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override requires this method name.

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_package_mutation_guard_multisite'] );
	}

	public function test_core_publishes_the_exact_facade_after_provider_sealing_and_before_dashboard_binding(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local bootstrap contract.
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );

		self::assertIsString( $bootstrap );
		self::assertSame( 3, NativePortabilityFacade::API_VERSION );
		self::assertStringContainsString( "'RAN_BOOSTER_PORTABILITY_API_VERSION'", $bootstrap );
		self::assertStringContainsString(
			"do_action( 'ran_booster_portability_ready', \$portability )",
			$bootstrap
		);
		$runtime_gate = strpos( $bootstrap, 'if ( ! $ran_booster_runtime_support->allows_managed_operations() )' );
		$marker       = strpos( $bootstrap, "if ( ! defined( 'RAN_BOOSTER_PORTABILITY_API_VERSION' )" );
		self::assertIsInt( $runtime_gate );
		self::assertIsInt( $marker );
		self::assertStringContainsString( 'return;', substr( $bootstrap, $runtime_gate, $marker - $runtime_gate ) );
		self::assertLessThan(
			strpos( $bootstrap, "do_action( 'ran_booster_portability_ready'" ),
			strpos( $bootstrap, '$provider_registry->seal()' )
		);
		self::assertLessThan(
			strpos( $bootstrap, '$ran_booster_container->bind( Dashboard::class' ),
			strpos( $bootstrap, "do_action( 'ran_booster_portability_ready'" )
		);
	}

	public function test_review_uses_one_provider_resolution_and_never_returns_install(): void {
		$facade = $this->facade( true );
		$result = $facade->review( $this->candidate(), 'valid-nonce' );

		self::assertSame( PortabilityReviewResult::ADOPT, $result->action );
		self::assertSame( 1, count( $this->provider?->credential_ids ?? array() ) );

		$missing = $this->facade( false )->review( $this->candidate(), 'valid-nonce' );
		self::assertSame( PortabilityReviewResult::BLOCKED, $missing->action );
		self::assertSame( 'destination_conflict', $missing->reason );
	}

	public function test_review_authorization_fails_before_provider_access(): void {
		$facade = $this->facade( true, false, null, false );
		$result = $facade->review( $this->candidate(), 'valid-nonce' );

		self::assertSame( PortabilityReviewResult::BLOCKED, $result->action );
		self::assertSame( 'forbidden', $result->reason );
		self::assertSame( array(), $this->provider?->credential_ids );
	}

	public function test_apply_treats_only_an_exact_disabled_managed_target_as_verified(): void {
		$exact  = $this->managed_plugin( false );
		$facade = $this->facade( true, true, $exact );
		$review = $facade->review( $this->candidate(), 'valid-nonce' );
		$result = $facade->apply( $this->candidate(), $review->fingerprint, 'valid-nonce' );

		self::assertSame( PortabilityReviewResult::MANAGED, $review->action );
		self::assertSame( PortabilityApplyResult::UNCHANGED, $result->status );
		self::assertTrue( $result->target_verified );

		$manual = $this->facade( true, true, $this->managed_plugin( false, DeploymentPolicy::MANUAL ) )
			->review( $this->candidate(), 'valid-nonce' );
		self::assertSame( PortabilityReviewResult::PROTECTED, $manual->action );
	}

	public function test_apply_recomputes_and_rejects_a_changed_review(): void {
		$facade = $this->facade( true, true, $this->managed_plugin( false ) );
		$review = $facade->review( $this->candidate(), 'valid-nonce' );
		$result = $facade->apply(
			$this->candidate( array( 'branch' => 'develop' ) ),
			$review->fingerprint,
			'valid-nonce'
		);

		self::assertSame( PortabilityApplyResult::BLOCKED, $result->status );
		self::assertSame( 'review_changed', $result->reason );
		self::assertFalse( $result->target_verified );
	}

	public function test_provider_privacy_drift_cannot_claim_managed_verification(): void {
		$public     = $this->facade( true, true, $this->managed_plugin( false ) )
			->review( $this->candidate(), 'valid-nonce' );
		$is_private = $this->facade( true, true, $this->managed_plugin( true ), true, true )
			->review( $this->candidate( array( 'credential_id' => null ) ), 'valid-nonce' );

		self::assertSame( PortabilityReviewResult::MANAGED, $public->action );
		self::assertSame( PortabilityReviewResult::BLOCKED, $is_private->action );
		self::assertNotSame( $public->fingerprint, $is_private->fingerprint );
	}

	public function test_facade_surface_contains_no_persistence_or_source_cleanup_authority(): void {
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
		?Plugin $managed_package = null,
		bool $authorized = true,
		bool $provider_private = false
	): NativePortabilityFacade {
		$plugins = $this->createStub( PluginRepository::class );
		$themes  = $this->createStub( ThemeRepository::class );
		$plugins->method( 'is_installed' )->willReturn( $installed );
		$plugins->method( 'has_management_record' )->willReturn( $managed );
		if ( null !== $managed_package ) {
			$plugins->method( 'booster_plugin_from_file' )->willReturn( $managed_package );
		}
		$themes->method( 'is_installed' )->willReturn( false );
		$themes->method( 'has_management_record' )->willReturn( false );

		$catalog        = new ProviderSecretPolicyCatalog();
		$secrets        = new SecretsFile( null, array(), $catalog );
		$this->provider = new TemporaryCredentialProvider(
			$secrets->credentials_for( 'gh' ),
			0,
			'repository-id',
			$provider_private
		);
		$registry       = new ProviderRegistry( array( $this->provider ), $catalog );
		$service        = new PortabilityApplicationService(
			new BlueprintReviewer( $plugins, $themes ),
			new BlueprintRepositoryVerifier( $registry, $secrets ),
			( new ReflectionClass( PackageOperationService::class ) )->newInstanceWithoutConstructor(),
			$secrets
		);

		return new NativePortabilityFacade(
			application: $service,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The Portability authorization callback retains its production callable signature while this fixture selects a controlled result.
			can_manage: static fn ( string $type, bool $apply ): bool => $authorized,
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The Portability authorization callback retains its production callable signature while this fixture selects a controlled result.
			verify_nonce: static fn ( string $nonce, string $action ): bool => 'valid-nonce' === $nonce
		);
	}

	/** @param array<string, mixed> $overrides */
	private function candidate( array $overrides = array() ): PortabilityCandidate {
		return new PortabilityCandidate(
			...array_merge(
				array(
					'type'          => 'plugin',
					'identifier'    => 'example/example.php',
					'display_name'  => 'Example',
					'provider_code' => 'gh',
					'repository'    => 'owner/repository',
					'branch'        => 'main',
					'subdirectory'  => null,
					'credential_id' => null,
				),
				$overrides
			)
		);
	}

	private function managed_plugin(
		bool $is_private,
		DeploymentPolicy $policy = DeploymentPolicy::DISABLED
	): Plugin {
		$plugin = Plugin::from_wp_array(
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
		$plugin->set_repository( new ManagedRepository( 'gh', 'owner/repository', 'repository-id', 'main', $is_private ) );
		$plugin->set_deployment_policy( $policy );

		return $plugin;
	}
}
