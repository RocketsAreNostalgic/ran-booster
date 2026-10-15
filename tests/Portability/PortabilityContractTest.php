<?php

declare(strict_types=1);

namespace RAN\Tests\Portability;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\Portability\PortabilityApplyResult;
use RAN\AddOn\Portability\PortabilityCandidate;
use RAN\AddOn\Portability\PortabilityFacade;
use RAN\AddOn\Portability\PortabilityReviewResult;
use RAN\Portability\TargetPackageReason;
use ReflectionClass;

final class PortabilityContractTest extends TestCase {

	public function test_selection_context_is_canonical_and_changes_nonce_scope(): void {
		$facade      = $this->facade();
		$candidate   = $this->candidate();
		$expectation = $this->expectation();
		$fingerprint = $this->review( $candidate )->fingerprint;
		$action      = $facade->nonce_action( 'apply', $candidate, $fingerprint, $expectation );
		self::assertSame( $action, $facade->nonce_action( 'apply', $candidate, $fingerprint, array_reverse( $expectation, true ) ) );
		$changed                          = $expectation;
		$changed['selection_generation'] += 1;
		self::assertNotSame( $action, $facade->nonce_action( 'apply', $candidate, $fingerprint, $changed ) );
		$changed                               = $expectation;
		$changed['implementation_fingerprint'] = str_repeat( 'b', 64 );
		self::assertNotSame( $action, $facade->nonce_action( 'apply', $candidate, $fingerprint, $changed ) );
		$this->expectException( InvalidArgumentException::class );
		$facade->nonce_action( 'apply', $this->candidate( array( 'provider_code' => 'bb' ) ), $fingerprint, $expectation );
	}

