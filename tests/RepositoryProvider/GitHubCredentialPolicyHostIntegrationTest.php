<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

// Native temporary files exercise the encrypted provider-policy boundary.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use RAN\Booster\GitHub\CredentialPolicy as GitHubCredentialPolicy;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use Tests\Secrets\InMemorySiteKeyStore;
use Tests\Secrets\SecretsFileTestFactory;

final class GitHubCredentialPolicyHostIntegrationTest extends TestCase {

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

	public function testRecoveryFitnessRejectsADecryptableStoredGitHubPrefixMismatch(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-github-recovery-' . bin2hex( random_bytes( 8 ) );
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
					'label'         => 'Legacy mismatched access',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				'github_pat_' . str_repeat( 'a', 40 ),
				false
			);

			self::assertFalse( $secrets->recoveryCredentialsFitAt( $path ) );
			$secrets->saveCredential(
				'gh',
				$id,
				array(
					'label'         => 'Restored classic access',
					'kind'          => 'classic',
					'configuration' => array( 'owner' => '' ),
				),
				'ghp_' . str_repeat( 'b', 36 ),
				true
			);
			self::assertTrue( $secrets->recoveryCredentialsFitAt( $path ) );
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
			self::assertSame( InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH, $failure->reason );
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

	public function testBlueprintImportRejectsAMismatchedDecodedTokenBeforePersistence(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-github-blueprint-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.json';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets    = SecretsFileTestFactory::create( $path, array(), $catalog );
		$package    = new BlueprintPackage( 'plugin', 'example/example.php', 'Example', 'gh', 'repository-id', 'owner/example', 'main', null );
		$credential = new BlueprintCredential(
			'gh',
			'Repository access',
			'classic',
			array( 'owner' => '' ),
			'github_pat_' . str_repeat( 'a', 40 ),
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'example/example.php',
				),
			)
		);
		$blueprint  = new PackageBlueprint( array( $package ), array( $credential ) );

		try {
			$secrets->importCredentialsIfAbsent( $blueprint, $credential );
			self::fail( 'Blueprint material with a mismatched token prefix must be rejected.' );
		} catch ( InvalidCredentialInput $failure ) {
			self::assertSame( InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH, $failure->reason );
			self::assertFileDoesNotExist( $path );
			self::assertSame( array(), $secrets->credentialProfiles( 'gh' ) );
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
