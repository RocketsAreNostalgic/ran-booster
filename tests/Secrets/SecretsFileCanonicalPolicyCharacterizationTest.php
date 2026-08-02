<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Native temporary files exercise the authenticated encrypted-sidecar contract.
// phpcs:disable WordPress.WP.AlternativeFunctions, Generic.Files.OneObjectStructurePerFile.MultipleFound

use PHPUnit\Framework\TestCase;
use RAN\Portability\BlueprintCredential;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\PackageBlueprint;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;
use RAN\RepositoryProvider\ProviderSecretPolicyCatalog;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\Secrets\EncryptedSecretsEnvelopeCodec;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageUnavailable;
use RuntimeException;

/**
 * Freezes the current schema-v2 extension-policy timing without changing it.
 *
 * Secret values are reduced to booleans at the instrumentation boundary so a
 * failed assertion cannot print them.
 */
final class SecretsFileCanonicalPolicyCharacterizationTest extends TestCase {

	private string $directory;
	private string $path;
	private InMemorySiteKeyStore $keyStore;
	private EncryptedSecretsEnvelopeCodec $codec;
	private CanonicalPolicyCallRecorder $calls;
	private ProviderSecretPolicyCatalog $policies;
	private SecretsFile $secrets;

	protected function setUp(): void {
		parent::setUp();

		$this->directory = sys_get_temp_dir() . '/ran-booster-canonical-policy-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.json';
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->keyStore = new InMemorySiteKeyStore( $this->path );
		$this->codec    = new EncryptedSecretsEnvelopeCodec();
		$this->calls    = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$this->policies = $this->catalog( $this->calls );
		$this->secrets  = $this->newSecrets( array(), $this->policies );
	}

	protected function tearDown(): void {
		InMemorySiteKeyStore::reset( $this->path );
		foreach ( array( $this->path, $this->path . '.lock' ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->directory ) ) {
			rmdir( $this->directory );
		}

		parent::tearDown();
	}

	public function testDisplayExactAndStorageReadsRenormalizeEveryActiveStoredRecordUnderLock(): void {
		$this->seedRepresentativeDocument();

		$operations = array(
			'file-backed credential profiles' => fn (): array => $this->secrets->credentialProfiles( 'alpha' ),
			'exact file-backed credential'    => fn (): ?array => $this->secrets->credentialMaterial( 'alpha', 'alpha-credential' ),
			'file-backed webhook profiles'    => fn (): array => $this->secrets->webhookProfiles( 'alpha' ),
			'webhook candidates'              => fn (): array => $this->secrets->webhookMaterials( 'alpha' ),
			'managed-storage readiness'       => function (): null {
				$this->secrets->assertManagedStorageReady();

				return null;
			},
			'healthy-storage check'           => fn (): bool => $this->secrets->hasHealthyManagedStorage(),
			'permission/storage verification' => fn (): bool => $this->secrets->verifyAndSecure(),
			'deletion preflight'              => function (): null {
				$this->secrets->assertManagedStorageDeletable();

				return null;
			},
		);

		foreach ( $operations as $label => $operation ) {
			$this->calls->reset();
			$operation();
			$expected = array(
				'alpha:credential:normalize' => 1,
				'alpha:webhook:normalize'    => 2,
				'beta:credential:normalize'  => 1,
				'beta:webhook:normalize'     => 1,
			) + ( str_contains( $label, 'credential' )
				? array( 'alpha:credential:constants' => 1 )
				: ( str_contains( $label, 'webhook' ) ? array( 'alpha:webhook:constants' => 1 ) : array() ) );
			ksort( $expected, SORT_STRING );

			self::assertSame(
				$expected,
				$this->calls->counts(),
				$label
			);
			self::assertTrue( $this->calls->allNormalizationsSawPlaintext(), $label );
			self::assertTrue( $this->calls->allNormalizationsRanUnderLock(), $label );
		}
	}

