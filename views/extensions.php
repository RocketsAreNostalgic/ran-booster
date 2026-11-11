<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/** @var list<array<string, mixed>> $extensions */
/** @var string $plugins_url */

defined( 'ABSPATH' ) || exit;

$extension_state_intents = array(
	'neutral' => 'none',
	'ok'      => 'stable',
	'error'   => 'high',
);
$extension_state_labels  = array(
	'Not installed'       => __( 'Not installed', 'ran-booster' ),
	'Incompatible'        => __( 'Incompatible', 'ran-booster' ),
	'Active'              => __( 'Active', 'ran-booster' ),
	'Installed, inactive' => __( 'Installed, inactive', 'ran-booster' ),
);
// The query carries only a fixed result code and catalogue ID, never error text.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice state; the install POST is independently authorized.
$result = isset( $_GET['ran_booster_extension_result'] ) && is_string( $_GET['ran_booster_extension_result'] ) ? wp_unslash( $_GET['ran_booster_extension_result'] ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The ID is matched against the fixed catalogue before use.
$result_id        = isset( $_GET['ran_booster_extension_id'] ) && is_string( $_GET['ran_booster_extension_id'] ) ? wp_unslash( $_GET['ran_booster_extension_id'] ) : '';
$result_messages  = array(
	'installed'                   => __( 'Extension installed.', 'ran-booster' ),
	'activated'                   => __( 'Extension activated.', 'ran-booster' ),
	'plugin_php_incompatible'     => __( 'Requires a different PHP version. Review in Plugins.', 'ran-booster' ),
	'plugin_wp_incompatible'      => __( 'Requires a different WordPress version. Review in Plugins.', 'ran-booster' ),
	'plugin_wp_php_incompatible'  => __( 'Requires different WordPress and PHP versions. Review in Plugins.', 'ran-booster' ),
	'plugin_missing_dependencies' => __( 'Requires other plugins to be installed and active. Review in Plugins.', 'ran-booster' ),
	'activation_failed'           => __( 'Activation could not be confirmed. Check its status in Plugins and try again.', 'ran-booster' ),
	'already_installed'           => __( 'The extension or its destination already exists. Review Plugins and the plugin folder before trying again.', 'ran-booster' ),
	'incompatible'                => __( 'This extension is not compatible with this version of Booster.', 'ran-booster' ),
	'failed'                      => __( 'The extension could not be installed. Try again or use its release page.', 'ran-booster' ),
	'uncertain'                   => __( 'The installation result could not be confirmed. Check Plugins before trying again.', 'ran-booster' ),
);
$result_extension = null;
foreach ( $extensions as $candidate ) {
	if ( $result_id === $candidate['id'] ) {
		$result_extension = $candidate;
		break;
	}
}
if ( 'activated' === $result && ( null === $result_extension || ! $result_extension['active'] ) ) {
	$result = '';
}
if ( 'installed' === $result && ( null === $result_extension || 'Installed, inactive' !== $result_extension['state'] || ! ( $result_extension['selected_release_is_installed'] ?? false ) ) ) {
	$result = '';
}
?>
<section class="ran-booster-page-shell ran-booster-extensions plugin-install-php" aria-labelledby="ran-booster-extensions-heading">
	<header class="ran-booster-page-shell__header ran-booster-extensions__header">
		<h2 id="ran-booster-extensions-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Extensions', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Add focused capabilities to Booster.', 'ran-booster' ); ?></p>
	</header>
	<div class="ran-booster-page-shell__body ran-booster-extensions__body">
		<?php if ( null !== $result_extension && isset( $result_messages[ $result ] ) ) : ?>
			<?php $result_intent = in_array( $result, array( 'installed', 'activated' ), true ) ? 'success' : 'warning'; ?>
			<div class="notice inline notice-<?php echo esc_attr( $result_intent ); ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( $result_intent ); ?>" role="status" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo esc_attr( $result_intent ); ?>"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><?php echo esc_html( $result_messages[ $result ] ); ?></p></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( $result_intent ); ?></div>
		<?php endif; ?>
		<div id="the-list" class="plugin-group ran-booster-extensions__grid">
		<?php foreach ( $extensions as $extension ) : ?>
			<?php
			$details_id           = 'ran-booster-extension-details-' . $extension['id'];
			$details_url          = '#' . $details_id;
			$availability_label   = $extension['availability'];
			$state_label          = $extension_state_labels[ $extension['state'] ] ?? $extension['state'];
			$manual_install_url   = is_string( $extension['manual_install_url'] ?? null ) ? $extension['manual_install_url'] : '';
			$release_is_installed = (bool) ( $extension['selected_release_is_installed'] ?? true );
			$compatibility_label  = $extension['compatibility_label'];
			$more_details_label   = sprintf(
				/* translators: %s: Extension name. */
				__( 'More details about %s', 'ran-booster' ),
				$extension['name']
			);
			?>
			<div class="plugin-card plugin-card-<?php echo esc_attr( $extension['id'] ); ?> ran-booster-extension-card ran-booster-card" data-extension="<?php echo esc_attr( $extension['id'] ); ?>" data-ran-booster-card="extensions"><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
				<div class="plugin-card-top">
					<div class="name column-name">
						<h3>
							<a class="ran-booster-extension-details-link" href="<?php echo esc_url( $details_url ); ?>" data-ran-booster-extension-details="<?php echo esc_attr( $extension['id'] ); ?>" data-title="<?php echo esc_attr( $extension['name'] ); ?>" aria-label="<?php echo esc_attr( $more_details_label ); ?>">
								<?php echo esc_html( $extension['name'] ); ?>
								<img class="plugin-icon" src="<?php echo esc_url( $extension['image_url'] ); ?>" alt="">
							</a>
						</h3>
					</div>
					<div class="action-links">
						<ul class="plugin-action-buttons">
								<li><div id="<?php echo esc_attr( 'ran-booster-extension-action-' . $extension['id'] ); ?>" class="ran-booster-extension-card__action">
								<?php if ( $extension['active'] ) : ?>
									<button type="button" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>" disabled aria-disabled="true"><?php esc_html_e( 'Active', 'ran-booster' ); ?></button>
								<?php elseif ( 'Installed, inactive' === $extension['state'] && current_user_can( 'activate_plugin', $extension['plugin'] ) && ! ( is_multisite() && is_network_only_plugin( $extension['plugin'] ) ) ) : ?>
									<form method="post" class="ran-booster-extension-action" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="ran_booster_extension_activate">
										<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension['id'] ); ?>">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'activate-plugin_' . $extension['plugin'] ) ); ?>">
										<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>" data-pending="<?php esc_attr_e( 'Activating…', 'ran-booster' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Extension name. */ _x( 'Activate %s', 'extension action', 'ran-booster' ), $extension['name'] ) ); ?>"><?php esc_html_e( 'Activate', 'ran-booster' ); ?></button>
									</form>
								<?php elseif ( $extension['installed'] ) : ?>
									<button type="button" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>" disabled aria-disabled="true"><?php esc_html_e( 'Inactive', 'ran-booster' ); ?></button>
									<?php if ( $extension['compatible'] ) : ?>
										<span class="description"><?php echo esc_html( is_multisite() && is_network_only_plugin( $extension['plugin'] ) ? __( 'A network administrator must activate this plugin.', 'ran-booster' ) : __( 'You do not have permission to activate this plugin.', 'ran-booster' ) ); ?></span>
									<?php endif; ?>
								<?php elseif ( $extension['compatible'] && '' !== $manual_install_url && current_user_can( 'install_plugins' ) ) : ?>
									<form method="post" class="ran-booster-extension-action" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="ran_booster_extension_install">
										<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension['id'] ); ?>">
										<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'ran_booster_extension_install_' . $extension['id'] ) ); ?>">
										<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>" data-pending="<?php esc_attr_e( 'Installing…', 'ran-booster' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Extension name. */ _x( 'Install %s', 'extension action', 'ran-booster' ), $extension['name'] ) ); ?>"><?php esc_html_e( 'Install', 'ran-booster' ); ?></button>
									</form>
								<?php elseif ( $extension['compatible'] && '' !== $manual_install_url ) : ?>
									<span class="description"><?php esc_html_e( 'You do not have permission to install plugins.', 'ran-booster' ); ?></span>
								<?php endif; ?>
								</div></li>
								<li>
									<a class="ran-booster-extension-details-link" href="<?php echo esc_url( $details_url ); ?>" data-ran-booster-extension-details="<?php echo esc_attr( $extension['id'] ); ?>" data-title="<?php echo esc_attr( $extension['name'] ); ?>" aria-label="<?php echo esc_attr( $more_details_label ); ?>"><?php esc_html_e( 'More details', 'ran-booster' ); ?></a>
								<?php if ( $extension['installed'] ) : ?>
									<br><a href="<?php echo esc_url( add_query_arg( 's', $extension['installed_name'], $plugins_url ) ); ?>"><?php esc_html_e( 'Manage plugin', 'ran-booster' ); ?></a>
								<?php endif; ?>
								</li>
						</ul>
					</div>
					<div class="desc column-description">
						<p><?php echo esc_html( $extension['description'] ); ?></p>
						<p class="authors">
							<cite>
								<?php esc_html_e( 'By', 'ran-booster' ); ?>
								<a href="https://github.com/RocketsAreNostalgic">Rockets Are Nostalgic</a>
								<span aria-hidden="true"> · </span>
								<a href="<?php echo esc_url( $extension['support_url'] ); ?>"><?php esc_html_e( 'Support', 'ran-booster' ); ?></a>
							</cite>
						</p>
					</div>
				</div>
				<div class="plugin-card-bottom">
					<div class="vers column-rating ran-booster-extension-card__metadata">
						<?php echo \RAN\Admin\Component\StatusBadge::for_intent( (string) $availability_label, 'none', array( 'class' => 'ran-booster-extension-card__badge' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?>
						<?php $beta_label = __( 'Beta', 'ran-booster' ); ?>
						<?php echo \RAN\Admin\Component\StatusBadge::for_intent( (string) $beta_label, 'none', array( 'class' => 'ran-booster-extension-card__badge' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?>
						<?php echo \RAN\Admin\Component\StatusBadge::for_intent( (string) $state_label, (string) $extension_state_intents[ $extension['state_kind'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?>
					</div>
					<div class="column-compatibility">
					<?php if ( $extension['compatible'] ) : ?>
						<span class="compatibility-compatible">
						<?php
						if ( $release_is_installed ) :
							?>
							<strong><?php esc_html_e( 'Compatible', 'ran-booster' ); ?></strong> <?php esc_html_e( 'with your version of Booster', 'ran-booster' ); ?>
							<?php
else :
	?>
							<?php echo esc_html( $compatibility_label ); ?><?php endif; ?></span>
					<?php else : ?>
						<span class="compatibility-incompatible"><?php echo esc_html( $compatibility_label ); ?></span>
					<?php endif; ?>
					</div>
				</div>
			</div></div></div>
		<?php endforeach; ?>
		</div>
		<?php
		// Dialog tabs, in order. Each section renders its panel below; add an
		// entry and its panel to grow the set. One Details tab is intended.
		$details_sections = array(
			array(
				'id'    => 'details',
				'label' => __( 'Details', 'ran-booster' ),
			),
		);
		?>
		<?php foreach ( $extensions as $extension ) : ?>
			<?php
			$details_id          = 'ran-booster-extension-details-' . $extension['id'];
			$details_title_id    = $details_id . '-title';
			$state_label         = $extension_state_labels[ $extension['state'] ] ?? $extension['state'];
			$compatibility_label = $extension['compatibility_label'];
			$banner_url          = is_string( $extension['banner_url'] ?? null ) ? $extension['banner_url'] : '';
			$banner_2x_url       = is_string( $extension['banner_2x_url'] ?? null ) ? $extension['banner_2x_url'] : '';
			$release_version     = is_string( $extension['release_version'] ?? null ) ? $extension['release_version'] : '';
			$installed_version   = $extension['installed'] && is_string( $extension['installed_version'] ?? null ) ? $extension['installed_version'] : '';
			$repository          = is_string( $extension['repository'] ?? null ) ? $extension['repository'] : '';
			$repository_url      = is_string( $extension['canonical_update_uri'] ?? null ) ? $extension['canonical_update_uri'] : '';
			?>
			<section id="<?php echo esc_attr( $details_id ); ?>" class="ran-booster-extension-details-host" aria-labelledby="<?php echo esc_attr( $details_title_id ); ?>" data-ran-booster-dialog-sections="<?php echo esc_attr( implode( ' ', array_column( $details_sections, 'id' ) ) ); ?>" hidden>
				<div id="<?php echo esc_attr( $details_id . '-banner' ); ?>" class="ran-booster-extension-banner<?php echo '' === $banner_url ? ' ran-booster-extension-banner--fallback' : ''; ?>">
				<?php if ( '' !== $banner_url ) : ?>
					<img class="ran-booster-extension-banner__image" src="<?php echo esc_url( $banner_url ); ?>"<?php echo '' !== $banner_2x_url ? ' srcset="' . esc_attr( esc_url( $banner_url ) . ' 1x, ' . esc_url( $banner_2x_url ) . ' 2x' ) . '"' : ''; ?> width="772" height="250" alt="" decoding="async">
				<?php else : ?>
					<img class="ran-booster-extension-banner__icon" src="<?php echo esc_url( $extension['image_url'] ); ?>" width="128" height="128" alt="">
				<?php endif; ?>
				</div>
				<h2 id="<?php echo esc_attr( $details_title_id ); ?>" class="ran-booster-extension-details-host__title"><?php echo esc_html( $extension['name'] ); ?></h2>
				<p class="ran-booster-extension-details-host__description"><?php echo esc_html( $extension['description'] ); ?></p>
				<div id="<?php echo esc_attr( $details_id . '-content' ); ?>" class="ran-booster-extension-details">
					<div class="ran-booster-extension-details__main">
					<?php foreach ( $details_sections as $details_section ) : ?>
						<div id="<?php echo esc_attr( $details_id . '-section-' . $details_section['id'] ); ?>" class="ran-booster-extension-details__section" data-ran-booster-dialog-section-label="<?php echo esc_attr( $details_section['label'] ); ?>">
						<?php if ( 'details' === $details_section['id'] ) : ?>
							<?php if ( '' !== $extension['details'] ) : ?>
								<h3><?php esc_html_e( 'About this extension', 'ran-booster' ); ?></h3>
								<p><?php echo esc_html( $extension['details'] ); ?></p>
							<?php endif; ?>
							<?php if ( array() !== $extension['features'] ) : ?>
								<h3><?php esc_html_e( 'What it adds', 'ran-booster' ); ?></h3>
								<ul>
								<?php foreach ( $extension['features'] as $feature ) : ?>
									<li><?php echo esc_html( $feature ); ?></li>
								<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<h3><?php esc_html_e( 'Requirements', 'ran-booster' ); ?></h3>
							<ul>
							<?php foreach ( $extension['requirements'] as $requirement ) : ?>
								<li><?php echo esc_html( $requirement ); ?></li>
							<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						</div>
					<?php endforeach; ?>
					</div>
					<div id="<?php echo esc_attr( $details_id . '-aside' ); ?>" class="ran-booster-extension-details__sidebar">
						<h3><?php esc_html_e( 'Extension details', 'ran-booster' ); ?></h3>
						<dl>
						<?php if ( '' !== $release_version ) : ?>
							<dt><?php esc_html_e( 'Version', 'ran-booster' ); ?></dt>
							<dd><?php echo esc_html( $release_version ); ?></dd>
						<?php endif; ?>
						<?php if ( '' !== $installed_version ) : ?>
							<dt><?php esc_html_e( 'Installed version', 'ran-booster' ); ?></dt>
							<dd><?php echo esc_html( $installed_version ); ?></dd>
						<?php endif; ?>
							<dt><?php esc_html_e( 'Status', 'ran-booster' ); ?></dt>
							<dd><?php echo esc_html( $state_label ); ?></dd>
							<dt><?php esc_html_e( 'Compatibility', 'ran-booster' ); ?></dt>
							<dd class="ran-booster-extension-details__compatibility<?php echo $extension['compatible'] ? '' : ' ran-booster-extension-details__compatibility--incompatible'; ?>">
								<span aria-hidden="true"><?php echo $extension['compatible'] ? '✓' : '×'; ?></span>
								<?php echo esc_html( $compatibility_label ); ?>
							</dd>
						<?php if ( '' !== $repository && '' !== $repository_url ) : ?>
							<dt><?php esc_html_e( 'Source', 'ran-booster' ); ?></dt>
							<dd><a href="<?php echo esc_url( $repository_url ); ?>"><?php echo esc_html( $repository ); ?></a></dd>
						<?php endif; ?>
						</dl>
						<p><a href="<?php echo esc_url( $extension['docs_url'] ); ?>"><?php esc_html_e( 'Documentation', 'ran-booster' ); ?></a></p>
						<?php if ( ! empty( $extension['manual_install_url'] ) ) : ?>
							<p><a href="<?php echo esc_url( $extension['manual_install_url'] ); ?>"><?php esc_html_e( 'Release page', 'ran-booster' ); ?></a></p>
						<?php endif; ?>
						<p><a href="<?php echo esc_url( $extension['support_url'] ); ?>"><?php esc_html_e( 'Support', 'ran-booster' ); ?></a></p>
					</div>
				</div>
			</section>
		<?php endforeach; ?>
	</div>
</section>
