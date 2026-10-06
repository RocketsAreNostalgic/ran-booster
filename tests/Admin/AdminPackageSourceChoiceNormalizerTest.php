<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once __DIR__ . '/../Support/PackageViewWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminPackageSourceChoiceNormalizer.php';

use LogicException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\AdminPackageSourceChoiceNormalizer;

final class AdminPackageSourceChoiceNormalizerTest extends TestCase {

	public function test_normalizes_core_shell_and_hydrated_release_choice(): void {
		$choices = ( new AdminPackageSourceChoiceNormalizer() )->normalize(
			array(
				'branch'        => array(
					'heading'     => 'Branch',
					'description' => 'Deploy a branch.',
					'meta'        => 'Included',
					'url'         => 'https://example.test/wp-admin/admin.php?page=plugins&source_view=branch',
					'hydrated'    => true,
				),
				'release_asset' => array(
					'heading'           => 'Published releases',
					'description'       => 'Install verified releases.',
					'meta'              => 'Included with Booster',
					'url'               => '',
					'disabled'          => true,
					'hydrated'          => true,
					'client_hydratable' => true,
				),
			)
		);

		self::assertFalse( $choices['branch']['disabled'] );
		self::assertTrue( $choices['release_asset']['hydrated'] );
		self::assertTrue( $choices['release_asset']['client_hydratable'] );
	}

	public function test_rejects_unknown_source_keys(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'known source key' );

		( new AdminPackageSourceChoiceNormalizer() )->normalize(
			array(
				'branch'        => array(
					'heading'     => 'Branch',
					'description' => 'Deploy a branch.',
					'url'         => 'https://example.test/',
				),
				'release_asset' => array(
					'heading'     => 'Releases',
					'description' => 'Deploy releases.',
					'disabled'    => true,
				),
				'fixture'       => array(
					'heading'     => 'Fixture',
					'description' => 'Unexpected.',
					'disabled'    => true,
				),
			)
		);
	}

	public function test_rejects_unsafe_enabled_urls(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'safe absolute URL' );

		( new AdminPackageSourceChoiceNormalizer() )->normalize(
			array(
				'branch'        => array(
					'heading'     => 'Branch',
					'description' => 'Deploy a branch.',
					'url'         => 'javascript:alert(1)',
				),
				'release_asset' => array(
					'heading'     => 'Releases',
					'description' => 'Deploy releases.',
					'disabled'    => true,
				),
			)
		);
	}
}
