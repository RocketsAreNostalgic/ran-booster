<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.
/**
 * @var array<string, mixed> $troubleshooting
 * @var array<string, mixed> $core_self_update
 */
if ( ! defined( 'WPINC' ) ) {
	die; }
$installation        = is_array( $troubleshooting['installation_details'] ?? null ) ? $troubleshooting['installation_details'] : array();
$github_fields       = $installation[ __( 'GitHub provider', 'ran-booster' ) ] ?? array();
$github_basic_labels = array( __( 'Source', 'ran-booster' ), __( 'Version', 'ran-booster' ), __( 'Selection state', 'ran-booster' ), __( 'Operational availability', 'ran-booster' ) );
$environment         = is_array( $troubleshooting['environment'] ?? null ) ? $troubleshooting['environment'] : array();
$environment_summary = array_intersect_key( $environment, array_flip( array( 'WordPress', 'PHP' ) ) );
$environment_details = array_diff_key( $environment, $environment_summary );
$storage             = is_array( $troubleshooting['storage'] ?? null ) ? $troubleshooting['storage'] : array();
$storage_statuses    = array(
	'healthy'     => array( __( 'Storage healthy', 'ran-booster' ), 'ok' ),
	'configured'  => array( __( 'Path configured', 'ran-booster' ), 'neutral' ),
	'actionable'  => array( __( 'Needs attention', 'ran-booster' ), 'warning' ),
	'unknown'     => array( __( 'Not checked', 'ran-booster' ), 'neutral' ),
	'unavailable' => array( __( 'Unavailable', 'ran-booster' ), 'error' ),
);
$storage_status      = $storage_statuses[ $storage['state'] ?? 'unknown' ] ?? $storage_statuses['unknown'];
$storage_labels      = array(
	'configuration'       => __( 'Configuration constant', 'ran-booster' ),
	'path_source'         => __( 'Path selection', 'ran-booster' ),
	'configured_location' => __( 'Storage directory', 'ran-booster' ),
	'storage_file'        => __( 'Storage file', 'ran-booster' ),
);
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
<p class="ran-booster-troubleshooting__environment-summary">
	<?php $environment_separator = ''; ?>
	<?php foreach ( $environment_summary as $label => $value ) { ?>
		<?php echo esc_html( $environment_separator ); ?><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $value ); ?></code>
		<?php $environment_separator = ' · '; ?>
	<?php } ?>
</p><?php } ?>
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
<header class="ran-booster-page-shell__header ran-booster-onboarding__storage-heading"><h4 class="ran-booster-section__title"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h4><span data-ran-booster-ui-badge data-ran-booster-ui-badge-label="<?php echo esc_attr( $storage_status[0] ); ?>" data-ran-booster-ui-badge-intent="<?php echo esc_attr( 'ok' === $storage_status[1] ? 'stable' : ( 'warning' === $storage_status[1] ? 'medium' : ( 'error' === $storage_status[1] ? 'high' : 'none' ) ) ); ?>"><span data-ran-booster-ui-badge-fallback class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $storage_status[1] ); ?>"><?php echo esc_html( $storage_status[0] ); ?></span><span data-ran-booster-ui-badge-mount></span></span></header>
<div class="ran-booster-page-shell__body">
<p><?php echo esc_html( $storage['message'] ?? __( 'Storage status is unavailable.', 'ran-booster' ) ); ?></p>
<details class="ran-booster-troubleshooting__disclosure ran-booster-disclosure"><summary><?php esc_html_e( 'Storage details', 'ran-booster' ); ?></summary>
	<div class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body">
		<?php if ( ! empty( $storage['details'] ) && is_array( $storage['details'] ) ) { ?>
		<dl class="ran-booster-onboarding__storage-facts">
			<?php
			$source_labels = array(
				'automatic' => __( 'Booster default', 'ran-booster' ),
				'manual'    => __( 'Custom wp-config.php path', 'ran-booster' ),
			);
			foreach ( $storage_labels as $key => $label ) {
				$value = $storage['details'][ $key ] ?? null;
				if ( 'path_source' === $key ) {
					$value = $source_labels[ $value ?? '' ] ?? null;
				}
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				?>
				<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php if ( in_array( $key, array( 'configured_location', 'storage_file' ), true ) ) { ?>
					<?php
					$copy_value = $value;
					$copy_label = 'configured_location' === $key ? __( 'Copy storage directory', 'ran-booster' ) : __( 'Copy storage file', 'ran-booster' );
					include __DIR__ . '/copy-displayed-value.php';
					?>
				<?php } else { ?>
					<code class="ran-booster-onboarding__storage-inline"><?php echo esc_html( $value ); ?></code>
				<?php } ?></dd></div>
			<?php } ?>
		</dl>
		<?php } else { ?>
			<p><?php esc_html_e( 'Storage details are unavailable for this request.', 'ran-booster' ); ?></p>
		<?php } ?>
	</div>
