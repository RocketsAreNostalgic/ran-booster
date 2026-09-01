<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Exact but display-safe, short-lived provider workflow preview. */
final readonly class RepositoryReleaseWorkflowPreview {
	/** @param array{repository:string,default_branch:string,base_sha:string,pack_version:string,template_digest:string,old_template_tag:string,new_template_tag:string} $summary @param list<array{path:string,operation:string,digest:string}> $changedPaths */
	public function __construct( private string $key, private string $providerCode, private string $repositoryId, private string $kind, private string $channel, private string $confirmation, private array $summary, private array $changedPaths ) {
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $this->key ) || ! $this->text( $this->providerCode, 32 ) || ! $this->text( $this->repositoryId, 191 )
			|| ! in_array( $this->kind, array( 'bootstrap', 'template_update' ), true ) || ! in_array( $this->channel, array( '', 'stable', 'prerelease' ), true )
			|| ( 'bootstrap' === $this->kind ) !== ( '' !== $this->channel ) || ! $this->text( $this->confirmation, 201 )
			|| array_keys( $this->summary ) !== array( 'repository', 'default_branch', 'base_sha', 'pack_version', 'template_digest', 'old_template_tag', 'new_template_tag' ) || count( $this->changedPaths ) > 32 ) {
			throw new InvalidArgumentException( 'Release workflow preview is invalid.' );
		}
		foreach ( $this->summary as $name => $value ) {
			if ( ! is_string( $value ) || ( 'old_template_tag' !== $name && ! $this->text( $value, 512 ) ) || ( 'old_template_tag' === $name && ! ( 'bootstrap' === $this->kind && '' === $value ) && ! $this->text( $value, 512 ) ) ) {
				throw new InvalidArgumentException( 'Release workflow preview summary is invalid.' ); }
		}
		foreach ( $this->changedPaths as $change ) {
			if ( ! is_array( $change ) || array_keys( $change ) !== array( 'path', 'operation', 'digest' ) || ! is_string( $change['path'] ) || ! $this->text( $change['path'], 512 ) || ! in_array( $change['operation'], array( 'added', 'modified' ), true ) || ! is_string( $change['digest'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $change['digest'] ) ) {
				throw new InvalidArgumentException( 'Release workflow preview paths are invalid.' ); }
		}
	}
	public function key(): string {
		return $this->key;
	} public function providerCode(): string {
		return $this->providerCode;
	} public function repositoryId(): string {
		return $this->repositoryId;
	} public function kind(): string {
		return $this->kind;
	} public function channel(): string {
		return $this->channel;
	} public function confirmation(): string {
		return $this->confirmation;
	} public function summary(): array {
		return $this->summary;
	} public function changedPaths(): array {
		return $this->changedPaths; }
	private function text( string $value, int $limit ): bool {
		return '' !== trim( $value ) && strlen( $value ) <= $limit && 1 === preg_match( '//u', $value ) && 0 === preg_match( '/[<>\x00-\x1F\x7F]/', $value ); }
}
