<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderCode;

/**
 * Bounded, non-secret package context for optional retained-webhook cleanup UI.
 */
final readonly class WebhookCleanupContext {

	/**
	 * @param list<string> $branch_package_references
	 */
	public function __construct(
		private string $package_type,
		private string $package_identifier,
		private string $provider_code,
		private string $repository_id,
		private string $repository,
		private string $local_secret_coverage,
		private bool $evidence_available,
		private bool $branch_evidence_available,
		private array $branch_package_references,
		private string $provider_webhooks_url,
		private string $secrets_url,
		private string $documentation_url,
		private string $return_url
	) {
		if ( ! in_array( $this->package_type, array( 'plugin', 'theme' ), true )
			|| '' === trim( $this->package_identifier )
			|| strlen( $this->package_identifier ) > 255
			|| '' === trim( $this->repository_id )
			|| strlen( $this->repository_id ) > 191
			|| '' === trim( $this->repository )
			|| strlen( $this->repository ) > 255
			|| ! in_array( $this->local_secret_coverage, array( 'repository', 'shared', 'none', 'unknown' ), true )
		) {
			throw new InvalidArgumentException( 'Webhook cleanup contexts require bounded package evidence.' );
		}
		ProviderCode::parse( $this->provider_code );

		foreach ( $this->branch_package_references as $reference ) {
			if ( ! is_string( $reference ) || '' === trim( $reference ) || strlen( $reference ) > 255 ) {
				throw new InvalidArgumentException( 'Webhook cleanup package references must be bounded.' );
			}
		}
		foreach ( array( $this->provider_webhooks_url, $this->secrets_url, $this->documentation_url, $this->return_url ) as $url ) {
			if ( '' !== $url && ! $this->safe_url( $url ) ) {
				throw new InvalidArgumentException( 'Webhook cleanup links must be safe absolute URLs.' );
			}
		}
	}

	public function package_type(): string {
		return $this->package_type;
	}

	public function package_identifier(): string {
		return $this->package_identifier;
	}

	public function provider_code(): string {
		return $this->provider_code;
	}

	public function repository_id(): string {
		return $this->repository_id;
	}

	public function repository(): string {
		return $this->repository;
	}

	public function local_secret_coverage(): string {
		return $this->local_secret_coverage;
	}

	public function evidence_available(): bool {
		return $this->evidence_available;
	}

	public function branch_evidence_available(): bool {
		return $this->branch_evidence_available;
	}

	/** @return list<string> */
	public function branch_package_references(): array {
		return $this->branch_package_references;
	}

	public function cleanup_allowed(): bool {
		return $this->branch_evidence_available && array() === $this->branch_package_references;
	}

	public function provider_webhooks_url(): string {
		return $this->provider_webhooks_url;
	}

	public function secrets_url(): string {
		return $this->secrets_url;
	}

	public function documentation_url(): string {
		return $this->documentation_url;
	}

	public function return_url(): string {
		return $this->return_url;
	}

	private function safe_url( string $url ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Value object may load before WordPress URL helpers.
		$parts = parse_url( $url );

		$fragment = is_array( $parts ) && isset( $parts['fragment'] ) ? $parts['fragment'] : '';

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ( '' === $fragment || 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $fragment ) );
	}
}
