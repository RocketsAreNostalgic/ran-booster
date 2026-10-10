<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\CredentialSelfDestructPurger;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\Secrets\SecretsFile;

final class CredentialSelfDestructPurgerTest extends TestCase {

	public function test_purger_uses_the_evidence_lock_without_an_outer_updater_lock(): void {
		$original = $GLOBALS['wpdb'] ?? null;
		try {
			$database = new class() {
				public string $options = 'wp_options';
				/** @var list<string> */
				public array $queries = array();
				public function prepare( string $query, string $name ): string {
					return $query . $name;
				}
				public function get_var( string $query ): string {
					$this->queries[] = $query;
					return '1';
				}
			};
			foreach ( array( $database, null ) as $connection ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Install or restore the isolated test-owned database connection for the real advisory lock.
				$GLOBALS['wpdb']    = $connection;
				$profiles           = new PurgerLookupProfiles();
				$profiles->profiles = array( 'gh' => 'expired-profile' );
				$evidence           = new class() extends RepositoryBranchCheckEvidenceStore {
					/** @var array<string, mixed> */
					public array $records = array();
					protected function read_option(): array {
						return $this->records;
					}
					protected function write_option( array $records ): bool {
						$this->records = $records;
						return true;
					}
				};
				$purger             = new CredentialSelfDestructPurger( new PurgerSecretsFile( array( 'gh' => array( 'expired-profile' ) ) ), new PurgerObservations(), $profiles, $evidence );
				$purger->purge();
				self::assertNull( $profiles->get( 'gh' ) );
				self::assertSame( null === $connection ? null : 2, $evidence->records['generation'] ?? null );
			}
			self::assertCount( 4, $database->queries );
			self::assertStringStartsWith( 'SELECT GET_LOCK', $database->queries[0] );
			self::assertStringStartsWith( 'SELECT RELEASE_LOCK', $database->queries[3] );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Install or restore the isolated test-owned database connection for the real advisory lock.
			$GLOBALS['wpdb'] = $original;
		}
	}

	public function test_purging_an_expired_credential_invalidates_its_branch_check_evidence(): void {
		$profiles           = new PurgerLookupProfiles();
		$profiles->profiles = array( 'gh' => 'expired-profile' );
		$evidence           = new PurgerEvidenceStore();
		$purger             = new CredentialSelfDestructPurger(
			new PurgerSecretsFile( array( 'gh' => array( 'expired-profile' ) ) ),
			new PurgerObservations(),
			$profiles,
			$evidence
		);

		$purger->purge();

		self::assertSame( array( 'gh:expired-profile' ), $evidence->profiles );
		self::assertSame( array( 'gh' ), $evidence->providers );
		self::assertNull( $profiles->get( 'gh' ) );
	}

	public function test_purging_an_expired_default_credential_still_clears_its_default_when_evidence_invalidation_fails(): void {
		$profiles           = new PurgerLookupProfiles();
		$profiles->profiles = array( 'gh' => 'expired-profile' );
		$purger             = new CredentialSelfDestructPurger(
			new PurgerSecretsFile( array( 'gh' => array( 'expired-profile' ) ) ),
			new PurgerObservations(),
			$profiles,
			new ThrowingPurgerEvidenceStore()
		);

		$purger->purge();

		self::assertNull( $profiles->get( 'gh' ) );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused purger collaborators stay beside the lifecycle contract test.
final class PurgerSecretsFile extends SecretsFile {

	/** @param array<string, list<string>> $removed */
	public function __construct( private readonly array $removed ) {
		parent::__construct( null, array() );
	}

	/** @return array<string, list<string>> */
	public function purge_expired_credentials(): array {
		return $this->removed;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused purger collaborators stay beside the lifecycle contract test.
final class PurgerObservations extends CredentialExpiryObservationStore {

	public function clear( string $provider, string $profile_id ): void {
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused purger collaborators stay beside the lifecycle contract test.
final class PurgerLookupProfiles extends PublicRepositoryLookupProfileStore {

	/** @var array<string, string> */
	public array $profiles = array();

	public function get( string $provider ): ?string {
		return $this->profiles[ $provider ] ?? null;
	}

	public function set( string $provider, ?string $profile_id ): void {
		if ( null === $profile_id ) {
			unset( $this->profiles[ $provider ] );
			return;
		}
		$this->profiles[ $provider ] = $profile_id;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused purger collaborators stay beside the lifecycle contract test.
final class PurgerEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	/** @var list<string> */
	public array $profiles = array();
	/** @var list<string> */
	public array $providers = array();

	public function bump_profile_generation( string $provider, string $profile_id ): void {
		$this->profiles[] = $provider . ':' . $profile_id;
	}

	public function bump_provider_generation( string $provider ): void {
		$this->providers[] = $provider;
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused purger collaborators stay beside the lifecycle contract test.
final class ThrowingPurgerEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of bump_profile_generation retains the production method contract; these inputs do not affect this controlled result.
	public function bump_profile_generation( string $provider, string $profile_id ): void {
		throw new \RuntimeException( 'evidence unavailable' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of bump_provider_generation retains the production method contract; these inputs do not affect this controlled result.
	public function bump_provider_generation( string $provider ): void {
		throw new \RuntimeException( 'evidence unavailable' );
	}
}
