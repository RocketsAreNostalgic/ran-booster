<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller.

/**
 * @var array<string,array{value:bool|int,source:string,locked:bool,error:?string}> $general_settings
 * @var string $general_token
 * @var ?string $general_feedback
 * @var array<string,string> $general_errors
 * @var array<string,string> $general_attempted
 * @var array{hidden:bool,eligible:bool,reason:string} $github_visibility
 * @var array<string,mixed> $core_update_status
 * @var bool $check_available
 * @var ?string $native_update_url
 * @var bool $general_save_succeeded Optional enhanced-save result.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

$field               = static function ( string $key ) use ( $general_settings ): array {
	return $general_settings[ $key ] ?? array(
		'value'  => 0,
		'source' => 'default',
		'locked' => true,
		'error'  => 'unavailable',
	);
};
$field_error         = static function ( string $key ) use ( $general_errors, $field ): ?string {
	$value = $general_errors[ $key ] ?? $field( $key )['error'];
	return match ( $value ) {
		'invalid_code' => __( 'The configured value is invalid. Correct the named constant; runtime safety restrictions still apply.', 'ran-booster' ),
		'invalid_saved' => __( 'The saved preference is invalid. Booster is using its safe fallback.', 'ran-booster' ),
		'unavailable' => __( 'This preference is currently unavailable.', 'ran-booster' ),
		default => is_string( $value ) && '' !== $value ? $value : null,
	};
};
$value               = static function ( string $key ) use ( $general_attempted, $field ): string {
	$attempted = $general_attempted[ $key ] ?? null;
	return is_string( $attempted ) ? $attempted : (string) $field( $key )['value'];
};
$source_labels       = array(
	'default' => __( 'Booster default', 'ran-booster' ),
	'saved'   => __( 'Saved for this site', 'ran-booster' ),
	'code'    => __( 'Set in configuration', 'ran-booster' ),
);
$configuration_value = static function ( bool|int $value ): string {
	return is_bool( $value ) ? ( $value ? __( 'On', 'ran-booster' ) : __( 'Off', 'ran-booster' ) ) : (string) $value;
};
/** @param array<string,string> $constants */
$render_configuration = static function ( array $constants ) use ( $field, $source_labels, $configuration_value ): void {
	?>
	<details class="ran-booster-disclosure ran-booster-settings__configuration">
		<summary><?php esc_html_e( 'Configuration constants', 'ran-booster' ); ?></summary>
		<div class="ran-booster-disclosure__body">
			<?php foreach ( $constants as $setting_key => $configuration_constant ) { ?>
				<p><code><?php echo esc_html( $configuration_constant ); ?></code>: <?php echo esc_html( $source_labels[ $field( $setting_key )['source'] ] ?? __( 'Unknown source', 'ran-booster' ) ); ?> — <code><?php echo esc_html( $configuration_value( $field( $setting_key )['value'] ) ); ?></code></p>
			<?php } ?>
		</div>
	</details>
	<?php
};
$visibility_reasons   = array(
	'no_other_provider'         => __( 'Another active non-GitHub provider is required before this can be hidden.', 'ran-booster' ),
	'active_github_replacement' => __( 'GitHub remains visible while an active GitHub replacement is present.', 'ran-booster' ),
	'github_management_present' => __( 'GitHub remains visible while this site manages GitHub packages.', 'ran-booster' ),
	'github_selection_unknown'  => __( 'GitHub remains visible until its local management state is known.', 'ran-booster' ),
	'provider_registry_unknown' => __( 'GitHub remains visible until provider availability is known.', 'ran-booster' ),
);
$hide                 = $field( 'hide_github' );
$discovery            = $field( 'self_update_discovery' );
$hide_locked          = true === $hide['locked'] || ! $github_visibility['eligible'];
$hide_display_value   = $github_visibility['eligible'] && '1' === ( $general_attempted['hide_github'] ?? ( $hide['value'] ? '1' : '0' ) );
$archive_bytes        = (int) $field( 'max_archive_bytes' )['value'];
$mib_value            = static function ( int $bytes ): string {
	$whole     = intdiv( $bytes, 1048576 );
	$remainder = $bytes % 1048576;
	if ( 0 === $remainder ) {
		return (string) $whole;
	}
	$fraction = '';
	for ( $index = 0; $index < 20 && 0 !== $remainder; ++$index ) {
		$remainder *= 10;
		$fraction  .= (string) intdiv( $remainder, 1048576 );
		$remainder %= 1048576;
	}
	return $whole . '.' . $fraction;
};
$archive_mib_value    = is_string( $general_attempted['max_archive_bytes'] ?? null )
	? $general_attempted['max_archive_bytes']
	: $mib_value( $archive_bytes );
