<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Secret-free current workflow record and assessment evidence. */
final readonly class RepositoryReleaseWorkflowStatus {
	/** @param list<array{operation:string,outcome_code:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string,recorded_at:string}> $failure_history @param list<array{id:string,label:string}> $credential_choices @param list<array{label:string,url:string}> $documentation_links */
	public function __construct( private string $provider_code, private string $repository_id, private bool $record_exact, private bool $record_occupied, private string $pull_request_url = '', private string $package_type = '', private string $package_identifier = '', private int $source_revision = 0, private string $record_operation = '', private string $observation_kind = '', private string $observed_at = '', private array $failure_history = array(), private array $credential_choices = array(), private array $documentation_links = array(), private string $provider_workflow_url = '', private string $write_guidance = '' ) {
		if ( $this->record_exact && ! $this->record_occupied ) {
			throw new InvalidArgumentException( 'An exact release workflow record must occupy the repository.' );
		}
		if ( ! $this->text( $this->provider_code, 32 ) || ! $this->text( $this->repository_id, 191 ) || ! $this->url( $this->pull_request_url, true )
			|| ! in_array( $this->package_type, array( '', 'plugin', 'theme' ), true ) || ! $this->text( $this->package_identifier, 255, true ) || $this->source_revision < 0
			|| ! in_array( $this->record_operation, array( '', 'bootstrap' ), true ) || ! in_array( $this->observation_kind, array( '', 'existing_automation_detected', 'booster_setup_verified', 'no_recognisable_automation' ), true )
			|| ! $this->timestamp( $this->observed_at, true ) || count( $this->failure_history ) > 12 || count( $this->credential_choices ) > 16 || count( $this->documentation_links ) > 16 || ! $this->url( $this->provider_workflow_url, true ) || ! $this->optional_text( $this->write_guidance, 512 ) ) {
			throw new InvalidArgumentException( 'Release workflow status is invalid.' ); }
		foreach ( $this->credential_choices as $choice ) {
			if ( ! is_array( $choice ) || array_keys( $choice ) !== array( 'id', 'label' ) || ! is_string( $choice['id'] ) || ! $this->text( $choice['id'], 191 ) || ! is_string( $choice['label'] ) || ! $this->text( $choice['label'], 255 ) ) {
				throw new InvalidArgumentException( 'Release workflow credentials are invalid.' ); }
		}
		foreach ( $this->failure_history as $failure ) {
			if ( ! is_array( $failure )
				|| array_keys( $failure ) !== array( 'operation', 'outcome_code', 'failure_stage', 'diagnostic_code', 'diagnostic_available', 'correlation_reference', 'recorded_at' )
				|| ! in_array( $failure['operation'], array( 'inspect', 'setup', 'outcome' ), true )
				|| ! is_string( $failure['outcome_code'] ) || 1 !== preg_match( '/\Aworkflow_[a-z0-9_]{1,55}\z/D', $failure['outcome_code'] )
				|| ! in_array( $failure['failure_stage'], array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true )
				|| ! is_string( $failure['diagnostic_code'] ) || ! $this->text( $failure['diagnostic_code'], 96 )
				|| ! is_bool( $failure['diagnostic_available'] )
				|| ! is_string( $failure['correlation_reference'] ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $failure['correlation_reference'] )
				|| ! is_string( $failure['recorded_at'] ) || ! $this->timestamp( $failure['recorded_at'] ) ) {
				throw new InvalidArgumentException( 'Release workflow failure history is invalid.' );
			}
		}
		foreach ( $this->documentation_links as $link ) {
			if ( ! is_array( $link ) || array_keys( $link ) !== array( 'label', 'url' ) || ! is_string( $link['label'] ) || ! $this->text( $link['label'], 255 ) || ! is_string( $link['url'] ) || ! $this->url( $link['url'] ) ) {
				throw new InvalidArgumentException( 'Release workflow documentation links are invalid.' ); }
		}
	}

	public function provider_code(): string {
		return $this->provider_code;
	}

	public function repository_id(): string {
		return $this->repository_id;
	}

	public function record_exact(): bool {
		return $this->record_exact;
	}

	public function record_occupied(): bool {
		return $this->record_occupied;
	}

	public function pull_request_url(): string {
		return $this->pull_request_url;
	}

	public function package_type(): string {
		return $this->package_type;
	}

	public function package_identifier(): string {
		return $this->package_identifier;
	}

	public function source_revision(): int {
		return $this->source_revision;
	}

	public function record_operation(): string {
		return $this->record_operation;
	}

	public function observation_kind(): string {
		return $this->observation_kind;
	}

	public function observed_at(): string {
		return $this->observed_at;
	}

	public function failure_history(): array {
		return $this->failure_history;
	}

	public function credential_choices(): array {
		return $this->credential_choices;
	}

	public function documentation_links(): array {
		return $this->documentation_links;
	}

	public function provider_workflow_url(): string {
		return $this->provider_workflow_url;
	}

	public function write_guidance(): string {
		return $this->write_guidance; }
	private function text( string $value, int $limit, bool $allow_empty = false ): bool {
		return ( $allow_empty || '' !== trim( $value ) ) && strlen( $value ) <= $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[<>\x00-\x1F\x7F]/', $value ); }
	private function optional_text( string $value, int $limit ): bool {
		return '' === $value || $this->text( $value, $limit ); }
	private function timestamp( string $value, bool $allow_empty = false ): bool {
		return ( $allow_empty && '' === $value ) || 1 === preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value ); }
	private function url( string $value, bool $allow_empty = false ): bool {
		if ( $allow_empty && '' === $value ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Provider DTO validation is deliberately WordPress-independent.
		$parts = strlen( $value ) <= 512 && 0 === preg_match( '/[\x00-\x20\x7F]/', $value ) && false !== filter_var( $value, FILTER_VALIDATE_URL ) ? parse_url( $value ) : false;
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? null ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && is_string( $parts['host'] ?? null ) && '' !== $parts['host'];
	}
}
