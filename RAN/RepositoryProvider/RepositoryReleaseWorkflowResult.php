<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Bounded, secret-free outcome of one provider workflow operation. */
final readonly class RepositoryReleaseWorkflowResult {
	public function __construct(
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $workflowCode,
		private bool $successful,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $previewKey = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $failureStage = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $diagnosticCode = '',
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		private string $correlationReference = '',
		private string $message = '',
		private string $remediation = ''
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		if ( 1 !== preg_match( '/\Aworkflow_[a-z0-9_]{1,55}\z/D', $this->workflowCode )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ( '' !== $this->previewKey && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $this->previewKey ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ( '' !== $this->correlationReference && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $this->correlationReference ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ( $this->successful && '' !== $this->failureStage )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ( '' !== $this->failureStage && ! in_array( $this->failureStage, array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) )
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
			|| ! $this->text( $this->failureStage, 64 ) || ! $this->text( $this->diagnosticCode, 96 )
			|| ! $this->optional_text( $this->message, 512 ) || ! $this->optional_text( $this->remediation, 512 ) ) {
			throw new InvalidArgumentException( 'Release workflow result is invalid.' );
		}
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function workflowCode(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->workflowCode; }
	public function successful(): bool {
		return $this->successful; }
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function previewKey(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->previewKey; }
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function failureStage(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->failureStage; }
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function diagnosticCode(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->diagnosticCode; }
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
	public function correlationReference(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Public workflow DTO preserves established accessors and promoted named-argument properties.
		return $this->correlationReference; }
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
