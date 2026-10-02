<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\Logging\BoosterLogger;
use RAN\Portability\WpPusherCoexistencePolicy;
use RAN\Runtime\RuntimeSupport;
use RuntimeException;

/**
 * Reject deployment operations outside Booster's supported runtime envelope.
 *
 * This is deliberately a small, synchronous boundary: callers must invoke it
 * before resolving providers, reading managed packages, acquiring claims or
 * handing work to a WordPress upgrader.
 */
final class PackageMutationGuard {

	public const BOOSTER_PLUGIN_FILE = 'ran-booster/ran-booster.php';

	public const MAX_DEPLOYMENT_TARGETS = 64;

	/**
	 * @param array<string, mixed> $request
	 */
	public static function assert_admin_action_allowed( string $action, array $request ): void {
		self::assert_package_mutation_allowed();

		if ( in_array( $action, array( 'edit-plugin', 'update-plugin', 'unlink-plugin', 'unlink-delete-plugin' ), true ) ) {
			self::assert_plugin_file_allowed( $request['file'] ?? null );
		}
	}

	public static function assert_plugin_file_allowed( mixed $identifier ): void {
		if ( self::is_booster_plugin_file( $identifier ) ) {
			BoosterLogger::log(
				'mutation guard blocked deployment',
				array(
					'step'  => 'plugin_file_guard',
					'event' => 'self_update_blocked',
				)
			);
			throw new RuntimeException( 'RAN Booster cannot manage its own plugin files.' );
		}
	}

	/**
	 * @param list<string> $identifiers
	 */
	public static function assert_bulk_admin_allowed( string $package_type, array $identifiers ): void {
		self::assert_package_mutation_allowed();

		if ( ! in_array( $package_type, array( 'plugin', 'theme' ), true ) || array() === $identifiers ) {
			throw new RuntimeException( 'The bulk package operation is invalid.' );
		}
	}

	public static function assert_webhook_dispatch_allowed(): void {
		self::assert_package_mutation_allowed();
	}

	/**
	 * Re-check the WordPress mutation policy immediately before an upgrader.
	 */
	public static function assert_filesystem_mutation_allowed(): void {
		self::assert_package_mutation_allowed();

		if ( ( defined( 'DISALLOW_FILE_MODS' ) && constant( 'DISALLOW_FILE_MODS' ) )
			|| ! wp_is_file_mod_allowed( 'ran-booster' ) ) {
			BoosterLogger::log(
				'mutation guard blocked deployment',
				array(
					'step'  => 'filesystem_mutation_guard',
					'event' => 'file_mods_disabled',
				)
			);
			throw new RuntimeException( 'WordPress file modifications are disabled for this site.' );
		}
	}

	public static function assert_deployment_target_count( int $count ): void {
		if ( $count > self::MAX_DEPLOYMENT_TARGETS ) {
			BoosterLogger::log(
				'mutation guard blocked deployment',
				array(
					'step'  => 'target_count_guard',
					'event' => 'target_count_exceeded',
				)
			);
			throw new RuntimeException( 'A webhook delivery matches too many managed packages.' );
		}
	}

	public static function is_booster_plugin_file( mixed $identifier ): bool {
		return is_string( $identifier ) && self::BOOSTER_PLUGIN_FILE === trim( str_replace( '\\', '/', $identifier ) );
	}

	public static function assert_package_mutation_allowed(): void {
		if ( ! RuntimeSupport::current()->allows_managed_operations() ) {
			BoosterLogger::log(
				'mutation guard blocked deployment',
				array(
					'step'  => 'single_site_guard',
					'event' => 'multisite_blocked',
				)
			);
			RuntimeSupport::assert_managed_operations_allowed();
		}

		WpPusherCoexistencePolicy::assert_package_mutation_allowed();
	}
}
