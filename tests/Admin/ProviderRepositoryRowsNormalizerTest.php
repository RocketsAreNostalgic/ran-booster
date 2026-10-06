<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once __DIR__ . '/../Support/PackageViewWordPressFunctions.php';
require_once __DIR__ . '/../Support/DocumentationHookWordPressFunctions.php';
require_once __DIR__ . '/WebhookManagement/WordPressInstallationStoreWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminActionNormalizer.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/ProviderRepositoryRowsNormalizer.php';

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\ProviderRepositoryRowsNormalizer;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

final class ProviderRepositoryRowsNormalizerTest extends TestCase {
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_documentation_test_filters']                 = array();
		$GLOBALS['ran_booster_repository_webhook_management_test_options'] = array();
		unset( $GLOBALS['ran_booster_package_view_multisite'] );
	}

	public function test_project_applies_bounded_provider_enrichment_before_normalization(): void {
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][] = static function ( array $rows, string $provider_code, array $projections, string $return_url ): array {
			self::assertSame( 'gh', $provider_code );
			self::assertNotEmpty( $projections );
			self::assertSame( 'https://example.test/repositories', $return_url );
			$key                       = (string) array_key_first( $rows );
			$rows[ $key ]['details'][] = array(
				'key'      => 'gh:release-automation-example',
				'label'    => 'Release automation',
				'value'    => 'Ready to assess',
				'tone'     => 'ok',
				'category' => 'release_workflow',
			);
			$rows[ $key ]['actions']['gh:release-automation'] = array(
				'key'           => 'gh:release-automation',
				'label'         => 'Release automation',
				'type'          => 'link',
				'url'           => 'https://example.test/package-settings',
				'hidden'        => array(),
				'disabled'      => false,
				'external'      => false,
				'described_by'  => '',
				'screen_reader' => 'example/example.php',
			);

			return $rows;
		};

		$result = ( new ProviderRepositoryRowsNormalizer() )->project(
			array(
				array(
					'target'               => 'example/example',
					'repository_id'        => '101',
					'source'               => 'branch',
					'package_references'   => array( 'example/example.php' ),
					'deployment_policies'  => array(
						'automatic' => 0,
						'manual'    => 1,
						'disabled'  => 0,
					),
					'automatic_count'      => 0,
					'repository_url'       => 'https://github.com/example/example',
					'webhook_settings_url' => null,
				),
			),
			'gh',
			'GitHub',
			'GitHub webhooks',
			'GitHub secret',
			'https://example.test/webhooks/gh',
			true,
			array(
				'by_id'         => array(
					'101' => array(
						'repository_id'         => '101',
						'eligible'              => true,
						'package_references'    => array( 'example/example.php' ),
						'deployment_policies'   => array(
							'automatic' => 0,
							'manual'    => 1,
							'disabled'  => 0,
						),
						'reason_codes'          => array(),
						'local_secret_coverage' => 'repository',
					),
				),
				'by_repository' => array(),
			),
			null,
			'',
			static fn ( array $arguments = array() ): string => 'https://example.test/provider?' . http_build_query( $arguments ),
			'https://example.test/repositories'
		);
		$row    = array_values( $result['rows'] )[0];

		self::assertSame( 'Ready to assess', $row['details'][0]['value'] );
		self::assertSame( 'release_workflow', $row['details'][0]['category'] );
		self::assertSame( 'gh:release-automation', $row['actions']['gh:release-automation']['key'] );
	}

	public function test_project_retains_core_and_webhook_rows_when_provider_row_extension_throws(): void {
		$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
			'gh:101' => array(
				'schema_version'              => 4,
				'provider_code'               => 'gh',
				'repository_id'               => '101',
				'repository'                  => 'example/example',
				'hook_id'                     => '77',
				'management_credential_id'    => 'credential_1',
				'webhook_profile_id'          => 'wh_0123456789abcdef01234567',
				'webhook_profile_scope'       => 'repository',
				'webhook_profile_revision'    => 1,
				'webhook_profile_disposition' => 'created',
				'endpoint'                    => 'https://hooks.example.test/webhook',
				'status'                      => 'configured',
				'created_at'                  => '2026-08-20T01:02:03Z',
				'checked_at'                  => '2026-08-20T01:02:03Z',
			),
		);
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][]                   = static function (): array {
			throw new \RuntimeException( 'Extension unavailable.' );
		};

		$result = $this->project_single_repository_page( $this->webhook_management_controls() );
		$row    = $result['repository_table_rows'][0];

		self::assertArrayHasKey( 'core:package-' . substr( hash( 'sha256', 'example/example.php' ), 0, 16 ), $row['actions'] );
		self::assertContains( 'Recorded hook status', array_column( $row['details'], 'label' ) );
		self::assertContains( 'Configured at last check', array_column( $row['details'], 'value' ) );
	}

	public function test_project_retains_webhook_evidence_when_provider_extension_removes_it(): void {
		$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
			'gh:101' => array(
				'schema_version'              => 4,
				'provider_code'               => 'gh',
				'repository_id'               => '101',
				'repository'                  => 'example/example',
				'hook_id'                     => '77',
				'management_credential_id'    => 'credential_1',
				'webhook_profile_id'          => 'wh_0123456789abcdef01234567',
				'webhook_profile_scope'       => 'repository',
				'webhook_profile_revision'    => 1,
				'webhook_profile_disposition' => 'created',
				'endpoint'                    => 'https://hooks.example.test/webhook',
				'status'                      => 'configured',
				'created_at'                  => '2026-08-20T01:02:03Z',
				'checked_at'                  => '2026-08-20T01:02:03Z',
			),
		);
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][]                   = static function ( array $rows ): array {
			$rows['101']['details'] = array();

			return $rows;
		};

		$result = $this->project_single_repository_page( $this->webhook_management_controls() );

		self::assertSame( 'core:webhook-recorded-status', $result['repository_table_rows'][0]['details'][0]['key'] );
		self::assertSame( 'Configured at last check', $result['repository_table_rows'][0]['details'][0]['value'] );
	}

	public function test_project_rejects_extension_forged_webhook_evidence(): void {
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][] = static function ( array $rows ): array {
			$rows['101']['details'][] = array(
				'key'      => 'core:webhook-recorded-status',
				'label'    => 'Recorded hook status',
				'value'    => 'Configured at last check',
				'recorded' => true,
				'state'    => 'configured',
			);

			return $rows;
		};

		$result = $this->project_single_repository_page( null, true );

		self::assertSame( array(), $result['repository_table_rows'][0]['details'] );
		self::assertSame( 0, $result['repository_integration_summary']['recorded_hooks'] );
		self::assertSame( 1, $result['repository_integration_summary']['needs_review'] );
	}

	public function test_project_retains_core_rows_when_provider_row_enrichment_is_invalid(): void {
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][] = static fn(): string => 'invalid enrichment';

		$result = $this->project_single_repository_page();

		self::assertCount( 1, $result['repository_table_rows'] );
		self::assertSame( 'example/example', $result['repository_table_rows'][0]['repository'] );
		self::assertArrayHasKey( 'core:package-' . substr( hash( 'sha256', 'example/example.php' ), 0, 16 ), $result['repository_table_rows'][0]['actions'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_release_webhook_cleanup_link_uses_repository_branch_management(): void {
		$GLOBALS['ran_booster_package_view_multisite'] = true;
		$result                                        = ( new ProviderRepositoryRowsNormalizer() )->project(
			array(
				array(
					'target'              => 'example/example',
					'repository_id'       => '101',
					'source'              => 'release_asset',
					'package_references'  => array( 'example/example.php' ),
					'deployment_policies' => array(
						'automatic' => 0,
						'manual'    => 1,
						'disabled'  => 0,
					),
					'automatic_count'     => 0,
					'repository_url'      => 'https://github.com/example/example',
					'retained_webhook'    => array( 'local_secret_coverage' => 'repository' ),
				),
			),
			'gh',
			'GitHub',
			'GitHub webhooks',
			'GitHub secret',
			'https://example.test/webhooks/gh',
			true,
			array(
				'by_id'         => array(),
				'by_repository' => array(),
			),
			null,
			'101',
			static fn ( array $arguments = array() ): string => 'https://example.test/provider?' . http_build_query( $arguments ),
			'https://example.test/repositories'
		);
		$row = $result['selected'];

		self::assertIsArray( $row );
		self::assertSame( 'Releases', $row['management_label'] );
		$action   = $row['actions']['core:webhook-cleanup-review'];
		$settings = $row['actions'][ 'core:package-' . substr( hash( 'sha256', 'example/example.php' ), 0, 16 ) ];
		self::assertStringContainsString( 'panel=repositories', $action['url'] );
		self::assertStringContainsString( 'repository=101', $action['url'] );
		self::assertStringContainsString( 'repository_view=branch', $action['url'] );
		self::assertStringEndsWith( '#ran-booster-repository-webhook-setup-heading', $action['url'] );
		self::assertSame( $row['consequence_id'], $action['described_by'] );
		self::assertStringStartsWith( 'https://example.test/wp-admin/network/admin.php?', $settings['url'] );
	}

	public function test_allows_bundled_management_state_and_namespaced_historical_rows(): void {
		$base                              = $this->base_rows();
		$presented                         = $base;
		$presented['repo-42']['details'][] = array(
			'label' => 'Remote hook',
			'value' => 'Configured',
		);
		$presented['repo-42']['actions']['core:webhook-management']['url']      = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&repository=repo-42';
		$presented['repo-42']['actions']['core:webhook-management']['disabled'] = false;
		$presented['fixture:historical:abc123']                                 = array(
			'provider_code'  => 'gh',
			'provider_label' => 'GitHub',
			'repository_id'  => 'old-42',
			'repository'     => 'owner/historical',
			'historical'     => true,
			'details'        => array(),
			'actions'        => array(
				'fixture:inspect' => array(
					'label' => 'Inspect',
					'type'  => 'link',
					'url'   => 'https://example.test/history/old-42',
				),
			),
		);

		$rows = ( new ProviderRepositoryRowsNormalizer() )->normalize( $base, $presented, 'gh' );

		self::assertFalse( $rows['repo-42']['actions']['core:webhook-management']['disabled'] );
		self::assertCount( 2, $rows['repo-42']['details'] );
		self::assertTrue( $rows['fixture:historical:abc123']['historical'] );
		self::assertArrayNotHasKey( 'review_url', $rows['fixture:historical:abc123'] );
		self::assertSame( 'fixture:inspect', $rows['fixture:historical:abc123']['actions']['fixture:inspect']['key'] );
	}

	public function test_preserves_unknown_add_on_detail_kind_metadata(): void {
		$base                         = $this->base_rows();
		$base['repo-42']['details'][] = array(
			'label' => 'Add-on detail',
			'value' => 'Observed',
			'kind'  => array( 'unbounded' => str_repeat( 'x', 128 ) ),
		);

		$rows = ( new ProviderRepositoryRowsNormalizer() )->normalize( $base, $base, 'gh' );

		self::assertSame( array( 'unbounded' => str_repeat( 'x', 128 ) ), $rows['repo-42']['details'][1]['kind'] );
	}

	public function test_rejects_historical_post_actions(): void {
		$presented                              = $this->base_rows();
		$presented['fixture:historical:abc123'] = array(
			'provider_code' => 'gh',
			'historical'    => true,
			'actions'       => array(
				'fixture:retry' => array(
					'label'  => 'Retry recorded hook',
					'type'   => 'post',
					'url'    => admin_url( 'admin-post.php' ),
					'hidden' => array(
						'action'   => 'fixture_retry_recorded_hook',
						'_wpnonce' => 'historical-nonce',
					),
				),
			),
		);

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Historical rows may contain link actions only.' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $this->base_rows(), $presented, 'gh' );
	}

	public function test_requires_every_core_row(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'preserve every Core repository row' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $this->base_rows(), array(), 'gh' );
	}

	public function test_rejects_core_field_rewrites(): void {
		$presented                          = $this->base_rows();
		$presented['repo-42']['repository'] = 'attacker/rewrite';

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'must not rewrite Core repository fields' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $this->base_rows(), $presented, 'gh' );
	}

	public function test_rejects_removal_of_core_integration_details(): void {
		$core_rows                         = $this->base_rows();
		$core_rows['repo-42']['details'][] = array(
			'key'      => 'core:release-workflow:example/example.php',
			'label'    => 'Release workflow',
			'value'    => 'Ready to assess',
			'tone'     => 'pending',
			'category' => 'release_workflow',
		);
		$presented                         = $core_rows;
		array_pop( $presented['repo-42']['details'] );

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'may append but not replace Core details' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $core_rows, $presented, 'gh' );
	}

	public function test_rejects_non_historical_or_wrong_provider_appends(): void {
		$presented                      = $this->base_rows();
		$presented['fixture:extra-row'] = array(
			'provider_code' => 'bb',
			'historical'    => true,
			'actions'       => array(),
		);

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'namespaced historical rows' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $this->base_rows(), $presented, 'gh' );
	}

	public function test_rejects_unknown_integration_detail_categories(): void {
		$presented                         = $this->base_rows();
		$presented['repo-42']['details'][] = array(
			'key'      => 'provider:unknown',
			'label'    => 'Nicht klassifiziert',
			'value'    => 'Unknown',
			'category' => 'provider_specific',
		);

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'detail categories are invalid' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $this->base_rows(), $presented, 'gh' );
	}

	public function test_projects_mixed_sources_and_keeps_webhook_consumers_branch_only(): void {
		$captured_projections = array();
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The repository-row filter retains the provider-code slot before the projections asserted by this fixture.
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][] = static function ( array $rows, string $provider_code, array $projections ) use ( &$captured_projections ): array {
			$captured_projections = $projections;

			return $rows;
		};

		$result = ( new ProviderRepositoryRowsNormalizer() )->project(
			array(
				array(
					'target'                    => 'owner/mixed',
					'repository_id'             => '101',
					'source'                    => 'mixed',
					'package_references'        => array( 'owner/plugin.php', 'owner-theme' ),
					'branch_package_references' => array( 'owner/plugin.php' ),
					'deployment_policies'       => array(
						'automatic' => 1,
						'manual'    => 1,
						'disabled'  => 0,
					),
					'package_summaries'         => array(
						$this->summary( 'plugin', 'owner/plugin.php', 'Plugin', 'branch', 'main', 'packages/plugin', 'automatic' ),
						$this->summary( 'theme', 'owner-theme', 'Theme', 'release_asset', '', '', 'manual' ),
					),
				),
				array(
					'target'              => 'owner/release',
					'repository_id'       => '202',
					'source'              => 'release_asset',
					'package_references'  => array( 'release-theme' ),
					'deployment_policies' => array(
						'automatic' => 0,
						'manual'    => 1,
						'disabled'  => 0,
					),
					'package_summaries'   => array( $this->summary( 'theme', 'release-theme', 'Release theme', 'release_asset', '', '', 'manual' ) ),
				),
				array(
					'target'              => 'owner/branch',
					'repository_id'       => '303',
					'source'              => 'branch',
					'package_references'  => array( 'branch/plugin.php' ),
					'deployment_policies' => array(
						'automatic' => 1,
						'manual'    => 0,
						'disabled'  => 0,
					),
					'package_summaries'   => array( $this->summary( 'plugin', 'branch/plugin.php', 'Branch plugin', 'branch', 'trunk', '', 'automatic' ) ),
				),
				array(
					'target'              => 'owner/unresolved',
					'repository_id'       => '',
					'source'              => 'branch',
					'historical'          => true,
					'package_references'  => array( 'unresolved/plugin.php' ),
					'deployment_policies' => array(
						'automatic' => 0,
						'manual'    => 1,
						'disabled'  => 0,
					),
					'package_summaries'   => array( $this->summary( 'plugin', 'unresolved/plugin.php', 'Unresolved', 'branch', 'main', '', 'manual' ) ),
				),
				array(
					'target'              => 'owner/conflict',
					'repository_id'       => '404',
					'source'              => 'branch',
					'package_references'  => array( 'conflict/plugin.php' ),
					'deployment_policies' => array(
						'automatic' => 1,
						'manual'    => 0,
						'disabled'  => 0,
					),
					'package_summaries'   => array( $this->summary( 'plugin', 'conflict/plugin.php', 'Conflict', 'branch', 'main', '', 'automatic' ) ),
				),
			),
			'gh',
			'GitHub',
			'GitHub webhooks',
			'GitHub secret',
			'https://example.test/webhooks/gh',
			true,
			array(
				'by_id'         => array(
					'101' => array(
						'repository_id'       => '101',
						'package_references'  => array( 'owner/plugin.php' ),
						'deployment_policies' => array(
							'automatic' => 1,
							'manual'    => 0,
							'disabled'  => 0,
						),
					),
					'404' => array(
						'repository_id' => '404',
						'reason_codes'  => array( 'repository_identity_conflict' ),
					),
				),
				'by_repository' => array(),
			),
			null,
			'',
			static fn ( array $arguments = array() ): string => 'https://example.test/provider?' . http_build_query( $arguments ),
			'https://example.test/repositories'
		);

		self::assertCount( 5, $result['rows'] );
		self::assertSame( 'mixed', $result['rows']['101']['source_key'] );
		self::assertSame( 'Conflicting sources', $result['rows']['101']['source_label'] );
		self::assertSame( 'Conflicting sources', $result['rows']['101']['statuses'][0]['label'] );
		self::assertSame( 'warning', $result['rows']['101']['statuses'][0]['tone'] );
		self::assertStringContainsString( 'Review the package settings', $result['rows']['101']['consequence'] );
		self::assertSame( 'packages/plugin', $result['rows']['101']['package_summaries'][0]['subdirectory'] );
		self::assertSame( array( 'owner/plugin.php', 'owner-theme' ), $result['rows']['101']['package_references'] );
		self::assertSame(
			array(
				array(
					'label'         => 'Plugin settings',
					'screen_reader' => 'owner/plugin.php',
				),
				array(
					'label'         => 'Theme settings',
					'screen_reader' => 'owner-theme',
				),
			),
			array_map(
				static fn ( array $action ): array => array(
					'label'         => $action['label'],
					'screen_reader' => $action['screen_reader'],
				),
				array_values( $result['rows']['101']['actions'] )
			)
		);
		self::assertSame( 'Automatic: 1', $result['rows']['101']['policies'][0]['label'] );
		self::assertSame( 'Manual: 1', $result['rows']['101']['policies'][1]['label'] );
		self::assertSame( array( 'owner/plugin.php' ), $captured_projections['101']['package_references'] );
		self::assertTrue( $result['rows']['101']['has_branch_consumer'] );
		self::assertArrayNotHasKey( '202', $captured_projections );
		self::assertFalse( $result['rows']['303']['historical'] );
		self::assertTrue( $result['rows'][ 'repository:' . hash( 'sha256', 'gh|owner/unresolved|branch' ) ]['historical'] );
		self::assertSame( array(), $result['rows'][ 'repository:' . hash( 'sha256', 'gh|owner/unresolved|branch' ) ]['actions'] );
		self::assertTrue( $result['rows']['404']['historical'] );
		self::assertSame( array(), $result['rows']['404']['actions'] );
		self::assertArrayNotHasKey( '404', $captured_projections );
	}

	public function test_project_page_builds_exact_repository_view_urls_and_local_release_summary(): void {
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][] = static function ( array $rows ): array {
			$rows['101']['details'][] = array(
				'key'            => 'gh:release-automation-release-plugin',
				'label'          => 'État du flux de publication',
				'value'          => 'Available from Branch',
				'tone'           => 'pending',
				'category'       => 'release_workflow',
				'review_summary' => true,
			);
			$rows['101']['details'][] = array(
				'key'      => 'gh:release-automation-earlier-release-plugin',
				'label'    => 'Historique',
				'value'    => 'Earlier warning',
				'tone'     => 'warning',
				'category' => 'release_workflow',
			);
			$rows['202']['details'][] = array(
				'key'            => 'gh:release-automation-branch-plugin',
				'label'          => 'Flusso di rilascio',
				'value'          => 'Unavailable',
				'tone'           => 'warning',
				'category'       => 'release_workflow',
				'review_summary' => true,
			);
			$rows['202']['details'][] = array(
				'key'   => 'custom:workflow-review',
				'label' => 'Custom workflow',
				'value' => 'Needs review',
				'kind'  => 'release_workflow',
				'tone'  => 'pending',
			);

			return $rows;
		};

		$result = ( new ProviderRepositoryRowsNormalizer() )->project_page(
			array(
				'provider'                => array(
					'code'           => 'gh',
					'label'          => 'GitHub',
					'capabilities'   => array(),
					'webhook_scopes' => array(),
				),
				'provider_task'           => 'repositories',
				'repository_view'         => 'releases',
				'requested_repository_id' => '101',
				'provider_repositories'   => array(
					'repositories' => array(
						array(
							'target'            => 'owner/release',
							'repository_id'     => '101',
							'source'            => 'release_asset',
							'package_summaries' => array( $this->summary( 'plugin', 'release/plugin.php', 'Release', 'release_asset', '', '', 'manual' ) ),
						),
						array(
							'target'            => 'owner/branch',
							'repository_id'     => '202',
							'source'            => 'branch',
							'package_summaries' => array( $this->summary( 'plugin', 'branch/plugin.php', 'Branch', 'branch', 'main', '', 'manual' ) ),
						),
						array(
							'target'            => 'owner/old',
							'repository_id'     => '303',
							'source'            => 'release_asset',
							'historical'        => true,
							'package_summaries' => array( $this->summary( 'plugin', 'old/plugin.php', 'Old', 'release_asset', '', '', 'manual' ) ),
						),
						array(
							'target'                    => 'owner/partial',
							'repository_id'             => '404',
							'source'                    => 'release_asset',
							'package_references'        => array( 'partial/first.php', 'partial/second.php' ),
							'package_summaries_omitted' => 1,
							'package_summaries'         => array( $this->summary( 'plugin', 'partial/plugin.php', 'Partial', 'release_asset', '', '', 'manual' ) ),
						),
					),
				),
			)
		);

		self::assertSame( 'releases', $result['repository_view'] );
		self::assertStringContainsString( 'panel=repositories&repository=101&repository_view=status', html_entity_decode( $result['repository_view_urls']['status'] ) );
		self::assertSame( 'admin.php?page=ran-booster&tab=gh&panel=repositories&repository=101&repository_view=branch', $result['repository_view_request_urls']['branch'] );
		self::assertSame( 3, $result['repository_integration_summary']['release_packages'] );
		self::assertSame( 2, $result['repository_integration_summary']['release_repositories'] );
		self::assertFalse( $result['repository_integration_summary']['release_totals_incomplete'] );
		self::assertTrue( $result['repository_integration_summary']['release_workflows_inventory_incomplete'] );
		self::assertSame( 3, $result['repository_integration_summary']['release_workflows_needing_review'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_repository_subtabs_use_network_admin_hrefs_but_keep_relative_htmx_requests_on_multisite(): void {
		$GLOBALS['ran_booster_package_view_multisite'] = true;
		$result                                        = ( new ProviderRepositoryRowsNormalizer() )->project_page(
			array(
				'provider'                => array(
					'code'           => 'gh',
					'label'          => 'GitHub',
					'capabilities'   => array(),
					'webhook_scopes' => array(),
				),
				'provider_task'           => 'repositories',
				'repository_view'         => 'branch',
				'requested_repository_id' => '101',
				'provider_repositories'   => array(
					'repositories' => array(
						array(
							'target'            => 'owner/repository',
							'repository_id'     => '101',
							'source'            => 'branch',
							'package_summaries' => array( $this->summary( 'plugin', 'plugin/plugin.php', 'Plugin', 'branch', 'main', '', 'manual' ) ),
						),
					),
				),
			)
		);

		self::assertStringStartsWith( 'https://example.test/wp-admin/network/admin.php?page=ran-booster&tab=gh&panel=repositories&repository=101&repository_view=status', html_entity_decode( $result['repository_view_urls']['status'] ) );
		self::assertSame( 'admin.php?page=ran-booster&tab=gh&panel=repositories&repository=101&repository_view=branch', $result['repository_view_request_urls']['branch'] );
	}

	public function test_repository_summary_uses_automatic_branch_aggregate_beyond_package_summary_cap(): void {
		$summaries = array();
		for ( $index = 1; $index <= 20; ++$index ) {
			$summaries[] = $this->summary( 'plugin', 'owner/manual-' . $index . '.php', 'Manual ' . $index, 'branch', 'main', '', 'manual' );
		}

		$result = ( new ProviderRepositoryRowsNormalizer() )->project_page(
			array(
				'provider'                     => array(
					'code'           => 'gh',
					'label'          => 'GitHub',
					'owner_label'    => 'Owner',
					'capabilities'   => array( 'webhooks' => true ),
					'webhook_scopes' => array( array( 'code' => 'repository' ) ),
				),
				'provider_task'                => 'repositories',
				'provider_repositories'        => array(
					'available'    => true,
					'repositories' => array(
						array(
							'target'                    => 'owner/capped',
							'repository_id'             => 'capped-42',
							'source'                    => 'branch',
							'package_references'        => array( 'owner/manual-1.php' ),
							'branch_package_references' => array( 'owner/manual-1.php' ),
							'has_automatic_branch_consumer' => true,
							'deployment_policies'       => array(
								'automatic' => 1,
								'manual'    => 20,
								'disabled'  => 0,
							),
							'package_summaries'         => $summaries,
							'package_summaries_omitted' => 1,
						),
					),
				),
				'managed_webhook_repositories' => array(
					'available'    => true,
					'repositories' => array(),
				),
				'webhook_assistance_readiness' => array(
					'site'         => array(
						'status'       => 'ready',
						'reason_codes' => array(),
						'callback_url' => 'https://example.test/webhook',
					),
					'repositories' => array(),
				),
			)
		);

		self::assertCount( 20, $result['repository_table_rows'][0]['package_summaries'] );
		self::assertSame( 1, $result['repository_table_rows'][0]['package_summaries_omitted'] );
		self::assertFalse( $result['repository_table_rows'][0]['has_automatic_branch_consumer'] );
		self::assertSame( 'Package inventory incomplete', $result['repository_table_rows'][0]['management_label'] );
		self::assertSame( array(), $result['repository_table_rows'][0]['actions'] );
		self::assertSame( 0, $result['repository_integration_summary']['needs_review'] );
	}

	public function test_rejects_provider_rewrite_of_immutable_package_summaries(): void {
		$base                                 = $this->base_rows();
		$base['repo-42']['package_summaries'] = array( $this->summary( 'plugin', 'example/example.php', 'Example', 'branch', 'main', '', 'manual' ) );
		$presented                            = $base;
		$presented['repo-42']['package_summaries'][0]['source'] = 'release_asset';

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'must not rewrite Core repository fields' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $base, $presented, 'gh' );
	}

	/** @return array<string, int|string> */
	private function summary( string $type, string $identifier, string $display_name, string $source, string $branch, string $subdirectory, string $policy ): array {
		return array(
			'type'              => $type,
			'identifier'        => $identifier,
			'display_name'      => $display_name,
			'settings_url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster-' . ( 'theme' === $type ? 'themes' : 'plugins' ) . '&package=' . rawurlencode( $identifier ),
			'source'            => $source,
			'source_revision'   => 1,
			'branch'            => $branch,
			'subdirectory'      => $subdirectory,
			'deployment_policy' => $policy,
		);
	}

	/** @return array<string,mixed> */
	private function project_single_repository_page( ?RepositoryWebhookManagementControls $webhook_management = null, bool $automatic = false ): array {
		return ( new ProviderRepositoryRowsNormalizer() )->project_page(
			array(
				'provider'                     => array(
					'code'           => 'gh',
					'label'          => 'GitHub',
					'owner_label'    => 'Owner',
					'capabilities'   => array( 'webhooks' => true ),
					'webhook_scopes' => array( array( 'code' => 'repository' ) ),
				),
				'provider_task'                => 'repositories',
				'provider_repositories'        => array(
					'available'    => true,
					'repositories' => array(
						array(
							'target'               => 'example/example',
							'repository_id'        => '101',
							'source'               => 'branch',
							'package_references'   => array( 'example/example.php' ),
							'deployment_policies'  => array(
								'automatic' => $automatic ? 1 : 0,
								'manual'    => $automatic ? 0 : 1,
								'disabled'  => 0,
							),
							'automatic_count'      => $automatic ? 1 : 0,
							'has_automatic_branch_consumer' => $automatic,
							'repository_url'       => 'https://github.com/example/example',
							'webhook_settings_url' => null,
						),
					),
				),
				'managed_webhook_repositories' => array(
					'available'    => true,
					'repositories' => array(),
				),
				'webhook_assistance_readiness' => array(
					'site'         => array(
						'status'       => 'ready',
						'reason_codes' => array(),
					),
					'repositories' => array(
						array(
							'repository_id'         => '101',
							'eligible'              => true,
							'package_references'    => array( 'example/example.php' ),
							'deployment_policies'   => array(
								'automatic' => $automatic ? 1 : 0,
								'manual'    => $automatic ? 0 : 1,
								'disabled'  => 0,
							),
							'reason_codes'          => array(),
							'local_secret_coverage' => 'repository',
						),
					),
				),
			),
			$webhook_management
		);
	}

	private function webhook_management_controls(): RepositoryWebhookManagementControls {
		return new RepositoryWebhookManagementControls(
			$this->createMock( WebhookAssistanceFacade::class ),
			$this->createMock( AdminInteractionFacade::class ),
			new ProviderRegistry(),
			dirname( __DIR__, 2 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/',
			new ManagedPackageWebhookAuthorityResolver(
				$this->createMock( PluginRepository::class ),
				$this->createMock( ThemeRepository::class )
			)
		);
	}

	/** @return array<string, array<string, mixed>> */
	private function base_rows(): array {
		return array(
			'repo-42' => array(
				'key'           => 'repo-42',
				'provider_code' => 'gh',
				'repository_id' => 'repo-42',
				'repository'    => 'owner/example',
				'historical'    => false,
				'details'       => array(
					array(
						'label' => 'Packages',
						'value' => '1',
					),
				),
				'actions'       => array(
					'core:webhook-management' => array(
						'label'        => 'Manage webhook',
						'type'         => 'link',
						'url'          => '',
						'disabled'     => true,
						'external'     => false,
						'described_by' => 'webhook-management-reason',
					),
					'core:settings'           => array(
						'label' => 'Plugin settings',
						'type'  => 'link',
						'url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=example%2Fexample.php',
					),
				),
			),
		);
	}
}
