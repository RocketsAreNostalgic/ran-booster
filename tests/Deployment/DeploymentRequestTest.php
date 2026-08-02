<?php

declare(strict_types=1);

namespace Tests\Deployment;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;

final class DeploymentRequestTest extends TestCase {

	public function testCanonicalRequestRoundTripsWithOnlyTheEightAllowedKeys(): void {
		$request = $this->request();

		self::assertSame(
			array( 'repository', 'credential_id', 'private', 'configured_branch', 'package_slug', 'subdirectory', 'deployment_policy', 'initiating_user_id' ),
			array_keys( $request->toArray() )
		);
		self::assertSame( $request->toJson(), DeploymentRequest::fromJson( $request->toJson() )->toJson() );
		self::assertLessThanOrEqual( 4096, strlen( $request->toJson() ) );
	}

	public function testNonCanonicalOrExtendedJsonIsRejected(): void {
		$data          = $this->request()->toArray();
		$data['token'] = 'must-never-be-stored';

		$this->expectException( InvalidArgumentException::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The unit test exercises the runtime JSON boundary.
		DeploymentRequest::fromJson( json_encode( $data, JSON_THROW_ON_ERROR ) );
	}

	#[DataProvider( 'unsafeRequestProvider' )]
	public function testUnsafeControlPathAndSecretMaterialIsRejected( callable $factory ): void {
		$this->expectException( InvalidArgumentException::class );
		$factory();
	}

	/** @return iterable<string, array{callable(): DeploymentRequest}> */
	public static function unsafeRequestProvider(): iterable {
		yield 'control' => array( static fn (): DeploymentRequest => self::make( repository: "group/repo\nAuthorization: bearer" ) );
		yield 'url credential' => array( static fn (): DeploymentRequest => self::make( repository: 'https://user:pass@example.test/repo' ) );
		yield 'traversal' => array( static fn (): DeploymentRequest => self::make( subdirectory: 'package/../secret' ) );
		yield 'absolute path' => array( static fn (): DeploymentRequest => self::make( subdirectory: '/tmp/package' ) );
		yield 'secret assignment' => array( static fn (): DeploymentRequest => self::make( configuredBranch: 'token=abcdef' ) );
		yield 'oversized' => array( static fn (): DeploymentRequest => self::make( repository: str_repeat( 'a', 513 ) ) );
	}

	private function request(): DeploymentRequest {
		return self::make();
	}

	private static function make(
		string $repository = 'group/subgroup/package',
		string $configuredBranch = 'main',
		?string $subdirectory = 'wordpress/plugin'
	): DeploymentRequest {
		return new DeploymentRequest(
			$repository,
			'profile_1',
			true,
			$configuredBranch,
			'example-package',
			$subdirectory,
			DeploymentPolicy::AUTOMATIC,
			7
		);
	}
}
