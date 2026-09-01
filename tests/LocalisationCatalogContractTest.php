<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class LocalisationCatalogContractTest extends TestCase {
	public function testCatalogueCheckRejectsWarningsAndStaleOutput(): void {
		$composer = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/composer.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local catalogue contract.
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$script   = file_get_contents( dirname( __DIR__ ) . '/scripts/make-pot.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local catalogue contract.
		self::assertIsString( $script );
		self::assertSame( 'bash scripts/make-pot.sh', $composer['scripts']['i18n:pot'] ?? null );
		self::assertSame( 'bash scripts/make-pot.sh --check', $composer['scripts']['i18n:check'] ?? null );
		self::assertContains( '@i18n:check', $composer['scripts']['check'] ?? array() );
		self::assertStringContainsString( "grep -q '^Warning:'", $script );
		self::assertStringContainsString( 'cmp -s "$temporary_pot" "$pot"', $script );
		self::assertStringContainsString( '--ignore-domain', $script );
		self::assertStringContainsString( 'cmp -s "$normalised_pot" "$normalised_all_domains_pot"', $script );
		self::assertStringContainsString( 'domain-filtered and all-domain catalogues differ', $script );
		self::assertStringContainsString( '--skip-block-json', $script );
		self::assertStringContainsString( '--skip-theme-json', $script );
		self::assertStringContainsString( '-d memory_limit=512M', $script );
		self::assertStringContainsString( 'E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', $script );
		self::assertStringContainsString( '--include=ran-booster.php,autoload.php,index.php,uninstall.php,RAN,views,assets', $script );
		self::assertStringContainsString( '--exclude=assets/lib,tests,vendor,build,node_modules,ran-booster-workbench,.git,.github,.agents,.dex,scripts', $script );
	}

	public function testReleaseAllowlistContainsOnlyTheRuntimeCatalogue(): void {
		$root     = dirname( __DIR__ );
		$manifest = file( $root . '/release-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- Local release contract.
		self::assertIsArray( $manifest );
		self::assertContains( 'languages', $manifest );
		self::assertFileExists( $root . '/languages/ran-booster.pot' );
		$languageFiles = scandir( $root . '/languages' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- Local release contract.
		if ( false === $languageFiles ) {
			self::fail( 'Could not list the release catalogue directory.' );
		}
		self::assertSame( array( 'ran-booster.pot' ), array_values( array_diff( $languageFiles, array( '.', '..' ) ) ) );

		foreach ( array( 'build-release.sh', 'verify-release.sh' ) as $scriptName ) {
			$script = file_get_contents( $root . '/scripts/' . $scriptName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
			self::assertIsString( $script );
			self::assertStringContainsString( "'languages'", $script );
		}
	}
}
