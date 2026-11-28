<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\Storage\Database;

require_once __DIR__ . '/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/DeploymentWorkerPhpFunctions.php';
require_once __DIR__ . '/../Activity/ActivityWordPressFunctions.php';

/** Claimed queued attempts that must finish before any package mutation. */
final class QueuedAttemptClaimTest extends TestCase {

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	private int $entropy = 1;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle method.
	protected function setUp(): void {
		$this->database = new AttemptRepositoryDatabase();
		$this->attempts = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-10-15 12:00:00 UTC' ),
			fn ( int $length ): string => str_repeat( chr( $this->entropy++ ), $length ),
			$this->createStub( Database::class )
		);
	}

	public function test_final_lock_loss_refuses_mutation_without_marking_attempt(): void {
		$this->queue( 'first' );
		$claimed = $this->attempts->claim_next();
		self::assertNotNull( $claimed );
		$result = $this->attempts->mark_mutation_started( $claimed->get_id(), owns_lock: static fn (): bool => false );
		self::assertInstanceOf( DeploymentOutcome::class, $result );
		self::assertSame( DeploymentOutcome::CODE_LOCK_UNAVAILABLE, $result->get_code() );
		self::assertNull( $this->attempts->find_exact( $claimed->get_id() )?->safe_data()['mutation_started_at'] );
	}

	public function test_stale_package_is_claimed_and_finishes_before_provider_access(): void {
		$GLOBALS['ran_booster_worker_doing_cron']      = true;
		$attempt                                       = $this->queued_update();
		$this->database->management['first/first.php'] = 'gh:42:22222222-2222-4222-8222-222222222222';
		$claimed                                       = $this->attempts->claim_next();
		self::assertNotNull( $claimed );
		self::assertSame( $attempt->get_id(), $claimed->get_id() );
		$plugins = $this->createMock( \RAN\Storage\PluginRepository::class );
		$plugin  = $this->createStub( \RAN\Plugin::class );
		$plugin->method( 'get_adoption_token' )->willReturn( $this->database->management['first/first.php'] );
		$plugins->method( 'booster_plugin_from_file' )->willReturn( $plugin );
		$provider = $this->createMock( \RAN\RepositoryProvider\RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new \RAN\RepositoryProvider\ProviderMetadata( \RAN\RepositoryProvider\ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createStub( \RAN\RepositoryProvider\ProviderDiagnostics::class ) );
		$provider->expects( self::never() )->method( 'prepare_archive' );
		$coordinator = new \RAN\Deployment\DeploymentCoordinator(
			$this->attempts,
			$plugins,
			$this->createStub( \RAN\Storage\ThemeRepository::class ),
			new \RAN\RepositoryProvider\ProviderRegistry( array( $provider ) ),
			new \RAN\Deployment\WordPressWorkerWakeup( $this->attempts ),
			'/unreached-maintenance',
			new \RAN\WordPress\WordPressUpdaterLock(),
			source_guard: new \RAN\Storage\RepositorySourceGuard( $this->database, $this->createStub( Database::class ) )
		);
		self::assertSame( DeploymentOutcome::CODE_DEPLOYMENT_SNAPSHOT_CHANGED, $coordinator->execute_claimed( $claimed )->get_code() );
		$finished = $this->attempts->find_exact( $claimed->get_id() );
		self::assertSame( DeploymentState::FAILED, $finished?->get_state() );
		self::assertNull( $finished->safe_data()['mutation_started_at'] );
	}

	private function queued_update(): DeploymentAttempt {
		$identifier                                = 'first/first.php';
		$token                                     = 'gh:42:11111111-1111-4111-8111-111111111111';
		$this->database->management[ $identifier ] = $token;
		$this->database->management_rows[ $identifier ] = array(
			'repository'             => 'owner/first',
			'branch'                 => 'main',
			'private'                => 0,
			'credential_id'          => null,
			'subdirectory'           => null,
			'deployment_policy'      => 'automatic',
			'source'                 => 'branch',
			'source_revision'        => 1,
			'provider'               => 'gh',
			'provider_repository_id' => '42',
		);
		return $this->attempts->admit_webhook_batch(
			'gh',
			'delivery-update',
			str_repeat( 'a', 64 ),
			array(
				array(
					'operation'               => 'update',
					'package_type'            => 'plugin',
					'provider_repository_id'  => '42',
					'requested_ref'           => 'main',
					'package_source'          => 'branch',
					'package_source_revision' => 1,
					'request'                 => new DeploymentRequest( 'owner/first', null, false, 'main', 'first', null, DeploymentPolicy::AUTOMATIC, null, installed_identifier: $identifier, adoption_token: $token ),
				),
			)
		)[0];
	}

	private function queue( string $slug ): DeploymentAttempt {
		return $this->attempts->admit_webhook_batch(
			'gh',
			'delivery-' . $slug,
			str_repeat( 'a', 64 ),
			array(
				array(
					'operation'               => 'install',
					'package_type'            => 'plugin',
					'provider_repository_id'  => '42',
					'requested_ref'           => 'main',
					'package_source'          => 'branch',
					'package_source_revision' => 0,
					'request'                 => new DeploymentRequest( 'owner/' . $slug, null, false, 'main', $slug, null, DeploymentPolicy::AUTOMATIC, null ),
				),
			)
		)[0];
	}
}
