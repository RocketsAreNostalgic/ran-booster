<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only quick local saves carry a bounded HTMX request timeout. Long-running operations stay
 * unbounded because a client timeout would report "unknown" for work that may still finish.
 */
final class HtmxRequestTimeoutContractTest extends TestCase {

	private const TIMEOUT = 'hx-request=\'{"timeout":30000}\'';

	/** Every server-rendered HTMX request element that is allowed to time out, and how many per file. */
	private const BOUNDED = array(
		'views/settings-general.php'               => 1,
		'views/provider-public-lookup-profile.php' => 1,
		'views/provider/modals.php'                => 2,
		'views/packages/edit.php'                  => 1,
	);

	/** @return array<string, list<string>> File path => lines that issue an HTMX request, from rendered-markup sources. */
	private function request_lines(): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/views', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
			$contents = file( $file->getPathname(), FILE_IGNORE_NEW_LINES );
			foreach ( false === $contents ? array() : $contents as $line ) {
				if ( str_contains( $line, 'hx-post=' ) ) {
					$found[ $relative ][] = $line;
				}
			}
		}

		return $found;
	}

	public function test_exactly_the_quick_local_saves_carry_the_timeout(): void {
		$counts = array();
		foreach ( $this->request_lines() as $file => $lines ) {
			foreach ( $lines as $line ) {
				if ( str_contains( $line, 'hx-request=' ) ) {
					self::assertStringContainsString( self::TIMEOUT, $line, $file );
					$counts[ $file ] = ( $counts[ $file ] ?? 0 ) + 1;
				}
			}
		}
		ksort( $counts );
		$expected = self::BOUNDED;
		ksort( $expected );

		self::assertSame( $expected, $counts );
	}

	/** @return iterable<string, array{string, string, bool}> */
	public static function element_provider(): iterable {
		yield 'settings switch and numeric form' => array( 'views/settings-general.php', 'data-ran-booster-general-form', true );
		yield 'public lookup profile save' => array( 'views/provider-public-lookup-profile.php', 'ran-booster-public-lookup-profile__form', true );
		yield 'credential profile save' => array( 'views/provider/modals.php', '#ran-booster-access-profile-error"', true );
		yield 'webhook secret profile save' => array( 'views/provider/modals.php', '#ran-booster-webhook-profile-error"', true );
		yield 'package settings save' => array( 'views/packages/edit.php', 'data-ran-booster-package-settings-save', true );
		yield 'credential validation' => array( 'views/provider.php', 'data-ran-booster-credential-validation', false );
		yield 'webhook profile delete' => array( 'views/provider.php', '#ran-booster-delete-webhook-profile-error', false );
		yield 'credential delete' => array( 'views/provider/modals.php', '#ran-booster-delete-access-profile-error', false );
		yield 'reinstall' => array( 'views/packages/reinstall.php', 'hx-post=', false );
		yield 'save and check' => array( 'views/packages/branch-readiness.php', 'hx-post=', false );
		yield 'diagnostics' => array( 'views/troubleshooting.php', 'hx-post=', false );
		yield 'debug capture' => array( 'views/debug-capture.php', 'hx-post=', false );
	}

	#[DataProvider( 'element_provider' )]
	public function test_each_named_element_is_bounded_or_unbounded_as_decided( string $file, string $marker, bool $bounded ): void {
		$lines = array_filter(
			$this->request_lines()[ $file ] ?? array(),
			static fn ( string $line ): bool => str_contains( $line, $marker )
		);

		self::assertNotSame( array(), $lines, $file . ' has an HTMX request element containing ' . $marker );
		foreach ( $lines as $line ) {
			self::assertSame( $bounded, str_contains( $line, self::TIMEOUT ), $file . ' ' . $marker );
		}
	}
}
