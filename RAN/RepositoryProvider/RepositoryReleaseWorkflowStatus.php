<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Secret-free current workflow record and assessment evidence. */
final readonly class RepositoryReleaseWorkflowStatus {
	/** @param list<array{operation:string,outcome_code:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string,recorded_at:string}> $failureHistory @param list<array{id:string,label:string}> $credentialChoices @param list<array{label:string,url:string}> $documentationLinks */
	public function __construct( private string $providerCode, private string $repositoryId, private bool $recordExact, private bool $recordOccupied, private string $pullRequestUrl = '', private string $packageType = '', private string $packageIdentifier = '', private int $sourceRevision = 0, private string $recordOperation = '', private string $observationKind = '', private string $observedAt = '', private array $failureHistory = array(), private array $credentialChoices = array(), private array $documentationLinks = array(), private string $providerWorkflowUrl = '', private string $writeGuidance = '' ) {
		if ( $this->recordExact && ! $this->recordOccupied ) {
			throw new InvalidArgumentException( 'An exact release workflow record must occupy the repository.' );
		}
		if ( ! $this->text( $this->providerCode, 32 ) || ! $this->text( $this->repositoryId, 191 ) || ! $this->url( $this->pullRequestUrl, true )
			|| ! in_array( $this->packageType, array( '', 'plugin', 'theme' ), true ) || ! $this->text( $this->packageIdentifier, 255, true ) || $this->sourceRevision < 0
			|| ! in_array( $this->recordOperation, array( '', 'bootstrap', 'template_update' ), true ) || ! in_array( $this->observationKind, array( '', 'existing_automation_detected', 'booster_setup_verified', 'no_recognisable_automation' ), true )
			|| ! $this->timestamp( $this->observedAt, true ) || count( $this->failureHistory ) > 12 || count( $this->credentialChoices ) > 16 || count( $this->documentationLinks ) > 16 || ! $this->url( $this->providerWorkflowUrl, true ) || ! $this->text( $this->writeGuidance, 512, true ) ) {
			throw new InvalidArgumentException( 'Release workflow status is invalid.' ); }
		foreach ( $this->credentialChoices as $choice ) {
			if ( ! is_array( $choice ) || array_keys( $choice ) !== array( 'id', 'label' ) || ! is_string( $choice['id'] ) || ! $this->text( $choice['id'], 191 ) || ! is_string( $choice['label'] ) || ! $this->text( $choice['label'], 255 ) ) {
				throw new InvalidArgumentException( 'Release workflow credentials are invalid.' ); }
		}
		foreach ( $this->failureHistory as $failure ) {
			if ( ! is_array( $failure )
				|| array_keys( $failure ) !== array( 'operation', 'outcome_code', 'failure_stage', 'diagnostic_code', 'diagnostic_available', 'correlation_reference', 'recorded_at' )
				|| ! in_array( $failure['operation'], array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ), true )
				|| ! is_string( $failure['outcome_code'] ) || 1 !== preg_match( '/\Aworkflow_[a-z0-9_]{1,55}\z/D', $failure['outcome_code'] )
				|| ! in_array( $failure['failure_stage'], array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true )
				|| ! is_string( $failure['diagnostic_code'] ) || ! $this->text( $failure['diagnostic_code'], 96 )
				|| ! is_bool( $failure['diagnostic_available'] )
				|| ! is_string( $failure['correlation_reference'] ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $failure['correlation_reference'] )
				|| ! is_string( $failure['recorded_at'] ) || ! $this->timestamp( $failure['recorded_at'] ) ) {
				throw new InvalidArgumentException( 'Release workflow failure history is invalid.' );
			}
		}
		foreach ( $this->documentationLinks as $link ) {
			if ( ! is_array( $link ) || array_keys( $link ) !== array( 'label', 'url' ) || ! is_string( $link['label'] ) || ! $this->text( $link['label'], 255 ) || ! is_string( $link['url'] ) || ! $this->url( $link['url'] ) ) {
				throw new InvalidArgumentException( 'Release workflow documentation links are invalid.' ); }
		}
	}
	public function providerCode(): string {
		return $this->providerCode;
	} public function repositoryId(): string {
		return $this->repositoryId;
	} public function recordExact(): bool {
		return $this->recordExact;
	} public function recordOccupied(): bool {
		return $this->recordOccupied;
	} public function pullRequestUrl(): string {
		return $this->pullRequestUrl;
	} public function packageType(): string {
		return $this->packageType;
	} public function packageIdentifier(): string {
		return $this->packageIdentifier;
	} public function sourceRevision(): int {
		return $this->sourceRevision;
	} public function recordOperation(): string {
		return $this->recordOperation;
	} public function observationKind(): string {
		return $this->observationKind;
	} public function observedAt(): string {
		return $this->observedAt;
	} public function failureHistory(): array {
		return $this->failureHistory;
	} public function credentialChoices(): array {
		return $this->credentialChoices;
	} public function documentationLinks(): array {
		return $this->documentationLinks;
	} public function providerWorkflowUrl(): string {
		return $this->providerWorkflowUrl;
	} public function writeGuidance(): string {
		return $this->writeGuidance; }
	private function text( string $value, int $limit, bool $empty = false ): bool {
		return ( $empty || '' !== trim( $value ) ) && strlen( $value ) <= $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[<>\x00-\x1F\x7F]/', $value ); }
	private function timestamp( string $value, bool $empty = false ): bool {
		return ( $empty && '' === $value ) || 1 === preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value ); }
	private function url( string $value, bool $empty = false ): bool {
		if ( $empty && '' === $value ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Provider DTO validation is deliberately WordPress-independent.
		$parts = strlen( $value ) <= 512 && 0 === preg_match( '/[\x00-\x20\x7F]/', $value ) && false !== filter_var( $value, FILTER_VALIDATE_URL ) ? parse_url( $value ) : false;
		return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? null ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && is_string( $parts['host'] ?? null ) && '' !== $parts['host'];
	}
}
