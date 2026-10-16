<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;

final class DeploymentRequestTest extends TestCase {

	public function test_initial_identity_round_trip_preserves_generation_and_historical_selection(): void {
		$request  = new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::DISABLED, 1, provider_selection_generation: 7, selected_commit: str_repeat( 'a', 40 ), expected_identifier: 'example/Main.php' );
		$restored = DeploymentRequest::from_json( $request->to_json() );
		self::assertSame( 'example/Main.php', $restored->expected_identifier );
		self::assertSame( 7, $restored->provider_selection_generation );
		self::assertSame( str_repeat( 'a', 40 ), $restored->selected_commit );
		self::assertNull( $restored->installed_identifier );
		self::assertNull( $restored->adoption_token );
	}

	public function test_initial_identity_rejects_noncanonical_or_wrong_slug(): void {
		foreach ( array( '', 'other/main.php', 'example/../main.php', 'example/nested/main.php', 'example/main.PHP', ' example/main.php', 'example/main.php ', 'example/main.php/evil', 'example/\\main.php' ) as $identifier ) {
			try {
				new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::MANUAL, 1, expected_identifier: $identifier );
				self::fail( 'Unsafe initial identity must refuse.' );
			} catch ( InvalidArgumentException $failure ) {
				self::assertSame( 'The expected initial package identity is invalid.', $failure->getMessage() );
			}
		}
	}

	public function test_initial_identity_cannot_replace_existing_adoption_snapshot(): void {
		$this->expectException( InvalidArgumentException::class );
		new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::MANUAL, 1, installed_identifier: 'example/main.php', adoption_token: 'gh:42:11111111-1111-4111-8111-111111111111', expected_identifier: 'example/main.php' );
	}

	public function test_canonical_request_round_trips_with_only_the_nine_allowed_keys(): void {
		$request = $this->request();

		self::assertSame(
			array( 'repository', 'credential_id', 'private', 'configured_branch', 'package_slug', 'subdirectory', 'deployment_policy', 'initiating_user_id', 'maximum_artifact_bytes' ),
			array_keys( $request->to_array() )
		);
		self::assertSame( $request->to_json(), DeploymentRequest::from_json( json: $request->to_json() )->to_json() );
		self::assertSame(
			'{"repository":"group/subgroup/package","credential_id":"profile_1","private":true,"configured_branch":"main","package_slug":"example-package","subdirectory":"wordpress/plugin","deployment_policy":"automatic","initiating_user_id":7,"maximum_artifact_bytes":52428800}',
			$request->to_json()
		);
		self::assertLessThanOrEqual( 4096, strlen( $request->to_json() ) );
	}

	public function test_pre_release_eight_key_request_shape_is_rejected(): void {
		$data = $this->request()->to_array();
		unset( $data['maximum_artifact_bytes'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The unit test exercises the runtime JSON boundary.
		$json = json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );

		$this->expectException( InvalidArgumentException::class );
		DeploymentRequest::from_json( $json );
	}

	public function test_non_canonical_or_extended_json_is_rejected(): void {
		$data          = $this->request()->to_array();
		$data['token'] = 'must-never-be-stored';

		$this->expectException( InvalidArgumentException::class );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The unit test exercises the runtime JSON boundary.
		DeploymentRequest::from_json( json_encode( $data, JSON_THROW_ON_ERROR ) );
	}

	#[DataProvider( 'unsafe_request_provider' )]
	public function test_unsafe_control_path_and_secret_material_is_rejected( callable $factory ): void {
		$this->expectException( InvalidArgumentException::class );
		$factory();
	}

	/** @return iterable<string, array{callable(): DeploymentRequest}> */
	public static function unsafe_request_provider(): iterable {
		yield 'control' => array( static fn (): DeploymentRequest => self::make( repository: "group/repo\nAuthorization: bearer" ) );
		yield 'url credential' => array( static fn (): DeploymentRequest => self::make( repository: 'https://user:pass@example.test/repo' ) );
		yield 'traversal' => array( static fn (): DeploymentRequest => self::make( subdirectory: 'package/../secret' ) );
		yield 'encoded traversal' => array( static fn (): DeploymentRequest => self::make( subdirectory: 'package/%252e%252e/secret' ) );
		yield 'absolute path' => array( static fn (): DeploymentRequest => self::make( subdirectory: '/tmp/package' ) );
		yield 'encoded drive prefix' => array( static fn (): DeploymentRequest => self::make( subdirectory: 'C%3A/packages/example' ) );
		yield 'decode depth exceeded' => array( static fn (): DeploymentRequest => self::make( subdirectory: 'packages/%' . str_repeat( '25', 8 ) . '41' ) );
		yield 'blank subdirectory' => array( static fn (): DeploymentRequest => self::make( subdirectory: '   ' ) );
		yield 'secret assignment' => array( static fn (): DeploymentRequest => self::make( configured_branch: 'token=abcdef' ) );
		yield 'oversized' => array( static fn (): DeploymentRequest => self::make( repository: str_repeat( 'a', 513 ) ) );
	}

	public function test_shared_decode_boundary_remains_durable(): void {
		$subdirectory = 'packages/%' . str_repeat( '25', 7 ) . '41';
		$request      = self::make( subdirectory: $subdirectory );

		self::assertSame( $subdirectory, $request->subdirectory );
		self::assertSame( $request->to_json(), DeploymentRequest::from_json( $request->to_json() )->to_json() );
	}

	public function test_bound_request_preserves_exact_lifetime_and_installed_identifier(): void {
		$token    = 'gh:opaque%3A%2Fid:11111111-1111-4111-8111-111111111111';
		$request  = new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::MANUAL, 1, null, 'example/main.php', $token );
		$restored = DeploymentRequest::from_json( $request->to_json() );
		self::assertSame( $token, $restored->adoption_token );
		self::assertSame( 'example/main.php', $restored->installed_identifier );
		self::assertNull( DeploymentRequest::from_json( $this->request()->to_json() )->adoption_token );
	}

	public function test_bound_empty_installed_identifier_is_rejected_at_construction(): void {
		$this->expectException( InvalidArgumentException::class );
		new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::MANUAL, 1, null, '', 'gh:42:11111111-1111-4111-8111-111111111111' );
	}

	public function test_bound_empty_installed_identifier_is_rejected_from_stored_json(): void {
		$request = new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::MANUAL, 1, null, 'example/main.php', 'gh:42:11111111-1111-4111-8111-111111111111' );
		$json    = str_replace( '"installed_identifier":"example/main.php"', '"installed_identifier":""', $request->to_json() );

		$this->expectException( InvalidArgumentException::class );
		DeploymentRequest::from_json( $json );
	}

	public function test_duplicate_lifetime_keys_are_not_canonical(): void {
		$token   = 'gh:42:11111111-1111-4111-8111-111111111111';
		$request = new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::MANUAL, 1, null, 'example/main.php', $token );
		$json    = str_replace( '"adoption_token":', '"adoption_token":"' . $token . '","adoption_token":', $request->to_json() );
		$this->expectException( InvalidArgumentException::class );
		DeploymentRequest::from_json( $json );
	}

	public function test_token_without_exact_installed_target_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new DeploymentRequest( 'owner/example', null, false, 'main', 'example', null, DeploymentPolicy::MANUAL, 1, null, null, 'gh:42:11111111-1111-4111-8111-111111111111' );
	}

	private function request(): DeploymentRequest {
		return self::make();
	}

	private static function make(
		string $repository = 'group/subgroup/package',
		string $configured_branch = 'main',
		?string $subdirectory = 'wordpress/plugin'
	): DeploymentRequest {
		return new DeploymentRequest(
			repository: $repository,
			credential_id: 'profile_1',
			is_private: true,
			configured_branch: $configured_branch,
			package_slug: 'example-package',
			subdirectory: $subdirectory,
			deployment_policy: DeploymentPolicy::AUTOMATIC,
			initiating_user_id: 7,
			maximum_artifact_bytes: 52428800
		);
	}
}
