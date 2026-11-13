<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

require_once __DIR__ . '/ReleaseRecheckWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PastReleaseObservation;
use RAN\Deployment\DeploymentPolicy;
use RAN\PackageSource;
use RAN\WordPress\ReleaseRecheck;

final class ReleaseRecheckTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$GLOBALS['ran_booster_recheck_deleted'] = array();
		unset( $GLOBALS['ran_booster_recheck_failure'] );
	}

	/** @return iterable<string, array{PackageSource, DeploymentPolicy, DeploymentPolicy, bool}> */
	public static function policy_matrix(): iterable {
		foreach ( array( DeploymentPolicy::MANUAL, DeploymentPolicy::AUTOMATIC ) as $after ) {
			yield 'release disabled to ' . $after->value => array( PackageSource::RELEASE_ASSET, DeploymentPolicy::DISABLED, $after, true );
			yield 'release ' . $after->value . ' to disabled' => array( PackageSource::RELEASE_ASSET, $after, DeploymentPolicy::DISABLED, false );
			yield 'branch disabled to ' . $after->value => array( PackageSource::BRANCH, DeploymentPolicy::DISABLED, $after, false );
		}
		yield 'release manual to automatic' => array( PackageSource::RELEASE_ASSET, DeploymentPolicy::MANUAL, DeploymentPolicy::AUTOMATIC, false );
		yield 'release disabled unchanged' => array( PackageSource::RELEASE_ASSET, DeploymentPolicy::DISABLED, DeploymentPolicy::DISABLED, false );
	}

	#[DataProvider( 'policy_matrix' )]
	public function test_only_enabling_a_release_package_requests_a_check( PackageSource $source, DeploymentPolicy $before, DeploymentPolicy $after, bool $expected ): void {
		self::assertSame( $expected, ReleaseRecheck::would_enable( $source, $before, $after ) );
	}

	public function test_request_clears_only_the_package_type_update_cache(): void {
		ReleaseRecheck::request( 'plugin' );
		ReleaseRecheck::request( 'theme' );

		self::assertSame( array( 'update_plugins', 'update_themes' ), $GLOBALS['ran_booster_recheck_deleted'] );
	}

	public function test_a_cache_failure_never_escapes_the_policy_change(): void {
		$GLOBALS['ran_booster_recheck_failure'] = true;

		ReleaseRecheck::request( 'plugin' );

		self::assertSame( array(), $GLOBALS['ran_booster_recheck_deleted'] );
	}

	/** @return iterable<string, array{array<string, mixed>, array{version: string, at: string}|null}> */
	public static function observations(): iterable {
		$at = '2026-11-13 10:00:00';
		yield 'newer release' => array(
			array(
				'sort_at' => $at,
				'facts'   => '{"relationship":"newer","candidate_version":"1.2.0"}',
			),
			array(
				'version' => '1.2.0',
				'at'      => $at,
			),
		);
		yield 'same release' => array(
			array(
				'sort_at' => $at,
				'facts'   => '{"relationship":"same","candidate_version":"1.0.0"}',
			),
			null,
		);
		yield 'no version' => array(
			array(
				'sort_at' => $at,
				'facts'   => '{"relationship":"newer"}',
			),
			null,
		);
		yield 'unsafe version' => array(
			array(
				'sort_at' => $at,
				'facts'   => '{"relationship":"newer","candidate_version":"<b>1</b>"}',
			),
			null,
		);
		yield 'malformed facts' => array(
			array(
				'sort_at' => $at,
				'facts'   => 'not json',
			),
			null,
		);
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array{version: string, at: string}|null $expected
	 */
	#[DataProvider( 'observations' )]
	public function test_past_observation_reads_only_a_newer_release_from_activity_facts( array $record, ?array $expected ): void {
		self::assertSame( $expected, PastReleaseObservation::from_record( $record ) );
	}
}
