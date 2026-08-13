<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\InvalidCredentialInput;

final class ProviderInputFailureContractTest extends TestCase {

	/** @return iterable<string, array{string, string}> */
	public static function unsafeFailures(): iterable {
		yield 'unknown reason' => array( 'provider_specific_reason', 'Safe-looking text.' );
		yield 'token shaped' => array( InvalidCredentialInput::INVALID_SECRET_SHAPE, 'Rejected github_pat_secretcanary123.' );
		yield 'authorization header' => array( InvalidCredentialInput::INVALID_SECRET_SHAPE, 'Authorization: Bearer secretcanary' );
		yield 'absolute path' => array( InvalidCredentialInput::INVALID_CONFIGURATION, 'Read /srv/private/credential.txt.' );
		yield 'markup' => array( InvalidCredentialInput::INVALID_CONFIGURATION, '<strong>Invalid</strong>' );
		yield 'control character' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\nvalue" );
		yield 'unicode format control' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\u{202E}value" );
		yield 'unicode next line' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\u{0085}value" );
		yield 'unicode line separator' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\u{2028}value" );
		yield 'invalid utf-8' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\xC3\x28value" );
		yield 'structured text' => array( InvalidCredentialInput::INVALID_CONFIGURATION, '{"reason":"invalid"}' );
		yield 'oversized' => array( InvalidCredentialInput::INVALID_CONFIGURATION, str_repeat( 'a', 513 ) );
		yield 'credentialed url' => array( InvalidCredentialInput::INVALID_CONFIGURATION, 'See https://secret@example.test/help.' );
	}

	#[DataProvider( 'unsafeFailures' )]
	public function testUnknownOrUnsafeProviderCopyFailsClosed( string $reason, string $message ): void {
		$this->expectException( InvalidArgumentException::class );

		new InvalidCredentialInput( $reason, $message );
	}

	public function testKnownReasonAndSafeProviderCopyRemainActionable(): void {
		$failure = new InvalidCredentialInput(
			InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH,
			'Choose the credential kind that matches the submitted secret.'
		);

		self::assertSame( InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH, $failure->reason );
		self::assertSame( 'Choose the credential kind that matches the submitted secret.', $failure->getMessage() );
	}
}
