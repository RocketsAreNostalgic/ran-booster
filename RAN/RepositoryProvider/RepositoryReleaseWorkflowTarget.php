<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use InvalidArgumentException;

/** Secret-free package and repository facts required by provider workflow operations. */
readonly class RepositoryReleaseWorkflowTarget {
	public function __construct(
		private string $type,
		private string $identifier,
		private int $sourceRevision,
		private string $providerRepositoryId,
		private string $packageRoot = '',
		private string $installedVersion = '',
		private string $expectedUpdateUri = ''
	) {
		if ( ! in_array( $this->type, array( 'plugin', 'theme' ), true )
			|| ! $this->text( $this->identifier, 255 )
			|| $this->sourceRevision < 1
			|| ! $this->text( $this->providerRepositoryId, 191 )
			|| ! $this->optionalText( $this->packageRoot, 255 )
			|| ! $this->optionalText( $this->installedVersion, 255 )
			|| ! $this->optionalUrl( $this->expectedUpdateUri ) ) {
			throw new InvalidArgumentException( 'Release workflow target is invalid.' );
		}
	}

	public function type(): string {
		return $this->type;
	}

	public function identifier(): string {
		return $this->identifier;
	}

	public function sourceRevision(): int {
		return $this->sourceRevision;
	}

	public function providerRepositoryId(): string {
		return $this->providerRepositoryId;
	}

	public function packageRoot(): string {
		return $this->packageRoot;
	}

	public function installedVersion(): string {
		return $this->installedVersion;
	}

	public function expectedUpdateUri(): string {
		return $this->expectedUpdateUri;
	}

	private function text( string $value, int $limit ): bool {
		return '' !== trim( $value )
			&& strlen( $value ) <= $limit
			&& 1 === preg_match( '//u', $value )
			&& 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	private function optionalText( string $value, int $limit ): bool {
		return '' === $value || $this->text( $value, $limit );
	}

	private function optionalUrl( string $value ): bool {
		if ( '' === $value ) {
			return true;
		}
		if ( strlen( $value ) > 255 || 0 !== preg_match( '/[\x00-\x20\x7F]/', $value ) || false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Provider DTO validation is deliberately WordPress-independent.
		$parts = parse_url( $value );

		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& is_string( $parts['host'] ?? null )
			&& '' !== $parts['host'];
	}
}
