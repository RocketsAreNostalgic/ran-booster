<?php

declare(strict_types=1);

namespace Tests\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\WebhookPolicy as GitHubWebhookPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Webhook\SignedWebhookVerifier;

final class SignedWebhookVerifierTest extends TestCase {

	private const SECRET = 'signed-webhook-verifier-secret-0001';

	public function testExactRawBodyProducesOnlySafeMatchedProfileData(): void {
		$body         = "{\n\t\"message\": \"José 🚀\"\n}";
		$verification = $this->verifier()->verify( $this->request( $body ), new GitHubWebhookPolicy() );

		self::assertSame(
			array(
				array(
					'id'           => 'profile-one',
					'scope'        => 'repository',
					'target'       => 'owner/repository',
					'authority_id' => 'authority-1001',
				),
			),
			$verification->getProfiles()
		);
		foreach ( $verification->getProfiles() as $profile ) {
			self::assertArrayNotHasKey( 'secret', $profile );
			self::assertNotContains( self::SECRET, $profile );
		}
	}

	public function testMatchingProfilesAreOrderedRepositoryThenOwnerAndByStableId(): void {
		$materials = array(
			'owner-profile'    => $this->profile( 'owner', 'owner', '' ),
			'repository-zeta'  => $this->profile( 'repository', 'owner/zeta', '2002' ),
			'repository-alpha' => $this->profile( 'repository', 'owner/alpha', '1001' ),
		);
		$profiles  = $this->verifier( $materials )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() )->getProfiles();

		self::assertSame(
			array( 'repository-alpha', 'repository-zeta', 'owner-profile' ),
			array_column( $profiles, 'id' )
		);
	}

	#[DataProvider( 'invalidSignatureProvider' )]
	public function testMissingMalformedUppercaseAndWrongSignaturesFailUniformly( ?string $signature, int $expectedSecretReads ): void {
		$headers = array();
		if ( null !== $signature ) {
			$headers['X-Hub-Signature-256'] = $signature;
		}
		$secrets  = new class( array( 'profile-one' => $this->profile() ) ) extends SecretsFile {
			public int $calls = 0;

			/** @param array<string, array<string, mixed>> $profiles */
			public function __construct( private array $profiles ) {
				parent::__construct( '/unused/signed-verifier-secrets.php', array() );
			}

			public function webhookMaterials( ProviderCode|string $provider ): array {
				++$this->calls;

				return $this->profiles;
			}
		};
		$verifier = new SignedWebhookVerifier( $secrets );

		$this->assertAuthenticationFailed(
			fn () => $verifier->verify(
				new WebhookRequest( ProviderCode::parse( 'gh' ), '{}', $headers, ( new GitHubWebhookPolicy() )->getRetainedHeaders() ),
				new GitHubWebhookPolicy()
			)
		);
		self::assertSame( $expectedSecretReads, $secrets->calls );
	}

	/** @return iterable<string, array{?string, int}> */
	public static function invalidSignatureProvider(): iterable {
		yield 'missing' => array( null, 0 );
		yield 'wrong' => array( 'sha256=' . str_repeat( '0', 64 ), 1 );
		yield 'uppercase digest' => array( 'sha256=' . str_repeat( 'A', 64 ), 0 );
		yield 'uppercase algorithm' => array( 'SHA256=' . str_repeat( 'a', 64 ), 0 );
	}

	public function testOversizedAndMalformedProfileSetsFailClosed(): void {
		$profiles = array_fill( 0, 17, $this->profile() );
		$this->assertAuthenticationFailed(
			fn () => $this->verifier( $profiles )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() )
		);

		$invalid           = $this->profile();
		$invalid['secret'] = 'too-short';
		$this->assertAuthenticationFailed(
			fn () => $this->verifier( array( 'invalid' => $invalid ) )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() )
		);
	}

	public function testSixteenProfilesCanMatchTheLastBoundedSecret(): void {
		$body      = '{"bounded":true}';
		$materials = array();
		foreach ( range( 1, 16 ) as $index ) {
			$id                         = sprintf( 'profile-%02d', $index );
			$materials[ $id ]           = $this->profile();
			$materials[ $id ]['secret'] = sprintf( 'signed-webhook-verifier-secret-%04d', $index );
		}
		$policy  = new GitHubWebhookPolicy();
		$request = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			$body,
			array( 'X-Hub-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, $materials['profile-16']['secret'] ) ),
			$policy->getRetainedHeaders()
		);

		$profiles = $this->verifier( $materials )->verify( $request, $policy )->getProfiles();

		self::assertSame( array( 'profile-16' ), array_column( $profiles, 'id' ) );
	}

	public function testHeaderAndBodyBudgetsRejectBeforeVerification(): void {
		$this->expectException( WebhookRejected::class );
		new WebhookRequest( ProviderCode::parse( 'gh' ), str_repeat( 'x', 262145 ), array(), array() );
	}

	/** @param array<string, array<string, mixed>>|null $profiles */
	private function verifier( ?array $profiles = null ): SignedWebhookVerifier {
		$profiles ??= array( 'profile-one' => $this->profile() );
		$secrets    = new class( $profiles ) extends SecretsFile {
			/** @param array<string, array<string, mixed>> $profiles */
			public function __construct( private array $profiles ) {
				parent::__construct( '/unused/signed-verifier-secrets.php', array() );
			}

			public function webhookMaterials( ProviderCode|string $provider ): array {
				return $this->profiles;
			}
		};

		return new SignedWebhookVerifier( $secrets );
	}

	private function request( string $body ): WebhookRequest {
		$policy = new GitHubWebhookPolicy();

		return new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			$body,
			array( 'X-Hub-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET ) ),
			$policy->getRetainedHeaders()
		);
	}

	/** @return array<string, mixed> */
	private function profile(
		string $scope = 'repository',
		string $target = 'owner/repository',
		string $authorityId = 'authority-1001'
	): array {
		return array(
			'scope'        => $scope,
			'target'       => $target,
			'authority_id' => $authorityId,
			'secret'       => self::SECRET,
		);
	}

	private function assertAuthenticationFailed( callable $callback ): void {
		try {
			$callback();
			self::fail( 'Webhook verification should fail closed.' );
		} catch ( WebhookRejected $exception ) {
			self::assertSame( 401, $exception->getStatusCode() );
			self::assertSame( 'Webhook authentication failed.', $exception->getMessage() );
		}
	}
}
