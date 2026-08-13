<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\Diagnostics;
use RAN\Booster\GitHub\RepositoryBrowser;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RuntimeException;
use Throwable;

final class DiagnosticsTest extends TestCase {

	private const SECRET_CANARY = 'github_pat_diagnostic_canary_secret';

	public function testMissingSelectionsReturnStableNotConfiguredResultsWithoutCallingGitHub(): void {
		$browser = $this->browser();
		$request = new ProviderDiagnosticRequest();

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame(
			array(
				array(
					'status'      => ProviderDiagnosticResult::NOT_CONFIGURED,
					'code'        => 'gh.credential.not_configured',
					'message'     => 'No GitHub credential was selected.',
					'remediation' => 'Select a credential to verify access to private repositories.',
				),
				array(
					'status'      => ProviderDiagnosticResult::NOT_CONFIGURED,
					'code'        => 'gh.repository.not_configured',
					'message'     => 'No GitHub repository was selected for the reachability check.',
					'remediation' => 'Select a repository to verify its visibility and scope.',
				),
			),
			array_map( static fn( ProviderDiagnosticResult $result ): array => $result->toArray(), $results )
		);
		self::assertSame( 0, $request->getRemoteCalls() );
		self::assertSame( array(), $browser->credentialCalls );
		self::assertSame( array(), $browser->repositoryCalls );
	}

