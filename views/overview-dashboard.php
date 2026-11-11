<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller.
/**
 * Dashboard sections share the Overview payload and storage flow.
 *
 * @var array<string, mixed> $onboarding
 * @var list<array{code: string, label: string, url: string, admitted: ?bool, selection: ?string, source_kind?: ?string, source_name?: ?string}> $provider_summaries
 * @var array<string, string> $provider_selection_labels
 * @var array{status: string, total: ?int, items: list<array{provider: string, provider_label: string, label: string, badge_label: string, stage: string, effective_expires_at: string, source: string, observed_at: ?string, url: string}>} $credential_expiry
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! empty( $overview_dashboard_activity ) ) {
	$state_tones  = array(
		'queued'          => 'pending',
		'running'         => 'pending',
		'succeeded'       => 'ok',
		'updated'         => 'ok',
		'failed'          => 'error',
		'needs_attention' => 'error',
	);
	$state_labels = array(
		'queued'          => __( 'Queued', 'ran-booster' ),
		'running'         => __( 'Running', 'ran-booster' ),
		'incomplete'      => __( 'Unconfirmed', 'ran-booster' ),
		'succeeded'       => __( 'Succeeded', 'ran-booster' ),
		'updated'         => __( 'Updated', 'ran-booster' ),
		'failed'          => __( 'Failed', 'ran-booster' ),
		'rejected'        => __( 'Rejected', 'ran-booster' ),
		'skipped'         => __( 'Skipped', 'ran-booster' ),
		'needs_attention' => __( 'Needs attention', 'ran-booster' ),
	);
	$activity     = $onboarding['activity'] ?? array(
		'available' => false,
		'records'   => array(),
	);
	?>
	<section class="ran-booster-panel ran-booster-onboarding__storage ran-booster-card" data-ran-booster-card="overview" aria-labelledby="ran-booster-overview-activity"><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
		<h3 class="ran-booster-overview-card-title" id="ran-booster-overview-activity"><?php esc_html_e( 'Recent Activity', 'ran-booster' ); ?></h3>
		<?php if ( empty( $activity['available'] ) ) { ?>
			<p class="ran-booster-overview-card-note"><?php esc_html_e( 'Activity is unavailable.', 'ran-booster' ); ?></p>
		<?php } elseif ( empty( $activity['records'] ) ) { ?>
			<p class="ran-booster-overview-card-note"><?php esc_html_e( 'No recent activity.', 'ran-booster' ); ?></p>
		<?php } else { ?>
			<ul class="ran-booster-overview-activity__list">
				<?php
				foreach ( array_slice( $activity['records'], 0, 5 ) as $record ) {
					$state = $record['status'];
					?>
					<li class="ran-booster-overview-activity__row">
						<div><strong><?php echo esc_html( $record['operation'] ); ?></strong><span aria-hidden="true"> · </span><span class="ran-booster-overview-activity__time"><?php echo esc_html( $record['sort_at'] ); ?></span></div>
						<?php echo \RAN\Admin\Component\StatusBadge::markup( $state_labels[ $state ] ?? __( 'Unknown', 'ran-booster' ), $state_tones[ $state ] ?? 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?>
					</li>
				<?php } ?>
			</ul>
		<?php } ?>
		<p class="ran-booster-overview-card-note"><a href="<?php echo esc_url( $onboarding['activity_url'] ); ?>"><?php esc_html_e( 'View Activity', 'ran-booster' ); ?></a></p>
	</div></div></section>
	<?php
	return;
}
$inventory = $onboarding['inventory'] ?? array(
	'plugins'  => null,
	'themes'   => null,
	'disabled' => null,
	'complete' => false,
);
?>
<section class="ran-booster-panel ran-booster-onboarding__storage ran-booster-card" data-ran-booster-card="overview" aria-labelledby="ran-booster-overview-packages"><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
	<h3 class="ran-booster-overview-card-title" id="ran-booster-overview-packages"><?php esc_html_e( 'Managed packages', 'ran-booster' ); ?></h3>
	<div class="ran-booster-overview-package-actions">
		<div class="ran-booster-overview-package-actions__group" role="group" aria-label="<?php esc_attr_e( 'Plugins', 'ran-booster' ); ?>">
			<p><strong><?php esc_html_e( 'Plugins', 'ran-booster' ); ?></strong> <span class="ran-booster-overview-package-count"><?php echo esc_html( null === $inventory['plugins'] ? __( 'Unknown', 'ran-booster' ) : sprintf( /* translators: %s: managed package count. */ __( '%s managed', 'ran-booster' ), (string) $inventory['plugins'] ) ); ?></span></p>
			<div class="ran-booster-overview-package-links">
				<a href="<?php echo esc_url( $onboarding['plugins_url'] ); ?>"><?php esc_html_e( 'Manage plugins', 'ran-booster' ); ?></a>
				<a href="<?php echo esc_url( $onboarding['install_plugin_url'] ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
			</div>
		</div>
		<div class="ran-booster-overview-package-actions__group" role="group" aria-label="<?php esc_attr_e( 'Themes', 'ran-booster' ); ?>">
			<p><strong><?php esc_html_e( 'Themes', 'ran-booster' ); ?></strong> <span class="ran-booster-overview-package-count"><?php echo esc_html( null === $inventory['themes'] ? __( 'Unknown', 'ran-booster' ) : sprintf( /* translators: %s: managed package count. */ __( '%s managed', 'ran-booster' ), (string) $inventory['themes'] ) ); ?></span></p>
			<div class="ran-booster-overview-package-links">
				<a href="<?php echo esc_url( $onboarding['themes_url'] ); ?>"><?php esc_html_e( 'Manage themes', 'ran-booster' ); ?></a>
				<a href="<?php echo esc_url( $onboarding['install_theme_url'] ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a>
			</div>
		</div>
	</div>
	<p class="ran-booster-overview-package-policy"><?php esc_html_e( 'Updates disabled:', 'ran-booster' ); ?> <?php echo esc_html( null === $inventory['disabled'] ? __( 'Unknown', 'ran-booster' ) : (string) $inventory['disabled'] ); ?></p>
