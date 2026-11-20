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

	/** @return iterable<string, array{?string, string, bool}> A null environment type is one not set explicitly. */
	public static function sites(): iterable {
		yield 'explicit local' => array( 'local', 'https://example.com', true );
		yield 'explicit development' => array( 'development', 'https://example.com', true );
		yield 'explicit production overrides a .test host' => array( 'production', 'https://site.test', false );
		yield 'explicit staging overrides localhost' => array( 'staging', 'http://localhost', false );
		yield 'an unknown explicit value reads as production' => array( 'qa', 'https://site.local', false );
		yield 'unset with a .test host' => array( null, 'https://site.test', true );
		yield 'unset with localhost' => array( null, 'http://localhost:8080', true );
		yield 'unset with IPv4 loopback' => array( null, 'http://127.0.0.1', true );
		yield 'unset with IPv6 loopback' => array( null, 'http://[::1]/wp', true );
		yield 'unset with a .localhost host' => array( null, 'https://site.localhost', true );
		yield 'unset with a .local host' => array( null, 'https://Site.Local/', true );
		yield 'unset with a public host' => array( null, 'https://example.com', false );
		yield 'unset with a loopback-looking name' => array( null, 'https://127.example.com', false );
		yield 'unset with a test subdomain' => array( null, 'https://test.example.com', false );
		yield 'unset with local in the path only' => array( null, 'https://example.com/site.local', false );
	}

	#[DataProvider( 'sites' )]
	public function test_only_a_development_site_gates_nothing( ?string $environment, string $home, bool $development ): void {
		$this->site( $environment, $home );

		self::assertSame( $development, PackageSourceGate::is_development_site() );
		self::assertSame( ! $development, PackageSourceGate::pauses( PackageSource::BRANCH ) );
		self::assertSame( ! $development, PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'prerelease' ) );
		self::assertFalse( PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'stable' ), 'Stable releases are never paused.' );
	}

	/** @return iterable<string, array{list<string>, bool, bool}> Saved overrides, then whether branch and Preview are paused. */
	public static function saved_overrides(): iterable {
		yield 'neither' => array( array(), true, true );
		yield 'branch only' => array( array( 'branch_sources' ), false, true );
		yield 'pre-release only' => array( array( 'prerelease_sources' ), true, false );
		yield 'both' => array( array( 'branch_sources', 'prerelease_sources' ), false, false );
	}

	/** @param list<string> $saved */
	#[DataProvider( 'saved_overrides' )]
	public function test_each_saved_override_allows_only_its_own_source_at_its_address( array $saved, bool $branch, bool $preview ): void {
		$this->site( 'production', 'https://example.com/' );
		$document = array();
		foreach ( $saved as $key ) {
			$document += array(
				$key              => true,
				$key . '_address' => 'example.com',
			);
		}
		$GLOBALS['ran_booster_general_preference_raw'] = \RAN\Settings\maybe_serialize( $document );
		self::assertSame( array( $branch, $preview ), array( PackageSourceGate::pauses( PackageSource::BRANCH ), PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'prerelease' ) ) );
		self::assertFalse( PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'stable' ) );

		$GLOBALS['ran_booster_test_home_url'] = 'https://copy.example.com';
		unset( $GLOBALS['ran_booster_general_preference_cached_option'] );
		self::assertSame( array( true, true ), array( PackageSourceGate::pauses( PackageSource::BRANCH ), PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'prerelease' ) ), 'A copied database arrives paused.' );
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
				'branch_sources'             => true,
				'branch_sources_address'     => 'example.com',
				'prerelease_sources'         => true,
				'prerelease_sources_address' => 'example.com',
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

		// Another request turns both off; this request's option cache still holds the old values.
		$GLOBALS['ran_booster_general_preference_raw'] = \RAN\Settings\maybe_serialize( array() );
		self::assertFalse( PackageSourceGate::pauses( PackageSource::BRANCH ) );
		self::assertTrue( PackageSourceGate::pauses( PackageSource::BRANCH, fresh: true ) );
		self::assertTrue( PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'prerelease', true ) );

		unset( $GLOBALS['wpdb'] );
		self::assertTrue( PackageSourceGate::pauses( PackageSource::BRANCH, fresh: true ), 'A fence that cannot read the setting stays paused.' );
	}

	/** @return iterable<string, array{string, bool}> The constant, then whether it allows branch sources rather than Preview. */
	public static function source_constants(): iterable {
		yield 'branch' => array( 'RAN_BOOSTER_ALLOW_BRANCH_SOURCES', true );
		yield 'pre-release' => array( 'RAN_BOOSTER_ALLOW_PRERELEASE_SOURCES', false );
	}

	#[DataProvider( 'source_constants' )]
	public function test_each_constant_allows_only_its_own_source_on_any_site( string $constant, bool $branch ): void {
		'RAN_BOOSTER_ALLOW_BRANCH_SOURCES' === $constant ? define( 'RAN_BOOSTER_ALLOW_BRANCH_SOURCES', true ) : define( 'RAN_BOOSTER_ALLOW_PRERELEASE_SOURCES', true );
		$this->site( 'production', 'https://example.com' );

		$paused = static fn ( bool $fresh ): array => array( PackageSourceGate::pauses( PackageSource::BRANCH, fresh: $fresh ), PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, 'prerelease', $fresh ) );
		self::assertSame( array( ! $branch, $branch ), $paused( false ) );
		unset( $GLOBALS['wpdb'] );
		self::assertSame( array( ! $branch, $branch ), $paused( true ), 'A fence reads its own constant before the database.' );
	}

	private function site( ?string $environment, string $home ): void {
		unset( $GLOBALS['ran_booster_test_environment_type'] );
		if ( null !== $environment ) {
			$GLOBALS['ran_booster_test_environment_type'] = $environment;
		}
		$GLOBALS['ran_booster_test_home_url'] = $home;
	}
}
