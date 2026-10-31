<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * View locals supplied by Dashboard::render().
 *
 * @var array<string, mixed> $troubleshooting
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$troubleshooting_panel = isset( $troubleshooting_panel ) && in_array( $troubleshooting_panel, array( 'installation', 'diagnostics', 'debug-capture', 'activity', 'support-report' ), true )
	? $troubleshooting_panel
	: 'installation';
$admin_base            = is_multisite()
	? network_admin_url( 'admin.php' )
	: admin_url( 'admin.php' );
$troubleshooting_base  = $admin_base . '?page=ran-booster&tab=troubleshooting';
$secondary_navigation  = array(
	array(
		'key'   => 'installation',
		'label' => __( 'Installation', 'ran-booster' ),
		'url'   => $troubleshooting_base . '&panel=installation',
	),
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
	array(
		'key'   => 'support-report',
		'label' => __( 'Support report', 'ran-booster' ),
		'url'   => $troubleshooting_base . '&panel=support-report',
	),
);

$status_labels      = array(
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
$partial_messages   = array(
	'local_incomplete'         => __( 'Local checks could not finish, so Git provider checks were not started.', 'ran-booster' ),
	'deadline_exhausted'       => __( 'The ten-second diagnostic deadline was reached. Completed checks are shown below.', 'ran-booster' ),
	'remote_calls_exhausted'   => __( 'The five-request Git provider budget was reached. Completed checks are shown below.', 'ran-booster' ),
	'provider_unavailable'     => __( 'The selected Git provider could not complete diagnostics. Local results are still available.', 'ran-booster' ),
	'provider_results_invalid' => __( 'The Git provider returned an unsafe or invalid diagnostic result, which Booster omitted.', 'ran-booster' ),
	'result_limit_exhausted'   => __( 'The eight-result limit was reached. Earlier results are shown below.', 'ran-booster' ),
);
$selected_provider  = $troubleshooting['selected_provider'] ?? '';
$results            = is_array( $troubleshooting['results'] ?? null ) ? $troubleshooting['results'] : array();
$credentials        = is_array( $troubleshooting['credentials'] ?? null ) ? $troubleshooting['credentials'] : array();
$credential_id      = is_string( $troubleshooting['credential_id'] ?? null ) ? $troubleshooting['credential_id'] : '';
$repository         = is_string( $troubleshooting['repository'] ?? null ) ? $troubleshooting['repository'] : '';
$show_specific_form = '' !== $credential_id || '' !== $repository;
$core_self_update   = is_array( $troubleshooting['core_self_update'] ?? null )
	? $troubleshooting['core_self_update']
	: array();
$locator_hints      = is_array( $troubleshooting['provider_locator_hints'] ?? null ) ? $troubleshooting['provider_locator_hints'] : array();
$locator_examples   = array();
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
<section id="ran-booster-troubleshooting-diagnostics-region" class="ran-booster-page-shell ran-booster-troubleshooting" aria-labelledby="ran-booster-troubleshooting-heading">
	<header class="ran-booster-page-shell__header ran-booster-troubleshooting__header">
		<h2 id="ran-booster-troubleshooting-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Troubleshooting', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Run protected checks, temporarily capture Booster events, or review recent update activity.', 'ran-booster' ); ?></p>
	</header>

	<div class="ran-booster-page-shell__body ran-booster-panel">
		<nav class="ran-booster-secondary-nav nav-tab-wrapper wp-clearfix" aria-label="<?php echo esc_attr( __( 'Troubleshooting views', 'ran-booster' ) ); ?>">
		<?php foreach ( $secondary_navigation as $secondary_item ) { ?>
			<a class="nav-tab<?php echo $troubleshooting_panel === $secondary_item['key'] ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $secondary_item['url'] ); ?>"<?php echo $troubleshooting_panel === $secondary_item['key'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $secondary_item['label'] ); ?></a>
		<?php } ?>
		</nav>

	<?php if ( 'installation' === $troubleshooting_panel ) { ?>
		<?php require __DIR__ . '/troubleshooting-installation.php'; ?>
	<?php } elseif ( 'support-report' === $troubleshooting_panel ) { ?>
		<?php require __DIR__ . '/troubleshooting-support-report.php'; ?>
	<?php } elseif ( 'debug-capture' === $troubleshooting_panel ) { ?>
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
		<h3 class="ran-booster-section__title" id="ran-booster-run-diagnostics-heading"><?php esc_html_e( 'Run diagnostics', 'ran-booster' ); ?></h3>
		<p class="ran-booster-section__description"><?php esc_html_e( 'Choose a Git provider and run checks. Results below describe only this diagnostic run.', 'ran-booster' ); ?></p>

	<form method="post" action="" class="ran-booster-troubleshooting__form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-troubleshooting-diagnostics-error" hx-post="" hx-target="#ran-booster-troubleshooting-diagnostics-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop">
		<?php wp_nonce_field( 'ran-booster-run-troubleshooting' ); ?>
		<input type="hidden" name="ran_booster[action]" value="run-troubleshooting">
		<div id="ran-booster-troubleshooting-diagnostics-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>

		<div class="ran-booster-troubleshooting__fields ran-booster-troubleshooting__provider-field">
			<p>
				<label for="ran-booster-troubleshooting-provider"><?php esc_html_e( 'Git provider', 'ran-booster' ); ?></label>
				<select id="ran-booster-troubleshooting-provider" class="ran-booster-troubleshooting__provider" name="ran_booster[provider]" required>
					<?php foreach ( $troubleshooting['providers'] as $code => $label ) { ?>
						<option value="<?php echo esc_attr( $code ); ?>"<?php selected( $selected_provider, $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php } ?>
				</select>
			</p>
		</div>
		<p class="description"><?php esc_html_e( 'Start with a Git provider to run a general health check. The optional fields below check only credential and repository access; they do not scope Push-to-Deploy delivery results.', 'ran-booster' ); ?></p>

		<details class="ran-booster-troubleshooting__specific ran-booster-troubleshooting__disclosure ran-booster-disclosure"<?php echo $show_specific_form ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Check credential or repository access (optional)', 'ran-booster' ); ?></summary>
			<div class="ran-booster-troubleshooting__disclosure-body ran-booster-troubleshooting__fields ran-booster-disclosure__body">
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
				</p>
				<p>
					<label for="ran-booster-troubleshooting-repository"><?php esc_html_e( 'Repository', 'ran-booster' ); ?> <span class="description"><?php esc_html_e( '(optional)', 'ran-booster' ); ?></span></label>
						<input id="ran-booster-troubleshooting-repository" type="text" name="ran_booster[repository]" value="<?php echo esc_attr( $repository ); ?>" maxlength="512" placeholder="<?php echo esc_attr( $repository_placeholder ); ?>">
					<span class="description"><?php esc_html_e( 'Enter a repository only when diagnosing a particular access issue. Without a saved credential, Booster checks whether it is publicly available.', 'ran-booster' ); ?></span>
				</p>
				<div class="notice notice-info inline ran-booster-troubleshooting__provider-notice"><p><?php esc_html_e( 'The active Git provider can read every credential saved under its Git provider code, not only the selected diagnostic profile. Booster does not authenticate a third-party publisher.', 'ran-booster' ); ?></p></div>
			</div>
		</details>

		<p class="description"><?php esc_html_e( 'The full check makes temporary local marker files and up to five Git provider requests. Markers are removed immediately. Booster does not install packages, trigger webhooks or save a report.', 'ran-booster' ); ?></p>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Run full diagnostics', 'ran-booster' ); ?></button></p>
	</form>

		<?php if ( ! empty( $troubleshooting['ran'] ) ) { ?>
			<?php if ( isset( $troubleshooting['retention_available'] ) && ! $troubleshooting['retention_available'] ) { ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'These results remain available below, but could not be retained for a later support report.', 'ran-booster' ); ?></p></div>
		<?php } ?>
		<div id="ran-booster-troubleshooting-results" class="ran-booster-troubleshooting__results" aria-live="polite">
			<h4 class="ran-booster-section__title"><?php esc_html_e( 'Diagnostic results', 'ran-booster' ); ?></h4>
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
							<th scope="row" data-label="<?php esc_attr_e( 'Check', 'ran-booster' ); ?>">
							<?php
								$check_family = explode( '.', $result['code'] )[1] ?? '';
								$check_labels = array(
									'runtime'             => __( 'WordPress runtime', 'ran-booster' ),
									'database'            => __( 'Database storage', 'ran-booster' ),
									'filesystem'          => __( 'Filesystem access', 'ran-booster' ),
									'destinations'        => __( 'Package destinations', 'ran-booster' ),
									'deployment_attempts' => __( 'Deployment history', 'ran-booster' ),
									'deployment_worker'   => __( 'Deployment worker', 'ran-booster' ),
									'credential'          => __( 'Credential access', 'ran-booster' ),
									'repository'          => __( 'Repository access', 'ran-booster' ),
								);
								echo esc_html( $check_labels[ $check_family ] ?? __( 'Git provider check', 'ran-booster' ) );
								?>
								<br><code class="description"><?php echo esc_html( $result['code'] ); ?></code></th>
							<td data-label="<?php esc_attr_e( 'Details', 'ran-booster' ); ?>"><?php echo esc_html( $result['message'] ); ?></td>
							<td data-label="<?php esc_attr_e( 'Action', 'ran-booster' ); ?>"><?php echo esc_html( $result['remediation'] ); ?></td>
						</tr>
					<?php } ?>
					</tbody>
					<?php } ?>
				</table>
			</div>


		</div>
	<?php } ?>

	<?php } ?>
	</div>
</section>
