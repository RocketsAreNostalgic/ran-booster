<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\CredentialSelfDestructPurger;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RAN\Secrets\EncryptedSecretsEnvelopeCodec;
use RAN\Secrets\SecretsFile;
use RAN\Tests\RepositoryProvider\Support\ShippedSecretPolicyCatalog;
use RAN\Tests\Secrets\InMemorySiteKeyStore;
use RAN\Tests\Secrets\SecretsFileTestFactory;
use RAN\Tests\Support\CapturesBoosterLog;
use RAN\Tests\Support\InMemoryCredentialExpiryObservationStore;
use RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

require_once dirname( __DIR__ ) . '/Secrets/MaterialTagWordPressFunctions.php';

/** A validation result is recorded only for the credential material that was checked. */
final class CredentialMaterialTagTest extends TestCase {
	use CapturesBoosterLog;

	private const UNAVAILABLE = '[ran-booster] Credential expiry observation unavailable. {"operation":"credential_expiry_observation","outcome_code":"material_tag_unavailable"}';

	private string $directory;
	private string $path;
	private SecretsFile $secrets;
	private InMemoryCredentialExpiryObservationStore $observations;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-material-tag-' . bin2hex( random_bytes( 8 ) );
		$this->path      = $this->directory . '/secrets.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Disposable native directory for the encrypted sidecar fixture.
		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->secrets      = SecretsFileTestFactory::create( $this->path, array(), ShippedSecretPolicyCatalog::create() );
		$this->observations = new InMemoryCredentialExpiryObservationStore();
		$this->observations->read_material_tags_with( $this->secrets->credential_material_tag( ... ) );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->stop_log_capture();
		InMemorySiteKeyStore::reset( $this->path );
		foreach ( array( $this->path, $this->path . '.lock' ) as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only this fixture's disposable files.
				unlink( $file );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only this fixture's disposable directory.
		rmdir( $this->directory );
	}

	/** @return iterable<string, array{string, array{bool, bool}}> */
	public static function rotation_orders(): iterable {
		yield 'replaced before the stale result is recorded' => array( 'before', array( false, false ) );
		yield 'replaced while the stale observation is written' => array( 'during', array( false, false ) );
		yield 'stale result recorded before the replacement' => array( 'after', array( true, true ) );
	}

	/** @param array{bool, bool} $stale_outcome */
	#[DataProvider( 'rotation_orders' )]
	public function test_a_replacement_during_validation_keeps_its_own_expiry_and_deadline( string $order, array $stale_outcome ): void {
		$this->save( 'original-secret' );
		$stale = $this->validation( '2027-01-01' );
		if ( 'before' === $order ) {
			$this->replace();
		} elseif ( 'during' === $order ) {
			$this->observations->before_write = fn () => $this->replace();
		}
		self::assertSame( $stale_outcome, $stale() );
		if ( 'after' === $order ) {
			$this->replace();
		}

		self::assertSame( array( true, true ), $this->validation( '2027-06-01' )() );
		self::assertSame( '2027-06-01T00:00:00Z', $this->observations->get( 'gh', 'cred_1' )['provider_expires_at'] ?? null );
		self::assertSame( '2027-06-01', $this->profile()['destroy_on'] );
		// A later validation of the unchanged replacement refreshes only the check time; the sidecar is not rewritten.
		$before = hash_file( 'sha256', $this->path );
		self::assertSame( array( true, true ), $this->validation( '2027-06-01', '2026-11-28T00:00:00Z' )() );
		self::assertSame( $before, hash_file( 'sha256', $this->path ) );
		self::assertSame(
			array(
				'event_generation'    => 1,
				'provider_checked_at' => '2026-11-28T00:00:00Z',
				'provider_expires_at' => '2027-06-01T00:00:00Z',
			),
			$this->observations->get( 'gh', 'cred_1' )
		);
	}

	/** @return iterable<string, array{string}> */
	public static function cleanups(): iterable {
		yield 'replacement validated in the same process before its reset' => array( 'reset' );
		yield 'replacement validated while its reset is written' => array( 'reset racing' );
		yield 'purged ID reused and validated while the purge cleanup is written' => array( 'purge racing' );
	}

