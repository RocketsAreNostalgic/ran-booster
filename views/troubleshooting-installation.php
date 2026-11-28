<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.
/**
 * @var array<string, mixed> $troubleshooting
 * @var array<string, mixed> $core_self_update
 */
if ( ! defined( 'WPINC' ) ) {
	die; }
$installation        = is_array( $troubleshooting['installation_details'] ?? null ) ? $troubleshooting['installation_details'] : array();
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
<h3 id="ran-booster-installation-heading" class="ran-booster-section__title ran-booster-section__title--ruled"><?php esc_html_e( 'Installation', 'ran-booster' ); ?></h3>
<?php
// Environment.
ob_start();
if ( array() === $environment ) {
	esc_html_e( 'Environment information is unavailable.', 'ran-booster' );
} else {
	$environment_separator = '';
	foreach ( $environment_summary as $label => $value ) {
		echo esc_html( $environment_separator ) . esc_html( $label ) . ' <code>' . esc_html( $value ) . '</code>';
		$environment_separator = ' · ';
	}
}
$entry_description = (string) ob_get_clean();
ob_start();
if ( array() !== $environment_details ) {
	?>
		<dl class="ran-booster-troubleshooting__fields-list">
		<?php foreach ( $environment_details as $label => $value ) { ?>
			<div><dt><?php echo esc_html( $label ); ?></dt><dd><code><?php echo esc_html( $value ); ?></code></dd></div><?php } ?>
		</dl>
	<?php
}
$entry_body  = (string) ob_get_clean();
$entry_title = esc_html__( 'Environment', 'ran-booster' );
$entry_label = __( 'Environment', 'ran-booster' );
$entry_badge = '';
$entry_id    = '';
require __DIR__ . '/troubleshooting-installation-entry.php';

