<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Provider page inputs assembled by Dashboard::get_index(), ProviderSettingsPresenter
 * and ProviderRepositoryRowsNormalizer.
 *
 * @var array<string,mixed>|null $github_selection
 * @var string $activity_url
 * @var string $credential_management_description
 * @var string $credentials_url
 * @var string $delete_webhook_interaction_values
 * @var string $empty_repository_description
 * @var string $install_plugin_url
 * @var string $install_theme_url
 * @var string $manual_setup_description
 * @var string $overview_url
 * @var string $provider_instructions_label
 * @var string $provider_list_action_url
 * @var string $provider_return_url
 * @var string $provider_task
 * @var string $provider_view
 * @var string $repository_list_url
 * @var string $repository_row_count_label
 * @var string $repository_view
 * @var string $repository_webhook_description
 * @var string $requested_repository_id
 * @var string $secret_management_description
 * @var string $secrets_url
 * @var string $webhook_endpoint
 * @var string $webhook_operations_url
 * @var string $wordpress_urls_url
 * @var bool $has_credential_settings
 * @var bool $has_webhook_settings
 * @var bool $provider_has_webhook_settings
 * @var bool $repository_inventory_available
 * @var bool $repository_integration_available
 * @var bool $storage_unavailable
 * @var bool $webhook_assistance_provider_capable
 * @var bool $webhook_assistance_site_ready
 * @var bool $webhook_has_hard_failure
 * @var int $credential_row_count
 * @var int $ready_webhook_profile_count
 * @var int $webhook_row_count
 * @var array<string, string> $credential_scopes
 * @var array<string, string> $credential_sort_urls
 * @var array<string, string> $package_type_labels
 * @var array<string, string> $provider_mutation_fields
 * @var array<string, string> $repository_view_request_urls
 * @var array<string, string> $repository_view_urls
 * @var array<string, string> $task_request_urls
 * @var array<string, string> $task_urls
 * @var array<string, string> $webhook_sort_urls
 * @var array{item_count_label:string,page_label:string,current:int,pages:int,per_page:int,action_url:string,hidden_fields:array<string,scalar>,previous_url:string,next_url:string} $credential_pagination
 * @var array<string, mixed> $provider
 * @var array<string, mixed> $provider_list_state
 * @var array{item_count_label:string,page_label:string,current:int,pages:int,per_page:int,action_url:string,hidden_fields:array<string,scalar>,previous_url:string,next_url:string} $webhook_pagination
 * @var array{rows: list<array<string, mixed>>, total: int, pages: int, current: int} $credential_list
 * @var array{rows: list<array<string, mixed>>, total: int, pages: int, current: int} $webhook_list
 * @var array{tone: string, heading: string, description: string} $credential_summary
 * @var array{tone: string, heading: string, description: string} $webhook_summary
 * @var array<string, int|bool> $repository_integration_summary
 * @var array<string, mixed>|null $selected_repository_row
 * @var array<string, mixed>|null $webhook_setup
 * @var array{configured_id: string, stale: bool}|null $public_lookup_profile
 * @var list<array<string, mixed>> $repository_table_rows
 * @var list<string> $webhook_site_reasons
 * @var \RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls|null $webhook_management
 * @var \RAN\Admin\Component\AdminStatusSummaryRenderer $status_summary_renderer
 * @var \RAN\Admin\Component\RepositoryDetailRenderer $repository_detail_renderer
 * @var \RAN\Admin\Component\RepositoryTableRenderer $repository_table_renderer
 * @var \RAN\Admin\Component\ProviderManagementTableRenderer $provider_management_table_renderer
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$credential_validation_feedback = isset( $credential_validation_feedback ) && is_array( $credential_validation_feedback ) ? $credential_validation_feedback : null;
$render_credential_cell         = static function ( array $profile, string $column ) use ( $package_type_labels, $provider, $provider_mutation_fields, $credential_validation_feedback ): void {
	if ( 'name' === $column ) {
		?>
		<strong><?php echo esc_html( $profile['label'] ); ?></strong>
		<?php if ( '' !== $profile['configuration_label'] ) { ?>
			<p class="description"><?php echo esc_html( $profile['configuration_label'] ); ?></p>
		<?php } ?>
		<?php
		return;
	}

	if ( 'kind' === $column ) {
		echo esc_html( $profile['kind_label'] );

		return;
	}

	if ( 'scope' === $column ) {
		echo esc_html( $profile['scope_label'] );

		return;
	}

	if ( 'usage' === $column ) {
		echo esc_html( $profile['usage_label'] );

		return;
	}

	if ( 'health' === $column ) {
		$credential_health_id          = (string) $profile['id'];
		$credential_health_label       = (string) $profile['health_label'];
		$matches                       = ( $credential_validation_feedback['provider'] ?? null ) === $provider['code'] && ( $credential_validation_feedback['id'] ?? null ) === $credential_health_id;
		$credential_validation_message = $matches ? ( $credential_validation_feedback['message'] ?? null ) : null;
		$credential_validation_error   = $matches ? ( $credential_validation_feedback['error'] ?? null ) : null;
		require __DIR__ . '/provider-credential-health.php';
		return;
	}

	$usage             = $profile['usage'];
	$usage_template_id = 'ran-booster-delete-credential-usage-' . (string) $profile['profile_index'];
	if ( $profile['configured'] && $provider['capabilities']['credentials'] ) {
		?>
		<form method="post" action="" class="ran-booster-inline-form" data-ran-booster-enhanced-mutation data-ran-booster-credential-validation data-ran-booster-error-target="#ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" hx-post="" hx-target="#ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" hx-swap="outerHTML" hx-sync="this:drop">
		<?php
		foreach ( $provider_mutation_fields as $name => $value ) {
			?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php } ?><input type="hidden" name="ran_booster[action]" value="validate-access-profile"><input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="ran_booster[id]" value="<?php echo esc_attr( $profile['id'] ); ?>"><button type="submit" class="button"><?php esc_html_e( 'Validate', 'ran-booster' ); ?></button></form>
		<?php
	}
	if ( ! $profile['editable'] ) {
		?>
		<span class="description"><?php esc_html_e( 'Managed by deployment configuration', 'ran-booster' ); ?></span>
		<?php
		return;
	}
	?>
	<button type="button" class="button ran-booster-open-credential-modal" data-modal="access" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-kind="<?php echo esc_attr( $profile['kind'] ); ?>" data-configuration="<?php echo esc_attr( $profile['configuration_json'] ); ?>" data-expires-on="<?php echo esc_attr( $profile['expiry']['manual_expires_on'] ?? '' ); ?>" data-provider-expires-on="<?php echo esc_attr( $profile['provider_expires_on'] ); ?>" data-self-destruct="<?php echo ! empty( $profile['self_destruct'] ) ? '1' : '0'; ?>" data-destroy-on="<?php echo esc_attr( $profile['destroy_on'] ?? '' ); ?>"><?php esc_html_e( 'Edit', 'ran-booster' ); ?></button><button type="button" class="button button-delete ran-booster-open-delete-credential-modal" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-usage-total="<?php echo esc_attr( $usage['available'] ? (string) $usage['total'] : '' ); ?>" data-usage-listed="<?php echo esc_attr( (string) $profile['usage_listed'] ); ?>" data-usage-template="<?php echo esc_attr( $usage_template_id ); ?>" data-public-lookup-default="<?php echo ! empty( $profile['public_lookup_default'] ) ? '1' : '0'; ?>" aria-haspopup="dialog" aria-controls="ran-booster-delete-access-modal" <?php disabled( ! $usage['available'] ); ?>><?php esc_html_e( 'Delete', 'ran-booster' ); ?></button>
	<template id="<?php echo esc_attr( $usage_template_id ); ?>">
		<ul class="ran-booster-delete-credential-package-list">
			<?php foreach ( $usage['packages'] as $package_usage ) { ?>
				<li class="ran-booster-delete-credential-package-list__item">
					<?php if ( null !== $package_usage['edit_url'] ) { ?>
						<a class="ran-booster-pill ran-booster-pill--label ran-booster-pill--info ran-booster-delete-credential-package-pill" href="<?php echo esc_url( $package_usage['edit_url'] ); ?>"><?php echo esc_html( $package_type_labels[ $package_usage['type'] ] . ': ' . $package_usage['identifier'] ); ?></a>
					<?php } else { ?>
						<span class="ran-booster-pill ran-booster-pill--label ran-booster-delete-credential-package-pill ran-booster-delete-credential-package-pill--unavailable"><?php echo esc_html( $package_type_labels[ $package_usage['type'] ] . ': ' . $package_usage['identifier'] ); ?> <?php esc_html_e( '(not installed)', 'ran-booster' ); ?></span>
					<?php } ?>
				</li>
			<?php } ?>
		</ul>
	</template>
	<?php
};
$render_webhook_cell  = static function ( array $profile, string $column ) use ( $provider, $delete_webhook_interaction_values, $provider_mutation_fields ): void {
	if ( 'name' === $column ) {
		?>
		<strong><?php echo esc_html( $profile['label'] ); ?></strong>
		<?php
		return;
	}

	if ( 'scope' === $column ) {
		echo esc_html( $profile['scope_label'] . ' · ' . $profile['target'] );

		return;
	}

	if ( 'usage' === $column ) {
		echo esc_html( $profile['usage_label'] );

		return;
	}

	if ( 'health' === $column ) {
		echo esc_html( $profile['health_label'] );

		return;
	}

	if ( ! $profile['editable'] ) {
		?>
		<span class="description"><?php esc_html_e( 'Managed by deployment configuration', 'ran-booster' ); ?></span>
		<?php
		return;
	}

	?>
	<button type="button" class="button ran-booster-open-credential-modal" data-modal="webhook" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-scope="<?php echo esc_attr( $profile['scope'] ); ?>" data-target="<?php echo esc_attr( $profile['target'] ); ?>"><?php esc_html_e( 'Edit', 'ran-booster' ); ?></button><form method="post" action="" class="ran-booster-inline-form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-delete-webhook-profile-error" hx-post="" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop" hx-vals="<?php echo esc_attr( $delete_webhook_interaction_values ); ?>">
	<?php
	foreach ( $provider_mutation_fields as $name => $value ) {
		?>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php } ?><input type="hidden" name="ran_booster[action]" value="delete-webhook-profile"><input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="ran_booster[id]" value="<?php echo esc_attr( $profile['id'] ); ?>"><button type="submit" class="button button-delete" data-confirm="<?php echo esc_attr( $profile['delete_confirmation'] ); ?>"><?php esc_html_e( 'Delete', 'ran-booster' ); ?></button></form>
	<?php
};
$is_repository_detail = 'overview' === $provider_view && 'repositories' === $provider_task && '' !== $requested_repository_id;

