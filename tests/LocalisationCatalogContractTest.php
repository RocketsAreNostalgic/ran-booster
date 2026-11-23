<?php

declare(strict_types=1);

namespace RAN\Tests;

use PHPUnit\Framework\TestCase;

final class LocalisationCatalogContractTest extends TestCase {
	public function test_catalogue_check_rejects_warnings_and_domain_drift(): void {
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
		self::assertStringNotContainsString( 'is stale', $script, 'The catalogue is generated, never compared with a committed copy.' );
		self::assertStringContainsString( '--source) ', $script );
		self::assertStringContainsString( '--output) ', $script );
		self::assertStringContainsString( '[[ "$mode" == check ]] && exit 0', $script );
		self::assertStringContainsString( '--ignore-domain', $script );
		self::assertStringContainsString( 'cmp -s "$normalised_pot" "$normalised_all_domains_pot"', $script );
		self::assertStringContainsString( 'domain-filtered and all-domain catalogues differ', $script );
		self::assertStringContainsString( '--skip-block-json', $script );
		self::assertStringContainsString( '--skip-theme-json', $script );
		self::assertStringContainsString( '-d memory_limit=512M', $script );
		self::assertStringContainsString( 'E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED', $script );
		self::assertStringContainsString( 'php_runner=( php -n )', $script );
		self::assertStringContainsString( '"$wp_cli" --info >/dev/null 2>&1', $script );
		self::assertStringContainsString( 'php_runner=( php )', $script );
		self::assertStringContainsString( "grep -qx 'WP-CLI 2.12.0' <<< \"\$wp_cli_version\"", $script );
		self::assertStringContainsString( '"${php_runner[@]}" -d memory_limit=512M', $script );
		self::assertSame( 2, substr_count( $script, "--package-name='RAN Booster'" ) );
		self::assertSame( 2, substr_count( $script, "--file-comment=\$'Copyright (C) 2026 Rockets Are Nostalgic\\nThis file is distributed under the GPL-2.0-only.'" ) );
		self::assertSame( 2, substr_count( $script, '"Project-Id-Version":"RAN Booster"' ) );
		self::assertStringContainsString( '--include=ran-booster.php,autoload.php,index.php,uninstall.php,RAN,views,assets', $script );
		self::assertStringContainsString( '--exclude=assets/lib,tests,vendor,build,node_modules,ran-booster-workbench,.git,.github,.agents,.dex,scripts', $script );

		$fixture_script = file_get_contents( dirname( __DIR__ ) . '/scripts/make-test-i18n-fixture.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local catalogue contract.
		self::assertIsString( $fixture_script );
		self::assertStringContainsString( 'php_runner=( php -n )', $fixture_script );
		self::assertStringContainsString( '"$wp_cli" --info >/dev/null 2>&1', $fixture_script );
		self::assertStringContainsString( 'php_runner=( php )', $fixture_script );
		self::assertStringContainsString( "grep -qx 'WP-CLI 2.12.0' <<< \"\$wp_cli_version\"", $fixture_script );
		self::assertStringContainsString( '"${php_runner[@]}" "$wp_cli" i18n make-mo', $fixture_script );

		$workflow = file_get_contents( dirname( __DIR__ ) . '/.github/workflows/quality.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local catalogue contract.
		$readme   = file_get_contents( dirname( __DIR__ ) . '/README.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local catalogue contract.
		self::assertIsString( $workflow );
		self::assertSame( 4, substr_count( $workflow, 'tools: composer:v2, wp-cli:2.12.0' ) );
		self::assertIsString( $readme );
		self::assertStringContainsString( 'WP-CLI 2.12.0', $readme );
	}

	public function test_release_allowlist_generates_the_catalogue_instead_of_committing_it(): void {
		$root     = dirname( __DIR__ );
		$manifest = file( $root . '/release-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		self::assertIsArray( $manifest );
		self::assertContains( 'languages', $manifest );
		$ignore = file_get_contents( $root . '/.gitignore' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		self::assertIsString( $ignore );
		self::assertStringContainsString( "\n/languages/ran-booster.pot\n", $ignore );

		$builder  = file_get_contents( $root . '/scripts/build-release.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		$verifier = file_get_contents( $root . '/scripts/verify-release.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local release contract.
		self::assertIsString( $builder );
		self::assertIsString( $verifier );
		self::assertSame( 1, preg_match( '/^committed_entries=\((.*?)^\)/ms', $verifier, $committed ) );
		self::assertSame( 1, preg_match( '/^generated_entries=\((.*?)^\)/ms', $verifier, $generated ) );
		if ( ! isset( $committed[1], $generated[1] ) ) {
			self::fail( 'Could not read the shared release entry lists.' );
		}
		self::assertStringNotContainsString( "'languages'", $committed[1] );
		self::assertStringContainsString( "'languages'", $generated[1] );
		self::assertStringContainsString( 'source "$script_dir/verify-release.sh"', $builder );
		self::assertStringContainsString( 'make-pot.sh" --source "$stage_root" --output "$stage_root/languages/ran-booster.pot"', $builder );
		self::assertStringContainsString( 'make-pot.sh" --source "$pot_source" --output "$tmp_dir/expected.pot"', $verifier );
		self::assertStringContainsString( "'ran-booster/languages/ran-booster.pot'", $verifier );
		self::assertStringContainsString( 'git -c core.autocrlf=false -c core.eol=lf archive --format=tar "$commit" -- "${committed_entries[@]}"', $verifier );
		self::assertStringContainsString( 'archived translation template does not match one regenerated from the release ref.', $verifier );
	}
}
