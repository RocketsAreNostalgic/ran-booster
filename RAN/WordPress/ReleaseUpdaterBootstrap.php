<?php

declare(strict_types=1);

namespace RAN\WordPress;

use LogicException;

/** Registers the shared release-updater broker before WordPress plugin init. */
final class ReleaseUpdaterBootstrap {

	private const PACKAGE_BOOTSTRAP = '/vendor/ran/wp-release-updater/bootstrap.php';

	public static function register(): object {
		$bootstrap = dirname( __DIR__, 2 ) . self::PACKAGE_BOOTSTRAP;
		if ( ! is_file( $bootstrap ) || ! is_readable( $bootstrap ) ) {
			throw new LogicException( 'RAN Booster release updater dependency is unavailable.' );
		}

		$registrar = require $bootstrap;
		if ( ! is_object( $registrar ) ) {
			throw new LogicException( 'RAN Booster release updater registrar is unavailable.' );
		}

		return $registrar;
	}
}