	/**
	 * @return iterable<string, array{CredentialValidationResult, array{status: string, code: string, message: string, remediation: string}}>
	 */
	public static function credentialResults(): iterable {
		yield 'accepted' => array(
			CredentialValidationResult::valid(),
			array(
				'status'      => ProviderDiagnosticResult::PASSED,
				'code'        => 'gh.credential.valid',
				'message'     => 'GitHub accepted the selected credential.',
				'remediation' => 'No action is needed.',
			),
		);
		yield 'rate limited' => array(
			CredentialValidationResult::rateLimited(),
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.credential.rate_limited',
				'message'     => 'GitHub rate-limited credential validation.',
				'remediation' => 'Try the check again after the rate limit resets.',
			),
		);
		yield 'temporarily unavailable' => array(
			CredentialValidationResult::unavailable(),
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.credential.unavailable',
				'message'     => 'GitHub credential validation could not be completed.',
				'remediation' => 'Try again and check GitHub service status if the problem continues.',
			),
		);
		yield 'invalid response' => array(
			CredentialValidationResult::invalidResponse(),
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.credential.unavailable',
				'message'     => 'GitHub credential validation could not be completed.',
				'remediation' => 'Try again and check GitHub service status if the problem continues.',
			),
		);
		yield 'rejected' => array(
			CredentialValidationResult::invalid(),
			array(
				'status'      => ProviderDiagnosticResult::FAILED,
				'code'        => 'gh.credential.invalid',
				'message'     => 'GitHub did not accept the selected credential.',
				'remediation' => 'Check the token, repository access, organisation approval, and expiry.',
			),
		);
	}

	#[DataProvider( 'credentialResults' )]
	public function testCredentialResultsKeepStableStatusCodeAndOperatorCopy(
		CredentialValidationResult $credentialResult,
		array $expected
	): void {
		$browser                   = $this->browser();
		$browser->credentialResult = $credentialResult;
		$request                   = new ProviderDiagnosticRequest(
			'diagnostic-profile',
			clock: static fn(): float => 100.0
		);

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame( $expected, $results[0]->toArray() );
		self::assertSame( array( array( 'diagnostic-profile', 10.0 ) ), $browser->credentialCalls );
		self::assertSame( 1, $request->getRemoteCalls() );
	}

	/**
	 * @return iterable<string, array{int|null, array{status: string, code: string, message: string, remediation: string}}>
	 */
	public static function repositoryResults(): iterable {
		yield 'reachable' => array(
			null,
			array(
				'status'      => ProviderDiagnosticResult::PASSED,
				'code'        => 'gh.repository.reachable',
				'message'     => 'GitHub returned the selected repository.',
				'remediation' => 'No action is needed.',
			),
		);
		foreach ( array( 401, 403 ) as $code ) {
			yield 'access denied ' . $code => array(
				$code,
				array(
					'status'      => ProviderDiagnosticResult::FAILED,
					'code'        => 'gh.repository.denied',
					'message'     => 'GitHub denied access to the selected repository.',
					'remediation' => 'Check the token permissions and organisation approval.',
				),
			);
		}
		yield 'not found' => array(
			404,
			array(
				'status'      => ProviderDiagnosticResult::FAILED,
				'code'        => 'gh.repository.not_found',
				'message'     => 'GitHub could not find the selected repository with this credential.',
				'remediation' => 'Check the repository name and credential repository access.',
			),
		);
		yield 'rate limited' => array(
			429,
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.repository.rate_limited',
				'message'     => 'GitHub rate-limited the repository check.',
				'remediation' => 'Try the check again after the rate limit resets.',
			),
		);
		foreach ( array( 400, 500, 502 ) as $code ) {
			yield 'unavailable ' . $code => array(
				$code,
				array(
					'status'      => ProviderDiagnosticResult::WARNING,
					'code'        => 'gh.repository.unavailable',
					'message'     => 'GitHub repository access could not be completed.',
					'remediation' => 'Try again and check GitHub service status if the problem continues.',
				),
			);
		}
	}

	#[DataProvider( 'repositoryResults' )]
	public function testRepositoryRuntimeResultsKeepStableMappingWithoutLoggingRawFailure(
		?int $exceptionCode,
		array $expected
	): void {
		$browser = $this->browser();
		if ( null !== $exceptionCode ) {
			$browser->repositoryException = new RuntimeException( self::SECRET_CANARY, $exceptionCode );
		}
		$request = new ProviderDiagnosticRequest(
			null,
			'RocketsAreNostalgic/ran-booster',
			clock: static fn(): float => 100.0
		);

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame( $expected, $results[1]->toArray() );
		self::assertSame(
			array( array( 'RocketsAreNostalgic/ran-booster', null, 10.0, 65536 ) ),
			$browser->repositoryCalls
		);
		self::assertStringNotContainsString( self::SECRET_CANARY, implode( ' ', $results[1]->toArray() ) );
	}

	public function testRemoteCallBudgetIsConsumedInCredentialThenRepositoryOrder(): void {
		$browser = $this->browser();
		$request = new ProviderDiagnosticRequest( 'diagnostic-profile', 'owner/repository', 1 );

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame( 'gh.credential.valid', $results[0]->code );
		self::assertSame(
			array(
				'status'      => ProviderDiagnosticResult::WARNING,
				'code'        => 'gh.repository.budget_exhausted',
				'message'     => 'This GitHub check was not run because the diagnostic budget was exhausted.',
				'remediation' => 'Run diagnostics again after other provider requests have completed.',
			),
			$results[1]->toArray()
		);
		self::assertSame( 1, $request->getRemoteCalls() );
		self::assertSame( 'remote_calls', $request->getExhaustionReason() );
		self::assertCount( 1, $browser->credentialCalls );
		self::assertSame( array(), $browser->repositoryCalls );
	}

	public function testExpiredDeadlineSkipsBothChecksWithoutCallingGitHub(): void {
		$now     = 100.0;
		$request = new ProviderDiagnosticRequest(
			'diagnostic-profile',
			'owner/repository',
			clock: static function () use ( &$now ): float {
				return $now;
			}
		);
		$now     = 111.0;
		$browser = $this->browser();

		$results = ( new Diagnostics( $browser ) )->diagnose( $request );

		self::assertSame( 'gh.credential.budget_exhausted', $results[0]->code );
		self::assertSame( 'gh.repository.budget_exhausted', $results[1]->code );
		self::assertSame( ProviderDiagnosticResult::WARNING, $results[0]->status );
		self::assertSame( ProviderDiagnosticResult::WARNING, $results[1]->status );
		self::assertSame( 0, $request->getRemoteCalls() );
		self::assertSame( 'deadline', $request->getExhaustionReason() );
		self::assertSame( array(), $browser->credentialCalls );
		self::assertSame( array(), $browser->repositoryCalls );
	}

	private function browser(): RepositoryBrowser {
		return new class() extends RepositoryBrowser {

			public CredentialValidationResult $credentialResult;
			public ?Throwable $credentialException = null;
			public ?Throwable $repositoryException = null;

			/** @var list<array{string, float}> */
			public array $credentialCalls = array();

			/** @var list<array{string, string|null, float|int, int|null}> */
			public array $repositoryCalls = array();

			public function __construct() {
				$this->credentialResult = CredentialValidationResult::valid();
			}

			public function validateCredential( string $credentialId, float $timeout = 15.0 ): CredentialValidationResult {
				$this->credentialCalls[] = array( $credentialId, $timeout );
				if ( null !== $this->credentialException ) {
					throw $this->credentialException;
				}

				return $this->credentialResult;
			}

			public function repository(
				string $fullName,
				?string $credentialId = null,
				float|int $timeout = 15,
				?int $responseSize = null,
				bool $authenticateDefault = false
			): RepositoryDescriptor {
				$this->repositoryCalls[] = array( $fullName, $credentialId, $timeout, $responseSize );
				if ( null !== $this->repositoryException ) {
					throw $this->repositoryException;
				}

				return new RepositoryDescriptor(
					ProviderCode::parse( 'gh' ),
					$fullName,
					'ran-booster',
					'987654321',
					false,
					'main',
					$credentialId
				);
			}
		};
	}
}
