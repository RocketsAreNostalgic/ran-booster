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
	public static function assertAdminActionAllowed( string $action, array $request ): void {
		self::assertPackageMutationAllowed();

		if ( in_array( $action, array( 'edit-plugin', 'update-plugin', 'unlink-plugin', 'unlink-delete-plugin' ), true ) ) {
			self::assertPluginFileAllowed( $request['file'] ?? null );
		}
	}

	public static function assertPluginFileAllowed( mixed $identifier ): void {
		if ( self::isBoosterPluginFile( $identifier ) ) {
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
	public static function assertBulkAdminAllowed( string $packageType, array $identifiers ): void {
		self::assertPackageMutationAllowed();

		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true ) || array() === $identifiers ) {
			throw new RuntimeException( 'The bulk package operation is invalid.' );
		}
	}

	public static function assertWebhookDispatchAllowed(): void {
		self::assertPackageMutationAllowed();
	}

	/**
	 * Re-check the WordPress mutation policy immediately before an upgrader.
	 */
	public static function assertFilesystemMutationAllowed(): void {
		self::assertPackageMutationAllowed();

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

	public static function assertDeploymentTargetCount( int $count ): void {
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

	public static function isBoosterPluginFile( mixed $identifier ): bool {
		return is_string( $identifier ) && self::BOOSTER_PLUGIN_FILE === trim( str_replace( '\\', '/', $identifier ) );
	}

	public static function assertPackageMutationAllowed(): void {
		if ( ! RuntimeSupport::current()->allowsManagedOperations() ) {
			BoosterLogger::log(
				'mutation guard blocked deployment',
				array(
					'step'  => 'single_site_guard',
					'event' => 'multisite_blocked',
				)
			);
			RuntimeSupport::assertManagedOperationsAllowed();
		}

		WpPusherCoexistencePolicy::assertPackageMutationAllowed();
	}
}