// Secure credential storage.
$entry_description = esc_html( $storage['message'] ?? __( 'Storage status is unavailable.', 'ran-booster' ) );
ob_start();
?>
		<?php if ( ! empty( $storage['details'] ) && is_array( $storage['details'] ) ) { ?>
		<dl class="ran-booster-onboarding__storage-facts">
			<?php
			$source_labels = array(
				'automatic' => __( 'Booster default', 'ran-booster' ),
				'manual'    => __( 'Configured path', 'ran-booster' ),
				'unknown'   => __( 'Unknown', 'ran-booster' ),
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
				<div<?php echo in_array( $key, array( 'configured_location', 'storage_file' ), true ) ? '' : ' class="ran-booster-onboarding__storage-fact--inline"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>><dt><?php echo esc_html( $label ); ?></dt><dd><?php if ( in_array( $key, array( 'configured_location', 'storage_file' ), true ) ) { ?>
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
		<p class="ran-booster-standalone-link-row"><a href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-settings#ran-booster-storage' ) ); ?>"><?php esc_html_e( 'Open secure credential storage settings', 'ran-booster' ); ?></a></p>
<?php
$entry_body  = (string) ob_get_clean();
$entry_title = esc_html__( 'Secure credential storage', 'ran-booster' );
$entry_label = __( 'Secure credential storage', 'ran-booster' );
$entry_badge = \RAN\Admin\Component\StatusBadge::for_intent( (string) $storage_status[0], 'ok' === $storage_status[1] ? 'stable' : ( 'warning' === $storage_status[1] ? 'medium' : ( 'error' === $storage_status[1] ? 'high' : 'none' ) ) );
$entry_id    = '';
require __DIR__ . '/troubleshooting-installation-entry.php';
?>
			<?php
			$self_update_mode   = $core_self_update['effective_mode'] ?? null;
			$self_update_reason = $core_self_update['reason'] ?? 'release_marker_missing_or_invalid';
			$updater_state      = $core_self_update['updater_state'] ?? null;
			if ( array() === $core_self_update ) {
				$self_update_message = __( 'Core updates status is unavailable.', 'ran-booster' );
			} elseif ( 'configuration_disabled' === $self_update_reason ) {
				$self_update_message = __( 'Turned off programmatically in site configuration.', 'ran-booster' );
			} elseif ( 'preference_disabled' === $self_update_reason ) {
				$self_update_message = __( 'Core updates are turned off in Settings.', 'ran-booster' );
			} elseif ( 'enabled' === $self_update_mode && in_array( $updater_state, array( 'unavailable', 'blocked' ), true ) ) {
				$self_update_message = __( 'Booster could not check for Core updates. The installed version will keep working, and Booster will retry automatically.', 'ran-booster' );
			} elseif ( 'enabled' === $self_update_mode ) {
				$self_update_message = __( 'Booster can check for Core updates. Installing an update remains a manual action.', 'ran-booster' );
			} elseif ( 'configuration_invalid' === $self_update_reason ) {
				$self_update_message = __( 'Core updates are off because this installation has an invalid Core updates configuration. Booster will not check for or replace itself.', 'ran-booster' );
			} elseif ( 'disabled' === $self_update_mode ) {
				$self_update_message = __( 'Core updates are off for this source installation. Booster will not check for or replace itself.', 'ran-booster' );
			} else {
				$self_update_message = __( 'Core updates status is unavailable.', 'ran-booster' );
			}
			$self_update_label = match ( $self_update_mode ) {
				'enabled' => __( 'Checks enabled', 'ran-booster' ),
				'disabled' => __( 'Checks disabled', 'ran-booster' ),
				default => __( 'Status unavailable', 'ran-booster' ) };
			$core_fields = $installation[ __( 'Core updates', 'ran-booster' ) ] ?? array();
			ob_start();
			if ( ! empty( $core_fields ) ) {
				?>
				<dl class="ran-booster-troubleshooting__fields-list">
				<?php foreach ( $core_fields as $field_label => $field_value ) { ?>
					<div><dt><?php echo esc_html( $field_label ); ?></dt><dd><code><?php echo esc_html( $field_value ); ?></code></dd></div><?php } ?>
				</dl>
				<?php
			}
			$entry_body        = (string) ob_get_clean();
			$entry_title       = esc_html__( 'Core updates', 'ran-booster' );
			$entry_label       = __( 'Core updates', 'ran-booster' );
			$entry_description = esc_html( $self_update_message );
			$entry_badge       = \RAN\Admin\Component\StatusBadge::for_intent( (string) $self_update_label, 'enabled' === $self_update_mode ? 'stable' : 'none' );
			$entry_id          = '';
			require __DIR__ . '/troubleshooting-installation-entry.php';
			?>
</section>
<section aria-labelledby="ran-booster-components-heading">
<h3 id="ran-booster-components-heading" class="ran-booster-section__title ran-booster-section__title--ruled"><?php esc_html_e( 'Providers and extensions', 'ran-booster' ); ?></h3>
<?php if ( ! empty( $troubleshooting['components_truncated'] ) ) { ?>
<p><?php esc_html_e( 'Only the first 32 components are shown. Additional components are omitted.', 'ran-booster' ); ?></p>
	<?php
}
if ( array() === $components ) {
	?>
<p><?php esc_html_e( 'Provider and extension information is unavailable.', 'ran-booster' ); ?></p>
	<?php
}
$basic_labels = array( 'Installed', 'Active', 'Version', 'Admission', 'Selection', 'Availability', 'Supplier' );
foreach ( $components as $component ) {
	$component_name   = $component['Name'] ?? __( 'Unknown', 'ran-booster' );
	$technical_fields = array_diff_key( $component, array_flip( array_merge( $basic_labels, array( 'Name', 'Capabilities' ) ) ) );
	$has_capabilities = is_string( $component['Capabilities'] ?? null ) && '' !== $component['Capabilities'];
	$is_github        = ( $component['Provider'] ?? '' ) === 'gh';
	$active           = $component['Active'] ?? null;
	$component_status = 'Unavailable' === ( $component['Availability'] ?? null ) ? __( 'Unavailable', 'ran-booster' ) : ( 'No' === $active ? __( 'Inactive', 'ran-booster' ) : ( 'Yes' === $active || ( isset( $component['Provider'] ) && 'Admitted' === ( $component['Admission'] ?? null ) ) ? __( 'Active', 'ran-booster' ) : __( 'Unknown', 'ran-booster' ) ) );
	$tone             = __( 'Active', 'ran-booster' ) === $component_status ? 'ok' : 'neutral';
	$version          = $component['Version'] ?? null;
	$source           = null;
	if ( $is_github && is_string( $troubleshooting['github_supplier'] ?? null ) ) {
		$source = $troubleshooting['github_supplier'];
	} elseif ( ! isset( $component['Provider'] ) && 'Yes' === ( $component['Installed'] ?? null ) ) {
		$source = __( 'Installed extension', 'ran-booster' );
	} elseif ( isset( $component['Provider'] ) && 'Yes' === $active && isset( $component['Name'] ) ) {
		$source = sprintf( /* translators: %s: selected provider plugin name. */ __( 'Provider extension: %s', 'ran-booster' ), $component['Name'] );
	} elseif ( $is_github ) {
		$source = __( 'Included with Booster', 'ran-booster' );
	}
	// The dot is decorative, so the heading is announced as the name and version.
	$version_markup = is_string( $version ) && '' !== $version
		? ' <span class="ran-booster-troubleshooting__version-separator" aria-hidden="true">•</span> <code class="ran-booster-troubleshooting__version">' . esc_html( $version ) . '</code>'
		: '';
	ob_start();
	if ( array() !== $technical_fields || $has_capabilities ) {
		?>
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
</dl>
		<?php
	}
	$entry_body        = (string) ob_get_clean();
	$entry_title       = esc_html( $component_name ) . $version_markup;
	$entry_label       = is_string( $version ) && '' !== $version ? $component_name . ' • ' . $version : $component_name;
	$entry_description = null !== $source || isset( $component['Provider'] ) ? esc_html( $source ?? __( 'Provider source unknown', 'ran-booster' ) ) : '';
	$entry_badge       = \RAN\Admin\Component\StatusBadge::for_intent( (string) $component_status, 'ok' === $tone ? 'stable' : 'none' );
	$entry_id          = $is_github ? 'ran-booster-github-provider-diagnostics' : '';
	require __DIR__ . '/troubleshooting-installation-entry.php';
}
?>
</section>
