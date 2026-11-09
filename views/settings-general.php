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
 * @var string $general_scope Optional fixed response scope.
 * @var array<string,string> $general_tokens Scoped persistence fences.
 * @var ?string $general_failed_scope Optional native failed operation scope.
 * @var list<array{key:string,label:string,url:string,active:bool,provider:bool}> $general_navigation Optional confirmed navigation.
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
$render_configuration   = static function ( array $constants ) use ( $field, $source_labels, $configuration_value ): void {
	?>
	<div class="ran-booster-settings__configuration" data-ran-booster-ui-disclosure="settings-configuration" data-ran-booster-ui-disclosure-density="compact">
	<details class="ran-booster-disclosure ran-booster-settings__configuration" data-ran-booster-ui-disclosure-fallback>
		<summary><?php esc_html_e( 'Configuration constants', 'ran-booster' ); ?></summary>
		<div class="ran-booster-disclosure__body" data-ran-booster-ui-disclosure-content>
			<?php foreach ( $constants as $setting_key => $configuration_constant ) { ?>
				<p><code><?php echo esc_html( $configuration_constant ); ?></code>: <?php echo esc_html( $source_labels[ $field( $setting_key )['source'] ] ?? __( 'Unknown source', 'ran-booster' ) ); ?> — <code><?php echo esc_html( $configuration_value( $field( $setting_key )['value'] ) ); ?></code></p>
			<?php } ?>
		</div>
	</details>
	</div>
	<?php
};
$visibility_reasons     = array(
	'no_other_provider'         => __( 'Another active non-GitHub provider is required before this can be hidden.', 'ran-booster' ),
	'active_github_replacement' => __( 'GitHub remains visible while an active GitHub replacement is present.', 'ran-booster' ),
	'github_management_present' => __( 'GitHub remains visible while this site manages GitHub packages.', 'ran-booster' ),
	'github_selection_unknown'  => __( 'GitHub remains visible until its local management state is known.', 'ran-booster' ),
	'provider_registry_unknown' => __( 'GitHub remains visible until provider availability is known.', 'ran-booster' ),
);
$hide                   = $field( 'hide_github' );
$discovery              = $field( 'self_update_discovery' );
$hide_locked            = true === $hide['locked'] || ! $github_visibility['eligible'];
$hide_confirmed         = $github_visibility['eligible'] && (bool) $hide['value'];
$hide_display_value     = $github_visibility['eligible'] && '1' === ( $general_attempted['hide_github'] ?? ( $hide['value'] ? '1' : '0' ) );
$download_bytes         = (int) $field( 'max_download_bytes' )['value'];
$mib_value              = static function ( int $bytes ): string {
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
$download_mib_value     = is_string( $general_attempted['max_download_bytes'] ?? null )
	? $general_attempted['max_download_bytes']
	: $mib_value( $download_bytes );
$branch_ceiling         = $download_bytes <= intdiv( PHP_INT_MAX, 4 ) ? $download_bytes * 4 : null;
$branch_ceiling_mib     = null === $branch_ceiling ? null : $mib_value( $branch_ceiling );
$status_mode            = is_string( $core_update_status['effective_mode'] ?? null ) ? $core_update_status['effective_mode'] : 'disabled';
$status_reason          = is_string( $core_update_status['reason'] ?? null ) ? $core_update_status['reason'] : '';
$update_reasons         = array(
	'source_checkout'                   => __( 'This development copy cannot receive Booster updates.', 'ran-booster' ),
	'release_marker_missing_or_invalid' => __( 'This installation cannot verify its release information.', 'ran-booster' ),
	'configuration_disabled'            => __( 'Your site configuration has turned update checks off. This setting cannot be changed here.', 'ran-booster' ),
	'configuration_invalid'             => __( 'Your site’s update configuration is invalid. Correct it before checking.', 'ran-booster' ),
	'preference_disabled'               => __( 'Update checks are turned off. Enable the switch to turn them on.', 'ran-booster' ),
);
$installed_version      = is_string( $core_update_status['installed_version'] ?? null ) ? $core_update_status['installed_version'] : null;
$offered_version        = is_string( $core_update_status['offered_version'] ?? null ) ? $core_update_status['offered_version'] : null;
$success_at             = $core_update_status['last_successful_check'] ?? null;
$success_label          = is_int( $success_at ) ? wp_date( 'Y-m-d H:i:s T', $success_at ) : false;
$success_label          = is_string( $success_label ) ? $success_label : __( 'Unknown', 'ran-booster' );
$general_save_succeeded = $general_save_succeeded ?? null;
$general_failed_scope   = $general_failed_scope ?? null;
$general_scope          = $general_scope ?? 'all';
$general_tokens         = $general_tokens ?? array();
$global_failure         = 'all' === $general_scope && null !== $general_feedback && in_array( $general_failed_scope, array( null, 'all' ), true ) && ( false === $general_save_succeeded || 'all' === $general_failed_scope );
$numeric_baseline       = array();
foreach ( array( 'history_rows', 'history_days', 'max_download_bytes' ) as $numeric_key ) {
	if ( ! $field( $numeric_key )['locked'] ) {
		$numeric_baseline[] = array( 'ran_booster[preferences][' . ( 'max_download_bytes' === $numeric_key ? 'max_download_mib' : $numeric_key ) . ']', 'max_download_bytes' === $numeric_key ? $mib_value( $download_bytes ) : (string) $field( $numeric_key )['value'] );
	}
}
/** @param string $scope Fixed Core-owned preference group. */
$open_scope = static function ( string $scope ) use ( $general_tokens, $general_feedback, $general_save_succeeded, $general_failed_scope, $general_scope, $github_visibility, $field, $field_error, $general_errors, $global_failure, $mib_value, $download_bytes, $numeric_baseline ): void {
	$failed = ( $general_scope === $scope && false === $general_save_succeeded ) || $general_failed_scope === $scope;
	$fields = match ( $scope ) {
		'hide_github' => array( 'hide_github' => array( 'ran-booster-hide-github-error', __( 'Hide GitHub UI', 'ran-booster' ) ) ),
		'self_update_discovery' => array( 'self_update_discovery' => array( 'ran-booster-self-update-discovery-error', __( 'Check for Booster updates', 'ran-booster' ) ) ),
		default => array(
			'history_rows'       => array( 'ran-booster-history-rows-error', __( 'Maximum records', 'ran-booster' ) ),
			'history_days'       => array( 'ran-booster-history-days-error', __( 'Retention days', 'ran-booster' ) ),
			'max_download_bytes' => array( 'ran-booster-max-download-mib-error', __( 'Maximum repository ZIP download (MiB)', 'ran-booster' ) ),
		),
	};
	$has_error = $failed;
	foreach ( $fields as $field_key => $_presentation ) {
		$has_error = $has_error || null !== $field_error( $field_key );
	}
	?>
	<div id="ran-booster-general-<?php echo esc_attr( $scope ); ?>" data-ran-booster-general-scope="<?php echo esc_attr( $scope ); ?>"<?php echo ! empty( $general_save_succeeded ) ? ' data-ran-booster-general-saved' : ''; ?>>
	<?php
	if ( 'self_update_discovery' === $scope ) {
		?>
		<form id="ran-booster-check-core-updates" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings' ) ); ?>"><?php wp_nonce_field( 'ran-booster-check-core-updates' ); ?><input type="hidden" name="ran_booster[action]" value="check-core-updates"></form><?php } ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings#ran-booster-general-' . $scope ) ); ?>" class="ran-booster-settings__general-form" data-ran-booster-general-form data-ran-booster-settings-scope="<?php echo esc_attr( $scope ); ?>" data-ran-booster-confirmed="<?php echo esc_attr( 'numeric' === $scope ? '' : ( $field( $scope )['value'] && ( 'hide_github' !== $scope || $github_visibility['eligible'] ) ? '1' : '0' ) ); ?>" data-ran-booster-baseline="<?php echo esc_attr( (string) wp_json_encode( $numeric_baseline ) ); ?>" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-general-<?php echo esc_attr( $scope ); ?>-error" hx-post="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings' ) ); ?>" hx-target="#ran-booster-general-<?php echo esc_attr( $scope ); ?>" hx-swap="outerHTML show:none" hx-sync="this:drop">
		<?php wp_nonce_field( 'ran-booster-save-general-settings' ); ?>
		<input type="hidden" name="ran_booster[action]" value="save-general-settings">
		<input type="hidden" name="ran_booster[scope]" value="<?php echo esc_attr( $scope ); ?>">
		<input type="hidden" name="ran_booster[token]" value="<?php echo esc_attr( $general_tokens[ $scope ] ?? '' ); ?>">
		<div id="ran-booster-general-<?php echo esc_attr( $scope ); ?>-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error data-ran-booster-ui-notice<?php echo $failed ? ' data-ran-booster-general-failed' : ''; ?><?php echo $failed && ! $global_failure ? ' role="alert" tabindex="-1"' : ''; ?><?php echo $has_error ? '' : ' hidden'; ?>><div data-ran-booster-ui-notice-content><p data-ran-booster-ui-notice-primary><?php echo esc_html( $failed ? ( $general_feedback ?? '' ) : '' ); ?></p>
			<p data-ran-booster-ui-notice-detail<?php echo $failed ? '' : ' hidden'; ?>>
			<?php
			if ( $failed ) {
				?>
				<?php esc_html_e( 'Current saved value:', 'ran-booster' ); ?> <?php echo esc_html( 'numeric' === $scope ? sprintf( /* translators: 1: saved maximum records, 2: saved retention days, 3: saved download limit in MiB. */ __( 'Maximum records: %1$s; Retention days: %2$s; Download limit: %3$s MiB.', 'ran-booster' ), (string) $field( 'history_rows' )['value'], (string) $field( 'history_days' )['value'], $mib_value( $download_bytes ) ) : ( $field( $scope )['value'] ? __( 'On', 'ran-booster' ) : __( 'Off', 'ran-booster' ) ) ); ?><?php } ?></p>
			<?php foreach ( $fields as $field_key => $presentation ) { ?>
				<?php if ( null !== $field_error( $field_key ) ) { ?>
					<p id="<?php echo esc_attr( $presentation[0] ); ?>" data-ran-booster-ui-notice-field<?php echo array_key_exists( $field_key, $general_errors ) ? ' data-ran-booster-ui-notice-request-field' : ''; ?>><?php echo esc_html( $presentation[1] . ': ' . $field_error( $field_key ) ); ?></p>
				<?php } ?>
			<?php } ?>
		</div></div>
	<?php
};
?>
<?php if ( 'all' === $general_scope ) { ?>
	<div id="ran-booster-general-region" class="ran-booster-settings__general"<?php echo ! empty( $general_save_succeeded ) ? ' data-ran-booster-general-saved' : ''; ?>>
	<section class="ran-booster-panel ran-booster-card ran-booster-settings__preferences" data-ran-booster-card="settings" aria-labelledby="ran-booster-general-heading">
		<h3 id="ran-booster-general-heading"><?php esc_html_e( 'General preferences', 'ran-booster' ); ?></h3>
		<?php if ( null !== $general_feedback && in_array( $general_failed_scope, array( null, 'all' ), true ) ) { ?>
		<div id="ran-booster-general-error" class="notice notice-info inline" data-ran-booster-admin-mutation-error<?php echo false === $general_save_succeeded || 'all' === $general_failed_scope ? ' data-ran-booster-ui-notice role="alert" tabindex="-1"' : ''; ?>><div data-ran-booster-ui-notice-content><p data-ran-booster-ui-notice-primary><?php echo esc_html( $general_feedback ); ?></p></div></div>
		<?php } ?>
		<noscript><p><?php esc_html_e( 'JavaScript is unavailable. Use Save setting beside each switch, and Save changes for numeric settings. Each save reloads Settings; save one group before editing another.', 'ran-booster' ); ?></p></noscript>
<?php } ?>
<?php if ( 'all' === $general_scope || 'hide_github' === $general_scope ) { ?>
	<?php $open_scope( 'hide_github' ); ?>
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'Appearance', 'ran-booster' ); ?></legend>
				<?php
				if ( ! $hide_locked ) {
					?>
					<input type="hidden" name="ran_booster[preferences][hide_github]" value="0"><?php } ?>
						<label id="ran-booster-hide-github-label" class="ran-booster-settings__switch-label" for="ran-booster-hide-github"><?php esc_html_e( 'Hide GitHub UI', 'ran-booster' ); ?></label>
						<div class="ran-booster-settings__switch-row"><span data-ran-booster-ui-switch="general-preference"><label class="ran-booster-settings__switch" data-ran-booster-ui-switch-fallback for="ran-booster-hide-github"><input id="ran-booster-hide-github" type="checkbox" role="switch" aria-labelledby="ran-booster-hide-github-label" aria-describedby="ran-booster-hide-github-help<?php echo null === $field_error( 'hide_github' ) ? '' : ' ran-booster-hide-github-error'; ?>" name="ran_booster[preferences][hide_github]" value="1"<?php checked( true, $hide_display_value ); ?><?php disabled( $hide_locked ); ?><?php echo null === $field_error( 'hide_github' ) ? '' : ' aria-invalid="true"'; ?>><span class="ran-booster-settings__switch-control" aria-hidden="true"></span></label><span data-ran-booster-ui-switch-mount></span></span>
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value.
						echo \RAN\Admin\Component\StatusBadge::for_intent(
							$hide_confirmed ? __( 'Enabled', 'ran-booster' ) : __( 'Disabled', 'ran-booster' ),
							$hide_confirmed ? 'stable' : 'none',
							array(
								'data-ran-booster-toggle-status' => '',
								'role' => 'status',
							)
						);
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
																																																																																																			</div>
				<p id="ran-booster-hide-github-help" class="description"><?php echo esc_html( $source_labels[ $hide['source'] ] ?? __( 'Unknown source', 'ran-booster' ) ); ?>. <?php echo esc_html( $visibility_reasons[ $github_visibility['reason'] ] ?? ( $github_visibility['hidden'] ? __( 'GitHub navigation is currently hidden.', 'ran-booster' ) : __( 'GitHub navigation remains available.', 'ran-booster' ) ) ); ?></p>
				<?php $render_configuration( array( 'hide_github' => 'RAN_BOOSTER_HIDE_GITHUB_UI' ) ); ?>
			</fieldset>
