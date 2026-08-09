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
			'email owner' => array(
				'fine-grained',
				'person@example.test',
				'github_pat_example',
				InvalidCredentialInput::INVALID_RESOURCE_OWNER,
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

	/** @return array<string, array{string, string, string}> */
	public static function invalidSubmittedToken(): array {
		return array(
			'classic uses fine prefix'           => array(
				'classic',
				'github_pat_' . str_repeat( 'a', 40 ),
				InvalidCredentialInput::REQUIRES_CLASSIC,
			),
			'classic has unknown prefix'         => array(
				'classic',
				'future_' . str_repeat( 'a', 40 ),
				InvalidCredentialInput::REQUIRES_CLASSIC,
			),
			'fine-grained uses classic prefix'   => array(
				'fine-grained',
				'ghp_' . str_repeat( 'a', 40 ),
				InvalidCredentialInput::LOOKS_CLASSIC,
			),
			'fine-grained has unknown prefix'    => array(
				'fine-grained',
				'future_' . str_repeat( 'a', 40 ),
				InvalidCredentialInput::REQUIRES_FINE_GRAINED,
			),
			'token is truncated'                 => array(
				'classic',
				'ghp_short',
				InvalidCredentialInput::INVALID_TOKEN_SHAPE,
			),
			'token is over the defensive bound'  => array(
				'classic',
				'ghp_' . str_repeat( 'a', 252 ),
				InvalidCredentialInput::INVALID_TOKEN_SHAPE,
			),
			'token contains punctuation'         => array(
				'classic',
				'ghp_' . str_repeat( 'a', 35 ) . '-',
				InvalidCredentialInput::INVALID_TOKEN_SHAPE,
			),
			'token contains a control character' => array(
				'fine-grained',
				'github_pat_' . str_repeat( 'a', 29 ) . "\n",
				InvalidCredentialInput::INVALID_TOKEN_SHAPE,
			),
		);
	}

	#[DataProvider( 'invalidSubmittedToken' )]
	public function testNewlySubmittedTokensUseClosedPrefixAndShapeValidation(
		string $kind,
		string $token,
		string $reason
	): void {
		$policy = new GitHubCredentialPolicy();

		try {
			$policy->validateSubmittedCredential(
				array(
					'label'         => 'Repository access',
					'kind'          => $kind,
					'configuration' => array( 'owner' => 'example-owner' ),
				),
				$token
			);
			self::fail( 'The malformed submitted token must be rejected.' );
		} catch ( InvalidCredentialInput $failure ) {
			self::assertSame( $reason, $failure->reason );
			self::assertStringNotContainsString( $token, $failure->getMessage() );
		}
	}

	public function testSubmittedTokenLengthRemainsVariableWithinTheDefensiveBounds(): void {
		$policy = new GitHubCredentialPolicy();

		foreach ( array(
			array( 'classic', 'ghp_' . str_repeat( 'a', 36 ) ),
			array( 'fine-grained', 'github_pat_' . str_repeat( 'b', 29 ) ),
			array( 'classic', 'ghp_' . str_repeat( 'c', 251 ) ),
		) as [ $kind, $token ] ) {
			$policy->validateSubmittedCredential(
				array(
					'label'         => 'Repository access',
					'kind'          => $kind,
					'configuration' => array( 'owner' => 'example-owner' ),
				),
				$token
			);
			self::addToAssertionCount( 1 );
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

	public function testBlankSecretEditRetainsTheExistingTokenWithoutSubmittedValidation(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-github-legacy-edit-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.json';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets = SecretsFileTestFactory::create( $path, array(), $catalog );

		try {
			$id = $secrets->saveCredential(
				'gh',
				null,
				array(
					'label'         => 'Legacy access',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				'ghp_' . str_repeat( 'a', 36 ),
				true
			);

			$secrets->saveCredential(
				'gh',
				$id,
				array(
					'label'         => 'Renamed legacy access',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				null,
				true
			);
			self::assertSame( 'ghp_' . str_repeat( 'a', 36 ), $secrets->credentialMaterial( 'gh', $id )['secret'] );
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

	public function testEncryptedStorePreservesOnlyTheClosedSubmittedTokenFailure(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-github-input-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.json';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets = SecretsFileTestFactory::create( $path, array(), $catalog );

		try {
			$token = 'github_pat_' . str_repeat( 'a', 40 );
			$secrets->saveCredential(
				'gh',
				null,
				array(
					'label'         => 'Repository access',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				$token,
				true
			);
			self::fail( 'The submitted token prefix mismatch must be rejected.' );
		} catch ( InvalidCredentialInput $failure ) {
			self::assertSame( InvalidCredentialInput::REQUIRES_CLASSIC, $failure->reason );
			self::assertStringContainsString( 'must begin with ghp_', $failure->getMessage() );
			self::assertFalse(
				str_contains( (string) json_encode( $failure->getTrace() ), $token ),
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
