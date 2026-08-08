<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

// Native temporary files exercise the encrypted provider-policy boundary.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\GitHubCredentialPolicy;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use Tests\Secrets\InMemorySiteKeyStore;
use Tests\Secrets\SecretsFileTestFactory;

final class GitHubCredentialPolicyTest extends TestCase {

	/** @return array<string, array{string, string, string, string}> */
	public static function invalidInput(): array {
		return array(
			'email owner'          => array(
				'fine-grained',
				'person@example.test',
				'github_pat_example',
				InvalidCredentialInput::INVALID_RESOURCE_OWNER,
			),
			'classic in fine form' => array(
				'fine-grained',
				'example-owner',
				'ghp_example',
				InvalidCredentialInput::LOOKS_CLASSIC,
			),
		);
	}

	#[DataProvider( 'invalidInput' )]
	public function testRejectsOnlyClosedActionableInputFailures(
		string $kind,
		string $owner,
		string $token,
		string $reason
	): void {
		try {
			( new GitHubCredentialPolicy() )->normalizeCredential(
				array(
					'label'         => 'Repository access',
					'kind'          => $kind,
					'configuration' => array( 'owner' => $owner ),
				),
				$token
			);
			self::fail( 'The recognized input mismatch must be rejected.' );
		} catch ( InvalidCredentialInput $failure ) {
			self::assertSame( $reason, $failure->reason );
			self::assertStringNotContainsString( $token, $failure->getMessage() );
		}
	}

	public function testUnknownPrefixesRemainAvailableForFutureOrLegacyFormats(): void {
		$policy = new GitHubCredentialPolicy();

		$fine    = $policy->normalizeCredential(
			array(
				'label'         => 'Fine-grained access',
				'kind'          => 'fine-grained',
				'configuration' => array( 'owner' => 'example-owner' ),
			),
			'future_token_format'
		);
		$classic = $policy->normalizeCredential(
			array(
				'label'         => 'Classic access',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'github_pat_future-or-mismatched-format'
		);

		self::assertSame( 'future_token_format', $fine['secret'] );
		self::assertSame( 'github_pat_future-or-mismatched-format', $classic['secret'] );
	}

	public function testLegacyConstantDoesNotReclassifyOrRejectAProviderTokenFormat(): void {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets    = SecretsFileTestFactory::create(
			null,
			array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'github_pat_existing-constant' ),
			$catalog
		);
		$credential = $secrets->credentialMaterial( 'gh', SecretsFile::CONSTANT_PROFILE );

		self::assertSame( 'classic', $credential['kind'] );
		self::assertSame( 'github_pat_existing-constant', $credential['secret'] );
	}

	public function testEncryptedStorePreservesOnlyTheClosedInputFailure(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-github-input-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.json';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets = SecretsFileTestFactory::create( $path, array(), $catalog );

		try {
			$secrets->saveCredential(
				'gh',
				null,
				array(
					'label'         => 'Repository access',
					'kind'          => 'fine-grained',
					'configuration' => array( 'owner' => 'person@example.test' ),
				),
				'github_pat_example'
			);
			self::fail( 'The invalid owner must be rejected.' );
		} catch ( InvalidCredentialInput $failure ) {
			self::assertStringContainsString( 'not an email address', $failure->getMessage() );
			self::assertFalse(
				str_contains( (string) json_encode( $failure->getTrace() ), 'github_pat_example' ),
				'The submitted token must not survive in the boundary exception trace.'
			);
		} finally {
			InMemorySiteKeyStore::reset( $path );
			foreach ( array( $path, $path . '.lock' ) as $file ) {
				if ( is_file( $file ) || is_link( $file ) ) {
					unlink( $file );
				}
			}
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}
	}
}
