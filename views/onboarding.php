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
<section class="ran-booster-page-shell ran-booster-onboarding<?php echo 'onboarding' === $overview_view ? ' ran-booster-onboarding--setup' : ''; ?>" aria-labelledby="ran-booster-onboarding-heading">
	<header class="ran-booster-page-shell__header ran-booster-onboarding__header">
		<?php if ( 'onboarding' === $overview_view ) { ?>
			<div>
				<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Prepare your site', 'ran-booster' ); ?></h2>
				<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Public repositories do not need credentials or webhooks.', 'ran-booster' ); ?></p>
			</div>
		<?php } else { ?>
			<div>
				<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Overview', 'ran-booster' ); ?></h2>
				<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Review recent activity, managed packages and your site’s configuration.', 'ran-booster' ); ?></p>
			</div>
		<?php } ?>
		<?php if ( current_user_can( 'manage_options' ) ) { ?>
			<form method="post" action="<?php echo esc_url( $overview_url ); ?>">
				<?php wp_nonce_field( 'ran-booster-overview-presentation' ); ?>
				<input type="hidden" name="ran_booster[action]" value="overview-presentation">
				<input type="hidden" name="ran_booster[presentation]" value="<?php echo 'onboarding' === $overview_view ? 'dashboard' : 'onboarding'; ?>">
				<span data-ran-booster-ui-button="overview-presentation">
					<button type="submit" class="button" data-ran-booster-ui-button-fallback><?php echo esc_html( 'onboarding' === $overview_view ? __( 'Skip onboarding', 'ran-booster' ) : __( 'Return to onboarding', 'ran-booster' ) ); ?></button>
					<span data-ran-booster-ui-button-mount></span>
				</span>
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
	$overview_storage_intents      = array(
		'path_configured'         => 'none',
		'storage_healthy'         => 'stable',
		'storage_needs_attention' => 'medium',
		'setup_available'         => 'none',
		'manual_required'         => 'medium',
		'unsupported'             => 'high',
		'pending_verification'    => 'informational',
	);
	$overview_storage_unknown      = '' === $overview_storage_status;
	$overview_storage_problem      = in_array( $overview_storage_status, array( 'storage_needs_attention', 'manual_required', 'unsupported' ), true );
	?>
	<?php if ( $overview_inventory_incomplete || $overview_storage_unknown || $overview_storage_problem || array() !== $provider_attention || 'unavailable' === $credential_expiry['status'] || ( 'captured' === $credential_expiry['status'] && array() !== $credential_expiry['items'] ) ) { ?>
		<section class="ran-booster-panel ran-booster-onboarding__storage ran-booster-card" data-ran-booster-card="overview" aria-labelledby="ran-booster-overview-attention"><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
			<h3 id="ran-booster-overview-attention"><?php esc_html_e( 'Needs attention', 'ran-booster' ); ?></h3>
			<?php if ( 'captured' === $credential_expiry['status'] ) { ?>
				<?php foreach ( $credential_expiry['items'] as $expiry_item ) { ?>
					<p><a href="<?php echo esc_url( $expiry_item['url'] ); ?>"><?php echo esc_html( $expiry_item['provider_label'] ); ?>: <?php echo esc_html( $expiry_item['label'] ); ?></a> <?php echo \RAN\Admin\Component\StatusBadge::for_intent( (string) $expiry_item['badge_label'], in_array( $expiry_item['stage'], array( 'expired', 'urgent' ), true ) ? 'high' : 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?></p>
				<?php } ?>
				<?php if ( null !== $credential_expiry['total'] && $credential_expiry['total'] > count( $credential_expiry['items'] ) ) { ?>
					<?php // translators: 1: displayed reminders, 2: total actionable reminders. ?>
					<p><?php echo esc_html( sprintf( __( 'Showing %1$d of %2$d actionable expiry reminders.', 'ran-booster' ), count( $credential_expiry['items'] ), $credential_expiry['total'] ) ); ?></p>
				<?php } ?>
			<?php } elseif ( 'unavailable' === $credential_expiry['status'] ) { ?>
				<p><?php esc_html_e( 'Credential expiry reminders are unavailable. Review credentials in provider settings.', 'ran-booster' ); ?></p>
				<?php foreach ( $onboarding['provider_links'] as $provider_link ) { ?>
					<a href="<?php echo esc_url( $provider_link['url'] ); ?>"><?php echo esc_html( $provider_link['label'] ); ?></a>
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
		</div></div></section>
	<?php } ?>

	<div class="ran-booster-overview-grid">
	<?php if ( 'dashboard' === $overview_view ) { ?>
		<?php $overview_dashboard_activity = true; ?>
		<?php require __DIR__ . '/overview-dashboard.php'; ?>
	<?php } ?>


	<?php if ( 'dashboard' === $overview_view ) { ?>
		<?php unset( $overview_dashboard_activity ); ?>
		<?php require __DIR__ . '/overview-dashboard.php'; ?>
	<?php } ?>
	<section class="ran-booster-panel ran-booster-onboarding__storage ran-booster-card" data-ran-booster-card="overview" aria-labelledby="ran-booster-onboarding-storage-heading"><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
		<div class="ran-booster-onboarding__storage-heading">
			<h3 id="ran-booster-onboarding-storage-heading"><?php echo esc_html( 'onboarding' === $overview_view ? __( '1. Secure credential storage', 'ran-booster' ) : __( 'Secure credential storage', 'ran-booster' ) ); ?></h3>
			<?php $overview_storage_label = $overview_storage_labels[ $overview_storage_status ] ?? __( 'Status unknown', 'ran-booster' ); ?>
			<?php echo \RAN\Admin\Component\StatusBadge::for_intent( (string) $overview_storage_label, (string) ( $overview_storage_intents[ $overview_storage_status ] ?? 'none' ), array( 'data-ran-booster-storage-status' => (string) $overview_storage_status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?>
		</div>
		<p><?php echo esc_html( $overview_storage_summary['message'] ?? __( 'Storage status is unavailable.', 'ran-booster' ) ); ?></p>
		<p><a href="<?php echo esc_url( $overview_storage_url ); ?>"><?php esc_html_e( 'Review secure credential storage', 'ran-booster' ); ?></a></p>
	</div></div></section>



	<?php if ( 'onboarding' === $overview_view ) { ?>

	<section class="ran-booster-panel ran-booster-onboarding__storage ran-booster-card" data-ran-booster-card="overview" aria-labelledby="ran-booster-onboarding-connect-heading"><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
		<h3 id="ran-booster-onboarding-connect-heading"><?php esc_html_e( '2. Choose a Git provider', 'ran-booster' ); ?></h3>
		<p><?php esc_html_e( 'Add credentials on the provider’s page if needed.', 'ran-booster' ); ?></p>
		<p class="ran-booster-onboarding__actions">
			<?php foreach ( $provider_summaries as $provider ) { ?>
				<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) ); ?>" href="<?php echo esc_url( $provider['url'] ); ?>"><?php echo esc_html( $provider['label'] ); ?></a>
			<?php } ?>
			<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-extensions' ) ); ?>"><?php esc_html_e( 'Install another provider', 'ran-booster' ); ?></a>
		</p>
	</div></div></section>
	<section class="ran-booster-panel ran-booster-onboarding__storage ran-booster-card" data-ran-booster-card="overview" aria-labelledby="ran-booster-onboarding-next-heading"><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
		<h3 id="ran-booster-onboarding-next-heading"><?php esc_html_e( '3. Import or migrate (optional)', 'ran-booster' ); ?></h3>
		<p><?php esc_html_e( 'Import a Booster Blueprint with Transporter. To migrate from another tool, browse the available extensions.', 'ran-booster' ); ?></p>
		<p class="ran-booster-onboarding__actions">
			<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) ); ?>" href="<?php echo esc_url( $onboarding['portability_url'] ); ?>"><?php esc_html_e( 'Transporter', 'ran-booster' ); ?></a>
			<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-extensions' ) ); ?>"><?php esc_html_e( 'Browse extensions', 'ran-booster' ); ?></a>
		</p>
	</div></div></section>
	<section class="ran-booster-panel ran-booster-onboarding__storage ran-booster-card" data-ran-booster-card="overview"><div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">
		<h3><?php esc_html_e( '4. Install and manage packages', 'ran-booster' ); ?></h3>
		<p><?php esc_html_e( 'Install a plugin or theme from a Git repository, or link one already installed on this site.', 'ran-booster' ); ?></p>
		<p class="ran-booster-onboarding__actions">
			<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary( false, true ) ); ?>" href="<?php echo esc_url( $onboarding['install_plugin_url'] ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
			<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) ); ?>" href="<?php echo esc_url( $onboarding['install_theme_url'] ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a>
		</p>
	</div></div></section>

	<?php } ?>
	</div>

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

</section>
