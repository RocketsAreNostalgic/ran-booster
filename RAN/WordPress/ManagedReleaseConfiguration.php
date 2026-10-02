<?php

declare(strict_types=1);

namespace RAN\WordPress;

use InvalidArgumentException;
use JsonException;

/**
 * Canonical, secret-free configuration for one native release target.
 */
final readonly class ManagedReleaseConfiguration {

	private string $package_root;
	private string $metadata_file;
	private string $channel;

	public function __construct(
		string $package_root,
		string $metadata_file,
		string $channel = 'stable'
	) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})\z/D', $package_root )
			|| 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,190})\z/D', $metadata_file ) ) {
			throw new InvalidArgumentException( 'The managed release configuration is invalid.' );
		}
		if ( ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			throw new InvalidArgumentException( 'The managed release channel is invalid.' );
		}
		$this->package_root  = $package_root;
		$this->metadata_file = $metadata_file;
		$this->channel       = $channel;
	}

	public static function from_json( string $json ): self {
		if ( '' === $json || strlen( $json ) > 4096 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $json ) ) {
			throw new InvalidArgumentException( 'The managed release configuration is invalid.' );
		}

		try {
			$value = json_decode( $json, true, 16, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The managed release configuration is invalid.' );
		}
		if ( ! is_array( $value )
			|| array_keys( $value ) !== array_values(
				array_filter(
					array( 'channel', 'package_root', 'metadata_file' ),
					static fn ( string $key ): bool => array_key_exists( $key, $value )
				)
			)
			|| ! in_array( $value['channel'] ?? null, array( 'stable', 'prerelease' ), true )
			|| ! is_string( $value['package_root'] ?? null )
			|| ! is_string( $value['metadata_file'] ?? null )
		) {
			throw new InvalidArgumentException( 'The managed release configuration is invalid.' );
		}

		$configuration = new self(
			$value['package_root'],
			$value['metadata_file'],
			$value['channel']
		);
		if ( ! hash_equals( $configuration->to_json(), $json ) ) {
			throw new InvalidArgumentException( 'The managed release configuration must use canonical JSON.' );
		}

		return $configuration;
	}

	public function package_root(): string {
		return $this->package_root;
	}

	public function metadata_file(): string {
		return $this->metadata_file;
	}

	public function channel(): string {
		return $this->channel;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'channel'       => $this->channel,
			'package_root'  => $this->package_root,
			'metadata_file' => $this->metadata_file,
		);
	}

	public function to_json(): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical JSON must not depend on WordPress filters.
			return json_encode( $this->to_array(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'The managed release configuration is invalid.' );
		}
	}
}
