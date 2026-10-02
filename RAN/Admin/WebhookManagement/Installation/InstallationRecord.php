<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Installation;

use InvalidArgumentException;

final readonly class InstallationRecord {
	private const UNKNOWN_HOOK_ID = 'recovery:hook-identity-unavailable';

	public function __construct(
		private string $provider_code,
		private string $repository_id,
		private string $repository,
		private string $hook_id,
		private string $management_credential_id,
		private string $webhook_profile_id,
		private string $webhook_profile_scope,
		private int $webhook_profile_revision,
		private string $webhook_profile_disposition,
		private string $endpoint,
		private string $status,
		private string $created_at,
		private string $checked_at
	) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $provider_code )
			|| '' === trim( $repository_id )
			|| '' === trim( $repository )
			|| '' === trim( $hook_id )
			|| strlen( $hook_id ) > 191
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $hook_id )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/', $management_credential_id )
			|| '' === trim( $webhook_profile_id )
			|| ! in_array( $webhook_profile_scope, array( 'owner', 'repository' ), true )
			|| $webhook_profile_revision < 1
			|| ! in_array( $webhook_profile_disposition, array( 'created', 'reused' ), true )
			|| ( 'created' === $webhook_profile_disposition && 'repository' !== $webhook_profile_scope )
			|| ! $this->valid_endpoint( $endpoint )
			|| ! in_array( $status, array( 'configured', 'needs_verification', 'orphaned', 'remote_missing', 'configuration_drift', 'local_profile_missing', 'profile_revision_stale', 'removal_pending' ), true )
			|| ! $this->valid_timestamp( $created_at )
			|| ! $this->valid_timestamp( $checked_at )
		) {
			throw new InvalidArgumentException( 'Invalid repository webhook-management installation record.' );
		}
	}

	public function provider_code(): string {
		return $this->provider_code;
	}

	public function repository_id(): string {
		return $this->repository_id;
	}

	public function repository(): string {
		return $this->repository;
	}

	public function hook_id(): string {
		return $this->hook_id;
	}

	public function requires_hook_identification(): bool {
		return hash_equals( self::UNKNOWN_HOOK_ID, $this->hook_id );
	}

	public static function unknown_hook_id(): string {
		return self::UNKNOWN_HOOK_ID;
	}

	public function management_credential_id(): string {
		return $this->management_credential_id;
	}

	public function webhook_profile_id(): string {
		return $this->webhook_profile_id;
	}

	public function webhook_profile_scope(): string {
		return $this->webhook_profile_scope;
	}

	public function webhook_profile_revision(): int {
		return $this->webhook_profile_revision;
	}

	public function webhook_profile_disposition(): string {
		return $this->webhook_profile_disposition;
	}

	public function endpoint(): string {
		return $this->endpoint;
	}

	public function status(): string {
		return $this->status;
	}

	public function checked_at(): string {
		return $this->checked_at;
	}

	public function with_check( string $status, string $checked_at, ?string $endpoint = null ): self {
		return new self( $this->provider_code, $this->repository_id, $this->repository, $this->hook_id, $this->management_credential_id, $this->webhook_profile_id, $this->webhook_profile_scope, $this->webhook_profile_revision, $this->webhook_profile_disposition, $endpoint ?? $this->endpoint, $status, $this->created_at, $checked_at );
	}

	public function with_management_credential( string $management_credential_id, string $status, string $checked_at, ?string $endpoint = null ): self {
		return new self( $this->provider_code, $this->repository_id, $this->repository, $this->hook_id, $management_credential_id, $this->webhook_profile_id, $this->webhook_profile_scope, $this->webhook_profile_revision, $this->webhook_profile_disposition, $endpoint ?? $this->endpoint, $status, $this->created_at, $checked_at );
	}

	public function with_profile( string $management_credential_id, string $profile_id, string $scope, int $revision, string $disposition, string $endpoint, string $status, string $checked_at ): self {
		return new self( $this->provider_code, $this->repository_id, $this->repository, $this->hook_id, $management_credential_id, $profile_id, $scope, $revision, $disposition, $endpoint, $status, $this->created_at, $checked_at );
	}

	public function storage_key(): string {
		return self::key( $this->provider_code, $this->repository_id );
	}

	public static function key( string $provider_code, string $repository_id ): string {
		return $provider_code . ':' . $repository_id;
	}

	/** @return array{schema_version: int, provider_code: string, repository_id: string, repository: string, hook_id: string, management_credential_id: string, webhook_profile_id: string, webhook_profile_scope: string, webhook_profile_revision: int, webhook_profile_disposition: string, endpoint: string, status: string, created_at: string, checked_at: string} */
	public function to_array(): array {
		return array(
			'schema_version'              => 4,
			'provider_code'               => $this->provider_code,
			'repository_id'               => $this->repository_id,
			'repository'                  => $this->repository,
			'hook_id'                     => $this->hook_id,
			'management_credential_id'    => $this->management_credential_id,
			'webhook_profile_id'          => $this->webhook_profile_id,
			'webhook_profile_scope'       => $this->webhook_profile_scope,
			'webhook_profile_revision'    => $this->webhook_profile_revision,
			'webhook_profile_disposition' => $this->webhook_profile_disposition,
			'endpoint'                    => $this->endpoint,
			'status'                      => $this->status,
			'created_at'                  => $this->created_at,
			'checked_at'                  => $this->checked_at,
		);
	}

	/** @param array<string, mixed> $record */
	public static function from_array( array $record ): self {
		$expected = array( 'schema_version', 'provider_code', 'repository_id', 'repository', 'hook_id', 'management_credential_id', 'webhook_profile_id', 'webhook_profile_scope', 'webhook_profile_revision', 'webhook_profile_disposition', 'endpoint', 'status', 'created_at', 'checked_at' );

		if ( count( $record ) !== count( $expected )
			|| array_diff( array_keys( $record ), $expected )
			|| 4 !== $record['schema_version']
			|| ! is_string( $record['provider_code'] )
			|| ! is_string( $record['repository_id'] )
			|| ! is_string( $record['repository'] )
			|| ! is_string( $record['hook_id'] )
			|| ! is_string( $record['management_credential_id'] )
			|| ! is_string( $record['webhook_profile_id'] )
			|| ! is_string( $record['webhook_profile_scope'] )
			|| ! is_int( $record['webhook_profile_revision'] )
			|| ! is_string( $record['webhook_profile_disposition'] )
			|| ! is_string( $record['endpoint'] )
			|| ! is_string( $record['status'] )
			|| ! is_string( $record['created_at'] )
			|| ! is_string( $record['checked_at'] )
		) {
			throw new InvalidArgumentException( 'Invalid persisted repository webhook-management installation record.' );
		}

		return new self( $record['provider_code'], $record['repository_id'], $record['repository'], $record['hook_id'], $record['management_credential_id'], $record['webhook_profile_id'], $record['webhook_profile_scope'], $record['webhook_profile_revision'], $record['webhook_profile_disposition'], $record['endpoint'], $record['status'], $record['created_at'], $record['checked_at'] );
	}

	private function valid_endpoint( string $endpoint ): bool {
		$parts = parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Byte-compatible schema validation is shared with the retirement release.

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? null ) || ! is_string( $parts['host'] ?? null ) || '' === $parts['host'] || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		return ! in_array( $host, array( 'localhost', '::1' ), true ) && ! str_ends_with( $host, '.local' ) && ( ! filter_var( $host, FILTER_VALIDATE_IP ) || false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) );
	}

	private function valid_timestamp( string $timestamp ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $timestamp );
	}
}
