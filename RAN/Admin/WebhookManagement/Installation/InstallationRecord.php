<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Installation;

use InvalidArgumentException;

final readonly class InstallationRecord {
	private const UNKNOWN_HOOK_ID = 'recovery:hook-identity-unavailable';

	public function __construct(
		private string $providerCode,
		private string $repositoryId,
		private string $repository,
		private string $hookId,
		private string $managementCredentialId,
		private string $webhookProfileId,
		private string $webhookProfileScope,
		private int $webhookProfileRevision,
		private string $webhookProfileDisposition,
		private string $endpoint,
		private string $status,
		private string $createdAt,
		private string $checkedAt
	) {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $providerCode )
			|| '' === trim( $repositoryId )
			|| '' === trim( $repository )
			|| '' === trim( $hookId )
			|| strlen( $hookId ) > 191
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $hookId )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/', $managementCredentialId )
			|| '' === trim( $webhookProfileId )
			|| ! in_array( $webhookProfileScope, array( 'owner', 'repository' ), true )
			|| $webhookProfileRevision < 1
			|| ! in_array( $webhookProfileDisposition, array( 'created', 'reused' ), true )
			|| ( 'created' === $webhookProfileDisposition && 'repository' !== $webhookProfileScope )
			|| ! $this->validEndpoint( $endpoint )
			|| ! in_array( $status, array( 'configured', 'needs_verification', 'orphaned', 'remote_missing', 'configuration_drift', 'local_profile_missing', 'profile_revision_stale', 'removal_pending' ), true )
			|| ! $this->validTimestamp( $createdAt )
			|| ! $this->validTimestamp( $checkedAt )
		) {
			throw new InvalidArgumentException( 'Invalid repository webhook-management installation record.' );
		}
	}

	public function providerCode(): string {
		return $this->providerCode;
	}

	public function repositoryId(): string {
		return $this->repositoryId;
	}

	public function repository(): string {
		return $this->repository;
	}

	public function hookId(): string {
		return $this->hookId;
	}

	public function requiresHookIdentification(): bool {
		return hash_equals( self::UNKNOWN_HOOK_ID, $this->hookId );
	}

	public static function unknownHookId(): string {
		return self::UNKNOWN_HOOK_ID;
	}

	public function managementCredentialId(): string {
		return $this->managementCredentialId;
	}

	public function webhookProfileId(): string {
		return $this->webhookProfileId;
	}

	public function webhookProfileScope(): string {
		return $this->webhookProfileScope;
	}

	public function webhookProfileRevision(): int {
		return $this->webhookProfileRevision;
	}

	public function webhookProfileDisposition(): string {
		return $this->webhookProfileDisposition;
	}

	public function endpoint(): string {
		return $this->endpoint;
	}

	public function status(): string {
		return $this->status;
	}

	public function checkedAt(): string {
		return $this->checkedAt;
	}

	public function withCheck( string $status, string $checkedAt, ?string $endpoint = null ): self {
		return new self( $this->providerCode, $this->repositoryId, $this->repository, $this->hookId, $this->managementCredentialId, $this->webhookProfileId, $this->webhookProfileScope, $this->webhookProfileRevision, $this->webhookProfileDisposition, $endpoint ?? $this->endpoint, $status, $this->createdAt, $checkedAt );
	}

	public function withManagementCredential( string $managementCredentialId, string $status, string $checkedAt, ?string $endpoint = null ): self {
		return new self( $this->providerCode, $this->repositoryId, $this->repository, $this->hookId, $managementCredentialId, $this->webhookProfileId, $this->webhookProfileScope, $this->webhookProfileRevision, $this->webhookProfileDisposition, $endpoint ?? $this->endpoint, $status, $this->createdAt, $checkedAt );
	}

	public function withProfile( string $managementCredentialId, string $profileId, string $scope, int $revision, string $disposition, string $endpoint, string $status, string $checkedAt ): self {
		return new self( $this->providerCode, $this->repositoryId, $this->repository, $this->hookId, $managementCredentialId, $profileId, $scope, $revision, $disposition, $endpoint, $status, $this->createdAt, $checkedAt );
	}

	public function storageKey(): string {
		return self::key( $this->providerCode, $this->repositoryId );
	}

	public static function key( string $providerCode, string $repositoryId ): string {
		return $providerCode . ':' . $repositoryId;
	}

	/** @return array{schema_version: int, provider_code: string, repository_id: string, repository: string, hook_id: string, management_credential_id: string, webhook_profile_id: string, webhook_profile_scope: string, webhook_profile_revision: int, webhook_profile_disposition: string, endpoint: string, status: string, created_at: string, checked_at: string} */
	public function toArray(): array {
		return array(
			'schema_version'              => 4,
			'provider_code'               => $this->providerCode,
			'repository_id'               => $this->repositoryId,
			'repository'                  => $this->repository,
			'hook_id'                     => $this->hookId,
			'management_credential_id'    => $this->managementCredentialId,
			'webhook_profile_id'          => $this->webhookProfileId,
			'webhook_profile_scope'       => $this->webhookProfileScope,
			'webhook_profile_revision'    => $this->webhookProfileRevision,
			'webhook_profile_disposition' => $this->webhookProfileDisposition,
			'endpoint'                    => $this->endpoint,
			'status'                      => $this->status,
			'created_at'                  => $this->createdAt,
			'checked_at'                  => $this->checkedAt,
		);
	}

	/** @param array<string, mixed> $record */
	public static function fromArray( array $record ): self {
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

	private function validEndpoint( string $endpoint ): bool {
		$parts = parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Byte-compatible schema validation is shared with the retirement release.

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? null ) || ! is_string( $parts['host'] ?? null ) || '' === $parts['host'] || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		return ! in_array( $host, array( 'localhost', '::1' ), true ) && ! str_ends_with( $host, '.local' ) && ( ! filter_var( $host, FILTER_VALIDATE_IP ) || false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) );
	}

	private function validTimestamp( string $timestamp ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $timestamp );
	}
}
