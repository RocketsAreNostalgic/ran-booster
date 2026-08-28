<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\RepositoryWebhookClient;
use RAN\Booster\GitHub\RepositoryResolverWpError;

final class RepositoryWebhookClientTest extends TestCase {

	private const TOKEN  = 'request-token-canary';
	private const SECRET = 'webhook-signing-canary-which-must-not-return';

	/** @return iterable<string, array{string}> */
	public static function fitnessActions(): iterable {
		yield 'setup' => array( 'assessSetup' );
		yield 'check' => array( 'assessCheck' );
		yield 'reconfigure' => array( 'assessReconfigure' );
		yield 'remove' => array( 'assessRemove' );
	}

	#[DataProvider( 'fitnessActions' )]
	public function testFitnessUsesOneBoundedReadAndReturnsNoCredential( string $method ): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array( $this->response( 200, array( 'id' => 101 ), array( 'x-oauth-scopes' => 'repo, admin:repo_hook' ) ) )
		);
		$result   = ( new RepositoryWebhookClient() )->{$method}( '101', 'owner/example', self::TOKEN );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();
		$evidence = $result->toArray();

		self::assertCount( 1, $requests );
		self::assertSame( 65536, $requests[0]['arguments']['limit_response_size'] );
		self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		self::assertSame( 'classic_scope_broad', $evidence['code'] );
		self::assertSame( 'observed', $evidence['evidence'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::TOKEN, json_encode( $evidence, JSON_THROW_ON_ERROR ) );
	}

	#[DataProvider( 'fitnessActions' )]
	public function testFitnessFailurePreservesTheRequestedAction( string $method ): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue( array( $this->response( 503, array() ) ) );

		$result = ( new RepositoryWebhookClient() )->{$method}( '101', 'owner/example', self::TOKEN );
		$action = strtolower( substr( $method, strlen( 'assess' ) ) );

		self::assertSame( $action . '_assessment_unavailable', $result->toArray()['code'] );
	}

	public function testSetupUsesAtMostFiveSuccessfulCallsAcrossThreePages(): void {
		$page = array();
		for ( $id = 1; $id <= 100; ++$id ) {
			$page[] = $this->hook( $id, 'https://other.example/' . $id );
		}
		$created = $this->hook( 999, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $page ),
				$this->response( 200, $page ),
				$this->response( 200, array( $this->hook( 301, 'https://other.example/301' ) ) ),
				$this->response( 201, $created ),
				$this->response( 200, $created ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'configured_pending_delivery', $result->code() );
		self::assertCount( 5, $requests );
		self::assertSame( array( 'GET', 'GET', 'GET', 'POST', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
		self::assertSame( array( 262144, 262144, 262144, 65536, 65536 ), array_column( array_column( $requests, 'arguments' ), 'limit_response_size' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::TOKEN, json_encode( $result->toArray(), JSON_THROW_ON_ERROR ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::SECRET, json_encode( $result->toArray(), JSON_THROW_ON_ERROR ) );
	}

	/** @return iterable<string, array{int}> */
	public static function deterministicCreateFailures(): iterable {
		yield 'bad request' => array( 400 );
		yield 'unauthorized' => array( 401 );
		yield 'forbidden' => array( 403 );
		yield 'repository absent' => array( 404 );
		yield 'validation rejected' => array( 422 );
	}

	#[DataProvider( 'deterministicCreateFailures' )]
	public function testDeterministicCreateFailureIsFailedWithoutAHookIdentity( int $status ): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				$this->response( $status, array( 'message' => 'request rejected' ) ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'setup_failed', $result->code() );
		self::assertNull( $result->hookId() );
		self::assertSame( array( 'GET', 'POST' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function testTransientCreateFailureIsAmbiguousWithoutAHookIdentity(): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				$this->response( 503, array( 'message' => 'temporarily unavailable' ) ),
			)
		);

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'setup_failed_ambiguous', $result->code() );
		self::assertNull( $result->hookId() );
		self::assertCount( 2, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	public function testLostCreateResponseIsAmbiguousWithoutAHookIdentity(): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				new RepositoryResolverWpError( 'http_request_failed' ),
			)
		);

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'setup_failed_ambiguous', $result->code() );
		self::assertNull( $result->hookId() );
		self::assertCount( 2, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	public function testFailedSetupReadbackIsCompensatedOnlyAfterConfirmedAbsence(): void {
		$created = $this->hook( 55, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				$this->response( 201, $created ),
				$this->response( 503, array( 'message' => 'readback unavailable' ) ),
				$this->response( 204, array() ),
				$this->response( 404, array() ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'setup_compensated', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertSame( 'absent', $result->toArray()['delivery'] );
		self::assertSame( array( 'GET', 'POST', 'GET', 'DELETE', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function testFailedSetupCompensationRemainsPartialWithTheKnownHookIdentity(): void {
		$created = $this->hook( 55, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, array() ),
				$this->response( 201, $created ),
				$this->response( 503, array( 'message' => 'readback unavailable' ) ),
				$this->response( 500, array( 'message' => 'delete failed' ) ),
				$this->response( 200, $created ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertSame( 'partial', $result->state() );
		self::assertSame( 'setup_compensation_incomplete', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertFalse( $result->confirmsAbsence() );
		self::assertSame( array( 'GET', 'POST', 'GET', 'DELETE', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function testThirdFullDiscoveryPageStopsBeforeMutation(): void {
		$page = array();
		for ( $id = 1; $id <= 100; ++$id ) {
			$page[] = $this->hook( $id, 'https://other.example/' . $id );
		}
		\RAN\Booster\GitHub\repository_resolver_http_queue( array( $this->response( 200, $page ), $this->response( 200, $page ), $this->response( 200, $page ) ) );

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'hook_inventory_incomplete', $result->code() );
		self::assertCount( 3, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	public function testSetupDoesNotAdoptAnExistingEndpointWithAnUnreadableSecret(): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue( array( $this->response( 200, array( $this->hook( 55, 'https://site.example/hook' ) ) ) ) );

		$result = ( new RepositoryWebhookClient() )->setup( 'owner/example', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'existing_hook_requires_reconfigure', $result->code() );
		self::assertNull( $result->hookId(), 'An unowned hook ID must not seed a later remove operation.' );
		self::assertCount( 1, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	public function testRemoveRequiresAbsenceReadbackWithinThreeCalls(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue( array( $this->response( 200, $hook ), $this->response( 204, array() ), $this->response( 404, array() ) ) );

		$result = ( new RepositoryWebhookClient() )->remove( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertTrue( $result->confirmsAbsence() );
		self::assertCount( 3, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	public function testReconfigureRefusesAHookOwnedByAnotherEndpoint(): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue( array( $this->response( 200, $this->hook( 55, 'https://other.example/hook' ) ) ) );

		$result = ( new RepositoryWebhookClient() )->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET );

		self::assertSame( 'failed', $result->state() );
		self::assertSame( 'hook_ownership_mismatch', $result->code() );
		self::assertCount( 1, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	public function testReconfigureReadbackFailureRemainsAmbiguousWithTheKnownHookIdentity(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, $hook ),
				$this->response( 503, array( 'message' => 'readback unavailable' ) ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'reconfigure_readback_unavailable', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertSame( array( 'GET', 'PATCH', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function testReconfigureSucceedsOnlyAfterConfirmedReadback(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, $hook ),
				$this->response( 200, $hook ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->reconfigure( 'owner/example', '55', 'https://site.example/hook', self::TOKEN, self::SECRET );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'configured_pending_delivery', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertSame( array( 'GET', 'PATCH', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
		self::assertSame( array( 65536, 65536, 65536 ), array_column( array_column( $requests, 'arguments' ), 'limit_response_size' ) );
		self::assertSame(
			array(
				'name'   => 'web',
				'active' => true,
				'events' => array( 'push' ),
				'config' => array(
					'url'          => 'https://site.example/hook',
					'content_type' => 'json',
					'insecure_ssl' => '0',
					'secret'       => self::SECRET,
				),
			),
			json_decode( $requests[1]['arguments']['body'], true, 32, JSON_THROW_ON_ERROR )
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::SECRET, json_encode( $result->toArray(), JSON_THROW_ON_ERROR ) );
	}

	public function testRemoveAbsenceReadbackFailureRemainsAmbiguousWithTheKnownHookIdentity(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 204, array() ),
				$this->response( 503, array( 'message' => 'readback unavailable' ) ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->remove( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'remove_readback_unavailable', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertSame( array( 'GET', 'DELETE', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
	}

	public function testCheckCannotConfirmAnIncompleteReadback(): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue( array( $this->response( 200, array( 'id' => 55 ) ) ) );

		$result = ( new RepositoryWebhookClient() )->check( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertSame( 'ambiguous', $result->state() );
		self::assertSame( 'hook_readback_invalid', $result->code() );
	}

	public function testCheckSucceedsWithOneBoundedReadOfTheExactHook(): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue( array( $this->response( 200, $this->hook( 55, 'https://site.example/hook' ) ) ) );

		$result   = ( new RepositoryWebhookClient() )->check( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'configuration_confirmed', $result->code() );
		self::assertSame( '55', $result->hookId() );
		self::assertCount( 1, $requests );
		self::assertSame( 'GET', $requests[0]['arguments']['method'] );
		self::assertSame( 65536, $requests[0]['arguments']['limit_response_size'] );
		self::assertSame( 0, $requests[0]['arguments']['redirection'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::TOKEN, json_encode( $result->toArray(), JSON_THROW_ON_ERROR ) );
	}

	public function testPingVerifiesOnlyANewSuccessfulPingDeliveryForTheExactHook(): void {
		$hook     = $this->hook( 55, 'https://site.example/hook' );
		$baseline = array(
			array(
				'id'          => 10,
				'event'       => 'push',
				'status_code' => 200,
			),
		);
		$delivery = array(
			array(
				'id'          => 11,
				'event'       => 'ping',
				'status_code' => 204,
			),
			$baseline[0],
		);
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, $baseline ),
				$this->response( 204, array() ),
				$this->response( 200, $delivery ),
			)
		);

		$result   = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'ping_verified', $result->code() );
		self::assertSame( 'verified', $result->toArray()['delivery'] );
		self::assertSame( array( 'GET', 'GET', 'POST', 'GET' ), array_column( array_column( $requests, 'arguments' ), 'method' ) );
		self::assertStringContainsString( '/hooks/55/pings', $requests[2]['url'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		self::assertStringNotContainsString( self::TOKEN, json_encode( $result->toArray(), JSON_THROW_ON_ERROR ) );
	}

	public function testPingAcceptanceWithoutANewDeliveryDoesNotClaimVerification(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, array() ),
				$this->response( 204, array() ),
				$this->response( 200, array() ),
				$this->response( 200, array() ),
				$this->response( 200, array() ),
				$this->response( 200, array() ),
			)
		);

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'ping_requested', $result->code() );
		self::assertSame( 'unknown', $result->toArray()['delivery'] );
	}

	public function testPingWaitsForANewPendingDeliveryToFinishWithoutAcceptingAnOlderPing(): void {
		$hook                       = $this->hook( 55, 'https://site.example/hook' );
		$oldPing                    = array(
			'id'          => 10,
			'event'       => 'ping',
			'status_code' => 200,
		);
		$newPending                 = array(
			'id'          => 11,
			'event'       => 'ping',
			'status_code' => null,
		);
		$newComplete                = $newPending;
		$newComplete['status_code'] = 204;
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, array( $oldPing ) ),
				$this->response( 204, array() ),
				$this->response( 200, array( $newPending, $oldPing ) ),
				$this->response( 200, array( $newComplete, $oldPing ) ),
			)
		);

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertSame( 'ping_verified', $result->code() );
		self::assertSame( 'verified', $result->toArray()['delivery'] );
	}

	public function testPingRecordsANewNonSuccessfulDeliveryAsUnverified(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, array() ),
				$this->response( 204, array() ),
				$this->response(
					200,
					array(
						array(
							'id'          => 11,
							'event'       => 'ping',
							'status_code' => 401,
						),
					)
				),
			)
		);

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertFalse( $result->succeeded() );
		self::assertSame( 'ping_delivery_failed', $result->code() );
		self::assertSame( 'unverified', $result->toArray()['delivery'] );
	}

	public function testPingDoesNotTreatARedirectedDeliveryAsVerified(): void {
		$hook = $this->hook( 55, 'https://site.example/hook' );
		\RAN\Booster\GitHub\repository_resolver_http_queue(
			array(
				$this->response( 200, $hook ),
				$this->response( 200, array() ),
				$this->response( 204, array() ),
				$this->response(
					200,
					array(
						array(
							'id'          => 11,
							'event'       => 'ping',
							'status_code' => 302,
						),
					)
				),
			)
		);

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertFalse( $result->succeeded() );
		self::assertSame( 'ping_delivery_failed', $result->code() );
		self::assertSame( 'unverified', $result->toArray()['delivery'] );
	}

	public function testPingRefusesAMismatchedRecordedHookBeforeAnyPingRequest(): void {
		\RAN\Booster\GitHub\repository_resolver_http_queue( array( $this->response( 200, $this->hook( 55, 'https://other.example/hook' ) ) ) );

		$result = ( new RepositoryWebhookClient() )->test( 'owner/example', '55', 'https://site.example/hook', self::TOKEN );

		self::assertSame( 'hook_ownership_mismatch', $result->code() );
		self::assertCount( 1, \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	/** @return array<string,mixed> */
	private function hook( int $id, string $url ): array {
		return array(
			'id'     => $id,
			'active' => true,
			'events' => array( 'push' ),
			'config' => array(
				'url'          => $url,
				'content_type' => 'json',
			),
		);
	}

	/** @param mixed $body @return array<string,mixed> */
	private function response( int $status, mixed $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test transport fixture.
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}
}