	public function test_api_four_binds_destination_expectation_without_changing_portable_candidate_bytes(): void {
		// Exact API 4 wire bytes; portable candidate fields remain source-only.
		$candidate = $this->candidate();
		$review    = $this->review( $candidate );
		$facade    = $this->facade();

		self::assertSame(
			'{"type":"plugin","identifier":"example/example.php","display_name":"Example","provider":"gh","repository":"owner/example","branch":"main","subdirectory":null,"credential_id":"target-profile"}',
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Preserve the exact canonical bytes used by the hash contract.
			json_encode( $candidate->to_array(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
		self::assertSame(
			'v1:897718ba1040e4499a8cd992a9ad689a256c853b4585016a1e6bebb0af851a19',
			$review->fingerprint
		);
		self::assertSame(
			'ran-booster-portability-review-v1-1641d74f5dc75d2858b214ff88948f9adfffec15c422166ab6540b796b310b9e',
			$facade->nonce_action( 'review', $candidate, null, null )
		);
		self::assertSame(
			'ran-booster-portability-apply-v1-dfb303925573bf4a63679902adf6417314e5e43618852533d0ccf79f862067ef-897718ba1040e4499a8cd992a9ad689a256c853b4585016a1e6bebb0af851a19',
			$facade->nonce_action( 'apply', $candidate, $review->fingerprint, $this->expectation() )
		);
	}

	public function test_completed_php_boundary_rejects_legacy_names_and_accepts_named_arguments(): void {
		$candidate = new PortabilityCandidate(
			type: 'plugin',
			identifier: 'example/example.php',
			display_name: 'Example',
			provider_code: 'gh',
			repository: 'owner/example',
			branch: 'main',
			credential_id: 'target-profile'
		);
		$review    = PortabilityReviewResult::from_resolved(
			candidate: $candidate,
			action: PortabilityReviewResult::ADOPT,
			reason: TargetPackageReason::NONE->value,
			message: 'Ready.',
			provider_repository_id: 'repository-id',
			repository_private: true
		);
		$result    = new PortabilityApplyResult(
			status: PortabilityApplyResult::ADOPTED,
			reason: TargetPackageReason::NONE->value,
			message: 'Adopted.',
			target_verified: true
		);
		self::assertTrue( $result->target_verified );
		self::assertSame( $this->candidate()->to_array(), $candidate->to_array() );
		self::assertSame(
			$this->facade()->nonce_action( 'apply', $candidate, $review->fingerprint, $this->expectation() ),
			$this->facade()->nonce_action( operation: 'apply', candidate: $candidate, expected_fingerprint: $review->fingerprint, provider_expectation: $this->expectation() )
		);
		foreach ( array( PortabilityFacade::class, PortabilityCandidate::class, PortabilityReviewResult::class, PortabilityApplyResult::class, \RAN\AddOn\Portability\NativePortabilityFacade::class ) as $class ) {
			$reflection = new ReflectionClass( $class );
			foreach ( $reflection->getMethods() as $method ) {
				self::assertMatchesRegularExpression( '/^[a-z_]+$/', $method->name );
				foreach ( $method->getParameters() as $parameter ) {
					self::assertMatchesRegularExpression( '/^[a-z_]+$/', $parameter->name );
				}
			}
			foreach ( $reflection->getProperties() as $property ) {
				self::assertMatchesRegularExpression( '/^[a-z_]+$/', $property->name );
			}
		}
	}

	public function test_candidate_projects_only_bounded_target_fields(): void {
		$candidate = $this->candidate();

		self::assertSame(
			array(
				'type'          => 'plugin',
				'identifier'    => 'example/example.php',
				'display_name'  => 'Example',
				'provider'      => 'gh',
				'repository'    => 'owner/example',
				'branch'        => 'main',
				'subdirectory'  => null,
				'credential_id' => 'target-profile',
			),
			$candidate->to_array()
		);
		self::assertSame(
			array( 'type', 'identifier', 'display_name', 'provider_code', 'repository', 'branch', 'subdirectory', 'credential_id' ),
			array_keys( get_object_vars( $candidate ) )
		);
	}

	/** @param array<string, mixed> $overrides */
	#[DataProvider( 'invalid_candidate_provider' )]
	public function test_candidate_rejects_non_canonical_or_unbounded_input( array $overrides ): void {
		$this->expectException( InvalidArgumentException::class );

		$this->candidate( $overrides );
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function invalid_candidate_provider(): iterable {
		yield 'operation-shaped type' => array( array( 'type' => 'install-plugin' ) );
		yield 'plugin without basename' => array( array( 'identifier' => 'example' ) );
		yield 'theme with plugin path' => array(
			array(
				'type'       => 'theme',
				'identifier' => 'example/example.php',
			),
		);
		yield 'blank display name' => array( array( 'display_name' => '' ) );
		yield 'display control' => array( array( 'display_name' => "Example\nPackage" ) );
		yield 'reserved provider' => array( array( 'provider_code' => 'portability' ) );
		yield 'repository credential' => array( array( 'repository' => 'https://token@example.test/owner/repository' ) );
		yield 'repository query' => array( array( 'repository' => 'https://example.test/owner/repository?token=secret' ) );
		yield 'blank branch' => array( array( 'branch' => '' ) );
		yield 'noncanonical subdirectory' => array( array( 'subdirectory' => 'plugin/' ) );
		yield 'short credential id' => array( array( 'credential_id' => 'ab' ) );
		yield 'credential control' => array( array( 'credential_id' => "target\nprofile" ) );
		yield 'long credential id' => array( array( 'credential_id' => str_repeat( 'a', 65 ) ) );
	}

	public function test_review_result_is_closed_and_version_fingerprint_bound(): void {
		$result = PortabilityReviewResult::from_resolved(
			$this->candidate(),
			PortabilityReviewResult::ADOPT,
			TargetPackageReason::NONE->value,
			'This package can be adopted.',
			'repository-id',
			true
		);

		self::assertSame( PortabilityReviewResult::ADOPT, $result->action );
		self::assertMatchesRegularExpression( '/\Av1:[a-f0-9]{64}\z/D', $result->fingerprint );
		self::assertStringNotContainsString( 'target-profile', $result->fingerprint );
		$reflection = new ReflectionClass( PortabilityReviewResult::class );
		self::assertSame( 'string', (string) $reflection->getProperty( 'action' )->getType() );
		self::assertSame( 'string', (string) $reflection->getMethod( 'from_resolved' )->getParameters()[1]->getType() );
	}

	#[DataProvider( 'invalid_review_provider' )]
	public function test_review_result_rejects_install_unsafe_messages_and_malformed_fingerprints(
		string $action,
		string $message,
		string $fingerprint
	): void {
		$this->expectException( InvalidArgumentException::class );

		new PortabilityReviewResult(
			$this->candidate(),
			$action,
			TargetPackageReason::NONE->value,
			$message,
			$fingerprint
		);
	}

	/** @return iterable<string, array{string, string, string}> */
	public static function invalid_review_provider(): iterable {
		yield 'install' => array( 'install', 'Ready.', 'v1:' . str_repeat( 'a', 64 ) );
		yield 'blank message' => array( PortabilityReviewResult::ADOPT, '', 'v1:' . str_repeat( 'a', 64 ) );
		yield 'control in message' => array( PortabilityReviewResult::ADOPT, "Unsafe\nmessage", 'v1:' . str_repeat( 'a', 64 ) );
		yield 'unversioned fingerprint' => array( PortabilityReviewResult::ADOPT, 'Ready.', str_repeat( 'a', 64 ) );
		yield 'uppercase fingerprint' => array( PortabilityReviewResult::ADOPT, 'Ready.', 'v1:' . str_repeat( 'A', 64 ) );
	}

	public function test_apply_result_can_verify_only_adopted_or_exact_unchanged_targets(): void {
		$adopted = new PortabilityApplyResult(
			PortabilityApplyResult::ADOPTED,
			TargetPackageReason::NONE->value,
			'The package was adopted.',
			true
		);
		$blocked = new PortabilityApplyResult(
			PortabilityApplyResult::BLOCKED,
			TargetPackageReason::PROVIDER_UNAVAILABLE->value,
			'The provider is unavailable.',
			false
		);

		self::assertTrue( $adopted->target_verified );
		self::assertFalse( $blocked->target_verified );

		foreach (
			array(
				array( PortabilityApplyResult::ADOPTED, false ),
				array( PortabilityApplyResult::UNCHANGED, false ),
				array( PortabilityApplyResult::BLOCKED, true ),
				array( PortabilityApplyResult::FAILED, true ),
			) as [ $status, $verified ]
		) {
			try {
				new PortabilityApplyResult( $status, 'unexpected_failure', 'The target was not verified.', $verified );
				self::fail( 'Invalid target verification state was accepted.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_facade_surface_has_no_lifecycle_or_generic_payload_operations(): void {
		$reflection = new ReflectionClass( PortabilityFacade::class );
		$methods    = array_map(
			static fn ( \ReflectionMethod $method ): string => $method->name,
			array_filter( $reflection->getMethods(), static fn ( \ReflectionMethod $method ): bool => $method->isPublic() )
		);
		sort( $methods );

		self::assertSame( 4, PortabilityFacade::API_VERSION );
		self::assertSame( array( 'apply', 'nonce_action', 'review' ), $methods );
		self::assertFalse( $reflection->hasMethod( 'prepare' ) );
		self::assertFalse( $reflection->hasMethod( 'cancel' ) );
	}

	public function test_nonce_scopes_bind_only_digests_and_the_expected_review(): void {
		$facade      = $this->facade();
		$candidate   = $this->candidate();
		$fingerprint = 'v1:' . str_repeat( 'a', 64 );
		$review      = $facade->nonce_action( 'review', $candidate, null, null );
		$apply       = $facade->nonce_action( 'apply', $candidate, $fingerprint, $this->expectation() );

		self::assertMatchesRegularExpression( '/\Aran-booster-portability-review-v1-[a-f0-9]{64}\z/D', $review );
		self::assertMatchesRegularExpression( '/\Aran-booster-portability-apply-v1-[a-f0-9]{64}-[a-f0-9]{64}\z/D', $apply );
		self::assertNotSame( $review, $apply );
		self::assertStringNotContainsString( 'owner/example', $apply );
		self::assertNotSame( $review, $facade->nonce_action( 'review', $this->candidate( array( 'branch' => 'develop' ) ), null, null ) );

		foreach (
			array(
				array( 'review', $fingerprint ),
				array( 'apply', null ),
				array( 'apply', str_repeat( 'a', 64 ) ),
				array( 'delete', null ),
			) as [ $operation, $expected ]
		) {
			try {
				$facade->nonce_action( $operation, $candidate, $expected, $this->expectation() );
				self::fail( 'Invalid nonce scope was accepted.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_review_fingerprint_changes_with_every_authority_input(): void {
		$base        = $this->candidate();
		$fingerprint = $this->review( $base )->fingerprint;
		$changes     = array(
			$this->candidate(
				array(
					'type'       => 'theme',
					'identifier' => 'example',
				)
			),
			$this->candidate( array( 'identifier' => 'other/other.php' ) ),
			$this->candidate( array( 'display_name' => 'Other' ) ),
			$this->candidate( array( 'provider_code' => 'gitlab' ) ),
			$this->candidate( array( 'repository' => 'owner/other' ) ),
			$this->candidate( array( 'branch' => 'develop' ) ),
			$this->candidate( array( 'subdirectory' => 'plugin' ) ),
			$this->candidate( array( 'credential_id' => 'other-profile' ) ),
		);

		foreach ( $changes as $candidate ) {
			self::assertNotSame( $fingerprint, $this->review( $candidate )->fingerprint );
		}
		self::assertNotSame( $fingerprint, $this->review( $base, 'other-id' )->fingerprint );
		self::assertNotSame( $fingerprint, $this->review( $base, 'repository-id', false )->fingerprint );
		self::assertNotSame( $fingerprint, $this->review( $base, 'repository-id', true, PortabilityReviewResult::BLOCKED )->fingerprint );
		self::assertSame( $fingerprint, $this->review( $base )->fingerprint );
	}

	/** @param array<string, mixed> $overrides */
	private function candidate( array $overrides = array() ): PortabilityCandidate {
		$values = array_merge(
			array(
				'type'          => 'plugin',
				'identifier'    => 'example/example.php',
				'display_name'  => 'Example',
				'provider_code' => 'gh',
				'repository'    => 'owner/example',
				'branch'        => 'main',
				'subdirectory'  => null,
				'credential_id' => 'target-profile',
			),
			$overrides
		);

		return new PortabilityCandidate( ...$values );
	}

	private function review(
		PortabilityCandidate $candidate,
		?string $provider_repository_id = 'repository-id',
		?bool $is_private = true,
		string $action = PortabilityReviewResult::ADOPT
	): PortabilityReviewResult {
		return PortabilityReviewResult::from_resolved(
			$candidate,
			$action,
			TargetPackageReason::NONE->value,
			'Review complete.',
			$provider_repository_id,
			$is_private
		);
	}

	/** @return array{provider_code:string,selection_generation:int,implementation_fingerprint:string} */
	private function expectation(): array {
		return array(
			'provider_code'              => 'gh',
			'selection_generation'       => 0,
			'implementation_fingerprint' => str_repeat( 'a', 64 ),
		);
	}

	private function facade(): PortabilityFacade {
		return new class() extends PortabilityFacade {
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of review retains the production method contract; these inputs do not affect this controlled result.
			public function review( PortabilityCandidate $candidate, string $nonce ): PortabilityReviewResult {
				throw new \LogicException();
			}

			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of apply retains the production method contract; these inputs do not affect this controlled result.
			public function apply( PortabilityCandidate $candidate, string $expected_fingerprint, string $nonce, ?array $provider_expectation ): PortabilityApplyResult {
				throw new \LogicException();
			}
		};
	}
}
