<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;

#[CoversClass( WebhookScopeMetadata::class )]
final class WebhookScopeMetadataTest extends TestCase {

	public function test_universal_logical_codes_accept_provider_specific_labels(): void {
		$owner      = new WebhookScopeMetadata(
			'owner',
			'Bitbucket workspace',
			true,
			'Workspace',
			'my-workspace'
		);
		$repository = new WebhookScopeMetadata(
			'repository',
			'GitHub repository',
			true,
			'Repository',
			'organization-or-user/repository'
		);

		self::assertSame( 'owner', $owner->code );
		self::assertSame( 'Bitbucket workspace', $owner->label );
		self::assertSame( 'Workspace', $owner->target_label );
		self::assertSame( 'repository', $repository->code );
		self::assertSame( 'GitHub repository', $repository->label );
	}

	public function test_removed_global_code_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Webhook scope codes must be owner or repository.' );

		new WebhookScopeMetadata( 'global', 'All repositories', false );
	}

	public function test_provider_defined_logical_code_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Webhook scope codes must be owner or repository.' );

		new WebhookScopeMetadata( 'workspace', 'Bitbucket workspace', true, 'Workspace' );
	}
}
