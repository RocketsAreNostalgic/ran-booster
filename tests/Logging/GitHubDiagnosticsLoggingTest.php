<?php

declare(strict_types=1);

namespace Tests\Logging;

// Direct local filesystem operations inspect the bounded temporary capture under test.
// phpcs:disable WordPress.WP.AlternativeFunctions

require_once __DIR__ . '/LoggingWordPressFunctions.php';

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\GitHub\RepositoryBrowser;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\GitHubDiagnostics;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\RepositoryDescriptor;
use Throwable;

final class GitHubDiagnosticsLoggingTest extends TestCase {

	private const SECRET_CANARY = 'github_pat_diagnostic_canary_secret';

	private string $captureDirectory;
	private TemporaryDebugCapture $capture;

	protected function setUp(): void {
		$this->captureDirectory = sys_get_temp_dir() . '/ran-booster-github-diagnostics-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->captureDirectory, 0700 ) );
		$this->capture = new TemporaryDebugCapture(
			$this->captureDirectory . '/secrets.php',
			static fn(): int => strtotime( '2026-08-13T12:00:00Z' )
		);
		$this->capture->start();
		BoosterLogger::configureCapture( $this->capture );
	}

	protected function tearDown(): void {
		BoosterLogger::configureCapture( null );
		foreach ( array( 'ran-booster-debug.php', 'ran-booster-debug.php.lock' ) as $name ) {
			$path = $this->captureDirectory . '/' . $name;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		if ( is_dir( $this->captureDirectory ) ) {
			rmdir( $this->captureDirectory );
		}
	}

	/** @return iterable<string, array{bool,string,string,int,string}> */
	public static function unexpectedFailures(): iterable {
		yield 'credential' => array( true, 'credential-secret-canary', 'gh_credential_diagnostics', 73, 'GitHub diagnostics credential check failed' );
		yield 'repository' => array( false, 'owner/repository-secret-canary', 'gh_repository_diagnostics', 74, 'GitHub diagnostics repository check failed' );
	}

	#[DataProvider( 'unexpectedFailures' )]
	public function testUnexpectedFailureIsLoggedWithoutProviderInputOrExceptionMessage(
		bool $credential,
		string $providerInput,
		string $step,
		int $code,
		string $event
	): void {
		$browser = new class() extends RepositoryBrowser {
			public ?Throwable $credentialException = null;
			public ?Throwable $repositoryException = null;

			public function __construct() {
			}

			public function validateCredential( string $credentialId, float $timeout = 15.0 ): CredentialValidationResult {
				unset( $credentialId, $timeout );
				throw $this->credentialException ?? new LogicException( 'Unexpected credential fixture state.' );
			}

			public function repository(
				string $fullName,
				?string $credentialId = null,
				float|int $timeout = 15,
				?int $responseSize = null,
				bool $authenticateDefault = false
			): RepositoryDescriptor {
				unset( $fullName, $credentialId, $timeout, $responseSize, $authenticateDefault );
				throw $this->repositoryException ?? new LogicException( 'Unexpected repository fixture state.' );
			}
		};
		$failure = new LogicException( self::SECRET_CANARY, $code );
		if ( $credential ) {
			$browser->credentialException = $failure;
			( new GitHubDiagnostics( $browser ) )->diagnose( new ProviderDiagnosticRequest( $providerInput ) );
		} else {
			$browser->repositoryException = $failure;
			( new GitHubDiagnostics( $browser ) )->diagnose( new ProviderDiagnosticRequest( null, $providerInput ) );
		}

		$line = $this->capture->snapshot()['entries'][0]['line'];
		self::assertSame(
			'[ran-booster] ' . $event . ' {"step":"' . $step . '","exception_class":"LogicException","exception_code":"' . $code . '"}',
			$line
		);
		self::assertStringNotContainsString( self::SECRET_CANARY, $line );
		self::assertStringNotContainsString( $providerInput, $line );
	}
}