	public function testCredentialReplaceRenormalizesTheWholeDocumentThreeTimesPlusTheChangedRecord(): void {
		$this->seedRepresentativeDocument();
		$this->calls->reset();

		$this->secrets->saveCredential(
			'alpha',
			'alpha-credential',
			$this->credentialMetadata( 'Alpha credential renamed' ),
			null
		);

		self::assertSame(
			array(
				'alpha:credential:normalize' => 4,
				'alpha:webhook:normalize'    => 6,
				'beta:credential:normalize'  => 3,
				'beta:webhook:normalize'     => 3,
			),
			$this->calls->counts()
		);
		self::assertTrue( $this->calls->allNormalizationsSawPlaintext() );
		self::assertTrue( $this->calls->allNormalizationsRanUnderLock() );
	}

	public function testWebhookReplaceRenormalizesTheWholeDocumentThreeTimesPlusTheChangedRecord(): void {
		$this->seedRepresentativeDocument();
		$this->calls->reset();

		$this->secrets->saveWebhook(
			'alpha',
			'alpha-owner-one',
			$this->webhookMetadata( 'Alpha owner renamed', 'owner-one' ),
			null
		);

		self::assertSame(
			array(
				'alpha:credential:normalize' => 3,
				'alpha:webhook:normalize'    => 7,
				'beta:credential:normalize'  => 3,
				'beta:webhook:normalize'     => 3,
			),
			$this->calls->counts()
		);
		self::assertTrue( $this->calls->allNormalizationsSawPlaintext() );
		self::assertTrue( $this->calls->allNormalizationsRanUnderLock() );
	}

	public function testEmptyReadsCallOnlyTheRequestedOverlayPolicyAndDoNotCreateStorage(): void {
		$this->calls->reset();

		self::assertSame( array(), $this->secrets->credentialProfiles( 'alpha' ) );
		self::assertSame( array(), $this->secrets->webhookProfiles( 'alpha' ) );
		self::assertFalse( $this->secrets->verifyAndSecure() );
		self::assertFalse( $this->secrets->hasHealthyManagedStorage() );

		self::assertSame(
			array(
				'alpha:credential:constants' => 1,
				'alpha:webhook:constants'    => 1,
			),
			$this->calls->counts()
		);
		self::assertTrue( $this->calls->allEventsRanOutsideLock() );
		self::assertFileDoesNotExist( $this->path );
		self::assertFileDoesNotExist( $this->path . '.lock' );
	}

	public function testMaximumWebhookCandidateReadInvokesOnlyTheRequestedOverlayThenSixteenStoredNormalizations(): void {
		foreach ( range( 1, SecretsFile::MAX_WEBHOOK_PROFILES ) as $index ) {
			$this->secrets->saveWebhook(
				'alpha',
				'alpha-owner-' . $index,
				$this->webhookMetadata( 'Alpha owner ' . $index, 'owner-' . $index ),
				str_repeat( chr( 96 + $index ), 32 )
			);
		}
		$this->calls->reset();

		self::assertSame( SecretsFile::MAX_WEBHOOK_PROFILES, count( $this->secrets->webhookMaterials( 'alpha' ) ) );
		self::assertSame(
			array(
				'alpha:webhook:constants' => 1,
				'alpha:webhook:normalize' => SecretsFile::MAX_WEBHOOK_PROFILES,
			),
			$this->calls->counts()
		);
		self::assertSame( SecretsFile::MAX_WEBHOOK_PROFILES, $this->calls->normalizationsUnderLock() );
		self::assertSame( 0, $this->calls->normalizationsOutsideLock() );
	}

