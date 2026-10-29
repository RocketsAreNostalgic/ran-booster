<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * View locals supplied by views/troubleshooting.php.
 *
 * @var array<string, mixed> $deployment_activity
 * @var string $troubleshooting_base
 */

use RAN\Admin\DeploymentOutcomeMessage;

if ( ! defined( 'WPINC' ) ) {
	die;
}

$items               = is_array( $deployment_activity['items'] ?? null ) ? $deployment_activity['items'] : array();
$filters             = is_array( $deployment_activity['filters'] ?? null ) ? $deployment_activity['filters'] : array();
$filter_options      = is_array( $deployment_activity['filter_options'] ?? null ) ? $deployment_activity['filter_options'] : array();
$filter_state        = is_array( $deployment_activity['filter_state'] ?? null ) ? $deployment_activity['filter_state'] : array();
$unavailable         = true === ( $deployment_activity['unavailable'] ?? false );
$has_cursor          = true === ( $deployment_activity['has_cursor'] ?? false );
$activity_filter_key = is_string( $deployment_activity['activity_filter_key'] ?? null ) ? $deployment_activity['activity_filter_key'] : '';
$base_url            = $troubleshooting_base . '&panel=activity';
$selected_provider   = is_string( $filter_state['provider'] ?? null ) ? $filter_state['provider'] : '';
$selected_package    = is_string( $filter_state['package'] ?? null ) ? $filter_state['package'] : '';
if ( '' !== $selected_provider ) {
	$base_url .= '&activity_provider=' . rawurlencode( $selected_provider );
}
if ( '' !== $selected_package ) {
	$base_url .= '&activity_package=' . rawurlencode( $selected_package );
}
$settings_urls = is_array( $deployment_activity['package_settings_urls'] ?? null )
	? $deployment_activity['package_settings_urls']
	: array();
