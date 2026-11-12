<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\ProviderDiagnosticResult;

#[CoversClass( ProviderDiagnosticResult::class )]
final class FailureTextScreenTest extends TestCase {

	/** @return array<string, array{string}> */
	public static function unsafe_text(): array {
		return array(
			'token'            => array( 'Failed with ghp_abcdefghijklmnop1234' ),
			'credentialed url' => array( 'See https://user:secret@example.com/repo' ),
			'plain url'        => array( 'Fetch https://example.com/a?x=1 failed' ),
			'query secret'     => array( 'Rejected token=abcdef' ),
			'absolute path'    => array( 'Cannot write /var/www/html/wp-content/x' ),
			'relative path'    => array( 'Cannot write wp-content/uploads/x' ),
			'digest'           => array( 'Hash 0123456789abcdef0123456789abcdef01234567 mismatch' ),
			'markup'           => array( 'Bad <b>thing</b>' ),
			'header'           => array( 'Authorization: Bearer abcdefghijkl' ),
		);
	}

	#[DataProvider( 'unsafe_text' )]
	public function test_unsafe_failure_text_is_rejected_by_the_shared_screen_and_activity_text_boundary( string $text ): void {
		self::assertFalse( ProviderDiagnosticResult::is_safe_text( $text ) );
		$this->expectException( \InvalidArgumentException::class );
		new ProviderDiagnosticResult( 'failed', 'x', $text, 'Retry later.' );
	}

	public function test_plain_fixed_sentences_remain_accepted(): void {
		self::assertTrue( ProviderDiagnosticResult::is_safe_text( 'Check repository access, then retry.' ) );
	}
}
