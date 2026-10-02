<?php

/**
 * View locals supplied by Dashboard::render().
 *
 * @var array<string, mixed> $troubleshooting
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$troubleshooting_panel = isset( $troubleshooting_panel ) && in_array( $troubleshooting_panel, array( 'diagnostics', 'debug-capture', 'activity' ), true )
	? $troubleshooting_panel
	: 'diagnostics';
$admin_base            = is_multisite()
	? network_admin_url( 'admin.php' )
	: admin_url( 'admin.php' );
$troubleshooting_base  = $admin_base . '?page=ran-booster&tab=troubleshooting';
$secondary_navigation  = array(
	array(
		'key'   => 'diagnostics',
		'label' => __( 'Diagnostics', 'ran-booster' ),
		'url'   => $troubleshooting_base . '&panel=diagnostics',
	),
	array(
		'key'   => 'activity',
		'label' => __( 'Activity', 'ran-booster' ),
		'url'   => $troubleshooting_base . '&panel=activity',
	),
	array(
		'key'   => 'debug-capture',
		'label' => __( 'Logging', 'ran-booster' ),
		'url'   => $troubleshooting_base . '&panel=debug-capture',
	),
);

$status_labels     = array(
	'pass'           => __( 'Passed', 'ran-booster' ),
	'warning'        => __( 'Warning', 'ran-booster' ),
	'fail'           => __( 'Failed', 'ran-booster' ),
	'not_configured' => __( 'Not configured', 'ran-booster' ),
);
$status_badge_tones = array(
	'pass'           => 'ok',
	'warning'        => 'warning',
	'fail'           => 'error',
	'not_configured' => 'warning',
);
$partial_messages  = array(
	'local_incomplete'         => __( 'Local checks could not finish, so provider checks were not started.', 'ran-booster' ),
	'deadline_exhausted'       => __( 'The ten-second diagnostic deadline was reached. Completed checks are shown below.', 'ran-booster' ),
	'remote_calls_exhausted'   => __( 'The five-request provider budget was reached. Completed checks are shown below.', 'ran-booster' ),
	'provider_unavailable'     => __( 'The selected provider could not complete diagnostics. Local results are still available.', 'ran-booster' ),
	'provider_results_invalid' => __( 'The provider returned an unsafe or invalid diagnostic result, which Booster omitted.', 'ran-booster' ),
	'result_limit_exhausted'   => __( 'The eight-result limit was reached. Earlier results are shown below.', 'ran-booster' ),
);
$selected_provider = $troubleshooting['selected_provider'] ?? '';
$results          = is_array( $troubleshooting['results'] ?? null ) ? $troubleshooting['results'] : array();
$credentials      = is_array( $troubleshooting['credentials'] ?? null ) ? $troubleshooting['credentials'] : array();
$credential_id     = is_string( $troubleshooting['credential_id'] ?? null ) ? $troubleshooting['credential_id'] : '';
$repository       = is_string( $troubleshooting['repository'] ?? null ) ? $troubleshooting['repository'] : '';
$show_specific_form = '' !== $credential_id || '' !== $repository;
$core_self_update   = is_array( $troubleshooting['core_self_update'] ?? null )
	? $troubleshooting['core_self_update']
	: array();
$locator_hints     = is_array( $troubleshooting['provider_locator_hints'] ?? null ) ? $troubleshooting['provider_locator_hints'] : array();
$locator_examples  = array();
foreach ( $troubleshooting['providers'] ?? array() as $provider_code => $provider_label ) {
	$hint = $locator_hints[ $provider_code ] ?? '';
	if ( is_string( $hint ) && is_string( $provider_label ) && '' !== $hint ) {
		$locator_examples[] = $hint . ' (' . $provider_label . ')';
	}
}
$repository_placeholder = array() === $locator_examples
	? __( 'account/repository', 'ran-booster' )
	: implode( ' or ', $locator_examples );

?>
<section id="ran-booster-troubleshooting-diagnostics-region" class="ran-booster-page-shell ran-booster-panel ran-booster-troubleshooting" aria-labelledby="ran-booster-troubleshooting-heading">
	<header class="ran-booster-page-shell__header ran-booster-troubleshooting__header">
		<p class="ran-booster-eyebrow"><?php esc_html_e( 'Diagnostics', 'ran-booster' ); ?></p>
		<h2 id="ran-booster-troubleshooting-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Troubleshooting', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Run protected checks, temporarily capture Booster events, or review recent update activity.', 'ran-booster' ); ?></p>
	</header>

	<div class="ran-booster-page-shell__body">
		<nav class="ran-booster-secondary-nav" aria-label="<?php echo esc_attr( __( 'Troubleshooting views', 'ran-booster' ) ); ?>">
		<?php foreach ( $secondary_navigation as $secondary_item ) { ?>
			<a class="ran-booster-secondary-nav__link<?php echo $troubleshooting_panel === $secondary_item['key'] ? ' is-current' : ''; ?>" href="<?php echo esc_url( $secondary_item['url'] ); ?>"<?php echo $troubleshooting_panel === $secondary_item['key'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $secondary_item['label'] ); ?></a>
		<?php } ?>
		</nav>

	<?php if ( 'debug-capture' === $troubleshooting_panel ) { ?>
		<?php require __DIR__ . '/debug-capture.php'; ?>
	<?php } elseif ( 'activity' === $troubleshooting_panel ) { ?>
		<?php
		$deployment_activity = isset( $deployment_activity ) && is_array( $deployment_activity )
			? $deployment_activity
			: array();
		$activity_mode       = isset( $deployment_activity['mode'] ) && in_array( $deployment_activity['mode'], array( 'list', 'detail' ), true )
			? $deployment_activity['mode']
			: 'list';
		if ( 'detail' === $activity_mode ) {
			require __DIR__ . '/attempts/detail.php';
		} else {
			require __DIR__ . '/attempts/index.php';
		}
		?>
	<?php } else { ?>
		<?php if ( array() !== $core_self_update ) { ?>
			<?php
			$self_update_mode   = $core_self_update['effective_mode'] ?? 'disabled';
			$self_update_reason = $core_self_update['reason'] ?? 'release_marker_missing_or_invalid';
			$updater_state     = $core_self_update['updater_state'] ?? null;
			$self_update_tone   = 'notice-info';
			if ( 'unavailable' === $updater_state || 'blocked' === $updater_state ) {
				$self_update_tone    = 'notice-warning';
				$self_update_message = __( 'RAN Booster could not check for Core updates. The installed version will keep working, and Booster will retry automatically.', 'ran-booster' );
			} elseif ( 'enabled' === $self_update_mode ) {
				$self_update_tone    = 'notice-success';
				$self_update_message = __( 'Core updates are available manually for this verified release installation. Background Core updates remain off.', 'ran-booster' );
			} elseif ( 'configuration_disabled' === $self_update_reason ) {
				$self_update_message = __( 'Core updates are off because this site explicitly disables them. Booster will not check for or replace itself.', 'ran-booster' );
			} elseif ( 'configuration_invalid' === $self_update_reason ) {
				$self_update_tone    = 'notice-warning';
				$self_update_message = __( 'Core updates are off because this installation has an invalid self-update setting. Booster will not check for or replace itself.', 'ran-booster' );
			} else {
				$self_update_message = __( 'Core updates are off for this source installation. Booster will not check for or replace itself.', 'ran-booster' );
			}
			$self_update_technical = array(
				__( 'Requested mode', 'ran-booster' )   => $core_self_update['requested_mode'] ?? null,
				__( 'Effective mode', 'ran-booster' )   => $self_update_mode,
				__( 'Policy reason', 'ran-booster' )    => $self_update_reason,
				__( 'Updater state', 'ran-booster' )    => $updater_state,
				__( 'Updater code', 'ran-booster' )     => $core_self_update['updater_code'] ?? null,
				__( 'Selected runtime', 'ran-booster' ) => $core_self_update['selected_version'] ?? null,
				__( 'Offered version', 'ran-booster' )  => $core_self_update['offered_version'] ?? null,
				__( 'Last check (UTC)', 'ran-booster' ) => is_int( $core_self_update['last_check'] ?? null )
					? gmdate( 'c', $core_self_update['last_check'] )
					: null,
				__( 'Next check (UTC)', 'ran-booster' ) => is_int( $core_self_update['next_check'] ?? null )
					? gmdate( 'c', $core_self_update['next_check'] )
					: null,
				__( 'Marker version', 'ran-booster' )   => $core_self_update['marker_version'] ?? null,
				__( 'Marker commit', 'ran-booster' )    => $core_self_update['marker_commit'] ?? null,
			);
			?>
			<div class="notice <?php echo esc_attr( $self_update_tone ); ?> inline ran-booster-troubleshooting__core-updates">
				<p><strong><?php esc_html_e( 'Core updates', 'ran-booster' ); ?></strong></p>
				<p><?php echo esc_html( $self_update_message ); ?></p>
				<details>
					<summary><?php esc_html_e( 'Technical details', 'ran-booster' ); ?></summary>
					<dl>
					<?php foreach ( $self_update_technical as $technical_label => $technical_value ) { ?>
						<?php if ( is_string( $technical_value ) && '' !== $technical_value ) { ?>
							<dt><?php echo esc_html( $technical_label ); ?></dt>
							<dd><code><?php echo esc_html( $technical_value ); ?></code></dd>
						<?php } ?>
					<?php } ?>
					</dl>
				</details>
			</div>
		<?php } ?>

	<form method="post" action="" class="ran-booster-troubleshooting__form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-troubleshooting-diagnostics-error" hx-post="" hx-target="#ran-booster-troubleshooting-diagnostics-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop">
		<?php wp_nonce_field( 'ran-booster-run-troubleshooting' ); ?>
		<input type="hidden" name="ran_booster[action]" value="run-troubleshooting">
		<div id="ran-booster-troubleshooting-diagnostics-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>

		<div class="ran-booster-troubleshooting__fields ran-booster-troubleshooting__provider-field">
			<p>
				<label for="ran-booster-troubleshooting-provider"><?php esc_html_e( 'Provider', 'ran-booster' ); ?></label>
				<select id="ran-booster-troubleshooting-provider" class="ran-booster-troubleshooting__provider" name="ran_booster[provider]" required>
					<?php foreach ( $troubleshooting['providers'] as $code => $label ) { ?>
						<option value="<?php echo esc_attr( $code ); ?>"<?php selected( $selected_provider, $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php } ?>
				</select>
			</p>
		</div>
		<p class="description"><?php esc_html_e( 'Start with a provider to run a general health check. The optional fields below check only credential and repository access; they do not scope Push-to-Deploy delivery results.', 'ran-booster' ); ?></p>

		<details class="ran-booster-troubleshooting__specific"<?php echo $show_specific_form ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Check credential or repository access (optional)', 'ran-booster' ); ?></summary>
			<div class="ran-booster-troubleshooting__fields">
				<p>
					<label for="ran-booster-troubleshooting-credential"><?php esc_html_e( 'Saved credential', 'ran-booster' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'ran-booster' ); ?></span></label>
					<select id="ran-booster-troubleshooting-credential" class="ran-booster-troubleshooting__credential" name="ran_booster[credential_id]">
						<option value=""><?php esc_html_e( 'No saved credential (check public access)', 'ran-booster' ); ?></option>
						<?php
						foreach ( $credentials as $provider_code => $provider_credentials ) {
							if ( ! is_string( $provider_code ) || ! is_array( $provider_credentials ) ) {
								continue;
							}
							foreach ( $provider_credentials as $credential ) {
								$choice_id    = is_string( $credential['id'] ?? null ) ? $credential['id'] : '';
								$choice_label = is_string( $credential['label'] ?? null ) ? $credential['label'] : '';
								if ( '' === $choice_id || '' === $choice_label ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $choice_id ); ?>" data-provider="<?php echo esc_attr( $provider_code ); ?>"<?php selected( $selected_provider === $provider_code ? $credential_id : '', $choice_id ); ?><?php disabled( $selected_provider !== $provider_code ); ?><?php echo $selected_provider !== $provider_code ? ' hidden' : ''; ?>><?php echo esc_html( $choice_label ); ?></option>
								<?php
							}
						}
						?>
					</select>
					<span class="description"><?php esc_html_e( 'Choose a saved credential only to check private-repository access. This is a credential reference, not the credential secret; Booster never displays the credential secret here.', 'ran-booster' ); ?></span>
					<span class="description"><?php esc_html_e( 'The active provider can read every credential saved under its provider code, not only the selected diagnostic profile. Booster does not authenticate a third-party publisher.', 'ran-booster' ); ?></span>
				</p>
				<p>
					<label for="ran-booster-troubleshooting-repository"><?php esc_html_e( 'Repository', 'ran-booster' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'ran-booster' ); ?></span></label>
						<input id="ran-booster-troubleshooting-repository" type="text" name="ran_booster[repository]" value="<?php echo esc_attr( $repository ); ?>" maxlength="512" placeholder="<?php echo esc_attr( $repository_placeholder ); ?>">
					<span class="description"><?php esc_html_e( 'Enter a repository only when diagnosing a particular access issue. Without a saved credential, Booster checks whether it is publicly available.', 'ran-booster' ); ?></span>
				</p>
			</div>
		</details>

		<p class="description"><?php esc_html_e( 'The full check makes temporary local marker files and up to five provider requests. Markers are removed immediately. Booster does not install packages, trigger webhooks or save a report.', 'ran-booster' ); ?></p>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Run full diagnostics', 'ran-booster' ); ?></button></p>
	</form>

		<?php if ( ! empty( $troubleshooting['ran'] ) ) { ?>
		<div class="ran-booster-troubleshooting__results" aria-live="polite">
			<?php if ( ! empty( $troubleshooting['partial'] ) ) { ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $partial_messages[ $troubleshooting['partial_reason'] ?? '' ] ?? __( 'Diagnostics finished with partial results.', 'ran-booster' ) ); ?></p></div>
			<?php } ?>

			<div class="ran-booster-data-table-wrap ran-booster-troubleshooting__table-wrap">
				<table class="widefat ran-booster-data-table ran-booster-data-table--rows ran-booster-troubleshooting__table">
					<caption class="screen-reader-text"><?php esc_html_e( 'RAN Booster diagnostic results', 'ran-booster' ); ?></caption>
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Status', 'ran-booster' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Check', 'ran-booster' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'ran-booster' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'ran-booster' ); ?></th>
					</tr></thead>
					<?php
					$local_results    = array();
					$provider_results = array();
					foreach ( $results as $result ) {
						if ( str_starts_with( $result['code'], 'local.' ) ) {
							$local_results[] = $result;
						} else {
							$provider_results[] = $result;
						}
					}

					foreach ( array(
						'local'    => $local_results,
						'provider' => $provider_results,
					) as $result_group => $group_results ) {
						if ( array() === $group_results ) {
							continue;
						}
						?>
						<tbody class="ran-booster-troubleshooting__<?php echo esc_attr( $result_group ); ?>-results">
						<?php foreach ( $group_results as $result ) { ?>
							<?php $status_tone = $status_badge_tones[ $result['status'] ] ?? 'neutral'; ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Status', 'ran-booster' ); ?>"><span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $status_tone ); ?> ran-booster-diagnostic-status ran-booster-diagnostic-status--<?php echo esc_attr( $result['status'] ); ?>"><?php echo esc_html( $status_labels[ $result['status'] ] ?? __( 'Unknown', 'ran-booster' ) ); ?></span></td>
							<th scope="row" data-label="<?php esc_attr_e( 'Check', 'ran-booster' ); ?>"><code><?php echo esc_html( $result['code'] ); ?></code></th>
							<td data-label="<?php esc_attr_e( 'Details', 'ran-booster' ); ?>"><?php echo esc_html( $result['message'] ); ?></td>
							<td data-label="<?php esc_attr_e( 'Action', 'ran-booster' ); ?>"><?php echo esc_html( $result['remediation'] ); ?></td>
						</tr>
					<?php } ?>
					</tbody>
					<?php } ?>
				</table>
			</div>

			<p><label for="ran-booster-troubleshooting-report"><strong><?php esc_html_e( 'Support report', 'ran-booster' ); ?></strong></label></p>
			<textarea id="ran-booster-troubleshooting-report" class="large-text code" rows="<?php echo esc_attr( (string) min( 16, max( 5, count( $results ) + 4 ) ) ); ?>" readonly><?php echo esc_textarea( $troubleshooting['report'] ?? '' ); ?></textarea>
			<p class="description"><?php esc_html_e( 'This report contains only the safe, displayed result fields. Review it before sharing.', 'ran-booster' ); ?></p>
		</div>
	<?php } ?>
	<?php } ?>
	</div>
</section>
