<?php

declare(strict_types=1);

namespace RANBoosterFixtureProvider;

use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;

final readonly class Diagnostics implements ProviderDiagnostics {

	public function __construct( private Client $client, private ProviderCredentialStore $credentials ) {
	}

	public function diagnose( ProviderDiagnosticRequest $request ): array {
		$this->client->checkPublicAccess( $request->claimRemoteCall() );
		$results = array(
			new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'fixture-provider.environment.ready',
				'The fixture provider prerequisite is ready.',
				'No action is needed.'
			),
		);

		$credentialId = $request->getCredentialId();
		if ( null === $credentialId ) {
			$results[] = new ProviderDiagnosticResult(
				ProviderDiagnosticResult::NOT_CONFIGURED,
				'fixture-provider.credential.not_configured',
				'No fixture credential was selected.',
				'Select a fixture credential to verify it.'
			);
		} else {
			$valid     = $this->client->validateCredential(
				$this->credentials->credentialMaterial( $credentialId ),
				$request->claimRemoteCall()
			);
			$results[] = new ProviderDiagnosticResult(
				$valid ? ProviderDiagnosticResult::PASSED : ProviderDiagnosticResult::FAILED,
				$valid ? 'fixture-provider.credential.valid' : 'fixture-provider.credential.invalid',
				$valid ? 'The fixture credential is valid.' : 'The fixture credential is unavailable.',
				$valid ? 'No action is needed.' : 'Save or select a valid fixture credential.'
			);
		}

		$locator = $request->getRepository();
		if ( null === $locator ) {
			$results[] = new ProviderDiagnosticResult(
				ProviderDiagnosticResult::NOT_CONFIGURED,
				'fixture-provider.repository.not_configured',
				'No fixture repository was selected.',
				'Enter a fixture repository locator to verify it.'
			);
		} else {
			$this->client->repository( $locator, $request->claimRemoteCall() );
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
