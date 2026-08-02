<?php

declare(strict_types=1);

namespace Tests\Deployment;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentState;

final class DeploymentOutcomeTest extends TestCase {

	public function testFixedOutcomeMapsToSafeState(): void {
		$outcome = DeploymentOutcome::fromCode( DeploymentOutcome::CODE_DEPLOYED );

		self::assertSame( DeploymentState::SUCCEEDED, $outcome->getState() );
		self::assertSame( 'deployed', $outcome->getCode() );
	}

	/** @return iterable<string, array{string}> */
	public static function archiveFailureCodes(): iterable {
		yield 'compressed archive' => array( DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE );
		yield 'expanded archive' => array( DeploymentOutcome::CODE_ARCHIVE_EXPANDED_TOO_LARGE );
		yield 'invalid configuration' => array( DeploymentOutcome::CODE_ARCHIVE_LIMIT_INVALID );
		yield 'downgrade blocked' => array( DeploymentOutcome::CODE_DOWNGRADE_BLOCKED );
	}

	#[DataProvider( 'archiveFailureCodes' )]
	public function testArchiveLimitFailuresAreClosedFailedOutcomes( string $code ): void {
		$outcome = DeploymentOutcome::fromCode( $code );

		self::assertSame( $code, $outcome->getCode() );
		self::assertSame( DeploymentState::FAILED, $outcome->getState() );
	}

	public function testArbitraryOutcomeCannotBeCreated(): void {
		$this->expectException( InvalidArgumentException::class );
		DeploymentOutcome::fromCode( 'provider said Authorization: bearer secret' );
	}

	/** @return list<array{int, string}> */
	public static function providerFailureProvider(): array {
		return array(
			array( 400, DeploymentOutcome::CODE_PROVIDER_REQUEST_INVALID ),
			array( 401, DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED ),
			array( 403, DeploymentOutcome::CODE_PROVIDER_ACCESS_DENIED ),
			array( 404, DeploymentOutcome::CODE_PROVIDER_REPOSITORY_MISSING ),
			array( 410, DeploymentOutcome::CODE_PROVIDER_REFERENCE_UNAVAILABLE ),
			array( 429, DeploymentOutcome::CODE_PROVIDER_RATE_LIMITED ),
			array( 502, DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE ),
			array( 504, DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE ),
			array( 0, DeploymentOutcome::CODE_PROVIDER_FAILED ),
		);
	}

	#[DataProvider( 'providerFailureProvider' )]
	public function testProviderFailureCodesMapToClosedSafeOutcomes( int $status, string $expected ): void {
		$outcome = DeploymentOutcome::fromProviderFailure(
			new \RuntimeException( 'Authorization: Bearer secret-canary', $status )
		);

		self::assertSame( $expected, $outcome->getCode() );
		self::assertSame( DeploymentState::FAILED, $outcome->getState() );
	}
}
