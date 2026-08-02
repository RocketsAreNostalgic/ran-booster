<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/../Support/PackageViewWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminActionNormalizer.php';

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\AdminActionNormalizer;

final class AdminActionNormalizerTest extends TestCase {

	public function testNormalizesBoundedLinkAndCanonicalPostActions(): void {
		$actions = ( new AdminActionNormalizer() )->normalize(
			array(
				'fixture:manage'  => array(
					'label'         => 'Manage releases',
					'type'          => 'link',
					'url'           => 'https://example.test/wp-admin/admin.php?page=fixture',
					'external'      => false,
					'screen_reader' => 'Example Plugin',
				),
				'fixture:refresh' => array(
					'label'  => 'Check published releases',
					'type'   => 'post',
					'url'    => 'https://example.test/wp-admin/admin-post.php',
					'hidden' => array(
						'action'   => 'fixture_refresh',
						'_wpnonce' => 'fixture-nonce',
						'package'  => 'example/example.php',
						'revision' => 3,
					),
				),
			)
		);

		self::assertSame( 'fixture:manage', $actions['fixture:manage']['key'] );
		self::assertSame( array(), $actions['fixture:manage']['hidden'] );
		self::assertFalse( $actions['fixture:manage']['disabled'] );
		self::assertSame( 'fixture_refresh', $actions['fixture:refresh']['hidden']['action'] );
		self::assertSame( '3', $actions['fixture:refresh']['hidden']['revision'] );
	}

	public function testRejectsUnnamespacedActions(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'namespaced keys' );

		( new AdminActionNormalizer() )->normalize(
			array(
				'manage' => array(
					'label' => 'Manage',
					'type'  => 'link',
					'url'   => 'https://example.test/',
				),
			)
		);
	}

	public function testRejectsPostActionsOutsideTheCanonicalHandler(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'canonical WordPress handler' );

		( new AdminActionNormalizer() )->normalize(
			array(
				'fixture:refresh' => array(
					'label'  => 'Refresh',
					'type'   => 'post',
					'url'    => 'https://attacker.example/',
					'hidden' => array(
						'action'   => 'fixture_refresh',
						'_wpnonce' => 'nonce',
					),
				),
			)
		);
	}

	public function testRejectsPostActionsWithoutANonce(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'action name and nonce' );

		( new AdminActionNormalizer() )->normalize(
			array(
				'fixture:refresh' => array(
					'label'  => 'Refresh',
					'type'   => 'post',
					'url'    => 'https://example.test/wp-admin/admin-post.php',
					'hidden' => array( 'action' => 'fixture_refresh' ),
				),
			)
		);
	}

	public function testRejectsHiddenFieldsOnLinks(): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'Link actions must not contain hidden fields' );

		( new AdminActionNormalizer() )->normalize(
			array(
				'fixture:manage' => array(
					'label'  => 'Manage',
					'type'   => 'link',
					'url'    => 'https://example.test/',
					'hidden' => array( 'unsafe' => 'value' ),
				),
			)
		);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function unsafeActionProvider(): array {
		return array(
			'mismatched embedded key' => array(
				array(
					'key'   => 'fixture:other',
					'label' => 'Manage',
					'type'  => 'link',
					'url'   => 'https://example.test/',
				),
				'keys must match',
			),
			'non-boolean flag'        => array(
				array(
					'label'    => 'Manage',
					'type'     => 'link',
					'url'      => 'https://example.test/',
					'disabled' => 0,
				),
				'flags must be booleans',
			),
			'unsafe enabled URL'      => array(
				array(
					'label' => 'Manage',
					'type'  => 'link',
					'url'   => 'javascript:alert(1)',
				),
				'safe absolute URL',
			),
		);
	}

	/** @param array<string, mixed> $action */
	#[DataProvider( 'unsafeActionProvider' )]
	public function testRejectsAmbiguousOrUnsafeActionState( array $action, string $message ): void {
		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( $message );

		( new AdminActionNormalizer() )->normalize( array( 'fixture:manage' => $action ) );
	}
}