	#[DataProvider( 'cleanups' )]
	public function test_cleanup_keeps_only_the_current_material_observation( string $cleanup ): void {
		$this->save( 'original-secret' );
		// A past provider date makes the original due for removal by the purger.
		$this->validation( 'purge racing' === $cleanup ? '2020-01-01' : '2027-01-01' )();
		$this->observations->set_manual_expiry( 'gh', 'cred_1', '2026-12-01' );
		$renew = function () use ( $cleanup ): void {
			if ( 'purge racing' === $cleanup ) {
				$this->save( 'new-secret' );
			}
			self::assertSame( array( true, true ), $this->validation( '2027-06-01' )() );
		};
		if ( 'purge racing' === $cleanup ) {
			$this->observations->before_write = $renew;
			$evidence                         = new class() extends RepositoryBranchCheckEvidenceStore {
				public function bump_profile_generation( string $provider, string $profile_id ): void {
					unset( $provider, $profile_id );
				}
			};
			$this->start_log_capture();
			( new CredentialSelfDestructPurger( $this->secrets, $this->observations, new InMemoryPublicRepositoryLookupProfileStore(), $evidence ) )->purge();
			self::assertSame( array( '[ran-booster] Expired credential purged. {"operation":"credential_purge","provider":"gh"}' ), $this->captured_log_lines() );
		} else {
			$this->save( 'new-secret' );
			if ( 'reset' === $cleanup ) {
				$renew();
			} else {
				$this->observations->before_write = $renew;
			}
			$this->observations->reset_for_replacement( 'gh', 'cred_1' );
		}

		// The manual date is cleared as before; the new material's observation survives.
		self::assertSame(
			( 'purge racing' === $cleanup ? array() : array( 'event_generation' => 1 ) ) + array(
				'provider_checked_at' => '2026-11-27T00:00:00Z',
				'provider_expires_at' => '2027-06-01T00:00:00Z',
			),
			$this->observations->get( 'gh', 'cred_1' )
		);
	}

	/** @return iterable<string, array{bool}> */
	public static function constant_request_orders(): iterable {
		yield 'newer request records first' => array( true );
		yield 'older request records first' => array( false );
	}

	#[DataProvider( 'constant_request_orders' )]
	public function test_requests_holding_old_and_new_constants_keep_separate_observations( bool $newer_first ): void {
		[ $older, $old_tag ]    = $this->constant_request( 'a' );
		[ $newer, $new_tag ]    = $this->constant_request( 'b' );
		[ $latest, $third_tag ] = $this->constant_request( 'c' );
		$newer->document        = &$older->document;
		$latest->document       = &$older->document;
		$record                 = static fn ( InMemoryCredentialExpiryObservationStore $store, string $tag, string $date ): bool => $store->record_provider_expiry( 'gh', SecretsFile::CONSTANT_PROFILE, CredentialExpiryReport::known( $date . 'T00:00:00Z' ), '2026-11-27T00:00:00Z', $tag );

		self::assertTrue( $newer_first ? $record( $newer, $new_tag, '2027-06-01' ) : $record( $older, $old_tag, '2027-01-01' ) );
		self::assertTrue( $newer_first ? $record( $older, $old_tag, '2027-01-01' ) : $record( $newer, $new_tag, '2027-06-01' ) );
		self::assertSame( '2027-06-01T00:00:00Z', $newer->get( 'gh', SecretsFile::CONSTANT_PROFILE )['provider_expires_at'] ?? null );
		self::assertSame( '2027-01-01T00:00:00Z', $older->get( 'gh', SecretsFile::CONSTANT_PROFILE )['provider_expires_at'] ?? null );
		// Refreshing the first-written entry protects it: a third constant evicts the least recently written.
		self::assertTrue( $newer_first ? $record( $newer, $new_tag, '2027-06-01' ) : $record( $older, $old_tag, '2027-01-01' ) );
		self::assertTrue( $record( $latest, $third_tag, '2028-01-01' ) );
		self::assertSame( array( $newer_first ? $new_tag : $old_tag, $third_tag ), array_keys( $older->document['profiles']['gh'][ SecretsFile::CONSTANT_PROFILE ]['provider_observations'] ) );
	}

	/** @return iterable<string, array{string, string, string, string}> */
	public static function provider_dates(): iterable {
		yield 'later date first' => array( '2030-01-01', '2027-05-01', '2027-04-01', '2027-04-01' );
		yield 'earlier date first' => array( '2030-01-01', '2027-04-01', '2027-05-01', '2027-04-01' );
		yield 'repeated date' => array( '2030-01-01', '2027-04-01', '2027-04-01', '2027-04-01' );
		yield 'earlier manual date' => array( '2027-03-01', '2027-05-01', '2027-04-01', '2027-03-01' );
	}

	#[DataProvider( 'provider_dates' )]
	public function test_the_removal_deadline_keeps_the_earliest_date( string $manual, string $first, string $second, string $effective ): void {
		$this->save( 'original-secret', destroy_on: $manual );
		$this->validation( $first )();
		$before = hash_file( 'sha256', $this->path );

		self::assertSame( array( true, true ), $this->validation( $second )() );
		self::assertSame( $effective, $this->profile()['destroy_on'] );
		self::assertSame( $second . 'T00:00:00Z', $this->observations->get( 'gh', 'cred_1' )['provider_expires_at'] ?? null );
		if ( $first <= $second ) {
			self::assertSame( $before, hash_file( 'sha256', $this->path ), 'A date that cannot shorten the deadline writes nothing.' );
		}
	}

