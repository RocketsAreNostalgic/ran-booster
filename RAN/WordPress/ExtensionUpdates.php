<?php

declare(strict_types=1);

namespace RAN\WordPress;

use Closure;
use RAN\Deployment\PackageMutationGuard;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
final class ExtensionUpdates {
	/** @var Closure(): list<array<string, mixed>> */
	private Closure $catalogue;
	/** @var Closure(string): bool */
	private Closure $managed_package_exists;
	private bool $notice_registered = false;
	/** @param callable(string): bool $managed_package_exists
	 *  @param callable(): list<array<string, mixed>> $catalogue
	 */
	public function __construct(
		private ManagedReleaseUpdaterRegistrar $registrar,
		callable $managed_package_exists,
		callable $catalogue
	) {
		$this->managed_package_exists = Closure::fromCallable( $managed_package_exists );
		$this->catalogue              = Closure::fromCallable( $catalogue );
	}
	public function register(): void {
		foreach ( ( $this->catalogue )() as $extension ) {
			if ( ! $this->valid( $extension ) ) {
				continue;
			}
			$plugin = $extension['plugin'];
			if ( ! $this->eligible( $extension ) ) {
				$this->suppress( $plugin );
				continue;
			}
			if ( ( $this->managed_package_exists )( $plugin ) ) {
				$this->suppress( $plugin );
				continue;
			}
			$admitted = $this->registrar->with_descriptor_admission( fn ( IdentityDescriptor $descriptor ): bool => $this->admits( $extension, $descriptor ) );
			if ( null === $admitted ) {
				$this->notice();
				$this->suppress( $plugin );
				continue;
			}
			try {
				$target = $admitted->plugin( 'github', WP_PLUGIN_DIR . '/' . $plugin, $extension['repository'], $extension['repository_id'], 'prerelease', 'manual', null );
				if ( ! is_callable( array( $target, 'register' ) ) || ! $target->register() ) {
					$this->suppress( $plugin );
				}
			} catch ( \Throwable ) {
				$this->suppress( $plugin );
			}
		}
	}
	/** @param array<string, mixed> $extension */
	private function valid( array $extension ): bool {
		foreach ( array( 'plugin', 'release_version', 'asset_sha256', 'repository', 'repository_id', 'canonical_update_uri', 'state' ) as $key ) {
			if ( ! is_string( $extension[ $key ] ?? null ) || '' === $extension[ $key ] ) {
				return false;
			}
		}
		return is_bool( $extension['compatible'] ?? null );
	}
	/** @param array<string, mixed> $extension */
	private function eligible( array $extension ): bool {
		return true === $extension['compatible'] && 'Not installed' !== $extension['state'];
	}
	/** @param array<string, mixed> $extension */
	private function admits( array $extension, IdentityDescriptor $descriptor ): bool {
		try {
			PackageMutationGuard::assert_package_mutation_allowed();
			$current = $this->current( $extension['plugin'] );
			if ( null === $current || ! $this->eligible( $current ) || ( $this->managed_package_exists )( $extension['plugin'] ) ) {
				return false;
			}
			$facts = $descriptor->to_array();
			return $current['release_version'] === $extension['release_version']
				&& $current['asset_sha256'] === $extension['asset_sha256']
				&& $current['repository'] === $extension['repository']
				&& $current['repository_id'] === $extension['repository_id']
				&& $current['canonical_update_uri'] === $extension['canonical_update_uri']
				&& ( $facts['target_type'] ?? null ) === 'plugin'
				&& ( $facts['installed_package_identity'] ?? null ) === $extension['plugin']
				&& ( $facts['provider_code'] ?? null ) === 'github'
				&& ( $facts['repository_identity'] ?? null ) === $extension['repository_id']
				&& ( $facts['repository_locator'] ?? null ) === $extension['repository']
				&& ( $facts['canonical_update_uri'] ?? null ) === $extension['canonical_update_uri']
				&& ( $facts['tag'] ?? null ) === 'v' . $extension['release_version']
				&& ( $facts['version'] ?? null ) === $extension['release_version']
				&& ( $facts['artifact_sha256'] ?? null ) === $extension['asset_sha256'];
		} catch ( \Throwable ) {
			return false;
		}
	}
	/** @return array<string, mixed>|null */
	private function current( string $plugin ): ?array {
		foreach ( ( $this->catalogue )() as $extension ) {
			if ( is_array( $extension ) && ( $extension['plugin'] ?? null ) === $plugin && $this->valid( $extension ) ) {
				return $extension;
			}
		}
		return null;
	}

	private function suppress( string $plugin ): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter(
				'site_transient_update_plugins',
				static function ( mixed $transient ) use ( $plugin ): mixed {
					if ( is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ) {
						unset( $transient->response[ $plugin ] );
					}
					return $transient;
				},
				PHP_INT_MAX
			);
		}
	}

	private function notice(): void {
		if ( ! $this->notice_registered && function_exists( 'add_action' ) ) {
			$this->notice_registered = true;
			add_action(
				'admin_notices',
				static function (): void {
					$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
					if ( ! is_object( $screen ) || ! in_array( $screen->id, array( 'plugins', 'ran-booster_page_ran-booster-extensions' ), true ) ) {
						return;
					}
					\RAN\Admin\BoosterNoticeScope::render(
						static function (): void {
							echo '<div class="' . esc_attr( 'notice notice-warning' . ( \RAN\Admin\BoosterNoticeScope::is_booster_screen() ? ' inline' : '' ) ) . '"><p>' . esc_html__( 'Native extension updates are unavailable in this Booster build. Update Booster, or download the selected ZIP from More Details for manual replacement.', 'ran-booster' ) . '</p></div>';
						}
					);
				}
			);
		}
	}
}
