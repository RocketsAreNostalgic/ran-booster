<?php

use RAN\Admin\DeploymentOutcomeMessage;
use RAN\Deployment\DeploymentAttempt;

if ( ! defined( 'WPINC' ) ) {
	die;
}

$items                   = array_map(
	static fn ( DeploymentAttempt $attempt ): array => $attempt->safeData(),
	array_filter(
		$deploymentActivity['items'] ?? array(),
		static fn ( mixed $item ): bool => $item instanceof DeploymentAttempt
	)
);
$unavailable             = true === ( $deploymentActivity['unavailable'] ?? false );
$rejectedAdmissionEvents = is_array( $deploymentActivity['rejected_admission_events'] ?? null )
	? $deploymentActivity['rejected_admission_events']
	: array();
$activityRows            = array();
$activityRows            = array_merge(
	array_map(
		static fn ( array $item ): array => array(
			'kind'        => 'deployment',
			'occurred_at' => (string) ( $item['created_at'] ?? '' ),
			'item'        => $item,
		),
		$items
	),
	array_values(
		array_filter(
			array_map(
				static fn ( mixed $event ): ?array => is_array( $event )
					? array(
						'kind'        => 'rejected_admission',
						'occurred_at' => (string) ( $event['occurred_at'] ?? '' ),
						'event'       => $event,
					)
					: null,
				$rejectedAdmissionEvents
			),
			static fn ( ?array $row ): bool => null !== $row
		)
	)
);
usort(
	$activityRows,
	static fn ( array $left, array $right ): int => strcmp( (string) $right['occurred_at'], (string) $left['occurred_at'] )
);
$hasCursor    = true === ( $deploymentActivity['has_cursor'] ?? false );
$baseUrl      = $troubleshootingBase . '&panel=activity';
$settingsUrls = is_array( $deploymentActivity['package_settings_urls'] ?? null )
	? $deploymentActivity['package_settings_urls']
	: array();
