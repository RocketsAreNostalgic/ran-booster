<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentState;
use RAN\RepositoryProvider\StaleDeployment;

final class DeploymentOutcomeTest extends TestCase {

	public function test_fixed_outcome_maps_to_safe_state(): void {
		$outcome = DeploymentOutcome::from_code( DeploymentOutcome::CODE_DEPLOYED );

		self::assertSame( DeploymentState::SUCCEEDED, $outcome->get_state() );
		self::assertSame( 'deployed', $outcome->get_code() );
	}

	/** @return iterable<string, array{string}> */
	public static function archive_failure_codes(): iterable {
		yield 'compressed archive' => array( DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE );
		yield 'expanded archive' => array( DeploymentOutcome::CODE_ARCHIVE_EXPANDED_TOO_LARGE );
		yield 'invalid configuration' => array( DeploymentOutcome::CODE_ARCHIVE_LIMIT_INVALID );
		yield 'downgrade blocked' => array( DeploymentOutcome::CODE_DOWNGRADE_BLOCKED );
	}

	#[DataProvider( 'archive_failure_codes' )]
	public function test_archive_limit_failures_are_closed_failed_outcomes( string $code ): void {
		$outcome = DeploymentOutcome::from_code( $code );

		self::assertSame( $code, $outcome->get_code() );
		self::assertSame( DeploymentState::FAILED, $outcome->get_state() );
	}

	public function test_arbitrary_outcome_cannot_be_created(): void {
		$this->expectException( InvalidArgumentException::class );
		DeploymentOutcome::from_code( 'provider said Authorization: bearer secret' );
	}

	public function test_check_failure_cannot_represent_success(): void {
		$this->expectException( InvalidArgumentException::class );
		new \RAN\Deployment\DeploymentCheckFailure( outcome_code: DeploymentOutcome::CODE_DEPLOYED, message: 'not a failure' );
	}

	/** @return list<array{int, string}> */
	public static function provider_failure_provider(): array {
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

	#[DataProvider( 'provider_failure_provider' )]
	public function test_provider_failure_codes_map_to_closed_safe_outcomes( int $status, string $expected ): void {
		$outcome = DeploymentOutcome::from_provider_failure(
			new \RuntimeException( 'Authorization: Bearer secret-canary', $status )
		);

		self::assertSame( $expected, $outcome->get_code() );
		self::assertSame( DeploymentState::FAILED, $outcome->get_state() );

		$failure = \RAN\Deployment\DeploymentCheckFailure::provider_status( status: $status, message: 'Provider check failed.' );
		self::assertSame( $expected, $failure->outcome_code );
		self::assertSame( 'Provider check failed.', $failure->getMessage() );
		self::assertSame( 0, $failure->getCode() );
	}

	public function test_stale_provider_failure_maps_to_stale_event(): void {
		$outcome = DeploymentOutcome::from_provider_failure( new StaleDeployment( 'The admitted ref is stale.' ) );

		self::assertSame( DeploymentOutcome::CODE_STALE_EVENT, $outcome->get_code() );
		self::assertSame( DeploymentState::FAILED, $outcome->get_state() );
	}
}
