<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;

final class RepositoryReleaseWorkflowInputTest extends TestCase {
	public function testTargetExposesOnlyProviderWorkflowFacts(): void {
		$target = new RepositoryReleaseWorkflowTarget(
			'plugin',
			'example/example.php',
			3,
			'101',
			'example',
			'1.2.3',
			'https://github.com/owner/example'
		);

		self::assertSame( 'plugin', $target->type() );
		self::assertSame( 'example/example.php', $target->identifier() );
		self::assertSame( 3, $target->sourceRevision() );
		self::assertSame( '101', $target->providerRepositoryId() );
		self::assertSame( 'example', $target->packageRoot() );
		self::assertSame( '1.2.3', $target->installedVersion() );
		self::assertSame( 'https://github.com/owner/example', $target->expectedUpdateUri() );
	}

	#[DataProvider( 'validHttpsUpdateUris' )]
	public function testTargetAcceptsCaseInsensitiveHttpsScheme( string $updateUri ): void {
		$target = new RepositoryReleaseWorkflowTarget( 'plugin', 'example/example.php', 3, '101', 'example', '1.2.3', $updateUri );

		self::assertSame( $updateUri, $target->expectedUpdateUri() );
	}

	/** @return iterable<string, array{0:string}> */
	public static function validHttpsUpdateUris(): iterable {
		yield 'uppercase scheme' => array( 'HTTPS://github.com/owner/example' );
		yield 'mixed-case scheme' => array( 'HtTpS://github.com/owner/example' );
	}

	#[DataProvider( 'invalidTargets' )]
	public function testTargetRejectsInvalidProviderFacts( array $arguments ): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowTarget( ...$arguments );
	}

	/** @return iterable<string, array{0:list<mixed>}> */
	public static function invalidTargets(): iterable {
		yield 'package type' => array( array( 'library', 'example/example.php', 3, '101' ) );
		yield 'empty identifier' => array( array( 'plugin', '', 3, '101' ) );
		yield 'revision' => array( array( 'plugin', 'example/example.php', 0, '101' ) );
		yield 'repository id control' => array( array( 'plugin', 'example/example.php', 3, "101\n" ) );
		yield 'http update uri' => array( array( 'plugin', 'example/example.php', 3, '101', 'example', '1.2.3', 'http://github.com/owner/example' ) );
		yield 'credentialed update uri' => array( array( 'plugin', 'example/example.php', 3, '101', 'example', '1.2.3', 'https://user:secret@github.com/owner/example' ) );
	}

	public function testPreflightExposesOnlyMachineFacts(): void {
		$preflight = new RepositoryReleaseWorkflowPreflight( RepositoryReleaseWorkflowPreflight::PREFLIGHT_UNAVAILABLE, 'provider_unavailable' );

		self::assertSame( 'preflight_unavailable', $preflight->code() );
		self::assertSame( 'provider_unavailable', $preflight->reasonCode() );
	}

	public function testPreflightAcceptsMaximumResultSuffixLength(): void {
		$code      = str_repeat( 'a', 55 );
		$preflight = new RepositoryReleaseWorkflowPreflight( $code );

		self::assertSame( $code, $preflight->code() );
	}

	#[DataProvider( 'invalidPreflights' )]
	public function testPreflightRejectsInvalidMachineCodes( string $code, string $reason ): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowPreflight( $code, $reason );
	}

	/** @return iterable<string, array{0:string,1:string}> */
	public static function invalidPreflights(): iterable {
		yield 'empty code' => array( '', '' );
		yield 'mixed case code' => array( 'Ready', '' );
		yield 'punctuated reason' => array( 'ready', 'provider-unavailable' );
		yield 'oversized code' => array( str_repeat( 'a', 56 ), '' );
		yield 'oversized reason' => array( 'ready', str_repeat( 'a', 97 ) );
	}
}