	public function test_the_store_returns_provider_fields_only_for_the_current_tag_and_fails_closed(): void {
		$this->save( 'original-secret' );
		$this->validation( '2027-01-01' )();
		$this->observations->set_manual_expiry( 'gh', 'cred_1', '2026-12-01' );
		$current = $this->observations->get( 'gh', 'cred_1' );
		self::assertSame( '2027-01-01T00:00:00Z', $current['provider_expires_at'] ?? null );
		self::assertArrayNotHasKey( 'material_tag', $current );

		$unbound           = new InMemoryCredentialExpiryObservationStore();
		$unbound->document = $this->observations->document;
		$throwing          = clone $unbound;
		$throwing->read_material_tags_with( static fn (): string => throw new \RuntimeException( 'Fixture secrets unavailable.' ) );
		$manual_only = array( 'manual_expires_on' => '2026-12-01' );
		$this->start_log_capture();
		self::assertSame( $manual_only, $unbound->get( 'gh', 'cred_1' ) );
		self::assertFalse( $unbound->record_provider_expiry( 'gh', 'cred_1', CredentialExpiryReport::unknown(), '2026-11-28T00:00:00Z', $this->tag() ) );
		self::assertSame( array( 'gh' => array( 'cred_1' => $manual_only ) ), $throwing->observations() );
		// A malformed tag from the reader is never written; a numeric one would become an integer key.
		$malformed = clone $unbound;
		$malformed->read_material_tags_with( static fn (): string => '123' );
		self::assertFalse( $malformed->record_provider_expiry( 'gh', 'cred_1', CredentialExpiryReport::unknown(), '2026-11-28T00:00:00Z', '123' ) );
		self::assertSame( array_fill( 0, 4, self::UNAVAILABLE ), $this->captured_log_lines() );
		self::assertSame( $this->observations->document, $unbound->document );
		self::assertSame( $this->observations->document, $malformed->document );
		// Not trusted: a non-array entry, two entries on a stored credential, and the earlier top-level fields.
		$observation = array( 'provider_checked_at' => '2026-11-27T00:00:00Z' );
		foreach ( array(
			array( 'provider_observations' => array( $this->tag() => new \stdClass() ) ),
			array(
				'provider_observations' => array(
					$this->tag()          => $observation,
					str_repeat( 'a', 32 ) => $observation,
				),
			),
			array( 'material_tag' => $this->tag() ) + $observation,
		) as $corrupt ) {
			$copy                                       = clone $this->observations;
			$copy->document['profiles']['gh']['cred_1'] = $corrupt + $manual_only;
			self::assertSame( $manual_only, $copy->get( 'gh', 'cred_1' ) );
		}
		// Production gives the shared store its reader when it builds the expiry reminder or the purger.
		foreach ( array(
			fn ( $store ) => new \RAN\Admin\CredentialExpiryReminder( new \RAN\RepositoryProvider\ProviderRegistry( array() ), $this->secrets, $store ),
			fn ( $store ) => new CredentialSelfDestructPurger( $this->secrets, $store, new InMemoryPublicRepositoryLookupProfileStore(), new RepositoryBranchCheckEvidenceStore() ),
		) as $build ) {
			$store = clone $unbound;
			$build( $store );
			self::assertSame( $current, $store->get( 'gh', 'cred_1' ) );
		}

		// A replacement saved without resetting the option still hides the old observation.
		$this->save( 'replacement-secret' );
		self::assertSame( $manual_only, $this->observations->get( 'gh', 'cred_1' ) );
		// A stored credential keeps a single observation slot, even when its record held two.
		$this->observations->document['profiles']['gh']['cred_1']['provider_observations'] = array(
			$this->tag()          => $observation,
			str_repeat( 'a', 32 ) => $observation,
		);
		$this->validation( '2027-06-01' )();
		self::assertSame( array( $this->tag() ), array_keys( $this->observations->document['profiles']['gh']['cred_1']['provider_observations'] ) );
	}

	public function test_only_a_new_secret_or_an_untagged_record_gets_a_new_tag(): void {
		$this->save( 'original-secret' );
		$tag = $this->tag();
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{32}\z/', $tag );
		$this->save( null, 'Renamed' );
		self::assertSame( $tag, $this->tag() );
		$this->save( 'replacement-secret' );
		self::assertNotSame( $tag, $this->tag() );
		self::assertArrayNotHasKey( 'material_tag', $this->secrets->credential_material( 'gh', 'cred_1' ) ?? array() );

		// A record written before tags existed records nothing until its next write adds one.
		$this->strip_tag();
		self::assertNull( $this->secrets->credential_material_tag( 'gh', 'cred_1' ) );
		$before = hash_file( 'sha256', $this->path );
		self::assertFalse( $this->secrets->record_credential_provider_expiry( 'gh', 'cred_1', '2027-01-01', $tag ) );
		self::assertSame( $before, hash_file( 'sha256', $this->path ) );
		$this->save( null, 'Tagged again' );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{32}\z/', $this->tag() );
	}