?>

<div id="ran-booster-provider-profile-region" data-ran-booster-admin-mutation-region="provider-profiles">
<?php settings_errors(); ?>
<?php if ( 'overview' !== $provider_view ) { ?>
	<header class="ran-booster-action-row ran-booster-provider-management__header">
		<div>
			<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title" data-ran-booster-provider-profile-focus tabindex="-1"><?php echo esc_html( 'credentials' === $provider_view ? __( 'Credentials', 'ran-booster' ) : __( 'Webhook secrets', 'ran-booster' ) ); ?></h2>
			<p class="ran-booster-page-heading__description">
				<?php
				echo esc_html(
					'credentials' === $provider_view
						? $credential_management_description
						: $secret_management_description
				);
				?>
			</p>
		</div>
		<?php if ( 'credentials' === $provider_view && $has_credential_settings ) { ?>
			<button type="button" class="button button-primary ran-booster-open-credential-modal" data-modal="access"><?php esc_html_e( 'Add credential', 'ran-booster' ); ?></button>
		<?php } elseif ( 'secrets' === $provider_view && $has_webhook_settings ) { ?>
			<button type="button" class="button button-primary ran-booster-open-credential-modal" data-modal="webhook"><?php esc_html_e( 'Add webhook secret', 'ran-booster' ); ?></button>
		<?php } ?>
	</header>
<?php } ?>
<section class="ran-booster-page-shell ran-booster-provider<?php echo $is_repository_detail ? ' ran-booster-repository-page' : ( 'overview' === $provider_view ? ' ran-booster-provider--overview' : '' ); ?><?php echo in_array( $provider_view, array( 'credentials', 'secrets' ), true ) ? ' ran-booster-card' : ''; ?>"<?php echo in_array( $provider_view, array( 'credentials', 'secrets' ), true ) ? ' data-ran-booster-card="provider"' : ''; ?> aria-labelledby="ran-booster-provider-heading">
	<?php if ( 'overview' === $provider_view && ! $is_repository_detail ) { ?>
		<header class="ran-booster-page-shell__header ran-booster-provider__header">
			<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title" data-ran-booster-provider-profile-focus tabindex="-1"><?php echo esc_html( $provider['label'] ); ?></h2>
			<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Manage repository access, integrations and the packages connected to this site.', 'ran-booster' ); ?></p>
		</header>
	<?php } ?>


	<?php if ( isset( $github_selection ) && is_array( $github_selection ) ) { ?>
		<?php
		$selection_status       = $github_selection['status'];
		$current_implementation = $selection_status['current']['declaration'] ?? null;
		$healthy_replacement    = 'available' === $selection_status['state'] && $github_selection['available'];
		?>
		<section aria-label="<?php esc_attr_e( 'GitHub provider management', 'ran-booster' ); ?>">
			<?php if ( $healthy_replacement && is_array( $current_implementation ) ) { ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: selected provider plugin name. */ __( 'Provider extension: %s', 'ran-booster' ), $current_implementation['display_name'] ) ); ?></p>
				<details class="ran-booster-disclosure"><summary><?php esc_html_e( 'Manage GitHub provider', 'ran-booster' ); ?></summary>
				<div class="ran-booster-disclosure__body">
				<p><?php esc_html_e( 'To change the GitHub provider integration, deactivate the current replacement plugin and enable only the replacement you want to use. To return to bundled GitHub, deactivate all replacement plugins. Then return here to review and approve the change. GitHub operations pause until you complete that review.', 'ran-booster' ); ?></p>
			<?php } else { ?>
				<?php $native_recovery = $github_selection['recovery'] ?? null; ?>
				<?php if ( 'decision_required' !== $selection_status['state'] ) { ?>
					<div class="notice notice-warning inline" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning"><div data-ran-booster-ui-notice-content>
						<h3>
						<?php
						echo esc_html(
							match ( $selection_status['state'] ) {
							'conflict' => __( 'Multiple GitHub providers are active', 'ran-booster' ),
							'incompatible' => __( 'A GitHub provider is incompatible', 'ran-booster' ),
							'missing' => __( 'The selected GitHub provider is missing', 'ran-booster' ),
							'storage_unavailable' => __( 'GitHub provider selection is unavailable', 'ran-booster' ),
							'recovery_required' => __( 'An installation needs recovery', 'ran-booster' ),
							default => __( 'GitHub provider unavailable', 'ran-booster' ),
							}
						);
						?>
						</h3>
						<p>
						<?php
						echo esc_html(
							match ( $selection_status['state'] ) {
							'conflict' => __( 'Deactivate the extra provider plugins, then return here to review your selection.', 'ran-booster' ),
							'incompatible' => __( 'Deactivate the incompatible provider plugin, then return here to review your selection.', 'ran-booster' ),
							'missing' => __( 'Restore the selected plugin or deactivate all replacement plugins, then review a return to bundled GitHub.', 'ran-booster' ),
							'storage_unavailable' => __( 'Booster could not verify its saved GitHub provider choice. Review GitHub provider details before continuing.', 'ran-booster' ),
							'recovery_required' => __( 'Booster could not confirm whether the installation or update completed. Resolve this before changing the GitHub provider.', 'ran-booster' ),
							default => '',
							}
						);
						?>
						</p>
						<?php if ( 'recovery_required' === $selection_status['state'] ) { ?>
							<?php if ( is_array( $native_recovery ) ) { ?>
						<p><code><?php echo esc_html( sprintf( 'theme' === $native_recovery['type'] ? /* translators: %s: affected theme identifier. */ __( 'Affected theme: %s', 'ran-booster' ) : /* translators: %s: affected plugin identifier. */ __( 'Affected plugin: %s', 'ran-booster' ), $native_recovery['identifier'] ) ); ?></code></p>
						<ol>
							<li><?php esc_html_e( 'Confirm the installation or update process has stopped.', 'ran-booster' ); ?></li>
							<li><?php esc_html_e( 'Back up anything you need, then remove the affected plugin or theme completely.', 'ran-booster' ); ?></li>
							<li><?php esc_html_e( 'Confirm the statements below to verify removal.', 'ran-booster' ); ?></li>
						</ol>
						<p><?php esc_html_e( 'Booster verifies removal, clears the installation block, and disconnects Booster management. It does not delete files or repair the installation.', 'ran-booster' ); ?></p>
						<details class="ran-booster-disclosure"><summary><?php esc_html_e( 'Recovery details', 'ran-booster' ); ?></summary>
						<div class="ran-booster-disclosure__body">
						<p><?php esc_html_e( 'Remove the target folder and all its contents, or the file itself for a standalone plugin. A missing main file alone is insufficient for a plugin or theme with a folder.', 'ran-booster' ); ?></p>
						<p><?php esc_html_e( 'An occupied updater lock prevents recovery. Booster will not clear it based on its age. Cleanup of temporary files from the interrupted process remains unverified.', 'ran-booster' ); ?></p>
						</div></details>
								<?php if ( current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' ) ) { ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster&tab=gh' ) ); ?>" data-hx-boost="false">
									<?php wp_nonce_field( 'ran-booster-recover-github-' . $native_recovery['digest'] ); ?>
								<input type="hidden" name="ran_booster[action]" value="recover-github-native">
								<input type="hidden" name="ran_booster[review_digest]" value="<?php echo esc_attr( $native_recovery['digest'] ); ?>">
								<p><label><input type="checkbox" name="ran_booster[confirmed_stopped]" value="1" required> <?php esc_html_e( 'I have confirmed that the interrupted installation or update process has stopped.', 'ran-booster' ); ?></label></p>
								<p><label><input type="checkbox" name="ran_booster[confirmed_removal]" value="1" required> <?php esc_html_e( 'I have removed the entire target and approve disconnecting its management record.', 'ran-booster' ); ?></label></p>
								<button type="submit" class="button"><?php esc_html_e( 'Verify removal and disconnect', 'ran-booster' ); ?></button>
							</form>
						<?php } ?>
					<?php } ?>
						<?php } ?>
						<?php if ( 'missing' === $selection_status['state'] && is_array( $current_implementation ) ) { ?>
							<p><?php echo esc_html( sprintf( /* translators: %s: selected provider plugin name. */ __( 'Missing provider: %s', 'ran-booster' ), $current_implementation['display_name'] ) ); ?></p>
						<?php } ?>
						<?php if ( in_array( $selection_status['state'], array( 'conflict', 'incompatible' ), true ) ) { ?>
							<ul><?php foreach ( $selection_status['candidates'] as $basename => $candidate ) { ?>
								<li><?php echo esc_html( is_array( $candidate ) ? $candidate['display_name'] : $basename ); ?></li>
							<?php } ?></ul>
						<?php } ?>
						<?php if ( ! $github_selection['available'] ) { ?>
							<p><?php esc_html_e( 'GitHub operations are paused. Local records remain available and Bitbucket is unaffected.', 'ran-booster' ); ?></p>
						<?php } ?>
					</div></div>
				<?php } elseif ( ! $github_selection['available'] ) { ?>
					<p><?php esc_html_e( 'GitHub operations are paused. Local records remain available and Bitbucket is unaffected.', 'ran-booster' ); ?></p>
				<?php } ?>
				<?php if ( '' !== $github_selection['error'] && ! in_array( $selection_status['state'], array( 'conflict', 'incompatible', 'storage_unavailable' ), true ) ) { ?>
					<p role="status"><?php echo esc_html( $github_selection['error'] ); ?></p>
				<?php } ?>
				<?php $adoption_review = $github_selection['review']; ?>
				<?php if ( is_array( $adoption_review ) ) { ?>
					<details class="ran-booster-disclosure"><summary><?php echo esc_html( 'bundled' === $adoption_review['selected'] ? __( 'Review return to bundled GitHub', 'ran-booster' ) : __( 'Review GitHub provider', 'ran-booster' ) ); ?></summary>
					<div class="ran-booster-disclosure__body">
					<?php
					$review_target = $selection_status['candidates'][ $adoption_review['target']['plugin_basename'] ] ?? null;
					$review_name   = 'bundled' === $adoption_review['selected']
						? __( 'Bundled GitHub', 'ran-booster' )
						: ( is_array( $review_target ) ? $review_target['display_name'] : $adoption_review['target']['implementation_id'] );
					?>
					<h4><?php esc_html_e( 'Selected provider', 'ran-booster' ); ?></h4>
					<p><?php echo esc_html( sprintf( /* translators: %s: the implementation being reviewed for approval. */ __( 'Reviewing GitHub provider: %s', 'ran-booster' ), $review_name ) ); ?></p>
					<p><?php esc_html_e( 'Plugin names and compatibility claims do not verify publisher identity. Approve only a plugin you trust.', 'ran-booster' ); ?></p>
					<p><?php echo esc_html( is_array( $github_selection['package_counts'] ) ? sprintf( /* translators: 1: managed Branch packages, 2: managed release packages. */ __( 'Affected GitHub packages: %1$d Branch, %2$d Published release.', 'ran-booster' ), $github_selection['package_counts']['branch'], $github_selection['package_counts']['release_asset'] ) : __( 'Affected package inventory could not be checked.', 'ran-booster' ) ); ?></p>
					<h4><?php esc_html_e( 'Saved credentials this provider may use', 'ran-booster' ); ?></h4>
					<p><?php echo esc_html( sprintf( /* translators: 1: compatible profile count, 2: incompatible profile count. */ __( 'Saved GitHub access credentials: %1$d compatible, %2$d incompatible. Counts include credentials configured in wp-config.php.', 'ran-booster' ), $adoption_review['compatible_count'], $adoption_review['incompatible_count'] ) ); ?></p>
					<h4><?php esc_html_e( 'Paused operations', 'ran-booster' ); ?></h4>
					<p><?php esc_html_e( 'Credentials this provider cannot use remain unchanged and unavailable. Packages using them stay paused; Booster will not substitute another credential. Operations this provider does not support remain unavailable.', 'ran-booster' ); ?></p>
					<h4><?php esc_html_e( 'Approval', 'ran-booster' ); ?></h4>
					<?php if ( ! $github_selection['available'] && current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' ) ) { ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster&tab=gh' ) ); ?>" data-hx-boost="false">
							<?php wp_nonce_field( 'ran-booster-adopt-github-' . $adoption_review['digest'] ); ?>
							<input type="hidden" name="ran_booster[action]" value="adopt-github">
							<input type="hidden" name="ran_booster[review_digest]" value="<?php echo esc_attr( $adoption_review['digest'] ); ?>">
							<p><label><input type="checkbox" name="ran_booster[confirmed]" value="1" required> <?php esc_html_e( 'I approve this provider and future setup of credentials it supports.', 'ran-booster' ); ?></label></p>
							<p><?php echo esc_html( $adoption_review['signing_reuse'] ? __( 'This approval also permits reuse of compatible existing webhook signing secrets.', 'ran-booster' ) : __( 'Existing webhook signing secrets remain stored but webhook verification is unavailable with this provider.', 'ran-booster' ) ); ?></p>
							<?php if ( $adoption_review['compatible_count'] > 0 ) { ?>
								<p><label><input type="checkbox" name="ran_booster[reuse_existing]" value="1" required> <?php esc_html_e( 'Allow this provider to read and use all compatible existing GitHub access credentials, including those configured in wp-config.php.', 'ran-booster' ); ?></label></p>
								<p><?php esc_html_e( 'Declining reuse cancels this adoption. GitHub remains paused until you complete an approved selection or restore the previously approved provider.', 'ran-booster' ); ?></p>
							<?php } elseif ( $adoption_review['has_material'] ) { ?>
								<p><label><input type="checkbox" name="ran_booster[acknowledge_incompatible]" value="1" required> <?php esc_html_e( 'I understand that none of the existing GitHub access credentials can be reused. Compatible access credentials must be configured afterward.', 'ran-booster' ); ?></label></p>
							<?php } ?>
							<button type="submit" class="button button-primary"><?php echo esc_html( 'bundled' === $adoption_review['selected'] ? __( 'Return to bundled GitHub', 'ran-booster' ) : __( 'Approve GitHub provider', 'ran-booster' ) ); ?></button>
						</form>
					<?php } ?>
					</div>
					</details>
				<?php } ?>
			<?php } ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=installation#ran-booster-github-provider-diagnostics' ) ); ?>"><?php esc_html_e( 'GitHub provider details', 'ran-booster' ); ?></a></p>
			<?php
			if ( $healthy_replacement ) {
				?>
				</div>
				</details><?php } ?>
		</section>
	<?php } ?>

	<?php if ( $storage_unavailable && ! $is_repository_detail ) { ?>
		<div class="notice notice-error inline ran-booster-provider__notice" data-ran-booster-provider-storage-notice data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error"><div data-ran-booster-ui-notice-content>
			<p><strong><?php esc_html_e( 'Saved credentials are unavailable.', 'ran-booster' ); ?></strong> <a href="<?php echo esc_url( ( function_exists( 'is_network_admin' ) && is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) ) . '?page=ran-booster-settings#ran-booster-storage' ); ?>"><?php esc_html_e( 'Review Secure credential storage in Settings', 'ran-booster' ); ?></a></p>
		</div></div>
	<?php } ?>

	<?php if ( $is_repository_detail ) { ?>
		<?php if ( is_array( $selected_repository_row ) ) { ?>
			<?php
			$has_branch_consumer = ! empty( $selected_repository_row['has_branch_consumer'] );
			$repository_detail_renderer->render(
				$selected_repository_row,
				$provider['label'],
				$repository_list_url,
				$activity_url,
				$webhook_assistance_site_ready,
				$webhook_assistance_site_ready ? __( 'This site can receive Git provider webhook deliveries.', 'ran-booster' ) : implode( ' ', $webhook_site_reasons ),
				$repository_view,
				$repository_view_urls,
				$repository_view_request_urls,
				null !== $webhook_management && $webhook_management->has_management_capability( $provider['code'] )
					? static function () use ( $webhook_management, $provider, $requested_repository_id, $provider_return_url, $repository_view_urls, $has_branch_consumer, $selected_repository_row ): bool|string {
						$return_url = is_string( $repository_view_urls['branch'] ?? null ) ? $repository_view_urls['branch'] : $provider_return_url;
						return $webhook_management->render_repository_webhook_setup( $provider['code'], $requested_repository_id, $return_url, $has_branch_consumer, (string) ( $selected_repository_row['repository'] ?? '' ) ) ?? true;
					}
					: null,
				static function () use ( $selected_repository_row, $provider_return_url, $repository_view_urls ): void {
					$return_url = is_string( $repository_view_urls['releases'] ?? null ) ? $repository_view_urls['releases'] : $provider_return_url;
					do_action( 'ran_booster_admin_repository_release_sections', $selected_repository_row, $return_url );
				}
			);
			?>
		<?php } else { ?>
			<div class="notice notice-error inline" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error"><div data-ran-booster-ui-notice-content><p><?php esc_html_e( 'That managed repository is no longer available. Return to the repository list and choose a current repository.', 'ran-booster' ); ?></p></div></div>
		<?php } ?>
	<?php } elseif ( 'overview' === $provider_view ) { ?>
		<?php if ( ! empty( $provider['credential_kinds'] ) ) { ?>
		<section class="ran-booster-provider-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-access-tokens-heading">
				<header class="ran-booster-provider-section__header">
					<h3 id="ran-booster-access-tokens-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository access', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php echo esc_html( __( 'Saved credentials can be used for repository access.', 'ran-booster' ) ); ?></p>
				</header>
			<div class="ran-booster-provider-section__body">
				<?php
				$status_summary_renderer->render(
					$credential_summary['tone'],
					$credential_summary['heading'],
					$credential_summary['description'],
					static function () use ( $credential_row_count, $credentials_url, $has_credential_settings, $storage_unavailable ): void {
						if ( 0 === $credential_row_count && $has_credential_settings ) {
							?>
							<button type="button" class="button ran-booster-open-credential-modal" data-modal="access"><?php esc_html_e( 'Add credential', 'ran-booster' ); ?></button>
							<?php
						} elseif ( ! $storage_unavailable ) {
							?>
							<a class="button" href="<?php echo esc_url( $credentials_url ); ?>"><?php esc_html_e( 'Manage credentials', 'ran-booster' ); ?></a>
							<?php
						}
					}
				);
			?>
			</div>
		</section>
		<?php } ?>

		<?php if ( null !== $public_lookup_profile ) { ?>
			<?php require __DIR__ . '/provider-public-lookup-profile.php'; ?>
		<?php } ?>

		<?php if ( $has_webhook_settings ) { ?>
			<section class="ran-booster-provider-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-webhook-secrets-heading">
				<header class="ran-booster-provider-section__header">
					<h3 id="ran-booster-webhook-secrets-heading" class="ran-booster-section__title"><?php esc_html_e( 'Signing secrets', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php esc_html_e( 'Signing secrets verify repository webhook deliveries.', 'ran-booster' ); ?></p>
				</header>
				<div class="ran-booster-provider-section__body">
					<?php
					$status_summary_renderer->render(
						$webhook_summary['tone'],
						$webhook_summary['heading'],
						$webhook_summary['description'],
						static function () use ( $secrets_url, $storage_unavailable, $webhook_row_count ): void {
							if ( 0 === $webhook_row_count ) {
								?>
								<button type="button" class="button ran-booster-open-credential-modal" data-modal="webhook"><?php esc_html_e( 'Add webhook secret', 'ran-booster' ); ?></button>
									<?php
							} elseif ( ! $storage_unavailable ) {
								?>
								<a class="button" href="<?php echo esc_url( $secrets_url ); ?>"><?php esc_html_e( 'Manage signing secrets', 'ran-booster' ); ?></a>
									<?php
							}
						}
					);
			?>
				</div>
			</section>
		<?php } ?>

		<?php if ( $repository_integration_available ) { ?>
			<section class="ran-booster-provider-section ran-booster-card" data-ran-booster-card="provider" aria-labelledby="ran-booster-repository-integrations-heading">
				<header class="ran-booster-provider-section__header">
					<h3 id="ran-booster-repository-integrations-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository integrations', 'ran-booster' ); ?></h3>
				<p class="ran-booster-section__description">
						<?php esc_html_e( 'Review managed repositories and available integrations.', 'ran-booster' ); ?>
					</p>
				</header>
				<div class="ran-booster-provider-section__body">
					<div
						id="ran-booster-provider-tasks"
						class="ran-booster-provider-tasks"
						hx-target="#ran-booster-provider-task-panel"
						hx-select="#ran-booster-provider-task-panel"
						hx-swap="outerHTML transition:false show:none"
						hx-push-url="true"
						hx-history="false"
						hx-sync="this:replace"
					>
						<nav class="ran-booster-provider-task-tabs" aria-label="<?php esc_attr_e( 'Repository integration views', 'ran-booster' ); ?>" hx-boost="true" data-ran-booster-ui-navigation="thin">
							<ul class="filter-links ran-section-navigation ran-section-navigation--thin">
							<?php
							foreach ( array(
								'status'       => __( 'Status', 'ran-booster' ),
								'repositories' => __( 'Repositories', 'ran-booster' ),
								'setup'        => __( 'Webhook receiver', 'ran-booster' ),
							) as $task => $label ) {
								?>
								<li><a class="ran-booster-provider-task-tab ran-section-navigation__link<?php echo $provider_task === $task ? ' current' : ''; ?>" href="<?php echo esc_url( $task_urls[ $task ] ); ?>" hx-get="<?php echo esc_url( $task_request_urls[ $task ] ); ?>" data-ran-booster-provider-task="<?php echo esc_attr( $task ); ?>" aria-controls="ran-booster-provider-task-panel" <?php echo $provider_task === $task ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a></li>
							<?php } ?>
							</ul>
							<p class="ran-booster-provider-task-progress" data-ran-booster-provider-task-progress role="status" aria-live="polite" hidden>
								<span class="spinner is-active" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Loading Git provider details…', 'ran-booster' ); ?></span>
							</p>
						</nav>
						<div class="notice notice-error inline ran-booster-provider-task-error" data-ran-booster-provider-task-error role="alert" tabindex="-1" hidden data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error"><div data-ran-booster-ui-notice-content>
							<p><?php esc_html_e( 'Booster could not load that Git provider view. The current view is unchanged; choose the task again to retry.', 'ran-booster' ); ?></p>
						</div></div>

						<?php if ( 'status' === $provider_task ) { ?>
							<section id="ran-booster-provider-task-panel" class="ran-booster-provider-task-panel" data-ran-booster-provider-task="status" aria-labelledby="ran-booster-provider-status-heading">
							<div class="ran-booster-provider-task-panel__heading">
								<div>
									<h4 id="ran-booster-provider-status-heading" class="ran-booster-section__title"><?php esc_html_e( 'Status', 'ran-booster' ); ?></h4>
									<p class="ran-booster-section__description"><?php esc_html_e( 'Local records only; no Git provider request is made.', 'ran-booster' ); ?></p>
								</div>
							</div>
							<?php
							$release_package_count = (int) $repository_integration_summary['release_packages'];
							$workflow_review_count = (int) $repository_integration_summary['release_workflows_needing_review'];
							?>
							<?php if ( ! $repository_inventory_available ) { ?>
								<p><?php esc_html_e( 'Repository inventory is unavailable; shown records may be incomplete.', 'ran-booster' ); ?></p>
							<?php } ?>
							<dl class="ran-booster-repository-integration-status">
								<?php if ( ! empty( $provider['capabilities']['webhooks'] ) ) { ?>
									<div><dt><?php esc_html_e( 'Site webhook delivery', 'ran-booster' ); ?></dt><dd><?php echo esc_html( ! $webhook_assistance_provider_capable ? __( 'Readiness not assessed', 'ran-booster' ) : ( $webhook_assistance_site_ready ? __( 'Ready for public HTTPS delivery', 'ran-booster' ) : __( 'Not ready for Git provider delivery', 'ran-booster' ) ) ); ?></dd></div>
									<?php
									if ( ! $storage_unavailable ) {
										?>
										<div><dt><?php esc_html_e( 'Signing profiles', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d is the number of locally ready signing profiles. */ _n( '%d ready locally', '%d ready locally', $ready_webhook_profile_count, 'ran-booster' ), $ready_webhook_profile_count ) ); ?></dd></div><?php } ?>
								<?php } ?>
								<?php if ( 0 < $release_package_count ) { ?>
									<div><dt><?php esc_html_e( 'Release packages recorded', 'ran-booster' ); ?></dt><dd>
									<?php
										$release_count_label = sprintf( /* translators: %d is the number of recorded release packages. */ _n( '%d package', '%d packages', $release_package_count, 'ran-booster' ), $release_package_count );
										echo esc_html(
											! $repository_inventory_available || ! empty( $repository_integration_summary['release_totals_incomplete'] )
											? sprintf( /* translators: %s is the lower-bound package count. */ __( 'At least %s; exact totals unavailable.', 'ran-booster' ), $release_count_label )
											: $release_count_label
										);
									?>
										</dd></div>
								<?php } ?>
								<?php if ( 0 < $workflow_review_count ) { ?>
									<div><dt><?php esc_html_e( 'Release workflow observations', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d is the number of observations needing review. */ _n( '%d observation shown needs review; coverage may be incomplete.', '%d observations shown need review; coverage may be incomplete.', $workflow_review_count, 'ran-booster' ), $workflow_review_count ) ); ?></dd></div>
								<?php } ?>
							</dl>
							<?php if ( ! empty( $repository_integration_summary['release_workflows_inventory_incomplete'] ) ) { ?>
								<p><?php esc_html_e( 'Release workflow observations are incomplete because some package details are omitted.', 'ran-booster' ); ?></p>
							<?php } ?>
							<?php if ( $webhook_assistance_provider_capable && ! $webhook_assistance_site_ready ) { ?>
								<div class="notice <?php echo esc_attr( $webhook_has_hard_failure ? 'notice-error' : 'notice-warning' ); ?> inline ran-booster-push-deploy__notice" data-ran-booster-assistance-site-notice data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo $webhook_has_hard_failure ? 'error' : 'warning'; ?>">
									<div data-ran-booster-ui-notice-content>
										<p><strong><?php esc_html_e( 'Webhook delivery is not ready.', 'ran-booster' ); ?></strong> <?php echo esc_html( implode( ' ', $webhook_site_reasons ) ); ?></p>
									</div>
								</div>
							<?php } ?>
							<div class="ran-booster-provider-task-actions">
								<a class="button" href="<?php echo esc_url( $task_urls['repositories'] ); ?>" hx-get="<?php echo esc_url( $task_request_urls['repositories'] ); ?>" hx-boost="true"><?php esc_html_e( 'Review repositories', 'ran-booster' ); ?></a>
								<a class="button" href="<?php echo esc_url( $wordpress_urls_url ); ?>"><?php esc_html_e( 'Review WordPress URLs', 'ran-booster' ); ?></a>
								<a class="button" href="<?php echo esc_url( $activity_url ); ?>"><?php esc_html_e( 'View Activity', 'ran-booster' ); ?></a>
							</div>
					</section>
				<?php } elseif ( 'setup' === $provider_task ) { ?>
					<section id="ran-booster-provider-task-panel" class="ran-booster-provider-task-panel" data-ran-booster-provider-task="setup" aria-labelledby="ran-booster-webhook-instructions-heading">
							<?php if ( empty( $provider['capabilities']['webhooks'] ) ) { ?>
								<h4 id="ran-booster-webhook-instructions-heading" class="ran-booster-section__title"><?php esc_html_e( 'Webhook receiver', 'ran-booster' ); ?></h4>
								<p><?php esc_html_e( 'Webhook receiver guidance is unavailable for this provider.', 'ran-booster' ); ?></p>
							<?php } else { ?>
							<div class="ran-booster-provider-task-panel__heading">
								<div>
									<h4 id="ran-booster-webhook-instructions-heading" class="ran-booster-section__title"><?php esc_html_e( 'Webhook receiver', 'ran-booster' ); ?></h4>
									<p class="ran-booster-section__description"><?php esc_html_e( 'This Git provider uses one shared receiver on this site. Configure and check each repository from Repositories.', 'ran-booster' ); ?></p>
								</div>
							</div>
								<div class="notice notice-warning inline" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning">
									<div data-ran-booster-ui-notice-content>
										<p><strong><?php esc_html_e( 'Webhook signatures authorize deployment; they do not protect your host from traffic.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Use a unique generated repository secret, rotate or disable a suspected secret, and do not cache, challenge or transform this callback. For timeouts and failed responses, compare Git provider delivery history with the Git provider request ID in Booster Activity.', 'ran-booster' ); ?> <a href="<?php echo esc_url( $webhook_operations_url ); ?>"><?php esc_html_e( 'Read the webhook operations guide', 'ran-booster' ); ?></a>.</p>
									</div>
								</div>
							<dl class="ran-booster-webhook-endpoint">
								<div>
									<dt id="ran-booster-webhook-url-label"><?php esc_html_e( 'Payload URL', 'ran-booster' ); ?></dt>
									<dd><span class="ran-booster-webhook-url" data-webhook-url-tools><input type="text" class="regular-text code" value="<?php echo esc_attr( $webhook_endpoint ); ?>" readonly aria-labelledby="ran-booster-webhook-url-label" data-webhook-url><button type="button" class="button ran-booster-copy-value__button hide-if-no-js" data-webhook-url-copy data-copy-label="<?php esc_attr_e( 'Copy payload URL', 'ran-booster' ); ?>" data-copied-label="<?php esc_attr_e( 'URL copied', 'ran-booster' ); ?>" aria-label="<?php esc_attr_e( 'Copy payload URL', 'ran-booster' ); ?>" title="<?php esc_attr_e( 'Copy payload URL', 'ran-booster' ); ?>"><svg class="ran-booster-copy-value__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg><svg class="ran-booster-copy-value__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 12 4 4L19 6"/></svg></button><span class="ran-booster-portability__password-status" data-webhook-url-status data-copied-message="<?php esc_attr_e( 'Payload URL copied to the clipboard.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The payload URL is selected; use your browser copy command.', 'ran-booster' ); ?>" role="status" aria-live="polite" aria-atomic="true"></span></span></dd>
								</div>
								<div><dt><?php esc_html_e( 'Content type', 'ran-booster' ); ?></dt><dd><code>application/json</code></dd></div>
								<?php
								if ( null !== $webhook_setup ) {
									?>
									<div><dt><?php esc_html_e( 'Event', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $webhook_setup['event'] ); ?></dd></div><?php } ?>
							</dl>
								<?php if ( null !== $webhook_setup ) { ?>
								<details class="ran-booster-provider-disclosure ran-booster-disclosure">
									<summary><?php esc_html_e( 'Detailed manual setup and troubleshooting', 'ran-booster' ); ?></summary>
									<div class="ran-booster-provider-disclosure__body ran-booster-disclosure__body">
										<p><?php echo esc_html( $manual_setup_description ); ?></p>
										<ol><li><?php esc_html_e( 'Paste the payload URL and a matching signing secret.', 'ran-booster' ); ?></li><li><?php esc_html_e( 'Keep SSL verification enabled and select the push event.', 'ran-booster' ); ?></li><li><?php esc_html_e( 'Trigger a delivery and confirm the Git provider records a successful response.', 'ran-booster' ); ?></li></ol>
										<p><a href="<?php echo esc_url( $webhook_setup['documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $provider_instructions_label ); ?> <span aria-hidden="true">↗</span><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span></a></p>
										<?php if ( $webhook_setup['documentation_url'] !== $webhook_setup['delivery_documentation_url'] ) { ?>
											<p><a href="<?php echo esc_url( $webhook_setup['delivery_documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Delivery troubleshooting', 'ran-booster' ); ?> <span aria-hidden="true">↗</span><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span></a></p>
										<?php } ?>
									</div>
								</details>
							<?php } else { ?>
								<p><a href="<?php echo esc_url( $task_urls['repositories'] ); ?>" hx-get="<?php echo esc_url( $task_request_urls['repositories'] ); ?>" hx-boost="true"><?php esc_html_e( 'Manage repositories', 'ran-booster' ); ?></a></p>
							<?php } ?>
							<?php } ?>
				</section>
				<?php } else { ?>
					<section id="ran-booster-provider-task-panel" class="ran-booster-provider-task-panel" data-ran-booster-provider-task="repositories" aria-labelledby="ran-booster-managed-webhook-repositories-heading">
						<?php if ( '' === $requested_repository_id ) { ?>
						<div class="ran-booster-provider-task-panel__heading">
							<div>
								<h4 id="ran-booster-managed-webhook-repositories-heading" class="ran-booster-section__title"><?php esc_html_e( 'Managed repositories', 'ran-booster' ); ?></h4>
								<p class="ran-booster-section__description"><?php echo esc_html( $repository_webhook_description ); ?></p>
							</div>
						</div>
						<?php } ?>
							<?php if ( '' !== $requested_repository_id ) { ?>
								<?php if ( is_array( $selected_repository_row ) ) { ?>
									<?php
									$has_branch_consumer = ! empty( $selected_repository_row['has_branch_consumer'] );
									$repository_detail_renderer->render(
										$selected_repository_row,
										$provider['label'],
										$repository_list_url,
										$activity_url,
										$webhook_assistance_site_ready,
										$webhook_assistance_site_ready ? __( 'This site can receive Git provider webhook deliveries.', 'ran-booster' ) : implode( ' ', $webhook_site_reasons ),
										$repository_view,
										$repository_view_urls,
										$repository_view_request_urls,
										null !== $webhook_management && $webhook_management->supports_provider( $provider['code'] )
										? static function () use ( $webhook_management, $provider, $requested_repository_id, $provider_return_url, $repository_view_urls, $has_branch_consumer, $selected_repository_row ): bool|string {
											$return_url = is_string( $repository_view_urls['branch'] ?? null ) ? $repository_view_urls['branch'] : $provider_return_url;
											return $webhook_management->render_repository_webhook_setup( $provider['code'], $requested_repository_id, $return_url, $has_branch_consumer, (string) ( $selected_repository_row['repository'] ?? '' ) ) ?? true;
										}
											: null,
										static function () use ( $selected_repository_row, $provider_return_url, $repository_view_urls ): void {
											$return_url = is_string( $repository_view_urls['releases'] ?? null ) ? $repository_view_urls['releases'] : $provider_return_url;
											do_action( 'ran_booster_admin_repository_release_sections', $selected_repository_row, $return_url );
										}
									);
									?>
								<?php } else { ?>
									<div class="notice notice-error inline" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error"><div data-ran-booster-ui-notice-content><p><?php esc_html_e( 'That managed repository is no longer available. Return to the repository list and choose a current repository.', 'ran-booster' ); ?></p></div></div>
								<?php } ?>
							<?php } elseif ( array() !== $repository_table_rows ) { ?>
								<div class="ran-booster-provider-repository-tools">
									<label class="screen-reader-text" for="ran-booster-provider-repository-search"><?php esc_html_e( 'Search managed repositories', 'ran-booster' ); ?></label>
									<input id="ran-booster-provider-repository-search" type="search" placeholder="<?php esc_attr_e( 'Search managed repositories…', 'ran-booster' ); ?>" data-ran-booster-provider-repository-filter>
									<span
										data-ran-booster-provider-repository-count
										aria-live="polite"
									>
										<?php echo esc_html( $repository_row_count_label ); ?>
									</span>
								</div>
								<?php $repository_table_renderer->render( 'ran-booster-managed-webhook-repositories-heading', $repository_table_rows ); ?>
							<?php } elseif ( $repository_inventory_available ) { ?>
								<div class="ran-booster-provider-empty-actions">
									<p><?php echo esc_html( $empty_repository_description ); ?></p>
									<a class="button button-primary" href="<?php echo esc_url( $install_plugin_url ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
									<a class="button" href="<?php echo esc_url( $install_theme_url ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a>
								</div>
							<?php } else { ?>
								<p class="description"><?php esc_html_e( 'Managed repository status is temporarily unavailable.', 'ran-booster' ); ?></p>
							<?php } ?>
				</section>
			<?php } ?>
					</div>
				</div>
			</section>
		<?php } ?>
	<?php } elseif ( 'credentials' === $provider_view ) { ?>
		<?php if ( $has_credential_settings ) { ?>
			<form class="ran-booster-provider-list-controls" method="get" action="<?php echo esc_url( $provider_list_action_url ); ?>">
				<input type="hidden" name="page" value="ran-booster"><input type="hidden" name="tab" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="view" value="credentials">
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $provider_list_state['orderby'] ); ?>"><input type="hidden" name="order" value="<?php echo esc_attr( $provider_list_state['order'] ); ?>"><input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $provider_list_state['per_page'] ); ?>">
				<div>
					<label class="screen-reader-text" for="ran-booster-credential-kind-filter"><?php esc_html_e( 'Filter by credential type', 'ran-booster' ); ?></label>
					<select id="ran-booster-credential-kind-filter" name="kind"><option value=""><?php esc_html_e( 'All credential types', 'ran-booster' ); ?></option>
					<?php
					foreach ( $provider['credential_kinds'] as $kind ) {
						?>
						<option value="<?php echo esc_attr( $kind['code'] ); ?>" <?php selected( $kind['code'], $provider_list_state['kind'] ); ?>><?php echo esc_html( $kind['label'] ); ?></option><?php } ?></select>
					<label class="screen-reader-text" for="ran-booster-credential-scope-filter"><?php esc_html_e( 'Filter by scope', 'ran-booster' ); ?></label>
					<select id="ran-booster-credential-scope-filter" name="scope"><option value=""><?php esc_html_e( 'All scopes', 'ran-booster' ); ?></option>
					<?php
					foreach ( $credential_scopes as $scope_key => $scope_label ) {
						?>
						<option value="<?php echo esc_attr( $scope_key ); ?>" <?php selected( $scope_key, $provider_list_state['scope'] ); ?>><?php echo esc_html( $scope_label ); ?></option><?php } ?></select>
				</div>
				<div><label class="screen-reader-text" for="ran-booster-credential-search"><?php esc_html_e( 'Search credentials', 'ran-booster' ); ?></label><input id="ran-booster-credential-search" type="search" name="s" value="<?php echo esc_attr( $provider_list_state['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search credentials…', 'ran-booster' ); ?>"><button class="button" type="submit"><?php esc_html_e( 'Search', 'ran-booster' ); ?></button></div>
			</form>
			<?php
				$provider_management_table_renderer->render(
					\RAN\Admin\Component\ProviderManagementTableRenderer::ACCESS,
					$credential_list['rows'],
					__( 'No credentials match the current filters.', 'ran-booster' ),
					$render_credential_cell,
					$credential_sort_urls,
					$credential_pagination
				);
			?>
		<?php } ?>
	<?php } elseif ( 'secrets' === $provider_view ) { ?>
		<?php if ( $has_webhook_settings ) { ?>
			<div id="ran-booster-delete-webhook-profile-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error" data-ran-booster-ui-notice-scope="global"><div data-ran-booster-ui-notice-content><p data-ran-booster-ui-notice-primary></p></div></div>
			<form class="ran-booster-provider-list-controls" method="get" action="<?php echo esc_url( $provider_list_action_url ); ?>"><input type="hidden" name="page" value="ran-booster"><input type="hidden" name="tab" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="view" value="secrets"><input type="hidden" name="orderby" value="<?php echo esc_attr( $provider_list_state['orderby'] ); ?>"><input type="hidden" name="order" value="<?php echo esc_attr( $provider_list_state['order'] ); ?>"><input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $provider_list_state['per_page'] ); ?>"><div><label class="screen-reader-text" for="ran-booster-secret-scope-filter"><?php esc_html_e( 'Filter by scope', 'ran-booster' ); ?></label><select id="ran-booster-secret-scope-filter" name="scope"><option value=""><?php esc_html_e( 'All scopes', 'ran-booster' ); ?></option>
			<?php
			foreach ( $provider['webhook_scopes'] as $scope ) {
				?>
				<option value="<?php echo esc_attr( $scope['code'] ); ?>" <?php selected( $scope['code'], $provider_list_state['scope'] ); ?>><?php echo esc_html( $scope['label'] ); ?></option><?php } ?></select><label class="screen-reader-text" for="ran-booster-secret-status-filter"><?php esc_html_e( 'Filter by status', 'ran-booster' ); ?></label><select id="ran-booster-secret-status-filter" name="status"><option value=""><?php esc_html_e( 'All statuses', 'ran-booster' ); ?></option><option value="ready" <?php selected( 'ready', $provider_list_state['status'] ); ?>><?php esc_html_e( 'Ready locally', 'ran-booster' ); ?></option><option value="attention" <?php selected( 'attention', $provider_list_state['status'] ); ?>><?php esc_html_e( 'Needs attention', 'ran-booster' ); ?></option></select></div><div><label class="screen-reader-text" for="ran-booster-secret-search"><?php esc_html_e( 'Search webhook secrets', 'ran-booster' ); ?></label><input id="ran-booster-secret-search" type="search" name="s" value="<?php echo esc_attr( $provider_list_state['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search secrets…', 'ran-booster' ); ?>"><button class="button" type="submit"><?php esc_html_e( 'Search', 'ran-booster' ); ?></button></div></form>
			<?php
			$provider_management_table_renderer->render(
				\RAN\Admin\Component\ProviderManagementTableRenderer::WEBHOOK,
				$webhook_list['rows'],
				__( 'No webhook secrets match the current filters.', 'ran-booster' ),
				$render_webhook_cell,
				$webhook_sort_urls,
				$webhook_pagination
			);
			?>
		<?php } ?>
	<?php } ?>

</section>

<?php require __DIR__ . '/provider/modals.php'; ?>
</div>
