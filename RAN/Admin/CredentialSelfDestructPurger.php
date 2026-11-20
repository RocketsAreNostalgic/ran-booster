<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Logging\BoosterLogger;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SecretsStorageUnavailable;

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
					// One line per removed credential: its provider only, never its identifier.
					$this->log( 'Expired credential purged.', $provider );
					$this->observations->clear( $provider, $id );
					try {
						$this->branch_check_evidence->bump_profile_generation( $provider, $id );
					} catch ( \Throwable $failure ) {
						// Evidence is advisory. Continue clearing an expired default profile.
						$this->log( 'Expired credential evidence could not be invalidated.', $provider, $failure );
					}
					if ( $id === $this->public_lookup_profiles->get( $provider ) ) {
						$this->public_lookup_profiles->set( $provider, null );
						try {
							$this->branch_check_evidence->bump_provider_generation( $provider );
						} catch ( \Throwable $failure ) {
							// Expiry cleanup remains useful even if advisory evidence is unavailable.
							$this->log( 'Expired credential evidence could not be invalidated.', $provider, $failure );
						}
					}
				}
			}
		} catch ( SecretsStorageUnavailable ) {
			// Read boundaries remain fail-closed. A site without usable encrypted storage reaches this on every
			// admin request and Installation diagnostics already report it, so it is not logged here.
			return;
		} catch ( \Throwable $failure ) {
			// Expiry cleanup must not interrupt ordinary WordPress bootstrap.
			$this->log( 'Expired credential purge failed.', null, $failure );
		}
	}

	/** Codes only: the operation, the provider and, for a failure, its class and code. */
	private function log( string $message, ?string $provider, ?\Throwable $failure = null ): void {
		$context = array_filter(
			array(
				'operation' => 'credential_purge',
				'provider'  => $provider,
			)
		);
		if ( null === $failure ) {
			BoosterLogger::log( $message, $context );
			return;
		}
		BoosterLogger::log_exception( $message, $failure, $context );
	}
}