$next          = is_string( $deployment_activity['next_cursor'] ?? null ) ? $deployment_activity['next_cursor'] : null;
$has_queued    = count( array_filter( $items, static fn ( array $item ): bool => 'queued' === ( $item['state'] ?? null ) ) ) > 0;
$state_tones   = array(
	'queued'          => 'pending',
	'running'         => 'pending',
	'succeeded'       => 'ok',
	'updated'         => 'ok',
	'failed'          => 'error',
	'needs_attention' => 'error',
);
$state_labels  = array(
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
$origin_labels = array(
	'manual'  => __( 'Manual administrator action', 'ran-booster' ),
	'webhook' => __( 'Repository webhook', 'ran-booster' ),
);
?>
<section class="ran-booster-activity" aria-labelledby="ran-booster-activity-heading" data-ran-booster-activity data-ran-booster-activity-filter="<?php echo esc_attr( $activity_filter_key ); ?>" data-ran-booster-activity-provider="<?php echo esc_attr( $selected_provider ); ?>" data-ran-booster-activity-package="<?php echo esc_attr( $selected_package ); ?>" data-ran-booster-activity-has-cursor="<?php echo $has_cursor ? '1' : '0'; ?>" data-ran-booster-activity-unavailable="<?php echo $unavailable ? '1' : '0'; ?>">
	<header class="ran-booster-activity__header">
		<h3 id="ran-booster-activity-heading"><?php esc_html_e( 'Activity', 'ran-booster' ); ?></h3>
		<p><?php esc_html_e( 'Review recorded checks and deployments. Activity is recorded independently of diagnostic logging.', 'ran-booster' ); ?></p>
	</header>
	<div class="ran-booster-package-list-controls">
		<form class="ran-booster-package-list-filters" method="get">
			<input type="hidden" name="page" value="ran-booster">
			<input type="hidden" name="tab" value="troubleshooting">
			<input type="hidden" name="panel" value="activity">
			<label class="screen-reader-text" for="ran-booster-activity-provider"><?php esc_html_e( 'Provider', 'ran-booster' ); ?></label>
			<select id="ran-booster-activity-provider" name="activity_provider">
				<option value=""><?php esc_html_e( 'All providers', 'ran-booster' ); ?></option>
				<?php foreach ( $filter_options['providers'] ?? array() as $option ) { ?>
					<?php if ( is_array( $option ) && is_string( $option['value'] ?? null ) && is_string( $option['label'] ?? null ) ) { ?>
						<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( $option['value'], $selected_provider ); ?>><?php echo esc_html( $option['label'] ); ?></option>
					<?php } ?>
				<?php } ?>
			</select>
			<label class="screen-reader-text" for="ran-booster-activity-package"><?php esc_html_e( 'Activity subject', 'ran-booster' ); ?></label>
			<select id="ran-booster-activity-package" name="activity_package">
				<option value=""><?php esc_html_e( 'All subjects', 'ran-booster' ); ?></option>
				<?php foreach ( $filter_options['packages'] ?? array() as $option ) { ?>
					<?php if ( is_array( $option ) && is_string( $option['value'] ?? null ) && is_string( $option['label'] ?? null ) ) { ?>
						<option value="<?php echo esc_attr( $option['value'] ); ?>" <?php selected( $option['value'], $selected_package ); ?>><?php echo esc_html( $option['label'] ); ?></option>
					<?php } ?>
				<?php } ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ran-booster' ); ?></button>
			<?php if ( '' !== $selected_provider || '' !== $selected_package ) { ?>
				<a class="ran-booster-package-list-filters__clear" href="<?php echo esc_url( $troubleshooting_base . '&panel=activity' ); ?>"><?php esc_html_e( 'Clear filters', 'ran-booster' ); ?></a>
			<?php } ?>
		</form>
	</div>
	<?php
	if ( is_string( $deployment_activity['filter_notice'] ?? null ) ) {
		?>
		<div class="notice notice-warning inline"><p><?php echo esc_html( $deployment_activity['filter_notice'] ); ?></p></div><?php } ?>
	<?php if ( $has_queued ) { ?>
		<form method="post" data-ran-booster-activity-runner>
			<?php wp_nonce_field( 'ran-booster-request-deployment-runner' ); ?>
			<input type="hidden" name="ran_booster[action]" value="request-deployment-runner">
			<?php submit_button( __( 'Request deployment runner', 'ran-booster' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php } ?>
	<?php if ( $unavailable ) { ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Activity is temporarily unavailable.', 'ran-booster' ); ?></p></div>
	<?php } elseif ( array() === $items && $has_cursor ) { ?>
		<p data-ran-booster-activity-exhausted><?php esc_html_e( 'No older activity is available.', 'ran-booster' ); ?></p>
	<?php } elseif ( array() === $items && ( '' !== $selected_provider || '' !== $selected_package ) ) { ?>
		<p><?php esc_html_e( 'No activity matches these filters.', 'ran-booster' ); ?></p>
	<?php } elseif ( array() === $items ) { ?>
		<p><?php esc_html_e( 'No activity has been recorded yet.', 'ran-booster' ); ?></p>
	<?php } else { ?>
		<div class="ran-booster-data-table-wrap ran-booster-attempt-table" role="table">
			<div class="ran-booster-attempt-table__head" role="row">
				<span role="columnheader"><?php esc_html_e( 'Time', 'ran-booster' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Subject', 'ran-booster' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Source', 'ran-booster' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Activity', 'ran-booster' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Outcome', 'ran-booster' ); ?></span>
			</div>
			<ul class="ran-booster-attempt-list" data-ran-booster-activity-list>
			<?php
			foreach ( $items as $item ) {
				$recorded_check   = 'activity' === ( $item['record_source'] ?? '' );
				$state            = (string) ( $item['state'] ?? '' );
				$summary          = $recorded_check
					? DeploymentOutcomeMessage::for_activity( $state, (string) ( $item['reason'] ?? '' ), (string) ( $item['message'] ?? '' ) )
					: DeploymentOutcomeMessage::for_code( (string) ( $item['outcome_code'] ?? 'pending' ) );
				$project_label    = (string) ( $item['package_slug'] ?? '' );
				$package_type     = (string) ( $item['package_type'] ?? '' );
				$provider_label   = (string) ( $item['provider_label'] ?? $item['provider'] ?? '' );
				$subject_identity = (string) ( $item['subject_identity'] ?? $project_label );
				$subject_label    = match ( $package_type ) {
					'plugin' => __( 'Plugin', 'ran-booster' ) . ': ' . $subject_identity,
					'theme' => __( 'Theme', 'ran-booster' ) . ': ' . $subject_identity,
					'provider' => $provider_label,
					default => $provider_label . ' · ' . __( 'Repository', 'ran-booster' ) . ': ' . $subject_identity,
				};
				$package_settings_url   = is_string( $settings_urls[ $package_type ][ $project_label ] ?? null )
					? $settings_urls[ $package_type ][ $project_label ]
					: '';
				$package_settings_label = 'theme' === $package_type
					? __( 'Open theme settings', 'ran-booster' )
					: __( 'Open plugin settings', 'ran-booster' );
				$activity_label         = match ( $item['operation'] ?? '' ) {
					'branch.check' => __( 'Branch check', 'ran-booster' ),
					'release.check' => __( 'Release check', 'ran-booster' ),
					'workflow.assessment' => __( 'Workflow assessment', 'ran-booster' ),
					default => ucfirst( str_replace( array( '.', '_' ), ' ', (string) ( $item['operation'] ?? '' ) ) ),
				};
				?>
				<li class="ran-booster-attempt-row" data-ran-booster-activity-row="<?php echo esc_attr( (string) ( $item['activity_record_id'] ?? '' ) ); ?>">
					<div class="ran-booster-attempt-row__summary" role="row">
						<span class="ran-booster-attempt-row__time" role="cell" data-label="<?php esc_attr_e( 'Time', 'ran-booster' ); ?>"><?php echo esc_html( (string) ( $item['created_at'] ?? '' ) ); ?></span>
						<span class="ran-booster-attempt-row__package" role="cell" data-label="<?php esc_attr_e( 'Subject', 'ran-booster' ); ?>">
						<?php
						if ( '' !== $package_settings_url ) {
							?>
							<a href="<?php echo esc_url( $package_settings_url ); ?>"><?php echo esc_html( $subject_label ); ?></a>
							<?php
						} else {
							?>
							<?php echo esc_html( $subject_label ); ?><?php } ?></span>
						<span class="ran-booster-attempt-row__source" role="cell" data-label="<?php esc_attr_e( 'Source', 'ran-booster' ); ?>"><?php echo esc_html( $recorded_check ? $provider_label : __( 'Branch deployment', 'ran-booster' ) ); ?></span>
						<span role="cell" data-label="<?php esc_attr_e( 'Activity', 'ran-booster' ); ?>"><?php echo esc_html( $activity_label ); ?></span>
						<span role="cell" data-label="<?php esc_attr_e( 'Outcome', 'ran-booster' ); ?>"><span class="ran-booster-attempt-row__outcome"><span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $state_tones[ $state ] ?? 'neutral' ); ?> ran-booster-deployment-state ran-booster-deployment-state--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $state_labels[ $state ] ?? ( $recorded_check ? __( 'Unknown', 'ran-booster' ) : $state ) ); ?></span><?php if ( $recorded_check ) { ?>
							<span class="ran-booster-attempt-row__message"><?php echo esc_html( $summary ); ?></span>
						<?php } ?></span></span>
					</div>
					<details class="ran-booster-attempt-row__details ran-booster-disclosure">
						<summary><?php esc_html_e( 'View details', 'ran-booster' ); ?></summary>
						<div class="ran-booster-disclosure__body">
						<?php if ( $recorded_check ) { ?>
							<dl class="ran-booster-activity__details">
								<div><dt><?php esc_html_e( 'Reported by', 'ran-booster' ); ?></dt><dd><?php echo esc_html( 'core' === ( $item['provenance'] ?? '' ) ? __( 'Recorded by Core', 'ran-booster' ) : __( 'Provider-reported', 'ran-booster' ) ); ?></dd></div>
								<?php
								foreach ( array(
									'stage'        => __( 'Last recorded stage', 'ran-booster' ),
									'reason'       => __( 'Reason', 'ran-booster' ),
									'remediation'  => __( 'Remediation', 'ran-booster' ),
									'correlation'  => __( 'Support reference', 'ran-booster' ),
									'completed_at' => __( 'Completed (UTC)', 'ran-booster' ),
								) as $key => $label ) {
									?>
									<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( (string) ( $item[ $key ] ?? '' ) ); ?></dd></div>
								<?php } ?>
								<?php foreach ( DeploymentOutcomeMessage::activity_facts( $item['facts'] ?? null ) as $fact_label => $fact_value ) { ?>
									<div><dt><?php echo esc_html( $fact_label ); ?></dt><dd><?php echo esc_html( $fact_value ); ?></dd></div>
								<?php } ?>
							</dl>
						<?php } else { ?>
						<dl class="ran-booster-activity__details">
							<div><dt><?php echo esc_html( in_array( $state, array( 'failed', 'needs_attention' ), true ) ? __( 'Failure reason', 'ran-booster' ) : __( 'Outcome', 'ran-booster' ) ); ?></dt><dd><?php echo esc_html( $summary ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Support reference', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) ( $item['correlation_id'] ?? '' ) ); ?></code></dd></div>
							<div><dt><?php esc_html_e( 'Package', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $project_label ); ?> (<?php echo esc_html( (string) ( $item['package_type'] ?? '' ) ); ?>)</dd></div>
							<div><dt><?php esc_html_e( 'Origin', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $origin_labels[ $item['source'] ?? '' ] ?? (string) ( $item['source'] ?? '' ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Requested reference', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) ( $item['requested_ref'] ?? '' ) ); ?></code></dd></div>
							<div><dt><?php esc_html_e( 'Resolved reference', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) ( $item['resolved_ref'] ?? __( 'Not resolved', 'ran-booster' ) ) ); ?></code></dd></div>
							<div><dt><?php esc_html_e( 'Mutation began', 'ran-booster' ); ?></dt><dd><?php echo esc_html( (string) ( $item['mutation_started_at'] ?? __( 'No', 'ran-booster' ) ) ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Finished', 'ran-booster' ); ?></dt><dd><?php echo esc_html( (string) ( $item['finished_at'] ?? __( 'Not finished', 'ran-booster' ) ) ); ?></dd></div>
							<?php if ( null !== ( $item['resolved_at'] ?? null ) && null !== ( $item['resolved_by'] ?? null ) ) { ?>
								<div><dt><?php esc_html_e( 'Operator review', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: review date and time, 2: WordPress user ID. */ __( 'Resolved %1$s by user #%2$d', 'ran-booster' ), $item['resolved_at'], $item['resolved_by'] ) ); ?></dd></div>
							<?php } ?>
							<?php if ( '' !== $package_settings_url ) { ?>
								<div><dt><?php esc_html_e( 'Package settings', 'ran-booster' ); ?></dt><dd><a href="<?php echo esc_url( $package_settings_url ); ?>"><?php echo esc_html( $package_settings_label ); ?></a></dd></div>
							<?php } ?>
						</dl>
							<?php if ( 'running' === $state ) { ?>
							<form method="post" action="">
								<?php wp_nonce_field( 'ran-booster-reconcile-deployment-worker' ); ?>
								<input type="hidden" name="ran_booster[action]" value="reconcile-deployment-worker">
								<input type="hidden" name="ran_booster[attempt_id]" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
								<input type="hidden" name="ran_booster[correlation_id]" value="<?php echo esc_attr( (string) ( $item['correlation_id'] ?? '' ) ); ?>">
								<p><label><input type="checkbox" name="ran_booster[confirm_stopped]" value="1" required> <?php esc_html_e( 'I have confirmed that the deployment worker has stopped.', 'ran-booster' ); ?></label></p>
								<button type="submit" class="button"><?php esc_html_e( 'Reconcile stopped worker', 'ran-booster' ); ?></button>
							</form>
						<?php } ?>
						<?php } ?>
						</div>
					</details>
				</li>
			<?php } ?>
			</ul>
		</div>
	<?php } ?>
	<?php if ( ! $unavailable && $has_cursor ) { ?>
		<p><a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'View latest activity', 'ran-booster' ); ?></a></p>
	<?php } ?>
	<?php if ( null !== $next ) { ?>
		<p data-ran-booster-activity-load-older-wrap><a class="button" data-ran-booster-activity-load-older href="<?php echo esc_url( $base_url . '&before=' . rawurlencode( $next ) ); ?>"><?php esc_html_e( 'View older activity', 'ran-booster' ); ?></a></p>
		<p data-ran-booster-activity-progress role="status" aria-live="polite" hidden><?php esc_html_e( 'Loading older activity…', 'ran-booster' ); ?></p>
		<p data-ran-booster-activity-error role="alert" tabindex="-1" hidden><?php esc_html_e( 'Older activity could not be loaded. Try again.', 'ran-booster' ); ?></p>
		<p data-ran-booster-activity-announcement role="status" aria-live="polite" tabindex="-1" hidden></p>
	<?php } ?>
</section>
