<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

require_once __DIR__ . '/../Support/ProviderFailureWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentOutcome;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderFailure;
use RAN\RepositoryProvider\ProviderRequestFailed;
use RAN\RepositoryProvider\RepositoryReleaseAcquisitionRejected;
use RAN\RepositoryProvider\RepositoryReleaseInspectionRejected;
use RAN\RepositoryProvider\RepositoryReleaseReadUnavailable;
use RuntimeException;

#[CoversClass( ProviderFailure::class )]
#[CoversClass( ProviderRequestFailed::class )]
#[CoversClass( RepositoryReleaseReadUnavailable::class )]
#[CoversClass( RepositoryReleaseInspectionRejected::class )]
#[CoversClass( RepositoryReleaseAcquisitionRejected::class )]
#[CoversClass( CredentialValidationResult::class )]
final class ProviderFailureTest extends TestCase {

	/** @return array<string, array{string}> */
	public static function reason_provider(): array {
		$cases = array();
		foreach ( ProviderFailure::REASONS as $reason ) {
			$cases[ $reason ] = array( $reason );
		}

		return $cases;
	}

	public function test_the_vocabulary_is_the_contract_set(): void {
		self::assertCount( 33, ProviderFailure::REASONS );
		self::assertCount( 33, array_unique( ProviderFailure::REASONS ) );
		self::assertSame( array(), array_intersect( ProviderFailure::REQUEST_REASONS, ProviderFailure::RELEASE_REASONS ) );
	}

	#[DataProvider( 'reason_provider' )]
	public function test_every_reason_has_core_copy_a_legacy_code_and_a_closed_deployment_outcome( string $reason ): void {
		$failure = new ProviderFailure( reason: $reason );

		self::assertNotSame( '', $failure->message() );
		self::assertMatchesRegularExpression( '/\A[A-Z][^<>{}\/\\\\]*\.\z/', $failure->message() );
		self::assertGreaterThanOrEqual( 100, $failure->legacy_code() );
		self::assertLessThanOrEqual( 599, $failure->legacy_code() );
		self::assertSame( $failure->deployment_outcome_code(), DeploymentOutcome::from_code( $failure->deployment_outcome_code() )->get_code() );
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalid_value_provider(): array {
		return array(
			'unknown reason'   => array( array( 'reason' => 'made_up' ) ),
			'empty reason'     => array( array( 'reason' => '' ) ),
			'status low'       => array(
				array(
					'reason'      => 'http_5xx',
					'http_status' => 99,
				),
			),
			'status high'      => array(
				array(
					'reason'      => 'http_5xx',
					'http_status' => 600,
				),
			),
			'retry zero'       => array(
				array(
					'reason'      => 'rate_limited',
					'retry_after' => 0,
				),
			),
			'retry too long'   => array(
				array(
					'reason'      => 'rate_limited',
					'retry_after' => 86401,
				),
			),
			'reset not a time' => array(
				array(
					'reason'   => 'rate_limited',
					'reset_at' => 0,
				),
			),
			'expiry text'      => array(
				array(
					'reason'                => 'credential_expired',
					'credential_expires_at' => 'tomorrow',
				),
			),
		);
	}

	/** @param array<string, mixed> $arguments */
	#[DataProvider( 'invalid_value_provider' )]
	public function test_the_constructor_rejects_unknown_reasons_and_out_of_range_facts( array $arguments ): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderFailure( ...$arguments );
	}

	public function test_the_constructor_accepts_the_range_edges(): void {
		$failure = new ProviderFailure( 'rate_limited', 100, 86400, 1, '2026-01-31T00:00:00Z' );

		self::assertSame( 100, $failure->http_status );
		self::assertSame( 86400, $failure->retry_after );
		self::assertNotNull( ( new ProviderFailure( 'http_5xx', 599, 1 ) )->retry_after );
	}

