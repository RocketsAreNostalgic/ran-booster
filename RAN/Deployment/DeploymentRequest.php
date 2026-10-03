<?php

declare(strict_types=1);

namespace RAN\Deployment;

use InvalidArgumentException;
use JsonException;
use RAN\PackageArtifactLimit;
use RAN\PackageSubdirectory;

/**
 * The closed, secret-free execution snapshot stored with an attempt.
 */
final readonly class DeploymentRequest {

	private const MAX_JSON_BYTES = 4096;

	public int $maximum_artifact_bytes;

	public function __construct(
		public string $repository,
		public ?string $credential_id,
		public bool $is_private,
		public string $configured_branch,
		public string $package_slug,
		public ?string $subdirectory,
		public DeploymentPolicy $deployment_policy,
		public ?int $initiating_user_id,
		?int $maximum_artifact_bytes = null
	) {
		self::assert_locator( $repository );
		self::assert_credential_id( $credential_id );
		self::assert_safe_text( $configured_branch, 255 );
		self::assert_package_slug( $package_slug );
		self::assert_subdirectory( $subdirectory );
		if ( null !== $initiating_user_id && $initiating_user_id < 1 ) {
			throw new InvalidArgumentException( 'The initiating user ID must be positive.' );
		}
		$this->maximum_artifact_bytes = null === $maximum_artifact_bytes
			? PackageArtifactLimit::resolve()
			: PackageArtifactLimit::require_valid( $maximum_artifact_bytes );
		if ( strlen( $this->to_json() ) > self::MAX_JSON_BYTES ) {
			throw new InvalidArgumentException( 'The deployment request is too large.' );
		}
	}

	/** @return array{repository: string, credential_id: ?string, private: bool, configured_branch: string, package_slug: string, subdirectory: ?string, deployment_policy: string, initiating_user_id: ?int, maximum_artifact_bytes: int} */
	public function to_array(): array {
		return array(
			'repository'             => $this->repository,
			'credential_id'          => $this->credential_id,
			'private'                => $this->is_private,
			'configured_branch'      => $this->configured_branch,
			'package_slug'           => $this->package_slug,
			'subdirectory'           => $this->subdirectory,
			'deployment_policy'      => $this->deployment_policy->value,
			'initiating_user_id'     => $this->initiating_user_id,
			'maximum_artifact_bytes' => $this->maximum_artifact_bytes,
		);
	}

	public function to_json(): string {
		return self::encode( $this->to_array() );
	}

	public static function from_json( string $json ): self {
		if ( '' === $json || strlen( $json ) > self::MAX_JSON_BYTES ) {
			throw new InvalidArgumentException( 'The stored deployment request is invalid.' );
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- Value object remains usable at CLI and worker boundaries.
			$data = json_decode( $json, true, 8, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained for developers and never rendered.
			throw new InvalidArgumentException( 'The stored deployment request is invalid.', 0, $exception );
		}

		$expected_keys = array(
			'repository',
			'credential_id',
			'private',
			'configured_branch',
			'package_slug',
			'subdirectory',
			'deployment_policy',
			'initiating_user_id',
			'maximum_artifact_bytes',
		);
		$keys          = is_array( $data ) ? array_keys( $data ) : array();
		if ( $keys !== $expected_keys
			|| ! is_array( $data )
			|| ! is_string( $data['repository'] )
			|| ( null !== $data['credential_id'] && ! is_string( $data['credential_id'] ) )
			|| ! is_bool( $data['private'] )
			|| ! is_string( $data['configured_branch'] )
			|| ! is_string( $data['package_slug'] )
			|| ( null !== $data['subdirectory'] && ! is_string( $data['subdirectory'] ) )
			|| ! is_string( $data['deployment_policy'] )
			|| ( null !== $data['initiating_user_id'] && ! is_int( $data['initiating_user_id'] ) )
			|| ! is_int( $data['maximum_artifact_bytes'] ) ) {
			throw new InvalidArgumentException( 'The stored deployment request is invalid.' );
		}

		$request = new self(
			$data['repository'],
			$data['credential_id'],
			$data['private'],
			$data['configured_branch'],
			$data['package_slug'],
			$data['subdirectory'],
			DeploymentPolicy::from_database( $data['deployment_policy'] ),
			$data['initiating_user_id'],
			$data['maximum_artifact_bytes']
		);
		if ( ! hash_equals( $request->to_json(), $json ) ) {
			throw new InvalidArgumentException( 'The stored deployment request is not canonical.' );
		}

		return $request;
	}

	/** @param array<string, mixed> $value */
	private static function encode( array $value ): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Value object remains usable at CLI and worker boundaries.
			return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained for developers and never rendered.
			throw new InvalidArgumentException( 'The deployment request cannot be encoded.', 0, $exception );
		}
	}

	private static function assert_locator( string $value ): void {
		self::assert_safe_text( $value, 512 );
		if ( str_starts_with( $value, '/' ) || str_contains( $value, '\\' ) || in_array( '..', explode( '/', $value ), true ) ) {
			throw new InvalidArgumentException( 'The repository locator is invalid.' );
		}
	}

	private static function assert_credential_id( ?string $value ): void {
		if ( null !== $value && preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $value ) !== 1 ) {
			throw new InvalidArgumentException( 'The credential profile ID is invalid.' );
		}
	}

	private static function assert_package_slug( string $value ): void {
		if ( preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/D', $value ) !== 1 ) {
			throw new InvalidArgumentException( 'The package slug is invalid.' );
		}
	}

	private static function assert_subdirectory( ?string $value ): void {
		if ( null === $value ) {
			return;
		}

		self::assert_safe_text( $value, 255 );
		try {
			$normalized = PackageSubdirectory::normalize( $value );
		} catch ( InvalidArgumentException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained for developers and never rendered.
			throw new InvalidArgumentException( 'The package subdirectory is invalid.', 0, $exception );
		}
		if ( null === $normalized ) {
			throw new InvalidArgumentException( 'The package subdirectory is invalid.' );
		}
	}

	private static function assert_safe_text( string $value, int $limit ): void {
		if ( '' === $value || strlen( $value ) > $limit || preg_match( '//u', $value ) !== 1
			|| preg_match( '/[[:cntrl:]]/', $value ) === 1
			|| preg_match( '/(?:https?:\/\/|[A-Za-z][A-Za-z0-9+.-]*:\/\/)[^\s]*@/i', $value ) === 1
			|| preg_match( '/\b(?:authorization|bearer|token|secret|password|signature)\b\s*[:=]/i', $value ) === 1 ) {
			throw new InvalidArgumentException( 'A deployment request field is unsafe.' );
		}
	}
}
