<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RAN\Admin\GitHubVisibility;
use RAN\Settings\GeneralPreferenceStore;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class GitHubVisibilityTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle signature.
	protected function setUp(): void {
		require __DIR__ . '/GitHubVisibilityWordPressFunctions.php';
		require __DIR__ . '/GitHubVisibilityConnection.php';
	}

	public function test_missing_local_admission_sources_fail_visible(): void {
		$visibility = new GitHubVisibility( new GeneralPreferenceStore(), connection: new GitHubVisibilityConnection() );

		self::assertSame(
			array(
				'hidden'   => false,
				'eligible' => false,
				'reason'   => 'provider_registry_unknown',
			),
			$visibility->projection()
		);
	}

	public function test_management_admission_locks_the_exact_github_provider_range_before_save_work(): void {
		$connection = new GitHubVisibilityConnection();
		$visibility = new GitHubVisibility( new GeneralPreferenceStore(), connection: $connection );
		$result     = $visibility->with_management_admission_lock( static fn (): string => 'saved' );

		self::assertSame( 'saved', $result );
		self::assertSame( array( "SELECT id FROM `wp_ran_booster_packages` WHERE provider = 'gh' FOR UPDATE" ), $connection->queries );
	}

	public function test_management_admission_refuses_unknown_inventory_before_save_work(): void {
		$connection            = new GitHubVisibilityConnection();
		$connection->fail_read = true;
		$visibility            = new GitHubVisibility( new GeneralPreferenceStore(), connection: $connection );
		$called                = false;

		$this->expectException( RuntimeException::class );
		try {
			$visibility->with_management_admission_lock(
				static function () use ( &$called ): void {
					$called = true;
				}
			);
		} finally {
			self::assertFalse( $called );
		}
	}
}