	public function testConstantOverlaysReceiveOnlyRequestedDeclaredNamesOutsideTheLockAndNeverPersist(): void {
		$this->seedRepresentativeDocument();
		$before = hash_file( 'sha256', $this->path );
		self::assertIsString( $before );

		$constants     = array(
			'RAN_BOOSTER_ALPHA_TOKEN'          => 'synthetic-alpha-overlay-value',
			'RAN_BOOSTER_ALPHA_UNUSED'         => 'synthetic-alpha-unused-value',
			'RAN_BOOSTER_ALPHA_WEBHOOK_SECRET' => str_repeat( 'w', 32 ),
			'RAN_BOOSTER_BETA_TOKEN'           => 'synthetic-beta-overlay-value',
			'RAN_BOOSTER_UNDECLARED'           => 'synthetic-undeclared-overlay-value',
		);
		$this->secrets = $this->newSecrets( $constants, $this->policies );

		$this->calls->reset();
		self::assertSame( 'constant', $this->secrets->credentialMaterial( 'alpha', SecretsFile::CONSTANT_PROFILE )['source'] );
		self::assertSame(
			array(
				'alpha:credential:constants' => 1,
				'alpha:credential:normalize' => 1,
			),
			$this->calls->counts()
		);
		self::assertSame(
			array( 'RAN_BOOSTER_ALPHA_TOKEN', 'RAN_BOOSTER_ALPHA_UNUSED' ),
			$this->calls->constantNames( 'alpha', 'credential' )
		);
		self::assertTrue( $this->calls->allEventsRanOutsideLock() );

		$this->calls->reset();
		self::assertTrue(
			array_key_exists( SecretsFile::CONSTANT_PROFILE, $this->secrets->webhookMaterials( 'alpha' ) ),
			'The requested synthetic webhook overlay was not returned.'
		);
		self::assertSame(
			array(
				'alpha:credential:normalize' => 1,
				'alpha:webhook:constants'    => 1,
				'alpha:webhook:normalize'    => 3,
				'beta:credential:normalize'  => 1,
				'beta:webhook:normalize'     => 1,
			),
			$this->calls->counts()
		);
		self::assertSame(
			array( 'RAN_BOOSTER_ALPHA_WEBHOOK_SECRET' ),
			$this->calls->constantNames( 'alpha', 'webhook' )
		);
		self::assertFalse( $this->calls->providerWasCalled( 'beta', 'constants' ) );

		$after = hash_file( 'sha256', $this->path );
		self::assertSame( $before, $after );
		$plaintext = $this->decryptedDocument();
		self::assertFalse( str_contains( $plaintext, 'synthetic-alpha-overlay-value' ), 'Credential overlay entered the sidecar.' );
		self::assertFalse( str_contains( $plaintext, str_repeat( 'w', 32 ) ), 'Webhook overlay entered the sidecar.' );
		self::assertFalse( str_contains( $plaintext, SecretsFile::CONSTANT_PROFILE ), 'A constant profile entered the sidecar.' );
	}

	public function testInactiveProviderRecordsStayOpaqueAndSurviveAnUnrelatedCanonicalRewrite(): void {
		$this->seedRepresentativeDocument();
		$before = $this->decodedDocument();

		$activeCalls    = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$activePolicies = new ProviderSecretPolicyCatalog();
		$activePolicies->register(
			ProviderCode::parse( 'beta' ),
			new RecordingCredentialPolicy( 'beta', $activeCalls ),
			new RecordingWebhookPolicy( 'beta', $activeCalls )
		);
		$activeSecrets = $this->newSecrets( array(), $activePolicies );

		self::assertTrue(
			array_key_exists( 'beta-credential', $activeSecrets->credentialProfiles( 'beta' ) ),
			'The active provider could not read its display-safe profile.'
		);
		self::assertSame(
			array(
				'beta:credential:constants' => 1,
				'beta:credential:normalize' => 1,
				'beta:webhook:normalize'    => 1,
			),
			$activeCalls->counts()
		);

		$activeCalls->reset();
		$activeSecrets->saveCredential(
			'beta',
			'beta-credential',
			$this->credentialMetadata( 'Beta credential renamed' ),
			null
		);
		$after = $this->decodedDocument();

		self::assertSame(
			$this->recordDigest( $before[ SecretsFile::CREDENTIALS ]['alpha'] ),
			$this->recordDigest( $after[ SecretsFile::CREDENTIALS ]['alpha'] )
		);
		self::assertSame(
			$this->recordDigest( $before[ SecretsFile::WEBHOOKS ]['alpha'] ),
			$this->recordDigest( $after[ SecretsFile::WEBHOOKS ]['alpha'] )
		);
		self::assertSame(
			array(
				'beta:credential:normalize' => 4,
				'beta:webhook:normalize'    => 3,
			),
			$activeCalls->counts()
		);
	}

