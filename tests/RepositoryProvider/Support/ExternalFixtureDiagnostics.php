<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider\Support;

use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderDiagnostics;

final readonly class ExternalFixtureDiagnostics implements ProviderDiagnostics {

	public function __construct( private ExternalFixtureClient $client ) {
	}

	public function diagnose( ProviderDiagnosticRequest $request ): array {
		$request->claimRemoteCall();
		$this->client->checkPublicAccess();

		$results = array(
			new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'fixture.environment.ready',
				'The fixture provider prerequisite is ready.',
				'No action is needed.'
			),
			new ProviderDiagnosticResult(
				ProviderDiagnosticResult::PASSED,
				'fixture.credential.public',
				'This fixture provider supports public access without credentials.',
				'No action is needed.'
			),
		);

		if ( null === $request->getRepository() ) {
			$results[] = new ProviderDiagnosticResult(
				ProviderDiagnosticResult::NOT_CONFIGURED,
				'fixture.repository.not_configured',
				'No fixture repository was selected.',
				'Select a repository to verify reachability.'
			);

			return $results;
		}

		$request->claimRemoteCall();
		$this->client->repository( $request->getRepository() );
		$results[] = new ProviderDiagnosticResult(
			ProviderDiagnosticResult::PASSED,
			'fixture.repository.reachable',
			'The fixture provider returned the selected repository.',
			'No action is needed.'
		);

		return $results;
	}
}
