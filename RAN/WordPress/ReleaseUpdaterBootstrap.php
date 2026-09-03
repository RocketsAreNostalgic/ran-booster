<?php

declare(strict_types=1);

namespace RAN\WordPress;

use LogicException;

/** Registers the shared release-updater broker before WordPress plugin init. */
final class ReleaseUpdaterBootstrap {

	private const PACKAGE_BOOTSTRAP = '/vendor/ran/wp-release-updater/bootstrap.php';
	private const BROKER_GLOBAL     = 'ran_wp_release_updater_v1_broker';

	public static function register(): void {
		$bootstrap = dirname( __DIR__, 2 ) . self::PACKAGE_BOOTSTRAP;
		if ( ! is_file( $bootstrap ) || ! is_readable( $bootstrap ) ) {
			throw new LogicException( 'RAN Booster release updater dependency is unavailable.' );
		}

		require $bootstrap;
	}

	/** Select and load one registered runtime copy after all plugins registered it. */
	public static function activate(): bool {
		$broker = $GLOBALS[ self::BROKER_GLOBAL ] ?? null;
		if ( ! is_object( $broker ) || ! is_callable( array( $broker, 'activate' ) ) ) {
			return false;
		}

		global $wp_version;
		if ( ! is_string( $wp_version ) || '' === $wp_version ) {
			return false;
		}

		try {
			$result = $broker->activate(
				array(
					'php_version'       => PHP_VERSION,
					'runtime_protocol'  => 1,
					'wordpress_version' => $wp_version,
				)
			);
		} catch ( \Throwable ) {
			return false;
		}

		return is_array( $result ) && true === ( $result['loaded'] ?? null );
	}
}