	public function testSelfDestructFilteringAndPurgeInvokePolicyBeforeWithholdingOrDeletion(): void {
		$this->secrets->saveCredential(
			'alpha',
			'expired-credential',
			$this->credentialMetadata( 'Expired credential' ) + array(
				'self_destruct' => true,
				'destroy_on'    => '2020-01-01',
			),
			'synthetic-expired-value'
		);
		$this->secrets->saveCredential(
			'alpha',
			'live-credential',
			$this->credentialMetadata( 'Live credential' ),
			'synthetic-live-value'
		);
		$this->calls->reset();

		self::assertArrayNotHasKey( 'expired-credential', $this->secrets->credentialProfiles( 'alpha' ) );
		self::assertSame( 2, $this->calls->count( 'alpha', 'credential', 'normalize' ) );
		self::assertTrue( $this->calls->allNormalizationsSawPlaintext() );

		$this->calls->reset();
		self::assertSame( array( 'alpha' => array( 'expired-credential' ) ), $this->secrets->purgeExpiredCredentials() );
		self::assertSame( 4, $this->calls->count( 'alpha', 'credential', 'normalize' ) );
		self::assertTrue( $this->calls->allNormalizationsRanUnderLock() );
	}

	public function testPortabilityValidatesBeforeLockThenRenormalizesAtReadWriteAndReadbackBoundaries(): void {
		$this->secrets->saveCredential(
			'alpha',
			'alpha-credential',
			$this->credentialMetadata( 'Alpha credential' ),
			'synthetic-alpha-value'
		);
		$credential = new BlueprintCredential(
			'alpha',
			'Portable credential',
			'api-key',
			array( 'tenant' => 'fixture' ),
			'synthetic-portable-value',
			array(
				array(
					'type'       => 'plugin',
					'identifier' => 'fixture/fixture.php',
				),
			)
		);
		$blueprint  = new PackageBlueprint(
			array(
				new BlueprintPackage(
					'plugin',
					'fixture/fixture.php',
					'Fixture',
					'alpha',
					'fixture-repository-id',
					'fixture/repository',
					'main',
					null
				),
			),
			array( $credential )
		);
		$this->calls->reset();

		$ids = $this->secrets->importCredentialsIfAbsent( $blueprint, $credential );
		self::assertCount( 1, $ids );
		self::assertSame( 6, $this->calls->count( 'alpha', 'credential', 'normalize' ) );
		self::assertSame( 1, $this->calls->normalizationsOutsideLock() );
		self::assertSame( 5, $this->calls->normalizationsUnderLock() );

		$this->calls->reset();
		self::assertSame( $ids, $this->secrets->importCredentialsIfAbsent( $blueprint, $credential ) );
		self::assertSame( 3, $this->calls->count( 'alpha', 'credential', 'normalize' ) );
		self::assertSame( 1, $this->calls->normalizationsOutsideLock() );
		self::assertSame( 2, $this->calls->normalizationsUnderLock() );
	}

