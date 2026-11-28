<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Admin\GitHubSupplierNotice;
use RAN\RepositoryProvider\ProviderRegistry;

/** One notice per change of GitHub supplier, recorded only once a capable administrator has been shown it. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GitHubSupplierNoticeTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		require_once __DIR__ . '/GitHubSupplierNoticeWordPressFunctions.php';
		$GLOBALS['ran_booster_supplier_caps']    = array( 'manage_options', 'activate_plugins' );
		$GLOBALS['ran_booster_supplier_options'] = array();
	}

	public function test_the_bundled_module_needs_no_notice(): void {
		self::assertSame( '', $this->render( ProviderRegistry::GITHUB_BUNDLED ) );
		self::assertSame( array(), $GLOBALS['ran_booster_supplier_options'] );
	}

	public function test_a_new_supplier_is_named_once_and_recorded_without_autoload(): void {
		$output = $this->render( 'alpha', array( 'alpha' ) );
		self::assertStringContainsString( 'notice-info', $output );
		self::assertStringContainsString( '<p>GitHub is provided by Alpha Provider.</p>', $output );
		self::assertSame( 'alpha', $GLOBALS['ran_booster_supplier_options'][ GitHubSupplierNotice::OPTION ] );
		self::assertFalse( $GLOBALS['ran_booster_supplier_autoload'][ GitHubSupplierNotice::OPTION ] );
		self::assertSame( '', $this->render( 'alpha', array( 'alpha' ) ) );
	}

	public function test_the_return_to_the_bundled_module_is_announced_and_clears_the_record(): void {
		$GLOBALS['ran_booster_supplier_options'][ GitHubSupplierNotice::OPTION ] = 'alpha';
		self::assertStringContainsString( '<p>GitHub is provided by RAN Booster again.</p>', $this->render( ProviderRegistry::GITHUB_BUNDLED ) );
		self::assertArrayNotHasKey( GitHubSupplierNotice::OPTION, $GLOBALS['ran_booster_supplier_options'] );
	}

	public function test_a_conflict_warns_and_names_each_claimant(): void {
		$output = $this->render( ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT, array( 'alpha', 'beta' ) );
		self::assertStringContainsString( 'notice-warning', $output );
		self::assertStringContainsString( 'GitHub is unavailable because more than one plugin provides it: Alpha Provider, beta.', $output );
		self::assertSame( ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT, $GLOBALS['ran_booster_supplier_options'][ GitHubSupplierNotice::OPTION ] );
	}

	public function test_an_unidentified_failed_claim_says_so_without_a_name(): void {
		self::assertStringContainsString( 'GitHub is unavailable because the plugin that provides it could not load.', $this->render( ProviderRegistry::GITHUB_SUPERSEDE_FAILED ) );
	}

	public function test_a_named_failed_claim_escapes_the_plugin_name(): void {
		$GLOBALS['ran_booster_supplier_plugins'] = array( 'alpha/alpha.php' => array( 'Name' => 'Alpha <b>"Provider"</b>' ) );
		$output                                  = $this->render( ProviderRegistry::GITHUB_SUPERSEDE_FAILED, array( 'alpha' ) );
		self::assertStringContainsString( 'notice-warning', $output );
		self::assertStringContainsString( '<p>GitHub is unavailable because Alpha &lt;b&gt;&quot;Provider&quot;&lt;/b&gt; could not load.</p>', $output );
		self::assertSame( ProviderRegistry::GITHUB_SUPERSEDE_FAILED, $GLOBALS['ran_booster_supplier_options'][ GitHubSupplierNotice::OPTION ] );
	}

	public function test_a_conflict_with_one_identified_claimant_names_no_one(): void {
		self::assertStringContainsString( '<p>GitHub is unavailable because more than one plugin provides it.</p>', $this->render( ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT, array( 'alpha' ) ) );
	}

	public function test_a_failed_bundled_module_neither_announces_a_return_nor_clears_the_record(): void {
		$GLOBALS['ran_booster_supplier_options'][ GitHubSupplierNotice::OPTION ] = 'alpha';
		self::assertSame( '', $this->render( null ) );
		self::assertSame( 'alpha', $GLOBALS['ran_booster_supplier_options'][ GitHubSupplierNotice::OPTION ] );
	}

	public function test_nothing_is_shown_or_recorded_without_both_capabilities_or_outside_scope(): void {
		$GLOBALS['ran_booster_supplier_caps'] = array( 'manage_options' );
		$without_capability                   = $this->render( 'alpha', array( 'alpha' ) );
		self::assertSame( '', $without_capability );
		$GLOBALS['ran_booster_supplier_caps']   = array( 'manage_options', 'activate_plugins' );
		$GLOBALS['ran_booster_supplier_screen'] = 'dashboard';
		$outside_scope                          = $this->render( 'alpha', array( 'alpha' ) );
		self::assertSame( '', $outside_scope );
		self::assertSame( array(), $GLOBALS['ran_booster_supplier_options'] );
	}

	/** @param list<string> $claimants */
	private function render( ?string $supplier, array $claimants = array() ): string {
		$registry = new ProviderRegistry();
		( new \ReflectionProperty( ProviderRegistry::class, 'github_supplier' ) )->setValue( $registry, $supplier );
		( new \ReflectionProperty( ProviderRegistry::class, 'github_claims' ) )->setValue(
			$registry,
			array_map(
				static fn ( string $plugin ): array => array(
					'plugin'  => $plugin,
					'factory' => null,
				),
				$claimants
			)
		);
		ob_start();
		( new GitHubSupplierNotice( $registry ) )->render();
		return (string) ob_get_clean();
	}
}
