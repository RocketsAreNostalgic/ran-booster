<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\Admin\WebhookScopeMetadata;

#[CoversClass( WebhookScopeMetadata::class )]
final class WebhookScopeMetadataTest extends TestCase {

	public function testUniversalLogicalCodesAcceptProviderSpecificLabels(): void {
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
		self::assertSame( 'Workspace', $owner->targetLabel );
		self::assertSame( 'repository', $repository->code );
		self::assertSame( 'GitHub repository', $repository->label );
	}

	public function testRemovedGlobalCodeIsRejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Webhook scope codes must be owner or repository.' );

		new WebhookScopeMetadata( 'global', 'All repositories', false );
	}

	public function testProviderDefinedLogicalCodeIsRejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Webhook scope codes must be owner or repository.' );

		new WebhookScopeMetadata( 'workspace', 'Bitbucket workspace', true, 'Workspace' );
	}
}
