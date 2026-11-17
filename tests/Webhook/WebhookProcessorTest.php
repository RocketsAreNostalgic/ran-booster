<?php

declare(strict_types=1);

namespace RAN\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\BoosterGitHubProvider\V1\WebhookNormalizer as GitHubWebhookNormalizer;
use RAN\BoosterGitHubProvider\V1\WebhookPolicy as GitHubWebhookPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Webhook\WebhookProcessor;
use RAN\Webhook\SignedWebhookVerifier;
use RuntimeException;
use RAN\Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use Throwable;

require_once __DIR__ . '/../Logging/LoggingWordPressFunctions.php';

final class WebhookProcessorTest extends TestCase {
	use \RAN\Tests\Support\QueuedSelectionFixture;

	public const WEBHOOK_SECRET = 'processor-test-webhook-secret-0001';

	public function test_unknown_and_unsupported_providers_fail_closed(): void {
		$request_calls = 0;
		$request       = static function () use ( &$request_calls ): array {
			++$request_calls;

			return array(
				'body'    => '{}',
				'headers' => array(),
			);
		};
		$processor     = $this->processor( new ProviderRegistry(), new WebhookProcessorCoordinator() );

		self::assertSame( 404, $processor->handle( 'bb', $request )->get_status() );

		$metadata_only = new class() implements RepositoryProvider {

			use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
			}
		};
		$processor     = $this->processor(
			new ProviderRegistry( array( $metadata_only ) ),
			new WebhookProcessorCoordinator()
		);

