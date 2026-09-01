<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/PackageViewWordPressFunctions.php';
require_once __DIR__ . '/../Support/DocumentationHookWordPressFunctions.php';
require_once __DIR__ . '/WebhookManagement/WordPressInstallationStoreWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminActionNormalizer.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/ProviderRepositoryRowsNormalizer.php';

use PHPUnit\Framework\TestCase;
use LogicException;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\ProviderRepositoryRowsNormalizer;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

final class ProviderRepositoryRowsNormalizerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_documentation_test_filters']                 = array();
		$GLOBALS['ran_booster_repository_webhook_management_test_options'] = array();
	}

	public function testProjectAppliesBoundedProviderEnrichmentBeforeNormalization(): void {
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][] = static function ( array $rows, string $providerCode, array $projections, string $returnUrl ): array {
			self::assertSame( 'gh', $providerCode );
			self::assertNotEmpty( $projections );
			self::assertSame( 'https://example.test/repositories', $returnUrl );
			$key                       = (string) array_key_first( $rows );
			$rows[ $key ]['details'][] = array(
				'label' => 'Release automation',
				'value' => 'Ready to assess',
				'tone'  => 'ok',
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
		self::assertSame( 'gh:release-automation', $row['actions']['gh:release-automation']['key'] );
	}

	public function testProjectRetainsCoreRowsWhenProviderRowExtensionThrows(): void {
		$GLOBALS['ran_booster_repository_webhook_management_test_options']['ran_booster_assisted_hooks_installations'] = array(
			'gh:101' => array(
				'schema_version'              => 3,
				'provider_code'               => 'gh',
				'repository_id'               => '101',
				'repository'                  => 'example/example',
				'hook_id'                     => '77',
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

		$result = $this->projectSingleRepositoryPage( $this->webhookManagementControls() );

		self::assertCount( 1, $result['repositoryTableRows'] );
		self::assertSame( 'example/example', $result['repositoryTableRows'][0]['repository'] );
		self::assertArrayHasKey( 'core:package-' . substr( hash( 'sha256', 'example/example.php' ), 0, 16 ), $result['repositoryTableRows'][0]['actions'] );
		self::assertSame( 'Recorded hook status', $result['repositoryTableRows'][0]['details'][0]['label'] );
		self::assertSame( 'Configured at last check', $result['repositoryTableRows'][0]['details'][0]['value'] );
	}

	public function testProjectRetainsCoreRowsWhenProviderRowEnrichmentIsInvalid(): void {
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][] = static fn(): string => 'invalid enrichment';

		$result = $this->projectSingleRepositoryPage();

		self::assertCount( 1, $result['repositoryTableRows'] );
		self::assertSame( 'example/example', $result['repositoryTableRows'][0]['repository'] );
		self::assertArrayHasKey( 'core:package-' . substr( hash( 'sha256', 'example/example.php' ), 0, 16 ), $result['repositoryTableRows'][0]['actions'] );
	}

	public function testReleaseWebhookCleanupLinkSelectsTheRetainedBranchPane(): void {
		$result = ( new ProviderRepositoryRowsNormalizer() )->project(
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
		$row    = $result['selected'];

		self::assertIsArray( $row );
		$action = $row['actions']['core:webhook-cleanup-review'];
		self::assertStringContainsString( 'source_view=branch', $action['url'] );
		self::assertStringContainsString( 'webhook_cleanup=1', $action['url'] );
		self::assertStringEndsWith( '#ran-booster-webhook-cleanup', $action['url'] );
	}

	public function testAllowsBundledManagementStateAndNamespacedHistoricalRows(): void {
		$base                              = $this->baseRows();
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
		self::assertSame( 'fixture:inspect', $rows['fixture:historical:abc123']['actions']['fixture:inspect']['key'] );
	}

	public function testRequiresEveryCoreRow(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'preserve every Core repository row' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $this->baseRows(), array(), 'gh' );
	}

	public function testRejectsCoreFieldRewrites(): void {
		$presented                          = $this->baseRows();
		$presented['repo-42']['repository'] = 'attacker/rewrite';

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'must not rewrite Core repository fields' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $this->baseRows(), $presented, 'gh' );
	}

	public function testRejectsNonHistoricalOrWrongProviderAppends(): void {
		$presented                      = $this->baseRows();
		$presented['fixture:extra-row'] = array(
			'provider_code' => 'bb',
			'historical'    => true,
			'actions'       => array(),
		);

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'namespaced historical rows' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $this->baseRows(), $presented, 'gh' );
	}

	public function testProjectsMixedSourcesAndKeepsWebhookConsumersBranchOnly(): void {
		$capturedProjections = array();
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][] = static function ( array $rows, string $providerCode, array $projections ) use ( &$capturedProjections ): array {
			$capturedProjections = $projections;

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
		self::assertSame( 'Mixed sources', $result['rows']['101']['source_label'] );
		self::assertSame( 'packages/plugin', $result['rows']['101']['package_summaries'][0]['subdirectory'] );
		self::assertSame( array( 'owner/plugin.php', 'owner-theme' ), $result['rows']['101']['package_references'] );
		self::assertSame( 'Automatic: 1', $result['rows']['101']['policies'][0]['label'] );
		self::assertSame( 'Manual: 1', $result['rows']['101']['policies'][1]['label'] );
		self::assertSame( array( 'owner/plugin.php' ), $capturedProjections['101']['package_references'] );
		self::assertTrue( $result['rows']['101']['has_branch_consumer'] );
		self::assertArrayNotHasKey( '202', $capturedProjections );
		self::assertFalse( $result['rows']['303']['historical'] );
		self::assertTrue( $result['rows'][ 'repository:' . hash( 'sha256', 'gh|owner/unresolved|branch' ) ]['historical'] );
		self::assertSame( array(), $result['rows'][ 'repository:' . hash( 'sha256', 'gh|owner/unresolved|branch' ) ]['actions'] );
		self::assertTrue( $result['rows']['404']['historical'] );
		self::assertSame( array(), $result['rows']['404']['actions'] );
		self::assertArrayNotHasKey( '404', $capturedProjections );
	}

	public function testRepositorySummaryUsesAutomaticBranchAggregateBeyondPackageSummaryCap(): void {
		$summaries = array();
		for ( $index = 1; $index <= 20; ++$index ) {
			$summaries[] = $this->summary( 'plugin', 'owner/manual-' . $index . '.php', 'Manual ' . $index, 'branch', 'main', '', 'manual' );
		}

		$result = ( new ProviderRepositoryRowsNormalizer() )->projectPage(
			array(
				'provider'                     => array(
					'code'           => 'gh',
					'label'          => 'GitHub',
					'owner_label'    => 'Owner',
					'capabilities'   => array( 'webhooks' => true ),
					'webhook_scopes' => array( array( 'code' => 'repository' ) ),
				),
				'providerTask'                 => 'repositories',
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

		self::assertCount( 20, $result['repositoryTableRows'][0]['package_summaries'] );
		self::assertSame( 1, $result['repositoryTableRows'][0]['package_summaries_omitted'] );
		self::assertTrue( $result['repositoryTableRows'][0]['has_automatic_branch_consumer'] );
		self::assertSame( 1, $result['repositoryIntegrationSummary']['needs_review'] );
	}

	public function testRejectsProviderRewriteOfImmutablePackageSummaries(): void {
		$base                                 = $this->baseRows();
		$base['repo-42']['package_summaries'] = array( $this->summary( 'plugin', 'example/example.php', 'Example', 'branch', 'main', '', 'manual' ) );
		$presented                            = $base;
		$presented['repo-42']['package_summaries'][0]['source'] = 'release_asset';

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'must not rewrite Core repository fields' );

		( new ProviderRepositoryRowsNormalizer() )->normalize( $base, $presented, 'gh' );
	}

	/** @return array<string, int|string> */
	private function summary( string $type, string $identifier, string $displayName, string $source, string $branch, string $subdirectory, string $policy ): array {
		return array(
			'type'              => $type,
			'identifier'        => $identifier,
			'display_name'      => $displayName,
			'settings_url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster-' . ( 'theme' === $type ? 'themes' : 'plugins' ) . '&package=' . rawurlencode( $identifier ),
			'source'            => $source,
			'source_revision'   => 1,
			'branch'            => $branch,
			'subdirectory'      => $subdirectory,
			'deployment_policy' => $policy,
		);
	}

	/** @return array<string,mixed> */
	private function projectSingleRepositoryPage( ?RepositoryWebhookManagementControls $webhookManagement = null ): array {
		return ( new ProviderRepositoryRowsNormalizer() )->projectPage(
			array(
				'provider'                     => array(
					'code'           => 'gh',
					'label'          => 'GitHub',
					'owner_label'    => 'Owner',
					'capabilities'   => array( 'webhooks' => true ),
					'webhook_scopes' => array( array( 'code' => 'repository' ) ),
				),
				'providerTask'                 => 'repositories',
				'provider_repositories'        => array(
					'available'    => true,
					'repositories' => array(
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
								'automatic' => 0,
								'manual'    => 1,
								'disabled'  => 0,
							),
							'reason_codes'          => array(),
							'local_secret_coverage' => 'repository',
						),
					),
				),
			),
			$webhookManagement
		);
	}

	private function webhookManagementControls(): RepositoryWebhookManagementControls {
		return new RepositoryWebhookManagementControls(
			$this->createMock( WebhookAssistanceFacade::class ),
			$this->createMock( AdminInteractionFacade::class ),
			new ManagedPackageWebhookAuthorityResolver( $this->createMock( PluginRepository::class ), $this->createMock( ThemeRepository::class ) ),
			new ProviderRegistry(),
			dirname( __DIR__, 2 ) . '/',
			'https://example.test/wp-content/plugins/ran-booster/'
		);
	}

	/** @return array<string, array<string, mixed>> */
	private function baseRows(): array {
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
