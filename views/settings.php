<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller.

/**
 * @var array<string, mixed>|null $secrets_storage
 * @var string $credential_storage_documentation_url
 * @var array<string,array{value:bool|int,source:string,locked:bool,error:?string}> $general_settings
 * @var string $general_token
 * @var ?string $general_feedback
 * @var array<string,string> $general_errors
 * @var array<string,string> $general_attempted
 * @var array{hidden:bool,eligible:bool,reason:string} $github_visibility
 * @var array<string,mixed> $core_update_status
 * @var bool $check_available
 * @var ?string $native_update_url
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

$field              = static function ( string $key ) use ( $general_settings ): array {
	return $general_settings[ $key ] ?? array(
		'value'  => 0,
		'source' => 'default',
		'locked' => true,
		'error'  => 'unavailable',
	);
};
$field_error        = static function ( string $key ) use ( $general_errors, $field ): ?string {
	$value = $general_errors[ $key ] ?? $field( $key )['error'];
	return match ( $value ) {
		'invalid_code' => __( 'The configured value is invalid. Correct the named constant; runtime safety restrictions still apply.', 'ran-booster' ),
		'invalid_saved' => __( 'The saved preference is invalid. Booster is using its safe fallback.', 'ran-booster' ),
		'unavailable' => __( 'This preference is currently unavailable.', 'ran-booster' ),
		default => is_string( $value ) && '' !== $value ? $value : null,
	};
};
$value              = static function ( string $key ) use ( $general_attempted, $field ): string {
	$attempted = $general_attempted[ $key ] ?? null;
	return is_string( $attempted ) ? $attempted : (string) $field( $key )['value'];
};
$source_labels      = array(
	'default' => __( 'Booster default', 'ran-booster' ),
	'saved'   => __( 'Saved for this site', 'ran-booster' ),
	'code'    => __( 'Set in configuration', 'ran-booster' ),
);
$visibility_reasons = array(
	'no_other_provider'         => __( 'Another active non-GitHub provider is required before this can be hidden.', 'ran-booster' ),
	'active_github_replacement' => __( 'GitHub remains visible while an active GitHub replacement is present.', 'ran-booster' ),
	'github_management_present' => __( 'GitHub remains visible while this site manages GitHub packages.', 'ran-booster' ),
	'github_selection_unknown'  => __( 'GitHub remains visible until its local management state is known.', 'ran-booster' ),
	'provider_registry_unknown' => __( 'GitHub remains visible until provider availability is known.', 'ran-booster' ),
);
$hide               = $field( 'hide_github' );
$discovery          = $field( 'self_update_discovery' );
$hide_locked        = true === $hide['locked'] || ! $github_visibility['eligible'];
$hide_display_value = $github_visibility['eligible'] && '1' === ( $general_attempted['hide_github'] ?? ( $hide['value'] ? '1' : '0' ) );
$archive_bytes      = (int) $field( 'max_archive_bytes' )['value'];
$branch_ceiling     = $archive_bytes <= intdiv( PHP_INT_MAX, 4 ) ? $archive_bytes * 4 : null;
$status_mode        = is_string( $core_update_status['effective_mode'] ?? null ) ? $core_update_status['effective_mode'] : 'disabled';
$status_reason      = is_string( $core_update_status['reason'] ?? null ) ? $core_update_status['reason'] : '';
$update_reasons     = array(
	'source_checkout'                   => __( 'This source checkout is protected from native updates.', 'ran-booster' ),
	'release_marker_missing_or_invalid' => __( 'This installation has no verified release provenance.', 'ran-booster' ),
	'configuration_disabled'            => __( 'Native discovery is disabled by configuration.', 'ran-booster' ),
	'configuration_invalid'             => __( 'The update configuration is invalid.', 'ran-booster' ),
	'preference_disabled'               => __( 'Native discovery is disabled by the saved preference.', 'ran-booster' ),
);
$installed_version  = is_string( $core_update_status['installed_version'] ?? null ) ? $core_update_status['installed_version'] : null;
$offered_version    = is_string( $core_update_status['offered_version'] ?? null ) ? $core_update_status['offered_version'] : null;
$success_at         = $core_update_status['last_successful_check'] ?? null;
$success_label      = is_int( $success_at ) ? wp_date( 'Y-m-d H:i:s T', $success_at ) : false;
$success_label      = is_string( $success_label ) ? $success_label : __( 'Unknown', 'ran-booster' );
?>
<section class="ran-booster-page-shell ran-booster-settings" aria-labelledby="ran-booster-settings-heading">
	<header class="ran-booster-page-shell__header">
		<h2 id="ran-booster-settings-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Settings', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Site-wide Booster preferences and secure credential storage.', 'ran-booster' ); ?></p>
	</header>
	<section class="ran-booster-panel" aria-labelledby="ran-booster-general-heading">
		<h3 id="ran-booster-general-heading"><?php esc_html_e( 'General', 'ran-booster' ); ?></h3>
		<p data-ran-booster-unsaved hidden role="status"><?php esc_html_e( 'You have unsaved changes.', 'ran-booster' ); ?></p>
		<?php
		if ( null !== $general_feedback && '' !== $general_feedback ) {
			?>
			<div class="notice notice-info inline"><p><?php echo esc_html( $general_feedback ); ?></p></div><?php } ?>
		<?php
		if ( $check_available ) {
			?>
			<form id="ran-booster-check-core-updates" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings' ) ); ?>"><?php wp_nonce_field( 'ran-booster-check-core-updates' ); ?><input type="hidden" name="ran_booster[action]" value="check-core-updates"></form><?php } ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings#ran-booster-general-heading' ) ); ?>" class="ran-booster-settings__general-form" data-ran-booster-general-form>
			<?php wp_nonce_field( 'ran-booster-save-general-settings' ); ?>
			<input type="hidden" name="ran_booster[action]" value="save-general-settings">
			<input type="hidden" name="ran_booster[token]" value="<?php echo esc_attr( $general_token ); ?>">
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'Appearance', 'ran-booster' ); ?></legend>
				<?php
				if ( ! $hide_locked ) {
					?>
					<input type="hidden" name="ran_booster[preferences][hide_github]" value="0"><?php } ?>
				<p><label for="ran-booster-hide-github"><input id="ran-booster-hide-github" type="checkbox" name="ran_booster[preferences][hide_github]" value="1"<?php checked( true, $hide_display_value ); ?><?php disabled( $hide_locked ); ?><?php echo null === $field_error( 'hide_github' ) ? '' : ' aria-invalid="true" aria-describedby="ran-booster-hide-github-error"'; ?>> <?php esc_html_e( 'Hide GitHub UI', 'ran-booster' ); ?></label></p>
				<p class="description"><?php echo esc_html( $source_labels[ $hide['source'] ] ?? __( 'Unknown source', 'ran-booster' ) ); ?>. <?php echo esc_html( $visibility_reasons[ $github_visibility['reason'] ] ?? ( $github_visibility['hidden'] ? __( 'GitHub navigation is currently hidden.', 'ran-booster' ) : __( 'GitHub navigation remains available.', 'ran-booster' ) ) ); ?></p>
				<?php
				if ( null !== $field_error( 'hide_github' ) ) {
					?>
					<p id="ran-booster-hide-github-error" class="notice notice-error inline"><?php echo esc_html( $field_error( 'hide_github' ) ); ?></p><?php } ?>
			</fieldset>
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'Booster updates', 'ran-booster' ); ?></legend>
				<?php
				if ( ! $discovery['locked'] ) {
					?>
					<input type="hidden" name="ran_booster[preferences][self_update_discovery]" value="0"><?php } ?>
				<p><label for="ran-booster-self-update-discovery"><input id="ran-booster-self-update-discovery" type="checkbox" name="ran_booster[preferences][self_update_discovery]" value="1"<?php checked( '1', $general_attempted['self_update_discovery'] ?? ( $discovery['value'] ? '1' : '0' ) ); ?><?php disabled( $discovery['locked'] ); ?><?php echo null === $field_error( 'self_update_discovery' ) ? '' : ' aria-invalid="true" aria-describedby="ran-booster-self-update-discovery-error"'; ?>> <?php esc_html_e( 'Check for Booster updates', 'ran-booster' ); ?></label></p>
				<p class="description"><?php echo esc_html( $source_labels[ $discovery['source'] ] ?? __( 'Unknown source', 'ran-booster' ) ); ?>. <?php /* The effective policy supplies one fixed, translated reason. */ ?><?php echo esc_html( 'enabled' === $status_mode ? __( 'Native discovery is currently eligible.', 'ran-booster' ) : $update_reasons[ $status_reason ] ?? __( 'Native discovery is currently unavailable.', 'ran-booster' ) ); ?></p>
				<?php
				if ( null !== $field_error( 'self_update_discovery' ) ) {
					?>
					<p id="ran-booster-self-update-discovery-error" class="notice notice-error inline"><?php echo esc_html( $field_error( 'self_update_discovery' ) ); ?></p><?php } ?>
				<p><?php esc_html_e( 'Installed version:', 'ran-booster' ); ?> <?php echo esc_html( $installed_version ?? __( 'Unknown', 'ran-booster' ) ); ?><br><?php esc_html_e( 'Available version:', 'ran-booster' ); ?> <?php echo esc_html( $offered_version ?? __( 'No verified newer offer', 'ran-booster' ) ); ?></p>
				<p><?php esc_html_e( 'Last successful Booster check:', 'ran-booster' ); ?> <?php echo esc_html( $success_label ); ?><br>
				<?php esc_html_e( 'Latest check result:', 'ran-booster' ); ?> <?php echo esc_html( $core_update_status['last_check_outcome'] ?? __( 'No completed check recorded', 'ran-booster' ) ); ?></p>
				<?php
				if ( is_string( $core_update_status['last_check_code'] ?? null ) ) {
					?>
					<p class="description"><?php esc_html_e( 'Check diagnostic code:', 'ran-booster' ); ?> <code><?php echo esc_html( $core_update_status['last_check_code'] ); ?></code></p><?php } ?>
				<p class="description"><?php esc_html_e( 'Check now also runs WordPress plugin update discovery. It does not save preferences or install an update.', 'ran-booster' ); ?></p>
				<?php
				if ( $check_available ) {
					?>
					<p><button form="ran-booster-check-core-updates" type="submit" class="button"><?php esc_html_e( 'Check now', 'ran-booster' ); ?></button></p><?php } ?>
				<?php
				if ( null !== $native_update_url && '' !== $native_update_url ) {
					?>
					<p><a class="button button-primary" href="<?php echo esc_url( $native_update_url ); ?>"><?php esc_html_e( 'Update now', 'ran-booster' ); ?></a></p><?php } ?>
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
			</fieldset>
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'Archive limits', 'ran-booster' ); ?></legend>
				<p><label for="ran-booster-max-archive-bytes"><?php esc_html_e( 'Maximum download size (bytes)', 'ran-booster' ); ?></label><br><input id="ran-booster-max-archive-bytes" type="number" min="1048576" max="536870911" step="1" name="ran_booster[preferences][max_archive_bytes]" value="<?php echo esc_attr( $value( 'max_archive_bytes' ) ); ?>"<?php disabled( $field( 'max_archive_bytes' )['locked'] ); ?><?php echo null === $field_error( 'max_archive_bytes' ) ? '' : ' aria-invalid="true" aria-describedby="ran-booster-max-archive-bytes-error"'; ?>></p>
				<?php
				if ( null !== $field_error( 'max_archive_bytes' ) ) {
					?>
					<p id="ran-booster-max-archive-bytes-error" class="notice notice-error inline"><?php echo esc_html( $field_error( 'max_archive_bytes' ) ); ?></p><?php } ?>
				<p class="description"><?php /* translators: 1: exact download bytes, 2: approximate size in MiB. */ ?><?php echo esc_html( sprintf( __( '%1$s bytes is approximately %2$s MiB.', 'ran-booster' ), number_format_i18n( $archive_bytes ), number_format_i18n( $archive_bytes / 1048576, 2 ) ) ); ?></p>
				<p class="description"><?php /* translators: %s: derived branch expansion ceiling in bytes. */ ?><?php echo esc_html( null === $branch_ceiling ? __( 'The derived branch expansion ceiling cannot be calculated on this host.', 'ran-booster' ) : sprintf( __( 'Branch downloads derive a ceiling of %s bytes (4× this download limit). Host and updater safeguards may be stricter.', 'ran-booster' ), number_format_i18n( $branch_ceiling ) ) ); ?></p>
			</fieldset>
			<dl class="description">
				<?php
				foreach ( array(
					'hide_github'           => 'RAN_BOOSTER_HIDE_GITHUB_UI',
					'self_update_discovery' => 'RAN_BOOSTER_SELF_UPDATE_MODE',
					'history_rows'          => 'RAN_BOOSTER_HISTORY_ROWS',
					'history_days'          => 'RAN_BOOSTER_HISTORY_DAYS',
					'max_archive_bytes'     => 'RAN_BOOSTER_MAX_ARCHIVE_BYTES',
				) as $setting_key => $configuration_constant ) {
					?>
					<dt><code><?php echo esc_html( $configuration_constant ); ?></code></dt><dd><?php echo esc_html( $source_labels[ $field( $setting_key )['source'] ] ?? __( 'Unknown source', 'ran-booster' ) ); ?></dd>
				<?php } ?>
			</dl>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'ran-booster' ); ?></button></p>
		</form>
	</section>
	<?php if ( null === $secrets_storage ) { ?>
		<section class="ran-booster-panel" aria-labelledby="ran-booster-storage"><h3 id="ran-booster-storage"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h3><p><?php esc_html_e( 'Storage status is unavailable. No setup or recovery action has been performed.', 'ran-booster' ); ?></p></section>
	<?php } else { ?>
		<?php require __DIR__ . '/settings-storage.php'; ?>
	<?php } ?>
</section>
