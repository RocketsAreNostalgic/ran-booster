<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\WebhookCleanupContext;

final class WebhookCleanupContextTest extends TestCase {

	public function test_it_exposes_only_bounded_display_and_cleanup_authority(): void {
		$context = new WebhookCleanupContext(
			'plugin',
			'plugin/plugin.php',
			'gh',
			'repository-42',
			'owner/repository',
			'repository',
			true,
			true,
			array( 'branch/branch.php' ),
			'https://github.com/owner/repository/settings/hooks',
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&view=secrets',
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation#ran-booster-webhook-cleanup',
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&package=plugin%2Fplugin.php'
		);

		self::assertSame( 'gh', $context->provider_code() );
		self::assertSame( 'repository-42', $context->repository_id() );
		self::assertSame( 'owner/repository', $context->repository() );
		self::assertSame( 'repository', $context->local_secret_coverage() );
		self::assertTrue( $context->evidence_available() );
		self::assertSame( array( 'branch/branch.php' ), $context->branch_package_references() );
		self::assertFalse( $context->cleanup_allowed() );
	}

	public function test_it_rejects_unsafe_links(): void {
		$this->expectException( \InvalidArgumentException::class );

		new WebhookCleanupContext(
			'plugin',
			'plugin/plugin.php',
			'gh',
			'repository-42',
			'owner/repository',
			'none',
			true,
			true,
			array(),
			'https://user:secret@example.test/hooks',
			'https://example.test/secrets',
			'https://example.test/docs',
			'https://example.test/settings'
		);
	}
}
