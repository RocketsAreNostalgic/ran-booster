<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\WebhookCleanupContext;

final class WebhookCleanupContextTest extends TestCase {

	public function testItExposesOnlyBoundedDisplayAndCleanupAuthority(): void {
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

		self::assertSame( 'gh', $context->providerCode() );
		self::assertSame( 'repository-42', $context->repositoryId() );
		self::assertSame( 'owner/repository', $context->repository() );
		self::assertSame( 'repository', $context->localSecretCoverage() );
		self::assertTrue( $context->evidenceAvailable() );
		self::assertSame( array( 'branch/branch.php' ), $context->branchPackageReferences() );
		self::assertFalse( $context->cleanupAllowed() );
	}

	public function testItRejectsUnsafeLinks(): void {
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
