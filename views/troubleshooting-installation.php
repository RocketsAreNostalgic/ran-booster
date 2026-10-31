<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.
/**
 * @var array<string, mixed> $troubleshooting
 * @var array<string, mixed> $core_self_update
 */
if ( ! defined( 'WPINC' ) ) {
	die; }
$installation        = is_array( $troubleshooting['installation_details'] ?? null ) ? $troubleshooting['installation_details'] : array();
$github_fields       = $installation[ __( 'GitHub provider', 'ran-booster' ) ] ?? array();
$summary_labels      = array( __( 'Name', 'ran-booster' ), __( 'Version', 'ran-booster' ), __( 'Source', 'ran-booster' ), __( 'Selection state', 'ran-booster' ), __( 'Operational availability', 'ran-booster' ) );
$environment         = is_array( $troubleshooting['environment'] ?? null ) ? $troubleshooting['environment'] : array();
$environment_summary = array_intersect_key( $environment, array_flip( array( 'WordPress', 'PHP' ) ) );
$environment_details = array_diff_key( $environment, $environment_summary );
$storage             = is_array( $troubleshooting['storage'] ?? null ) ? $troubleshooting['storage'] : array();
$components          = is_array( $troubleshooting['components'] ?? null ) ? $troubleshooting['components'] : array();
?>
<section id="ran-booster-installation-details" aria-labelledby="ran-booster-installation-heading">
<h3 id="ran-booster-installation-heading"><?php esc_html_e( 'Installation', 'ran-booster' ); ?></h3>
<section class="ran-booster-panel ran-booster-page-shell ran-booster-troubleshooting__installation-card">
<header class="ran-booster-page-shell__header"><h4 class="ran-booster-section__title"><?php esc_html_e( 'Environment', 'ran-booster' ); ?></h4></header>
<div class="ran-booster-page-shell__body">
<?php
if ( array() === $environment ) {
	?>
	<p><?php esc_html_e( 'Environment information is unavailable.', 'ran-booster' ); ?></p><?php } else { ?>
<dl class="ran-booster-troubleshooting__fields-list">
	<?php
	foreach ( $environment_summary as $label => $value ) {
		?>
	<div><dt><?php echo esc_html( $label ); ?></dt><dd><code><?php echo esc_html( $value ); ?></code></dd></div><?php } ?></dl><?php } ?>
<?php if ( array() !== $environment_details ) { ?>
<details class="ran-booster-troubleshooting__disclosure ran-booster-disclosure"><summary><?php esc_html_e( 'Environment details', 'ran-booster' ); ?></summary>
	<div class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body">
		<dl class="ran-booster-troubleshooting__fields-list">
		<?php foreach ( $environment_details as $label => $value ) { ?>
			<div><dt><?php echo esc_html( $label ); ?></dt><dd><code><?php echo esc_html( $value ); ?></code></dd></div><?php } ?>
		</dl>
	</div>
</details><?php } ?>
</div></section>
<section class="ran-booster-panel ran-booster-page-shell ran-booster-troubleshooting__installation-card">
<header class="ran-booster-page-shell__header"><h4 class="ran-booster-section__title"><?php esc_html_e( 'Storage', 'ran-booster' ); ?></h4></header>
<div class="ran-booster-page-shell__body">
<p><?php echo esc_html( $storage['message'] ?? __( 'Storage status is unavailable.', 'ran-booster' ) ); ?></p>
<?php
if ( ! empty( $storage['details'] ) && is_array( $storage['details'] ) ) {
	?>
	<dl class="ran-booster-troubleshooting__fields-list">
	<?php
	foreach ( $storage['details'] as $label => $value ) {
		?>
	<div><dt><?php echo esc_html( $label ); ?></dt><dd><code><?php echo esc_html( $value ); ?></code></dd></div><?php } ?></dl><?php } ?>
<?php
if ( 'actionable' === ( $storage['state'] ?? '' ) && ! empty( $storage['url'] ) ) {
	?>
	<p><a href="<?php echo esc_url( $storage['url'] ); ?>"><?php esc_html_e( 'Review storage settings', 'ran-booster' ); ?></a></p><?php } ?>
</div></section>
			<?php
			$self_update_mode   = $core_self_update['effective_mode'] ?? 'disabled';
			$self_update_reason = $core_self_update['reason'] ?? 'release_marker_missing_or_invalid';
			$updater_state      = $core_self_update['updater_state'] ?? null;
			if ( array() === $core_self_update ) {
				$self_update_message = __( 'Core update status is unavailable.', 'ran-booster' );
			} elseif ( in_array( $self_update_reason, array( 'configuration_disabled', 'preference_disabled' ), true ) ) {
				$self_update_message = __( 'Core updates are off because this site explicitly disables them. Booster will not check for or replace itself.', 'ran-booster' );
			} elseif ( 'unavailable' === $updater_state || 'blocked' === $updater_state ) {
				$self_update_message = __( 'RAN Booster could not check for Core updates. The installed version will keep working, and Booster will retry automatically.', 'ran-booster' );
			} elseif ( 'enabled' === $self_update_mode ) {
				$self_update_message = __( 'Core updates are available manually for this verified release installation. Background Core updates remain off.', 'ran-booster' );
			} elseif ( 'configuration_invalid' === $self_update_reason ) {
				$self_update_message = __( 'Core updates are off because this installation has an invalid self-update setting. Booster will not check for or replace itself.', 'ran-booster' );
			} else {
				$self_update_message = __( 'Core updates are off for this source installation. Booster will not check for or replace itself.', 'ran-booster' );
			}
			?>
			<section class="ran-booster-panel ran-booster-page-shell ran-booster-troubleshooting__core-updates ran-booster-troubleshooting__installation-card" aria-labelledby="ran-booster-core-updates-heading">
				<header class="ran-booster-page-shell__header"><h4 id="ran-booster-core-updates-heading" class="ran-booster-section__title"><?php esc_html_e( 'Booster Core updates', 'ran-booster' ); ?></h4></header>
				<div class="ran-booster-page-shell__body">
					<p><?php echo esc_html( $self_update_message ); ?></p>
					<details class="ran-booster-troubleshooting__disclosure ran-booster-disclosure"><summary><?php esc_html_e( 'Technical details', 'ran-booster' ); ?></summary>
						<div class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body">
						<dl class="ran-booster-troubleshooting__fields-list">
						<?php
						foreach ( $installation[ __( 'Booster Core updates', 'ran-booster' ) ] ?? array() as $field_label => $field_value ) {
							?>
							<div><dt><?php echo esc_html( $field_label ); ?></dt><dd><code><?php echo esc_html( $field_value ); ?></code></dd></div><?php } ?></dl>
						</div>
					</details>
				</div>
			</section>
