<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RAN\RepositoryProvider\CredentialValidationResult;

final class CredentialExpiryReportTest extends TestCase {

	public function test_valid_credential_result_retains_optional_known_and_unknown_reports(): void {
		$known   = CredentialValidationResult::valid(
			CredentialExpiryReport::known( '2026-08-23T12:30:00Z' )
		);
		$unknown = CredentialValidationResult::valid( CredentialExpiryReport::unknown() );

		self::assertSame( '2026-08-23T12:30:00Z', $known->expiry?->expires_at );
		self::assertTrue( $known->expiry?->is_known() );
		self::assertNull( $unknown->expiry?->expires_at );
		self::assertFalse( $unknown->expiry?->is_known() );
		self::assertNull( CredentialValidationResult::valid()->expiry );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalid_timestamps(): array {
		return array(
			'date only'      => array( '2026-08-23' ),
			'offset'         => array( '2026-08-23T12:30:00+01:00' ),
			'leap day'       => array( '2025-02-29T12:30:00Z' ),
			'invalid hour'   => array( '2026-08-23T24:00:00Z' ),
			'control suffix' => array( "2026-08-23T12:30:00Z\n" ),
		);
	}

	#[DataProvider( 'invalid_timestamps' )]
	public function test_known_report_rejects_non_canonical_utc_timestamps( string $timestamp ): void {
		$this->expectException( InvalidArgumentException::class );

		CredentialExpiryReport::known( $timestamp );
	}
}
