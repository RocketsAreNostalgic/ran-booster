<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * View locals supplied by views/troubleshooting.php.
 *
 * @var array<string, mixed> $deployment_activity
 * @var string $troubleshooting_base
 */

use RAN\Admin\DeploymentOutcomeMessage;
use RAN\Deployment\DeploymentAttempt;

if ( ! defined( 'WPINC' ) ) {
	die;
}

$items         = array_map(
	static fn ( DeploymentAttempt $attempt ): array => $attempt->safe_data(),
	array_filter(
		$deployment_activity['items'] ?? array(),
		static fn ( mixed $item ): bool => $item instanceof DeploymentAttempt
	)
);
$unavailable   = true === ( $deployment_activity['unavailable'] ?? false );
$has_cursor    = true === ( $deployment_activity['has_cursor'] ?? false );
$base_url      = $troubleshooting_base . '&panel=activity';
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
	'succeeded'       => __( 'Succeeded', 'ran-booster' ),
	'updated'         => __( 'Updated', 'ran-booster' ),
	'failed'          => __( 'Failed', 'ran-booster' ),
	'needs_attention' => __( 'Needs attention', 'ran-booster' ),
);
$origin_labels = array(
	'manual'  => __( 'Manual administrator action', 'ran-booster' ),
	'webhook' => __( 'Repository webhook', 'ran-booster' ),
);
?>
<section class="ran-booster-activity" aria-labelledby="ran-booster-activity-heading">
	<header class="ran-booster-activity__header">
		<h3 id="ran-booster-activity-heading"><?php esc_html_e( 'Activity', 'ran-booster' ); ?></h3>
		<p><?php esc_html_e( 'Review recent branch deployments.', 'ran-booster' ); ?></p>
	</header>
	<?php if ( $has_queued ) { ?>
		<form method="post">
			<?php wp_nonce_field( 'ran-booster-request-deployment-runner' ); ?>
			<input type="hidden" name="ran_booster[action]" value="request-deployment-runner">
			<?php submit_button( __( 'Request deployment runner', 'ran-booster' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php } ?>
	<?php if ( $unavailable ) { ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Activity is temporarily unavailable.', 'ran-booster' ); ?></p></div>
	<?php } elseif ( array() === $items && $has_cursor ) { ?>
		<p><?php esc_html_e( 'No older activity is available.', 'ran-booster' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'View latest activity', 'ran-booster' ); ?></a></p>
	<?php } elseif ( array() === $items ) { ?>
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
			foreach ( $items as $item ) {
				$state                  = (string) ( $item['state'] ?? '' );
				$summary                = DeploymentOutcomeMessage::for_code( (string) ( $item['outcome_code'] ?? 'pending' ) );
				$project_label          = (string) ( $item['package_slug'] ?? '' );
				$package_type           = (string) ( $item['package_type'] ?? '' );
				$package_settings_url   = is_string( $settings_urls[ $package_type ][ $project_label ] ?? null )
					? $settings_urls[ $package_type ][ $project_label ]
					: '';
				$package_settings_label = 'theme' === $package_type
					? __( 'Open theme settings', 'ran-booster' )
					: __( 'Open plugin settings', 'ran-booster' );
				$activity_label         = ucfirst( (string) ( $item['operation'] ?? '' ) );
				?>
				<li class="ran-booster-attempt-row">
					<div class="ran-booster-attempt-row__summary" role="row">
						<span class="ran-booster-attempt-row__time" role="cell" data-label="<?php esc_attr_e( 'Time', 'ran-booster' ); ?>"><?php echo esc_html( (string) ( $item['created_at'] ?? '' ) ); ?></span>
						<span class="ran-booster-attempt-row__package" role="cell" data-label="<?php esc_attr_e( 'Project', 'ran-booster' ); ?>">
						<?php
						if ( '' !== $package_settings_url ) {
							?>
							<a href="<?php echo esc_url( $package_settings_url ); ?>"><?php echo esc_html( $project_label ); ?></a>
							<?php
						} else {
							?>
							<?php echo esc_html( $project_label ); ?><?php } ?></span>
						<span role="cell" data-label="<?php esc_attr_e( 'Source', 'ran-booster' ); ?>"><span class="ran-booster-badge ran-booster-badge--neutral"><?php esc_html_e( 'Branch deployment', 'ran-booster' ); ?></span></span>
						<span role="cell" data-label="<?php esc_attr_e( 'Activity', 'ran-booster' ); ?>"><?php echo esc_html( $activity_label ); ?></span>
						<span role="cell" data-label="<?php esc_attr_e( 'Outcome', 'ran-booster' ); ?>"><span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $state_tones[ $state ] ?? 'neutral' ); ?> ran-booster-deployment-state ran-booster-deployment-state--<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $state_labels[ $state ] ?? $state ); ?></span></span>
					</div>
					<details class="ran-booster-attempt-row__details">
						<summary><?php esc_html_e( 'View details', 'ran-booster' ); ?></summary>
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
					</details>
				</li>
			<?php } ?>
			</ul>
		</div>
	<?php } ?>
	<?php
	if ( null !== $next ) {
		?>
		<p><a class="button" href="<?php echo esc_url( $base_url . '&before=' . rawurlencode( $next ) ); ?>"><?php esc_html_e( 'Older activity', 'ran-booster' ); ?></a></p><?php } ?>
</section>
