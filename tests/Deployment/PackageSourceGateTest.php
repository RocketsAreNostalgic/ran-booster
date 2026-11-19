<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentCheckFailure;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\PackageSourceGate;
use RAN\PackageSource;

require_once __DIR__ . '/../Settings/GeneralPreferenceStoreWordPressFunctions.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PackageSourceGateTest extends TestCase {

	/** @return iterable<string, array{string, string, bool}> */
	public static function sites(): iterable {
		yield 'local environment' => array( 'local', 'https://example.com', true );
		yield 'development environment' => array( 'development', 'https://example.com', true );
		yield 'localhost' => array( 'production', 'http://localhost:8080', true );
		yield 'IPv4 loopback' => array( 'production', 'http://127.0.0.1', true );
		yield 'IPv6 loopback' => array( 'production', 'http://[::1]/wp', true );
		yield '.localhost host' => array( 'production', 'https://site.localhost', true );
		yield '.test host' => array( 'production', 'https://site.test', true );
		yield '.local host' => array( 'production', 'https://Site.Local/', true );
		yield 'production' => array( 'production', 'https://example.com', false );
		yield 'staging' => array( 'staging', 'https://staging.example.com', false );
		yield 'loopback-looking name' => array( 'production', 'https://127.example.com', false );
		yield 'test subdomain' => array( 'production', 'https://test.example.com', false );
		yield 'local in the path only' => array( 'production', 'https://example.com/site.local', false );
	}

	#[DataProvider( 'sites' )]
	public function test_only_a_development_site_gates_nothing( string $environment, string $home, bool $development ): void {
		$this->site( $environment, $home );

		self::assertSame( $development, PackageSourceGate::is_development_site() );
		self::assertSame( ! $development, PackageSourceGate::pauses( PackageSource::BRANCH ) );
		self::assertSame( ! $development, PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'prerelease' ) );
		self::assertFalse( PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'stable' ), 'Stable releases are never paused.' );
	}

	public function test_the_saved_setting_allows_gated_sources_only_at_its_site_address(): void {
		$this->site( 'production', 'https://example.com/' );
		$GLOBALS['ran_booster_general_preference_raw'] = \RAN\Settings\maybe_serialize(
			array(
				'package_sources'         => true,
				'package_sources_address' => 'example.com',
			)
		);
		self::assertTrue( PackageSourceGate::allows() );
		self::assertFalse( PackageSourceGate::pauses( PackageSource::BRANCH ) );

		$GLOBALS['ran_booster_test_home_url'] = 'https://copy.example.com';
		unset( $GLOBALS['ran_booster_general_preference_cached_option'] );
		self::assertTrue( PackageSourceGate::pauses( PackageSource::BRANCH ), 'A copied database arrives paused.' );
		try {
			PackageSourceGate::assert_allowed( PackageSource::RELEASE_ASSET, 'prerelease' );
			self::fail( 'A paused Preview track must be refused.' );
		} catch ( DeploymentCheckFailure $failure ) {
			self::assertSame( DeploymentOutcome::CODE_SOURCE_PAUSED, $failure->outcome_code );
		}
	}

	public function test_a_pause_committed_after_admission_is_seen_by_the_mutation_recheck(): void {
		$this->site( 'production', 'https://example.com' );
		$GLOBALS['ran_booster_general_preference_raw'] = \RAN\Settings\maybe_serialize(
			array(
				'package_sources'         => true,
				'package_sources_address' => 'example.com',
			)
		);
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Controlled test-only database boundary.
		$GLOBALS['wpdb'] = new class() {
			public string $options    = 'wp_options';
			public string $last_error = '';
			public function prepare( string $query ): string {
				return $query;
			}
			public function get_var(): ?string {
				return $GLOBALS['ran_booster_general_preference_raw'];
			}
		};
		self::assertFalse( PackageSourceGate::pauses( PackageSource::BRANCH ), 'Admission reads the cached option.' );

		// Another request turns the setting off; this request's option cache still holds the old value.
		$GLOBALS['ran_booster_general_preference_raw'] = \RAN\Settings\maybe_serialize( array( 'package_sources' => false ) );
		self::assertFalse( PackageSourceGate::pauses( PackageSource::BRANCH ) );
		self::assertTrue( PackageSourceGate::pauses( PackageSource::BRANCH, fresh: true ) );
		self::assertTrue( PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'prerelease', true ) );

		unset( $GLOBALS['wpdb'] );
		self::assertTrue( PackageSourceGate::pauses( PackageSource::BRANCH, fresh: true ), 'A fence that cannot read the setting stays paused.' );
	}

	public function test_the_constant_allows_gated_sources_on_any_site(): void {
		define( 'RAN_BOOSTER_ALLOW_BRANCH_AND_PRERELEASE_SOURCES', true );
		$this->site( 'production', 'https://example.com' );

		self::assertFalse( PackageSourceGate::pauses( PackageSource::BRANCH ) );
		PackageSourceGate::assert_allowed( PackageSource::RELEASE_ASSET, 'prerelease' );
	}

	private function site( string $environment, string $home ): void {
		$GLOBALS['ran_booster_test_environment_type'] = $environment;
		$GLOBALS['ran_booster_test_home_url']         = $home;
	}
}
