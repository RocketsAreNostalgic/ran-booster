<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * View locals supplied by Dashboard::render().
 *
 * @var array<string, mixed> $onboarding
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$overview_view = ( $onboarding['view'] ?? 'onboarding' ) === 'dashboard' ? 'dashboard' : 'onboarding';
$overview_url  = $onboarding['overview_url'] ?? admin_url( 'admin.php?page=ran-booster&tab=overview' );

$provider_summaries        = $onboarding['provider_summaries'] ?? array_map(
	static fn( array $link ): array => array(
		'code'      => '',
		'label'     => $link['label'],
		'url'       => $link['url'],
		'admitted'  => null,
		'selection' => null,
	),
	$onboarding['provider_links']
);
$provider_selection_labels = array(
	'available'           => __( 'Selection available', 'ran-booster' ),
	'conflict'            => __( 'Provider selection conflict', 'ran-booster' ),
	'incompatible'        => __( 'Provider selection is incompatible', 'ran-booster' ),
	'missing'             => __( 'Selected provider is missing', 'ran-booster' ),
	'recovery_required'   => __( 'Provider selection needs recovery', 'ran-booster' ),
	'decision_required'   => __( 'Provider selection needs a decision', 'ran-booster' ),
	'storage_unavailable' => __( 'Provider selection storage is unavailable', 'ran-booster' ),
);
$provider_attention        = array_filter(
	$provider_summaries,
	static fn( array $provider ): bool => false === $provider['admitted']
		|| ( 'gh' === $provider['code'] && in_array( $provider['selection'], array( 'conflict', 'incompatible', 'missing', 'recovery_required', 'decision_required', 'storage_unavailable' ), true ) )
);