	public function test_a_reason_outside_the_accepted_group_is_dropped(): void {
		$rate = new ProviderFailure( 'rate_limited' );

		self::assertSame( $rate, ProviderFailure::within( $rate, ProviderFailure::REQUEST_REASONS ) );
		self::assertNull( ProviderFailure::within( $rate, ProviderFailure::RELEASE_REASONS ) );
		self::assertNull( ProviderFailure::within( null, ProviderFailure::REQUEST_REASONS ) );
		self::assertNull( ( new RepositoryReleaseReadUnavailable( failure: new ProviderFailure( 'release_changed' ) ) )->failure );
		self::assertNull( RepositoryReleaseInspectionRejected::no_releases( $rate )->failure );
		self::assertNull( RepositoryReleaseAcquisitionRejected::changed( $rate )->failure );
		self::assertNull( CredentialValidationResult::invalid( new ProviderFailure( 'site_zip_unavailable' ) )->failure );
	}

	/** @return array<int|string, array{int, string|null}> */
	public static function legacy_code_provider(): array {
		return array(
			'400' => array( 400, 'http_4xx_other' ),
			'401' => array( 401, 'credential_rejected' ),
			'403' => array( 403, 'permission_denied' ),
			'404' => array( 404, 'repository_not_found_or_no_access' ),
			'410' => array( 410, 'ref_not_found' ),
			'413' => array( 413, 'response_too_large' ),
			'422' => array( 422, 'http_4xx_other' ),
			'429' => array( 429, 'rate_limited' ),
			'502' => array( 502, 'http_5xx' ),
			'503' => array( 503, 'http_5xx' ),
			'504' => array( 504, 'transport_other' ),
			'0'   => array( 0, null ),
			'500' => array( 500, null ),
			'418' => array( 418, null ),
		);
	}

	#[DataProvider( 'legacy_code_provider' )]
	public function test_a_plain_exception_code_derives_the_contract_reason( int $code, ?string $reason ): void {
		$derived = ProviderFailure::from_throwable( new RuntimeException( 'sentinel-provider-text', $code ) );

		self::assertSame( $reason, $derived?->reason );
		self::assertNull( ProviderFailure::carried( new RuntimeException( 'x', $code ) ) );
	}

	public function test_a_string_code_derives_nothing(): void {
		self::assertNull( ProviderFailure::from_throwable( new \PDOException( 'x' ) ) );
	}

	public function test_a_carried_failure_wins_over_the_legacy_code_on_every_carrier(): void {
		$failure = new ProviderFailure( 'rate_limited_secondary', 403, 30 );

		foreach (
			array(
				new ProviderRequestFailed( $failure ),
				new RepositoryReleaseReadUnavailable( 'x', 404, null, $failure ),
			) as $carrier
		) {
			self::assertSame( $failure, ProviderFailure::carried( $carrier ) );
			self::assertSame( $failure, ProviderFailure::from_throwable( $carrier ) );
		}
		$release = new ProviderFailure( 'release_changed' );
		self::assertSame( $release, ProviderFailure::carried( RepositoryReleaseAcquisitionRejected::changed( $release ) ) );
		self::assertSame( $release, ProviderFailure::carried( RepositoryReleaseInspectionRejected::invalid_release( $release ) ) );
	}

	public function test_request_failed_code_is_the_status_else_the_reason_default(): void {
		self::assertSame( 429, ( new ProviderRequestFailed( new ProviderFailure( 'rate_limited' ) ) )->getCode() );
		self::assertSame( 403, ( new ProviderRequestFailed( new ProviderFailure( 'rate_limited', 403 ) ) )->getCode() );
		self::assertSame( 504, ( new ProviderRequestFailed( new ProviderFailure( 'dns_failed' ) ) )->getCode() );
		self::assertSame( 503, ( new ProviderRequestFailed( new ProviderFailure( 'credential_store_unavailable' ) ) )->getCode() );
		self::assertSame( 409, ( new ProviderRequestFailed( new ProviderFailure( 'repository_identity_changed' ) ) )->getCode() );
		self::assertSame( 422, ( new ProviderRequestFailed( new ProviderFailure( 'repository_no_default_branch' ) ) )->getCode() );
		self::assertSame( 502, ( new ProviderRequestFailed( new ProviderFailure( 'response_invalid' ) ) )->getCode() );
		self::assertSame( 413, ( new ProviderRequestFailed( new ProviderFailure( 'response_too_large' ) ) )->getCode() );
		self::assertSame( 401, ( new ProviderRequestFailed( new ProviderFailure( 'credential_expired' ) ) )->getCode() );
		self::assertSame( 'The repository provider request failed.', ( new ProviderRequestFailed( new ProviderFailure( 'dns_failed' ) ) )->getMessage() );
	}

