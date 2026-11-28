<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/ProviderCredentialDispatcherWordPressFunctions.php';
require_once __DIR__ . '/DashboardRoutingWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackageAdminController;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Admin\PublicRepositoryLookupProfileStore;
use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\Tests\Deployment\AttemptRepositoryDatabase;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\Dashboard;
use RAN\AbstractPackage;
use RAN\PackageSource;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class DashboardBranchCheckCacheTest extends TestCase {
	/** @var array<string, mixed> */
	private array $previous_get;
	private mixed $previous_database;

	#[Before]
	protected function isolate_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve the test request before exercising the real nonce-checked route.
		$this->previous_get      = $_GET;
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The real updater lock reads its row through this isolated fake.
		$GLOBALS['wpdb'] = new AttemptRepositoryDatabase();
		$GLOBALS['ran_booster_test_capabilities']['manage_options'] = true;
		$GLOBALS['ran_booster_dashboard_test_transients']           = array();
		WordPressUpdaterLock::take_refusal();
	}

	#[After]
	protected function restore_request(): void {
		WordPressUpdaterLock::take_refusal();
		$_GET = $this->previous_get;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the database boundary replaced above.
		$GLOBALS['wpdb'] = $this->previous_database;
		unset( $GLOBALS['ran_booster_dashboard_test_transient_write_failure'], $GLOBALS['ran_booster_dashboard_test_transients'] );
	}

	/** @return list<array{bool, string}> */
	public static function cache_write_provider(): array {
		return array( array( false, 'branch_unavailable' ), array( false, 'branch_redirected' ), array( true, 'branch_unavailable' ), array( true, 'branch_redirected' ) );
	}

	#[DataProvider( 'cache_write_provider' )]
	public function test_cache_misses_and_failed_writes_require_fresh_verification( bool $write_failure, string $outcome ): void {
		$GLOBALS['ran_booster_dashboard_test_transient_write_failure'] = $write_failure;
		$checks = 0;
		$lock   = $this->createStub( WordPressUpdaterLock::class );
		$lock->method( 'acquire' )->willReturnCallback(
			static function () use ( &$checks ): string {
				++$checks;
				throw new \RuntimeException( 'Controlled verifier lock failure.' );
			}
		);
		list( $dashboard, $package ) = $this->branch_check_dashboard( $lock );
		$check                       = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );

		self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 1, $checks );
		self::assertCount( $write_failure ? 0 : 1, $GLOBALS['ran_booster_dashboard_test_transients'] );
		$GLOBALS['ran_booster_dashboard_test_transients'] = array();
		self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 2, $checks, 'A cache miss must enter fresh verification even when writing its previous result failed.' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reconstruct the cache key from the fixture nonce already verified by the production route.
		$marker = 'ran_booster_branch_check_' . hash( 'sha256', \RAN\get_current_user_id() . "\0" . PackageAdminController::repository_branch_check_action( $package, 'plugin' ) . "\0" . $_GET['_ran_booster_repository_branch_nonce'] . "\0" . $_GET['ran_booster_repository_branch_check'] . "\0current-access" );
		$GLOBALS['ran_booster_dashboard_test_transients'][ $marker ] = $outcome;
		self::assertSame( $outcome, $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 2, $checks, 'A cached failure must not trigger another verification.' );
		$GLOBALS['ran_booster_dashboard_test_transients'][ $marker ] = 'verified';
		self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( 3, $checks, 'Cached verified without stored evidence must not suppress fresh verification.' );
	}

	public function test_only_a_check_the_lock_refused_names_the_refusing_booster_owner(): void {
		$token = ( new WordPressUpdaterLock() )->acquire();
		$owner = new ReflectionProperty( Dashboard::class, 'updater_lock_owner' );
		$check = new ReflectionMethod( Dashboard::class, 'requested_package_repository_branch_check' );
		// A refusal left over from earlier in the request, then a check that never takes the lock: no one is named.
		self::assertNull( ( new WordPressUpdaterLock() )->try_acquire_without_reclaim() );
		list( $dashboard, $package ) = $this->branch_check_dashboard( new WordPressUpdaterLock(), PackageSource::RELEASE_ASSET );
		self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertNull( $owner->getValue( $dashboard ) );
		list( $dashboard, $package ) = $this->branch_check_dashboard( new WordPressUpdaterLock() );
		self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertSame( $token, $owner->getValue( $dashboard )['token'] ?? null );
		// The cached result on reload names no owner: nothing was refused in that request.
		$owner->setValue( $dashboard, null );
		self::assertSame( 'unable_to_check', $check->invoke( $dashboard, $package, 'plugin' ) );
		self::assertNull( $owner->getValue( $dashboard ) );
	}

	/** @return array{Dashboard, AbstractPackage} */
	private function branch_check_dashboard( WordPressUpdaterLock $lock, PackageSource $source = PackageSource::BRANCH ): array {
		$package = $this->createStub( AbstractPackage::class );
		$package->method( 'get_identifier' )->willReturn( 'example/example.php' );
		$package->method( 'get_adoption_token' )->willReturn( 'gh:repo-42:11111111-1111-4111-8111-111111111111' );
		$package->method( 'get_source' )->willReturn( $source );
		$package->method( 'get_source_revision' )->willReturn( 1 );
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
		$_GET = array(
			'ran_booster_repository_branch_check'  => '1',
			'_ran_booster_repository_branch_nonce' => \RAN\wp_create_nonce( PackageAdminController::repository_branch_check_action( $package, 'plugin' ) ),
		);

		return array( $dashboard, $package );
	}
}
