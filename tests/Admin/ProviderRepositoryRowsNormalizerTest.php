<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/PackageViewWordPressFunctions.php';
require_once __DIR__ . '/../Support/DocumentationHookWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminActionNormalizer.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/ProviderRepositoryRowsNormalizer.php';

use LogicException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderRepositoryRowsNormalizer;

final class ProviderRepositoryRowsNormalizerTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_documentation_test_filters'] = array();
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

	public function testReleaseWebhookCleanupLinkUsesRepositoryBranchManagement(): void {
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
		self::assertStringContainsString( 'panel=repositories', $action['url'] );
		self::assertStringContainsString( 'repository=101', $action['url'] );
		self::assertStringContainsString( 'repository_view=branch', $action['url'] );
		self::assertStringEndsWith( '#ran-booster-repository-webhook-setup-heading', $action['url'] );
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

	public function testProjectPageBuildsExactRepositoryViewUrlsAndLocalReleaseSummary(): void {
		$GLOBALS['ran_booster_documentation_test_filters']['ran_booster_provider_repository_rows'][] = static function ( array $rows ): array {
			$rows['101']['details'][] = array(
				'key'   => 'gh:release-automation-aaaaaaaaaaaaaaaa',
				'label' => 'Release automation',
				'value' => 'Available from Branch',
				'tone'  => 'pending',
			);
			$rows['202']['details'][] = array(
				'key'   => 'gh:release-automation-bbbbbbbbbbbbbbbb',
				'label' => 'Release automation',
				'value' => 'Unavailable',
				'tone'  => 'warning',
			);

			return $rows;
		};

		$result = ( new ProviderRepositoryRowsNormalizer() )->projectPage(
			array(
				'provider'              => array(
					'code'           => 'gh',
					'label'          => 'GitHub',
					'capabilities'   => array(),
					'webhook_scopes' => array(),
				),
				'providerTask'          => 'repositories',
				'repositoryView'        => 'releases',
				'requestedRepositoryId' => '101',
				'provider_repositories' => array(
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
							'package_summaries_omitted' => 1,
							'package_summaries'         => array( $this->summary( 'plugin', 'partial/plugin.php', 'Partial', 'release_asset', '', '', 'manual' ) ),
						),
					),
				),
			)
		);

		self::assertSame( 'releases', $result['repositoryView'] );
		self::assertStringContainsString( 'panel=repositories&repository=101&repository_view=status', html_entity_decode( $result['repositoryViewUrls']['status'] ) );
		self::assertSame( 'admin.php?page=ran-booster&tab=gh&panel=repositories&repository=101&repository_view=branch', $result['repositoryViewRequestUrls']['branch'] );
		self::assertSame( 1, $result['repositoryIntegrationSummary']['release_packages'] );
		self::assertSame( 1, $result['repositoryIntegrationSummary']['release_repositories'] );
		self::assertSame( 2, $result['repositoryIntegrationSummary']['release_workflows_needing_review'] );
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
		self::assertFalse( $result['repositoryTableRows'][0]['has_automatic_branch_consumer'] );
		self::assertSame( 'Package inventory incomplete', $result['repositoryTableRows'][0]['management_label'] );
		self::assertSame( array(), $result['repositoryTableRows'][0]['actions'] );
		self::assertSame( 0, $result['repositoryIntegrationSummary']['needs_review'] );
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
