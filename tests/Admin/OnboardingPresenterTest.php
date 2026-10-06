<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use LogicException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\OnboardingPresenter;

final class OnboardingPresenterTest extends TestCase {

	public function test_builds_provider_and_help_links_from_allowlisted_navigation(): void {
		$onboarding = ( new OnboardingPresenter() )->build(
			$this->tabs(),
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins-create',
			'https://example.test/wp-admin/admin.php?page=ran-booster-themes-create'
		);

		self::assertSame(
			array(
				array(
					'label' => 'GitHub',
					'url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh',
				),
				array(
					'label' => 'GitLab <unsafe>',
					'url'   => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gl',
				),
			),
			$onboarding['provider_links']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins-create',
			$onboarding['install_plugin_url']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster-themes-create',
			$onboarding['install_theme_url']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability',
			$onboarding['portability_url']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation',
			$onboarding['documentation_url']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting',
			$onboarding['troubleshooting_url']
		);
	}

	public function test_allows_no_registered_provider_without_inventing_one(): void {
		$tabs       = array_values(
			array_filter(
				$this->tabs(),
				static fn ( array $tab ): bool => ! $tab['provider']
			)
		);
		$onboarding = ( new OnboardingPresenter() )->build( $tabs, '/plugins', '/themes' );

		self::assertSame( array(), $onboarding['provider_links'] );
	}

	public function test_rejects_incomplete_fixed_navigation(): void {
		$tabs = array_values(
			array_filter(
				$this->tabs(),
				static fn ( array $tab ): bool => 'troubleshooting' !== $tab['key']
			)
		);

		$this->expectException( LogicException::class );
		$this->expectExceptionMessage( 'requires every fixed admin destination' );

		( new OnboardingPresenter() )->build( $tabs, '/plugins', '/themes' );
	}

	/** @return list<array{key: string, label: string, url: string, active: bool, provider: bool}> */
	private function tabs(): array {
		return array(
			array(
				'key'      => 'gh',
				'label'    => 'GitHub',
				'url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh',
				'active'   => true,
				'provider' => true,
			),
			array(
				'key'      => 'gl',
				'label'    => 'GitLab <unsafe>',
				'url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gl',
				'active'   => false,
				'provider' => true,
			),
			array(
				'key'      => 'portability',
				'label'    => 'Transporter',
				'url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability',
				'active'   => false,
				'provider' => false,
			),
			array(
				'key'      => 'documentation',
				'label'    => 'Documentation',
				'url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation',
				'active'   => false,
				'provider' => false,
			),
			array(
				'key'      => 'troubleshooting',
				'label'    => 'Troubleshooting',
				'url'      => 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=troubleshooting',
				'active'   => false,
				'provider' => false,
			),
		);
	}
}
