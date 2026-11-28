<?php

declare(strict_types=1);

namespace RAN_Booster_GitHubOfflineProviderFixture;

use RAN\RepositoryProvider\AuthenticatedWebhookDeliveryEvidenceReader;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\ProviderRegistrationContext;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;

/** Test-only instrumentation. Never stores credential bytes or profile identifiers. */
final class Fixture {
	public const COUNTERS  = 'ran_booster_offline_provider_fixture_counts';
	public const DIRECTORY = 'ran-booster-github-offline-provider';
	private const KINDS    = array( 'factory_calls', 'provider_calls', 'credential_calls', 'credential_material_returns' );

	/** Exact origin of the disposable CI site, shared by HTTP requests and CLI phases. */
	public static function expected_origin(): string {
		return 'http://localhost';
	}

	public static function guard(): void {
		$root = realpath( ABSPATH );
		if ( false === $root || rtrim( ABSPATH, '/' ) !== $root
			|| WP_CONTENT_DIR !== $root . '/wp-content' || WP_PLUGIN_DIR !== WP_CONTENT_DIR . '/plugins'
			|| self::expected_origin() !== get_option( 'siteurl' ) || self::expected_origin() !== get_option( 'home' )
			|| is_link( $root ) || is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR )
			|| is_link( WP_PLUGIN_DIR . '/' . self::DIRECTORY )
			|| is_link( $root . '/.ran-booster-disposable-test-site' )
			|| ! is_file( $root . '/.ran-booster-disposable-test-site' ) ) {
			throw new \RuntimeException( 'Offline provider fixture requires the exact marked disposable site.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the exact local disposable-site marker before test-only observations or mutations.
		$marker = file_get_contents( $root . '/.ran-booster-disposable-test-site' );
		if ( ! is_string( $marker ) || 'RAN Booster disposable test site' !== trim( $marker ) ) {
			throw new \RuntimeException( 'Offline provider fixture marker is invalid.' );
		}
	}

	/** @return array<string,int> */
	public static function counters(): array {
		self::guard();
		$result = array();
		$stored = get_option( self::COUNTERS, array() );
		foreach ( self::KINDS as $kind ) {
			$value           = is_array( $stored ) ? ( $stored[ $kind ] ?? 0 ) : 0;
			$result[ $kind ] = is_int( $value ) ? $value : 0;
		}
		return $result;
	}

	public static function observe( string $kind ): void {
		$counts = self::counters();
		if ( ! array_key_exists( $kind, $counts ) ) {
			throw new \RuntimeException( 'Unknown offline provider fixture counter.' );
		}
		++$counts[ $kind ];
		update_option( self::COUNTERS, $counts, false );
	}

	/** Claim `gh` through Core's supersede seam; the factory is defined here, inside the plugin directory. */
	public static function register( string $provider_file ): void {
		self::guard();
		add_action(
			'ran_booster_register_providers',
			static function ( object $registry ) use ( $provider_file ): void {
				if ( ! $registry instanceof ProviderRegistry ) {
					throw new \RuntimeException( 'Offline provider fixture needs the real Core registry.' );
				}
				$registry->supersede_github(
					static function ( ProviderCredentialStore $credentials, AuthenticatedWebhookDeliveryEvidenceReader $delivery_evidence, ProviderRegistrationContext $context ) use ( $provider_file ): RepositoryProvider {
						unset( $delivery_evidence );
						self::observe( 'factory_calls' );
						require_once $provider_file;
						return new Provider( $credentials, $context );
					}
				);
			}
		);
	}
}