</div></div></section>
<section class="ran-booster-panel ran-booster-onboarding__storage ran-booster-card" data-ran-booster-card="overview" aria-labelledby="ran-booster-overview-providers"><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
	<h3 class="ran-booster-overview-card-title" id="ran-booster-overview-providers"><?php esc_html_e( 'Git providers', 'ran-booster' ); ?></h3>
	<?php if ( array() === $provider_summaries ) { ?>
		<p class="ran-booster-overview-card-note"><?php esc_html_e( 'No provider settings are currently available.', 'ran-booster' ); ?></p>
	<?php } else { ?>
		<ul class="ran-booster-overview-provider-list">
		<?php
		foreach ( $provider_summaries as $provider ) {
			$provider_uses_cloud_name = 'extension' === ( $provider['source_kind'] ?? null ) && 'bb' === $provider['code'] && 'Bitbucket Cloud' === ( $provider['source_name'] ?? null ) && in_array( $provider['label'], array( 'Bitbucket', 'Bitbucket Cloud' ), true );
			?>
			<li><a class="ran-booster-overview-provider-link" href="<?php echo esc_url( $provider['url'] ); ?>"><strong><?php echo esc_html( $provider_uses_cloud_name ? $provider['source_name'] : $provider['label'] ); ?></strong><span><?php esc_html_e( 'Manage provider', 'ran-booster' ); ?> <span aria-hidden="true">→</span></span></a>
				<span class="ran-booster-overview-provider-origin">
				<?php if ( 'bundled' === ( $provider['source_kind'] ?? null ) ) { ?>
					<?php esc_html_e( 'Included with Booster', 'ran-booster' ); ?>
				<?php } elseif ( $provider_uses_cloud_name ) { ?>
					<?php esc_html_e( 'Provider extension', 'ran-booster' ); ?>
				<?php } elseif ( is_string( $provider['source_name'] ?? null ) && '' !== $provider['source_name'] ) { ?>
					<?php /* translators: %s: selected provider plugin name. */ ?>
					<?php echo esc_html( sprintf( __( 'Provider extension: %s', 'ran-booster' ), $provider['source_name'] ) ); ?>
				<?php } else { ?>
					<?php esc_html_e( 'Provider source unknown', 'ran-booster' ); ?>
				<?php } ?>
				<?php
				if ( 'replacement' === ( $provider['source_kind'] ?? null ) ) {
					$custom_provider_label = __( 'Custom provider', 'ran-booster' );
					?>
					<?php echo \RAN\Admin\Component\StatusBadge::for_intent( (string) $custom_provider_label, 'none' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?><?php } ?>
				</span>
			</li>
		<?php } ?></ul>
	<?php } ?>
</div></div></section>
