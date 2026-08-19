<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\RepositorySnapshot;

final class RepositorySnapshotTest extends TestCase {
	public function testReturnsCanonicalDocumentOrderWithoutChangingIdentity(): void {
		$snapshot = $this->snapshot(
			array(
				'z.php' => '<?php',
				'a.php' => '<?php',
			)
		);
		self::assertSame( array( 'a.php', 'z.php' ), $snapshot->documentPaths() );
		self::assertSame( 'owner/repository', $snapshot->repository() );
		self::assertSame( str_repeat( 'a', 40 ), $snapshot->sha() );
		$entries          = $snapshot->entries();
		$entries['a.php'] = array();
		self::assertSame( 'blob', $snapshot->entries()['a.php']['type'] );
	}

	#[DataProvider( 'invalidDocuments' )]
	public function testRejectsUnsafeOrNonTextDocuments( string $path, string $content ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->snapshot( array( $path => $content ) );
	}

	/** @return iterable<string,array{string,string}> */
	public static function invalidDocuments(): iterable {
		yield 'parent traversal' => array( '../unsafe.php', '<?php' );
		yield 'absolute' => array( '/unsafe.php', '<?php' );
		yield 'backslash' => array( 'unsafe\\path.php', '<?php' );
		yield 'nul' => array( 'unsafe.php', "bad\0text" );
		yield 'invalid utf8' => array( 'unsafe.php', "bad\xC3\x28" );
	}

	public function testRejectsDocumentSizeAndEntryModeMismatches(): void {
		$entry = array(
			'type' => 'blob',
			'mode' => '040000',
			'sha'  => str_repeat( 'b', 40 ),
			'size' => 5,
		);
		try {
			new RepositorySnapshot( '101', 'owner/repository', 'main', str_repeat( 'a', 40 ), array( 'a.php' => $entry ), array( 'a.php' => '<?php' ) );
			self::fail( 'A blob with a tree mode must refuse.' );
		} catch ( InvalidArgumentException ) {
			self::assertTrue( true );
		}
		$entry['mode'] = '100644';
		$entry['size'] = 4;
		$this->expectException( InvalidArgumentException::class );
		new RepositorySnapshot( '101', 'owner/repository', 'main', str_repeat( 'a', 40 ), array( 'a.php' => $entry ), array( 'a.php' => '<?php' ) );
	}

	public function testRejectsEntryAndDocumentCountOverflow(): void {
		$entry   = array(
			'type' => 'blob',
			'mode' => '100644',
			'sha'  => str_repeat( 'b', 40 ),
			'size' => 0,
		);
		$entries = array_fill_keys( array_map( static fn ( int $index ): string => "files/{$index}.php", range( 0, 2000 ) ), $entry );
		try {
			new RepositorySnapshot( '101', 'owner/repository', 'main', str_repeat( 'a', 40 ), $entries, array() );
			self::fail( 'Entry overflow must refuse.' );
		} catch ( InvalidArgumentException ) {
			self::assertTrue( true );
		}
		$documents = array_fill_keys( array_map( static fn ( int $index ): string => "files/{$index}.php", range( 0, 256 ) ), '' );
		$entries   = array_fill_keys( array_keys( $documents ), $entry );
		$this->expectException( InvalidArgumentException::class );
		new RepositorySnapshot( '101', 'owner/repository', 'main', str_repeat( 'a', 40 ), $entries, $documents );
	}

	/** @param array<string,string> $documents */
	private function snapshot( array $documents ): RepositorySnapshot {
		$entries = array();
		foreach ( $documents as $path => $content ) {
			$entries[ $path ] = array(
				'type' => 'blob',
				'mode' => '100644',
				'sha'  => sha1( $path ),
				'size' => strlen( $content ),
			);
		}
		return new RepositorySnapshot( '101', 'owner/repository', 'main', str_repeat( 'a', 40 ), $entries, $documents );
	}
}
