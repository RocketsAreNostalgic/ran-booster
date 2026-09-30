<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Installation;

use InvalidArgumentException;

final readonly class InstallationRecord {
	private const UNKNOWN_HOOK_ID = 'recovery:hook-identity-unavailable';

	public function __construct(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $providerCode,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $repositoryId,
		private string $repository,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $hookId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $managementCredentialId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $webhookProfileId,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $webhookProfileScope,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private int $webhookProfileRevision,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $webhookProfileDisposition,
		private string $endpoint,
		private string $status,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $createdAt,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		private string $checkedAt
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $providerCode )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| '' === trim( $repositoryId )
			|| '' === trim( $repository )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| '' === trim( $hookId )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| strlen( $hookId ) > 191
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $hookId )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/', $managementCredentialId )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| '' === trim( $webhookProfileId )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| ! in_array( $webhookProfileScope, array( 'owner', 'repository' ), true )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| $webhookProfileRevision < 1
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| ! in_array( $webhookProfileDisposition, array( 'created', 'reused' ), true )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| ( 'created' === $webhookProfileDisposition && 'repository' !== $webhookProfileScope )
			|| ! $this->valid_endpoint( $endpoint )
			|| ! in_array( $status, array( 'configured', 'needs_verification', 'orphaned', 'remote_missing', 'configuration_drift', 'local_profile_missing', 'profile_revision_stale', 'removal_pending' ), true )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| ! $this->valid_timestamp( $createdAt )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
			|| ! $this->valid_timestamp( $checkedAt )
		) {
			throw new InvalidArgumentException( 'Invalid repository webhook-management installation record.' );
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function providerCode(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->providerCode;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function repositoryId(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->repositoryId;
	}

	public function repository(): string {
		return $this->repository;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function hookId(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->hookId;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function requiresHookIdentification(): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return hash_equals( self::UNKNOWN_HOOK_ID, $this->hookId );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public static function unknownHookId(): string {
		return self::UNKNOWN_HOOK_ID;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function managementCredentialId(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->managementCredentialId;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function webhookProfileId(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->webhookProfileId;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function webhookProfileScope(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->webhookProfileScope;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function webhookProfileRevision(): int {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->webhookProfileRevision;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function webhookProfileDisposition(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->webhookProfileDisposition;
	}

	public function endpoint(): string {
		return $this->endpoint;
	}

	public function status(): string {
		return $this->status;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function checkedAt(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return $this->checkedAt;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public/protected caller contract; retain public named-parameter names.
	public function withCheck( string $status, string $checkedAt, ?string $endpoint = null ): self {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names; retain promoted constructor properties.
		return new self( $this->providerCode, $this->repositoryId, $this->repository, $this->hookId, $this->managementCredentialId, $this->webhookProfileId, $this->webhookProfileScope, $this->webhookProfileRevision, $this->webhookProfileDisposition, $endpoint ?? $this->endpoint, $status, $this->createdAt, $checkedAt );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public/protected caller contract; retain public named-parameter names.
	public function withManagementCredential( string $managementCredentialId, string $status, string $checkedAt, ?string $endpoint = null ): self {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names; retain promoted constructor properties.
		return new self( $this->providerCode, $this->repositoryId, $this->repository, $this->hookId, $managementCredentialId, $this->webhookProfileId, $this->webhookProfileScope, $this->webhookProfileRevision, $this->webhookProfileDisposition, $endpoint ?? $this->endpoint, $status, $this->createdAt, $checkedAt );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public/protected caller contract; retain public named-parameter names.
	public function withProfile( string $managementCredentialId, string $profileId, string $scope, int $revision, string $disposition, string $endpoint, string $status, string $checkedAt ): self {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names; retain promoted constructor properties.
		return new self( $this->providerCode, $this->repositoryId, $this->repository, $this->hookId, $managementCredentialId, $profileId, $scope, $revision, $disposition, $endpoint, $status, $this->createdAt, $checkedAt );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function storageKey(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
		return self::key( $this->providerCode, $this->repositoryId );
	}

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
	public static function key( string $providerCode, string $repositoryId ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- retain public named-parameter names.
		return $providerCode . ':' . $repositoryId;
	}

	/** @return array{schema_version: int, provider_code: string, repository_id: string, repository: string, hook_id: string, management_credential_id: string, webhook_profile_id: string, webhook_profile_scope: string, webhook_profile_revision: int, webhook_profile_disposition: string, endpoint: string, status: string, created_at: string, checked_at: string} */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
	public function toArray(): array {
		return array(
			'schema_version'              => 4,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'provider_code'               => $this->providerCode,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'repository_id'               => $this->repositoryId,
			'repository'                  => $this->repository,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'hook_id'                     => $this->hookId,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'management_credential_id'    => $this->managementCredentialId,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'webhook_profile_id'          => $this->webhookProfileId,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'webhook_profile_scope'       => $this->webhookProfileScope,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'webhook_profile_revision'    => $this->webhookProfileRevision,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'webhook_profile_disposition' => $this->webhookProfileDisposition,
			'endpoint'                    => $this->endpoint,
			'status'                      => $this->status,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'created_at'                  => $this->createdAt,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- retain promoted constructor properties.
			'checked_at'                  => $this->checkedAt,
		);
	}

	/** @param array<string, mixed> $record */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Retain the public/protected caller contract.
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
