<?php

declare(strict_types=1);

namespace Tests\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\WebhookPolicy as GitHubWebhookPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\WebhookRejected;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Webhook\SignedWebhookVerifier;

final class SignedWebhookVerifierTest extends TestCase {

	private const SECRET = 'signed-webhook-verifier-secret-0001';

	public function test_exact_raw_body_produces_only_safe_matched_profile_data(): void {
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
			$verification->get_profiles()
		);
		foreach ( $verification->get_profiles() as $profile ) {
			self::assertArrayNotHasKey( 'secret', $profile );
			self::assertNotContains( self::SECRET, $profile );
		}
	}

	public function test_matching_profiles_are_ordered_repository_then_owner_and_by_stable_id(): void {
		$materials = array(
			'owner-profile'    => $this->profile( 'owner', 'owner', '' ),
			'repository-zeta'  => $this->profile( 'repository', 'owner/zeta', '2002' ),
			'repository-alpha' => $this->profile( 'repository', 'owner/alpha', '1001' ),
		);
		$profiles  = $this->verifier( $materials )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() )->get_profiles();

		self::assertSame(
			array( 'repository-alpha', 'repository-zeta', 'owner-profile' ),
			array_column( $profiles, 'id' )
		);
	}

	#[DataProvider( 'invalid_signature_provider' )]
	public function test_missing_malformed_uppercase_and_wrong_signatures_fail_uniformly( ?string $signature, int $expected_secret_reads ): void {
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

			public function webhook_materials( ProviderCode|string $provider ): array {
				++$this->calls;

				return $this->profiles;
			}
		};
		$verifier = new SignedWebhookVerifier( $secrets );

		$this->assert_authentication_failed(
			fn () => $verifier->verify(
				new WebhookRequest( ProviderCode::parse( 'gh' ), '{}', $headers, ( new GitHubWebhookPolicy() )->get_retained_headers() ),
				new GitHubWebhookPolicy()
			)
		);
		self::assertSame( $expected_secret_reads, $secrets->calls );
	}

	/** @return iterable<string, array{?string, int}> */
	public static function invalid_signature_provider(): iterable {
		yield 'missing' => array( null, 0 );
		yield 'wrong' => array( 'sha256=' . str_repeat( '0', 64 ), 1 );
		yield 'uppercase digest' => array( 'sha256=' . str_repeat( 'A', 64 ), 0 );
		yield 'uppercase algorithm' => array( 'SHA256=' . str_repeat( 'a', 64 ), 0 );
	}

	public function test_oversized_and_malformed_profile_sets_fail_closed(): void {
		$profiles = array_fill( 0, 17, $this->profile() );
		$this->assert_authentication_failed(
			fn () => $this->verifier( $profiles )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() )
		);

		$invalid           = $this->profile();
		$invalid['secret'] = 'too-short';
		$this->assert_authentication_failed(
			fn () => $this->verifier( array( 'invalid' => $invalid ) )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() )
		);
	}

	public function test_sixteen_profiles_can_match_the_last_bounded_secret(): void {
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
			$policy->get_retained_headers()
		);

		$profiles = $this->verifier( $materials )->verify( $request, $policy )->get_profiles();

		self::assertSame( array( 'profile-16' ), array_column( $profiles, 'id' ) );
	}

	public function test_header_and_body_budgets_reject_before_verification(): void {
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

			public function webhook_materials( ProviderCode|string $provider ): array {
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
			$policy->get_retained_headers()
		);
	}

	/** @return array<string, mixed> */
	private function profile(
		string $scope = 'repository',
		string $target = 'owner/repository',
		string $authority_id = 'authority-1001'
	): array {
		return array(
			'scope'        => $scope,
			'target'       => $target,
			'authority_id' => $authority_id,
			'secret'       => self::SECRET,
		);
	}

	private function assert_authentication_failed( callable $callback ): void {
		try {
			$callback();
			self::fail( 'Webhook verification should fail closed.' );
		} catch ( WebhookRejected $exception ) {
			self::assertSame( 401, $exception->get_status_code() );
			self::assertSame( 'Webhook authentication failed.', $exception->getMessage() );
		}
	}
}
