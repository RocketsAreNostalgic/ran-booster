<?php

declare(strict_types=1);

namespace RAN_Booster_GitHubReplacementFixture;

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;

/** Test-only instrumentation. Never stores credential bytes or profile identifiers. */
final class Fixture {
	public const COUNTERS = 'ran_booster_replacement_fixture_counts';

	/** Exact test-only origin shared by browser requests and CLI phases. */
	public static function expected_origin(): string {
		$origin = defined( 'RAN_BOOSTER_REPLACEMENT_FIXTURE_ORIGIN' ) ? constant( 'RAN_BOOSTER_REPLACEMENT_FIXTURE_ORIGIN' ) : 'http://localhost';
		if ( ! is_string( $origin ) || 1 !== preg_match( '/\Ahttp:\/\/localhost(?::([1-9][0-9]{0,4}))?\z/', $origin, $matches )
			|| ( isset( $matches[1] ) && (int) $matches[1] > 65535 ) ) {
			throw new \RuntimeException( 'Replacement fixture requires an exact HTTP localhost origin with an optional valid port.' );
		}
		return $origin;
	}

	public static function guard(): void {
		$root = realpath( ABSPATH );
		if ( false === $root || rtrim( ABSPATH, '/' ) !== $root
			|| WP_CONTENT_DIR !== $root . '/wp-content' || WP_PLUGIN_DIR !== WP_CONTENT_DIR . '/plugins'
			|| self::expected_origin() !== get_option( 'siteurl' ) || self::expected_origin() !== get_option( 'home' )
			|| is_link( $root ) || is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR )
			|| is_link( WP_PLUGIN_DIR . '/github-replacement' )
			|| is_link( $root . '/.ran-booster-disposable-test-site' )
			|| ! is_file( $root . '/.ran-booster-disposable-test-site' ) ) {
			throw new \RuntimeException( 'Replacement fixture requires the exact marked disposable site.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact local disposable-site marker before test-only observations or mutations.
		$marker = file_get_contents( $root . '/.ran-booster-disposable-test-site' );
		if ( ! is_string( $marker ) || 'RAN Booster disposable test site' !== trim( $marker ) ) {
			throw new \RuntimeException( 'Replacement fixture marker is invalid.' );
		}
	}

	/** @return array<string,int> */
	public static function counters(): array {
		self::guard();
		$result = array();
		$stored = get_option( self::COUNTERS, array() );
		foreach ( array( 'a', 'b' ) as $identity ) {
			foreach ( array( 'factory_calls', 'provider_calls', 'credential_calls', 'credential_material_returns' ) as $kind ) {
				$key            = $identity . '.' . $kind;
				$value          = is_array( $stored ) ? ( $stored[ $key ] ?? 0 ) : 0;
				$result[ $key ] = is_int( $value ) ? $value : 0;
			}
		}
		return $result;
	}

	public static function observe( string $identity, string $kind ): void {
		$counts = self::counters();
		$key    = $identity . '.' . $kind;
		if ( ! array_key_exists( $key, $counts ) ) {
			throw new \RuntimeException( 'Unknown replacement fixture counter.' );
		}
		++$counts[ $key ];
		update_option( self::COUNTERS, $counts, false );
	}

	public static function register( string $identity ): void {
		self::guard();
		add_action(
			'ran_booster_register_providers',
			static function ( object $registry ) use ( $identity ): void {
				if ( ! $registry instanceof ProviderRegistry ) {
					throw new \RuntimeException( 'Replacement fixture needs the real Core registry.' );
				}
				$declaration = GitHubReplacementDeclaration::from_plugin_file( WP_PLUGIN_DIR, 'github-replacement/' . $identity . '.php' );
				if ( null === $declaration ) {
					throw new \RuntimeException( 'Replacement fixture declaration is absent.' );
				}
				$registry->declare_github_replacement(
					$declaration,
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The real registry factory requires delivery evidence even though this fixture has no webhook facet.
					static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence, ProviderRegistrationContext $context ) use ( $identity ): RepositoryProvider {
						self::observe( $identity, 'factory_calls' );
						require_once __DIR__ . '/provider.php';
						return new Provider( $identity, $credentials, $context );
					}
				);
			}
		);
	}
}
