<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';
require_once __DIR__ . '/Support/RepositoryResolverSecretsStub.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\RepositoryBrowser;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class CredentialExpiryValidationTest extends TestCase {

	private const TOKEN = 'github-expiry-validation-canary';

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function validExpiryHeaders(): iterable {
		yield 'UTC' => array( '2026-08-31 14:25:30 UTC', '2026-08-31T14:25:30Z' );
		yield 'positive offset' => array( '2026-08-31 14:25:30 +0100', '2026-08-31T13:25:30Z' );
		yield 'negative offset' => array( '2026-08-31 14:25:30 -0530', '2026-08-31T19:55:30Z' );
		yield 'past date' => array( '2020-01-02 03:04:05 UTC', '2020-01-02T03:04:05Z' );
	}

	#[DataProvider( 'validExpiryHeaders' )]
	public function testValidationReturnsStrictlyParsedProviderExpiry( string $header, string $expected ): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset( $this->response( $header ) );

		$result = ( new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'expiry-profile' => self::TOKEN ) )
		) )->validateCredential( 'expiry-profile' );

		self::assertTrue( $result->isValid() );
		self::assertNotNull( $result->expiry );
		self::assertTrue( $result->expiry->isKnown() );
		self::assertSame( $expected, $result->expiry->expiresAt );
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function unknownExpiryHeaders(): iterable {
		yield 'missing' => array( null );
		yield 'invalid calendar date' => array( '2026-02-30 14:25:30 UTC' );
		yield 'unsupported timezone abbreviation' => array( '2026-08-31 14:25:30 BST' );
		yield 'invalid offset' => array( '2026-08-31 14:25:30 +2500' );
		yield 'surrounding whitespace' => array( ' 2026-08-31 14:25:30 UTC ' );
		yield 'control character' => array( "2026-08-31 14:25:30 UTC\n" );
		yield 'oversized' => array( str_repeat( 'x', 65 ) );
	}

	#[DataProvider( 'unknownExpiryHeaders' )]
	public function testMissingOrMalformedExpiryMetadataIsUnknown( ?string $header ): void {
		\RAN\Booster\GitHub\repository_resolver_http_reset( $this->response( $header ) );

		$result = ( new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'expiry-profile' => self::TOKEN ) )
		) )->validateCredential( 'expiry-profile' );

		self::assertTrue( $result->isValid() );
		self::assertNotNull( $result->expiry );
		self::assertFalse( $result->expiry->isKnown() );
		self::assertNull( $result->expiry->expiresAt );
	}

	public function testFailedValidationNeverReturnsExpiryMetadataOrLeaksHeader(): void {
		$header = '2026-08-31 14:25:30 UTC';
		\RAN\Booster\GitHub\repository_resolver_http_reset(
			array(
				'response' => array( 'code' => 401 ),
				'headers'  => array( 'GitHub-Authentication-Token-Expiration' => $header ),
				'body'     => '{"message":"Bad credentials"}',
			)
		);

		$result = ( new RepositoryBrowser(
			new RepositoryResolverSecretsStub( array( 'expiry-profile' => self::TOKEN ) )
		) )->validateCredential( 'expiry-profile' );

		self::assertFalse( $result->isValid() );
		self::assertNull( $result->expiry );
		self::assertStringNotContainsString( $header, (string) $result->getDisplayMessage() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function response( ?string $expiryHeader ): array {
		$headers = null === $expiryHeader
			? array()
			: array( 'GitHub-Authentication-Token-Expiration' => $expiryHeader );

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => $headers,
			'body'     => '{"login":"expiry-user"}',
		);
	}
}
