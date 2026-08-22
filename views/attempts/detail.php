<?php

use RAN\Admin\DeploymentOutcomeMessage;
use RAN\Deployment\DeploymentAttempt;

if ( ! defined( 'WPINC' ) ) {
	die;
}

$attempt              = $deploymentActivity['detail'] ?? null;
$unavailable          = true === ( $deploymentActivity['unavailable'] ?? false );
$laterVerifiedAttempt = $deploymentActivity['later_verified_attempt'] ?? null;
$backUrl              = $troubleshootingBase . '&panel=activity';
$item                 = $attempt instanceof DeploymentAttempt ? $attempt->safeData() : array();
$packageType          = is_string( $item['package_type'] ?? null ) ? $item['package_type'] : '';
$packageSlug          = is_string( $item['package_slug'] ?? null ) ? $item['package_slug'] : '';
$settingsUrls         = is_array( $deploymentActivity['package_settings_urls'] ?? null )
	? $deploymentActivity['package_settings_urls']
	: array();
$packageSettingsUrl   = is_string( $settingsUrls[ $packageType ][ $packageSlug ] ?? null )
	? $settingsUrls[ $packageType ][ $packageSlug ]
	: '';
$packageSettingsLabel = 'theme' === $packageType
	? __( 'Open theme settings', 'ran-booster' )
	: __( 'Open plugin settings', 'ran-booster' );
