<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/PackageViewWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminPackageSourceChoiceNormalizer.php';

use LogicException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\AdminPackageSourceChoiceNormalizer;

final class AdminPackageSourceChoiceNormalizerTest extends TestCase {

	public function testNormalizesCoreShellAndHydratedReleaseChoice(): void {
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
					'meta'              => 'Release Deployments',
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

	public function testRejectsUnknownSourceKeys(): void {
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

	public function testRejectsUnsafeEnabledUrls(): void {
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
