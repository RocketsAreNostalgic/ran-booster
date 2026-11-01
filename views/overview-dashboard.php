<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller.
/**
 * Dashboard sections share the Overview payload and storage flow.
 *
 * @var array<string, mixed> $onboarding
 * @var list<array{code: string, label: string, url: string, admitted: ?bool, selection: ?string}> $provider_summaries
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
	<section class="ran-booster-panel ran-booster-onboarding__storage" aria-labelledby="ran-booster-overview-activity">
		<h3 id="ran-booster-overview-activity"><?php esc_html_e( 'Recent Activity', 'ran-booster' ); ?></h3>
		<?php if ( empty( $activity['available'] ) ) { ?>
			<p><?php esc_html_e( 'Activity is unavailable.', 'ran-booster' ); ?></p>
		<?php } elseif ( empty( $activity['records'] ) ) { ?>
			<p><?php esc_html_e( 'No recent activity.', 'ran-booster' ); ?></p>
		<?php } else { ?>
			<ul class="ran-booster-overview-activity__list">
				<?php
				foreach ( array_slice( $activity['records'], 0, 5 ) as $record ) {
					$state = $record['status'];
					?>
					<li class="ran-booster-overview-activity__row">
						<div><strong><?php echo esc_html( $record['operation'] ); ?></strong><span aria-hidden="true"> · </span><span class="ran-booster-overview-activity__time"><?php echo esc_html( $record['sort_at'] ); ?></span></div>
						<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $state_tones[ $state ] ?? 'neutral' ); ?>"><?php echo esc_html( $state_labels[ $state ] ?? __( 'Unknown', 'ran-booster' ) ); ?></span>
					</li>
				<?php } ?>
			</ul>
		<?php } ?>
		<p><a href="<?php echo esc_url( $onboarding['activity_url'] ); ?>"><?php esc_html_e( 'View Activity', 'ran-booster' ); ?></a></p>
	</section>
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
<section class="ran-booster-panel ran-booster-onboarding__storage" aria-labelledby="ran-booster-overview-packages">
	<h3 id="ran-booster-overview-packages"><?php esc_html_e( 'Managed packages', 'ran-booster' ); ?></h3>
	<ul class="ran-booster-onboarding__package-metrics">
		<li><strong><?php esc_html_e( 'Plugins:', 'ran-booster' ); ?></strong> <?php echo esc_html( null === $inventory['plugins'] ? __( 'Unknown', 'ran-booster' ) : (string) $inventory['plugins'] ); ?></li>
		<li><strong><?php esc_html_e( 'Themes:', 'ran-booster' ); ?></strong> <?php echo esc_html( null === $inventory['themes'] ? __( 'Unknown', 'ran-booster' ) : (string) $inventory['themes'] ); ?></li>
		<li><strong><?php esc_html_e( 'Updates disabled:', 'ran-booster' ); ?></strong> <?php echo esc_html( null === $inventory['disabled'] ? __( 'Unknown', 'ran-booster' ) : (string) $inventory['disabled'] ); ?></li>
	</ul>
	<div class="ran-booster-overview-package-actions">
		<a class="button button-primary" href="<?php echo esc_url( $onboarding['install_plugin_url'] ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
		<a class="button" href="<?php echo esc_url( $onboarding['plugins_url'] ); ?>"><?php esc_html_e( 'Manage plugins', 'ran-booster' ); ?></a>
		<a class="button button-primary" href="<?php echo esc_url( $onboarding['install_theme_url'] ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a>
		<a class="button" href="<?php echo esc_url( $onboarding['themes_url'] ); ?>"><?php esc_html_e( 'Manage themes', 'ran-booster' ); ?></a>
	</div>
</section>
<section class="ran-booster-panel ran-booster-onboarding__storage" aria-labelledby="ran-booster-overview-providers">
	<h3 id="ran-booster-overview-providers"><?php esc_html_e( 'Providers and access', 'ran-booster' ); ?></h3>
	<p><?php esc_html_e( 'Repository access has not been checked here.', 'ran-booster' ); ?></p>
	<?php if ( array() === $provider_summaries ) { ?>
		<p><?php esc_html_e( 'No provider settings are currently available.', 'ran-booster' ); ?></p>
	<?php } else { ?>
		<ul><?php foreach ( $provider_summaries as $provider ) { ?>
			<li><a href="<?php echo esc_url( $provider['url'] ); ?>"><?php echo esc_html( $provider['label'] ); ?></a> <span class="ran-booster-pill ran-booster-pill--<?php echo esc_attr( true === $provider['admitted'] ? 'ok' : ( false === $provider['admitted'] ? 'error' : 'info' ) ); ?>"><?php echo esc_html( true === $provider['admitted'] ? __( 'Admitted', 'ran-booster' ) : ( false === $provider['admitted'] ? __( 'Not admitted', 'ran-booster' ) : __( 'Admission unknown', 'ran-booster' ) ) ); ?></span>
			<?php
			if ( 'gh' === $provider['code'] ) {
				?>
				— <?php echo esc_html( $provider_selection_labels[ $provider['selection'] ?? '' ] ?? __( 'Selection unknown', 'ran-booster' ) ); ?><?php } ?></li>
		<?php } ?></ul>
	<?php } ?>
</section>