<div class="ran-booster-settings__toggle-actions">
	<button id="ran-booster-save-hide_github" type="submit" class="button" data-ran-booster-toggle-save<?php disabled( $hide_locked ); ?>><?php esc_html_e( 'Save setting', 'ran-booster' ); ?></button>
</div>
	<?php if ( isset( $general_navigation ) ) { ?>
<template data-ran-booster-general-navigation>
		<?php foreach ( $general_navigation as $general_tab ) { ?>
			<?php
			if ( 'troubleshooting' === $general_tab['key'] ) {
				continue;
			}
			?>
			<?php if ( 'portability' === $general_tab['key'] ) { ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-plugins' ) ); ?>"><?php esc_html_e( 'Plugins', 'ran-booster' ); ?></a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-themes' ) ); ?>"><?php esc_html_e( 'Themes', 'ran-booster' ); ?></a>
	<?php } else { ?>
		<a href="<?php echo esc_url( $general_tab['url'] ); ?>"><?php echo esc_html( $general_tab['label'] ); ?></a>
	<?php } ?>
	<?php } ?>
</template>
	<?php } ?>
</form></div>
<?php } ?>
<?php if ( 'all' === $general_scope || 'self_update_discovery' === $general_scope ) { ?>

	<?php $open_scope( 'self_update_discovery' ); ?>
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'Booster updates', 'ran-booster' ); ?></legend>
				<?php
				if ( ! $discovery['locked'] ) {
					?>
					<input type="hidden" name="ran_booster[preferences][self_update_discovery]" value="0"><?php } ?>
				<div class="ran-booster-settings__update-controls">
					<div><label id="ran-booster-self-update-discovery-label" class="ran-booster-settings__switch-label" for="ran-booster-self-update-discovery"><?php esc_html_e( 'Check for Booster updates', 'ran-booster' ); ?></label>
					<div class="ran-booster-settings__switch-row"><span data-ran-booster-ui-switch="general-preference"><label class="ran-booster-settings__switch" data-ran-booster-ui-switch-fallback for="ran-booster-self-update-discovery"><input id="ran-booster-self-update-discovery" type="checkbox" role="switch" aria-labelledby="ran-booster-self-update-discovery-label" aria-describedby="ran-booster-self-update-discovery-help<?php echo null === $field_error( 'self_update_discovery' ) ? '' : ' ran-booster-self-update-discovery-error'; ?>" name="ran_booster[preferences][self_update_discovery]" value="1"<?php checked( '1', $general_attempted['self_update_discovery'] ?? ( $discovery['value'] ? '1' : '0' ) ); ?><?php disabled( $discovery['locked'] ); ?><?php echo null === $field_error( 'self_update_discovery' ) ? '' : ' aria-invalid="true"'; ?>><span class="ran-booster-settings__switch-control" aria-hidden="true"></span></label><span data-ran-booster-ui-switch-mount></span></span>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value.
					echo \RAN\Admin\Component\StatusBadge::for_intent(
						$discovery['value'] ? __( 'Enabled', 'ran-booster' ) : __( 'Disabled', 'ran-booster' ),
						$discovery['value'] ? 'stable' : 'none',
						array(
							'data-ran-booster-toggle-status' => '',
							'role' => 'status',
						)
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					</div></div>
				</div>
				<p id="ran-booster-self-update-discovery-help" class="description"><?php echo esc_html( 'enabled' === $status_mode ? __( 'Booster update checks are enabled.', 'ran-booster' ) : $update_reasons[ $status_reason ] ?? __( 'Booster update checks are currently unavailable.', 'ran-booster' ) ); ?></p>
				<div class="ran-booster-settings__update-summary">
					<dl class="ran-booster-settings__update-facts">
						<div><dt><?php esc_html_e( 'Installed version', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $installed_version ?? __( 'Unknown', 'ran-booster' ) ); ?></code></dd></div>
						<div><dt><?php esc_html_e( 'Available version', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $offered_version ?? __( 'No verified newer offer', 'ran-booster' ) ); ?></code></dd></div>
						<div><dt><?php esc_html_e( 'Latest check result', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $core_update_status['last_check_outcome'] ?? __( 'No completed check recorded', 'ran-booster' ) ); ?></code></dd></div>
					</dl>
					<button form="ran-booster-check-core-updates" type="submit" class="button"<?php echo $check_available ? '' : ( 'enabled' === $status_mode ? ' aria-describedby="ran-booster-check-updates-unavailable"' : ' aria-describedby="ran-booster-self-update-discovery-help"' ); ?><?php disabled( ! $check_available ); ?>><?php esc_html_e( 'Check for updates', 'ran-booster' ); ?></button>
				</div>

				<?php if ( ! $check_available && 'enabled' === $status_mode ) { ?>
					<p id="ran-booster-check-updates-unavailable" class="description"><?php esc_html_e( 'Checking is unavailable for this site or your account.', 'ran-booster' ); ?></p>
				<?php } ?>
				<?php if ( is_int( $success_at ) || is_string( $core_update_status['last_check_code'] ?? null ) ) { ?>
					<dl class="ran-booster-settings__update-facts ran-booster-settings__update-facts--detail">
						<?php if ( is_int( $success_at ) ) { ?>
							<div><dt><?php esc_html_e( 'Last successful Booster check', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $success_label ); ?></code></dd></div>
						<?php } ?>
						<?php if ( is_string( $core_update_status['last_check_code'] ?? null ) ) { ?>
							<div><dt><?php esc_html_e( 'Check diagnostic code', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $core_update_status['last_check_code'] ); ?></code></dd></div>
						<?php } ?>
					</dl>
				<?php } ?>
				<?php
				if ( null !== $native_update_url && '' !== $native_update_url ) {
					?>
					<p><a class="button button-primary" href="<?php echo esc_url( $native_update_url ); ?>"><?php esc_html_e( 'Update now', 'ran-booster' ); ?></a></p><?php } ?>
				<?php $render_configuration( array( 'self_update_discovery' => 'RAN_BOOSTER_SELF_UPDATE_MODE' ) ); ?>
			</fieldset>
<div class="ran-booster-settings__toggle-actions">
	<button id="ran-booster-save-self_update_discovery" type="submit" class="button" data-ran-booster-toggle-save<?php disabled( $discovery['locked'] ); ?>><?php esc_html_e( 'Save setting', 'ran-booster' ); ?></button>
</div>
</form></div>
<?php } ?>
<?php if ( 'all' === $general_scope ) { ?>
	</section>
	<section class="ran-booster-panel ran-booster-card ran-booster-settings__limits" data-ran-booster-card="settings" aria-labelledby="ran-booster-limits-heading">
		<h3 id="ran-booster-limits-heading"><?php esc_html_e( 'History and download limits', 'ran-booster' ); ?></h3>
<?php } ?>
<?php if ( 'all' === $general_scope || 'numeric' === $general_scope ) { ?>
	<?php $open_scope( 'numeric' ); ?>
			<fieldset class="ran-booster-settings__group">
				<legend><?php esc_html_e( 'History', 'ran-booster' ); ?></legend>
				<div class="ran-booster-settings__history-fields">
					<div>
				<p><label for="ran-booster-history-rows"><?php esc_html_e( 'Maximum records', 'ran-booster' ); ?></label><br><input id="ran-booster-history-rows" type="number" min="200" max="100000" step="1" name="ran_booster[preferences][history_rows]" value="<?php echo esc_attr( $value( 'history_rows' ) ); ?>"<?php disabled( $field( 'history_rows' )['locked'] ); ?><?php echo null === $field_error( 'history_rows' ) ? '' : ' aria-invalid="true" aria-describedby="ran-booster-history-rows-error"'; ?>></p>
					</div>
					<div>
				<p><label for="ran-booster-history-days"><?php esc_html_e( 'Retention days', 'ran-booster' ); ?></label><br><input id="ran-booster-history-days" type="number" min="1" max="365" step="1" name="ran_booster[preferences][history_days]" value="<?php echo esc_attr( $value( 'history_days' ) ); ?>"<?php disabled( $field( 'history_days' )['locked'] ); ?><?php echo null === $field_error( 'history_days' ) ? '' : ' aria-invalid="true" aria-describedby="ran-booster-history-days-error"'; ?>></p>
					</div>
				</div>
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
				<legend><?php esc_html_e( 'Download limits', 'ran-booster' ); ?></legend>
					<p><label for="ran-booster-max-download-mib"><?php esc_html_e( 'Maximum repository ZIP download (MiB)', 'ran-booster' ); ?></label><br><input id="ran-booster-max-download-mib" type="number" min="1" max="511.99999904632568359375" step="any" inputmode="decimal" name="ran_booster[preferences][max_download_mib]" value="<?php echo esc_attr( $download_mib_value ); ?>"<?php disabled( $field( 'max_download_bytes' )['locked'] ); ?><?php echo null === $field_error( 'max_download_bytes' ) ? '' : ' aria-invalid="true" aria-describedby="ran-booster-max-download-mib-error"'; ?>></p>
						<p class="description"><?php esc_html_e( 'Limits the compressed repository ZIP, not installed size or History retention. One MiB is 1,048,576 bytes; fractional values round to the nearest byte.', 'ran-booster' ); ?></p>
					<p class="description"><?php /* translators: 1: derived branch expansion ceiling in MiB, 2: exact ceiling in bytes. */ ?><?php echo esc_html( null === $branch_ceiling ? __( 'The derived branch expansion ceiling cannot be calculated on this host.', 'ran-booster' ) : sprintf( __( 'Branch packages have a separate expanded-content ceiling of %1$s MiB (%2$s bytes; 4× the download limit). Host and updater safeguards may be stricter.', 'ran-booster' ), $branch_ceiling_mib, number_format_i18n( $branch_ceiling ) ) ); ?></p>
				<?php $render_configuration( array( 'max_download_bytes' => 'RAN_BOOSTER_MAX_DOWNLOAD_BYTES' ) ); ?>
			</fieldset><div class="ran-booster-settings__save-actions"><button id="ran-booster-save-numeric-bottom" type="submit" class="button button-primary"<?php disabled( array() === $numeric_baseline ); ?>><?php esc_html_e( 'Save changes', 'ran-booster' ); ?></button><span class="screen-reader-text" data-ran-booster-unsaved role="status"><?php esc_html_e( 'No pending numeric changes.', 'ran-booster' ); ?></span></div>
</form></div>
<?php } ?>
<?php
if ( 'all' === $general_scope ) {
	?>
	</section>
	</div><?php } ?>
