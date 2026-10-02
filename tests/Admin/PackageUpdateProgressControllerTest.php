<?php

declare(strict_types=1);

namespace Tests\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackageUpdateProgressController;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use Tests\Deployment\AttemptRepositoryDatabase;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';

final class PackageUpdateProgressControllerTest extends TestCase {

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $repository;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$_POST = array();
		$GLOBALS['ran_booster_repository_admin_allowed']      = true;
		$GLOBALS['ran_booster_repository_admin_nonce_valid']  = true;
		$GLOBALS['ran_booster_repository_admin_capabilities'] = array();
		$this->database                                       = new AttemptRepositoryDatabase();
		$this->repository                                     = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-07-23 00:00:00 UTC' ),
			static fn ( int $length ): string => str_repeat( "\x01", $length )
		);
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_POST = array();
		unset(
			$GLOBALS['ran_booster_repository_admin_allowed'],
			$GLOBALS['ran_booster_repository_admin_nonce_valid'],
			$GLOBALS['ran_booster_repository_admin_capabilities']
		);
	}

	public function test_returns_only_the_matched_attempt_state(): void {
		$attempt                 = $this->queued_attempt();
		$_POST                   = $this->request_for( $attempt );
		$this->database->queries = array();

		$result = ( new PackageUpdateProgressController( $this->repository ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertSame(
			array(
				(string) $attempt->get_id() => array(
					'attempt_id' => $attempt->get_id(),
					'reference'  => $attempt->get_correlation_id(),
					'state'      => 'queued',
				),
			),
			$result['data']['items']
		);
		self::assertCount( 1, $this->database->queries );
	}

	public function test_mismatched_reference_does_not_disclose_the_attempt(): void {
		$attempt                                 = $this->queued_attempt();
		$_POST                                   = $this->request_for( $attempt );
		$_POST['attempts'][ $attempt->get_id() ] = str_repeat( 'f', 32 );

		$result = ( new PackageUpdateProgressController( $this->repository ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertSame( array(), $result['data']['items'] );
	}

	public function test_authorization_and_nonce_failures_perform_no_reads(): void {
		$attempt = $this->queued_attempt();
		$_POST   = $this->request_for( $attempt );

		foreach ( array( 'authorization', 'nonce', 'capability' ) as $failure ) {
			$this->database->queries                              = array();
			$GLOBALS['ran_booster_repository_admin_allowed']      = 'authorization' !== $failure;
			$GLOBALS['ran_booster_repository_admin_nonce_valid']  = 'nonce' !== $failure;
			$GLOBALS['ran_booster_repository_admin_capabilities'] = array(
				'manage_options' => 'authorization' !== $failure,
				'update_plugins' => 'capability' !== $failure,
			);

			$result = ( new PackageUpdateProgressController( $this->repository ) )->handle();

			self::assertFalse( $result['success'] );
			self::assertSame( 403, $result['status'] );
			self::assertSame( array(), $this->database->queries );
		}
	}

	public function test_malformed_and_oversized_requests_fail_before_storage(): void {
		foreach ( array(
			array(),
			array( 'not-an-id' => str_repeat( 'a', 32 ) ),
			array_fill_keys( range( 1, 21 ), str_repeat( 'a', 32 ) ),
		) as $attempts ) {
			$_POST                   = array(
				'package_type' => 'plugin',
				'attempts'     => $attempts,
			);
			$this->database->queries = array();

			$result = ( new PackageUpdateProgressController( $this->repository ) )->handle();

			self::assertFalse( $result['success'] );
			self::assertSame( 400, $result['status'] );
			self::assertSame( array(), $this->database->queries );
		}
	}

	public function test_storage_failure_returns_one_safe_error(): void {
		$attempt                    = $this->queued_attempt();
		$_POST                      = $this->request_for( $attempt );
		$this->database->fail_reads = true;

		$result = ( new PackageUpdateProgressController( $this->repository ) )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 503, $result['status'] );
		self::assertSame( 'Package update progress is temporarily unavailable.', $result['data']['message'] );
		self::assertStringNotContainsString( 'database', strtolower( $result['data']['message'] ) );
	}

	private function queued_attempt(): DeploymentAttempt {
		$request = new DeploymentRequest(
			'owner/example',
			null,
			false,
			'main',
			'example',
			null,
			DeploymentPolicy::MANUAL,
			7
		);

		return $this->repository->admit_manual_batch(
			array(
				array(
					'package_type'            => 'plugin',
					'provider'                => 'gh',
					'provider_repository_id'  => 'R_example',
					'requested_ref'           => 'main',
					'package_source'          => 'branch',
					'package_source_revision' => 1,
					'request'                 => $request,
				),
			)
		)['admitted'][0];
	}

	/** @return array{package_type: string, attempts: array<int, string>} */
	private function request_for( DeploymentAttempt $attempt ): array {
		return array(
			'package_type' => 'plugin',
			'attempts'     => array( $attempt->get_id() => $attempt->get_correlation_id() ),
		);
	}
}
