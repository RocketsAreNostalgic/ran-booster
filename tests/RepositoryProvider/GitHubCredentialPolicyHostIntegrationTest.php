<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

// Native temporary files exercise the encrypted provider-policy boundary.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use RAN\BoosterGitHubProvider\V1\CredentialPolicy as GitHubCredentialPolicy;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\Secrets\SecretsFile;
use RAN\Tests\Secrets\InMemorySiteKeyStore;
use RAN\Tests\Secrets\SecretsFileTestFactory;

final class GitHubCredentialPolicyHostIntegrationTest extends TestCase {

	public function test_legacy_constant_does_not_reclassify_or_reject_aprovider_token_format(): void {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets    = SecretsFileTestFactory::create(
			null,
			array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'github_pat_existing-constant' ),
			$catalog
		);
		$credential = $secrets->credential_material( 'gh', SecretsFile::CONSTANT_PROFILE );

		self::assertSame( 'classic', $credential['kind'] );
		self::assertSame( 'github_pat_existing-constant', $credential['secret'] );
	}

	public function test_blank_secret_edit_retains_the_existing_token_without_submitted_validation(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-github-legacy-edit-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.json';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets = SecretsFileTestFactory::create( $path, array(), $catalog );

		try {
			$id = $secrets->save_credential(
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

			$secrets->save_credential(
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
			self::assertSame( 'ghp_' . str_repeat( 'a', 36 ), $secrets->credential_material( 'gh', $id )['secret'] );
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

	public function test_recovery_fitness_rejects_adecryptable_stored_git_hub_prefix_mismatch(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-github-recovery-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.json';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets = SecretsFileTestFactory::create( $path, array(), $catalog );

		try {
			$id = $secrets->save_credential(
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

			self::assertFalse( $secrets->recovery_credentials_fit_at( $path ) );
			$secrets->save_credential(
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
			self::assertTrue( $secrets->recovery_credentials_fit_at( $path ) );
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

	public function test_encrypted_store_preserves_only_the_closed_submitted_token_failure(): void {
		$directory = sys_get_temp_dir() . '/ran-booster-github-input-' . bin2hex( random_bytes( 8 ) );
		$path      = $directory . '/secrets.json';
		self::assertTrue( mkdir( $directory, 0700 ) );
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), new GitHubCredentialPolicy(), null );
		$secrets = SecretsFileTestFactory::create( $path, array(), $catalog );

		try {
			$token = 'github_pat_' . str_repeat( 'a', 40 );
			$secrets->save_credential(
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

	public function test_blueprint_import_rejects_amismatched_decoded_token_before_persistence(): void {
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
			$secrets->import_credentials_if_absent( $blueprint, $credential );
			self::fail( 'Blueprint material with a mismatched token prefix must be rejected.' );
		} catch ( InvalidCredentialInput $failure ) {
			self::assertSame( InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH, $failure->reason );
			self::assertFileDoesNotExist( $path );
			self::assertSame( array(), $secrets->credential_profiles( 'gh' ) );
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