</details>
<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings#ran-booster-storage' ) ); ?>"><?php esc_html_e( 'Open secure credential storage settings', 'ran-booster' ); ?></a></p>
</div></section>
			<?php
			$self_update_mode   = $core_self_update['effective_mode'] ?? null;
			$self_update_reason = $core_self_update['reason'] ?? 'release_marker_missing_or_invalid';
			$updater_state      = $core_self_update['updater_state'] ?? null;
			if ( array() === $core_self_update ) {
				$self_update_message = __( 'Core update status is unavailable.', 'ran-booster' );
			} elseif ( 'configuration_disabled' === $self_update_reason ) {
				$self_update_message = __( 'Site configuration has turned Booster update checks off.', 'ran-booster' );
			} elseif ( 'preference_disabled' === $self_update_reason ) {
				$self_update_message = __( 'Booster update checks are turned off in Settings.', 'ran-booster' );
			} elseif ( 'enabled' === $self_update_mode && in_array( $updater_state, array( 'unavailable', 'blocked' ), true ) ) {
				$self_update_message = __( 'RAN Booster could not check for Core updates. The installed version will keep working, and Booster will retry automatically.', 'ran-booster' );
			} elseif ( 'enabled' === $self_update_mode ) {
				$self_update_message = __( 'Booster can check for updates. Installing an update remains a manual action.', 'ran-booster' );
			} elseif ( 'configuration_invalid' === $self_update_reason ) {
				$self_update_message = __( 'Core updates are off because this installation has an invalid self-update setting. Booster will not check for or replace itself.', 'ran-booster' );
			} elseif ( 'disabled' === $self_update_mode ) {
				$self_update_message = __( 'Core updates are off for this source installation. Booster will not check for or replace itself.', 'ran-booster' );
			} else {
				$self_update_message = __( 'Core update status is unavailable.', 'ran-booster' );
			}
			?>
			<section class="ran-booster-panel ran-booster-page-shell ran-booster-troubleshooting__core-updates ran-booster-troubleshooting__installation-card" aria-labelledby="ran-booster-core-updates-heading">
				<header class="ran-booster-page-shell__header ran-booster-troubleshooting__card-heading"><h4 id="ran-booster-core-updates-heading" class="ran-booster-section__title"><?php esc_html_e( 'Booster Core updates', 'ran-booster' ); ?></h4>
				<?php
				$self_update_label = match ( $self_update_mode ) {
					'enabled' => __( 'Checks enabled', 'ran-booster' ),
					'disabled' => __( 'Checks disabled', 'ran-booster' ),
					default => __( 'Status unavailable', 'ran-booster' ) };
				?>
				<span data-ran-booster-ui-badge data-ran-booster-ui-badge-label="<?php echo esc_attr( $self_update_label ); ?>" data-ran-booster-ui-badge-intent="<?php echo esc_attr( 'enabled' === $self_update_mode ? 'stable' : 'none' ); ?>"><span data-ran-booster-ui-badge-fallback class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( 'enabled' === $self_update_mode ? 'ok' : 'neutral' ); ?>"><?php echo esc_html( $self_update_label ); ?></span><span data-ran-booster-ui-badge-mount></span></span></header>
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
</section>
<section aria-labelledby="ran-booster-components-heading">
<h3 id="ran-booster-components-heading"><?php esc_html_e( 'Providers and extensions', 'ran-booster' ); ?></h3>
<?php if ( ! empty( $troubleshooting['components_truncated'] ) ) { ?>
<p><?php esc_html_e( 'Only the first 32 components are shown. Additional components are omitted.', 'ran-booster' ); ?></p>
	<?php
}
if ( array() === $components ) {
	?>
<p><?php esc_html_e( 'Provider and extension information is unavailable.', 'ran-booster' ); ?></p>
	<?php
}
$basic_labels          = array( 'Installed', 'Active', 'Version', 'Admission', 'Selection', 'Availability' );
$github_problem_states = array( 'conflict', 'incompatible', 'missing', 'recovery_required', 'decision_required', 'storage_unavailable' );
foreach ( $components as $component ) {
	$component_name          = $component['Name'] ?? __( 'Unknown', 'ran-booster' );
	$technical_fields        = array_diff_key( $component, array_flip( array_merge( $basic_labels, array( 'Name', 'Capabilities' ) ) ) );
	$has_capabilities        = is_string( $component['Capabilities'] ?? null ) && '' !== $component['Capabilities'];
	$is_github               = ( $component['Provider'] ?? '' ) === 'gh';
	$selection_state         = $github_fields[ __( 'Selection state', 'ran-booster' ) ] ?? null;
	$github_available        = $github_fields[ __( 'Operational availability', 'ran-booster' ) ] ?? null;
	$problem                 = $is_github && ( in_array( $selection_state, $github_problem_states, true ) || __( 'Unavailable', 'ran-booster' ) === $github_available );
	$active                  = $component['Active'] ?? null;
	$component_status        = $problem ? __( 'Needs attention', 'ran-booster' ) : ( 'No' === $active ? __( 'Inactive', 'ran-booster' ) : ( 'Yes' === $active || ( isset( $component['Provider'] ) && 'Admitted' === ( $component['Admission'] ?? null ) && ( ! $is_github || __( 'Available', 'ran-booster' ) === $github_available ) ) ? __( 'Active', 'ran-booster' ) : __( 'Unknown', 'ran-booster' ) ) );
	$tone                    = $problem ? 'warning' : ( __( 'Active', 'ran-booster' ) === $component_status ? 'ok' : 'neutral' );
	$version                 = $is_github ? ( $github_fields[ __( 'Version', 'ran-booster' ) ] ?? null ) : ( $component['Version'] ?? null );
	$source                  = null;
	$github_problem_messages = array(
		'missing'             => __( 'The selected GitHub provider plugin is not active or available. Review provider settings.', 'ran-booster' ),
		'conflict'            => __( 'Multiple GitHub provider plugins need review in provider settings.', 'ran-booster' ),
		'incompatible'        => __( 'A GitHub provider plugin is incompatible. Review provider settings.', 'ran-booster' ),
		'recovery_required'   => __( 'GitHub provider recovery is required. Review provider settings.', 'ran-booster' ),
		'decision_required'   => __( 'The GitHub provider selection needs a decision. Review provider settings.', 'ran-booster' ),
		'storage_unavailable' => __( 'GitHub provider selection could not be checked. Review provider settings.', 'ran-booster' ),
	);
	if ( $is_github ) {
		$selected_name = null;
		if ( __( 'Bundled with Booster', 'ran-booster' ) === ( $github_fields[ __( 'Source', 'ran-booster' ) ] ?? null ) ) {
			$selected_name = __( 'Included with Booster', 'ran-booster' );
		} elseif ( __( 'External replacement', 'ran-booster' ) === ( $github_fields[ __( 'Source', 'ran-booster' ) ] ?? null ) && is_string( $github_fields[ __( 'Name', 'ran-booster' ) ] ?? null ) && __( 'Unavailable', 'ran-booster' ) !== $github_fields[ __( 'Name', 'ran-booster' ) ] ) {
			$selected_name = $github_fields[ __( 'Name', 'ran-booster' ) ];
		}
		if ( null !== $selected_name ) {
			if ( $problem || __( 'Available', 'ran-booster' ) !== $github_available ) {
				$source = sprintf( /* translators: %s: configured GitHub provider name. */ __( 'Selected provider: %s', 'ran-booster' ), $selected_name );
			} elseif ( __( 'Bundled with Booster', 'ran-booster' ) === ( $github_fields[ __( 'Source', 'ran-booster' ) ] ?? null ) ) {
				$source = $selected_name;
			} else {
				$source = sprintf( /* translators: %s: selected provider plugin name. */ __( 'Provided by %s', 'ran-booster' ), $selected_name );
			}
		}
	} elseif ( ! isset( $component['Provider'] ) && 'Yes' === ( $component['Installed'] ?? null ) ) {
		$source = __( 'Installed extension', 'ran-booster' );
	} elseif ( isset( $component['Provider'] ) && 'Yes' === $active && isset( $component['Name'] ) ) {
		$source = sprintf( /* translators: %s: selected provider plugin name. */ __( 'Provided by %s', 'ran-booster' ), $component['Name'] );
	}
	?>
<section class="ran-booster-panel ran-booster-page-shell ran-booster-troubleshooting__installation-card">
<header class="ran-booster-page-shell__header ran-booster-troubleshooting__card-heading"><h4 class="ran-booster-section__title"><?php echo esc_html( $component_name ); ?>
	<?php
	if ( is_string( $version ) && '' !== $version ) {
		?>
	<code><?php echo esc_html( $version ); ?></code><?php } ?></h4><span data-ran-booster-ui-badge data-ran-booster-ui-badge-label="<?php echo esc_attr( $component_status ); ?>" data-ran-booster-ui-badge-intent="<?php echo esc_attr( 'ok' === $tone ? 'stable' : ( 'warning' === $tone ? 'medium' : 'none' ) ); ?>"><span data-ran-booster-ui-badge-fallback class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $component_status ); ?></span><span data-ran-booster-ui-badge-mount></span></span></header>
