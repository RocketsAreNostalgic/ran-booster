<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidence;
use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\GitHubWebhookNormalizer;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\SignedWebhookVerification;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;

final class GitHubWebhookNormalizerTest extends TestCase {

	private const OWNER_SECRET     = 'owner-test-webhook-secret-0001';
	private const OTHER_SECRET     = 'other-test-webhook-secret';
	private const REPOSITORY       = 'RocketsAreNostalgic/ran-booster';
	private const REPOSITORY_ID    = '9223372036854775807123';
	private const COMMIT           = '0123456789abcdef0123456789abcdef01234567';
	private const ZERO_COMMIT      = '0000000000000000000000000000000000000000';
	private const DELIVERY_ID      = 'delivery-one';
	private const RETAINED_HEADERS = array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' );

	public function testValidPushIsNormalizedToTheExactProviderNeutralShape(): void {
		$body     = $this->encode( $this->validPushPayload() );
		$envelope = $this->normalizer()->normalizeWebhook( $this->request( $body ) );

		self::assertTrue( $envelope->hasEvents() );
		self::assertSame(
			array(
				'provider'               => 'gh',
				'repository'             => self::REPOSITORY,
				'provider_repository_id' => self::REPOSITORY_ID,
				'branch'                 => 'release/alpha',
				'commit'                 => self::COMMIT,
				'delivery_id'            => self::DELIVERY_ID,
			),
			$envelope->getEvents()[0]->toArray()
		);
	}

	public function testRepositoryIdsThatFitNativeIntegersRemainOpaqueStrings(): void {
		$payload                     = $this->validPushPayload();
		$payload['repository']['id'] = 123456;
		$body                        = $this->encode( $payload );
		$event                       = $this->normalizer()->normalizeWebhook( $this->request( $body ) )->getEvents()[0];

		self::assertSame( '123456', $event->providerRepositoryId );
	}

	public function testSignedPingIsReturnedAsAProbe(): void {
		$body     = '{"zen":"Keep it logically awesome."}';
		$request  = $this->request( $body, 'ping' );
		$envelope = $this->normalizer()->normalizeWebhook( $request );

		self::assertTrue( $envelope->isProbe() );
		self::assertSame( array(), $envelope->getEvents() );
	}

	public function testSignedUnrelatedEventIsIgnoredWithoutParsingItsBody(): void {
		$body     = 'not-json-and-not-needed';
		$request  = $this->request( $body, 'issues' );
		$envelope = $this->normalizer()->normalizeWebhook( $request );

		self::assertTrue( $envelope->isIgnored() );
	}

	public function testExactBodyAndHeaderLimitsAreAccepted(): void {
		$body = str_repeat( 'a', 262144 );

		$envelope = $this->normalizer()->normalizeWebhook(
			$this->request( $body, str_repeat( 'e', 64 ), str_repeat( 'd', 191 ) )
		);

		self::assertTrue( $envelope->isIgnored() );
	}

	public function testBodyAboveTheLimitIsRejectedBeforeSecrets(): void {
		$body                         = str_repeat( 'a', 262145 );
		list( $normalizer, $secrets ) = $this->countingNormalizer();

		$this->assertRejected(
			413,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook( $this->request( $body, 'issues' ) )
		);
		self::assertSame( 0, $secrets->calls );
	}

	public function testMissingProcessorVerificationIsRejectedAfterBoundedHeaders(): void {
		$normalizer = $this->normalizer( array() );
		$request    = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array(
				'X-GitHub-Event'      => 'issues',
				'X-GitHub-Delivery'   => self::DELIVERY_ID,
				'X-Hub-Signature-256' => $this->signature( '{}' ),
			),
			self::RETAINED_HEADERS
		);