$branch_ceiling       = $archive_bytes <= intdiv( PHP_INT_MAX, 4 ) ? $archive_bytes * 4 : null;
$branch_ceiling_mib   = null === $branch_ceiling ? null : $mib_value( $branch_ceiling );
$status_mode          = is_string( $core_update_status['effective_mode'] ?? null ) ? $core_update_status['effective_mode'] : 'disabled';
$status_reason        = is_string( $core_update_status['reason'] ?? null ) ? $core_update_status['reason'] : '';
$update_reasons       = array(
	'source_checkout'                   => __( 'This development copy cannot receive Booster updates.', 'ran-booster' ),
	'release_marker_missing_or_invalid' => __( 'This installation cannot verify its release information.', 'ran-booster' ),
	'configuration_disabled'            => __( 'Your site configuration has turned update checks off. This setting cannot be changed here.', 'ran-booster' ),
	'configuration_invalid'             => __( 'Your site’s update configuration is invalid. Correct it before checking.', 'ran-booster' ),
	'preference_disabled'               => __( 'Update checks are turned off. Enable the switch and save to turn them on.', 'ran-booster' ),
);
$installed_version    = is_string( $core_update_status['installed_version'] ?? null ) ? $core_update_status['installed_version'] : null;
$offered_version      = is_string( $core_update_status['offered_version'] ?? null ) ? $core_update_status['offered_version'] : null;
$success_at           = $core_update_status['last_successful_check'] ?? null;
$success_label        = is_int( $success_at ) ? wp_date( 'Y-m-d H:i:s T', $success_at ) : false;
$success_label        = is_string( $success_label ) ? $success_label : __( 'Unknown', 'ran-booster' );
?>
	<section id="ran-booster-general-region" class="ran-booster-panel" aria-labelledby="ran-booster-general-heading"<?php echo ! empty( $general_save_succeeded ) ? ' data-ran-booster-general-saved' : ''; ?>>
		<h3 id="ran-booster-general-heading"><?php esc_html_e( 'General', 'ran-booster' ); ?></h3>
		<p data-ran-booster-unsaved hidden role="status"><?php esc_html_e( 'You have unsaved changes.', 'ran-booster' ); ?></p>
		<?php
		if ( null !== $general_feedback && '' !== $general_feedback ) {
			?>
			<div id="ran-booster-general-error" class="notice notice-info inline" data-ran-booster-admin-mutation-error<?php echo isset( $general_save_succeeded ) && ! $general_save_succeeded ? ' role="alert" tabindex="-1"' : ''; ?>><p><?php echo esc_html( $general_feedback ); ?></p></div>
		<?php } else { ?>
			<div id="ran-booster-general-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>
		<?php } ?>
		<form id="ran-booster-check-core-updates" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings' ) ); ?>"><?php wp_nonce_field( 'ran-booster-check-core-updates' ); ?><input type="hidden" name="ran_booster[action]" value="check-core-updates"></form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings#ran-booster-general-heading' ) ); ?>" class="ran-booster-settings__general-form" data-ran-booster-general-form data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-general-error" hx-post="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings' ) ); ?>" hx-target="#ran-booster-general-region" hx-swap="outerHTML show:none" hx-sync="this:drop">
			<?php wp_nonce_field( 'ran-booster-save-general-settings' ); ?>
			<input type="hidden" name="ran_booster[action]" value="save-general-settings">
			<input type="hidden" name="ran_booster[token]" value="<?php echo esc_attr( $general_token ); ?>">
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'Appearance', 'ran-booster' ); ?></legend>
				<?php
				if ( ! $hide_locked ) {
					?>
					<input type="hidden" name="ran_booster[preferences][hide_github]" value="0"><?php } ?>
						<p class="ran-booster-settings__switch-row"><label class="ran-booster-settings__switch" for="ran-booster-hide-github"><input id="ran-booster-hide-github" type="checkbox" role="switch" aria-describedby="ran-booster-hide-github-help<?php echo null === $field_error( 'hide_github' ) ? '' : ' ran-booster-hide-github-error'; ?>" name="ran_booster[preferences][hide_github]" value="1"<?php checked( true, $hide_display_value ); ?><?php disabled( $hide_locked ); ?><?php echo null === $field_error( 'hide_github' ) ? '' : ' aria-invalid="true"'; ?>><span class="ran-booster-settings__switch-control" aria-hidden="true"></span><span><?php esc_html_e( 'Hide GitHub UI', 'ran-booster' ); ?></span></label></p>
				<p id="ran-booster-hide-github-help" class="description"><?php echo esc_html( $source_labels[ $hide['source'] ] ?? __( 'Unknown source', 'ran-booster' ) ); ?>. <?php echo esc_html( $visibility_reasons[ $github_visibility['reason'] ] ?? ( $github_visibility['hidden'] ? __( 'GitHub navigation is currently hidden.', 'ran-booster' ) : __( 'GitHub navigation remains available.', 'ran-booster' ) ) ); ?></p>
				<?php
				if ( null !== $field_error( 'hide_github' ) ) {
					?>
					<p id="ran-booster-hide-github-error" class="notice notice-error inline"><?php echo esc_html( $field_error( 'hide_github' ) ); ?></p><?php } ?>
				<?php $render_configuration( array( 'hide_github' => 'RAN_BOOSTER_HIDE_GITHUB_UI' ) ); ?>
			</fieldset>
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'Booster updates', 'ran-booster' ); ?></legend>
				<?php
				if ( ! $discovery['locked'] ) {
					?>
					<input type="hidden" name="ran_booster[preferences][self_update_discovery]" value="0"><?php } ?>
						<p class="ran-booster-settings__switch-row"><label class="ran-booster-settings__switch" for="ran-booster-self-update-discovery"><input id="ran-booster-self-update-discovery" type="checkbox" role="switch" aria-describedby="ran-booster-self-update-discovery-help<?php echo null === $field_error( 'self_update_discovery' ) ? '' : ' ran-booster-self-update-discovery-error'; ?>" name="ran_booster[preferences][self_update_discovery]" value="1"<?php checked( '1', $general_attempted['self_update_discovery'] ?? ( $discovery['value'] ? '1' : '0' ) ); ?><?php disabled( $discovery['locked'] ); ?><?php echo null === $field_error( 'self_update_discovery' ) ? '' : ' aria-invalid="true"'; ?>><span class="ran-booster-settings__switch-control" aria-hidden="true"></span><span><?php esc_html_e( 'Check for Booster updates', 'ran-booster' ); ?></span></label></p>
				<p id="ran-booster-self-update-discovery-help" class="description"><?php echo esc_html( 'enabled' === $status_mode ? __( 'Booster update checks are enabled.', 'ran-booster' ) : $update_reasons[ $status_reason ] ?? __( 'Booster update checks are currently unavailable.', 'ran-booster' ) ); ?></p>
				<?php
				if ( null !== $field_error( 'self_update_discovery' ) ) {
					?>
					<p id="ran-booster-self-update-discovery-error" class="notice notice-error inline"><?php echo esc_html( $field_error( 'self_update_discovery' ) ); ?></p><?php } ?>
				<dl class="ran-booster-settings__update-facts">
					<div><dt><?php esc_html_e( 'Installed version', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $installed_version ?? __( 'Unknown', 'ran-booster' ) ); ?></code></dd></div>
					<div><dt><?php esc_html_e( 'Available version', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $offered_version ?? __( 'No verified newer offer', 'ran-booster' ) ); ?></code></dd></div>
					<?php if ( is_int( $success_at ) ) { ?>
						<div><dt><?php esc_html_e( 'Last successful Booster check', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $success_label ); ?></code></dd></div>
					<?php } ?>
					<div><dt><?php esc_html_e( 'Latest check result', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $core_update_status['last_check_outcome'] ?? __( 'No completed check recorded', 'ran-booster' ) ); ?></code></dd></div>
					<?php if ( is_string( $core_update_status['last_check_code'] ?? null ) ) { ?>
						<div><dt><?php esc_html_e( 'Check diagnostic code', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $core_update_status['last_check_code'] ); ?></code></dd></div>
					<?php } ?>
				</dl>
				<p><button form="ran-booster-check-core-updates" type="submit" class="button" aria-describedby="ran-booster-check-updates-help<?php echo $check_available ? '' : ( 'enabled' === $status_mode ? ' ran-booster-check-updates-unavailable' : ' ran-booster-self-update-discovery-help' ); ?>"<?php disabled( ! $check_available ); ?>><?php esc_html_e( 'Check now', 'ran-booster' ); ?></button></p>
				<p id="ran-booster-check-updates-help" class="description"><?php esc_html_e( 'Checks for Booster and other plugin updates. Nothing is installed.', 'ran-booster' ); ?></p>
				<?php if ( ! $check_available && 'enabled' === $status_mode ) { ?>
					<p id="ran-booster-check-updates-unavailable" class="description"><?php esc_html_e( 'Checking is unavailable for this site or your account.', 'ran-booster' ); ?></p>
				<?php } ?>
				<?php
				if ( null !== $native_update_url && '' !== $native_update_url ) {
					?>
					<p><a class="button button-primary" href="<?php echo esc_url( $native_update_url ); ?>"><?php esc_html_e( 'Update now', 'ran-booster' ); ?></a></p><?php } ?>
				<?php $render_configuration( array( 'self_update_discovery' => 'RAN_BOOSTER_SELF_UPDATE_MODE' ) ); ?>
			</fieldset>
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'History', 'ran-booster' ); ?></legend>
				<p><label for="ran-booster-history-rows"><?php esc_html_e( 'Maximum records', 'ran-booster' ); ?></label><br><input id="ran-booster-history-rows" type="number" min="200" max="100000" step="1" name="ran_booster[preferences][history_rows]" value="<?php echo esc_attr( $value( 'history_rows' ) ); ?>"<?php disabled( $field( 'history_rows' )['locked'] ); ?><?php echo null === $field_error( 'history_rows' ) ? '' : ' aria-invalid="true" aria-describedby="ran-booster-history-rows-error"'; ?>></p>
				<?php
				if ( null !== $field_error( 'history_rows' ) ) {
					?>
					<p id="ran-booster-history-rows-error" class="notice notice-error inline"><?php echo esc_html( $field_error( 'history_rows' ) ); ?></p><?php } ?>
				<p><label for="ran-booster-history-days"><?php esc_html_e( 'Retention days', 'ran-booster' ); ?></label><br><input id="ran-booster-history-days" type="number" min="1" max="365" step="1" name="ran_booster[preferences][history_days]" value="<?php echo esc_attr( $value( 'history_days' ) ); ?>"<?php disabled( $field( 'history_days' )['locked'] ); ?><?php echo null === $field_error( 'history_days' ) ? '' : ' aria-invalid="true" aria-describedby="ran-booster-history-days-error"'; ?>></p>
				<?php
				if ( null !== $field_error( 'history_days' ) ) {
					?>
					<p id="ran-booster-history-days-error" class="notice notice-error inline"><?php echo esc_html( $field_error( 'history_days' ) ); ?></p><?php } ?>
				<p class="description"><?php esc_html_e( 'Lowering these values does not delete protected running or recovery records immediately. Retention is irreversible once records are pruned.', 'ran-booster' ); ?></p>
				<?php
				$render_configuration(
					array(
						'history_rows' => 'RAN_BOOSTER_HISTORY_ROWS',
						'history_days' => 'RAN_BOOSTER_HISTORY_DAYS',
					)
				);
				?>
			</fieldset>
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'Archive limits', 'ran-booster' ); ?></legend>
					<p><label for="ran-booster-max-archive-mib"><?php esc_html_e( 'Maximum download size (MiB)', 'ran-booster' ); ?></label><br><input id="ran-booster-max-archive-mib" type="number" min="1" max="511.99999904632568359375" step="any" inputmode="decimal" name="ran_booster[preferences][max_archive_mib]" value="<?php echo esc_attr( $archive_mib_value ); ?>"<?php disabled( $field( 'max_archive_bytes' )['locked'] ); ?><?php echo null === $field_error( 'max_archive_bytes' ) ? '' : ' aria-invalid="true" aria-describedby="ran-booster-max-archive-mib-error"'; ?>></p>
				<?php
				if ( null !== $field_error( 'max_archive_bytes' ) ) {
					?>
						<p id="ran-booster-max-archive-mib-error" class="notice notice-error inline"><?php echo esc_html( $field_error( 'max_archive_bytes' ) ); ?></p><?php } ?>
						<p class="description"><?php esc_html_e( 'One MiB is 1,048,576 bytes. Fractional values are rounded to the nearest byte.', 'ran-booster' ); ?></p>
					<p class="description"><?php /* translators: 1: derived branch expansion ceiling in MiB, 2: exact ceiling in bytes. */ ?><?php echo esc_html( null === $branch_ceiling ? __( 'The derived branch expansion ceiling cannot be calculated on this host.', 'ran-booster' ) : sprintf( __( 'Branch downloads derive a ceiling of %1$s MiB (%2$s bytes; 4× this download limit). Host and updater safeguards may be stricter.', 'ran-booster' ), $branch_ceiling_mib, number_format_i18n( $branch_ceiling ) ) ); ?></p>
				<?php $render_configuration( array( 'max_archive_bytes' => 'RAN_BOOSTER_MAX_ARCHIVE_BYTES' ) ); ?>
			</fieldset>

				<hr class="ran-booster-settings__save-divider">
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'ran-booster' ); ?></button></p>
		</form>
	</section>
