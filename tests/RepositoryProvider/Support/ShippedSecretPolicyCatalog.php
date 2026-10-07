<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider\Support;

use RAN\BoosterGitHubProvider\V1\CredentialPolicy as GitHubCredentialPolicy;
use RAN\BoosterGitHubProvider\V1\WebhookPolicy as GitHubWebhookPolicy;
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
				public function get_provider(): ProviderCode {
					return ProviderCode::parse( 'bb' ); }
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of normalize_credential retains the production method contract; these inputs do not affect this controlled result.
				public function normalize_credential( array $metadata, mixed $secret ): array {
					return array(
						'label'         => '',
						'kind'          => '',
						'configuration' => array(),
						'secret'        => '',
					); }
				public function get_constant_names(): array {
					return array(); }
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The fixture implementation of credential_from_constants retains the production method contract; these inputs do not affect this controlled result.
				public function credential_from_constants( array $constants ): ?array {
					return null; }
			},
			new class() implements ProviderWebhookPolicy {
				public function get_provider(): ProviderCode {
					return ProviderCode::parse( 'bb' ); }
				public function get_retained_headers(): array {
					return array(); }
				public function get_signature_header(): string {
					return ''; }
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of normalize_webhook retains the production method contract; these inputs do not affect this controlled result.
				public function normalize_webhook( array $metadata, mixed $secret ): array {
					return array(
						'label'        => '',
						'scope'        => '',
						'target'       => '',
						'authority_id' => '',
						'secret'       => '',
					); }
				public function get_constant_names(): array {
					return array(); }
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The fixture implementation of webhook_from_constants retains the production method contract; these inputs do not affect this controlled result.
				public function webhook_from_constants( array $constants ): ?array {
					return null; }
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of authorize_webhook retains the production method contract; these inputs do not affect this controlled result.
				public function authorize_webhook( SignedWebhookVerification $verification, string $repository_authority_id, string $repository ): bool {
					return false; }
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The fixture implementation of repository_target_matches retains the production method contract; these inputs do not affect this controlled result.
				public function repository_target_matches( string $target, string $repository_locator ): bool {
					return false; }
			}
		);

		return $catalog;
	}
}