?>
<section class="ran-booster-page-shell ran-booster-onboarding" aria-labelledby="ran-booster-onboarding-heading">
	<header class="ran-booster-page-shell__header ran-booster-onboarding__header">
		<?php if ( 'onboarding' === $overview_view ) { ?>
		<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Prepare your site', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Public repositories do not need repository credentials or webhooks.', 'ran-booster' ); ?></p>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Install and manage custom plugins and themes from supported Git repositories; private access and Push-to-Deploy are optional.', 'ran-booster' ); ?></p>

		<?php } else { ?>
			<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Overview', 'ran-booster' ); ?></h2>
		<?php } ?>
		<?php if ( 'onboarding' === $overview_view && current_user_can( 'manage_options' ) ) { ?>
			<form method="post" action="<?php echo esc_url( $overview_url ); ?>">
				<?php wp_nonce_field( 'ran-booster-overview-presentation' ); ?>
				<input type="hidden" name="ran_booster[action]" value="overview-presentation">
				<input type="hidden" name="ran_booster[presentation]" value="dashboard">
				<button type="submit" class="button"><?php esc_html_e( 'Skip onboarding', 'ran-booster' ); ?></button>
				<p class="description"><?php esc_html_e( 'This changes the view for all site administrators. It does not reset your configuration.', 'ran-booster' ); ?></p>
			</form>
		<?php } ?>
	</header>

	<?php
	$credential_expiry             = $onboarding['credential_expiry'] ?? array(
		'status' => 'unknown',
		'total'  => null,
		'items'  => array(),
	);
	$overview_inventory_incomplete = isset( $onboarding['inventory'] ) && empty( $onboarding['inventory']['complete'] );
	$overview_storage_summary      = $onboarding['storage_summary'] ?? null;
	$overview_storage_url          = $overview_storage_summary['url'] ?? admin_url( 'admin.php?page=ran-booster-settings#ran-booster-storage' );
	$overview_storage_status       = is_array( $overview_storage_summary ) ? (string) ( $overview_storage_summary['status'] ?? '' ) : '';
	$overview_storage_labels       = array(
		'path_configured'         => __( 'Path configured', 'ran-booster' ),
		'storage_healthy'         => __( 'Storage healthy', 'ran-booster' ),
		'storage_needs_attention' => __( 'Storage needs attention', 'ran-booster' ),
		'setup_available'         => __( 'Setup available', 'ran-booster' ),
		'manual_required'         => __( 'Needs attention', 'ran-booster' ),
		'unsupported'             => __( 'Unavailable', 'ran-booster' ),
		'pending_verification'    => __( 'Verification pending', 'ran-booster' ),
	);
	$overview_storage_classes      = array(
		'path_configured'         => 'neutral',
		'storage_healthy'         => 'ok',
		'storage_needs_attention' => 'warning',
		'setup_available'         => 'neutral',
		'manual_required'         => 'warning',
		'unsupported'             => 'error',
		'pending_verification'    => 'pending',
	);
	$overview_storage_unknown      = '' === $overview_storage_status;
	$overview_storage_problem      = in_array( $overview_storage_status, array( 'storage_needs_attention', 'manual_required', 'unsupported' ), true );
	?>
	<?php if ( $overview_inventory_incomplete || $overview_storage_unknown || $overview_storage_problem || array() !== $provider_attention || ( 'captured' === $credential_expiry['status'] && array() !== $credential_expiry['items'] ) ) { ?>
		<section class="ran-booster-panel ran-booster-onboarding__storage" aria-labelledby="ran-booster-overview-attention">
			<h3 id="ran-booster-overview-attention"><?php esc_html_e( 'Needs attention', 'ran-booster' ); ?></h3>
			<?php if ( 'captured' === $credential_expiry['status'] ) { ?>
				<?php foreach ( $credential_expiry['items'] as $expiry_item ) { ?>
					<p><a href="<?php echo esc_url( $expiry_item['url'] ); ?>"><?php echo esc_html( $expiry_item['provider_label'] ); ?>: <?php echo esc_html( $expiry_item['label'] ); ?></a> <span class="ran-booster-pill ran-booster-pill--<?php echo esc_attr( in_array( $expiry_item['stage'], array( 'expired', 'urgent' ), true ) ? 'error' : 'warning' ); ?>"><?php echo esc_html( $expiry_item['badge_label'] ); ?></span></p>
				<?php } ?>
			<?php } ?>
			<?php foreach ( $provider_attention as $provider ) { ?>
				<p><a href="<?php echo esc_url( $provider['url'] ); ?>"><?php echo esc_html( $provider['label'] ); ?></a>: <?php echo esc_html( false === $provider['admitted'] ? __( 'Provider is not admitted.', 'ran-booster' ) : $provider_selection_labels[ $provider['selection'] ] ); ?></p>
			<?php } ?>
			<?php if ( $overview_inventory_incomplete ) { ?>
				<p><?php esc_html_e( 'The managed package inventory is incomplete or unavailable.', 'ran-booster' ); ?></p>
			<?php } ?>
			<?php if ( $overview_storage_unknown ) { ?>
				<p><?php esc_html_e( 'Secure credential storage status is unavailable.', 'ran-booster' ); ?> <a href="<?php echo esc_url( $overview_storage_url ); ?>"><?php esc_html_e( 'Review storage', 'ran-booster' ); ?></a></p>
			<?php } elseif ( $overview_storage_problem ) { ?>
				<p><?php echo esc_html( $overview_storage_summary['message'] ?? '' ); ?> <a href="<?php echo esc_url( $overview_storage_url ); ?>"><?php esc_html_e( 'Review storage', 'ran-booster' ); ?></a></p>
			<?php } ?>
		</section>
	<?php } ?>

	<?php if ( 'dashboard' === $overview_view ) { ?>
		<?php $overview_dashboard_activity = true; ?>
		<?php require __DIR__ . '/overview-dashboard.php'; ?>
	<?php } ?>

	<?php if ( 'onboarding' === $overview_view ) { ?>
		<h3><?php esc_html_e( '1. Prepare secure credential storage', 'ran-booster' ); ?></h3>
		<p><?php esc_html_e( 'Review storage before adding credentials. Public installations remain available while you prepare.', 'ran-booster' ); ?></p>
	<?php } ?>
	<section class="ran-booster-panel ran-booster-onboarding__storage" aria-labelledby="ran-booster-onboarding-storage-heading">
		<div class="ran-booster-onboarding__storage-heading">
			<h3 id="ran-booster-onboarding-storage-heading"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h3>
			<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $overview_storage_classes[ $overview_storage_status ] ?? 'neutral' ); ?>" data-ran-booster-storage-status="<?php echo esc_attr( $overview_storage_status ); ?>"><?php echo esc_html( $overview_storage_labels[ $overview_storage_status ] ?? __( 'Status unknown', 'ran-booster' ) ); ?></span>
		</div>
		<p><?php echo esc_html( $overview_storage_summary['message'] ?? __( 'Storage status is unavailable.', 'ran-booster' ) ); ?></p>
		<p><a href="<?php echo esc_url( $overview_storage_url ); ?>"><?php esc_html_e( 'Review secure credential storage', 'ran-booster' ); ?></a></p>
	</section>

	<?php if ( 'dashboard' === $overview_view ) { ?>
		<?php unset( $overview_dashboard_activity ); ?>
		<?php require __DIR__ . '/overview-dashboard.php'; ?>
	<?php } ?>

	<?php if ( 'onboarding' === $overview_view ) { ?>

	<div class="ran-booster-onboarding__columns">
		<section class="ran-booster-panel ran-booster-onboarding__column" aria-labelledby="ran-booster-onboarding-connect-heading">
			<h3 id="ran-booster-onboarding-connect-heading"><?php esc_html_e( '2. Choose a Git provider', 'ran-booster' ); ?></h3>
			<p><?php esc_html_e( 'Add a credential for a Git provider, such as GitHub or Bitbucket, only when anonymous access is not enough.', 'ran-booster' ); ?></p>

			<h4 class="ran-booster-onboarding__provider-heading"><?php esc_html_e( '3. Add credentials if needed', 'ran-booster' ); ?></h4>
			<p><?php esc_html_e( 'Repository access has not been checked here.', 'ran-booster' ); ?></p>
			<?php if ( array() !== $provider_summaries ) { ?>
				<ul class="ran-booster-onboarding__links">
					<?php foreach ( $provider_summaries as $provider ) { ?>
						<?php // translators: %s is the display name of a registered Git provider. ?>
						<li><a href="<?php echo esc_url( $provider['url'] ); ?>"><?php echo esc_html( sprintf( __( 'Add %s access', 'ran-booster' ), $provider['label'] ) ); ?></a> — <?php echo esc_html( true === $provider['admitted'] ? __( 'Admitted', 'ran-booster' ) : ( false === $provider['admitted'] ? __( 'Not admitted', 'ran-booster' ) : __( 'Admission unknown', 'ran-booster' ) ) ); ?>
						<?php
						if ( 'gh' === $provider['code'] ) {
							?>
							— <?php echo esc_html( $provider_selection_labels[ $provider['selection'] ?? '' ] ?? __( 'Selection unknown', 'ran-booster' ) ); ?><?php } ?></li>
					<?php } ?>
				</ul>
			<?php } else { ?>
				<p><?php esc_html_e( 'Git provider settings will appear here when an integration is available.', 'ran-booster' ); ?></p>
			<?php } ?>
		</section>

		<section class="ran-booster-panel ran-booster-onboarding__column" aria-labelledby="ran-booster-onboarding-next-heading">
			<h3 id="ran-booster-onboarding-next-heading"><?php esc_html_e( 'Move or automate later', 'ran-booster' ); ?></h3>
			<p><?php esc_html_e( 'Packages begin in Manual mode. Enable Push-to-Deploy only when the target is ready.', 'ran-booster' ); ?></p>
			<ul class="ran-booster-onboarding__links">
				<li><a href="<?php echo esc_url( $onboarding['portability_url'] ); ?>"><?php esc_html_e( 'Move an existing Booster setup', 'ran-booster' ); ?></a></li>
				<li><a href="<?php echo esc_url( $onboarding['documentation_url'] ); ?>"><?php esc_html_e( 'Read the documentation', 'ran-booster' ); ?></a></li>
				<li><a href="<?php echo esc_url( $onboarding['troubleshooting_url'] ); ?>"><?php esc_html_e( 'Open troubleshooting', 'ran-booster' ); ?></a></li>
			</ul>
		</section>
	</div>
	<section class="ran-booster-panel ran-booster-onboarding__storage">
		<h3><?php esc_html_e( '4. Install and manage packages', 'ran-booster' ); ?></h3>
		<p class="ran-booster-onboarding__actions">
			<a class="button button-primary" href="<?php echo esc_url( $onboarding['install_plugin_url'] ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
			<a class="button" href="<?php echo esc_url( $onboarding['install_theme_url'] ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a>
		</p>
	</section>



	<?php } ?>

	<?php
	$migration_prompt_buffer_level = ob_get_level();
	ob_start();
	try {
		do_action( 'ran_booster_overview_render_migration_prompt' );
		$migration_prompt = (string) ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Add-ons own and escape their bounded migration prompt.
		echo $migration_prompt;
	} catch ( \Throwable $failure ) {
		while ( ob_get_level() > $migration_prompt_buffer_level ) {
			ob_end_clean();
		}
		\RAN\Logging\BoosterLogger::log_exception( 'overview migration prompt rendering failed', $failure, array( 'step' => 'overview_migration_prompt_render' ) );
	}
	?>

	<?php if ( 'dashboard' === $overview_view && current_user_can( 'manage_options' ) ) { ?>
		<form class="ran-booster-onboarding__presentation-control" method="post" action="<?php echo esc_url( $overview_url ); ?>">
			<?php wp_nonce_field( 'ran-booster-overview-presentation' ); ?>
			<input type="hidden" name="ran_booster[action]" value="overview-presentation">
			<input type="hidden" name="ran_booster[presentation]" value="onboarding">
			<button type="submit" class="button"><?php esc_html_e( 'Return to onboarding', 'ran-booster' ); ?></button>
			<p class="description"><?php esc_html_e( 'This changes the view for all site administrators. It does not reset your configuration.', 'ran-booster' ); ?></p>
		</form>
	<?php } ?>
</section>
