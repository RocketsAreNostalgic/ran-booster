<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub;

require_once __DIR__ . '/Support/RepositoryResolverWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\GitHubProvider;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RuntimeException;
use Tests\Booster\GitHub\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Tests\Booster\GitHub\Support\RepositoryResolverSecretsStub;

final class GitHubProviderWebhookManagementTest extends TestCase {

	private const SAVED_TOKEN = 'saved-token-canary';
	private const SECRET      = 'signing-secret-canary';

	/** @return iterable<string, array{string}> */
	public static function managementCredentialSources(): iterable {
		foreach ( array( 'setup', 'check', 'reconfigure', 'remove', 'test' ) as $operation ) {
			yield $operation . ' with saved credential' => array( $operation );
		}
	}

	#[DataProvider( 'managementCredentialSources' )]
	public function testManagementOperationUsesTheSelectedSavedCredential( string $operation ): void {
		$store    = new RepositoryResolverSecretsStub( array( 'saved-profile' => self::SAVED_TOKEN ) );
		$provider = $this->provider( $store );
		$token    = self::SAVED_TOKEN;
		$profile  = 'saved-profile';

		\RAN\Booster\GitHub\repository_resolver_http_queue( $this->responsesFor( $operation ) );

		$result   = $this->operate( $provider, $operation, $profile );
		$requests = \RAN\Booster\GitHub\repository_resolver_http_requests();
		$expected = $this->operationExpectation( $operation );

		self::assertTrue( $result->succeeded() );
		self::assertSame( $expected['code'], $result->code() );
		self::assertSame( $expected['methods'], array_column( array_column( $requests, 'arguments' ), 'method' ) );
		self::assertSame( array( 'saved-profile' ), $store->lookups );
		self::assertNotEmpty( $requests );
		foreach ( $requests as $remoteRequest ) {
			self::assertSame( 'Bearer ' . $token, $remoteRequest['arguments']['headers']['Authorization'] );
			self::assertSame( 0, $remoteRequest['arguments']['redirection'] );
			self::assertLessThanOrEqual( 262144, $remoteRequest['arguments']['limit_response_size'] );
		}
		self::assertLessThanOrEqual( 5, count( $requests ) );
		if ( isset( $expected['mutation'] ) ) {
			$payload = json_decode( $requests[ $expected['mutation'] ]['arguments']['body'], true, 32, JSON_THROW_ON_ERROR );
			self::assertSame( 'https://site.example/hook', $payload['config']['url'] ?? null );
			self::assertSame( self::SECRET, $payload['config']['secret'] ?? null );
			self::assertSame( array( 'push' ), $payload['events'] ?? null );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test-only secret-containment assertion.
		$serialized = json_encode( $result->toArray(), JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( self::SAVED_TOKEN, $serialized );
		self::assertStringNotContainsString( self::SECRET, $serialized );
	}

	/** @return iterable<string, array{string|null, string, int}> */
	public static function invalidCredentialSources(): iterable {
		yield 'no saved credential' => array( null, 'Choose a saved GitHub credential.', 0 );
		yield 'unavailable saved credential' => array( 'missing-profile', 'The selected GitHub credential is unavailable.', 1 );
	}

	#[DataProvider( 'invalidCredentialSources' )]
	public function testInvalidCredentialSourceFailsBeforeAnyRemoteRequest(
		?string $profile,
		string $message,
		int $lookups
	): void {
		$store    = new RepositoryResolverSecretsStub( array( 'saved-profile' => self::SAVED_TOKEN ) );
		$provider = $this->provider( $store );
		\RAN\Booster\GitHub\repository_resolver_http_queue( array() );

		try {
			$provider->check( '101', 'owner/example', '55', 'https://site.example/hook', $profile );
			self::fail( 'Invalid credential selection must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 400, $exception->getCode() );
			self::assertSame( $message, $exception->getMessage() );
		}

		self::assertCount( $lookups, $store->lookups );
		self::assertSame( array(), \RAN\Booster\GitHub\repository_resolver_http_requests() );
	}

	private function provider( RepositoryResolverSecretsStub $store ): GitHubProvider {
		$provider = GitHubProvider::create(
			$store,
			new EmptyAuthenticatedWebhookDeliveryEvidenceReader()
		);
		self::assertInstanceOf( GitHubProvider::class, $provider );

		return $provider;
	}

	private function operate( GitHubProvider $provider, string $operation, ?string $profile ): RepositoryWebhookOperationResult {
		return match ( $operation ) {
			'setup' => $provider->setup( '101', 'owner/example', 'https://site.example/hook', $profile, self::SECRET ),
			'check' => $provider->check( '101', 'owner/example', '55', 'https://site.example/hook', $profile ),
			'reconfigure' => $provider->reconfigure( '101', 'owner/example', '55', 'https://site.example/hook', $profile, self::SECRET ),
			'remove' => $provider->remove( '101', 'owner/example', '55', 'https://site.example/hook', $profile ),
			'test' => $provider->test( '101', 'owner/example', '55', 'https://site.example/hook', $profile ),
		};
	}

	/** @return list<array<string, mixed>> */
	private function responsesFor( string $operation ): array {
		$hook           = $this->hook();
		$pingDeliveries = array(
			array(
				'id'          => 56,
				'event'       => 'ping',
				'status_code' => 204,
			),
		);

		return match ( $operation ) {
			'setup' => array( $this->response( 200, array() ), $this->response( 201, $hook ), $this->response( 200, $hook ) ),
			'check' => array( $this->response( 200, $hook ) ),
			'reconfigure' => array( $this->response( 200, $hook ), $this->response( 200, $hook ), $this->response( 200, $hook ) ),
			'remove' => array( $this->response( 200, $hook ), $this->response( 204, array() ), $this->response( 404, array() ) ),
			'test' => array(
				$this->response( 200, $hook ),
				$this->response( 200, array() ),
				$this->response( 204, array() ),
				$this->response( 200, $pingDeliveries ),
			),
		};
	}

	/** @return array{code:string,methods:list<string>,mutation?:int} */
	private function operationExpectation( string $operation ): array {
		return match ( $operation ) {
			'setup' => array(
				'code'     => 'configured_pending_delivery',
				'methods'  => array( 'GET', 'POST', 'GET' ),
				'mutation' => 1,
			),
			'check' => array(
				'code'    => 'configuration_confirmed',
				'methods' => array( 'GET' ),
			),
			'reconfigure' => array(
				'code'     => 'configured_pending_delivery',
				'methods'  => array( 'GET', 'PATCH', 'GET' ),
				'mutation' => 1,
			),
			'remove' => array(
				'code'    => 'absence_confirmed',
				'methods' => array( 'GET', 'DELETE', 'GET' ),
			),
			'test' => array(
				'code'    => 'ping_requested',
				'methods' => array( 'GET', 'POST' ),
			),
		};
	}

	/** @return array<string, mixed> */
	private function hook(): array {
		return array(
			'id'     => 55,
			'active' => true,
			'events' => array( 'push' ),
			'config' => array(
				'url'          => 'https://site.example/hook',
				'content_type' => 'json',
			),
		);
	}

	/** @return array<string, mixed> */
	private function response( int $status, mixed $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => array(),
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test transport fixture.
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
		);
	}
}
