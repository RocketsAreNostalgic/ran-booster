<?php

declare(strict_types=1);

namespace RAN\Secrets;

/**
 * One bootstrap-safe view of the runtime requirements for encrypted secrets.
 */
final readonly class SecretsRuntimeAvailability {

	public function __construct(
		private ?bool $sodium = null,
		private ?bool $multisite = null
	) {
	}

	/**
	 * Permit only the exact secrets authentication needed by WordPress's
	 * confirmed uninstall entrypoint. This never creates a key and must not be
	 * used by normal runtime services.
	 */
	public static function for_confirmed_uninstall( string $plugin_file ): self {
		$plugin   = defined( 'WP_UNINSTALL_PLUGIN' ) ? WP_UNINSTALL_PLUGIN : null;
		$expected = function_exists( 'plugin_basename' )
			? plugin_basename( $plugin_file )
			: basename( dirname( $plugin_file ) ) . '/' . basename( $plugin_file );
		if ( ! is_string( $plugin )
			|| ! is_string( $expected )
			|| '' === $expected
			|| ! hash_equals(
				strtolower( str_replace( '\\', '/', ltrim( $expected, '/\\' ) ) ),
				strtolower( str_replace( '\\', '/', ltrim( $plugin, '/\\' ) ) )
			)
		) {
			throw new \LogicException( 'The Booster uninstall cleanup context is unavailable.' );
		}

		return new self( multisite: false );
	}

	public function is_available(): bool {
		return $this->sodium_available() && ! $this->is_multisite();
	}

	public function code(): string {
		if ( ! $this->sodium_available() ) {
			return 'sodium_unavailable';
		}

		return $this->is_multisite() ? 'multisite_unsupported' : 'available';
	}

	public function message(): string {
		return match ( $this->code() ) {
			'sodium_unavailable'    => 'Encrypted credential operations are unavailable because the PHP Sodium extension is missing.',
			'multisite_unsupported' => 'Encrypted credential operations are unavailable because this Beta supports single-site WordPress only.',
			default                 => 'Encrypted credential operations are available.',
		};
	}

	private function sodium_available(): bool {
		return $this->sodium ?? (
			extension_loaded( 'sodium' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' )
		);
	}

	private function is_multisite(): bool {
		return $this->multisite ?? ( function_exists( 'is_multisite' ) && is_multisite() );
	}
}
