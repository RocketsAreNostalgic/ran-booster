<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Package;
use RAN\Storage\PluginRepository;
use Throwable;

/**
 * Adds WordPress-native inline failure rows beneath affected managed plugins.
 */
final readonly class ManagedPluginFailureRows {

	public function __construct(
		private PluginRepository $plugins,
		private BackgroundDeploymentFailureMonitor $monitor
	) {
	}

	public function register(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		try {
			$plugins = $this->plugins->allBoosterPlugins();
		} catch ( Throwable ) {
			return;
		}

		foreach ( $plugins as $plugin ) {
			if ( ! $plugin instanceof Package || ! is_string( $plugin->getIdentifier() ) || ! is_string( $plugin->getSlug() ) ) {
				continue;
			}
			$failure = $this->monitor->forPackage( 'plugin', $plugin->getSlug() );
			if ( null === $failure ) {
				continue;
			}
			$file = $plugin->getIdentifier();
			add_action(
				'after_plugin_row_' . $file,
				function ( string $pluginFile ) use ( $failure ): void {
					$this->render( $pluginFile, $failure );
				},
				10,
				1
			);
		}
	}

	/** @param array<string, int|string|null> $failure */
	private function render( string $pluginFile, array $failure ): void {
		global $wp_list_table;

		$columns = is_object( $wp_list_table ) && method_exists( $wp_list_table, 'get_column_count' )
			? max( 1, (int) $wp_list_table->get_column_count() )
			: 4;
		$active  = function_exists( 'is_plugin_active' ) && is_plugin_active( $pluginFile ) ? ' active' : '';
		?>
		<tr class="plugin-update-tr<?php echo esc_attr( $active ); ?>" data-plugin="<?php echo esc_attr( $pluginFile ); ?>" data-ran-booster-background-failure-row>
			<td colspan="<?php echo esc_attr( (string) $columns ); ?>" class="plugin-update colspanchange">
				<div class="update-message notice inline notice-error notice-alt">
					<p>
						<strong><?php esc_html_e( 'RAN Booster automatic deployment failed.', 'ran-booster' ); ?></strong>
						<?php echo esc_html( DeploymentOutcomeMessage::forCode( (string) $failure['outcome_code'] ) ); ?>
						<a href="<?php echo esc_url( $this->activityUrl( $failure ) ); ?>"><?php esc_html_e( 'Review deployment', 'ran-booster' ); ?></a>
						<?php if ( is_string( $failure['credential_id'] ) && '' !== $failure['credential_id'] ) { ?>
							<a href="<?php echo esc_url( $this->credentialUrl( $failure ) ); ?>"><?php esc_html_e( 'Replace credential', 'ran-booster' ); ?></a>
						<?php } ?>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}

	/** @param array<string, int|string|null> $failure */
	private function activityUrl( array $failure ): string {
		return admin_url(
			'admin.php?page=ran-booster&tab=troubleshooting&panel=activity'
			. '&attempt=' . rawurlencode( (string) $failure['attempt_id'] )
			. '&reference=' . rawurlencode( (string) $failure['correlation_id'] )
		);
	}

	/** @param array<string, int|string|null> $failure */
	private function credentialUrl( array $failure ): string {
		return admin_url(
			'admin.php?page=ran-booster&tab=' . rawurlencode( (string) $failure['provider'] )
			. '&replace_credential=' . rawurlencode( (string) $failure['credential_id'] )
		);
	}
}