		$this->assertRejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook( $request )
		);
	}

	#[DataProvider( 'malformedSignatureFormProvider' )]
	public function testMalformedSignatureFormsAreRejectedBeforeSecrets( array $values ): void {
		$body                         = '{}';
		list( $normalizer, $secrets ) = $this->countingNormalizer();
		$headers                      = array(
			'X-GitHub-Event'      => 'issues',
			'X-GitHub-Delivery'   => self::DELIVERY_ID,
			'X-Hub-Signature-256' => $values,
		);

		$this->assertRejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @return iterable<string, array{list<string>}>
	 */
	public static function malformedSignatureFormProvider(): iterable {
		yield 'leading whitespace' => array( array( ' sha256=' . str_repeat( 'a', 64 ) ) );
		yield 'trailing whitespace' => array( array( 'sha256=' . str_repeat( 'a', 64 ) . ' ' ) );
		yield 'control byte' => array( array( 'sha256=' . str_repeat( 'a', 63 ) . "\x7F" ) );
		yield 'uppercase algorithm' => array( array( 'SHA256=' . str_repeat( 'a', 64 ) ) );
		yield 'uppercase digest' => array( array( 'sha256=' . str_repeat( 'A', 64 ) ) );
		yield 'short digest' => array( array( 'sha256=' . str_repeat( 'a', 63 ) ) );
		yield 'long digest' => array( array( 'sha256=' . str_repeat( 'a', 65 ) ) );
		yield 'repeated equivalent values' => array(
			array(
				'sha256=' . str_repeat( 'a', 64 ),
				'sha256=' . str_repeat( 'a', 64 ),
			),
		);
	}

	public function testMissingSignatureIsRejectedBeforeSecrets(): void {
		list( $normalizer, $secrets ) = $this->countingNormalizer();

		$this->assertRejected(
			401,
			static fn (): WebhookEnvelope => $normalizer->normalizeWebhook(
				new WebhookRequest(
					ProviderCode::parse( 'gh' ),
					'{}',
					array(
						'X-GitHub-Event'    => 'issues',
						'X-GitHub-Delivery' => self::DELIVERY_ID,
					),
					self::RETAINED_HEADERS
				)
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	#[DataProvider( 'invalidBoundedHeaderProvider' )]
	public function testInvalidEventAndDeliveryHeadersAreRejectedBeforeSecrets(
		string $header,
		string|array $value
	): void {
		$body                         = '{}';
		list( $normalizer, $secrets ) = $this->countingNormalizer();
		$headers                      = array(
			'X-GitHub-Event'      => 'issues',
			'X-GitHub-Delivery'   => self::DELIVERY_ID,
			'X-Hub-Signature-256' => $this->signature( $body ),
		);
		$headers[ $header ]           = $value;

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @return iterable<string, array{string, string|list<string>}>
	 */
	public static function invalidBoundedHeaderProvider(): iterable {
		yield 'event leading whitespace' => array( 'X-GitHub-Event', ' push' );
		yield 'event trailing whitespace' => array( 'X-GitHub-Event', 'push ' );
		yield 'event tab' => array( 'X-GitHub-Event', "pu\tsh" );
		yield 'event control' => array( 'X-GitHub-Event', "pu\x7Fsh" );
		yield 'event non-ASCII' => array( 'X-GitHub-Event', "pu\xC3\xB1sh" );
		yield 'event too long' => array( 'X-GitHub-Event', str_repeat( 'e', 65 ) );
		yield 'event repeated equivalent' => array( 'X-GitHub-Event', array( 'push', 'push' ) );
		yield 'delivery leading whitespace' => array( 'X-GitHub-Delivery', ' delivery' );
		yield 'delivery trailing whitespace' => array( 'X-GitHub-Delivery', 'delivery ' );
		yield 'delivery newline' => array( 'X-GitHub-Delivery', "delivery\n" );
		yield 'delivery control' => array( 'X-GitHub-Delivery', "delivery\x7F" );
		yield 'delivery non-ASCII' => array( 'X-GitHub-Delivery', "delivery\xC3\xB1" );
		yield 'delivery too long' => array( 'X-GitHub-Delivery', str_repeat( 'd', 192 ) );
		yield 'delivery repeated equivalent' => array( 'X-GitHub-Delivery', array( 'delivery', 'delivery' ) );
	}

	#[DataProvider( 'missingBoundedHeaderProvider' )]
	public function testMissingEventAndDeliveryHeadersAreRejectedBeforeSecrets( string $header ): void {
		$body                         = '{}';
		list( $normalizer, $secrets ) = $this->countingNormalizer();
		$headers                      = array(
			'X-GitHub-Event'      => 'issues',
			'X-GitHub-Delivery'   => self::DELIVERY_ID,
			'X-Hub-Signature-256' => $this->signature( $body ),
		);
		unset( $headers[ $header ] );

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function missingBoundedHeaderProvider(): iterable {
		yield 'event missing' => array( 'X-GitHub-Event' );
		yield 'delivery missing' => array( 'X-GitHub-Delivery' );
	}

	#[DataProvider( 'aliasedHeaderProvider' )]
	public function testAliasedHeadersAreRejectedBeforeSecrets( string $canonical, string $alias, int $status ): void {
		$body                         = '{}';
		list( $normalizer, $secrets ) = $this->countingNormalizer();
		$headers                      = array(
			'X-GitHub-Event'      => 'issues',
			'X-GitHub-Delivery'   => self::DELIVERY_ID,
			'X-Hub-Signature-256' => $this->signature( $body ),
		);
		$headers[ $alias ]            = $headers[ $canonical ];

		$this->assertRejected(
			$status,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
		self::assertSame( 0, $secrets->calls );
	}

	/**
	 * @return iterable<string, array{string, string, int}>
	 */
	public static function aliasedHeaderProvider(): iterable {
		yield 'signature alias' => array( 'X-Hub-Signature-256', 'X_Hub_Signature_256', 401 );
		yield 'event alias' => array( 'X-GitHub-Event', 'X_GitHub_Event', 400 );
		yield 'delivery alias' => array( 'X-GitHub-Delivery', 'X_GitHub_Delivery', 400 );
	}

	#[DataProvider( 'invalidSignatureProvider' )]
	public function testMissingAndInvalidSignaturesAreRejected( ?string $signature ): void {
		$body    = $this->encode( $this->validPushPayload() );
		$headers = array(
			'X-GitHub-Event'    => 'push',
			'X-GitHub-Delivery' => self::DELIVERY_ID,
		);

		if ( null !== $signature ) {
			$headers['X-Hub-Signature-256'] = $signature;
		}

		$this->assertRejected(
			401,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook(
				new WebhookRequest( ProviderCode::parse( 'gh' ), $body, $headers, self::RETAINED_HEADERS )
			)
		);
	}

	/**
	 * @return iterable<string, array{?string}>
	 */
	public static function invalidSignatureProvider(): iterable {
		yield 'missing' => array( null );
		yield 'wrong digest' => array( 'sha256=' . str_repeat( 'a', 64 ) );
		yield 'obsolete algorithm' => array( 'sha1=' . str_repeat( 'a', 40 ) );
		yield 'invalid encoding' => array( 'sha256=not-hex' );
	}

	public function testPushRequiresADeliveryIdentifier(): void {
		$body = $this->encode( $this->validPushPayload() );

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook(
				new WebhookRequest(
					ProviderCode::parse( 'gh' ),
					$body,
					array(
						'X-GitHub-Event'      => 'push',
						'X-Hub-Signature-256' => $this->signature( $body ),
					),
					self::RETAINED_HEADERS
				)
			)
		);
	}

	public function testMalformedJsonIsRejectedWithASafeMessage(): void {
		$secretBody                   = '{"token":"body-secret"';
		list( $normalizer, $secrets ) = $this->countingNormalizer();

		try {
			$normalizer->normalizeWebhook( $this->request( $secretBody ) );
			self::fail( 'Malformed JSON should be rejected.' );
		} catch ( WebhookRejected $exception ) {
			self::assertSame( 400, $exception->getStatusCode() );
				self::assertStringNotContainsString( self::OWNER_SECRET, $exception->getMessage() );
			self::assertStringNotContainsString( 'body-secret', $exception->getMessage() );
		}

		self::assertSame( 0, $secrets->calls );
	}

	public function testInvalidSignatureOnMalformedJsonIsRejectedBeforeJsonParsing(): void {
		$body                         = '{"token":"body-secret"';
		list( $normalizer, $secrets ) = $this->countingNormalizer();
		$request                      = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			$body,
			array(
				'X-GitHub-Event'      => 'push',
				'X-GitHub-Delivery'   => self::DELIVERY_ID,
				'X-Hub-Signature-256' => $this->signature( $body, self::OTHER_SECRET ),
			),
			self::RETAINED_HEADERS
		);

		$this->assertRejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook( $request )
		);
		self::assertSame( 0, $secrets->calls );
	}

	#[DataProvider( 'malformedPushProvider' )]
	public function testMissingOrMalformedRequiredPushFieldsAreRejected( array $payload ): void {
		$body = $this->encode( $payload );

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook( $this->request( $body ) )
		);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function malformedPushProvider(): iterable {
		$valid = self::staticValidPushPayload();

		$payload = $valid;
		unset( $payload['repository'] );
		yield 'missing repository' => array( $payload );

		$payload = $valid;
		unset( $payload['repository']['id'] );
		yield 'missing repository id' => array( $payload );

		$payload                     = $valid;
		$payload['repository']['id'] = 12.5;
		yield 'lossy repository id' => array( $payload );

		$payload = $valid;
		unset( $payload['repository']['full_name'] );
		yield 'missing repository name' => array( $payload );

		$payload                            = $valid;
		$payload['repository']['full_name'] = 'missing-repository-component';
		yield 'invalid repository name' => array( $payload );

		$payload = $valid;
		unset( $payload['ref'] );
		yield 'missing ref' => array( $payload );

		$payload = $valid;
		unset( $payload['after'] );
		yield 'missing commit' => array( $payload );

		$payload            = $valid;
		$payload['deleted'] = 'false';
		yield 'non-boolean deletion flag' => array( $payload );
	}

	#[DataProvider( 'ignoredPushProvider' )]
	public function testNonDeployablePushesAreIgnored( array $payload ): void {
		$body     = $this->encode( $payload );
		$envelope = $this->normalizer()->normalizeWebhook( $this->request( $body ) );

		self::assertTrue( $envelope->isIgnored() );
		self::assertSame( array(), $envelope->getEvents() );
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function ignoredPushProvider(): iterable {
		$valid = self::staticValidPushPayload();

		$payload            = $valid;
		$payload['deleted'] = true;
		yield 'deleted branch' => array( $payload );

		$payload        = $valid;
		$payload['ref'] = 'refs/tags/v1.0.0';
		yield 'tag push' => array( $payload );

		$payload          = $valid;
		$payload['after'] = 'not-an-immutable-commit';
		yield 'invalid commit' => array( $payload );

		$payload          = $valid;
		$payload['after'] = self::ZERO_COMMIT;
		yield 'zero commit' => array( $payload );
	}

	#[DataProvider( 'authorizedScopeProvider' )]
	public function testMatchedProfilesAuthorizeOnlyTheirConfiguredScope( string $scope, string $target ): void {
		$body       = $this->encode( $this->validPushPayload() );
		$normalizer = $this->normalizer( array( $this->profile( self::OWNER_SECRET, $scope, $target ) ) );

		self::assertTrue( $normalizer->normalizeWebhook( $this->verifiedRequest( $body, $scope, $target ) )->hasEvents() );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function authorizedScopeProvider(): iterable {
		yield 'owner case insensitive' => array( 'owner', 'rocketsarenostalgic' );
		yield 'repository case insensitive' => array( 'repository', 'rocketsarenostalgic/RAN-BOOSTER' );
	}

	#[DataProvider( 'unauthorizedScopeProvider' )]
	public function testMatchedSecretOutsideItsConfiguredScopeIsRejected( string $scope, string $target ): void {
		$body       = $this->encode( $this->validPushPayload() );
		$normalizer = $this->normalizer( array( $this->profile( self::OWNER_SECRET, $scope, $target ) ) );

		$this->assertRejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook( $this->verifiedRequest( $body, $scope, $target ) )
		);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function unauthorizedScopeProvider(): iterable {
		yield 'different owner' => array( 'owner', 'ProtestsAndSuffergettes' );
		yield 'different repository' => array( 'repository', 'RocketsAreNostalgic/other-plugin' );
		yield 'removed global scope' => array( 'global', '' );
		yield 'unknown scope' => array( 'organization', 'RocketsAreNostalgic' );
	}

	public function testASecondRequestCannotReuseTheFirstRequestsMatchedProfile(): void {
		$profiles   = array(
			$this->profile( self::OWNER_SECRET, 'owner', 'RocketsAreNostalgic' ),
			$this->profile( self::OTHER_SECRET, 'owner', 'ProtestsAndSuffergettes' ),
		);
		$normalizer = $this->normalizer( $profiles );
		$body       = $this->encode( $this->validPushPayload() );

		self::assertTrue( $normalizer->normalizeWebhook( $this->request( $body ) )->hasEvents() );

		$this->assertRejected(
			401,
			fn (): WebhookEnvelope => $normalizer->normalizeWebhook(
				$this->verifiedRequest( $body, 'owner', 'ProtestsAndSuffergettes', self::OTHER_SECRET )
			)
		);
	}

	public function testWebhookReadinessReportsMissingConfigurationWithoutReadingDeliveryState(): void {
		$deliveries = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public int $calls = 0;

			public function latestAuthenticatedDelivery( ProviderCode $provider ): ?AuthenticatedWebhookDeliveryEvidence {
				++$this->calls;

				return null;
			}
		};
		$result     = $this->normalizer( array(), $deliveries )->diagnoseWebhookReadiness();

		self::assertSame( ProviderDiagnosticResult::NOT_CONFIGURED, $result->status );
		self::assertSame( 'gh.webhook.not_configured', $result->code );
		self::assertSame( 0, $deliveries->calls );
	}

	public function testWebhookReadinessReportsConfiguredButNoRetainedDeliveryEvidence(): void {
		$result = $this->normalizer()->diagnoseWebhookReadiness();
		$output = implode( ' ', $result->toArray() );

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'gh.webhook.delivery_not_observed', $result->code );
		self::assertStringContainsString( 'Site-wide Push-to-Deploy check', $result->message );
		self::assertStringContainsString( 'not scoped to the repository selected above', $result->message );
		self::assertStringContainsString( 'GitHub ping tests', $result->remediation );
		self::assertStringNotContainsString( self::OWNER_SECRET, $output );
		self::assertStringNotContainsString( self::OTHER_SECRET, $output );
	}

	public function testWebhookReadinessReportsLatestAuthenticatedMatchedDelivery(): void {
		$result = $this->normalizer(
			null,
			$this->deliveryReader( new AuthenticatedWebhookDeliveryEvidence( ProviderCode::parse( 'gh' ), '2026-07-26 18:30:00', true ) )
		)->diagnoseWebhookReadiness();

		self::assertSame( ProviderDiagnosticResult::PASSED, $result->status );
		self::assertSame( 'gh.webhook.delivery_authenticated', $result->code );
		self::assertStringContainsString( '2026-07-26 18:30:00 site time', $result->message );
		self::assertStringContainsString( 'matched at least one managed package', $result->message );
		self::assertStringContainsString( 'not scoped to the repository selected above', $result->message );
		self::assertStringContainsString( 'If the webhook secret or provider hook changed after this time', $result->remediation );
	}

	public function testWebhookReadinessReportsAuthenticatedDeliveryThatMatchedNoPackage(): void {
		$result = $this->normalizer(
			null,
			$this->deliveryReader( new AuthenticatedWebhookDeliveryEvidence( ProviderCode::parse( 'gh' ), '2026-07-26 18:31:00', false ) )
		)->diagnoseWebhookReadiness();

		self::assertSame( ProviderDiagnosticResult::WARNING, $result->status );
		self::assertSame( 'gh.webhook.delivery_authenticated_unmatched', $result->code );
		self::assertStringContainsString( 'matched no managed package', $result->message );
		self::assertStringContainsString( 'not scoped to the repository selected above', $result->message );
		self::assertStringContainsString( 'repository identity, configured branch, and package deployment policy', strtolower( $result->remediation ) );
	}

	public function testWebhookReadinessSafelyReportsUnavailableDeliveryEvidence(): void {
		$deliveries = new class() implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function latestAuthenticatedDelivery( ProviderCode $provider ): ?AuthenticatedWebhookDeliveryEvidence {
				throw new \RuntimeException( 'delivery-evidence-canary' );
			}
		};
		$result     = $this->normalizer( null, $deliveries )->diagnoseWebhookReadiness();
		$output     = implode( ' ', $result->toArray() );

		self::assertSame( ProviderDiagnosticResult::FAILED, $result->status );
		self::assertSame( 'gh.webhook.delivery_evidence_unavailable', $result->code );
		self::assertStringNotContainsString( 'delivery-evidence-canary', $output );
	}

	public function testWebhookReadinessSafelyReportsUnreadableConfiguration(): void {
		$secrets = new class() extends SecretsFile {
			public function __construct() {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			public function webhookProfiles( ProviderCode|string $provider ): array {
				throw new \RuntimeException( 'github-webhook-secret-canary' );
			}
		};
		$result  = ( new GitHubWebhookNormalizer( $secrets, new EmptyAuthenticatedWebhookDeliveryEvidenceReader() ) )->diagnoseWebhookReadiness();
		$output  = implode( ' ', $result->toArray() );

		self::assertSame( ProviderDiagnosticResult::FAILED, $result->status );
		self::assertSame( 'gh.webhook.configuration_unavailable', $result->code );
		self::assertStringNotContainsString( 'github-webhook-secret-canary', $output );
	}

	public function testNormalizerRejectsARequestForAnotherProvider(): void {
		$body    = $this->encode( $this->validPushPayload() );
		$request = new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			$body,
			array(
				'X-GitHub-Event'      => 'push',
				'X-GitHub-Delivery'   => self::DELIVERY_ID,
				'X-Hub-Signature-256' => $this->signature( $body ),
			),
			self::RETAINED_HEADERS
		);

		$this->assertRejected(
			400,
			fn (): WebhookEnvelope => $this->normalizer()->normalizeWebhook( $request )
		);
	}

	/**
	 * @param list<array<string, mixed>>|null $profiles Secret profiles.
	 */
	private function normalizer(
		?array $profiles = null,
		?AuthenticatedWebhookDeliveryEvidenceReader $deliveries = null
	): GitHubWebhookNormalizer {
		return $this->countingNormalizer( $profiles, $deliveries )[0];
	}

	/**
	 * @param list<array<string, mixed>>|null $profiles Secret profiles.
	 * @return array{GitHubWebhookNormalizer, object}
	 */
	private function countingNormalizer(
		?array $profiles = null,
		?AuthenticatedWebhookDeliveryEvidenceReader $deliveries = null
	): array {
		$profiles   ??= array( $this->profile( self::OWNER_SECRET, 'owner', 'RocketsAreNostalgic' ) );
		$deliveries ??= new EmptyAuthenticatedWebhookDeliveryEvidenceReader();

		$secrets = new class( $profiles ) extends SecretsFile {
			public int $calls = 0;

			/**
			 * @param list<array<string, mixed>> $profiles Secret profiles.
			 */
			public function __construct( private array $profiles ) {
				parent::__construct( '/unused/test-secrets.php', array() );
			}

			/**
			 * @return list<array<string, mixed>>
			 */
			public function webhookMaterials( ProviderCode|string $provider ): array {
				++$this->calls;

				return $this->profiles;
			}
		};

		return array( new GitHubWebhookNormalizer( $secrets, $deliveries ), $secrets );
	}

	private function deliveryReader( ?AuthenticatedWebhookDeliveryEvidence $evidence ): AuthenticatedWebhookDeliveryEvidenceReader {
		return new class( $evidence ) implements AuthenticatedWebhookDeliveryEvidenceReader {
			public function __construct( private ?AuthenticatedWebhookDeliveryEvidence $evidence ) {
			}

			public function latestAuthenticatedDelivery( ProviderCode $provider ): ?AuthenticatedWebhookDeliveryEvidence {
				if ( null !== $this->evidence && ! $this->evidence->provider->equals( $provider ) ) {
					throw new \RuntimeException( 'Unexpected provider.' );
				}

				return $this->evidence;
			}
		};
	}

	private function request(
		string $body,
		string $event = 'push',
		string $deliveryId = self::DELIVERY_ID,
		string $secret = self::OWNER_SECRET
	): WebhookRequest {
		return ( new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			$body,
			array(
				'X-GitHub-Event'      => $event,
				'X-GitHub-Delivery'   => $deliveryId,
				'X-Hub-Signature-256' => $this->signature( $body, $secret ),
			),
			self::RETAINED_HEADERS
		) )->withVerification( $this->verification( 'owner', 'RocketsAreNostalgic' ) );
	}

	private function verifiedRequest( string $body, string $scope, string $target, string $secret = self::OWNER_SECRET ): WebhookRequest {
		return $this->request( $body, 'push', self::DELIVERY_ID, $secret )
			->withVerification( $this->verification( $scope, $target ) );
	}

	private function verification( string $scope, string $target ): SignedWebhookVerification {
		return new SignedWebhookVerification(
			ProviderCode::parse( 'gh' ),
			array(
				array(
					'id'           => 'test-profile',
					'scope'        => $scope,
					'target'       => $target,
					'authority_id' => 'repository' === $scope && 0 === strcasecmp( $target, self::REPOSITORY )
						? self::REPOSITORY_ID
						: ( 'repository' === $scope ? 'different-repository-id' : '' ),
				),
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function validPushPayload(): array {
		return self::staticValidPushPayload();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function staticValidPushPayload(): array {
		return array(
			'ref'        => 'refs/heads/release/alpha',
			'after'      => self::COMMIT,
			'deleted'    => false,
			'repository' => array(
				'id'        => self::REPOSITORY_ID,
				'full_name' => self::REPOSITORY,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function profile( string $secret, string $scope, string $target ): array {
		return array(
			'id'           => 'test-profile',
			'label'        => 'Test profile',
			'scope'        => $scope,
			'target'       => $target,
			'authority_id' => 'repository' === $scope && 0 === strcasecmp( $target, self::REPOSITORY )
				? self::REPOSITORY_ID
				: '',
			'secret'       => $secret,
			'source'       => 'test',
			'immutable'    => false,
		);
	}

	private function signature( string $body, string $secret = self::OWNER_SECRET ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * @param array<string, mixed> $payload JSON payload.
	 */
	private function encode( array $payload ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress is not loaded by focused unit tests.
		$json = json_encode( $payload, JSON_THROW_ON_ERROR );

		self::assertIsString( $json );

		return $json;
	}

	/**
	 * @param callable(): WebhookEnvelope $operation Normalizer operation expected to reject.
	 */
	private function assertRejected( int $statusCode, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Webhook request should have been rejected.' );
		} catch ( WebhookRejected $exception ) {
			self::assertSame( $statusCode, $exception->getStatusCode() );
			self::assertNotSame( '', $exception->getMessage() );
			self::assertStringNotContainsString( self::OWNER_SECRET, $exception->getMessage() );
			self::assertStringNotContainsString( self::OTHER_SECRET, $exception->getMessage() );
		}
	}
}
