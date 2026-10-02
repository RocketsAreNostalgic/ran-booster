<?php

/**
 * Provider page inputs assembled by Dashboard::get_index(), ProviderSettingsPresenter
 * and ProviderRepositoryRowsNormalizer.
 *
 * @var string $activity_url
 * @var string $credential_management_description
 * @var string $credentials_url
 * @var string $delete_webhook_interaction_values
 * @var string $empty_repository_description
 * @var string $install_plugin_url
 * @var string $install_theme_url
 * @var string $manual_setup_description
 * @var string $overview_url
 * @var string $provider_back_label
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
 * @var array<string, mixed> $credential_pagination
 * @var array<string, mixed> $provider
 * @var array<string, mixed> $provider_list_state
 * @var array<string, mixed> $webhook_pagination
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

$render_credential_cell = static function ( array $profile, string $column ) use ( $package_type_labels, $provider, $provider_mutation_fields ): void {
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
		echo esc_html( $profile['health_label'] );

		return;
	}

	$usage             = $profile['usage'];
	$usage_template_id = 'ran-booster-delete-credential-usage-' . (string) $profile['profile_index'];
	if ( $profile['configured'] && $provider['capabilities']['credentials'] ) {
		?>
		<form method="post" action="" class="ran-booster-inline-form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" hx-post="" hx-target="#ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" hx-swap="outerHTML" hx-sync="this:drop">
		<?php
		foreach ( $provider_mutation_fields as $name => $value ) {
			?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php } ?><input type="hidden" name="ran_booster[action]" value="validate-access-profile"><input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="ran_booster[id]" value="<?php echo esc_attr( $profile['id'] ); ?>"><button type="submit" class="button"><?php esc_html_e( 'Validate', 'ran-booster' ); ?></button></form><div id="ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>
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
	<button type="button" class="button ran-booster-open-credential-modal" data-modal="webhook" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-scope="<?php echo esc_attr( $profile['scope'] ); ?>" data-target="<?php echo esc_attr( $profile['target'] ); ?>"><?php esc_html_e( 'Edit', 'ran-booster' ); ?></button><form method="post" action="" class="ran-booster-inline-form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-delete-webhook-profile-error" data-ran-booster-interaction-operation="core:delete-webhook-profile" hx-post="" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop" hx-vals="<?php echo esc_attr( $delete_webhook_interaction_values ); ?>">
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
<section class="ran-booster-page-shell ran-booster-provider<?php echo $is_repository_detail ? ' ran-booster-repository-page' : ' ran-booster-panel'; ?>" aria-labelledby="ran-booster-provider-heading">
	<?php if ( 'overview' === $provider_view && ! $is_repository_detail ) { ?>
		<header class="ran-booster-page-shell__header ran-booster-provider__header">
			<p class="ran-booster-provider__eyebrow ran-booster-eyebrow"><?php esc_html_e( 'Repository provider', 'ran-booster' ); ?></p>
			<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title" data-ran-booster-provider-profile-focus tabindex="-1"><?php echo esc_html( $provider['label'] ); ?></h2>
			<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Manage repository access, integrations and the packages connected to this site.', 'ran-booster' ); ?></p>
		</header>
	<?php } elseif ( 'overview' !== $provider_view ) { ?>
		<a class="ran-booster-provider-management__back" href="<?php echo esc_url( $overview_url ); ?>">&larr; <?php echo esc_html( $provider_back_label ); ?></a>
		<header class="ran-booster-provider-management__header">
			<div>
				<p class="ran-booster-provider__eyebrow ran-booster-eyebrow"><?php echo esc_html( 'credentials' === $provider_view ? __( 'Repository access', 'ran-booster' ) : __( 'Push-to-Deploy prerequisite', 'ran-booster' ) ); ?></p>
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

	<?php if ( $storage_unavailable && ! $is_repository_detail ) { ?>
		<div class="notice notice-error inline ran-booster-provider__notice" data-ran-booster-provider-storage-notice>
			<p><strong><?php esc_html_e( 'Encrypted credential storage is unavailable.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Restore the matching sidecar and site key from the same backup before changing credentials.', 'ran-booster' ); ?></p>
		</div>
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
				$webhook_assistance_site_ready ? __( 'This site can receive provider webhook deliveries.', 'ran-booster' ) : implode( ' ', $webhook_site_reasons ),
				$repository_view,
				$repository_view_urls,
				$repository_view_request_urls,
				null !== $webhook_management && $webhook_management->has_management_capability( $provider['code'] )
					? static function () use ( $webhook_management, $provider, $requested_repository_id, $provider_return_url, $repository_view_urls, $has_branch_consumer, $selected_repository_row ): void {
						$return_url = is_string( $repository_view_urls['branch'] ?? null ) ? $repository_view_urls['branch'] : $provider_return_url;
						$webhook_management->render_repository_webhook_setup( $provider['code'], $requested_repository_id, $return_url, $has_branch_consumer, (string) ( $selected_repository_row['repository'] ?? '' ) );
					}
					: null,
				static function () use ( $selected_repository_row, $provider_return_url, $repository_view_urls ): void {
					$return_url = is_string( $repository_view_urls['releases'] ?? null ) ? $repository_view_urls['releases'] : $provider_return_url;
					do_action( 'ran_booster_admin_repository_release_sections', $selected_repository_row, $return_url );
				}
			);
			?>
		<?php } else { ?>
			<p><a href="<?php echo esc_url( $repository_list_url ); ?>">&larr; <?php esc_html_e( 'Back to repositories', 'ran-booster' ); ?></a></p>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'That managed repository is no longer available. Return to the repository list and choose a current repository.', 'ran-booster' ); ?></p></div>
		<?php } ?>
	<?php } elseif ( 'overview' === $provider_view ) { ?>
		<?php if ( ! empty( $provider['credential_kinds'] ) ) { ?>
		<section class="ran-booster-provider-section" aria-labelledby="ran-booster-access-tokens-heading">
				<header class="ran-booster-provider-section__header">
					<h3 id="ran-booster-access-tokens-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository access', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php echo esc_html( $provider['capabilities']['browse'] ? __( 'Saved credentials enable private repository access; public repository discovery does not require one.', 'ran-booster' ) : __( 'Saved credentials enable access to private repositories entered manually.', 'ran-booster' ) ); ?></p>
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
			<section class="ran-booster-provider-section" aria-labelledby="ran-booster-webhook-secrets-heading">
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
						static function () use ( $has_webhook_settings, $secrets_url, $storage_unavailable, $webhook_row_count ): void {
							if ( 0 === $webhook_row_count && $has_webhook_settings ) {
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
			<section class="ran-booster-provider-section" aria-labelledby="ran-booster-repository-integrations-heading">
				<header class="ran-booster-provider-section__header">
					<h3 id="ran-booster-repository-integrations-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository integrations', 'ran-booster' ); ?></h3>
				<p class="ran-booster-section__description">
						<?php esc_html_e( 'Manage repositories, webhooks and release workflows.', 'ran-booster' ); ?>
					</p>
				</header>
				<div class="ran-booster-provider-section__body">
					<div
						id="ran-booster-provider-tasks"
						class="ran-booster-provider-tasks"
						hx-target="#ran-booster-provider-task-panel"
						hx-select="#ran-booster-provider-task-panel"
						hx-swap="outerHTML transition:true show:none"
						hx-push-url="true"
						hx-history="false"
						hx-sync="this:replace"
					>
						<nav class="ran-booster-provider-task-tabs" aria-label="<?php esc_attr_e( 'Repository integration views', 'ran-booster' ); ?>" hx-boost="true">
							<?php
							foreach ( array(
								'status'       => __( 'Status', 'ran-booster' ),
								'repositories' => __( 'Repositories', 'ran-booster' ),
								'setup'        => __( 'Webhook receiver', 'ran-booster' ),
							) as $task => $label ) {
								?>
								<a class="ran-booster-provider-task-tab" href="<?php echo esc_url( $task_urls[ $task ] ); ?>" hx-get="<?php echo esc_url( $task_request_urls[ $task ] ); ?>" data-ran-booster-provider-task="<?php echo esc_attr( $task ); ?>" aria-controls="ran-booster-provider-task-panel" <?php echo $provider_task === $task ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
							<?php } ?>
							<p class="ran-booster-provider-task-progress" data-ran-booster-provider-task-progress role="status" aria-live="polite" hidden>
								<span class="spinner is-active" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Loading provider details…', 'ran-booster' ); ?></span>
							</p>
						</nav>
						<div class="notice notice-error inline ran-booster-provider-task-error" data-ran-booster-provider-task-error role="alert" tabindex="-1" hidden>
							<p><?php esc_html_e( 'Booster could not load that provider view. The current view is unchanged; choose the task again to retry.', 'ran-booster' ); ?></p>
						</div>

						<?php if ( 'status' === $provider_task ) { ?>
							<section id="ran-booster-provider-task-panel" class="ran-booster-provider-task-panel" data-ran-booster-provider-task="status" aria-labelledby="ran-booster-provider-status-heading">
							<div class="ran-booster-provider-task-panel__heading">
								<div>
									<h4 id="ran-booster-provider-status-heading" class="ran-booster-section__title"><?php esc_html_e( 'Status', 'ran-booster' ); ?></h4>
									<p class="ran-booster-section__description"><?php esc_html_e( 'Local records only; no provider request is made.', 'ran-booster' ); ?></p>
								</div>
							</div>
							<?php
							$release_package_count    = (int) $repository_integration_summary['release_packages'];
							$release_repository_count = (int) $repository_integration_summary['release_repositories'];
							/* translators: %d is the number of packages using releases. */
							$release_package_count_label = sprintf( _nx( '%d package', '%d packages', $release_package_count, 'Packages using releases', 'ran-booster' ), $release_package_count );
							/* translators: %d is the number of repositories. */
							$release_repository_label  = sprintf( _n( '%d repository', '%d repositories', $release_repository_count, 'ran-booster' ), $release_repository_count );
							$published_release_summary = true === ( $repository_integration_summary['release_totals_incomplete'] ?? false )
								? sprintf(
									/* translators: 1: lower-bound package count label, 2: repository count label. */
									__( 'At least %1$s across %2$s · exact totals unavailable while a repository inventory is incomplete', 'ran-booster' ),
									$release_package_count_label,
									$release_repository_label
								)
								: ( true === ( $repository_integration_summary['release_workflows_inventory_incomplete'] ?? false )
									? sprintf(
										/* translators: 1: number of Published release packages, 2: number of repositories. */
										__( '%1$d packages in %2$d repositories · workflow state unavailable while a repository inventory is incomplete', 'ran-booster' ),
										$repository_integration_summary['release_packages'],
										$repository_integration_summary['release_repositories']
									)
									: sprintf(
										/* translators: 1: number of Published release packages, 2: number of repositories, 3: number of release workflows needing review. */
										__( '%1$d packages in %2$d repositories · %3$d workflows need review', 'ran-booster' ),
										$repository_integration_summary['release_packages'],
										$repository_integration_summary['release_repositories'],
										$repository_integration_summary['release_workflows_needing_review']
									)
								);
							?>
							<dl class="ran-booster-repository-integration-status">
								<div><dt><?php esc_html_e( 'Site webhook delivery', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $webhook_assistance_site_ready ? __( 'Ready for public HTTPS delivery', 'ran-booster' ) : __( 'Not ready for provider delivery', 'ran-booster' ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Signing profiles', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d is the number of locally ready signing profiles. */ _n( '%d ready locally', '%d ready locally', $ready_webhook_profile_count, 'ran-booster' ), $ready_webhook_profile_count ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Repository hooks', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: number of locally recorded hooks, 2: number of hook records needing review. */ __( '%1$d recorded locally · %2$d need review', 'ran-booster' ), $repository_integration_summary['recorded_hooks'], $repository_integration_summary['needs_review'] ) ); ?></dd></div>
								<div><dt><?php echo esc_html( _x( 'Releases', 'Provider integration status label', 'ran-booster' ) ); ?></dt><dd><?php echo esc_html( $published_release_summary ); ?></dd></div>
							</dl>
							<?php if ( ! $webhook_assistance_provider_capable || ! $webhook_assistance_site_ready ) { ?>
								<div class="notice <?php echo esc_attr( $webhook_has_hard_failure ? 'notice-error' : 'notice-warning' ); ?> inline ran-booster-push-deploy__notice" data-ran-booster-assistance-site-notice>
									<p><strong><?php esc_html_e( 'Webhook delivery is not ready.', 'ran-booster' ); ?></strong> <?php echo esc_html( $webhook_assistance_provider_capable ? implode( ' ', $webhook_site_reasons ) : sprintf( /* translators: %s is the repository provider name. */ __( '%s does not provide Booster with assisted webhook management. Repository inventory remains available.', 'ran-booster' ), $provider['label'] ) ); ?></p>
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
							<div class="ran-booster-provider-task-panel__heading">
								<div>
									<h4 id="ran-booster-webhook-instructions-heading" class="ran-booster-section__title"><?php esc_html_e( 'Webhook receiver', 'ran-booster' ); ?></h4>
									<p class="ran-booster-section__description"><?php esc_html_e( 'This provider uses one shared receiver on this site. Configure and check each repository from Repositories.', 'ran-booster' ); ?></p>
								</div>
								<?php if ( null !== $webhook_setup ) { ?>
									<a class="button" href="<?php echo esc_url( $webhook_setup['documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $provider_instructions_label ); ?></a>
								<?php } ?>
							</div>
							<p><a class="button" href="<?php echo esc_url( $task_urls['repositories'] ); ?>" hx-get="<?php echo esc_url( $task_request_urls['repositories'] ); ?>" hx-boost="true"><?php esc_html_e( 'Manage repositories', 'ran-booster' ); ?></a></p>
								<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Webhook signatures authorize deployment; they do not protect your host from traffic.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Use a unique generated repository secret, rotate or disable a suspected secret, and do not cache, challenge or transform this callback. For timeouts and failed responses, compare provider delivery history with the Provider request ID in Booster Activity.', 'ran-booster' ); ?> <a href="<?php echo esc_url( $webhook_operations_url ); ?>"><?php esc_html_e( 'Read the webhook operations guide', 'ran-booster' ); ?></a>.</p></div>
							<dl class="ran-booster-webhook-endpoint">
								<div>
									<dt id="ran-booster-webhook-url-label"><?php esc_html_e( 'Payload URL', 'ran-booster' ); ?></dt>
									<dd><span class="ran-booster-webhook-url" data-webhook-url-tools><input type="text" class="regular-text code" value="<?php echo esc_attr( $webhook_endpoint ); ?>" readonly aria-labelledby="ran-booster-webhook-url-label" data-webhook-url><button type="button" class="button" data-webhook-url-copy data-copy-label="<?php esc_attr_e( 'Copy URL', 'ran-booster' ); ?>" data-copied-label="<?php esc_attr_e( 'URL copied', 'ran-booster' ); ?>"><?php esc_html_e( 'Copy URL', 'ran-booster' ); ?></button><span class="ran-booster-portability__password-status" data-webhook-url-status data-copied-message="<?php esc_attr_e( 'Payload URL copied to the clipboard.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The payload URL is selected; use your browser copy command.', 'ran-booster' ); ?>" role="status" aria-live="polite" aria-atomic="true"></span></span></dd>
								</div>
								<div><dt><?php esc_html_e( 'Content type', 'ran-booster' ); ?></dt><dd><code>application/json</code></dd></div>
								<?php
								if ( null !== $webhook_setup ) {
									?>
									<div><dt><?php esc_html_e( 'Event', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $webhook_setup['event'] ); ?></dd></div><?php } ?>
							</dl>
							<?php if ( null !== $webhook_setup ) { ?>
								<details class="ran-booster-provider-disclosure">
									<summary><?php esc_html_e( 'Detailed manual setup and troubleshooting', 'ran-booster' ); ?></summary>
									<div class="ran-booster-provider-disclosure__body">
										<p><?php echo esc_html( $manual_setup_description ); ?></p>
										<ol><li><?php esc_html_e( 'Paste the payload URL and a matching signing secret.', 'ran-booster' ); ?></li><li><?php esc_html_e( 'Keep SSL verification enabled and select the push event.', 'ran-booster' ); ?></li><li><?php esc_html_e( 'Trigger a delivery and confirm the provider records a successful response.', 'ran-booster' ); ?></li></ol>
										<p><a href="<?php echo esc_url( $webhook_setup['delivery_documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Delivery troubleshooting', 'ran-booster' ); ?></a></p>
									</div>
								</details>
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
										$webhook_assistance_site_ready ? __( 'This site can receive provider webhook deliveries.', 'ran-booster' ) : implode( ' ', $webhook_site_reasons ),
										$repository_view,
										$repository_view_urls,
										$repository_view_request_urls,
										null !== $webhook_management && $webhook_management->supports_provider( $provider['code'] )
										? static function () use ( $webhook_management, $provider, $requested_repository_id, $provider_return_url, $repository_view_urls, $has_branch_consumer, $selected_repository_row ): bool {
											$return_url = is_string( $repository_view_urls['branch'] ?? null ) ? $repository_view_urls['branch'] : $provider_return_url;
											$webhook_management->render_repository_webhook_setup( $provider['code'], $requested_repository_id, $return_url, $has_branch_consumer, (string) ( $selected_repository_row['repository'] ?? '' ) );

											return true;
										}
											: null,
										static function () use ( $selected_repository_row, $provider_return_url, $repository_view_urls ): void {
											$return_url = is_string( $repository_view_urls['releases'] ?? null ) ? $repository_view_urls['releases'] : $provider_return_url;
											do_action( 'ran_booster_admin_repository_release_sections', $selected_repository_row, $return_url );
										}
									);
									?>
								<?php } else { ?>
									<p><a href="<?php echo esc_url( $repository_list_url ); ?>">&larr; <?php esc_html_e( 'Back to repositories', 'ran-booster' ); ?></a></p>
									<div class="notice notice-error inline"><p><?php esc_html_e( 'That managed repository is no longer available. Return to the repository list and choose a current repository.', 'ran-booster' ); ?></p></div>
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
							<?php } elseif ( ! empty( $managed_repositories['available'] ) ) { ?>
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
			<div id="ran-booster-delete-webhook-profile-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>
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

	<?php if ( ! $is_repository_detail && ( $has_credential_settings || $provider_has_webhook_settings ) ) { ?>
		<footer class="ran-booster-provider__footer"><p class="description ran-booster-secrets-location"><?php esc_html_e( 'Saved credentials use Booster encrypted local storage outside the plugin directory and WordPress database. Deployment constants appear as immutable profiles and always take precedence.', 'ran-booster' ); ?></p></footer>
	<?php } ?>
</section>

<?php require __DIR__ . '/provider/modals.php'; ?>
</div>
