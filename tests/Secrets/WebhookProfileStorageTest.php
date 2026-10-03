<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Direct local filesystem operations exercise the encrypted sidecar lifecycle.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\InvalidWebhookInput;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\Secrets\SecretsFile;
use RuntimeException;
use Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;

final class WebhookProfileStorageTest extends TestCase {

	private string $directory;
	private string $path;
	private SecretsFile $secrets;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		parent::setUp();

		$this->directory = sys_get_temp_dir() . '/ran-booster-webhook-profile-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->secrets = SecretsFileTestFactory::create( $this->path, array(), ShippedSecretPolicyCatalog::create() );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		InMemorySiteKeyStore::reset( $this->path );
		foreach ( array( $this->path, $this->path . '.lock' ) as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
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
					return $metadata + array( 'secret' => $secret );
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
