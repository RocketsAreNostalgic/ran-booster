<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Bounded, secret-free outcome of one provider workflow operation. */
final readonly class RepositoryReleaseWorkflowResult {
	public function __construct(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $workflow_code,
		private bool $successful,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $preview_key = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $failure_stage = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $diagnostic_code = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $correlation_reference = '',
		private string $message = '',
		private string $remediation = ''
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		if ( 1 !== preg_match( '/\Aworkflow_[a-z0-9_]{1,55}\z/D', $this->workflow_code )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ( '' !== $this->preview_key && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $this->preview_key ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ( '' !== $this->correlation_reference && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $this->correlation_reference ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ( $this->successful && '' !== $this->failure_stage )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ( '' !== $this->failure_stage && ! in_array( $this->failure_stage, array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ! $this->text( $this->failure_stage, 64 ) || ! $this->text( $this->diagnostic_code, 96 )
			|| ! $this->optional_text( $this->message, 512 ) || ! $this->optional_text( $this->remediation, 512 ) ) {
			throw new InvalidArgumentException( 'Release workflow result is invalid.' );
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function workflow_code(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->workflow_code; }
	public function successful(): bool {
		return $this->successful; }
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function preview_key(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->preview_key; }
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function failure_stage(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->failure_stage; }
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function diagnostic_code(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->diagnostic_code; }
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function correlation_reference(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->correlation_reference; }
	public function message(): string {
		return $this->message; }
	public function remediation(): string {
		return $this->remediation; }

	private function text( string $value, int $limit ): bool {
		return strlen( $value ) <= $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[<>\x00-\x1F\x7F]/', $value );
	}

	private function optional_text( string $value, int $limit ): bool {
		return ( '' === $value || '' !== trim( $value ) ) && $this->text( $value, $limit );
	}
}
