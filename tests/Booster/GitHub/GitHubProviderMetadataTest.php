<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class GitHubProviderMetadataTest extends TestCase {

	public function testGitHubOwnsItsCredentialVocabulary(): void {
		$admin = $this->provider()->getMetadata()->admin;

		self::assertNotNull( $admin );
		$classic     = $admin->getCredentialKind( 'classic' );
		$fineGrained = $admin->getCredentialKind( 'fine-grained' );

		self::assertNotNull( $classic );
		self::assertSame( 'Classic personal access token', $classic->label );
		self::assertSame( 'Classic PAT', $classic->shortLabel );
		self::assertNotNull( $fineGrained );
		self::assertSame( 'Fine-grained personal access token', $fineGrained->label );
		self::assertSame( 'Fine-grained PAT', $fineGrained->shortLabel );
	}

	private function provider(): GitHubProvider {
		$provider = GitHubProvider::create(
			new RepositoryResolverSecretsStub(),
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);

		self::assertInstanceOf( GitHubProvider::class, $provider );

		return $provider;
	}
}
