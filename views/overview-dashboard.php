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
	$activity = $onboarding['activity'] ?? array(
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
			<ul>
				<?php foreach ( array_slice( $activity['records'], 0, 5 ) as $record ) { ?>
					<li><?php echo esc_html( $record['operation'] ); ?> — <?php echo esc_html( $record['status'] ); ?> — <?php echo esc_html( $record['sort_at'] ); ?></li>
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
	<p><?php esc_html_e( 'Plugins:', 'ran-booster' ); ?> <?php echo esc_html( null === $inventory['plugins'] ? __( 'Unknown', 'ran-booster' ) : (string) $inventory['plugins'] ); ?> · <?php esc_html_e( 'Themes:', 'ran-booster' ); ?> <?php echo esc_html( null === $inventory['themes'] ? __( 'Unknown', 'ran-booster' ) : (string) $inventory['themes'] ); ?> · <?php esc_html_e( 'Updates disabled:', 'ran-booster' ); ?> <?php echo esc_html( null === $inventory['disabled'] ? __( 'Unknown', 'ran-booster' ) : (string) $inventory['disabled'] ); ?></p>
	<p><a href="<?php echo esc_url( $onboarding['plugins_url'] ); ?>"><?php esc_html_e( 'Manage plugins', 'ran-booster' ); ?></a> · <a href="<?php echo esc_url( $onboarding['themes_url'] ); ?>"><?php esc_html_e( 'Manage themes', 'ran-booster' ); ?></a></p>
	<p><a class="button button-primary" href="<?php echo esc_url( $onboarding['install_plugin_url'] ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a> <a class="button" href="<?php echo esc_url( $onboarding['install_theme_url'] ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a> <a href="<?php echo esc_url( $onboarding['portability_url'] ); ?>"><?php esc_html_e( 'Move an existing Booster setup', 'ran-booster' ); ?></a></p>
</section>
<section class="ran-booster-panel ran-booster-onboarding__storage" aria-labelledby="ran-booster-overview-providers">
	<h3 id="ran-booster-overview-providers"><?php esc_html_e( 'Providers and access', 'ran-booster' ); ?></h3>
	<p><?php esc_html_e( 'Repository access has not been checked here.', 'ran-booster' ); ?></p>
	<?php if ( array() === $provider_summaries ) { ?>
		<p><?php esc_html_e( 'No provider settings are currently available.', 'ran-booster' ); ?></p>
	<?php } else { ?>
		<ul><?php foreach ( $provider_summaries as $provider ) { ?>
			<li><a href="<?php echo esc_url( $provider['url'] ); ?>"><?php echo esc_html( $provider['label'] ); ?></a> — <?php echo esc_html( true === $provider['admitted'] ? __( 'Admitted', 'ran-booster' ) : ( false === $provider['admitted'] ? __( 'Not admitted', 'ran-booster' ) : __( 'Admission unknown', 'ran-booster' ) ) ); ?>
			<?php
			if ( 'gh' === $provider['code'] ) {
				?>
				— <?php echo esc_html( $provider_selection_labels[ $provider['selection'] ?? '' ] ?? __( 'Selection unknown', 'ran-booster' ) ); ?><?php } ?></li>
		<?php } ?></ul>
	<?php } ?>
</section>
<section class="ran-booster-panel ran-booster-onboarding__storage" aria-labelledby="ran-booster-overview-expiry">
	<h3 id="ran-booster-overview-expiry"><?php esc_html_e( 'Credential expiry', 'ran-booster' ); ?></h3>
	<?php if ( 'captured' === $credential_expiry['status'] ) { ?>
		<p><?php esc_html_e( 'These reminders cover credentials with actionable expiry information; they are not a complete credential inventory.', 'ran-booster' ); ?></p>
		<?php if ( array() === $credential_expiry['items'] ) { ?>
			<p><?php esc_html_e( 'No actionable expiry reminders were captured.', 'ran-booster' ); ?></p>
		<?php } else { ?>
			<ul>
				<?php foreach ( $credential_expiry['items'] as $expiry_item ) { ?>
					<li><a href="<?php echo esc_url( $expiry_item['url'] ); ?>"><?php echo esc_html( $expiry_item['provider_label'] ); ?>: <?php echo esc_html( $expiry_item['label'] ); ?></a> — <?php echo esc_html( $expiry_item['badge_label'] ); ?>; <?php esc_html_e( 'Expires:', 'ran-booster' ); ?> <?php echo esc_html( $expiry_item['effective_expires_at'] ); ?>; <?php esc_html_e( 'Source:', 'ran-booster' ); ?> <?php echo esc_html( $expiry_item['source'] ); ?>
					<?php
					if ( null !== $expiry_item['observed_at'] ) {
						?>
						; <?php esc_html_e( 'Observed:', 'ran-booster' ); ?> <?php echo esc_html( $expiry_item['observed_at'] ); ?><?php } ?></li>
				<?php } ?>
			</ul>
			<?php if ( null !== $credential_expiry['total'] && $credential_expiry['total'] > count( $credential_expiry['items'] ) ) { ?>
				<?php // translators: 1: displayed reminders, 2: total actionable reminders. ?>
				<p><?php echo esc_html( sprintf( __( 'Showing %1$d of %2$d actionable expiry reminders.', 'ran-booster' ), count( $credential_expiry['items'] ), $credential_expiry['total'] ) ); ?></p>
			<?php } ?>
		<?php } ?>
	<?php } elseif ( 'unavailable' === $credential_expiry['status'] ) { ?>
		<p><?php esc_html_e( 'Credential expiry reminders are unavailable. Review credentials in provider settings.', 'ran-booster' ); ?></p>
	<?php } else { ?>
		<p><?php esc_html_e( 'Current credential expiry is unknown. Review credentials in provider settings.', 'ran-booster' ); ?></p>
	<?php } ?>
	<?php foreach ( $onboarding['provider_links'] as $provider_link ) { ?>
		<a href="<?php echo esc_url( $provider_link['url'] ); ?>"><?php echo esc_html( $provider_link['label'] ); ?></a>
	<?php } ?>
</section>
