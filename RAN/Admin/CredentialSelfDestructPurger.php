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
		private PublicRepositoryLookupProfileStore $public_lookup_profiles,
		private RepositoryBranchCheckEvidenceStore $branch_check_evidence
	) {
	}

	public function purge(): void {
		try {
			$removed = $this->secrets->purge_expired_credentials();
			foreach ( $removed as $provider => $ids ) {
				foreach ( $ids as $id ) {
					$this->observations->clear( $provider, $id );
					try {
						$this->branch_check_evidence->bump_profile_generation( $provider, $id );
					} catch ( \Throwable $failure ) {
						unset( $failure );
						// Evidence is advisory. Continue clearing an expired default profile.
					}
					if ( $id === $this->public_lookup_profiles->get( $provider ) ) {
						$this->public_lookup_profiles->set( $provider, null );
						try {
							$this->branch_check_evidence->bump_provider_generation( $provider );
						} catch ( \Throwable $failure ) {
							unset( $failure );
							// Expiry cleanup remains useful even if advisory evidence is unavailable.
						}
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
