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
use RAN\Deployment\DeploymentStorageFailure;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\Storage\Database;
use RAN\Tests\Support\QueuedSelectionFixture;

require_once __DIR__ . '/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/DeploymentWorkerPhpFunctions.php';
require_once __DIR__ . '/../Activity/ActivityWordPressFunctions.php';

final class SelectionQueuedWorkTest extends TestCase {
	use QueuedSelectionFixture;

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;
	private GitHubProviderSelection $selection;
	private int $entropy = 1;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle method.
	protected function setUp(): void {
		$this->database  = new AttemptRepositoryDatabase();
		$this->selection = $this->queued_selection( $this->database );
		$this->attempts  = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-10-15 12:00:00 UTC' ),
			fn ( int $length ): string => str_repeat( chr( $this->entropy++ ), $length ),
			$this->createStub( Database::class ),
			selection: $this->selection
		);
	}

	public function test_admission_captures_generation_and_canonical_request_rejects_bad_values(): void {
		$attempt = $this->queue( 'gh', 'first' );
		self::assertSame( 0, $attempt->get_request()->provider_selection_generation );
		self::assertSame( $attempt->get_request()->to_json(), DeploymentRequest::from_json( $attempt->get_request()->to_json() )->to_json() );
		foreach ( array( -1, null, '0', 0.0, true ) as $value ) {
			$data                                  = $attempt->get_request()->to_array();
			$data['provider_selection_generation'] = $value;
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact canonical storage bytes and malformed negative controls must bypass sanitization.
				DeploymentRequest::from_json( json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
				self::fail( 'Invalid generation was accepted.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertContains( $exception->getMessage(), array( 'The provider selection generation must be nonnegative.', 'The stored deployment request is invalid.' ) );
			}
		}
	}

	public function test_missing_generation_is_claimed_as_stale_without_rebinding(): void {
		$attempt = $this->queue( 'gh', 'first' );
		$data    = $attempt->get_request()->to_array();
		unset( $data['provider_selection_generation'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact canonical storage bytes and malformed negative controls must bypass sanitization.
		$this->database->rows[ $attempt->get_id() - 1 ]['request_json'] = json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		$claimed = $this->attempts->claim_next();
		self::assertNotNull( $claimed );
		self::assertNull( $claimed->get_request()->provider_selection_generation );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_SELECTION_CHANGED, $this->attempts->provider_refusal( $claimed ) );
		self::assertNull( $claimed->safe_data()['mutation_started_at'] );
	}

	public function test_changed_generation_becomes_a_claimed_failure_even_when_request_context_is_old(): void {
		$this->queue( 'gh', 'first' );
		$this->change_state( 'selection_generation', 1 );
		$claimed = $this->attempts->claim_next();
		self::assertNotNull( $claimed );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_SELECTION_CHANGED, $this->attempts->provider_refusal( $claimed ) );
		$result = $this->attempts->mark_mutation_started( $claimed->get_id() );
		self::assertInstanceOf( DeploymentOutcome::class, $result );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_SELECTION_CHANGED, $result->get_code() );
		self::assertNull( $this->attempts->find_exact( $claimed->get_id() )?->safe_data()['mutation_started_at'] );
	}

	public function test_paused_github_does_not_starve_bitbucket_and_can_resume(): void {
		$github                                  = $this->queue( 'gh', 'first' );
		$bitbucket                               = $this->queue( 'bb', 'second' );
		$this->database->selection->active_bytes = 'a:0:{}';
		self::assertSame( $bitbucket->get_id(), $this->attempts->claim_next()?->get_id() );
		self::assertSame( DeploymentState::QUEUED, $this->attempts->find_exact( $github->get_id() )?->get_state() );
		$this->database->selection->active_bytes = 'a:1:{i:0;s:9:"index.php";}';
		self::assertSame( $github->get_id(), $this->attempts->claim_next()?->get_id() );
	}

	public function test_invalid_selection_does_not_starve_unrelated_provider(): void {
		$this->queue( 'gh', 'first' );
		$bitbucket = $this->queue( 'bb', 'second' );
		$this->database->selection->selection->rows[0]->option_value = '{}';
		self::assertSame( $bitbucket->get_id(), $this->attempts->claim_next()?->get_id() );
	}

	public function test_native_uncertainty_pauses_only_its_affected_branch_target(): void {
		$affected = $this->queue( 'gh', 'first' );
		$other    = $this->queue( 'gh', 'second' );
		$this->change_state(
			'native_mutation',
			array(
				'selection_generation' => 0,
				'owner_tag'            => str_repeat( 'a', 32 ),
				'target_type'          => 'plugin',
				'installed_identity'   => 'first/first.php',
				'phase'                => 'started',
			)
		);
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE, $this->selection->deployment_refusal( 0, 'plugin', 'first/other.php' ) );
		self::assertSame( $other->get_id(), $this->attempts->claim_next()?->get_id() );
		self::assertSame( DeploymentState::QUEUED, $this->attempts->find_exact( $affected->get_id() )?->get_state() );
	}

	public function test_final_lock_loss_refuses_mutation_without_marking_attempt(): void {
		$this->queue( 'gh', 'first' );
		$claimed = $this->attempts->claim_next();
		self::assertNotNull( $claimed );
		$result = $this->attempts->mark_mutation_started( $claimed->get_id(), owns_lock: static fn (): bool => false );
		self::assertInstanceOf( DeploymentOutcome::class, $result );
		self::assertSame( DeploymentOutcome::CODE_LOCK_UNAVAILABLE, $result->get_code() );
		self::assertNull( $this->attempts->find_exact( $claimed->get_id() )?->safe_data()['mutation_started_at'] );
	}

	public function test_cross_connection_selection_is_not_transaction_authority(): void {
		$other    = new AttemptRepositoryDatabase();
		$attempts = new DeploymentAttemptRepository( $other, 'wp_attempts', database_lifecycle: $this->createStub( Database::class ), selection: $this->selection );
		$this->expectException( DeploymentStorageFailure::class );
		$attempts->admit_webhook_batch( 'gh', 'delivery', str_repeat( 'a', 64 ), array() );
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

	public function test_revocation_during_locked_adoption_read_refuses_mutation(): void {
		$this->queued_update();
		$claimed = $this->attempts->claim_next();
		self::assertNotNull( $claimed );
		$read                               = false;
		$this->database->on_management_read = function () use ( &$read ): void {
			$read                                    = true;
			$this->database->selection->active_bytes = 'a:0:{}';
		};
		$result                             = $this->attempts->mark_mutation_started( $claimed->get_id(), owns_lock: static fn (): bool => true );
		self::assertTrue( $read );
		self::assertInstanceOf( DeploymentOutcome::class, $result );
		self::assertSame( DeploymentOutcome::CODE_PROVIDER_UNAVAILABLE, $result->get_code() );
		self::assertNull( $this->attempts->find_exact( $claimed->get_id() )?->safe_data()['mutation_started_at'] );
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

	private function queue( string $provider, string $slug ): DeploymentAttempt {
		return $this->attempts->admit_webhook_batch(
			$provider,
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

	private function change_state( string $key, mixed $value ): void {
		$state = json_decode( $this->database->selection->selection->rows[0]->option_value, true, 8, JSON_THROW_ON_ERROR );
		self::assertIsArray( $state );
		$state[ $key ] = $value;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact canonical storage bytes and malformed negative controls must bypass sanitization.
		$this->database->selection->selection->rows[0]->option_value = json_encode( $state, JSON_THROW_ON_ERROR );
	}
}
