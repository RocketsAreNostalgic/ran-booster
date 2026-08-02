<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\Admin\ProviderWebhookAssistanceMetadata;

final class ProviderWebhookAssistanceMetadataTest extends TestCase {

	public function testProviderOwnsTheDormantWebhookAssistancePresentation(): void {
		$assistance = new ProviderWebhookAssistanceMetadata(
			'Assisted Hooks',
			'Assisted Hooks add-on not active.',
			'Activate the compatible add-on to configure repository webhooks here.',
			'Assisted Hooks is active.',
			'Repository status and assisted actions are available below.'
		);
		$admin      = new ProviderAdminMetadata(
			array(),
			array(),
			webhookAssistance: $assistance
		);

		self::assertSame( 'core:assisted-hooks', $admin->webhookAssistance?->actionKey );
		self::assertSame( 'Assisted Hooks', $admin->webhookAssistance?->actionLabel );
		self::assertSame( 'Assisted Hooks add-on not active.', $admin->webhookAssistance?->inactiveHeading );
		self::assertSame( 'Assisted Hooks is active.', $admin->webhookAssistance?->activeHeading );
	}

	public function testWebhookAssistancePresentationRejectsEmptyCopy(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderWebhookAssistanceMetadata(
			'',
			'Inactive',
			'Inactive description',
			'Active',
			'Active description'
		);
	}
}
