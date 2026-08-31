<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Bounded, secret-free outcome of one provider workflow operation. */
final readonly class RepositoryReleaseWorkflowResult {
	public function __construct(
		private string $workflowCode,
		private bool $successful,
		private string $previewKey = '',
		private string $failureStage = '',
		private string $diagnosticCode = '',
		private string $correlationReference = '',
		private string $message = '',
		private string $remediation = ''
	) {
		if ( 1 !== preg_match( '/\Aworkflow_[a-z0-9_]{1,55}\z/D', $this->workflowCode )
			|| ( '' !== $this->previewKey && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $this->previewKey ) )
			|| ( '' !== $this->correlationReference && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $this->correlationReference ) )
			|| ( $this->successful && '' !== $this->failureStage )
			|| ( '' !== $this->failureStage && ! in_array( $this->failureStage, array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) )
			|| ! $this->text( $this->failureStage, 64 ) || ! $this->text( $this->diagnosticCode, 96 )
			|| ! $this->optionalText( $this->message, 512 ) || ! $this->optionalText( $this->remediation, 512 ) ) {
			throw new InvalidArgumentException( 'Release workflow result is invalid.' );
		}
	}

	public function workflowCode(): string {
		return $this->workflowCode; }
	public function successful(): bool {
		return $this->successful; }
	public function previewKey(): string {
		return $this->previewKey; }
	public function failureStage(): string {
		return $this->failureStage; }
	public function diagnosticCode(): string {
		return $this->diagnosticCode; }
	public function correlationReference(): string {
		return $this->correlationReference; }
	public function message(): string {
		return $this->message; }
	public function remediation(): string {
		return $this->remediation; }

	private function text( string $value, int $limit ): bool {
		return strlen( $value ) <= $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[<>\x00-\x1F\x7F]/', $value );
	}

	private function optionalText( string $value, int $limit ): bool {
		return ( '' === $value || '' !== trim( $value ) ) && $this->text( $value, $limit );
	}
}