	public function testTamperingFailsBeforePolicyButAuthenticatedNonCanonicalInputReachesPolicyBeforeFailure(): void {
		$this->seedRepresentativeDocument();
		$canonicalEnvelope = (string) file_get_contents( $this->path );
		$tampered          = json_decode( $canonicalEnvelope, true, 4, JSON_THROW_ON_ERROR );
		self::assertIsArray( $tampered );
		$tampered['ciphertext'][12] = 'A' === $tampered['ciphertext'][12] ? 'B' : 'A';
		self::assertNotFalse(
			file_put_contents( $this->path, json_encode( $tampered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) . "\n" )
		);
		self::assertTrue( chmod( $this->path, 0600 ) );
		$this->calls->reset();

		$this->expectStorageFailure( fn (): bool => $this->secrets->hasHealthyManagedStorage() );
		self::assertSame( array(), $this->calls->counts() );

		self::assertNotFalse( file_put_contents( $this->path, $canonicalEnvelope ) );
		self::assertTrue( chmod( $this->path, 0600 ) );
		$document     = $this->decodedDocument();
		$nonCanonical = array(
			SecretsFile::WEBHOOKS    => $document[ SecretsFile::WEBHOOKS ],
			SecretsFile::CREDENTIALS => $document[ SecretsFile::CREDENTIALS ],
			'schema_version'         => SecretsFile::SCHEMA_VERSION,
		);
		$this->writeAuthenticatedDocument( $nonCanonical );
		$this->calls->reset();

		$this->expectStorageFailure( fn (): bool => $this->secrets->hasHealthyManagedStorage() );
		self::assertSame(
			array(
				'alpha:credential:normalize' => 1,
				'alpha:webhook:normalize'    => 2,
				'beta:credential:normalize'  => 1,
				'beta:webhook:normalize'     => 1,
			),
			$this->calls->counts()
		);
		self::assertTrue( $this->calls->allNormalizationsRanUnderLock() );
	}

	public function testAuthenticatedMalformedShapeFailsBeforeAnyPolicyCallback(): void {
		$this->seedRepresentativeDocument();
		$this->writeAuthenticatedDocument(
			array(
				'schema_version'         => SecretsFile::SCHEMA_VERSION,
				SecretsFile::CREDENTIALS => 'not-a-provider-map',
				SecretsFile::WEBHOOKS    => array(),
			)
		);
		$this->calls->reset();

		$this->expectStorageFailure( fn (): bool => $this->secrets->hasHealthyManagedStorage() );
		self::assertSame( array(), $this->calls->counts() );
	}

	public function testPolicyVersionDriftCanMakeAnAuthenticatedStoredRecordUnreadableWithoutRewritingIt(): void {
		$this->secrets->saveCredential(
			'alpha',
			'alpha-credential',
			$this->credentialMetadata( 'Alpha credential' ),
			'synthetic-alpha-value'
		);
		$before = hash_file( 'sha256', $this->path );
		self::assertIsString( $before );

		$driftCalls    = new CanonicalPolicyCallRecorder( $this->path . '.lock' );
		$driftPolicies = new ProviderSecretPolicyCatalog();
		$driftPolicies->register(
			ProviderCode::parse( 'alpha' ),
			new RecordingCredentialPolicy( 'alpha', $driftCalls, true ),
			null
		);
		$drifted = $this->newSecrets( array(), $driftPolicies );

		$this->expectStorageFailure( fn (): array => $drifted->credentialProfiles( 'alpha' ) );
		self::assertSame(
			array(
				'alpha:credential:constants' => 1,
				'alpha:credential:normalize' => 1,
			),
			$driftCalls->counts()
		);
		self::assertTrue( $driftCalls->allNormalizationsRanUnderLock() );
		self::assertSame( $before, hash_file( 'sha256', $this->path ) );
	}