	public function test_a_request_failure_rejects_a_release_reason_and_a_failure_cannot_be_unserialized(): void {
		try {
			new ProviderRequestFailed( new ProviderFailure( 'release_changed' ) );
			self::fail( 'A release reason is not a request failure.' );
		} catch ( InvalidArgumentException ) {
			self::assertSame( 'release_changed', ( new ProviderFailure( 'release_changed' ) )->reason );
		}
		$this->expectException( \LogicException::class );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The test proves a serialized carrier is refused.
		unserialize( serialize( new ProviderFailure( 'http_5xx' ) ) );
	}

	public function test_only_retry_later_reasons_are_transient(): void {
		foreach ( array( 'rate_limited', 'dns_failed', 'http_5xx', 'credential_store_unavailable' ) as $reason ) {
			self::assertTrue( ( new ProviderFailure( $reason ) )->is_transient(), $reason );
		}
		foreach ( array( 'credential_rejected', 'repository_not_found', 'ref_not_found', 'http_4xx_other' ) as $reason ) {
			self::assertFalse( ( new ProviderFailure( $reason, 503 ) )->is_transient(), $reason );
		}
	}

	public function test_old_style_read_unavailable_calls_are_unchanged(): void {
		$previous = new RuntimeException( 'earlier' );
		$read     = new RepositoryReleaseReadUnavailable( 'message', 503, $previous );

		self::assertSame( 'message', $read->getMessage() );
		self::assertSame( 503, $read->getCode() );
		self::assertSame( $previous, $read->getPrevious() );
		self::assertNull( $read->failure );
		self::assertNull( ( new RepositoryReleaseReadUnavailable() )->failure );
	}

	/** @return array<string, array{string, string}> */
	public static function deployment_outcome_provider(): array {
		return array(
			'credential missing' => array( 'credential_missing', 'provider_credential_rejected' ),
			'credential store'   => array( 'credential_store_unavailable', 'provider_credential_rejected' ),
			'credential expired' => array( 'credential_expired', 'provider_credential_rejected' ),
			'permission'         => array( 'permission_denied', 'provider_access_denied' ),
			'not found'          => array( 'repository_not_found_or_no_access', 'provider_repository_missing' ),
			'owner'              => array( 'owner_not_found', 'provider_repository_missing' ),
			'ref'                => array( 'ref_not_found', 'provider_reference_unavailable' ),
			'no default branch'  => array( 'repository_no_default_branch', 'provider_reference_unavailable' ),
			'rate'               => array( 'rate_limited_secondary', 'provider_rate_limited' ),
			'dns'                => array( 'dns_failed', 'provider_unavailable' ),
			'5xx'                => array( 'http_5xx', 'provider_unavailable' ),
			'too large'          => array( 'response_too_large', 'provider_unavailable' ),
			'4xx'                => array( 'http_4xx_other', 'provider_request_invalid' ),
			'zip'                => array( 'site_zip_unavailable', 'provider_failed' ),
			'superseded'         => array( 'operation_superseded', 'provider_failed' ),
			'identity'           => array( 'repository_identity_changed', 'provider_failed' ),
		);
	}

	#[DataProvider( 'deployment_outcome_provider' )]
	public function test_reasons_map_onto_the_existing_deployment_outcomes( string $reason, string $outcome ): void {
		self::assertSame( $outcome, ( new ProviderFailure( $reason ) )->deployment_outcome_code() );
		self::assertSame( $outcome, DeploymentOutcome::from_provider_failure( new ProviderRequestFailed( new ProviderFailure( $reason ) ) )->get_code() );
	}

	public function test_a_provider_reason_beats_a_misleading_exception_code_for_the_outcome(): void {
		$carrier = new RepositoryReleaseReadUnavailable( 'x', 404, null, new ProviderFailure( 'credential_expired' ) );

		self::assertSame( 'provider_credential_rejected', DeploymentOutcome::from_provider_failure( $carrier )->get_code() );
		self::assertSame( 'provider_failed', DeploymentOutcome::from_provider_failure( new RuntimeException( 'x', 0 ) )->get_code() );
		self::assertSame( 'provider_unavailable', DeploymentOutcome::from_provider_failure( new RuntimeException( 'x', 413 ) )->get_code() );
	}