<section class="ran-booster-panel ran-booster-page-shell ran-booster-troubleshooting__installation-card">
<header class="ran-booster-page-shell__header"><h4 class="ran-booster-section__title"><?php esc_html_e( 'Providers and extensions', 'ran-booster' ); ?></h4></header>
<div class="ran-booster-page-shell__body">
<?php if ( ! empty( $troubleshooting['components_truncated'] ) ) { ?>
<p><?php esc_html_e( 'Only the first 32 admitted components are shown, alongside the fixed extension catalogue. Additional declarations are omitted.', 'ran-booster' ); ?></p>
<?php } ?>
<?php
if ( array() === $components ) {
	?>
	<p><?php esc_html_e( 'Provider and extension information is unavailable.', 'ran-booster' ); ?></p>
	<?php
} else {
	foreach ( $components as $component ) {
		?>
	<dl class="ran-booster-troubleshooting__fields-list">
		<?php
		foreach ( $component as $label => $value ) {
			?>
	<div><dt><?php echo esc_html( $label ); ?></dt><dd><code><?php echo esc_html( $value ); ?></code></dd></div><?php } ?></dl>
		<?php
	}
}
?>
</div></section>
			<section class="ran-booster-panel ran-booster-page-shell ran-booster-troubleshooting__installation-card" aria-labelledby="ran-booster-github-details-heading">
				<header class="ran-booster-page-shell__header"><h4 id="ran-booster-github-details-heading" class="ran-booster-section__title"><?php esc_html_e( 'GitHub provider', 'ran-booster' ); ?></h4></header>
				<div class="ran-booster-page-shell__body">
					<p><?php echo esc_html( array() === $github_fields ? __( 'GitHub provider information is unavailable.', 'ran-booster' ) : implode( ' · ', array_intersect_key( $github_fields, array_fill_keys( array( __( 'Name', 'ran-booster' ), __( 'Source', 'ran-booster' ), __( 'Operational availability', 'ran-booster' ) ), true ) ) ) ); ?></p>
					<details id="ran-booster-github-provider-diagnostics" class="ran-booster-troubleshooting__disclosure ran-booster-disclosure">
						<summary><?php esc_html_e( 'GitHub provider details', 'ran-booster' ); ?></summary>
						<div class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body">
						<p><?php esc_html_e( 'Local declarations only; these claims do not verify publisher identity or remote access.', 'ran-booster' ); ?></p>
						<dl class="ran-booster-troubleshooting__fields-list"><?php foreach ( $summary_labels as $field_label ) { ?>
							<?php
							if ( isset( $github_fields[ $field_label ] ) ) {
								?>
								<div><dt><?php echo esc_html( $field_label ); ?></dt><dd><code><?php echo esc_html( $github_fields[ $field_label ] ); ?></code></dd></div><?php } ?>
						<?php } ?></dl>
						<details class="ran-booster-troubleshooting__disclosure ran-booster-disclosure"><summary><?php esc_html_e( 'Technical details', 'ran-booster' ); ?></summary>
							<div class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body">
							<dl class="ran-booster-troubleshooting__fields-list"><?php foreach ( $github_fields as $field_label => $field_value ) { ?>
								<?php
								if ( ! in_array( $field_label, $summary_labels, true ) ) {
									?>
									<div><dt><?php echo esc_html( $field_label ); ?></dt><dd><code><?php echo esc_html( $field_value ); ?></code></dd></div><?php } ?>
							<?php } ?></dl>
							<?php foreach ( $installation as $context_heading => $context_fields ) { ?>
								<?php
								if ( in_array( $context_heading, array( __( 'GitHub provider', 'ran-booster' ), __( 'Booster Core updates', 'ran-booster' ), __( 'Diagnostic Git provider', 'ran-booster' ) ), true ) ) {
									continue; }
								?>
								<h5><?php echo esc_html( $context_heading ); ?></h5><dl class="ran-booster-troubleshooting__fields-list">
								<?php
								foreach ( $context_fields as $field_label => $field_value ) {
									?>
									<div><dt><?php echo esc_html( $field_label ); ?></dt><dd><code><?php echo esc_html( $field_value ); ?></code></dd></div><?php } ?></dl>
							<?php } ?>
							</div>
						</details>
						</div>
					</details>
				</div>
			</section>

</section>
