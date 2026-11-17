<?php

declare(strict_types=1);

namespace RAN\Tests\Webhook;

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

	public function test_verifier_and_result_do_not_retain_signing_secret_directly(): void {
		$verifier = $this->verifier();
		$result   = $verifier->verify( $this->request( '{}' ), new GitHubWebhookPolicy() );
		foreach ( array( $verifier, $result ) as $object ) {
			foreach ( ( new \ReflectionObject( $object ) )->getProperties() as $property ) {
				$value = $property->getValue( $object );
				if ( $value instanceof SecretsFile ) {
					continue;
				}
				$this->assert_no_secret_in_owned_value( $value );
				self::assertNotSame( 'secret', $property->getName() );
			}
		}
		foreach ( $result->get_profiles() as $profile ) {
			self::assertNotContains( self::SECRET, $profile );
		}
	}

	private function assert_no_secret_in_owned_value( mixed $value ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$this->assert_no_secret_in_owned_value( $item );
			}
		} elseif ( is_string( $value ) ) {
			self::assertStringNotContainsString( self::SECRET, $value );
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
	public function test_missing_malformed_uppercase_and_wrong_signatures_fail_uniformly( ?string $signature, int $expected_secret_reads, string $reason ): void {
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

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of webhook_materials retains the production method contract; these inputs do not affect this controlled result.
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
			),
			$reason
		);
		self::assertSame( $expected_secret_reads, $secrets->calls );
	}

	/** @return iterable<string, array{?string, int, string}> */
	public static function invalid_signature_provider(): iterable {
		yield 'missing' => array( null, 0, 'signature_missing' );
		yield 'wrong' => array( 'sha256=' . str_repeat( '0', 64 ), 1, 'signature_mismatch' );
		yield 'uppercase digest' => array( 'sha256=' . str_repeat( 'A', 64 ), 0, 'signature_malformed' );
		yield 'uppercase algorithm' => array( 'SHA256=' . str_repeat( 'a', 64 ), 0, 'signature_malformed' );
	}

	public function test_oversized_and_malformed_profile_sets_fail_closed(): void {
		$profiles = array_fill( 0, 17, $this->profile() );
		$this->assert_authentication_failed(
			fn () => $this->verifier( $profiles )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() )
		);

		$this->assert_authentication_failed(
			fn () => $this->verifier( array( $this->profile() ) )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() )
		);

		$invalid           = $this->profile();
		$invalid['secret'] = 'too-short';
		$this->assert_authentication_failed(
			fn () => $this->verifier( array( 'invalid' => $invalid ) )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() )
		);
	}

	public function test_observed_store_profile_and_secret_failures_keep_uniform_authentication_response(): void {
		$invalid_secret                  = $this->profile();
		$invalid_secret['secret']        = 'short';
		$invalid_profile                 = $this->profile();
		$invalid_profile['authority_id'] = 7;
		foreach ( array(
			array( array(), 'profiles_missing' ),
			array( array_fill( 0, 17, $this->profile() ), 'profile_invalid' ),
			array( array( $this->profile() ), 'profile_invalid' ),
			array( array( 'invalid' => $invalid_profile ), 'profile_invalid' ),
			array( array( 'invalid' => $invalid_secret ), 'secret_invalid' ),
		) as [ $materials, $reason ] ) {
			$this->assert_authentication_failed( fn () => $this->verifier( $materials )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() ), $reason );
		}
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'webhook_materials' )->willThrowException( new \RuntimeException( 'private store path and message' ) );
		$this->assert_authentication_failed( fn () => ( new SignedWebhookVerifier( $secrets ) )->verify( $this->request( '{}' ), new GitHubWebhookPolicy() ), 'secret_store_unavailable' );
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

	public function test_revocation_during_signature_callback_prevents_material_read(): void {
		$allowed = true;
		$policy  = $this->createMock( \RAN\RepositoryProvider\ProviderWebhookPolicy::class );
		$policy->method( 'get_signature_header' )->willReturnCallback(
			static function () use ( &$allowed ): string {
				$allowed = false;
				return 'X-Hub-Signature-256';
			}
		);
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'webhook_materials' );
		$guard = static function () use ( &$allowed ): void {
			if ( ! $allowed ) {
				throw new \RuntimeException( 'Provider revoked.' );
			}
		};
		$this->expectException( \RuntimeException::class );
		( new SignedWebhookVerifier( $secrets ) )->verify( $this->request( '{}' ), $policy, $guard );
	}

	public function test_revocation_during_material_read_prevents_verified_result(): void {
		$allowed = true;
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->method( 'webhook_materials' )->willReturnCallback(
			function () use ( &$allowed ): array {
				$allowed = false;
				return array( 'profile-one' => $this->profile() );
			}
		);
		$guard = static function () use ( &$allowed ): void {
			if ( ! $allowed ) {
				throw new \RuntimeException( 'Provider revoked.' );
			}
		};
		$this->expectException( \RuntimeException::class );
		( new SignedWebhookVerifier( $secrets ) )->verify( $this->request( '{}' ), new GitHubWebhookPolicy(), $guard );
	}

	/** @param array<int|string, array<string, mixed>>|null $profiles */
	private function verifier( ?array $profiles = null ): SignedWebhookVerifier {
		$profiles ??= array( 'profile-one' => $this->profile() );
		$secrets    = $this->createMock( SecretsFile::class );
		$secrets->method( 'webhook_materials' )->willReturn( $profiles );

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

	private function assert_authentication_failed( callable $callback, ?string $reason = null ): void {
		try {
			$callback();
			self::fail( 'Webhook verification should fail closed.' );
		} catch ( WebhookRejected $exception ) {
			self::assertSame( 401, $exception->get_status_code() );
			if ( null !== $reason ) {
				self::assertSame( $reason, $exception->get_reason() );
			}
			self::assertSame( 'Webhook authentication failed.', $exception->getMessage() );
		}
	}
}