	public function test_rate_limit_copy_renders_the_retry_time_as_text(): void {
		self::assertSame( 'The Git provider rate limit has been reached.', ( new ProviderFailure( 'rate_limited' ) )->message() );
		self::assertSame( 'The Git provider rate limit has been reached. Try again in less than a minute.', ( new ProviderFailure( 'rate_limited', retry_after: 59 ) )->message() );
		self::assertSame( 'The Git provider rate limit has been reached. Try again in about 1 minute.', ( new ProviderFailure( 'rate_limited', retry_after: 60 ) )->message() );
		self::assertSame( 'The Git provider is throttling requests. Try again in about 2 minutes.', ( new ProviderFailure( 'rate_limited_secondary', retry_after: 61 ) )->message() );
		self::assertSame( 'The Git provider rate limit has been reached. Try again in about 5 minutes.', ( new ProviderFailure( 'rate_limited', reset_at: time() + 290 ) )->message() );
		self::assertSame( 'The Git provider rate limit has been reached.', ( new ProviderFailure( 'rate_limited', reset_at: time() - 5 ) )->message() );
		self::assertSame( 'The Git provider rate limit has been reached.', ( new ProviderFailure( 'rate_limited', reset_at: time() + 90000 ) )->message() );
		// A retry time on a reason that is not a rate limit is never rendered.
		self::assertSame( 'The Git provider reported an error.', ( new ProviderFailure( 'http_5xx', 503, 120 ) )->message() );
	}

	public function test_expired_credential_copy_shows_only_the_date(): void {
		self::assertSame( 'The credential has expired.', ( new ProviderFailure( 'credential_expired' ) )->message() );
		self::assertSame(
			'The credential expired on 2026-01-31.',
			( new ProviderFailure( 'credential_expired', credential_expires_at: '2026-01-31T23:59:59Z' ) )->message()
		);
	}

	public function test_credential_check_copy_distinguishes_the_reasons_and_old_calls_keep_their_sentences(): void {
		self::assertSame( 'The repository provider rejected this credential.', CredentialValidationResult::invalid()->get_display_message() );
		self::assertSame( 'The credential has expired.', CredentialValidationResult::invalid( new ProviderFailure( 'credential_expired' ) )->get_display_message() );
		self::assertSame( 'The credential lacks permission for this request.', CredentialValidationResult::invalid( new ProviderFailure( 'permission_denied' ) )->get_display_message() );
		self::assertSame( 'Booster could not read the saved credential.', CredentialValidationResult::unavailable( new ProviderFailure( 'credential_store_unavailable' ) )->get_display_message() );
		self::assertSame(
			'The Git provider rate limit has been reached. Try again in about 2 minutes.',
			CredentialValidationResult::rate_limited( new ProviderFailure( 'rate_limited', retry_after: 120 ) )->get_display_message()
		);
		self::assertSame( 'The repository provider returned an invalid credential-validation response.', CredentialValidationResult::invalid_response()->get_display_message() );
		self::assertNull( CredentialValidationResult::valid()->get_display_message() );
		self::assertNull( CredentialValidationResult::valid()->failure );
	}

	public function test_the_acquisition_changed_factory_has_its_own_reason(): void {
		$changed = RepositoryReleaseAcquisitionRejected::changed();

		self::assertSame( 'release_changed', $changed->reason );
		self::assertNull( $changed->failure );
		self::assertSame( 'invalid_release', RepositoryReleaseAcquisitionRejected::invalid_release()->reason );
		self::assertSame( 'cleanup_failed', RepositoryReleaseAcquisitionRejected::cleanup_failed()->reason );
	}

	public function test_inspection_rejection_keeps_its_coarse_reason_detail_and_adds_the_failure(): void {
		$failure  = new ProviderFailure( 'package_asset_missing' );
		$rejected = RepositoryReleaseInspectionRejected::incompatible( 'package_asset_ambiguous', $failure );

		self::assertSame( 'incompatible', $rejected->reason );
		self::assertSame( 'package_asset_ambiguous', $rejected->detail );
		self::assertSame( $failure, $rejected->failure );
		self::assertNull( RepositoryReleaseInspectionRejected::incompatible( 'Bad detail' )->detail );
		self::assertSame( 'no_releases', RepositoryReleaseInspectionRejected::no_releases()->reason );
	}
}