	public function testCanonicalWritesSortProvidersAndIdsAndReplaceTheCiphertextAtomically(): void {
		$this->secrets->saveCredential(
			'beta',
			'z-credential',
			$this->credentialMetadata( 'Z credential' ),
			'synthetic-z-value'
		);
		$before = lstat( $this->path );
		self::assertIsArray( $before );

		$this->secrets->saveCredential(
			'alpha',
			'a-credential',
			$this->credentialMetadata( 'A credential' ),
			'synthetic-a-value'
		);
		$after = lstat( $this->path );
		self::assertIsArray( $after );
		$document = $this->decodedDocument();

		self::assertSame( array( 'alpha', 'beta' ), array_keys( $document[ SecretsFile::CREDENTIALS ] ) );
		self::assertNotSame( $before['ino'], $after['ino'] );
		self::assertSame( 0600, $after['mode'] & 0777 );
		self::assertSame( 1, $after['nlink'] );
		$canonical = json_encode( $document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		self::assertSame( hash( 'sha256', $canonical ), hash( 'sha256', $this->decryptedDocument() ) );
	}

	private function seedRepresentativeDocument(): void {
		$this->secrets->saveCredential(
			'alpha',
			'alpha-credential',
			$this->credentialMetadata( 'Alpha credential' ),
			'synthetic-alpha-value'
		);
		$this->secrets->saveCredential(
			'beta',
			'beta-credential',
			$this->credentialMetadata( 'Beta credential' ),
			'synthetic-beta-value'
		);
		$this->secrets->saveWebhook(
			'alpha',
			'alpha-owner-one',
			$this->webhookMetadata( 'Alpha owner one', 'owner-one' ),
			str_repeat( 'a', 32 )
		);
		$this->secrets->saveWebhook(
			'alpha',
			'alpha-owner-two',
			$this->webhookMetadata( 'Alpha owner two', 'owner-two' ),
			str_repeat( 'b', 32 )
		);
		$this->secrets->saveWebhook(
			'beta',
			'beta-owner-one',
			$this->webhookMetadata( 'Beta owner one', 'owner-one' ),
			str_repeat( 'c', 32 )
		);
	}

	/** @return array<string, mixed> */
	private function credentialMetadata( string $label ): array {
		return array(
			'label'         => $label,
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'fixture' ),
		);
	}

	/** @return array<string, mixed> */
	private function webhookMetadata( string $label, string $owner ): array {
		return array(
			'label'        => $label,
			'scope'        => 'owner',
			'target'       => $owner,
			'authority_id' => '',
			'origin'       => 'manual',
		);
	}

	private function catalog( CanonicalPolicyCallRecorder $calls ): ProviderSecretPolicyCatalog {
		$catalog = new ProviderSecretPolicyCatalog();
		foreach ( array( 'alpha', 'beta' ) as $provider ) {
			$catalog->register(
				ProviderCode::parse( $provider ),
				new RecordingCredentialPolicy( $provider, $calls ),
				new RecordingWebhookPolicy( $provider, $calls )
			);
		}

		return $catalog;
	}

	/** @param array<string, mixed> $constants */
	private function newSecrets( array $constants, ProviderSecretPolicyCatalog $policies ): SecretsFile {
		return new SecretsFile(
			$this->path,
			$constants,
			$policies,
			$this->keyStore,
			$this->codec
		);
	}

	/** @return array<string, mixed> */
	private function decodedDocument(): array {
		$document = json_decode( $this->decryptedDocument(), true, 16, JSON_THROW_ON_ERROR );
		if ( ! is_array( $document ) ) {
			throw new RuntimeException( 'The synthetic encrypted document did not decode to an array.' );
		}

		return $document;
	}

