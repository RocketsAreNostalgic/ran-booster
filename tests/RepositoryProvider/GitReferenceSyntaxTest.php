<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\GitReferenceSyntax;

final class GitReferenceSyntaxTest extends TestCase {

	#[DataProvider( 'validNamedReferences' )]
	public function testAcceptsTheExistingNamedReferenceGrammar( string $reference ): void {
		self::assertTrue( GitReferenceSyntax::isValidNamedReference( $reference ) );
	}

	/** @return iterable<string, array{string}> */
	public static function validNamedReferences(): iterable {
		yield 'simple branch' => array( 'main' );
		yield 'slash separated' => array( 'feature/release-candidate' );
		yield 'allowed punctuation' => array( 'release_1.2+candidate' );
		yield 'single byte limit' => array( str_repeat( 'a', 255 ) );
		yield 'unicode bytes below limit' => array( str_repeat( 'é', 127 ) );
	}

	#[DataProvider( 'invalidNamedReferences' )]
	public function testRejectsEveryExistingNamedReferenceEdge( string $reference ): void {
		self::assertFalse( GitReferenceSyntax::isValidNamedReference( $reference ) );
	}

	/** @return iterable<string, array{string}> */
	public static function invalidNamedReferences(): iterable {
		yield 'empty' => array( '' );
		yield 'leading space' => array( ' main' );
		yield 'trailing space' => array( 'main ' );
		yield 'byte limit exceeded' => array( str_repeat( 'a', 256 ) );
		yield 'unicode byte limit exceeded' => array( str_repeat( 'é', 128 ) );
		yield 'at sign alone' => array( '@' );
		yield 'leading dash' => array( '-main' );
		yield 'leading slash' => array( '/main' );
		yield 'trailing slash' => array( 'main/' );
		yield 'trailing dot' => array( 'main.' );
		yield 'double dot' => array( 'release..candidate' );
		yield 'reflog marker' => array( 'main@{1}' );
		yield 'repeated slash' => array( 'feature//candidate' );

		foreach ( array( "\x00", "\x1F", ' ', "\x7F", '~', '^', ':', '?', '*', '[', '\\', '%' ) as $byte ) {
			yield 'forbidden byte ' . bin2hex( $byte ) => array( 'main' . $byte . 'candidate' );
		}

		yield 'dot-prefixed root segment' => array( '.main' );
		yield 'dot-prefixed nested segment' => array( 'feature/.candidate' );
		yield 'lock suffix' => array( 'main.lock' );
		yield 'case-insensitive lock suffix' => array( 'feature/candidate.LOCK' );
	}
}
