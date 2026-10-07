<?php

declare(strict_types=1);

namespace RAN_Booster_FixtureProvider;

use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;

final readonly class Diagnostics implements ProviderDiagnostics {

	public function __construct( private Client $client, private ProviderCredentialStore $credentials ) {
	}

	public function diagnose( ProviderDiagnosticRequest $request ): array {
		$this->client->check_public_access( $request->claim_remote_call() );
		$results = array(
			new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'fixture-provider.environment.ready',
				'The fixture provider prerequisite is ready.',
				'No action is needed.'
			),
		);

		$credential_id = $request->get_credential_id();
		if ( null === $credential_id ) {
			$results[] = new ProviderDiagnosticResult(
				ProviderDiagnosticResult::NOT_CONFIGURED,
				'fixture-provider.credential.not_configured',
				'No fixture credential was selected.',
				'Select a fixture credential to verify it.'
			);
		} else {
			$valid     = $this->client->validate_credential(
				$this->credentials->credential_material( $credential_id ),
				$request->claim_remote_call()
			);
			$results[] = new ProviderDiagnosticResult(
				$valid ? ProviderDiagnosticResult::PASSED : ProviderDiagnosticResult::FAILED,
				$valid ? 'fixture-provider.credential.valid' : 'fixture-provider.credential.invalid',
				$valid ? 'The fixture credential is valid.' : 'The fixture credential is unavailable.',
				$valid ? 'No action is needed.' : 'Save or select a valid fixture credential.'
			);
		}

		$locator = $request->get_repository();
		if ( null === $locator ) {
			$results[] = new ProviderDiagnosticResult(
				ProviderDiagnosticResult::NOT_CONFIGURED,
				'fixture-provider.repository.not_configured',
				'No fixture repository was selected.',
				'Enter a fixture repository locator to verify it.'
			);
		} else {
			$this->client->repository( $locator, $request->claim_remote_call() );
			$results[] = new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'fixture-provider.repository.reachable',
				'The fixture provider returned the selected repository.',
				'No action is needed.'
			);
		}

		return $results;
	}
}
