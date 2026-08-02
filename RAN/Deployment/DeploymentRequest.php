<?php

declare(strict_types=1);

namespace RAN\Deployment;

use InvalidArgumentException;
use JsonException;

/**
 * The closed, secret-free execution snapshot stored with an attempt.
 */
final readonly class DeploymentRequest {

	private const MAX_JSON_BYTES = 4096;

	public function __construct(
		public string $repository,
		public ?string $credentialId,
		public bool $private,
		public string $configuredBranch,
		public string $packageSlug,
		public ?string $subdirectory,
		public DeploymentPolicy $deploymentPolicy,
		public ?int $initiatingUserId
	) {
		self::assertLocator( $repository );
		self::assertCredentialId( $credentialId );
		self::assertSafeText( $configuredBranch, 255 );
		self::assertPackageSlug( $packageSlug );
		self::assertSubdirectory( $subdirectory );
		if ( null !== $initiatingUserId && $initiatingUserId < 1 ) {
			throw new InvalidArgumentException( 'The initiating user ID must be positive.' );
		}
		if ( strlen( $this->toJson() ) > self::MAX_JSON_BYTES ) {
			throw new InvalidArgumentException( 'The deployment request is too large.' );
		}
	}

	/** @return array{repository: string, credential_id: ?string, private: bool, configured_branch: string, package_slug: string, subdirectory: ?string, deployment_policy: string, initiating_user_id: ?int} */
	public function toArray(): array {
		return array(
			'repository'         => $this->repository,
			'credential_id'      => $this->credentialId,
			'private'            => $this->private,
			'configured_branch'  => $this->configuredBranch,
			'package_slug'       => $this->packageSlug,
			'subdirectory'       => $this->subdirectory,
			'deployment_policy'  => $this->deploymentPolicy->value,
			'initiating_user_id' => $this->initiatingUserId,
		);
	}

	public function toJson(): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Value object remains usable at CLI and worker boundaries.
			return json_encode( $this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( JsonException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Chained for developers and never rendered.
			throw new InvalidArgumentException( 'The deployment request cannot be encoded.', 0, $exception );
		}
	}

	public static function fromJson( string $json ): self {
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

		$keys = array(
			'repository',
			'credential_id',
			'private',
			'configured_branch',
			'package_slug',
			'subdirectory',
			'deployment_policy',
			'initiating_user_id',
		);
		if ( ! is_array( $data ) || array_keys( $data ) !== $keys
			|| ! is_string( $data['repository'] )
			|| ( null !== $data['credential_id'] && ! is_string( $data['credential_id'] ) )
			|| ! is_bool( $data['private'] )
			|| ! is_string( $data['configured_branch'] )
			|| ! is_string( $data['package_slug'] )
			|| ( null !== $data['subdirectory'] && ! is_string( $data['subdirectory'] ) )
			|| ! is_string( $data['deployment_policy'] )
			|| ( null !== $data['initiating_user_id'] && ! is_int( $data['initiating_user_id'] ) ) ) {
			throw new InvalidArgumentException( 'The stored deployment request is invalid.' );
		}

		$request = new self(
			$data['repository'],
			$data['credential_id'],
			$data['private'],
			$data['configured_branch'],
			$data['package_slug'],
			$data['subdirectory'],
			DeploymentPolicy::fromDatabase( $data['deployment_policy'] ),
			$data['initiating_user_id']
		);
		if ( ! hash_equals( $request->toJson(), $json ) ) {
			throw new InvalidArgumentException( 'The stored deployment request is not canonical.' );
		}

		return $request;
	}

	private static function assertLocator( string $value ): void {
		self::assertSafeText( $value, 512 );
		if ( str_starts_with( $value, '/' ) || str_contains( $value, '\\' ) || in_array( '..', explode( '/', $value ), true ) ) {
			throw new InvalidArgumentException( 'The repository locator is invalid.' );
		}
	}

	private static function assertCredentialId( ?string $value ): void {
		if ( null !== $value && preg_match( '/^[A-Za-z0-9_-]{3,64}$/D', $value ) !== 1 ) {
			throw new InvalidArgumentException( 'The credential profile ID is invalid.' );
		}
	}

	private static function assertPackageSlug( string $value ): void {
		if ( preg_match( '/^[a-z0-9][a-z0-9._-]{0,190}$/D', $value ) !== 1 ) {
			throw new InvalidArgumentException( 'The package slug is invalid.' );
		}
	}

	private static function assertSubdirectory( ?string $value ): void {
		if ( null === $value ) {
			return;
		}
		self::assertSafeText( $value, 255 );
		if ( str_starts_with( $value, '/' ) || str_contains( $value, '\\' ) || in_array( '..', explode( '/', $value ), true ) ) {
			throw new InvalidArgumentException( 'The package subdirectory is invalid.' );
		}
	}

	private static function assertSafeText( string $value, int $limit ): void {
		if ( '' === $value || strlen( $value ) > $limit || preg_match( '//u', $value ) !== 1
			|| preg_match( '/[[:cntrl:]]/', $value ) === 1
			|| preg_match( '/(?:https?:\/\/|[A-Za-z][A-Za-z0-9+.-]*:\/\/)[^\s]*@/i', $value ) === 1
			|| preg_match( '/\b(?:authorization|bearer|token|secret|password|signature)\b\s*[:=]/i', $value ) === 1 ) {
			throw new InvalidArgumentException( 'A deployment request field is unsafe.' );
		}
	}
}
