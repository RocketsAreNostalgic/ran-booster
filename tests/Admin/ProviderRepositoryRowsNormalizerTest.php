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
