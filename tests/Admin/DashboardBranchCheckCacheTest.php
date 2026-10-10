<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackageAdminController;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\Dashboard;
use RAN\Package;
use RAN\PackageSource;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class DashboardBranchCheckCacheTest extends TestCase {
	/** @return list<array{bool}> */
	public static function cache_write_provider(): array {
		return array( array( false ), array( true ) );
	}

	#[DataProvider( 'cache_write_provider' )]
	public function test_cache_misses_and_failed_writes_require_fresh_verification( bool $write_failure ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve the test request before exercising the real nonce-checked route.
		$previous_get = $_GET;
		$GLOBALS['ran_booster_test_capabilities']['manage_options']    = true;
		$GLOBALS['ran_booster_dashboard_test_transients']              = array();
		$GLOBALS['ran_booster_dashboard_test_transient_write_failure'] = $write_failure;
		$package = $this->createStub( Package::class );
		$package->method( 'get_identifier' )->willReturn( 'example/example.php' );
		$package->method( 'get_source' )->willReturn( PackageSource::BRANCH );
		$package->method( 'get_source_revision' )->willReturn( 1 );
		$checks = 0;
		$lock   = $this->createStub( WordPressUpdaterLock::class );
		$lock->method( 'acquire' )->willReturnCallback(
			static function () use ( &$checks ): string {
				++$checks;
				throw new \RuntimeException( 'Controlled verifier lock failure.' );
			}
		);
		$evidence = $this->createStub( RepositoryBranchCheckEvidenceStore::class );
		$evidence->method( 'profile_fingerprint_for' )->willReturn( 'current-access' );
		$evidence->method( 'find' )->willReturn( null );
		$profiles = $this->createStub( PublicRepositoryLookupProfileStore::class );
		$profiles->method( 'get' )->willReturn( null );
		$presenter = ( new ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor();
		foreach ( array(
			'branch_check_lock'      => $lock,
			'branch_check_evidence'  => $evidence,
			'public_lookup_profiles' => $profiles,
		) as $name => $dependency ) {
			( new ReflectionProperty( ProviderSettingsPresenter::class, $name ) )->setValue( $presenter, $dependency );
		}
		$dashboard = ( new ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( Dashboard::class, 'provider_settings' ) )->setValue( $dashboard, $presenter );
		$_GET  = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( PackageAdminController::repository_branch_check_action( $package, 'plugin' ) ),
		);
		$check = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );

		try {
			self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
			self::assertSame( 1, $checks );
			self::assertCount( $write_failure ? 0 : 1, $GLOBALS['ran_booster_dashboard_test_transients'] );
			$GLOBALS['ran_booster_dashboard_test_transients'] = array();
			self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
			self::assertSame( 2, $checks, 'A cache miss must enter fresh verification even when writing its previous result failed.' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reconstruct the cache key from the fixture nonce already verified by the production route.
			$marker = 'ran_booster_branch_check_' . hash( 'sha256', \RAN\get_current_user_id() . "\0" . PackageAdminController::repository_branch_check_action( $package, 'plugin' ) . "\0" . $_GET['_ran_booster_repository_branch_nonce'] . "\0current-access" );
			$GLOBALS['ran_booster_dashboard_test_transients'][ $marker ] = 'verified';
			self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
			self::assertSame( 3, $checks, 'Cached verified without stored evidence must not suppress fresh verification.' );
		} finally {
			$_GET = $previous_get;
			unset( $GLOBALS['ran_booster_dashboard_test_transient_write_failure'], $GLOBALS['ran_booster_dashboard_test_transients'] );
		}
	}
}
