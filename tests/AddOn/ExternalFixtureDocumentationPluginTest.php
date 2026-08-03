<?php

declare(strict_types=1);

namespace Tests\AddOn;

require_once __DIR__ . '/../Support/ExternalFixtureAddOnWordPressFunctions.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ExternalFixtureDocumentationPluginTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginRegistersOneStructuredProviderDocumentationFilter(): void {
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 14 );
		$this->loadFixturePlugin();
		$this->runHook( 'plugins_loaded' );

		$sections = $this->runFilter(
			'ran_booster_documentation_sections_after_provider_gh',
			array(),
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=documentation',
			'site'
		);

		self::assertCount( 1, $sections );
		self::assertSame( 'ran-booster-fixture-documentation', $sections[0]['id'] );
		self::assertSame( 'Fixture documentation', $sections[0]['summary'] );
		$html = $sections[0]['content'];

		self::assertSame(
			'<p data-ran-booster-fixture-documentation-url="https://example.test/wp-admin/admin.php?page=ran-booster&amp;tab=documentation" data-ran-booster-fixture-documentation-scope="site">Fixture documentation</p>',
			$html
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testPluginIsHarmlessWhenBoosterIsAbsentOrTheApiIsIncompatible(): void {
		$this->loadFixturePlugin();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_documentation_sections_after_provider_gh', $GLOBALS['ran_booster_external_fixture_addon_actions'] );

		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		define( 'RAN_BOOSTER_ADDON_API_VERSION', 13 );
		$this->loadFixturePlugin();
		$this->runHook( 'plugins_loaded' );
		self::assertArrayNotHasKey( 'ran_booster_documentation_sections_after_provider_gh', $GLOBALS['ran_booster_external_fixture_addon_actions'] );
	}

	private function loadFixturePlugin(): void {
		$GLOBALS['ran_booster_external_fixture_addon_actions'] = array();
		require dirname( __DIR__ ) . '/fixtures/ran-booster-fixture-documentation/ran-booster-fixture-documentation.php';
	}

	private function runHook( string $hook, mixed ...$arguments ): void {
		$callbacks = $GLOBALS['ran_booster_external_fixture_addon_actions'][ $hook ] ?? array();
		self::assertCount( 1, $callbacks, sprintf( 'The %s callback must be registered once.', $hook ) );
		$callbacks[0]( ...$arguments );
	}

	private function runFilter( string $hook, mixed $value, mixed ...$arguments ): mixed {
		$callbacks = $GLOBALS['ran_booster_external_fixture_addon_actions'][ $hook ] ?? array();
		self::assertCount( 1, $callbacks, sprintf( 'The %s callback must be registered once.', $hook ) );

		return $callbacks[0]( $value, ...$arguments );
	}
}