	/** @param array<string, mixed> $records */
	private function recordDigest( array $records ): string {
		return hash(
			'sha256',
			json_encode( $records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	private function decryptedDocument(): string {
		$key = $this->keyStore->load( false );
		self::assertIsString( $key );

		return $this->codec->decrypt( (string) file_get_contents( $this->path ), $key );
	}

	/** @param array<string, mixed> $document */
	private function writeAuthenticatedDocument( array $document ): void {
		$key = $this->keyStore->load( false );
		self::assertIsString( $key );
		$plaintext = json_encode( $document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		self::assertNotFalse( file_put_contents( $this->path, $this->codec->encrypt( $plaintext, $key ) ) );
		self::assertTrue( chmod( $this->path, 0600 ) );
	}

	/** @param callable(): mixed $operation */
	private function expectStorageFailure( callable $operation ): void {
		try {
			$operation();
			self::fail( 'The invalid authenticated sidecar must fail closed.' );
		} catch ( SecretsStorageUnavailable $failure ) {
			self::assertSame( 'local_secret_store_unavailable', $failure->getDiagnosticId() );
		}
	}
}

final class CanonicalPolicyCallRecorder {

	/** @var list<array{provider:string,kind:string,method:string,has_plaintext:bool,lock_held:bool,names:list<string>}> */
	private array $events = array();

	public function __construct( private readonly string $lockPath ) {
	}

	/** @param list<string> $names */
	public function record( string $provider, string $kind, string $method, bool $hasPlaintext, array $names = array() ): void {
		$this->events[] = array(
			'provider'      => $provider,
			'kind'          => $kind,
			'method'        => $method,
			'has_plaintext' => $hasPlaintext,
			'lock_held'     => $this->lockIsHeld(),
			'names'         => $names,
		);
	}

	public function reset(): void {
		$this->events = array();
	}

	/** @return array<string, int> */
	public function counts(): array {
		$counts = array();
		foreach ( $this->events as $event ) {
			$key            = $event['provider'] . ':' . $event['kind'] . ':' . $event['method'];
			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}
		ksort( $counts, SORT_STRING );

		return $counts;
	}

	public function count( string $provider, string $kind, string $method ): int {
		return $this->counts()[ $provider . ':' . $kind . ':' . $method ] ?? 0;
	}

	/** @return list<string> */
	public function constantNames( string $provider, string $kind ): array {
		foreach ( $this->events as $event ) {
			if ( $provider === $event['provider'] && $kind === $event['kind'] && 'constants' === $event['method'] ) {
				return $event['names'];
			}
		}

		return array();
	}

	public function providerWasCalled( string $provider, string $method ): bool {
		foreach ( $this->events as $event ) {
			if ( $provider === $event['provider'] && $method === $event['method'] ) {
				return true;
			}
		}

		return false;
	}

	public function allNormalizationsSawPlaintext(): bool {
		$normalizations = array_filter(
			$this->events,
			static fn ( array $event ): bool => 'normalize' === $event['method']
		);

		return array() !== $normalizations
			&& array_reduce(
				$normalizations,
				static fn ( bool $carry, array $event ): bool => $carry && $event['has_plaintext'],
				true
			);
	}

	public function allNormalizationsRanUnderLock(): bool {
		$normalizations = array_filter(
			$this->events,
			static fn ( array $event ): bool => 'normalize' === $event['method']
		);

		return array() !== $normalizations
			&& array_reduce(
				$normalizations,
				static fn ( bool $carry, array $event ): bool => $carry && $event['lock_held'],
				true
			);
	}

	public function allEventsRanOutsideLock(): bool {
		return array() !== $this->events
			&& array_reduce(
				$this->events,
				static fn ( bool $carry, array $event ): bool => $carry && ! $event['lock_held'],
				true
			);
	}

	public function normalizationsUnderLock(): int {
		return count(
			array_filter(
				$this->events,
				static fn ( array $event ): bool => 'normalize' === $event['method'] && $event['lock_held']
			)
		);
	}

	public function normalizationsOutsideLock(): int {
		return count(
			array_filter(
				$this->events,
				static fn ( array $event ): bool => 'normalize' === $event['method'] && ! $event['lock_held']
			)
		);
	}

	private function lockIsHeld(): bool {
		if ( ! is_file( $this->lockPath ) ) {
			return false;
		}

		$handle = fopen( $this->lockPath, 'r+b' );
		if ( false === $handle ) {
			return true;
		}

		try {
			$acquired = flock( $handle, LOCK_EX | LOCK_NB );
			if ( $acquired ) {
				flock( $handle, LOCK_UN );
			}

			return ! $acquired;
		} finally {
			fclose( $handle );
		}
	}
}

final readonly class RecordingCredentialPolicy implements ProviderCredentialPolicy {

	public function __construct(
		private string $provider,
		private CanonicalPolicyCallRecorder $calls,
		private bool $rejectStoredMaterial = false
	) {
	}

	public function getProvider(): ProviderCode {
		return ProviderCode::parse( $this->provider );
	}

	public function normalizeCredential( array $metadata, mixed $secret ): array {
		$this->calls->record( $this->provider, 'credential', 'normalize', is_string( $secret ) && '' !== $secret );
		if ( $this->rejectStoredMaterial ) {
			throw new RuntimeException( 'The upgraded synthetic policy rejects the stored record.' );
		}

		return array(
			'label'         => is_string( $metadata['label'] ?? null ) ? trim( $metadata['label'] ) : '',
			'kind'          => is_string( $metadata['kind'] ?? null ) ? $metadata['kind'] : '',
			'configuration' => is_array( $metadata['configuration'] ?? null ) ? $metadata['configuration'] : array(),
			'secret'        => is_string( $secret ) ? trim( $secret ) : '',
		);
	}

	public function getConstantNames(): array {
		return array(
			'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_TOKEN',
			'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_UNUSED',
		);
	}

	public function credentialFromConstants( array $constants ): ?array {
		$this->calls->record( $this->provider, 'credential', 'constants', false, array_keys( $constants ) );
		$name   = 'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_TOKEN';
		$secret = $constants[ $name ] ?? null;
		if ( ! is_string( $secret ) || '' === trim( $secret ) ) {
			return null;
		}

		return array(
			'label'         => ucfirst( $this->provider ) . ' constant',
			'kind'          => 'api-key',
			'configuration' => array( 'tenant' => 'constant' ),
			'secret'        => $secret,
		);
	}
}

final readonly class RecordingWebhookPolicy implements ProviderWebhookPolicy {

	public function __construct(
		private string $provider,
		private CanonicalPolicyCallRecorder $calls
	) {
	}

	public function getProvider(): ProviderCode {
		return ProviderCode::parse( $this->provider );
	}

	public function getRetainedHeaders(): array {
		return array( 'x-fixture-signature' );
	}

	public function getSignatureHeader(): string {
		return 'x-fixture-signature';
	}

	public function normalizeWebhook( array $metadata, mixed $secret ): array {
		$this->calls->record( $this->provider, 'webhook', 'normalize', is_string( $secret ) && '' !== $secret );

		return array(
			'label'        => is_string( $metadata['label'] ?? null ) ? trim( $metadata['label'] ) : '',
			'scope'        => is_string( $metadata['scope'] ?? null ) ? $metadata['scope'] : '',
			'target'       => is_string( $metadata['target'] ?? null ) ? $metadata['target'] : '',
			'authority_id' => is_string( $metadata['authority_id'] ?? null ) ? $metadata['authority_id'] : '',
			'secret'       => is_string( $secret ) ? $secret : '',
		);
	}

	public function getConstantNames(): array {
		return array( 'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_WEBHOOK_SECRET' );
	}

	public function webhookFromConstants( array $constants ): ?array {
		$this->calls->record( $this->provider, 'webhook', 'constants', false, array_keys( $constants ) );
		$name   = 'RAN_BOOSTER_' . strtoupper( $this->provider ) . '_WEBHOOK_SECRET';
		$secret = $constants[ $name ] ?? null;
		if ( ! is_string( $secret ) || '' === trim( $secret ) ) {
			return null;
		}

		return array(
			'label'        => ucfirst( $this->provider ) . ' constant webhook',
			'scope'        => 'owner',
			'target'       => $this->provider . '-owner',
			'authority_id' => '',
			'secret'       => $secret,
		);
	}

	public function authorizeWebhook( SignedWebhookVerification $verification, string $repositoryAuthorityId, string $repository ): bool {
		return false;
	}

	public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
		return $target === $repositoryLocator;
	}
}
