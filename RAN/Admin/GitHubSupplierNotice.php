<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Admin\Component\NoticeIcon;
use RAN\RepositoryProvider\ProviderRegistry;

/**
 * Says once, to an administrator, that the plugin supplying GitHub changed.
 *
 * The option holds only the last supplier an administrator was shown. It is per
 * site, because providers register per request from the plugins active on that
 * site. It carries no approval: the supplier is already in effect.
 */
final class GitHubSupplierNotice {
	public const OPTION = 'ran_booster_github_supplier_shown';

	public function __construct( private ProviderRegistry $providers ) {
	}

	/** The supplying plugin's name while a plugin supplies GitHub, otherwise null. */
	public function supplier_name(): ?string {
		$supplier = $this->providers->github_supplier();
		return null === $supplier || in_array( $supplier, array( ProviderRegistry::GITHUB_BUNDLED, ProviderRegistry::GITHUB_SUPERSEDE_FAILED, ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT ), true )
			? null
			: self::plugin_name( $supplier );
	}

	/** One sentence naming who supplies GitHub, or null while the bundled module does. */
	public function sentence(): ?string {
		$names = implode( ', ', array_map( self::plugin_name( ... ), $this->providers->github_claimants() ) );
		return match ( $this->providers->github_supplier() ) {
			null, ProviderRegistry::GITHUB_BUNDLED => null,
			ProviderRegistry::GITHUB_SUPERSEDE_FAILED => '' === $names
				? __( 'GitHub is unavailable because the plugin that provides it could not load.', 'ran-booster' )
				/* translators: %s: plugin name. */
				: sprintf( __( 'GitHub is unavailable because %s could not load.', 'ran-booster' ), $names ),
			ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT => '' === $names
				? __( 'GitHub is unavailable because more than one plugin provides it.', 'ran-booster' )
				/* translators: %s: comma-separated plugin names. */
				: sprintf( __( 'GitHub is unavailable because more than one plugin provides it: %s.', 'ran-booster' ), $names ),
			/* translators: %s: plugin name. */
			default => sprintf( __( 'GitHub is provided by %s.', 'ran-booster' ), (string) $this->supplier_name() ),
		};
	}

	public function render(): void {
		$supplier = $this->providers->github_supplier();
		if ( null === $supplier || ! current_user_can( 'manage_options' ) || ! current_user_can( 'activate_plugins' ) || ! BoosterNoticeScope::allows() ) {
			return;
		}
		$shown = get_option( self::OPTION, ProviderRegistry::GITHUB_BUNDLED );
		if ( $shown === $supplier ) {
			return;
		}
		$intent = in_array( $supplier, array( ProviderRegistry::GITHUB_SUPERSEDE_FAILED, ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT ), true ) ? 'warning' : 'info';
		?>
		<div class="notice notice-<?php echo esc_attr( $intent ); ?><?php echo BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( $intent ); ?>" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo esc_attr( $intent ); ?>" data-ran-booster-ui-notice-scope="global"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
			<p><?php echo esc_html( $this->sentence() ?? __( 'GitHub is provided by RAN Booster again.', 'ran-booster' ) ); ?></p>
		</div><?php NoticeIcon::print_intent( $intent ); ?></div>
		<?php
		if ( ProviderRegistry::GITHUB_BUNDLED === $supplier ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $supplier, false );
		}
	}

	/** The plugin's name from its header, or its directory name when that is unavailable. */
	private static function plugin_name( string $directory ): string {
		foreach ( function_exists( 'get_plugins' ) ? get_plugins() : array() as $basename => $data ) {
			if ( str_starts_with( $basename, $directory . '/' ) && is_string( $data['Name'] ?? null ) && '' !== trim( $data['Name'] ) ) {
				return $data['Name'];
			}
		}
		return $directory;
	}
}
