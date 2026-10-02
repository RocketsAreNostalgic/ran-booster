<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Bounded, secret-free outcome of one provider workflow operation. */
final readonly class RepositoryReleaseWorkflowResult {
	public function __construct(
		private string $workflow_code,
		private bool $successful,
		private string $preview_key = '',
		private string $failure_stage = '',
		private string $diagnostic_code = '',
		private string $correlation_reference = '',
		private string $message = '',
		private string $remediation = ''
	) {
		if ( 1 !== preg_match( '/\Aworkflow_[a-z0-9_]{1,55}\z/D', $this->workflow_code )
			|| ( '' !== $this->preview_key && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $this->preview_key ) )
			|| ( '' !== $this->correlation_reference && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $this->correlation_reference ) )
			|| ( $this->successful && '' !== $this->failure_stage )
			|| ( '' !== $this->failure_stage && ! in_array( $this->failure_stage, array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) )
			|| ! $this->text( $this->failure_stage, 64 ) || ! $this->text( $this->diagnostic_code, 96 )
			|| ! $this->optional_text( $this->message, 512 ) || ! $this->optional_text( $this->remediation, 512 ) ) {
			throw new InvalidArgumentException( 'Release workflow result is invalid.' );
		}
	}


	public function workflow_code(): string {
		return $this->workflow_code; }
	public function successful(): bool {
		return $this->successful; }

	public function preview_key(): string {
		return $this->preview_key; }

	public function failure_stage(): string {
		return $this->failure_stage; }

	public function diagnostic_code(): string {
		return $this->diagnostic_code; }

	public function correlation_reference(): string {
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