?>
<section class="ran-booster-activity ran-booster-activity--detail">
	<p>
		<a href="<?php echo esc_url( $backUrl ); ?>">&larr; <?php esc_html_e( 'Back to Activity', 'ran-booster' ); ?></a>
		<?php if ( '' !== $packageSettingsUrl ) { ?>
			<span aria-hidden="true"> | </span>
			<a href="<?php echo esc_url( $packageSettingsUrl ); ?>"><?php echo esc_html( $packageSettingsLabel ); ?></a>
		<?php } ?>
	</p>
	<h3><?php esc_html_e( 'Deployment activity details', 'ran-booster' ); ?></h3>
	<?php if ( $unavailable ) { ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Deployment activity is temporarily unavailable.', 'ran-booster' ); ?></p></div>
	<?php } elseif ( ! $attempt instanceof DeploymentAttempt ) { ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'That deployment record was not found.', 'ran-booster' ); ?></p></div>
		<?php
	} else {
		$originLabels = array(
			'manual'  => __( 'Manual administrator action', 'ran-booster' ),
			'webhook' => __( 'Repository webhook', 'ran-booster' ),
		);
		$outcomeLabel = in_array( $item['state'], array( 'failed', 'needs_attention' ), true )
			? __( 'Failure reason', 'ran-booster' )
			: __( 'Outcome', 'ran-booster' );
		?>
		<p><strong><?php echo esc_html( $outcomeLabel ); ?>:</strong> <?php echo esc_html( null === $item['outcome_code'] ? __( 'This operation has not reached a recorded outcome.', 'ran-booster' ) : DeploymentOutcomeMessage::forCode( (string) $item['outcome_code'] ) ); ?></p>
		<?php if ( 'restoration_uncertain' === ( $item['outcome_code'] ?? null ) ) { ?>
			<section class="notice notice-warning inline" aria-labelledby="ran-booster-historical-uncertainty-heading">
				<h4 id="ran-booster-historical-uncertainty-heading"><?php esc_html_e( 'Before you retry', 'ran-booster' ); ?></h4>
				<p><?php esc_html_e( 'WordPress reported that it changed this package, but Booster could not confirm the final result. The package may already contain the requested update.', 'ran-booster' ); ?></p>
				<?php if ( $laterVerifiedAttempt instanceof DeploymentAttempt ) { ?>
					<?php $laterVerifiedData = $laterVerifiedAttempt->safeData(); ?>
					<p><?php echo esc_html( sprintf( /* translators: 1: later verified deployment date, 2: later deployment ID. */ __( 'Booster verified a later deployment on %1$s (activity #%2$d), so the package has since reached a known state.', 'ran-booster' ), (string) ( $laterVerifiedData['finished_at'] ?? $laterVerifiedData['created_at'] ?? '' ), $laterVerifiedAttempt->getId() ) ); ?></p>
				<?php } else { ?>
					<p><?php esc_html_e( 'Check that the package is present, has the expected version and activation state, and that the site is not in maintenance mode.', 'ran-booster' ); ?></p>
				<?php } ?>
				<p><?php esc_html_e( 'Checking the box only clears the retry block. It does not change the package or prove what happened before.', 'ran-booster' ); ?></p>
			</section>
		<?php } ?>
		<dl class="ran-booster-activity__details">
			<div><dt><?php esc_html_e( 'Support reference', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) $item['correlation_id'] ); ?></code></dd></div>
			<?php if ( 'webhook' === $item['source'] && is_string( $item['delivery_id'] ?? null ) && '' !== $item['delivery_id'] ) { ?>
				<div><dt><?php esc_html_e( 'Provider request ID', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( $item['delivery_id'] ); ?></code></dd></div>
			<?php } ?>
			<div><dt><?php esc_html_e( 'State', 'ran-booster' ); ?></dt><dd><?php echo esc_html( (string) $item['state'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Origin', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $originLabels[ $item['source'] ] ?? (string) $item['source'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Package', 'ran-booster' ); ?></dt><dd>
			<?php
			if ( '' !== $packageSettingsUrl ) {
				?>
				<a href="<?php echo esc_url( $packageSettingsUrl ); ?>"><?php echo esc_html( (string) $item['package_slug'] ); ?></a>
				<?php
			} else {
				?>
				<?php echo esc_html( (string) $item['package_slug'] ); ?><?php } ?> (<?php echo esc_html( (string) $item['package_type'] ); ?>)</dd></div>
			<div><dt><?php esc_html_e( 'Provider', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) $item['provider'] ); ?></code></dd></div>
			<div><dt><?php esc_html_e( 'Requested reference', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) $item['requested_ref'] ); ?></code></dd></div>
			<div><dt><?php esc_html_e( 'Resolved reference', 'ran-booster' ); ?></dt><dd><code><?php echo esc_html( (string) ( $item['resolved_ref'] ?? __( 'Not resolved', 'ran-booster' ) ) ); ?></code></dd></div>
			<div><dt><?php esc_html_e( 'Mutation began', 'ran-booster' ); ?></dt><dd><?php echo esc_html( (string) ( $item['mutation_started_at'] ?? __( 'No', 'ran-booster' ) ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Finished', 'ran-booster' ); ?></dt><dd><?php echo esc_html( (string) ( $item['finished_at'] ?? __( 'Not finished', 'ran-booster' ) ) ); ?></dd></div>
			<?php if ( null !== $item['resolved_at'] && null !== $item['resolved_by'] ) { ?>
				<div><dt><?php esc_html_e( 'Operator review', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: review date and time, 2: WordPress user ID. */ __( 'Resolved %1$s by user #%2$d', 'ran-booster' ), $item['resolved_at'], $item['resolved_by'] ) ); ?></dd></div>
			<?php } ?>
		</dl>
		<?php if ( $attempt->requiresOperatorResolution() ) { ?>
			<form method="post" action="<?php echo esc_url( $packageSettingsUrl ); ?>">
				<?php wp_nonce_field( 'ran-booster-resolve-needs-attention' ); ?>
				<input type="hidden" name="ran_booster[action]" value="resolve-needs-attention">
				<input type="hidden" name="ran_booster[attempt_id]" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
				<input type="hidden" name="ran_booster[correlation_id]" value="<?php echo esc_attr( (string) $item['correlation_id'] ); ?>">
				<p><label><input type="checkbox" name="ran_booster[confirm_reviewed]" value="1" required> <?php esc_html_e( 'I have checked the package\'s current state and want to allow another deployment.', 'ran-booster' ); ?></label></p>
				<button type="submit" class="button button-primary">
					<?php
					if ( '' === $packageSettingsUrl ) {
						esc_html_e( 'Allow retry', 'ran-booster' );
					} elseif ( 'theme' === $packageType ) {
						esc_html_e( 'Allow retry and return to theme settings', 'ran-booster' );
					} else {
						esc_html_e( 'Allow retry and return to plugin settings', 'ran-booster' );
					}
					?>
				</button>
			</form>
		<?php } ?>
		<?php if ( 'running' === $item['state'] ) { ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'ran-booster-reconcile-deployment-worker' ); ?>
				<input type="hidden" name="ran_booster[action]" value="reconcile-deployment-worker">
				<input type="hidden" name="ran_booster[attempt_id]" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
				<input type="hidden" name="ran_booster[correlation_id]" value="<?php echo esc_attr( (string) $item['correlation_id'] ); ?>">
				<p><label><input type="checkbox" name="ran_booster[confirm_stopped]" value="1" required> <?php esc_html_e( 'I have confirmed that the deployment worker has stopped.', 'ran-booster' ); ?></label></p>
				<button type="submit" class="button"><?php esc_html_e( 'Reconcile stopped worker', 'ran-booster' ); ?></button>
			</form>
		<?php } ?>
	<?php } ?>
</section>
