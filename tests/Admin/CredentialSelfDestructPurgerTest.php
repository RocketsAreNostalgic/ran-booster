<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused purger collaborators stay beside the lifecycle contract test.

use PHPUnit\Framework\TestCase;
use RAN\Admin\CredentialExpiryObservationStore;
use RAN\Admin\CredentialSelfDestructPurger;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\Secrets\SecretsFile;

final class CredentialSelfDestructPurgerTest extends TestCase {

	public function testPurgingAnExpiredCredentialInvalidatesItsBranchCheckEvidence(): void {
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

	public function testPurgingAnExpiredDefaultCredentialStillClearsItsDefaultWhenEvidenceInvalidationFails(): void {
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

final class PurgerSecretsFile extends SecretsFile {

	/** @param array<string, list<string>> $removed */
	public function __construct( private readonly array $removed ) {
		parent::__construct( null, array() );
	}

	/** @return array<string, list<string>> */
	public function purgeExpiredCredentials(): array {
		return $this->removed;
	}
}

final class PurgerObservations extends CredentialExpiryObservationStore {

	public function clear( string $provider, string $profileId ): void {
	}
}

final class PurgerLookupProfiles extends PublicRepositoryLookupProfileStore {

	/** @var array<string, string> */
	public array $profiles = array();

	public function get( string $provider ): ?string {
		return $this->profiles[ $provider ] ?? null;
	}

	public function set( string $provider, ?string $profileId ): void {
		if ( null === $profileId ) {
			unset( $this->profiles[ $provider ] );
			return;
		}
		$this->profiles[ $provider ] = $profileId;
	}
}

final class PurgerEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	/** @var list<string> */
	public array $profiles = array();
	/** @var list<string> */
	public array $providers = array();

	public function bumpProfileGeneration( string $provider, string $profileId ): void {
		$this->profiles[] = $provider . ':' . $profileId;
	}

	public function bumpProviderGeneration( string $provider ): void {
		$this->providers[] = $provider;
	}
}

final class ThrowingPurgerEvidenceStore extends RepositoryBranchCheckEvidenceStore {

	public function bumpProfileGeneration( string $provider, string $profileId ): void {
		throw new \RuntimeException( 'evidence unavailable' );
	}

	public function bumpProviderGeneration( string $provider ): void {
		throw new \RuntimeException( 'evidence unavailable' );
	}
}
