<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

use RAN\BoosterGitHubProvider\V1\CredentialPolicy;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialPolicy;

final class GitHubAccessPolicySpy implements ProviderCredentialPolicy, \RAN\RepositoryProvider\SubmittedCredentialValidator {
	public int $normalizations     = 0;
	public int $constant_reads     = 0;
	public bool $suppress_constant = false;
	/** @var \Closure():void|null */
	public ?\Closure $on_names = null;
	/** @var \Closure():void|null */
	public ?\Closure $on_normalize = null;
	/** @var \Closure():void|null */
	public ?\Closure $on_submitted = null;
	public function get_provider(): ProviderCode {
		return ProviderCode::parse( 'gh' ); }
	public function get_constant_names(): array {
		if ( null !== $this->on_names ) {
			( $this->on_names )(); }
		return array( 'RAN_BOOSTER_GITHUB_TOKEN' );
	}
	public function credential_from_constants( array $constants ): ?array {
		++$this->constant_reads;
		if ( $this->suppress_constant ) {
			return null; }
		return ( new CredentialPolicy() )->credential_from_constants( $constants );
	}
	public function normalize_credential( array $metadata, mixed $secret ): array {
		++$this->normalizations;
		if ( null !== $this->on_normalize ) {
			( $this->on_normalize )(); }
		return ( new CredentialPolicy() )->normalize_credential( $metadata, $secret );
	}
	public function validate_submitted_credential( array $metadata, string $secret ): void {
		if ( array() === $metadata || '' === $secret ) {
			throw new \RuntimeException( 'Missing fixture credential.' );
		}
		if ( null !== $this->on_submitted ) {
			( $this->on_submitted )(); }
	}
}
