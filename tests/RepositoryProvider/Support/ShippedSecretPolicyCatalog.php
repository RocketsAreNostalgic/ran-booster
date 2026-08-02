<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\GitHubCredentialPolicy;
use RAN\RepositoryProvider\GitHubWebhookPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;

final class ShippedSecretPolicyCatalog {

	public static function create(): ProviderSecretPolicyCatalog {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register(
			ProviderCode::parse( 'gh' ),
			new GitHubCredentialPolicy(),
			new GitHubWebhookPolicy()
		);
		$catalog->register(
			ProviderCode::parse( 'bb' ),
			new class() implements ProviderCredentialPolicy {
				public function getProvider(): ProviderCode {
					return ProviderCode::parse( 'bb' ); }
				public function normalizeCredential( array $metadata, mixed $secret ): array {
					return array(
						'label'         => '',
						'kind'          => '',
						'configuration' => array(),
						'secret'        => '',
					); }
				public function getConstantNames(): array {
					return array(); }
				public function credentialFromConstants( array $constants ): ?array {
					return null; }
			},
			new class() implements ProviderWebhookPolicy {
				public function getProvider(): ProviderCode {
					return ProviderCode::parse( 'bb' ); }
				public function getRetainedHeaders(): array {
					return array(); }
				public function getSignatureHeader(): string {
					return ''; }
				public function normalizeWebhook( array $metadata, mixed $secret ): array {
					return array(
						'label'        => '',
						'scope'        => '',
						'target'       => '',
						'authority_id' => '',
						'secret'       => '',
					); }
				public function getConstantNames(): array {
					return array(); }
				public function webhookFromConstants( array $constants ): ?array {
					return null; }
				public function authorizeWebhook( SignedWebhookVerification $verification, string $repositoryAuthorityId, string $repository ): bool {
					return false; }
				public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
					return false; }
			}
		);

		return $catalog;
	}
}
