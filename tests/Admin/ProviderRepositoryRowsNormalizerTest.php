<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/PackageViewWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminActionNormalizer.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/ProviderRepositoryRowsNormalizer.php';

use LogicException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderRepositoryRowsNormalizer;

final class ProviderRepositoryRowsNormalizerTest extends TestCase {

	public function testAllowsReservedAssistedStateAndNamespacedHistoricalRows(): void {
		$base                              = $this->baseRows();
		$presented                         = $base;
		$presented['repo-42']['details'][] = array(
			'label' => 'Remote hook',
			'value' => 'Configured',
		);
		$presented['repo-42']['actions']['core:webhook-management']['url']      = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&assisted_repository=repo-42';
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
						'label'        => 'Assisted Hooks',
						'type'         => 'link',
						'url'          => '',
						'disabled'     => true,
						'external'     => false,
						'described_by' => 'assisted-reason',
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
