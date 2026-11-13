<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\InvalidCredentialInput;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RAN\UpdaterSupport\V1\FailureText;

/**
 * Core applies the shared ran-updater-support screener at every failure-text sink.
 * The shared hostile/benign corpus is the cross-package contract: the policy itself
 * is tested in that package, and these tests prove Core's sinks honour it.
 */
#[CoversClass( ProviderDiagnosticResult::class )]
#[CoversClass( InvalidCredentialInput::class )]
#[CoversClass( RepositoryWebhookOperationResult::class )]
#[CoversClass( RepositoryWebhookFitnessResult::class )]
final class FailureTextShapeScreenTest extends TestCase {

	/** @return array<string, array<string, string>> */
	private static function corpus(): array {
		/** @var array<string, array<string, string>> $corpus */
		$corpus = require dirname( __DIR__, 2 ) . '/vendor/ran/updater-support/tests/fixtures/failure-text-corpus.php';

		return $corpus;
	}

	/** @return array<string, array{string}> */
	private static function labelled( string $group ): array {
		$rows = array();
		foreach ( self::corpus()[ $group ] as $label => $text ) {
			$rows[ $label ] = array( $text );
		}

		return $rows;
	}

	/** @return array<string, array{string}> */
	public static function hostile(): array {
		return self::labelled( 'hostile' );
	}

	/** @return array<string, array{string}> */
	public static function strict_only(): array {
		return self::labelled( 'strict_only' );
	}

	/** @return array<string, array{string}> */
	public static function display_only(): array {
		return self::labelled( 'display_only' );
	}

	/** @return array<string, array{string}> */
	public static function benign(): array {
		return self::labelled( 'benign' );
	}

	public function test_the_shared_corpus_is_present_and_every_group_is_populated(): void {
		foreach ( array( 'hostile', 'strict_only', 'display_only', 'benign' ) as $group ) {
			self::assertNotSame( array(), self::corpus()[ $group ] ?? array(), $group );
		}
	}

	#[DataProvider( 'hostile' )]
	public function test_hostile_text_is_dropped_by_display_and_strict( string $text ): void {
		self::assertNull( FailureText::display( $text, 512 ) );
		self::assertNull( FailureText::strict( $text, 512 ) );
	}

	#[DataProvider( 'strict_only' )]
	public function test_credential_prose_is_dropped_by_strict_and_kept_by_display( string $text ): void {
		self::assertNull( FailureText::strict( $text, 512 ) );
		self::assertNotNull( FailureText::display( $text, 512 ) );
	}

	#[DataProvider( 'display_only' )]
	public function test_curated_sentences_are_kept_by_display_and_dropped_by_strict( string $text ): void {
		self::assertNotNull( FailureText::display( $text, 512 ) );
		self::assertNull( FailureText::strict( $text, 512 ) );
	}

	#[DataProvider( 'benign' )]
	public function test_benign_text_survives_both_modes( string $text ): void {
		self::assertNotNull( FailureText::display( $text, 512 ) );
		self::assertNotNull( FailureText::strict( $text, 512 ) );
	}

	#[DataProvider( 'hostile' )]
	public function test_every_hostile_entry_is_rejected_by_the_diagnostic_result_message_and_remediation( string $text ): void {
		foreach ( array( array( $text, 'Retry later.' ), array( 'Retry later.', $text ) ) as $pair ) {
			$rejected = false;
			try {
				new ProviderDiagnosticResult( 'fail', 'x', $pair[0], $pair[1] );
			} catch ( InvalidArgumentException ) {
				$rejected = true;
			}
			self::assertTrue( $rejected, 'Hostile text was accepted by ProviderDiagnosticResult.' );
		}
	}

	#[DataProvider( 'hostile' )]
	public function test_every_hostile_entry_is_replaced_in_a_credential_input_failure( string $text ): void {
		$failure = new InvalidCredentialInput( InvalidCredentialInput::INVALID_SECRET_SHAPE, $text );

		self::assertNotSame( $text, $failure->getMessage() );
		self::assertNotSame( '', $failure->getMessage() );
	}

	#[DataProvider( 'hostile' )]
	public function test_every_hostile_entry_is_dropped_from_webhook_remediation_without_losing_the_result( string $text ): void {
		$configuration = array(
			'endpoint'     => 'matched',
			'events'       => 'matched',
			'content_type' => 'matched',
			'active'       => 'matched',
		);
		try {
			$operation = new RepositoryWebhookOperationResult( 'succeeded', 'configured_pending_delivery', '2026-08-02T20:00:00Z', '77', $configuration, 'configured_pending_delivery', $text );
			$fitness   = new RepositoryWebhookFitnessResult( 'supported', 'suitable', 'appropriate', 'observed', 'fitness_ok', '2026-08-02T20:00:00Z', $text );
		} catch ( InvalidArgumentException ) {
			// Empty, over-long and control-character text is a structural failure of the result, not guidance to drop.
			self::assertTrue( '' === $text || strlen( $text ) > 512 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $text ) );

			return;
		}

		self::assertSame( '', $operation->to_array()['remediation'] );
		self::assertSame( '', $fitness->to_array()['remediation'] );
		self::assertSame( 'configured_pending_delivery', $operation->to_array()['code'], 'The confirmed operation facts survive.' );
	}

	#[DataProvider( 'benign' )]
	public function test_benign_entries_are_accepted_by_the_diagnostic_result( string $text ): void {
		if ( strlen( $text ) > 512 ) {
			self::markTestSkipped( 'Longer than the 512 byte sink bound.' );
		}
		self::assertSame( $text, ( new ProviderDiagnosticResult( 'pass', 'x', $text, 'No action required.' ) )->message );
	}

	public function test_curated_provider_guidance_remains_accepted(): void {
		foreach ( array( 'Check repository access, then retry.', 'Check the token permissions and organisation approval.', 'The secret or password may have expired; update it.' ) as $text ) {
			self::assertSame( $text, FailureText::display( $text, 512 ) );
			self::assertSame( $text, ( new ProviderDiagnosticResult( 'fail', 'x', $text, $text ) )->remediation );
		}
	}

	public function test_text_longer_than_the_sink_bound_is_not_accepted_by_a_validating_sink(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticResult( 'fail', 'x', str_repeat( 'a ', 257 ), 'Retry later.' );
	}

	public function test_surrounding_spaces_are_not_a_valid_message(): void {
		$this->expectException( InvalidArgumentException::class );

		new ProviderDiagnosticResult( 'fail', 'x', ' Retry later. ', 'Retry later.' );
	}
}
