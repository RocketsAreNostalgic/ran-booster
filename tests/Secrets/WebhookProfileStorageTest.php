<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

// Direct local filesystem operations exercise the encrypted sidecar lifecycle.

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\InvalidWebhookInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SiteKeyStore;
use RuntimeException;
use RAN\Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;

final class WebhookProfileStorageTest extends TestCase {

	private string $directory;
	private string $path;
	private SecretsFile $secrets;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		parent::setUp();

		$this->directory = sys_get_temp_dir() . '/ran-booster-webhook-profile-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->secrets = SecretsFileTestFactory::create( $this->path, array(), ShippedSecretPolicyCatalog::create() );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		InMemorySiteKeyStore::reset( $this->path );
		foreach ( array( $this->path, $this->path . '.lock' ) as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
			rmdir( $this->directory );
		}

		parent::tearDown();
	}

	public function test_label_edit_preserves_revision_and_secret_replacement_increments_it(): void {
		$id = $this->secrets->save_webhook(
			'gh',
			null,
			$this->owner( 'Owner one', 'ExampleOwner' ),
			str_repeat( 'a', 32 )
		);
		self::assertSame( 1, $this->secrets->webhook_profiles( 'gh' )[ $id ]['revision'] );

		$this->secrets->save_webhook( 'gh', $id, $this->owner( 'Renamed owner', 'ExampleOwner' ), null );
		$renamed = $this->secrets->webhook_profiles( 'gh' )[ $id ];
		self::assertSame( 'Renamed owner', $renamed['label'] );
		self::assertSame( 1, $renamed['revision'] );
		self::assertSame( str_repeat( 'a', 32 ), $this->secrets->webhook_materials( 'gh' )[ $id ]['secret'] );

		$this->secrets->save_webhook( 'gh', $id, $this->owner( 'Renamed owner', 'ExampleOwner' ), str_repeat( 'b', 32 ) );
		self::assertSame( 2, $this->secrets->webhook_profiles( 'gh' )[ $id ]['revision'] );
		self::assertSame( str_repeat( 'b', 32 ), $this->secrets->webhook_materials( 'gh' )[ $id ]['secret'] );
	}

	public function test_conditional_delete_cannot_remove_aconcurrently_rotated_profile(): void {
		$id = $this->secrets->save_webhook(
			'gh',
			null,
			$this->repository( 'Repository', 'owner/example', '101', 'assisted' ),
			str_repeat( 'a', 32 )
		);
		$this->secrets->save_webhook( 'gh', $id, $this->repository( 'Repository', 'owner/example', '101', 'assisted' ), str_repeat( 'b', 32 ) );

		self::assertFalse( $this->secrets->delete_webhook_if_revision( 'gh', $id, 1 ) );
		self::assertSame( 2, $this->secrets->webhook_profiles( 'gh' )[ $id ]['revision'] );
		self::assertTrue( $this->secrets->delete_webhook_if_revision( 'gh', $id, 2 ) );
		self::assertArrayNotHasKey( $id, $this->secrets->webhook_profiles( 'gh' ) );
	}

	public function test_scope_target_authority_and_origin_are_immutable(): void {
		$id = $this->secrets->save_webhook(
			'gh',
			null,
			$this->repository( 'Repository', 'owner/example', '101', 'assisted' ),
			str_repeat( 'a', 32 )
		);

		foreach ( array(
			$this->owner( 'Repository', 'owner', 'assisted' ),
			$this->repository( 'Repository', 'owner/other', '101', 'assisted' ),
			$this->repository( 'Repository', 'owner/example', '102', 'assisted' ),
			$this->repository( 'Repository', 'owner/example', '101', 'manual' ),
		) as $metadata ) {
			try {
				$this->secrets->save_webhook( 'gh', $id, $metadata, null );
				self::fail( 'Immutable webhook authority metadata must reject edits.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'immutable', $exception->getMessage() );
			}
		}
	}

	public function test_owner_and_repository_authority_keys_are_unique(): void {
		$this->secrets->save_webhook( 'gh', null, $this->owner( 'Owner', 'ExampleOwner' ), str_repeat( 'a', 32 ) );
		$this->secrets->save_webhook( 'gh', null, $this->repository( 'Repository', 'owner/example', '101' ), str_repeat( 'b', 32 ) );

		foreach ( array(
			$this->owner( 'Duplicate owner', 'exampleowner' ),
			$this->repository( 'Duplicate repository', 'renamed/example', '101' ),
		) as $metadata ) {
			try {
				$this->secrets->save_webhook( 'gh', null, $metadata, str_repeat( 'c', 32 ) );
				self::fail( 'Duplicate webhook authority must be rejected.' );
			} catch ( InvalidWebhookInput $exception ) {
				self::assertSame( InvalidWebhookInput::DUPLICATE_TARGET, $exception->reason );
				self::assertStringContainsString( 'already exists', $exception->getMessage() );
			}
		}
	}

	public function test_provider_profile_count_is_bounded_at_sixteen(): void {
		foreach ( range( 1, SecretsFile::MAX_WEBHOOK_PROFILES ) as $index ) {
			$this->secrets->save_webhook(
				'gh',
				null,
				$this->owner( 'Owner ' . $index, 'owner' . $index ),
				str_repeat( chr( 96 + $index ), 32 )
			);
		}
		self::assertCount( SecretsFile::MAX_WEBHOOK_PROFILES, $this->secrets->webhook_profiles( 'gh' ) );

		$this->expectException( InvalidWebhookInput::class );
		$this->expectExceptionMessage( 'maximum of 16' );
		$this->secrets->save_webhook( 'gh', null, $this->owner( 'Overflow', 'owner17' ), str_repeat( 'z', 32 ) );
	}

	public function test_submitted_secret_and_target_failures_are_closed_and_do_not_expose_the_secret(): void {
		$secret = "short-secret\ncanary";
		try {
			$this->secrets->save_webhook( 'gh', null, $this->owner( 'Invalid', 'not an owner!' ), str_repeat( 'v', 32 ) );
			self::fail( 'Malformed submitted webhook material must be rejected.' );
		} catch ( InvalidWebhookInput $failure ) {
			self::assertSame( InvalidWebhookInput::INVALID_TARGET, $failure->reason );
			self::assertStringNotContainsString( 'not an owner', $failure->getMessage() );
		}

		try {
			$this->secrets->save_webhook( 'gh', null, $this->owner( 'Invalid secret', 'valid-owner' ), $secret );
			self::fail( 'Malformed submitted webhook secret must be rejected.' );
		} catch ( InvalidWebhookInput $failure ) {
			self::assertSame( InvalidWebhookInput::INVALID_SECRET, $failure->reason );
			self::assertStringContainsString( '32 to 512 bytes', $failure->getMessage() );
			self::assertStringNotContainsString( $secret, $failure->getMessage() );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve native JSON bytes for the isolated fixture/trace assertion without loading WordPress.
			self::assertFalse( str_contains( (string) json_encode( $failure->getTrace() ), $secret ) );
		}
	}

	public function test_removed_global_scope_and_constant_are_unavailable(): void {
		$policy = ShippedSecretPolicyCatalog::create()->webhook_policy( 'gh' );
		self::assertSame( array(), $policy->get_constant_names() );
		self::assertNull( $policy->webhook_from_constants( array( 'RAN_BOOSTER_GITHUB_WEBHOOK_SECRET' => str_repeat( 'a', 32 ) ) ) );

		$this->expectException( RuntimeException::class );
		$this->secrets->save_webhook(
			'gh',
			null,
			array(
				'label'        => 'Global',
				'scope'        => 'global',
				'target'       => '',
				'authority_id' => '',
			),
			str_repeat( 'a', 32 )
		);
	}

	public function test_storage_rejects_unknown_scopes_returned_by_apermissive_provider_policy(): void {
		$secrets = SecretsFileTestFactory::create( $this->path, array(), $this->permissive_webhook_policy_catalog() );

		foreach ( array( 'global', 'workspace' ) as $scope ) {
			try {
				$secrets->save_webhook(
					'gh',
					null,
					array(
						'label'        => 'Unsupported scope',
						'scope'        => $scope,
						'target'       => 'owner',
						'authority_id' => '',
					),
					str_repeat( 'a', 32 )
				);
				self::fail( 'Core storage must reject provider-defined webhook scope codes.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( 'Webhook secret scope must be owner or repository.', $exception->getMessage() );
			}
		}
	}

	public function test_paused_signing_keeps_local_metadata_without_provider_callbacks(): void {
		$id     = $this->secrets->save_webhook( 'gh', null, $this->owner( 'Owner', 'owner' ), str_repeat( 'a', 32 ) );
		$policy = $this->createMock( ProviderWebhookPolicy::class );
		$policy->method( 'get_provider' )->willReturn( ProviderCode::parse( 'gh' ) );
		$policy->expects( self::never() )->method( 'get_constant_names' );
		$policy->expects( self::never() )->method( 'webhook_from_constants' );
		$policy->expects( self::never() )->method( 'normalize_webhook' );
		$secrets = $this->bound_secrets( $policy, static fn (): bool => false );
		$profile = $secrets->webhook_profiles( 'gh' )[ $id ];
		self::assertSame( 'Owner', $profile['label'] );
		self::assertArrayNotHasKey( 'secret', $profile );
		foreach ( array( 'read', 'edit' ) as $operation ) {
			try {
				if ( 'read' === $operation ) {
					$secrets->webhook_materials( 'gh' );
				} else {
					$secrets->save_webhook( 'gh', $id, $this->owner( 'Edited', 'owner' ), null );
				}
				self::fail( 'Paused signing must refuse secret access.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( 'GitHub webhook access is unavailable.', $failure->getMessage() );
			}
		}
		self::assertSame( 'Owner', $this->secrets->webhook_profiles( 'gh' )[ $id ]['label'] );
	}

	public function test_revocation_during_normalization_refuses_read_and_retained_secret_edit(): void {
		$id     = $this->secrets->save_webhook( 'gh', null, $this->owner( 'Owner', 'owner' ), str_repeat( 'a', 32 ) );
		$before = hash_file( 'sha256', $this->path );
		$real   = ShippedSecretPolicyCatalog::create()->webhook_policy( 'gh' );
		foreach ( array( 'read', 'edit' ) as $operation ) {
			$allowed = true;
			$policy  = $this->createMock( ProviderWebhookPolicy::class );
			$policy->method( 'get_provider' )->willReturn( ProviderCode::parse( 'gh' ) );
			$policy->method( 'get_constant_names' )->willReturn( array() );
			$policy->method( 'webhook_from_constants' )->willReturn( null );
			$policy->expects( self::once() )->method( 'normalize_webhook' )->willReturnCallback(
				static function ( array $metadata, mixed $secret ) use ( &$allowed, $real ): array {
					$allowed = false;
					return $real->normalize_webhook( $metadata, $secret );
				}
			);
			$secrets = $this->bound_secrets(
				$policy,
				static function () use ( &$allowed ): bool {
					return $allowed;
				}
			);
			try {
				if ( 'read' === $operation ) {
					$secrets->webhook_materials( 'gh' );
				} else {
					$secrets->save_webhook( 'gh', $id, $this->owner( 'Edited', 'owner' ), null );
				}
				self::fail( 'Revoked signing authority must discard normalized material.' );
			} catch ( RuntimeException $failure ) {
				self::assertSame( 'GitHub webhook access is unavailable.', $failure->getMessage() );
			}
			self::assertSame( $before, hash_file( 'sha256', $this->path ) );
		}
	}

	public function test_signing_policy_cannot_request_api_token_constant(): void {
		$policy = $this->createMock( ProviderWebhookPolicy::class );
		$policy->method( 'get_provider' )->willReturn( ProviderCode::parse( 'gh' ) );
		$policy->expects( self::once() )->method( 'get_constant_names' )->willReturn( array( 'RAN_BOOSTER_GITHUB_TOKEN' ) );
		$policy->expects( self::never() )->method( 'webhook_from_constants' );
		$policy->expects( self::never() )->method( 'normalize_webhook' );
		$secrets = $this->bound_secrets( $policy, static fn (): bool => true );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Provider webhook constants could not be validated.' );
		$secrets->webhook_materials( 'gh' );
	}

	public function test_bound_signing_policy_cannot_invent_constant_material(): void {
		$policy = $this->createMock( ProviderWebhookPolicy::class );
		$policy->method( 'get_provider' )->willReturn( ProviderCode::parse( 'gh' ) );
		$policy->method( 'get_constant_names' )->willReturn( array() );
		$policy->method( 'webhook_from_constants' )->willReturn( array( 'secret' => str_repeat( 'a', 32 ) ) );
		$policy->expects( self::never() )->method( 'normalize_webhook' );
		$secrets = $this->bound_secrets( $policy, static fn (): bool => true );
		$this->expectException( RuntimeException::class );
		$secrets->webhook_materials( 'gh' );
	}

	public function test_signing_access_is_independent_of_api_credential_approval_and_survives_clone(): void {
		$id      = $this->secrets->save_webhook( 'gh', null, $this->owner( 'Owner', 'owner' ), str_repeat( 'a', 32 ) );
		$allowed = true;
		$secrets = $this->bound_secrets(
			ShippedSecretPolicyCatalog::create()->webhook_policy( 'gh' ),
			static function () use ( &$allowed ): bool {
				return $allowed; }
		);
		$clone   = clone $secrets;
		self::assertSame( str_repeat( 'a', 32 ), $clone->webhook_materials( 'gh' )[ $id ]['secret'] );
		$allowed = false;
		$this->expectException( RuntimeException::class );
		$clone->webhook_materials( 'gh' );
	}

	public function test_revocation_after_normalization_before_ciphertext_write_preserves_stored_record(): void {
		$id         = $this->secrets->save_webhook( 'gh', null, $this->owner( 'Owner', 'owner' ), str_repeat( 'a', 32 ) );
		$before     = hash_file( 'sha256', $this->path );
		$allowed    = true;
		$normalized = false;
		$real       = ShippedSecretPolicyCatalog::create()->webhook_policy( 'gh' );
		$policy     = $this->createMock( ProviderWebhookPolicy::class );
		$policy->method( 'get_provider' )->willReturn( ProviderCode::parse( 'gh' ) );
		$policy->expects( self::once() )->method( 'normalize_webhook' )->willReturnCallback(
			static function ( array $metadata, mixed $secret ) use ( &$normalized, $real ): array {
				$normalized = true;
				return $real->normalize_webhook( $metadata, $secret );
			}
		);
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), null, $policy );
		$store      = $this->createMock( SiteKeyStore::class );
		$real_store = SecretsFileTestFactory::key_store( $this->path );
		$store->method( 'load' )->willReturnCallback(
			static function () use ( &$allowed, &$normalized, $real_store ): ?string {
				if ( $normalized ) {
					$allowed = false;
				}
				return $real_store->load();
			}
		);
		$secrets = new SecretsFile(
			$this->path,
			array(),
			$catalog,
			$store,
			github_webhook_access: static function () use ( &$allowed ): bool {
				return $allowed; }
		);
		try {
			$secrets->save_webhook( 'gh', $id, $this->owner( 'Edited', 'owner' ), null );
			self::fail( 'Authority must remain current after key-store callbacks.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'GitHub webhook access is unavailable.', $failure->getMessage() );
		}
		self::assertSame( $before, hash_file( 'sha256', $this->path ) );
		self::assertSame( 'Owner', $this->secrets->webhook_profiles( 'gh' )[ $id ]['label'] );
	}

	public function test_paused_github_signing_does_not_block_bitbucket_storage(): void {
		$policy = $this->createMock( ProviderWebhookPolicy::class );
		$policy->method( 'get_provider' )->willReturn( ProviderCode::parse( 'bb' ) );
		$policy->method( 'get_constant_names' )->willReturn( array() );
		$policy->method( 'webhook_from_constants' )->willReturn( null );
		$policy->expects( self::exactly( 2 ) )->method( 'normalize_webhook' )->willReturn(
			array_diff_key( $this->owner( 'Bitbucket', 'workspace' ), array( 'origin' => true ) ) + array( 'secret' => str_repeat( 'b', 32 ) )
		);
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'bb' ), null, $policy );
		$secrets = new SecretsFile(
			$this->path,
			array(),
			$catalog,
			SecretsFileTestFactory::key_store( $this->path ),
			github_webhook_access: static fn (): bool => false
		);
		$id      = $secrets->save_webhook( 'bb', null, $this->owner( 'Bitbucket', 'workspace' ), str_repeat( 'b', 32 ) );
		self::assertSame( str_repeat( 'b', 32 ), $secrets->webhook_materials( 'bb' )[ $id ]['secret'] );
	}

	/** @param callable(): bool $access */
	private function bound_secrets( ProviderWebhookPolicy $policy, callable $access ): SecretsFile {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register( ProviderCode::parse( 'gh' ), null, $policy );
		return new SecretsFile(
			$this->path,
			array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'test-token-must-not-reach-signing-policy' ),
			$catalog,
			SecretsFileTestFactory::key_store( $this->path ),
			github_credential_access: static fn (): bool => false,
			github_webhook_access: $access
		);
	}

	/** @return array<string, mixed> */
	private function owner( string $label, string $owner, string $origin = 'manual' ): array {
		return array(
			'label'        => $label,
			'scope'        => 'owner',
			'target'       => $owner,
			'authority_id' => '',
			'origin'       => $origin,
		);
	}

	/** @return array<string, mixed> */
	private function repository( string $label, string $repository, string $authority_id, string $origin = 'manual' ): array {
		return array(
			'label'        => $label,
			'scope'        => 'repository',
			'target'       => $repository,
			'authority_id' => $authority_id,
			'origin'       => $origin,
		);
	}

	private function permissive_webhook_policy_catalog(): ProviderSecretPolicyCatalog {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register(
			ProviderCode::parse( 'gh' ),
			null,
			new class() implements ProviderWebhookPolicy {
				public function get_provider(): ProviderCode {
					return ProviderCode::parse( 'gh' );
				}

				public function get_retained_headers(): array {
					return array();
				}

				public function get_signature_header(): string {
					return 'x-fixture-signature';
				}

				public function normalize_webhook( array $metadata, mixed $secret ): array {
					$result = $metadata + array( 'secret' => $secret );
					if ( ! isset( $result['label'] ) || ! is_string( $result['label'] ) ) {
						\PHPUnit\Framework\Assert::fail( 'Expected string label in the permissive scope fixture.' );
					}
					if ( ! isset( $result['scope'] ) || ! is_string( $result['scope'] ) ) {
						\PHPUnit\Framework\Assert::fail( 'Expected string scope in the permissive scope fixture.' );
					}
					if ( ! isset( $result['target'] ) || ! is_string( $result['target'] ) ) {
						\PHPUnit\Framework\Assert::fail( 'Expected string target in the permissive scope fixture.' );
					}
					if ( ! isset( $result['authority_id'] ) || ! is_string( $result['authority_id'] ) ) {
						\PHPUnit\Framework\Assert::fail( 'Expected string authority_id in the permissive scope fixture.' );
					}
					if ( ! isset( $result['secret'] ) || ! is_string( $result['secret'] ) ) {
						\PHPUnit\Framework\Assert::fail( 'Expected string secret in the permissive scope fixture.' );
					}
					return $result;
				}

				public function get_constant_names(): array {
					return array();
				}

				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_from_constants retains the production method contract; these inputs do not affect this controlled result.
				public function webhook_from_constants( array $constants ): ?array {
					return null;
				}

				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of authorize_webhook retains the production method contract; these inputs do not affect this controlled result.
				public function authorize_webhook(
					SignedWebhookVerification $verification,
					string $repository_authority_id,
					string $repository
				): bool {
					return false;
				}

				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of repository_target_matches retains the production method contract; these inputs do not affect this controlled result.
				public function repository_target_matches( string $target, string $repository_locator ): bool {
					return false;
				}
			}
		);

		return $catalog;
	}
}
