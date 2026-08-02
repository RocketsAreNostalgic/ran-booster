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

	protected function tearDown(): void {
		$_POST = array();
		unset(
			$GLOBALS['ran_booster_repository_admin_allowed'],
			$GLOBALS['ran_booster_repository_admin_nonce_valid'],
			$GLOBALS['ran_booster_repository_admin_capabilities']
		);
	}

	public function testReturnsOnlyTheMatchedAttemptState(): void {
		$attempt                 = $this->queuedAttempt();
		$_POST                   = $this->requestFor( $attempt );
		$this->database->queries = array();

		$result = ( new PackageUpdateProgressController( $this->repository ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertSame(
			array(
				(string) $attempt->getId() => array(
					'attempt_id' => $attempt->getId(),
					'reference'  => $attempt->getCorrelationId(),
					'state'      => 'queued',
				),
			),
			$result['data']['items']
		);
		self::assertCount( 1, $this->database->queries );
	}

	public function testMismatchedReferenceDoesNotDiscloseTheAttempt(): void {
		$attempt                                = $this->queuedAttempt();
		$_POST                                  = $this->requestFor( $attempt );
		$_POST['attempts'][ $attempt->getId() ] = str_repeat( 'f', 32 );

		$result = ( new PackageUpdateProgressController( $this->repository ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertSame( array(), $result['data']['items'] );
	}

	public function testAuthorizationAndNonceFailuresPerformNoReads(): void {
		$attempt = $this->queuedAttempt();
		$_POST   = $this->requestFor( $attempt );

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

	public function testMalformedAndOversizedRequestsFailBeforeStorage(): void {
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

	public function testStorageFailureReturnsOneSafeError(): void {
		$attempt                   = $this->queuedAttempt();
		$_POST                     = $this->requestFor( $attempt );
		$this->database->failReads = true;

		$result = ( new PackageUpdateProgressController( $this->repository ) )->handle();

		self::assertFalse( $result['success'] );
		self::assertSame( 503, $result['status'] );
		self::assertSame( 'Package update progress is temporarily unavailable.', $result['data']['message'] );
		self::assertStringNotContainsString( 'database', strtolower( $result['data']['message'] ) );
	}

	private function queuedAttempt(): DeploymentAttempt {
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

		return $this->repository->admitManualBatch(
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
	private function requestFor( DeploymentAttempt $attempt ): array {
		return array(
			'package_type' => 'plugin',
			'attempts'     => array( $attempt->getId() => $attempt->getCorrelationId() ),
		);
	}
}