	public function test_a_constant_is_tagged_with_a_site_keyed_hmac_and_observed_without_a_storage_write(): void {
		$value           = 'ghp_' . str_repeat( 'a', 36 );
		[ $store, $tag ] = $this->constant_request( 'a' );
		self::assertMatchesRegularExpression( '/\A[0-9a-f]{32}\z/', $tag );
		foreach ( array( hash( 'sha256', $value ), hash( 'sha256', "gh\0" . $value ), hash_hmac( 'sha256', "gh\0" . $value, 'ran-booster-credential-material-tag-v1' ) ) as $unkeyed ) {
			self::assertNotSame( substr( $unkeyed, 0, 32 ), $tag );
		}

		self::assertTrue( $store->record_provider_expiry( 'gh', SecretsFile::CONSTANT_PROFILE, CredentialExpiryReport::known( '2027-01-01T00:00:00Z' ), '2026-11-27T00:00:00Z', $tag ) );
		self::assertSame( '2027-01-01T00:00:00Z', $store->get( 'gh', SecretsFile::CONSTANT_PROFILE )['provider_expires_at'] ?? null );
		self::assertFileDoesNotExist( $this->path );
		// Rotating the site's salts makes the observation stale: the fail-safe direction.
		$GLOBALS['ran_booster_test_wp_salt'] = 'rotated-site-salt';
		try {
			self::assertArrayNotHasKey( 'provider_expires_at', $store->get( 'gh', SecretsFile::CONSTANT_PROFILE ) );
		} finally {
			unset( $GLOBALS['ran_booster_test_wp_salt'] );
		}
	}

	/** Reads the tag first, as the controller does before its provider call; the closure records the result. */
	private function validation( string $expires_on, string $checked_at = '2026-11-27T00:00:00Z' ): \Closure {
		$tag = $this->tag();

		return fn (): array => array(
			$this->observations->record_provider_expiry( 'gh', 'cred_1', CredentialExpiryReport::known( $expires_on . 'T00:00:00Z' ), $checked_at, $tag ),
			$this->secrets->record_credential_provider_expiry( 'gh', 'cred_1', $expires_on, $tag ),
		);
	}

	/**
	 * A request's store and tag while the GitHub constant holds a token of $character.
	 *
	 * @return array{InMemoryCredentialExpiryObservationStore, string}
	 */
	private function constant_request( string $character ): array {
		$secrets = SecretsFileTestFactory::create( $this->path, array( 'RAN_BOOSTER_GITHUB_TOKEN' => 'ghp_' . str_repeat( $character, 36 ) ), ShippedSecretPolicyCatalog::create() );
		$store   = new InMemoryCredentialExpiryObservationStore();
		$store->read_material_tags_with( $secrets->credential_material_tag( ... ) );

		return array( $store, $secrets->credential_material_tag( 'gh', SecretsFile::CONSTANT_PROFILE ) ?? '' );
	}

	/** Mirrors the controller's replacement: the new secret is saved, then the observation is reset. */
	private function replace(): void {
		$this->save( 'replacement-secret' );
		$this->observations->reset_for_replacement( 'gh', 'cred_1' );
	}

	private function save( ?string $secret, string $label = 'Fixture', string $destroy_on = '2030-01-01' ): void {
		$this->secrets->save_credential(
			'gh',
			'cred_1',
			array(
				'label'         => $label,
				'kind'          => 'classic',
				'configuration' => array(),
				'self_destruct' => true,
				'destroy_on'    => $destroy_on,
			),
			$secret
		);
	}

	private function tag(): string {
		return $this->secrets->credential_material_tag( 'gh', 'cred_1' ) ?? self::fail( 'The fixture credential has no material tag.' );
	}

	/** @return array<string, mixed> */
	private function profile(): array {
		return $this->secrets->credential_profiles( 'gh' )['cred_1'] ?? self::fail( 'The fixture credential is missing.' );
	}

	private function strip_tag(): void {
		$codec = new EncryptedSecretsEnvelopeCodec();
		$key   = SecretsFileTestFactory::key_store( $this->path )->load() ?? self::fail( 'The fixture key is missing.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the disposable local sidecar fixture.
		$plaintext = preg_replace( '/,"material_tag":"[0-9a-f]{32}"/', '', $codec->decrypt( (string) file_get_contents( $this->path ), $key ) );
		self::assertIsString( $plaintext );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Rewrites the disposable sidecar in place to simulate a record written before tags.
		self::assertNotFalse( file_put_contents( $this->path, $codec->encrypt( $plaintext, $key ) ) );
	}
}
