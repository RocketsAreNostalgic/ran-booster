<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Secrets\SecretsFile;

/**
 * Performs best-effort physical removal of credentials whose encrypted local
 * retention deadline has elapsed. SecretsFile independently withholds expired
 * material, so a failed cleanup can never keep a credential usable.
 */
final class CredentialSelfDestructPurger {

	public function __construct(
		private SecretsFile $secrets,
		private CredentialExpiryObservationStore $observations,
		private PublicRepositoryLookupProfileStore $publicLookupProfiles,
		private RepositoryBranchCheckEvidenceStore $branchCheckEvidence
	) {
	}

	public function purge(): void {
		try {
			$removed = $this->secrets->purgeExpiredCredentials();
			foreach ( $removed as $provider => $ids ) {
				foreach ( $ids as $id ) {
					$this->observations->clear( $provider, $id );
					$this->branchCheckEvidence->bumpProfileGeneration( $provider, $id );
					if ( $id === $this->publicLookupProfiles->get( $provider ) ) {
						$this->publicLookupProfiles->set( $provider, null );
						$this->branchCheckEvidence->bumpProviderGeneration( $provider );
					}
				}
			}
		} catch ( \Throwable $failure ) {
			unset( $failure );
			// Read boundaries remain fail-closed; expiry cleanup must not interrupt
			// ordinary WordPress bootstrap when encrypted storage is unavailable.
		}
	}
}
