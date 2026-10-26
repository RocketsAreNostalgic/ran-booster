<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

// The pinned published plugin proves a physically separate extension layout.

require_once dirname( __DIR__ ) . '/Support/ExternalFixturePluginWordPressFunctions.php';
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
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
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
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV4;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\Secrets\SecretsFile;
use RuntimeException;

final class GitHubExternalExtensionParityTest extends TestCase {
	private const ARTIFACT_LIMIT = 67_108_864;

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_v4_status_ignores_retired_history_and_rejects_old_setup_records_without_rewriting(): void {
		require dirname( __DIR__ ) . '/Runtime/Support/GitHubWorkflowAssistanceWordPressFunctions.php';
		$provider = GitHubProvider::create( new Phase5CredentialStore(), new Phase5DeliveryEvidenceReader(), new Phase5BundledReleaseUpdater() );
		self::assertInstanceOf( RepositoryReleaseWorkflowManagementV4::class, $provider );
		$target  = new RepositoryReleaseWorkflowTarget( 'plugin', 'example/example.php', 3, '123456789' );
		$current = array(
			'operation'             => 'inspect',
			'outcome_code'          => 'workflow_preflight_failed',
			'failure_stage'         => 'release_preflight',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example/example.php',
			'source_revision'       => 3,
			'repository_id'         => '123456789',
			'diagnostic_code'       => 'provider_unavailable',
			'diagnostic_available'  => false,
			'correlation_reference' => str_repeat( 'a', 32 ),
			'recorded_at'           => '2026-09-29T12:00:00Z',
		);
		$option  = 'ran_booster_github_provider_release_workflow_failure_history';
		$GLOBALS['ran_booster_release_deployments_test_options'] = array( $option => array( $current ) );
		self::assertSame( array(), $provider->workflow_status( $target )->failure_history() );
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_github_provider_release_workflow_setup_records'] = array(
			'123456789' => array(
				'schema_version' => 2,
				'operation'      => 'template_update',
			),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON encoding preserves the exact fixture payload or secret-containment evidence without a WordPress runtime.
		$before = json_encode( $GLOBALS['ran_booster_release_deployments_test_options'], JSON_THROW_ON_ERROR );
		$status = $provider->workflow_status( $target );
		self::assertTrue( $status->record_occupied() );
		self::assertFalse( $status->record_exact() );
		self::assertSame( '', $status->record_operation() );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON encoding preserves the exact fixture payload or secret-containment evidence without a WordPress runtime.
		self::assertSame( $before, json_encode( $GLOBALS['ran_booster_release_deployments_test_options'], JSON_THROW_ON_ERROR ) );
		foreach ( array( 'update_inspect', 'update_setup' ) as $operation ) {
			$obsolete              = $current;
			$obsolete['operation'] = $operation;
			foreach ( array( array( $obsolete ), array( $current, $obsolete ) ) as $history ) {
				$GLOBALS['ran_booster_release_deployments_test_options'] = array( $option => $history );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON encoding preserves the exact fixture payload or secret-containment evidence without a WordPress runtime.
				$before = json_encode( $GLOBALS['ran_booster_release_deployments_test_options'], JSON_THROW_ON_ERROR );
				self::assertSame( array(), $provider->workflow_status( $target )->failure_history() );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON encoding preserves the exact fixture payload or secret-containment evidence without a WordPress runtime.
				self::assertSame( $before, json_encode( $GLOBALS['ran_booster_release_deployments_test_options'], JSON_THROW_ON_ERROR ) );
			}
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_actual_provider_preserves_core_outcome_admission_for_stale_bootstrap_but_rejects_retired_records(): void {
		require_once dirname( __DIR__ ) . '/Runtime/Support/GitHubWorkflowAssistanceWordPressFunctions.php';
		require_once dirname( __DIR__ ) . '/Admin/ReleaseManagement/Support/ReleaseManagementWordPressFunctions.php';
		require_once dirname( __DIR__ ) . '/Admin/ReleaseManagement/Support/ReleaseManagementFixtures.php';
		require_once dirname( __DIR__ ) . '/Admin/ReleaseManagement/Support/ReleaseTrackingFacadeDouble.php';
		require_once dirname( __DIR__ ) . '/Admin/ReleaseManagement/GitHub/Support/PluginRepositoryDouble.php';
		require_once dirname( __DIR__ ) . '/Admin/ReleaseManagement/GitHub/Support/ThemeRepositoryDouble.php';

		$provider = GitHubProvider::create( new Phase5CredentialStore(), new Phase5DeliveryEvidenceReader(), new Phase5BundledReleaseUpdater() );
		self::assertInstanceOf( \RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV4::class, $provider );
		$status     = \RAN\Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture::status();
		$tracking   = new \RAN\Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble( $status );
		$plugins    = new \RAN\Tests\Admin\ReleaseManagement\GitHub\Support\PluginRepositoryDouble();
		$themes     = new \RAN\Tests\Admin\ReleaseManagement\GitHub\Support\ThemeRepositoryDouble();
		$registry   = new ProviderRegistry( array( $provider ) );
		$controller = new \RAN\Admin\ReleaseManagement\ReleaseWorkflowRequestController( $tracking, $plugins, $themes, $registry, new \RAN\Storage\RepositorySourceGuard() );
		$presenter  = new \RAN\Admin\ReleaseManagement\ReleaseWorkflowPresenter( $tracking, $plugins, $themes, $registry, $controller );
		$target     = new RepositoryReleaseWorkflowTarget( 'plugin', 'example/example.php', 3, '101' );
		$record     = array(
			'schema_version'        => 3,
			'operation'             => 'bootstrap',
			'repo_id'               => '101',
			'repository'            => 'RocketsAreNostalgic/example-plugin',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example/example.php',
			'source_revision'       => 2,
			'default_branch'        => 'main',
			'base_sha'              => str_repeat( 'a', 40 ),
			'setup_branch'          => 'ran-booster/release-setup-v3-aaaaaaaaaaaa-deadbeef',
			'head_sha'              => str_repeat( 'b', 40 ),
			'pr_number'             => 42,
			'profile_id'            => 'source-ready-wordpress-plugin/3',
			'template_repo_name'    => 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates',
			'template_repo_id'      => '1322743261',
			'template_release_id'   => 41,
			'template_tag'          => 'v1.2.3',
			'template_commit'       => str_repeat( 'c', 40 ),
			'template_asset_id'     => 73,
			'template_asset_name'   => 'ran-booster-release-bootstrap-templates.zip',
			'template_asset_size'   => 1000,
			'template_asset_digest' => str_repeat( 'd', 64 ),
			'manifest_digest'       => str_repeat( 'e', 64 ),
			'changed_files'         => array(
				array(
					'path'   => 'version.txt',
					'status' => 'added',
					'sha'    => str_repeat( 'f', 40 ),
				),
			),
			'consumer_api'          => 3,
			'pack_version'          => '1.2.3',
			'bundle_hash'           => str_repeat( '1', 64 ),
			'changed_path_hash'     => str_repeat( '2', 64 ),
		);
		foreach ( array( 'current_bootstrap', 'retired_bootstrap', 'retired_update' ) as $case ) {
			$stored = $record;
			if ( 'current_bootstrap' !== $case ) {
				$stored['schema_version'] = 2;
				$stored['operation']      = 'retired_update' === $case ? 'template_update' : 'bootstrap';
			}
			$GLOBALS['ran_booster_release_deployments_test_options'] = array(
				'ran_booster_github_provider_release_workflow_setup_records' => array( '101' => $stored ),
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON encoding preserves the exact fixture payload or secret-containment evidence without a WordPress runtime.
			$before = json_encode( $GLOBALS['ran_booster_release_deployments_test_options'], JSON_THROW_ON_ERROR );
			$actual = $provider->workflow_status( $target );
			self::assertTrue( $actual->record_occupied(), $case );
			self::assertFalse( $actual->record_exact(), $case );
			foreach ( array( $controller, $presenter ) as $consumer ) {
				$admission = new \ReflectionMethod( $consumer, 'record_matches_package_status' );
				self::assertSame( 'current_bootstrap' === $case, $admission->invoke( $consumer, $actual, $status ), $case . ': ' . $consumer::class );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON encoding preserves the exact fixture payload or secret-containment evidence without a WordPress runtime.
			self::assertSame( $before, json_encode( $GLOBALS['ran_booster_release_deployments_test_options'], JSON_THROW_ON_ERROR ), $case );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_released_git_hub_package_composes_as_aphysically_separate_external_plugin(): void {
		define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 15 );
		define( 'RAN_BOOSTER_RUNTIME_MODE', 'single_site_supported' );
		$GLOBALS['ran_booster_external_fixture_actions'] = array();

		require_once __DIR__ . '/Support/GitHubExampleHttpWordPressFunctions.php';
		$extension = $this->published_example();
		$this->load_generic_fixture();
		require $extension . '/ran-booster-github-example.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact passive manifest from the checksum-verified published plugin.
		$manifest      = json_decode( (string) file_get_contents( $extension . '/ran-booster-github-example.gh-replacement.json' ), true, 8, JSON_THROW_ON_ERROR );
		$declaration   = new GitHubReplacementDeclaration( $manifest );
		$approval      = new \ArrayObject( array( 'approved' => false ) );
		$disclosures   = 0;
		$limit_reads   = 0;
		$guard         = static function () use ( $approval, $declaration ): ?array {
			return $approval['approved'] ? array(
				'provider_code'              => 'gh',
				'selection_generation'       => 1,
				'implementation_fingerprint' => $declaration->implementation_fingerprint,
				'plugin_basename'            => $declaration->plugin_basename,
			) : null;
		};
		$make_registry = static function () use ( &$disclosures, &$limit_reads, $guard, $declaration ): ProviderRegistry {
			return new ProviderRegistry(
				credential_store_factory: static function ( ProviderCode $code ) use ( &$disclosures ): ProviderCredentialStore {
					if ( 'gh' === $code->value ) {
						++$disclosures;
					}
					return new Phase5CredentialStore();
				},
				delivery_evidence_reader_factory: static fn (): AuthenticatedWebhookDeliveryEvidenceReader => new Phase5DeliveryEvidenceReader(),
				registration_context: new ProviderRegistrationContext(
					static function () use ( &$limit_reads ): int {
						++$limit_reads;
						return self::ARTIFACT_LIMIT;
					}
				),
				github_selection_guard: $guard,
				github_candidates: array( $declaration->plugin_basename => $declaration )
			);
		};
		$callbacks     = $GLOBALS['ran_booster_external_fixture_actions']['ran_booster_register_providers'] ?? array();
		self::assertCount( 2, $callbacks );
		self::assertSame( array( 'RAN\\BoosterGitHubExample\\Plugin', 'declare' ), $callbacks[1] );
		$registry = $make_registry();
		foreach ( $callbacks as $callback ) {
			$callback( $registry );
		}
		$registry->seal();
		self::assertSame( 0, $disclosures );
		self::assertSame( 0, $limit_reads );
		self::assertFalse( class_exists( GitHubProvider::class, false ), 'Passive declaration must not load the provider.' );
		$approval['approved'] = true;
		$registry             = $make_registry();
		foreach ( $callbacks as $callback ) {
			$callback( $registry );
		}
		$registry->seal();
		self::assertSame( array( 'fixture-provider', 'gh' ), array_keys( $registry->all() ) );
		$provider = $registry->get( 'gh' );
		self::assertInstanceOf( GitHubProvider::class, $provider );
		self::assertSame( 1, $disclosures );
		self::assertSame( 0, $limit_reads );
		$source = ( new \ReflectionClass( $provider ) )->getFileName();
		self::assertIsString( $source );
		self::assertStringStartsWith( $extension . '/vendor/ran/booster-github-provider/src/', str_replace( '\\', '/', $source ) );

		$metadata = $provider->get_metadata();
		self::assertSame( 'gh', $metadata->code->value );
		self::assertSame( 'GitHub', $metadata->label );
		self::assertSame( 'https://github.com/', $metadata->repository_url_base );

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
				RepositoryReleaseWorkflowManagementV4::class,
			) as $capability
		) {
			self::assertSame( $provider, $registry->require_capability( 'gh', $capability ), $capability );
		}

		$this->prove_anonymous_public_browse( $registry );
		$this->prove_archive_composition( $provider );
		$this->prove_external_release_limit( $provider, $extension );
		$this->assert_no_private_booster_imports( $extension );
		$this->assert_self_update_is_provider_independent();
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_bundled_git_hub_passes_the_same_configured_limit_to_release_operations(): void {
		define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', self::ARTIFACT_LIMIT );

		$updater   = new Phase5BundledReleaseUpdater();
		$container = new CoreContainer();
		$runtime   = new Booster( $container );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core composition requires the WordPress table prefix.
		$GLOBALS['wpdb'] = new \RAN\Tests\RepositoryProvider\HostSelectionDatabase();

		( new BoosterServiceProvider(
			static fn ( ProviderSecretPolicyCatalog $policies, callable $guard ): SecretsFile => new Phase5SecretsFile( $policies, $guard )
		) )->register( $container, $runtime, $updater, $GLOBALS['wpdb']->basename );

		$container->make( \RAN\RepositoryProvider\GitHubProviderSelection::class )->complete_collection();
		$container->make( ProviderRegistry::class )->seal();
		$provider = $container->make( ProviderRegistry::class )->get( 'gh' );
		try {
			$provider->list_release_candidates(
				'plugin',
				new RepositoryReference( 'RocketsAreNostalgic/example-plugin', '987654321', false, null ),
				'stable'
			);
			self::fail( 'The inert recorder must not produce a release listing.' );
		} catch ( RuntimeException ) {
			self::assertNotNull( $updater->release_arguments );
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
			$updater->release_arguments
		);
	}

	public function test_published_example_declares_native_dependency_and_uses_only_public_composition_surfaces(): void {
		$root = $this->published_example();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect checksum-verified published plugin bytes without modifying or substituting its autoloader.
		$entrypoint = file_get_contents( $root . '/ran-booster-github-example.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the published public composition root as source data.
		$plugin = file_get_contents( $root . '/src/Plugin.php' );
		self::assertIsString( $entrypoint );
		self::assertIsString( $plugin );
		self::assertStringContainsString( 'Requires Plugins: ran-booster', $entrypoint );
		self::assertStringContainsString( 'vendor/ran/wp-release-updater/bootstrap.php', $plugin );
		self::assertStringContainsString( 'declare_github_replacement( new GitHubReplacementDeclaration( $manifest ), self::create( ... ) )', $plugin );
		self::assertStringContainsString( 'ProviderRegistrationContext $context', $plugin );
		self::assertStringContainsString( '$context->maximum_artifact_bytes( ... )', $plugin );
		self::assertStringContainsString( '$context->operation_allowed( ... )', $plugin );
		self::assertStringContainsString( '$context->begin_native_mutation( ... )', $plugin );
		self::assertStringContainsString( '$context->complete_native_mutation( ... )', $plugin );
		self::assertFileExists( $root . '/vendor/composer/autoload_real.php' );
		$this->assert_no_private_booster_imports( $root );
	}

	private function prove_anonymous_public_browse( ProviderRegistry $registry ): void {
		ran_booster_example_http_queue(
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

		$browser = $registry->require_capability( 'gh', CredentialedPublicRepositoryBrowser::class );
		$result  = $browser->browse_repositories( RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic' ) );

		self::assertCount( 1, $result->repositories );
		self::assertSame( 'RocketsAreNostalgic/ran-booster', $result->repositories[0]->locator );
		foreach ( ran_booster_example_http_requests() as $request ) {
			self::assertArrayNotHasKey( 'Authorization', $request['arguments']['headers'] );
		}
	}

	private function prove_archive_composition( object $provider ): void {
		self::assertInstanceOf( \RAN\RepositoryProvider\RepositoryProvider::class, $provider );
		$commit = '0123456789abcdef0123456789abcdef01234567';
		ran_booster_example_http_queue(
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

		$archive = $provider->prepare_archive(
			new ArchiveRequest(
				new RepositoryReference( 'RocketsAreNostalgic/example-plugin', '987654321', false, null ),
				'main'
			)
		);

		self::assertSame( $commit, $archive->get_resolved_ref() );
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/zipball/' . $commit,
			$archive->get_url()
		);
		$archive->cleanup();
	}

	private function prove_external_release_limit( object $provider, string $extension ): void {
		self::assertInstanceOf( RepositoryReleaseInspector::class, $provider );
		$registrar = ( new \ReflectionProperty( GitHubProvider::class, 'registrar' ) )->getValue( $provider );
		self::assertIsObject( $registrar );
		$source = ( new \ReflectionClass( $registrar ) )->getFileName();
		self::assertIsString( $source );
		self::assertSame( $extension . '/vendor/ran/wp-release-updater/bootstrap.php', str_replace( '\\', '/', $source ) );
		$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
		self::assertInstanceOf( \RAN\WPReleaseUpdater\V1\Runtime\RequestBroker::class, $broker );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Activate the real release runtime with an explicit synthetic WordPress version; no installed site is loaded.
		$GLOBALS['wp_version'] = '7.0.0';
		self::assertSame(
			'runtime_active',
			$broker->activate(
				array(
					'php_version'       => PHP_VERSION,
					'runtime_protocol'  => 6,
					'wordpress_version' => '7.0.0',
				)
			)['code']
		);
		foreach ( array( self::ARTIFACT_LIMIT, self::ARTIFACT_LIMIT + 1 ) as $size ) {
			ran_booster_example_http_queue(
				array(
					$this->response(
						array(
							'id'        => 987654321,
							'full_name' => 'RocketsAreNostalgic/example-plugin',
						)
					),
					$this->response(
						array(
							'id'         => 41,
							'tag_name'   => 'v1.0.0',
							'draft'      => false,
							'prerelease' => false,
							'immutable'  => true,
							'html_url'   => 'https://github.com/RocketsAreNostalgic/example-plugin/releases/tag/v1.0.0',
							'assets'     => array(
								array(
									'id'     => 42,
									'name'   => 'example-plugin-1.0.0.zip',
									'size'   => $size,
									'state'  => 'uploaded',
									'digest' => 'sha256:' . str_repeat( 'a', 64 ),
								),
							),
						)
					),
					array(
						'response' => array( 'code' => 500 ),
						'body'     => '{}',
					),
				)
			);
			try {
				$provider->inspect_release( 'plugin', new RepositoryReference( 'RocketsAreNostalgic/example-plugin', '987654321', false, null ), '41', 'v1.0.0', 'stable' );
				self::fail( 'The controlled commit read must stop inspection before any artifact download.' );
			} catch ( RepositoryReleaseInspectionRejected $failure ) {
				self::assertSame( self::ARTIFACT_LIMIT + 1, $size );
				self::assertSame( RepositoryReleaseInspectionRejected::INCOMPATIBLE, $failure->reason );
			} catch ( RuntimeException $failure ) {
				self::assertSame( self::ARTIFACT_LIMIT, $size );
				self::assertSame( 'GitHub could not inspect the selected release.', $failure->getMessage() );
			}
			$requests = ran_booster_example_http_requests();
			self::assertCount( self::ARTIFACT_LIMIT === $size ? 3 : 2, $requests );
			self::assertSame( 'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/releases/41', $requests[1]['url'] );
			if ( self::ARTIFACT_LIMIT === $size ) {
				// The real registrar accepted the nondefault 64-MiB boundary and reached
				// the next HTTP stage; default 50-MiB policy would reject this metadata.
				self::assertSame( 'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/commits/v1.0.0', $requests[2]['url'] );
			}
		}
	}

	private function assert_no_private_booster_imports( string $extension ): void {
		$this->assert_no_forbidden_namespace_text( $extension . '/src' );
		$this->assert_no_forbidden_namespace_text( $extension . '/vendor/ran/booster-github-provider/src' );
	}

	private function assert_no_forbidden_namespace_text( string $directory ): void {
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local fixture or source bytes whose exact contents this test verifies.
			$source = file_get_contents( $file->getPathname() );
			self::assertIsString( $source );
			foreach ( $forbidden as $namespace ) {
				self::assertStringNotContainsString( $namespace, $source, $file->getPathname() );
			}
		}
	}

	private function assert_self_update_is_provider_independent(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local fixture or source bytes whose exact contents this test verifies.
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );
		self::assertIsString( $bootstrap );
		$start = strpos( $bootstrap, 'if ( $ran_booster_self_update_policy->allows_native_discovery() )' );
		self::assertIsInt( $start );
		$end = strpos( $bootstrap, '$ran_booster_container->bind(', $start );
		self::assertIsInt( $end );
		$self_update = substr( $bootstrap, $start, $end - $start );

		self::assertStringContainsString( 'ManagedReleaseUpdaterRegistrar::class )->plugin(', $self_update );
		self::assertStringContainsString( 'new CoreSelfUpdateNativeTarget( $core_updater )', $self_update );
		self::assertStringNotContainsString( 'ProviderRegistry', $self_update );
		self::assertStringNotContainsString( 'GitHubProvider', $self_update );
		self::assertStringNotContainsString( 'require_capability', $self_update );
	}

	private function load_generic_fixture(): void {
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-provider/ran-booster-fixture-provider.php';
	}

	private function published_example(): string {
		$root = dirname( __DIR__, 2 ) . '/vendor/.test-fixtures/ran-booster-github-example';
		self::assertFileExists( $root . '/ran-booster-github-example.php', 'Prepare the pinned published Example with scripts/prepare-github-example-fixture.py.' );
		self::assertSame( $root, realpath( $root ) );
		return $root;
	}

	/**
	 * @param array<string, mixed>|list<array<string, mixed>> $body
	 * @return array{response: array{code: int}, body: string}
	 */
	private function response( array $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON encoding preserves the exact fixture payload or secret-containment evidence without a WordPress runtime.
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class Phase5CredentialStore implements ProviderCredentialStore {
	public function credential_profiles(): array {
		return array();
	}

	public function credential_material( ?string $id = null ): ?array {
		unset( $id );

		return null;
	}

	public function has_webhook_profile(): bool {
		return false;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class Phase5DeliveryEvidenceReader implements AuthenticatedWebhookDeliveryEvidenceReader {
	public function latest_authenticated_delivery(): ?AuthenticatedWebhookDeliveryEvidence {
		return null;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class Phase5BundledReleaseUpdater {
	/** @var array<array-key, mixed>|null */
	public ?array $release_arguments = null;

	public function with_operation_guard( callable $guard ): self {
		unset( $guard );
		return $this;
	}
	public function with_native_mutation_callbacks( callable $begin, callable $complete ): self {
		unset( $begin, $complete );
		return $this;
	}
	public function releases( mixed ...$arguments ): object {
		$this->release_arguments = $arguments;

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

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- This concrete test collaborator stays beside the contract it exercises.
final class Phase5SecretsFile extends SecretsFile {
	private Phase5CredentialStore $credentials;

	public function __construct( ProviderSecretPolicyCatalog $policies, callable $guard ) {
		parent::__construct( '/unused/phase5-github-extension-secrets.php', array(), $policies, github_credential_access: $guard );
		$this->credentials = new Phase5CredentialStore();
	}

	public function credentials_for( ProviderCode|string $provider ): ProviderCredentialStore {
		unset( $provider );

		return $this->credentials;
	}
}