$next         = is_string( $deploymentActivity['next_cursor'] ?? null ) ? $deploymentActivity['next_cursor'] : null;
$hasQueued    = count( array_filter( $items, static fn ( array $item ): bool => 'queued' === ( $item['state'] ?? null ) ) ) > 0;
$stateTones   = array(
	'queued'          => 'pending',
	'running'         => 'pending',
	'succeeded'       => 'ok',
	'updated'         => 'ok',
	'failed'          => 'error',
	'needs_attention' => 'error',
);
$stateLabels  = array(
	'queued'          => __( 'Queued', 'ran-booster' ),
	'running'         => __( 'Running', 'ran-booster' ),
	'succeeded'       => __( 'Succeeded', 'ran-booster' ),
	'updated'         => __( 'Updated', 'ran-booster' ),
	'failed'          => __( 'Failed', 'ran-booster' ),
	'needs_attention' => __( 'Needs attention', 'ran-booster' ),
);
$originLabels = array(
	'manual'  => __( 'Manual administrator action', 'ran-booster' ),
	'webhook' => __( 'Repository webhook', 'ran-booster' ),
);
?>
<section class="ran-booster-activity" aria-labelledby="ran-booster-activity-heading">
	<header class="ran-booster-activity__header">
		<h3 id="ran-booster-activity-heading"><?php esc_html_e( 'Activity', 'ran-booster' ); ?></h3>
		<p><?php esc_html_e( 'Review recent branch deployments.', 'ran-booster' ); ?></p>
	</header>
	<?php if ( $hasQueued ) { ?>
		<form method="post">
			<?php wp_nonce_field( 'ran-booster-request-deployment-runner' ); ?>
			<input type="hidden" name="ran_booster[action]" value="request-deployment-runner">
			<?php submit_button( __( 'Request deployment runner', 'ran-booster' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php } ?>
	<?php if ( $unavailable ) { ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Activity is temporarily unavailable.', 'ran-booster' ); ?></p></div>
	<?php } elseif ( array() === $activityRows && $hasCursor ) { ?>
		<p><?php esc_html_e( 'No older activity is available.', 'ran-booster' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $baseUrl ); ?>"><?php esc_html_e( 'View latest activity', 'ran-booster' ); ?></a></p>
	<?php } elseif ( array() === $activityRows ) { ?>
		<p><?php esc_html_e( 'No activity has been recorded yet.', 'ran-booster' ); ?></p>
	<?php } else { ?>
		<div class="ran-booster-data-table-wrap ran-booster-attempt-table" role="table">
			<div class="ran-booster-attempt-table__head" role="row">
				<span role="columnheader"><?php esc_html_e( 'Time', 'ran-booster' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Project', 'ran-booster' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Source', 'ran-booster' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Activity', 'ran-booster' ); ?></span>
				<span role="columnheader"><?php esc_html_e( 'Outcome', 'ran-booster' ); ?></span>
			</div>
			<ul class="ran-booster-attempt-list">
			<?php
			foreach ( $activityRows as $row ) {
				$isRejectedAdmission  = 'rejected_admission' === $row['kind'];
				$item                 = $isRejectedAdmission ? array() : $row['item'];
				$event                = $isRejectedAdmission ? $row['event'] : array();
				$state                = $isRejectedAdmission ? 'failed' : (string) ( $item['state'] ?? '' );
				$summary              = $isRejectedAdmission
					? __( 'This reinstall request was blocked because the linked prior deployment still needs review.', 'ran-booster' )
					: DeploymentOutcomeMessage::forCode( (string) ( $item['outcome_code'] ?? 'pending' ) );
				$projectLabel         = (string) ( $isRejectedAdmission ? ( $event['package_slug'] ?? '' ) : ( $item['package_slug'] ?? '' ) );
				$packageType          = (string) ( $isRejectedAdmission ? ( $event['package_type'] ?? '' ) : ( $item['package_type'] ?? '' ) );
				$packageSettingsUrl   = is_string( $settingsUrls[ $packageType ][ $projectLabel ] ?? null )
					? $settingsUrls[ $packageType ][ $projectLabel ]
					: '';
				$packageSettingsLabel = 'theme' === $packageType
					? __( 'Open theme settings', 'ran-booster' )
					: __( 'Open plugin settings', 'ran-booster' );
				$activityLabel        = $isRejectedAdmission ? __( 'Reinstall', 'ran-booster' ) : ucfirst( (string) ( $item['operation'] ?? '' ) );
				$detailUrl            = $isRejectedAdmission
					? $baseUrl . '&attempt=' . rawurlencode( (string) ( $event['attempt_id'] ?? '' ) ) . '&reference=' . rawurlencode( (string) ( $event['correlation_id'] ?? '' ) )
					: '';
				?>
				<li class="ran-booster-attempt-row<?php echo $isRejectedAdmission ? ' ran-booster-attempt-row--rejected-admission' : ''; ?>">
					<div class="ran-booster-attempt-row__summary" role="row">
						<span class="ran-booster-attempt-row__time" role="cell" data-label="<?php esc_attr_e( 'Time', 'ran-booster' ); ?>"><?php echo esc_html( (string) $row['occurred_at'] ); ?></span>
						<span class="ran-booster-attempt-row__package" role="cell" data-label="<?php esc_attr_e( 'Project', 'ran-booster' ); ?>">
						<?php
						if ( '' !== $packageSettingsUrl ) {
							?>
							<a href="<?php echo esc_url( $packageSettingsUrl ); ?>"><?php echo esc_html( $projectLabel ); ?></a>
							<?php
						} else {
							?>
							<?php echo esc_html( $projectLabel ); ?><?php } ?></span>
						<span role="cell" data-label="<?php esc_attr_e( 'Source', 'ran-booster' ); ?>"><span class="ran-booster-badge ran-booster-badge--neutral"><?php esc_html_e( 'Branch deployment', 'ran-booster' ); ?></span></span>
						<span role="cell" data-label="<?php esc_attr_e( 'Activity', 'ran-booster' ); ?>"><?php echo esc_html( $activityLabel ); ?></span>
						<span role="cell" data-label="<?php esc_attr_e( 'Outcome', 'ran-booster' ); ?>"><span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $stateTones[ $state ] ?? 'neutral' ); ?> ran-booster-deployment-state ran-booster-deployment-state--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $stateLabels[ $state ] ?? $state ); ?></span></span>
					</div>
					<details class="ran-booster-attempt-row__details">
						<summary><?php esc_html_e( 'View details', 'ran-booster' ); ?></summary>
						<dl class="ran-booster-activity__details">
							<?php if ( $isRejectedAdmission ) { ?>
								<div><dt><?php esc_html_e( 'Failure reason', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $summary ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Package', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $projectLabel ); ?> (<?php echo esc_html( (string) ( $event['package_type'] ?? '' ) ); ?>)</dd></div>
								<div><dt><?php esc_html_e( 'Requested by', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: WordPress user ID. */ __( 'User #%d', 'ran-booster' ), (int) ( $event['actor_id'] ?? 0 ) ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Prior deployment', 'ran-booster' ); ?></dt><dd><a href="<?php echo esc_url( $detailUrl ); ?>"><?php esc_html_e( 'Review activity record', 'ran-booster' ); ?></a></dd></div>
							<?php } else { ?>
								<div><dt><?php echo esc_html( in_array( $state, array( 'failed', 'needs_attention' ), true ) ? __( 'Failure reason', 'ran-booster' ) : __( 'Outcome', 'ran-booster' ) ); ?></dt><dd><?php echo esc_html( $summary ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Support reference', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) ( $item['correlation_id'] ?? '' ) ); ?></code></dd></div>
								<div><dt><?php esc_html_e( 'Package', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $projectLabel ); ?> (<?php echo esc_html( (string) ( $item['package_type'] ?? '' ) ); ?>)</dd></div>
								<div><dt><?php esc_html_e( 'Origin', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $originLabels[ $item['source'] ?? '' ] ?? (string) ( $item['source'] ?? '' ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Requested reference', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) ( $item['requested_ref'] ?? '' ) ); ?></code></dd></div>
								<div><dt><?php esc_html_e( 'Resolved reference', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) ( $item['resolved_ref'] ?? __( 'Not resolved', 'ran-booster' ) ) ); ?></code></dd></div>
								<div><dt><?php esc_html_e( 'Mutation began', 'ran-booster' ); ?></dt><dd><?php echo esc_html( (string) ( $item['mutation_started_at'] ?? __( 'No', 'ran-booster' ) ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Finished', 'ran-booster' ); ?></dt><dd><?php echo esc_html( (string) ( $item['finished_at'] ?? __( 'Not finished', 'ran-booster' ) ) ); ?></dd></div>
								<?php if ( null !== ( $item['resolved_at'] ?? null ) && null !== ( $item['resolved_by'] ?? null ) ) { ?>
									<div><dt><?php esc_html_e( 'Operator review', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: review date and time, 2: WordPress user ID. */ __( 'Resolved %1$s by user #%2$d', 'ran-booster' ), $item['resolved_at'], $item['resolved_by'] ) ); ?></dd></div>
								<?php } ?>
							<?php } ?>
							<?php if ( '' !== $packageSettingsUrl ) { ?>
								<div><dt><?php esc_html_e( 'Package settings', 'ran-booster' ); ?></dt><dd><a href="<?php echo esc_url( $packageSettingsUrl ); ?>"><?php echo esc_html( $packageSettingsLabel ); ?></a></dd></div>
							<?php } ?>
						</dl>
						<?php if ( ! $isRejectedAdmission && 'running' === $state ) { ?>
							<form method="post" action="">
								<?php wp_nonce_field( 'ran-booster-reconcile-deployment-worker' ); ?>
								<input type="hidden" name="ran_booster[action]" value="reconcile-deployment-worker">
								<input type="hidden" name="ran_booster[attempt_id]" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
								<input type="hidden" name="ran_booster[correlation_id]" value="<?php echo esc_attr( (string) ( $item['correlation_id'] ?? '' ) ); ?>">
								<p><label><input type="checkbox" name="ran_booster[confirm_stopped]" value="1" required> <?php esc_html_e( 'I have confirmed that the deployment worker has stopped.', 'ran-booster' ); ?></label></p>
								<button type="submit" class="button"><?php esc_html_e( 'Reconcile stopped worker', 'ran-booster' ); ?></button>
							</form>
						<?php } ?>
					</details>
				</li>
			<?php } ?>
			</ul>
		</div>
	<?php } ?>
	<?php
	if ( null !== $next ) {
		?>
		<p><a class="button" href="<?php echo esc_url( $baseUrl . '&before=' . rawurlencode( $next ) ); ?>"><?php esc_html_e( 'Older activity', 'ran-booster' ); ?></a></p><?php } ?>
</section>