<div class="ran-booster-page-shell__body">
	<?php
	if ( null !== $source || isset( $component['Provider'] ) ) {
		?>
		<p><?php echo esc_html( $source ?? __( 'Provider source unknown', 'ran-booster' ) ); ?></p><?php } ?>
	<?php
	if ( $problem ) {
		?>
		<p><?php echo esc_html( $github_problem_messages[ $selection_state ?? '' ] ?? __( 'GitHub provider selection needs attention. Review the provider settings before use.', 'ran-booster' ) ); ?></p><?php } ?>
	<?php if ( array() !== $technical_fields || $has_capabilities ) { ?>
<details<?php echo $is_github ? ' id="ran-booster-github-provider-diagnostics"' : ''; ?> class="ran-booster-troubleshooting__disclosure ran-booster-disclosure">
<summary aria-label="<?php echo esc_attr( sprintf( /* translators: %s: provider or extension name. */ __( 'Technical details: %s', 'ran-booster' ), $component_name ) ); ?>"><?php esc_html_e( 'Technical details', 'ran-booster' ); ?></summary>
<div class="ran-booster-troubleshooting__disclosure-body ran-booster-disclosure__body">
<p><?php echo esc_html( isset( $component['Provider'] ) ? __( 'These are local configuration details. Use Diagnostics to check repository access.', 'ran-booster' ) : __( 'These are local configuration details.', 'ran-booster' ) ); ?></p>
<dl class="ran-booster-troubleshooting__fields-list">
		<?php
		foreach ( $technical_fields as $label => $value ) {
			?>
		<div><dt><?php echo esc_html( 'Implementation' === $label ? __( 'Implementation fingerprint', 'ran-booster' ) : $label ); ?></dt><dd><code><?php echo esc_html( $value ); ?></code></dd></div>
		<?php } ?>
		<?php if ( $has_capabilities ) { ?>
		<div><dt><?php esc_html_e( 'Implemented capabilities', 'ran-booster' ); ?></dt><dd>
			<?php
			foreach ( explode( ', ', $component['Capabilities'] ) as $index => $capability ) {
				echo 0 === $index ? '' : ', ';
				echo '<code>' . esc_html( $capability ) . '</code>';
			}
			?>
		</dd></div>
	<?php } ?>
		<?php if ( $is_github ) { ?>
			<?php
			if ( isset( $github_fields[ __( 'Implementation', 'ran-booster' ) ] ) ) {
				?>
			<div><dt><?php esc_html_e( 'Implementation ID', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $github_fields[ __( 'Implementation', 'ran-booster' ) ] ); ?></code></dd></div><?php } ?>
			<?php foreach ( array( __( 'Compatibility revision', 'ran-booster' ), __( 'Webhook signing format', 'ran-booster' ), __( 'Provider API', 'ran-booster' ) ) as $field_label ) { ?>
				<?php
				if ( isset( $github_fields[ $field_label ] ) ) {
					?>
				<div><dt><?php echo esc_html( $field_label ); ?></dt><dd><code><?php echo esc_html( $github_fields[ $field_label ] ); ?></code></dd></div><?php } ?>
		<?php } ?>
			<?php
			$credential_formats = array();
			for ( $index = 1; $index <= 32; ++$index ) {
				/* translators: %d: locally declared credential format number. */
				$format = $github_fields[ sprintf( __( 'API credential format %d', 'ran-booster' ), $index ) ] ?? null;
				if ( 'github-classic-pat-v1' === $format ) {
					$credential_formats[] = __( 'Classic personal access token', 'ran-booster' ); }
				if ( 'github-fine-grained-pat-v1' === $format ) {
					$credential_formats[] = __( 'Fine-grained personal access token', 'ran-booster' ); }
			}
			if ( array() !== $credential_formats ) {
				?>
			<div><dt><?php esc_html_e( 'Supported credential types', 'ran-booster' ); ?></dt><dd><?php echo esc_html( implode( ', ', $credential_formats ) ); ?></dd></div>
			<?php } ?>
	<?php } ?>
</dl>
		<?php
		if ( $is_github ) {
			$candidate_heading = false;
			$candidate_shown   = 0;
			$candidate_total   = $github_fields[ __( 'Enabled candidates', 'ran-booster' ) ] ?? null;
			for ( $index = 1; $index <= 4; ++$index ) {
				/* translators: %d: enabled replacement candidate number. */
				$candidate = $installation[ sprintf( __( 'GitHub candidate %d', 'ran-booster' ), $index ) ] ?? null;
				if ( ! is_array( $candidate ) ) {
					continue; }
				++$candidate_shown;
				if ( ! $candidate_heading ) {
					?>
		<h5><?php esc_html_e( 'GitHub replacement plugins detected', 'ran-booster' ); ?></h5>
					<?php
					$candidate_heading = true; }
				?>
		<dl class="ran-booster-troubleshooting__fields-list">
				<?php
				foreach ( array( __( 'Name', 'ran-booster' ), __( 'Version', 'ran-booster' ), __( 'Implementation', 'ran-booster' ), __( 'Compatibility revision', 'ran-booster' ), __( 'Provider API', 'ran-booster' ), __( 'Webhook signing format', 'ran-booster' ) ) as $field_label ) {
					if ( isset( $candidate[ $field_label ] ) ) {
						?>
			<div><dt><?php echo esc_html( $field_label ); ?></dt><dd><code><?php echo esc_html( $candidate[ $field_label ] ); ?></code></dd></div>
						<?php
					}
				}
				?>
															</dl>
				<?php
			}
			if ( is_string( $candidate_total ) && ctype_digit( $candidate_total ) && (int) $candidate_total > $candidate_shown ) {
				?>
			<p><?php /* translators: 1: displayed replacement plugins, 2: total replacement plugins. */ ?><?php echo esc_html( sprintf( __( 'Showing %1$d of %2$d replacement plugins.', 'ran-booster' ), $candidate_shown, (int) $candidate_total ) ); ?></p>
				<?php
			}
		}
		?>
</div></details>
	<?php } ?>
</div></section>
<?php } ?>
</section>
