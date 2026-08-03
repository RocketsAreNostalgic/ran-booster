<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Direct local filesystem operations exercise the encrypted sidecar lifecycle.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\TestCase;
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

	protected function setUp(): void {
		parent::setUp();

		$this->directory = sys_get_temp_dir() . '/ran-booster-webhook-profile-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->secrets = SecretsFileTestFactory::create( $this->path, array(), ShippedSecretPolicyCatalog::create() );
	}

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

	public function testLabelEditPreservesRevisionAndSecretReplacementIncrementsIt(): void {
		$id = $this->secrets->saveWebhook(
			'gh',
			null,
			$this->owner( 'Owner one', 'ExampleOwner' ),
			str_repeat( 'a', 32 )
		);
		self::assertSame( 1, $this->secrets->webhookProfiles( 'gh' )[ $id ]['revision'] );

		$this->secrets->saveWebhook( 'gh', $id, $this->owner( 'Renamed owner', 'ExampleOwner' ), null );
		$renamed = $this->secrets->webhookProfiles( 'gh' )[ $id ];
		self::assertSame( 'Renamed owner', $renamed['label'] );
		self::assertSame( 1, $renamed['revision'] );
		self::assertSame( str_repeat( 'a', 32 ), $this->secrets->webhookMaterials( 'gh' )[ $id ]['secret'] );

		$this->secrets->saveWebhook( 'gh', $id, $this->owner( 'Renamed owner', 'ExampleOwner' ), str_repeat( 'b', 32 ) );
		self::assertSame( 2, $this->secrets->webhookProfiles( 'gh' )[ $id ]['revision'] );
		self::assertSame( str_repeat( 'b', 32 ), $this->secrets->webhookMaterials( 'gh' )[ $id ]['secret'] );
	}

	public function testConditionalDeleteCannotRemoveAConcurrentlyRotatedProfile(): void {
		$id = $this->secrets->saveWebhook(
			'gh',
			null,
			$this->repository( 'Repository', 'owner/example', '101', 'assisted' ),
			str_repeat( 'a', 32 )
		);
		$this->secrets->saveWebhook( 'gh', $id, $this->repository( 'Repository', 'owner/example', '101', 'assisted' ), str_repeat( 'b', 32 ) );

		self::assertFalse( $this->secrets->deleteWebhookIfRevision( 'gh', $id, 1 ) );
		self::assertSame( 2, $this->secrets->webhookProfiles( 'gh' )[ $id ]['revision'] );
		self::assertTrue( $this->secrets->deleteWebhookIfRevision( 'gh', $id, 2 ) );
		self::assertArrayNotHasKey( $id, $this->secrets->webhookProfiles( 'gh' ) );
	}

	public function testScopeTargetAuthorityAndOriginAreImmutable(): void {
		$id = $this->secrets->saveWebhook(
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
				$this->secrets->saveWebhook( 'gh', $id, $metadata, null );
				self::fail( 'Immutable webhook authority metadata must reject edits.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'immutable', $exception->getMessage() );
			}
		}
	}

	public function testOwnerAndRepositoryAuthorityKeysAreUnique(): void {
		$this->secrets->saveWebhook( 'gh', null, $this->owner( 'Owner', 'ExampleOwner' ), str_repeat( 'a', 32 ) );
		$this->secrets->saveWebhook( 'gh', null, $this->repository( 'Repository', 'owner/example', '101' ), str_repeat( 'b', 32 ) );

		foreach ( array(
			$this->owner( 'Duplicate owner', 'exampleowner' ),
			$this->repository( 'Duplicate repository', 'renamed/example', '101' ),
		) as $metadata ) {
			try {
				$this->secrets->saveWebhook( 'gh', null, $metadata, str_repeat( 'c', 32 ) );
				self::fail( 'Duplicate webhook authority must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'Only one webhook secret', $exception->getMessage() );
			}
		}
	}

	public function testProviderProfileCountIsBoundedAtSixteen(): void {
		foreach ( range( 1, SecretsFile::MAX_WEBHOOK_PROFILES ) as $index ) {
			$this->secrets->saveWebhook(
				'gh',
				null,
				$this->owner( 'Owner ' . $index, 'owner' . $index ),
				str_repeat( chr( 96 + $index ), 32 )
			);
		}
		self::assertCount( SecretsFile::MAX_WEBHOOK_PROFILES, $this->secrets->webhookProfiles( 'gh' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'cannot store more than 16' );
		$this->secrets->saveWebhook( 'gh', null, $this->owner( 'Overflow', 'owner17' ), str_repeat( 'z', 32 ) );
	}

	public function testRemovedGlobalScopeAndConstantAreUnavailable(): void {
		$policy = ShippedSecretPolicyCatalog::create()->webhookPolicy( 'gh' );
		self::assertSame( array(), $policy->getConstantNames() );
		self::assertNull( $policy->webhookFromConstants( array( 'RAN_BOOSTER_GITHUB_WEBHOOK_SECRET' => str_repeat( 'a', 32 ) ) ) );

		$this->expectException( RuntimeException::class );
		$this->secrets->saveWebhook(
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

	public function testStorageRejectsUnknownScopesReturnedByAPermissiveProviderPolicy(): void {
		$secrets = SecretsFileTestFactory::create( $this->path, array(), $this->permissiveWebhookPolicyCatalog() );

		foreach ( array( 'global', 'workspace' ) as $scope ) {
			try {
				$secrets->saveWebhook(
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
	private function repository( string $label, string $repository, string $authorityId, string $origin = 'manual' ): array {
		return array(
			'label'        => $label,
			'scope'        => 'repository',
			'target'       => $repository,
			'authority_id' => $authorityId,
			'origin'       => $origin,
		);
	}

	private function permissiveWebhookPolicyCatalog(): ProviderSecretPolicyCatalog {
		$catalog = new ProviderSecretPolicyCatalog();
		$catalog->register(
			ProviderCode::parse( 'gh' ),
			null,
			new class() implements ProviderWebhookPolicy {
				public function getProvider(): ProviderCode {
					return ProviderCode::parse( 'gh' );
				}

				public function getRetainedHeaders(): array {
					return array();
				}

				public function getSignatureHeader(): string {
					return 'x-fixture-signature';
				}

				public function normalizeWebhook( array $metadata, mixed $secret ): array {
					return $metadata + array( 'secret' => $secret );
				}

				public function getConstantNames(): array {
					return array();
				}

				public function webhookFromConstants( array $constants ): ?array {
					return null;
				}

				public function authorizeWebhook(
					SignedWebhookVerification $verification,
					string $repositoryAuthorityId,
					string $repository
				): bool {
					return false;
				}

				public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
					return false;
				}
			}
		);

		return $catalog;
	}
}
