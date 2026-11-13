<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\UpdaterSupport\V1\FailureText;

final class ProviderInputFailureContractTest extends TestCase {

	/** @return iterable<string, array{string, string}> */
	public static function unsafe_failures(): iterable {
		yield 'token shaped' => array( InvalidCredentialInput::INVALID_SECRET_SHAPE, 'Rejected github_pat_secretcanary123.' );
		yield 'quoted token prefix' => array( InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH, 'Classic personal access tokens must begin with ghp_.' );
		yield 'authorization header' => array( InvalidCredentialInput::INVALID_SECRET_SHAPE, 'Authorization: Bearer secretcanary1' );
		yield 'absolute path' => array( InvalidCredentialInput::INVALID_CONFIGURATION, 'Read /srv/private/credential.txt.' );
		yield 'markup' => array( InvalidCredentialInput::INVALID_CONFIGURATION, '<strong>Invalid</strong>' );
		yield 'control character' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\nvalue" );
		yield 'unicode format control' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\u{202E}value" );
		yield 'unicode next line' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\u{0085}value" );
		yield 'unicode line separator' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\u{2028}value" );
		yield 'curly apostrophe' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Enter the owner of the token\u{2019}s resource." );
		yield 'invalid utf-8' => array( InvalidCredentialInput::INVALID_CONFIGURATION, "Invalid\xC3\x28value" );
		yield 'structured text' => array( InvalidCredentialInput::INVALID_CONFIGURATION, '{"reason":"invalid"}' );
		yield 'oversized' => array( InvalidCredentialInput::INVALID_CONFIGURATION, str_repeat( 'a ', 257 ) );
		yield 'credentialed url' => array( InvalidCredentialInput::INVALID_CONFIGURATION, 'See https://secret@example.test/help.' );
		yield 'empty' => array( InvalidCredentialInput::INVALID_CONFIGURATION, '' );
	}

	#[DataProvider( 'unsafe_failures' )]
	public function test_unsafe_provider_copy_is_replaced_by_core_copy_and_keeps_the_typed_reason( string $reason, string $message ): void {
		$failure = new InvalidCredentialInput( $reason, $message );

		self::assertSame( $reason, $failure->reason );
		self::assertNotSame( $message, $failure->getMessage() );
		self::assertNotSame( '', $failure->getMessage() );
		self::assertSame( $failure->getMessage(), FailureText::display( $failure->getMessage(), 512 ), 'Core copy for each reason must itself pass the screen.' );
	}

	public function test_an_unknown_reason_fails_closed(): void {
		$this->expectException( InvalidArgumentException::class );

		new InvalidCredentialInput( 'provider_specific_reason', 'Safe-looking text.' );
	}

	public function test_known_reason_and_safe_provider_copy_remain_actionable(): void {
		$failure = new InvalidCredentialInput(
			InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH,
			'Choose the credential kind that matches the submitted secret.'
		);

		self::assertSame( InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH, $failure->reason );
		self::assertSame( 'Choose the credential kind that matches the submitted secret.', $failure->getMessage() );
	}
}
