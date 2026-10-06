<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;

final class RepositoryReleaseWorkflowInputTest extends TestCase {
	public function test_target_exposes_only_provider_workflow_facts(): void {
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
		self::assertSame( 3, $target->source_revision() );
		self::assertSame( '101', $target->provider_repository_id() );
		self::assertSame( 'example', $target->package_root() );
		self::assertSame( '1.2.3', $target->installed_version() );
		self::assertSame( 'https://github.com/owner/example', $target->expected_update_uri() );
	}

	#[DataProvider( 'valid_https_update_uris' )]
	public function test_target_accepts_case_insensitive_https_scheme( string $update_uri ): void {
		$target = new RepositoryReleaseWorkflowTarget( 'plugin', 'example/example.php', 3, '101', 'example', '1.2.3', $update_uri );

		self::assertSame( $update_uri, $target->expected_update_uri() );
	}

	/** @return iterable<string, array{0:string}> */
	public static function valid_https_update_uris(): iterable {
		yield 'uppercase scheme' => array( 'HTTPS://github.com/owner/example' );
		yield 'mixed-case scheme' => array( 'HtTpS://github.com/owner/example' );
	}

	#[DataProvider( 'invalid_targets' )]
	public function test_target_rejects_invalid_provider_facts( array $arguments ): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowTarget( ...$arguments );
	}

	/** @return iterable<string, array{0:list<mixed>}> */
	public static function invalid_targets(): iterable {
		yield 'package type' => array( array( 'library', 'example/example.php', 3, '101' ) );
		yield 'empty identifier' => array( array( 'plugin', '', 3, '101' ) );
		yield 'revision' => array( array( 'plugin', 'example/example.php', 0, '101' ) );
		yield 'repository id control' => array( array( 'plugin', 'example/example.php', 3, "101\n" ) );
		yield 'http update uri' => array( array( 'plugin', 'example/example.php', 3, '101', 'example', '1.2.3', 'http://github.com/owner/example' ) );
		yield 'credentialed update uri' => array( array( 'plugin', 'example/example.php', 3, '101', 'example', '1.2.3', 'https://user:secret@github.com/owner/example' ) );
	}

	public function test_preflight_exposes_only_machine_facts(): void {
		$preflight = new RepositoryReleaseWorkflowPreflight( RepositoryReleaseWorkflowPreflight::PREFLIGHT_UNAVAILABLE, 'provider_unavailable' );

		self::assertSame( 'preflight_unavailable', $preflight->code() );
		self::assertSame( 'provider_unavailable', $preflight->reason_code() );
	}

	public function test_preflight_accepts_maximum_result_suffix_length(): void {
		$code      = str_repeat( 'a', 55 );
		$preflight = new RepositoryReleaseWorkflowPreflight( $code );

		self::assertSame( $code, $preflight->code() );
	}

	#[DataProvider( 'invalid_preflights' )]
	public function test_preflight_rejects_invalid_machine_codes( string $code, string $reason ): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowPreflight( $code, $reason );
	}

	/** @return iterable<string, array{0:string,1:string}> */
	public static function invalid_preflights(): iterable {
		yield 'empty code' => array( '', '' );
		yield 'mixed case code' => array( 'Ready', '' );
		yield 'punctuated reason' => array( 'ready', 'provider-unavailable' );
		yield 'oversized code' => array( str_repeat( 'a', 56 ), '' );
		yield 'oversized reason' => array( 'ready', str_repeat( 'a', 97 ) );
	}
}
