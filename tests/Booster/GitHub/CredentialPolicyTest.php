<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\CredentialPolicy;
use RAN\RepositoryProvider\InvalidCredentialInput;

final class CredentialPolicyTest extends TestCase {

	/** @return array<string, array{string, string, string, string}> */
	public static function invalidInput(): array {
		return array(
			'email owner' => array( 'fine-grained', 'person@example.test', 'github_pat_example', InvalidCredentialInput::INVALID_CONFIGURATION ),
		);
	}

	#[DataProvider( 'invalidInput' )]
	public function testRejectsOnlyClosedActionableInputFailures( string $kind, string $owner, string $token, string $reason ): void {
		try {
			( new CredentialPolicy() )->normalizeCredential(
				array(
					'label'         => 'Repository access',
					'kind'          => $kind,
					'configuration' => array( 'owner' => $owner ),
				),
				$token
			);
			self::fail( 'The recognized input mismatch must be rejected.' );
		} catch ( InvalidCredentialInput $failure ) {
			self::assertSame( $reason, $failure->reason );
			self::assertStringNotContainsString( $token, $failure->getMessage() );
		}
	}

	/** @return array<string, array{string, string, string}> */
	public static function invalidSubmittedToken(): array {
		return array(
			'classic uses fine prefix'           => array( 'classic', 'github_pat_' . str_repeat( 'a', 40 ), InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH ),
			'classic has unknown prefix'         => array( 'classic', 'future_' . str_repeat( 'a', 40 ), InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH ),
			'fine-grained uses classic prefix'   => array( 'fine-grained', 'ghp_' . str_repeat( 'a', 40 ), InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH ),
			'fine-grained has unknown prefix'    => array( 'fine-grained', 'future_' . str_repeat( 'a', 40 ), InvalidCredentialInput::CREDENTIAL_KIND_MISMATCH ),
			'token is truncated'                 => array( 'classic', 'ghp_short', InvalidCredentialInput::INVALID_SECRET_SHAPE ),
			'token is over the defensive bound'  => array( 'classic', 'ghp_' . str_repeat( 'a', 252 ), InvalidCredentialInput::INVALID_SECRET_SHAPE ),
			'token contains punctuation'         => array( 'classic', 'ghp_' . str_repeat( 'a', 35 ) . '-', InvalidCredentialInput::INVALID_SECRET_SHAPE ),
			'token contains a control character' => array( 'fine-grained', 'github_pat_' . str_repeat( 'a', 29 ) . "\n", InvalidCredentialInput::INVALID_SECRET_SHAPE ),
		);
	}

	#[DataProvider( 'invalidSubmittedToken' )]
	public function testNewlySubmittedTokensUseClosedPrefixAndShapeValidation( string $kind, string $token, string $reason ): void {
		$policy = new CredentialPolicy();

		try {
			$policy->validateSubmittedCredential(
				array(
					'label'         => 'Repository access',
					'kind'          => $kind,
					'configuration' => array( 'owner' => 'example-owner' ),
				),
				$token
			);
			self::fail( 'The malformed submitted token must be rejected.' );
		} catch ( InvalidCredentialInput $failure ) {
			self::assertSame( $reason, $failure->reason );
			self::assertStringNotContainsString( $token, $failure->getMessage() );
		}
	}

	public function testSubmittedTokenLengthRemainsVariableWithinTheDefensiveBounds(): void {
		$policy = new CredentialPolicy();

		foreach ( array(
			array( 'classic', 'ghp_' . str_repeat( 'a', 36 ) ),
			array( 'fine-grained', 'github_pat_' . str_repeat( 'b', 29 ) ),
			array( 'classic', 'ghp_' . str_repeat( 'c', 251 ) ),
		) as [ $kind, $token ] ) {
			$policy->validateSubmittedCredential(
				array(
					'label'         => 'Repository access',
					'kind'          => $kind,
					'configuration' => array( 'owner' => 'example-owner' ),
				),
				$token
			);
			self::addToAssertionCount( 1 );
		}
	}

	public function testUnknownPrefixesRemainAvailableForFutureOrLegacyFormats(): void {
		$policy  = new CredentialPolicy();
		$fine    = $policy->normalizeCredential(
			array(
				'label'         => 'Fine-grained access',
				'kind'          => 'fine-grained',
				'configuration' => array( 'owner' => 'example-owner' ),
			),
			'future_token_format'
		);
		$classic = $policy->normalizeCredential(
			array(
				'label'         => 'Classic access',
				'kind'          => 'classic',
				'configuration' => array( 'owner' => '' ),
			),
			'github_pat_future-or-mismatched-format'
		);

		self::assertSame( 'future_token_format', $fine['secret'] );
		self::assertSame( 'github_pat_future-or-mismatched-format', $classic['secret'] );
	}
}
