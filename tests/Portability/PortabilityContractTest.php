<?php

declare(strict_types=1);

namespace Tests\Portability;

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

	public function testCandidateProjectsOnlyBoundedTargetFields(): void {
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
			$candidate->toArray()
		);
		self::assertSame(
			array( 'type', 'identifier', 'displayName', 'providerCode', 'repository', 'branch', 'subdirectory', 'credentialId' ),
			array_keys( get_object_vars( $candidate ) )
		);
	}

	/** @param array<string, mixed> $overrides */
	#[DataProvider( 'invalidCandidateProvider' )]
	public function testCandidateRejectsNonCanonicalOrUnboundedInput( array $overrides ): void {
		$this->expectException( InvalidArgumentException::class );

		$this->candidate( $overrides );
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function invalidCandidateProvider(): iterable {
		yield 'operation-shaped type' => array( array( 'type' => 'install-plugin' ) );
		yield 'plugin without basename' => array( array( 'identifier' => 'example' ) );
		yield 'theme with plugin path' => array(
			array(
				'type'       => 'theme',
				'identifier' => 'example/example.php',
			),
		);
		yield 'blank display name' => array( array( 'displayName' => '' ) );
		yield 'display control' => array( array( 'displayName' => "Example\nPackage" ) );
		yield 'reserved provider' => array( array( 'providerCode' => 'portability' ) );
		yield 'repository credential' => array( array( 'repository' => 'https://token@example.test/owner/repository' ) );
		yield 'repository query' => array( array( 'repository' => 'https://example.test/owner/repository?token=secret' ) );
		yield 'blank branch' => array( array( 'branch' => '' ) );
		yield 'noncanonical subdirectory' => array( array( 'subdirectory' => 'plugin/' ) );
		yield 'short credential id' => array( array( 'credentialId' => 'ab' ) );
		yield 'credential control' => array( array( 'credentialId' => "target\nprofile" ) );
		yield 'long credential id' => array( array( 'credentialId' => str_repeat( 'a', 65 ) ) );
	}

	public function testReviewResultIsClosedAndVersionFingerprintBound(): void {
		$result = PortabilityReviewResult::fromResolved(
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
		self::assertSame( 'string', (string) $reflection->getMethod( 'fromResolved' )->getParameters()[1]->getType() );
	}

	#[DataProvider( 'invalidReviewProvider' )]
	public function testReviewResultRejectsInstallUnsafeMessagesAndMalformedFingerprints(
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
	public static function invalidReviewProvider(): iterable {
		yield 'install' => array( 'install', 'Ready.', 'v1:' . str_repeat( 'a', 64 ) );
		yield 'blank message' => array( PortabilityReviewResult::ADOPT, '', 'v1:' . str_repeat( 'a', 64 ) );
		yield 'control in message' => array( PortabilityReviewResult::ADOPT, "Unsafe\nmessage", 'v1:' . str_repeat( 'a', 64 ) );
		yield 'unversioned fingerprint' => array( PortabilityReviewResult::ADOPT, 'Ready.', str_repeat( 'a', 64 ) );
		yield 'uppercase fingerprint' => array( PortabilityReviewResult::ADOPT, 'Ready.', 'v1:' . str_repeat( 'A', 64 ) );
	}

	public function testApplyResultCanVerifyOnlyAdoptedOrExactUnchangedTargets(): void {
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

		self::assertTrue( $adopted->targetVerified );
		self::assertFalse( $blocked->targetVerified );

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

	public function testFacadeSurfaceHasNoLifecycleOrGenericPayloadOperations(): void {
		$reflection = new ReflectionClass( PortabilityFacade::class );
		$methods    = array_map(
			static fn ( \ReflectionMethod $method ): string => $method->name,
			array_filter( $reflection->getMethods(), static fn ( \ReflectionMethod $method ): bool => $method->isPublic() )
		);
		sort( $methods );

		self::assertSame( 2, PortabilityFacade::API_VERSION );
		self::assertSame( array( 'apply', 'nonceAction', 'review' ), $methods );
		self::assertFalse( $reflection->hasMethod( 'prepare' ) );
		self::assertFalse( $reflection->hasMethod( 'cancel' ) );
	}

	public function testNonceScopesBindOnlyDigestsAndTheExpectedReview(): void {
		$facade      = $this->facade();
		$candidate   = $this->candidate();
		$fingerprint = 'v1:' . str_repeat( 'a', 64 );
		$review      = $facade->nonceAction( 'review', $candidate );
		$apply       = $facade->nonceAction( 'apply', $candidate, $fingerprint );

		self::assertMatchesRegularExpression( '/\Aran-booster-portability-review-v1-[a-f0-9]{64}\z/D', $review );
		self::assertMatchesRegularExpression( '/\Aran-booster-portability-apply-v1-[a-f0-9]{64}-[a-f0-9]{64}\z/D', $apply );
		self::assertNotSame( $review, $apply );
		self::assertStringNotContainsString( 'owner/example', $apply );
		self::assertNotSame( $review, $facade->nonceAction( 'review', $this->candidate( array( 'branch' => 'develop' ) ) ) );

		foreach (
			array(
				array( 'review', $fingerprint ),
				array( 'apply', null ),
				array( 'apply', str_repeat( 'a', 64 ) ),
				array( 'delete', null ),
			) as [ $operation, $expected ]
		) {
			try {
				$facade->nonceAction( $operation, $candidate, $expected );
				self::fail( 'Invalid nonce scope was accepted.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function testReviewFingerprintChangesWithEveryAuthorityInput(): void {
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
			$this->candidate( array( 'displayName' => 'Other' ) ),
			$this->candidate( array( 'providerCode' => 'gitlab' ) ),
			$this->candidate( array( 'repository' => 'owner/other' ) ),
			$this->candidate( array( 'branch' => 'develop' ) ),
			$this->candidate( array( 'subdirectory' => 'plugin' ) ),
			$this->candidate( array( 'credentialId' => 'other-profile' ) ),
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
				'type'         => 'plugin',
				'identifier'   => 'example/example.php',
				'displayName'  => 'Example',
				'providerCode' => 'gh',
				'repository'   => 'owner/example',
				'branch'       => 'main',
				'subdirectory' => null,
				'credentialId' => 'target-profile',
			),
			$overrides
		);

		return new PortabilityCandidate( ...$values );
	}

	private function review(
		PortabilityCandidate $candidate,
		?string $providerRepositoryId = 'repository-id',
		?bool $private = true,
		string $action = PortabilityReviewResult::ADOPT
	): PortabilityReviewResult {
		return PortabilityReviewResult::fromResolved(
			$candidate,
			$action,
			TargetPackageReason::NONE->value,
			'Review complete.',
			$providerRepositoryId,
			$private
		);
	}

	private function facade(): PortabilityFacade {
		return new class() extends PortabilityFacade {
			public function review( PortabilityCandidate $candidate, string $nonce ): PortabilityReviewResult {
				throw new \LogicException();
			}

			public function apply( PortabilityCandidate $candidate, string $expectedFingerprint, string $nonce ): PortabilityApplyResult {
				throw new \LogicException();
			}
		};
	}
}