		self::assertSame( 503, $processor->handle( 'gh', $request )->get_status() );
		self::assertSame( 0, $request_calls );
	}

	public function test_lost_selection_refuses_before_request_or_signing_material_access(): void {
		$registry  = new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) );
		$db        = new \RAN\Tests\Deployment\AttemptRepositoryDatabase();
		$selection = $this->queued_selection( $db, $registry );
		$secrets   = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'webhook_materials' );
		$processor                   = new WebhookProcessor( $registry, new WebhookProcessorCoordinator(), new SignedWebhookVerifier( $secrets ), $selection );
		$db->selection->active_bytes = 'a:0:{}';
		$response                    = $processor->handle(
			'gh',
			static function (): array {
				self::fail( 'Paused ingress must not read the request.' );
			}
		);
		self::assertSame( 503, $response->get_status() );
	}

	public function test_request_callback_revocation_refuses_before_signing_material_access(): void {
		$registry  = new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) );
		$db        = new \RAN\Tests\Deployment\AttemptRepositoryDatabase();
		$selection = $this->queued_selection( $db, $registry );
		$secrets   = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'webhook_materials' );
		$processor = new WebhookProcessor( $registry, new WebhookProcessorCoordinator(), new SignedWebhookVerifier( $secrets ), $selection );
		$response  = $processor->handle(
			'gh',
			static function () use ( $db ): array {
				$db->selection->active_bytes = 'a:0:{}';
				return array(
					'body'    => '{}',
					'headers' => array(),
				);
			}
		);
		self::assertSame( 503, $response->get_status() );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'callback_failure_provider' )]
	public function test_callback_failure_reports_current_availability( Throwable $failure, bool $revoke, int $expected ): void {
		$registry  = new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) );
		$db        = new \RAN\Tests\Deployment\AttemptRepositoryDatabase();
		$selection = $this->queued_selection( $db, $registry );
		$secrets   = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'webhook_materials' );
		$spy       = new WebhookProcessorCoordinatorSpy();
		$processor = new WebhookProcessor( $registry, new WebhookProcessorCoordinator( null, $spy ), new SignedWebhookVerifier( $secrets ), $selection );
		$response  = $processor->handle(
			'gh',
			static function () use ( $db, $failure, $revoke ): array {
				if ( $revoke ) {
					$db->selection->active_bytes = 'a:0:{}';
				}
				throw $failure;
			}
		);
		self::assertSame( $expected, $response->get_status() );
		self::assertSame( 0, $spy->calls );
	}

	/** @return iterable<string,array{Throwable,bool,int}> */
	public static function callback_failure_provider(): iterable {
		foreach ( array( false, true ) as $revoke ) {
			$label = $revoke ? 'revoked' : 'available';
			yield $label . '-invalid-input' => array( new \InvalidArgumentException( 'test' ), $revoke, $revoke ? 503 : 400 );
			yield $label . '-unknown-provider' => array( \RAN\RepositoryProvider\UnknownProvider::for_code(), $revoke, $revoke ? 503 : 404 );
			yield $label . '-unsupported-capability' => array( \RAN\RepositoryProvider\UnsupportedProviderCapability::for_provider(), $revoke, $revoke ? 503 : 404 );
		}
	}

	public function test_authorization_callback_revocation_refuses_before_admission(): void {
		$db     = new \RAN\Tests\Deployment\AttemptRepositoryDatabase();
		$policy = $this->createMock( ProviderWebhookPolicy::class );
		$policy->method( 'get_provider' )->willReturn( ProviderCode::parse( 'gh' ) );
		$policy->method( 'get_retained_headers' )->willReturn( array( 'x-hub-signature-256' ) );
		$policy->method( 'get_signature_header' )->willReturn( 'X-Hub-Signature-256' );
		$policy->method( 'authorize_webhook' )->willReturnCallback(
			static function () use ( $db ): bool {
				$db->selection->active_bytes = 'a:0:{}';
				return true;
			}
		);
		$event     = new PushEvent( ProviderCode::parse( 'gh' ), 'owner/example', '4001', 'main', str_repeat( 'a', 40 ), 'delivery-one' );
		$registry  = new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( $event ), $policy ) ) );
		$selection = $this->queued_selection( $db, $registry );
		$secrets   = $this->createMock( SecretsFile::class );
		$secrets->expects( self::once() )->method( 'webhook_materials' )->willReturn(
			array(
				'profile' => array(
					'scope'  => 'owner',
					'target' => 'owner',
					'secret' => self::WEBHOOK_SECRET,
				),
			)
		);
		$spy       = new WebhookProcessorCoordinatorSpy();
		$processor = new WebhookProcessor( $registry, new WebhookProcessorCoordinator( null, $spy ), new SignedWebhookVerifier( $secrets ), $selection );
		$response  = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );
		self::assertSame( 503, $response->get_status() );
		self::assertSame( 0, $spy->calls );
	}

	public function test_provider_exceptions_never_reach_the_response(): void {
		$provider  = new WebhookProcessorProvider(
			static function (): WebhookEnvelope {
				throw new \RuntimeException( 'secret-canary body-canary token-canary' );
			}
		);
		$processor = $this->processor(
			new ProviderRegistry( array( $provider ) ),
			new WebhookProcessorCoordinator()
		);

		$response = $processor->handle( 'gh', $this->request( 'body-canary', $this->signed_headers( 'body-canary' ) ) );
		$data     = implode( ' ', array_map( 'strval', $response->get_data() ) );

		self::assertSame( 500, $response->get_status() );
		self::assertStringNotContainsString( 'secret-canary', $data );
		self::assertStringNotContainsString( 'body-canary', $data );
		self::assertStringNotContainsString( 'token-canary', $data );
	}

	public function test_provider_rejection_messages_are_mapped_at_the_trusted_edge(): void {
		$provider  = new WebhookProcessorProvider(
			static function (): WebhookEnvelope {
				throw new WebhookRejected( 403, 'secret-canary token-canary' );
			}
		);
		$processor = $this->processor(
			new ProviderRegistry( array( $provider ) ),
			new WebhookProcessorCoordinator()
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );
		$data     = implode( ' ', array_map( 'strval', $response->get_data() ) );

		self::assertSame( 401, $response->get_status() );
		self::assertSame( 'Webhook authentication failed.', $response->get_data()['message'] );
		self::assertStringNotContainsString( 'secret-canary', $data );
		self::assertStringNotContainsString( 'token-canary', $data );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'rejection_status_provider' )]
	public function test_provider_rejections_return_only_fixed_data( int $status, int $expected, string $message ): void {
		$body      = 'synthetic body for webhook';
		$profile   = 'synthetic-matched-profile';
		$failure   = 'synthetic-failure-alpha';
		$provider  = new WebhookProcessorProvider(
			static function ( WebhookRequest $request ) use ( $status, &$failure, $profile ): WebhookEnvelope {
				self::assertSame( $profile, $request->require_verification()->get_profiles()[0]['id'] );
				throw new WebhookRejected( $status, $failure );
			}
		);
		$processor = $this->processor(
			new ProviderRegistry( array( $provider ) ),
			new WebhookProcessorCoordinator(),
			array(
				$profile => array(
					'scope'        => 'owner',
					'target'       => 'synthetic-target',
					'authority_id' => '',
					'secret'       => self::WEBHOOK_SECRET,
				),
			)
		);
		$directory = sys_get_temp_dir() . '/ran-webhook-rejection-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated capture fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$now     = time();
		$capture = new \RAN\Logging\TemporaryDebugCapture(
			$directory . '/secrets.json',
			static function () use ( &$now ): int {
				// Every clock read advances enough that duplicate emissions cannot hide behind sampling.
				$now += 5;
				return $now;
			}
		);
		$capture->start();
		\RAN\Logging\BoosterLogger::configure_capture( $capture );
		try {
			self::assertTrue( \RAN\Logging\BoosterLogger::log( 'capture control' ) );
			foreach ( array( 'synthetic-failure-alpha', 'synthetic-failure-beta ' . self::WEBHOOK_SECRET . ' ' . hash( 'sha256', $body ) . ' https://fake:password@example.invalid/' ) as $failure ) {
				$capture->start();
				$now     += 5;
				$response = $processor->handle( 'gh', $this->request( $body, $this->signed_headers( $body ) ) );
				self::assertSame( $expected, $response->get_status() );
				self::assertSame( array( 'message' => $message ), $response->get_data() );
				self::assertSame( array( '[ran-booster] Webhook intake {"step":"webhook","event":"intake","reason":"rejected"}' ), array_column( $capture->snapshot()['entries'], 'line' ) );
			}
		} finally {
			\RAN\Logging\BoosterLogger::configure_capture( null );
			foreach ( array( '/ran-booster-debug.php', '/ran-booster-debug.php.lock' ) as $file ) {
				if ( is_file( $directory . $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated capture fixture.
					unlink( $directory . $file );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated capture fixture.
			rmdir( $directory );
		}
	}

	/** @return iterable<string, array{int, int, string}> */
	public static function rejection_status_provider(): iterable {
		yield 'bad request' => array( 400, 400, 'Invalid webhook request.' );
		yield 'authentication' => array( 401, 401, 'Webhook authentication failed.' );
		yield 'forbidden' => array( 403, 401, 'Webhook authentication failed.' );
		yield 'too large' => array( 413, 413, 'Webhook request is too large.' );
		yield 'unavailable' => array( 503, 401, 'Webhook authentication failed.' );
		yield 'other' => array( 418, 500, 'Webhook request rejected.' );
	}

	public function test_rejection_boundaries_keep_fixed_responses_and_safe_logs(): void {
		$body                        = 'synthetic-profile-text https://fake-user:fake-password@example.invalid/';
		$digest                      = hash( 'sha256', $body );
		$details                     = self::WEBHOOK_SECRET . ' ' . $body . ' ' . $digest;
		$registry                    = new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) );
		$processor                   = $this->processor(
			$registry,
			new WebhookProcessorCoordinator(),
			array(
				'synthetic-profile-text' => array(
					'scope'        => 'owner',
					'target'       => 'synthetic-profile-text',
					'authority_id' => '',
					'secret'       => self::WEBHOOK_SECRET,
				),
			)
		);
		$request                     = $this->request( $body, $this->signed_headers( $body ) );
		$db                          = new \RAN\Tests\Deployment\AttemptRepositoryDatabase();
		$paused_registry             = new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) );
		$selection                   = $this->queued_selection( $db, $paused_registry );
		$paused                      = new WebhookProcessor( $paused_registry, new WebhookProcessorCoordinator(), new SignedWebhookVerifier( $this->createStub( SecretsFile::class ) ), $selection );
		$db->selection->active_bytes = 'a:0:{}';
		$cases                       = array(
			'paused provider'   => array( static fn () => $paused->handle( 'gh', $request ), 503, 'Webhook processing is temporarily unavailable.' ),
			'unknown provider'  => array( static fn () => $processor->handle( 'bb', $request ), 404, 'Webhook provider not found.' ),
			'invalid input'     => array(
				static fn () => $processor->handle(
					'gh',
					static function () use ( $details ): array {
						throw new \InvalidArgumentException( $details );
					}
				),
				400,
				'Invalid webhook request.',
			),
			'provider failure'  => array(
				static fn () => $processor->handle(
					'gh',
					static function () use ( $details ): array {
						throw new RuntimeException( $details );
					}
				),
				500,
				'Webhook processing failed.',
			),
			'bad signature'     => array( fn () => $processor->handle( 'gh', $this->request( $body, array( 'X-Hub-Signature-256' => 'sha256=' . str_repeat( '0', 64 ) ) ) ), 401, 'Webhook authentication failed.' ),
			'oversized body'    => array( fn () => $processor->handle( 'gh', $this->request( $body . str_repeat( 'x', 262145 ), array() ) ), 413, 'Webhook request is too large.' ),
			'ambiguous headers' => array( fn () => $processor->handle( 'gh', $this->request( $body, array( 'X-GitHub-Event' => array( 'push', 'ping' ) ) ) ), 400, 'Invalid webhook request.' ),
		);
		foreach ( array( new WebhookRejected( 401, $details ), DeploymentStorageFailure::delivery_conflict() ) as $index => $failure ) {
			$revoked_db                   = new \RAN\Tests\Deployment\AttemptRepositoryDatabase();
			$revoked_registry             = new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) );
			$revoked_selection            = $this->queued_selection( $revoked_db, $revoked_registry );
			$revoked                      = new WebhookProcessor( $revoked_registry, new WebhookProcessorCoordinator(), new SignedWebhookVerifier( $this->createStub( SecretsFile::class ) ), $revoked_selection );
			$cases[ 'revoked ' . $index ] = array(
				static fn () => $revoked->handle(
					'gh',
					static function () use ( $revoked_db, $failure ): array {
					$revoked_db->selection->active_bytes = 'a:0:{}';
					throw $failure;
					}
				),
				503,
				'Webhook processing is temporarily unavailable.',
			);
		}
		$event                         = new PushEvent( ProviderCode::parse( 'gh' ), 'other/repository', 'other-id', 'main', str_repeat( 'a', 40 ), 'delivery-one' );
		$denied                        = $this->processor( new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( $event ) ) ) ), new WebhookProcessorCoordinator() );
		$cases['authorization denied'] = array( static fn () => $denied->handle( 'gh', $request ), 401, 'Webhook authentication failed.' );
		$events                        = array_fill( 0, 33, $event );
		$fanout                        = $this->processor( new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( ...$events ) ) ) ), new WebhookProcessorCoordinator() );
		$cases['excess fan-out']       = array( static fn () => $fanout->handle( 'gh', $request ), 400, 'Invalid webhook request.' );
		foreach ( array(
			array( DeploymentStorageFailure::delivery_conflict(), 409, 'Webhook delivery conflict.' ),
			array( DeploymentStorageFailure::unsupported_database(), 503, 'Webhook processing is temporarily unavailable.' ),
			array( DeploymentStorageFailure::capacity_exhausted(), 503, 'Webhook processing is temporarily unavailable.' ),
			array( DeploymentStorageFailure::unavailable(), 500, 'Webhook processing failed.' ),
			array( new RuntimeException( $details ), 500, 'Webhook processing failed.' ),
		) as $index => $failure_case ) {
			$event_processor              = $this->event_processor( new WebhookProcessorCoordinator( null, null, $failure_case[0] ) );
			$cases[ 'storage ' . $index ] = array( static fn () => $event_processor->handle( 'gh', $request ), $failure_case[1], $failure_case[2] );
		}
		$cases['signature_missing']   = array( fn () => $processor->handle( 'gh', $this->request( $body, array() ) ), 401, 'Webhook authentication failed.' );
		$cases['signature_malformed'] = array( fn () => $processor->handle( 'gh', $this->request( $body, array( 'X-Hub-Signature-256' => $details ) ) ), 401, 'Webhook authentication failed.' );
		foreach ( array(
			'profiles_missing' => array(),
			'secret_invalid'   => array(
				'private-profile' => array(
					'scope'        => 'owner',
					'target'       => 'owner',
					'authority_id' => '',
					'secret'       => 'short',
				),
			),
		) as $reason => $materials ) {
			$invalid          = $this->processor( new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) ), new WebhookProcessorCoordinator(), $materials );
			$cases[ $reason ] = array( static fn () => $invalid->handle( 'gh', $request ), 401, 'Webhook authentication failed.' );
		}

		foreach ( array( 'verification', 'normalization', 'admission' ) as $stage ) {
			$revoked_db = new \RAN\Tests\Deployment\AttemptRepositoryDatabase();
			$revoke     = static function () use ( $revoked_db ): void {
				$revoked_db->selection->active_bytes = 'a:0:{}';
				throw new WebhookRejected( 401, 'hostile private material', 'signature_mismatch' );
			};
			$policy     = $this->createMock( ProviderWebhookPolicy::class );
			$policy->method( 'get_provider' )->willReturn( ProviderCode::parse( 'gh' ) );
			$policy->method( 'get_retained_headers' )->willReturn( array( 'x-hub-signature-256' ) );
			$policy->method( 'get_signature_header' )->willReturn( 'X-Hub-Signature-256' );
			$policy->method( 'authorize_webhook' )->willReturnCallback(
				static function () use ( $stage, $revoke ): bool {
					if ( 'admission' === $stage ) {
						$revoke();
					}
					return true;
				}
			);
			$revoked_registry  = new ProviderRegistry(
				array(
					new WebhookProcessorProvider(
						static function () use ( $stage, $revoke, $event ): WebhookEnvelope {
							if ( 'normalization' === $stage ) {
								$revoke();
							}
							return WebhookEnvelope::events( $event );
						},
						$policy
					),
				)
			);
			$revoked_selection = $this->queued_selection( $revoked_db, $revoked_registry );
			$secrets           = $this->createMock( SecretsFile::class );
			$secrets->method( 'webhook_materials' )->willReturnCallback(
				static function () use ( $stage, $revoke ): array {
					if ( 'verification' === $stage ) {
						$revoke();
					}
					return array(
						'profile' => array(
							'scope'  => 'owner',
							'target' => 'owner',
							'secret' => self::WEBHOOK_SECRET,
						),
					);
				}
			);
			$spy                                 = new WebhookProcessorCoordinatorSpy();
			$revoked                             = new WebhookProcessor( $revoked_registry, new WebhookProcessorCoordinator( null, $spy ), new SignedWebhookVerifier( $secrets ), $revoked_selection );
			$cases[ 'revoked during ' . $stage ] = array(
				static function () use ( $revoked, $request, $spy ) {
									$response = $revoked->handle( 'gh', $request );
									self::assertSame( 0, $spy->calls );
									return $response;
				},
				503,
				'Webhook processing is temporarily unavailable.',
			);
		}

		foreach ( array_merge( array( null, 'unknown', "ignored_ref\n" ), WebhookRejected::REASONS ) as $reason ) {
			$ignored                                        = $this->processor( new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::ignored( $reason ) ) ) ), new WebhookProcessorCoordinator() );
			$cases[ 'ignored:' . ( $reason ?? 'legacy' ) ]  = array( static fn () => $ignored->handle( 'gh', $request ), 202, 'Webhook event ignored.' );
			$rejected                                       = $this->processor(
				new ProviderRegistry(
					array(
						new WebhookProcessorProvider(
							static function () use ( $reason, $details ): WebhookEnvelope {
										throw new WebhookRejected( 400, $details, $reason );
							}
						),
					)
				),
				new WebhookProcessorCoordinator()
			);
			$cases[ 'rejected:' . ( $reason ?? 'legacy' ) ] = array( static fn () => $rejected->handle( 'gh', $request ), 400, 'Invalid webhook request.' );
		}
		$cases['verified probe'] = array( static fn () => $processor->handle( 'gh', $request ), 200, 'Webhook verified.' );

		$directory = sys_get_temp_dir() . '/ran-webhook-boundaries-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated capture fixture.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$now     = time();
		$capture = new \RAN\Logging\TemporaryDebugCapture(
			$directory . '/secrets.json',
			static function () use ( &$now ): int {
				// Every clock read advances enough that duplicate emissions cannot hide behind sampling.
				$now += 5;
				return $now;
			}
		);
		$capture->start();
		$previous_error_log = ini_get( 'error_log' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Route direct PHP logs to the isolated fixture and restore the setting.
		self::assertNotFalse( ini_set( 'error_log', $directory . '/php.log' ) );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Positive control for the isolated direct PHP log.
		error_log( 'synthetic-direct-log-control' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the isolated direct PHP log.
		$direct_baseline = file_get_contents( $directory . '/php.log' );
		self::assertIsString( $direct_baseline );
		self::assertStringContainsString( 'synthetic-direct-log-control', $direct_baseline );
		\RAN\Logging\BoosterLogger::configure_capture( $capture );
		try {
			self::assertTrue( \RAN\Logging\BoosterLogger::log( 'capture control' ) );
			self::assertTrue( \RAN\Logging\BoosterLogger::capture_webhook( 'rejected' ) );
			self::assertTrue( \RAN\Logging\BoosterLogger::capture_webhook( 'rejected' ) );
			self::assertCount( 3, $capture->snapshot()['entries'] );
			foreach ( $cases as $label => [ $invoke, $status, $message ] ) {
				$capture->start();
				self::assertTrue( \RAN\Logging\BoosterLogger::log( 'capture control' ) );
				$now     += 5;
				$response = $invoke();
				self::assertSame( $status, $response->get_status(), $label );
				self::assertSame( array( 'message' => $message ), $response->get_data(), $label );
				$reason = array(
					'bad signature'        => 'signature_mismatch',
					'oversized body'       => 'request_too_large',
					'authorization denied' => 'scope_mismatch',
					'excess fan-out'       => 'rejected',
					'verified probe'       => 'probe_verified',
				)[ $label ] ?? null;
				if ( in_array( $label, WebhookRejected::REASONS, true ) ) {
					$reason = $label;
				}
				foreach ( array( 'ignored:', 'rejected:' ) as $prefix ) {
					if ( str_starts_with( $label, $prefix ) ) {
						$carried = substr( $label, strlen( $prefix ) );
						$reason  = in_array( $carried, WebhookRejected::REASONS, true ) ? $carried : rtrim( $prefix, ':' );
					}
				}
				$lines = array( '[ran-booster] capture control []' );
				if ( null !== $reason ) {
					$lines[] = '[ran-booster] Webhook intake {"step":"webhook","event":"intake","reason":"' . $reason . '"}';
				}
				self::assertSame( $lines, array_column( $capture->snapshot()['entries'], 'line' ), $label );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the isolated direct PHP log.
				self::assertSame( $direct_baseline, file_get_contents( $directory . '/php.log' ), $label );
			}
		} finally {
			if ( is_string( $previous_error_log ) ) {
				// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the original PHP log destination after the fixture.
				ini_set( 'error_log', $previous_error_log );
			}
			\RAN\Logging\BoosterLogger::configure_capture( null );
			foreach ( array( '/ran-booster-debug.php', '/ran-booster-debug.php.lock', '/php.log' ) as $file ) {
				if ( is_file( $directory . $file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated capture fixture.
					unlink( $directory . $file );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated capture fixture.
			rmdir( $directory );
		}
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_capture_storage_warnings_cannot_escape_into_the_webhook_response_or_php_log(): void {
		define( 'WP_DEBUG_LOG', true );
		$directory = sys_get_temp_dir() . '/ran-webhook-warning-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Private capture fixture owned by this subprocess.
		self::assertTrue( mkdir( $directory, 0700 ) );
		$capture = new \RAN\Logging\TemporaryDebugCapture( $directory . '/secrets.json' );
		$capture->start();
		$lock = $directory . '/ran-booster-debug.php.lock';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Replace only the fixture lock with a real warning-producing storage failure.
		self::assertTrue( unlink( $lock ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- fopen cannot open a directory for writing.
		self::assertTrue( mkdir( $lock, 0700 ) );
		$settings = array();
		foreach ( array(
			'error_log'      => $directory . '/php.log',
			'log_errors'     => '1',
			'display_errors' => '1',
			'html_errors'    => '0',
		) as $key => $value ) {
			$settings[ $key ] = ini_get( $key );
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Separate subprocess owns and restores its diagnostic settings.
			self::assertNotFalse( ini_set( $key, $value ) );
		}
		$warnings = 0;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Enable real native warning disclosure in the isolated subprocess as the negative control.
		$reporting = error_reporting( E_ALL );
		$previous  = static function () use ( &$warnings ): bool {
			++$warnings;
			return false;
		};
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Forward native warnings to PHP and test restoration of the prior handler.
		set_error_handler( $previous, E_WARNING );
		\RAN\Logging\BoosterLogger::configure_capture( $capture );
		try {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Positive direct-log control before testing byte-for-byte silence.
			error_log( 'synthetic-warning-log-control' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only the private direct PHP log.
			$baseline = file_get_contents( $directory . '/php.log' );
			self::assertIsString( $baseline );
			self::assertStringContainsString( 'synthetic-warning-log-control', $baseline );
			self::assertFalse( \RAN\Logging\BoosterLogger::capture_webhook( 'signature_missing' ) );
			$processor = $this->processor(
				new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) ),
				new WebhookProcessorCoordinator()
			);
			ob_start();
			try {
				$response = $processor->handle( 'gh', $this->request( '{}', array() ) );
			} finally {
				$output = ob_get_clean();
			}
			self::assertSame( 401, $response->get_status() );
			self::assertSame( array( 'message' => 'Webhook authentication failed.' ), $response->get_data() );
			self::assertSame( '', $output );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native storage warnings must leave the direct log bytes unchanged.
			self::assertSame( $baseline, file_get_contents( $directory . '/php.log' ) );
			self::assertSame( 0, $warnings );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Inspect the restored handler without changing the handler stack depth.
			self::assertSame( $previous, set_error_handler( $previous, E_WARNING ) );
			restore_error_handler();
			ob_start();
			try {
				self::assertTrue( \RAN\Logging\BoosterLogger::log( 'ordinary storage warning control' ) );
			} finally {
				$ordinary_output = ob_get_clean();
			}
			self::assertSame( 1, $warnings );
			self::assertIsString( $ordinary_output );
			self::assertStringContainsString( 'fopen(', $ordinary_output );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The identical ordinary storage failure must still reach native PHP logging.
			$ordinary_log = file_get_contents( $directory . '/php.log' );
			self::assertIsString( $ordinary_log );
			self::assertStringContainsString( $lock, $ordinary_log );
			self::assertStringContainsString( 'ordinary storage warning control', $ordinary_log );
		} finally {
			restore_error_handler();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Restore the subprocess's original reporting mask.
			error_reporting( $reporting );
			\RAN\Logging\BoosterLogger::configure_capture( null );
			foreach ( $settings as $key => $value ) {
				if ( is_string( $value ) ) {
					// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restore the subprocess diagnostic settings.
					ini_set( $key, $value );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the owned failing lock directory.
			rmdir( $lock );
			foreach ( array( '/ran-booster-debug.php', '/php.log' ) as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this subprocess's capture and direct log fixtures.
				unlink( $directory . $file );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- All fixture files have been removed.
			rmdir( $directory );
		}
	}

	public function test_probe_and_ignored_envelopes_do_not_invoke_the_intake(): void {
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );

		$probe = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::probe() ) ) ),
			$coordinator
		);
		self::assertSame( 200, $probe->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) )->get_status() );

		$ignored = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::ignored() ) ) ),
			$coordinator
		);
		self::assertSame( 202, $ignored->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) )->get_status() );
		self::assertSame( 0, $spy->calls );
	}

	public function test_accepted_admission_returns_only_the_safe_receipt(): void {
		$correlation_id = str_repeat( 'a', 32 );
		$body           = '{"secret-canary":"raw-body-canary"}';
		$processor      = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'accepted', $correlation_id, 1, 'scheduled' ) )
		);

		$response = $processor->handle( 'gh', $this->request( $body, $this->signed_headers( $body ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame(
			array(
				'message'          => 'Webhook accepted.',
				'status'           => 'accepted',
				'correlation_id'   => $correlation_id,
				'accepted_targets' => 1,
				'runner_status'    => 'scheduled',
			),
			$response->get_data()
		);
		$rendered = implode( ' ', array_map( 'strval', $response->get_data() ) );
		self::assertStringNotContainsString( 'secret-canary', $rendered );
		self::assertStringNotContainsString( 'raw-body-canary', $rendered );
		self::assertStringNotContainsString( self::WEBHOOK_SECRET, $rendered );
	}

	public function test_exact_duplicate_admission_returns202_without_replaying_deployment(): void {
		$correlation_id = str_repeat( 'b', 32 );
		$processor      = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'duplicate', $correlation_id, 1, 'already_scheduled' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 'duplicate', $response->get_data()['status'] );
		self::assertSame( $correlation_id, $response->get_data()['correlation_id'] );
		self::assertSame( 'already_scheduled', $response->get_data()['runner_status'] );
	}

	public function test_conflicting_delivery_returns409(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( null, null, DeploymentStorageFailure::delivery_conflict() )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'Webhook delivery conflict.', $response->get_data()['message'] );
		self::assertArrayNotHasKey( 'status', $response->get_data() );
	}

	public function test_unsupported_database_returns_retry_safe_unavailable_response(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( null, null, DeploymentStorageFailure::unsupported_database() )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 503, $response->get_status() );
		self::assertSame( 'Webhook processing is temporarily unavailable.', $response->get_data()['message'] );
		self::assertArrayNotHasKey( 'status', $response->get_data() );
	}

	public function test_exhausted_attempt_capacity_returns_retry_safe_unavailable_response(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( null, null, DeploymentStorageFailure::capacity_exhausted() )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 503, $response->get_status() );
		self::assertSame( 'Webhook processing is temporarily unavailable.', $response->get_data()['message'] );
		self::assertArrayNotHasKey( 'status', $response->get_data() );
	}

	public function test_processor_reauthorizes_every_normalized_event_before_intake(): void {
		$event     = new PushEvent( ProviderCode::parse( 'gh' ), 'other/repository', 'other-id', 'main', str_repeat( 'a', 40 ), 'delivery-one' );
		$spy       = new WebhookProcessorCoordinatorSpy();
		$processor = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( $event ) ) ) ),
			new WebhookProcessorCoordinator( null, $spy ),
			array(
				'allowed-profile' => array(
					'scope'        => 'repository',
					'target'       => 'owner/repository',
					'authority_id' => 'allowed-id',
					'secret'       => self::WEBHOOK_SECRET,
				),
			)
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 401, $response->get_status() );
		self::assertSame( 0, $spy->calls );
	}

	public function test_unavailable_runner_does_not_reject_an_already_accepted_delivery(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'accepted', str_repeat( 'd', 32 ), 1, 'unavailable' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 'accepted', $response->get_data()['status'] );
		self::assertSame( 'unavailable', $response->get_data()['runner_status'] );
	}

	public function test_no_targets_are_accepted_without_a_worker_wakeup(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'accepted', str_repeat( 'e', 32 ), 0, 'not_required' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 0, $response->get_data()['accepted_targets'] );
		self::assertSame( 'not_required', $response->get_data()['runner_status'] );
	}

	public function test_zero_target_replay_returns202_without_a_worker_wakeup(): void {
		$correlation_id = str_repeat( 'f', 32 );
		$processor      = $this->event_processor(
			new WebhookProcessorCoordinator( self::admission_result( 'duplicate', $correlation_id, 0, 'not_required' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 'duplicate', $response->get_data()['status'] );
		self::assertSame( $correlation_id, $response->get_data()['correlation_id'] );
		self::assertSame( 0, $response->get_data()['accepted_targets'] );
		self::assertSame( 'not_required', $response->get_data()['runner_status'] );
	}

	public function test_database_throwable_maps_to_generic500_without_leaking_details(): void {
		$processor = $this->event_processor(
			new WebhookProcessorCoordinator( null, null, new RuntimeException( 'db-canary secret-canary raw-body-canary' ) )
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );
		$rendered = implode( ' ', array_map( 'strval', $response->get_data() ) );

		self::assertSame( 500, $response->get_status() );
		self::assertSame( 'Webhook processing failed.', $response->get_data()['message'] );
		self::assertStringNotContainsString( 'db-canary', $rendered );
		self::assertStringNotContainsString( 'secret-canary', $rendered );
		self::assertStringNotContainsString( 'raw-body-canary', $rendered );
	}

	public function test_ambiguous_retained_headers_are_rejected_safely(): void {
		$processor = $this->processor(
			new ProviderRegistry( array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::ignored() ) ) ),
			new WebhookProcessorCoordinator()
		);

		$response = $processor->handle(
			'gh',
			$this->request( '{}', array( 'X-GitHub-Event' => array( 'push', 'ping' ) ) )
		);

		self::assertSame( 400, $response->get_status() );
	}

	public function test_false_signature_stops_before_normalization_and_intake(): void {
		$normalizer_calls = 0;
		$spy              = new WebhookProcessorCoordinatorSpy();
		$materials        = array();
		foreach ( range( 1, 16 ) as $index ) {
			$materials[ 'profile-' . $index ] = array(
				'scope'        => 'owner',
				'target'       => 'owner',
				'authority_id' => '',
				'secret'       => sprintf( 'processor-test-webhook-secret-%04d', $index ),
			);
		}
		$processor = $this->processor(
			new ProviderRegistry(
				array(
					new WebhookProcessorProvider(
						static function () use ( &$normalizer_calls ): WebhookEnvelope {
							++$normalizer_calls;

							return WebhookEnvelope::ignored();
						}
					),
				)
			),
			new WebhookProcessorCoordinator( null, $spy ),
			$materials
		);

		$response = $processor->handle(
			'gh',
			$this->request( '{}', array( 'X-Hub-Signature-256' => 'sha256=' . str_repeat( '0', 64 ) ) )
		);

		self::assertSame( 401, $response->get_status() );
		self::assertSame( 0, $normalizer_calls );
		self::assertSame( 0, $spy->calls );
	}

	public function test_oversized_git_hub_request_does_not_load_secrets_or_invoke_intake(): void {
		$secrets     = new class() extends SecretsFile {
			public int $calls = 0;

			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_materials retains the production method contract; these inputs do not affect this controlled result.
			public function webhook_materials( ProviderCode|string $provider ): array {
				++$this->calls;

				return array();
			}
		};
		$normalizer  = new GitHubWebhookNormalizer( $secrets->credentials_for( 'gh' ), new EmptyAuthenticatedWebhookDeliveryEvidenceReader() );
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );
		$processor   = $this->processor(
			new ProviderRegistry(
				array(
					new WebhookProcessorProvider(
						static fn ( WebhookRequest $request ): WebhookEnvelope => $normalizer->normalize_webhook( $request )
					),
				)
			),
			$coordinator
		);

		$response = $processor->handle( 'gh', $this->request( str_repeat( 'x', 262145 ), array() ) );

		self::assertSame( 413, $response->get_status() );
		self::assertSame( 'Webhook request is too large.', $response->get_data()['message'] );
		self::assertSame( 0, $secrets->calls );
		self::assertSame( 0, $spy->calls );
	}

	public function test_normalized_event_fan_out_is_bounded_before_intake(): void {
		$events = array();
		foreach ( range( 1, 33 ) as $index ) {
			$events[] = new PushEvent(
				ProviderCode::parse( 'gh' ),
				'owner/example',
				'4001',
				'branch-' . $index,
				str_repeat( 'a', 40 ),
				'delivery-one'
			);
		}
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );
		$processor   = $this->processor(
			new ProviderRegistry(
				array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( ...$events ) ) )
			),
			$coordinator
		);

		$response = $processor->handle( 'gh', $this->request( '{}', $this->signed_headers( '{}' ) ) );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( 0, $spy->calls );
	}

	public function test_intake_receives_only_events_and_body_digest(): void {
		$body        = '{"body-canary":"must-not-cross-intake"}';
		$secret      = self::WEBHOOK_SECRET;
		$event       = new PushEvent(
			ProviderCode::parse( 'gh' ),
			'owner/example',
			'4001',
			'main',
			str_repeat( 'a', 40 ),
			'delivery-one'
		);
		$spy         = new WebhookProcessorCoordinatorSpy();
		$coordinator = new WebhookProcessorCoordinator( null, $spy );
		$materials   = array(
			'zeta-profile'  => array(
				'scope'        => 'owner',
				'target'       => 'owner',
				'authority_id' => '',
				'secret'       => $secret,
			),
			'alpha-profile' => array(
				'scope'        => 'owner',
				'target'       => 'owner',
				'authority_id' => '',
				'secret'       => $secret,
			),
		);
		$processor   = $this->processor(
			new ProviderRegistry(
				array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( $event ) ) )
			),
			$coordinator,
			$materials
		);

		$response = $processor->handle( 'gh', $this->request( $body, $this->signed_headers( $body ) ) );

		self::assertSame( 202, $response->get_status() );
		self::assertSame( 1, $spy->calls );
		self::assertSame( hash( 'sha256', $body ), $spy->authenticated_body_digest );
		self::assertSame( array( $event ), $spy->events );
		$captured = implode(
			'|',
			array_merge( $event->to_array(), array( $spy->authenticated_body_digest ) )
		);
		self::assertStringNotContainsString( $body, $captured );
		self::assertStringNotContainsString( $secret, $captured );
		self::assertStringNotContainsString( 'X-Hub-Signature-256', $captured );
	}

	/**
	 * @param array<string, array{scope: string, target: string, authority_id: string, secret: string}>|null $materials
	 */
	private function processor(
		ProviderRegistry $registry,
		DeploymentCoordinator $coordinator,
		?array $materials = null
	): WebhookProcessor {
		$secrets = new class($materials) extends SecretsFile {
			/** @var array<string, array{scope: string, target: string, authority_id: string, secret: string}> */
			private array $materials;

			/** @param array<string, array{scope: string, target: string, authority_id: string, secret: string}>|null $materials */
			public function __construct( ?array $materials = null ) {
				parent::__construct( '/unused/processor-secrets.php', array() );
				$this->materials = $materials ?? array(
					'test-profile' => array(
						'scope'        => 'owner',
						'target'       => 'owner',
						'authority_id' => '',
						'secret'       => WebhookProcessorTest::WEBHOOK_SECRET,
					),
				);
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_materials retains the production method contract; these inputs do not affect this controlled result.
			public function webhook_materials( ProviderCode|string $provider ): array {
				return $this->materials;
			}
		};

		return new WebhookProcessor( $registry, $coordinator, new SignedWebhookVerifier( $secrets ), $this->queued_selection( new \RAN\Tests\Deployment\AttemptRepositoryDatabase(), $registry ) );
	}

	private function event_processor( WebhookProcessorCoordinator $coordinator ): WebhookProcessor {
		$event = new PushEvent(
			ProviderCode::parse( 'gh' ),
			'owner/example',
			'4001',
			'main',
			str_repeat( 'a', 40 ),
			'delivery-one'
		);

		return $this->processor(
			new ProviderRegistry(
				array( new WebhookProcessorProvider( static fn (): WebhookEnvelope => WebhookEnvelope::events( $event ) ) )
			),
			$coordinator
		);
	}

	/**
	 * @param array<string, string|list<string>> $headers
	 * @return callable(): array{body: string, headers: array<string, string|list<string>>}
	 */
	private function request( string $body, array $headers ): callable {
		return static fn (): array => array(
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * @param 'accepted'|'duplicate' $status
	 * @param 'scheduled'|'already_scheduled'|'unavailable'|'not_required' $runner_status
	 * @return array{
	 *     status: 'accepted'|'duplicate',
	 *     correlation_id: string,
	 *     accepted_targets: int,
	 *     runner_status: 'scheduled'|'already_scheduled'|'unavailable'|'not_required'
	 * }
	 */
	public static function admission_result(
		string $status,
		string $correlation_id,
		int $accepted_targets,
		string $runner_status
	): array {
		return array(
			'status'           => $status,
			'correlation_id'   => $correlation_id,
			'accepted_targets' => $accepted_targets,
			'runner_status'    => $runner_status,
		);
	}

	/** @return array<string, string> */
	private function signed_headers( string $body ): array {
		return array(
			'X-Hub-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, self::WEBHOOK_SECRET ),
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final readonly class WebhookProcessorProvider implements RepositoryProvider, WebhookNormalizer {

	use \RAN\Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	private \Closure $normalizer;

	public function __construct( callable $normalizer, private ?ProviderWebhookPolicy $policy = null ) {
		$this->normalizer = \Closure::fromCallable( $normalizer );
	}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' );
	}

	public function normalize_webhook( WebhookRequest $request ): WebhookEnvelope {
		return ( $this->normalizer )( $request );
	}

	public function get_webhook_policy(): ProviderWebhookPolicy {
		return $this->policy ?? new GitHubWebhookPolicy();
	}

	public function diagnose_webhook_readiness(): \RAN\RepositoryProvider\ProviderDiagnosticResult {
		return new \RAN\RepositoryProvider\ProviderDiagnosticResult(
			\RAN\RepositoryProvider\ProviderDiagnosticResult::WARNING,
			'test.webhook.delivery_unverified',
			'Test webhook delivery is not verified.',
			'Use a provider test delivery.'
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final class WebhookProcessorCoordinator extends DeploymentCoordinator {

	/**
	 * @param array{status: string, correlation_id: string, accepted_targets: int, runner_status: string}|null $result
	 */
	public function __construct(
		private ?array $result = null,
		private ?WebhookProcessorCoordinatorSpy $spy = null,
		private ?Throwable $failure = null
	) {
	}

	public function accept_webhook(
		array $events,
		string $authenticated_body_digest
	): array {
		if ( null !== $this->spy ) {
			++$this->spy->calls;
			$this->spy->events                    = $events;
			$this->spy->authenticated_body_digest = $authenticated_body_digest;
		}
		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->result ?? WebhookProcessorTest::admission_result( 'accepted', str_repeat( 'a', 32 ), 1, 'scheduled' );
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep this private fixture beside the only test/provider double that consumes it.
final class WebhookProcessorCoordinatorSpy {

	public int $calls = 0;
	/** @var list<PushEvent> */
	public array $events                     = array();
	public string $authenticated_body_digest = '';
}
