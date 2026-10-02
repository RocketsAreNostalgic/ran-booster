<?php

/**
 * PackagePagePresenter projection passed through Dashboard::render().
 *
 * @var list<array<string, mixed>> $package_providers
 * @var \RAN\Admin\PackagePagePresenter $package_view
 * @var list<\RAN\Plugin|\RAN\Theme> $packages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$package_providers_by_code    = array_column( $package_providers, null, 'code' );
$package_activity_payload     = isset( $package_activity ) && is_array( $package_activity ) ? $package_activity : array();
$package_activity_unavailable = true === ( $package_activity_payload['unavailable'] ?? false );
$package_activity             = isset( $package_activity_payload['items'] ) && is_array( $package_activity_payload['items'] )
	? $package_activity_payload['items']
	: $package_activity_payload;
$package_extension_rows       = isset( $package_extension_rows ) && is_array( $package_extension_rows )
	? $package_extension_rows
	: array();
$package_extension_actions    = isset( $package_extension_actions ) && is_array( $package_extension_actions )
	? $package_extension_actions
	: array();
$package_list_state           = isset( $package_list_state ) && is_array( $package_list_state )
	? array_merge(
		array(
			'search'   => '',
			'provider' => '',
			'source'   => '',
			'policy'   => '',
		),
		$package_list_state
	)
	: array(
		'search'   => '',
		'provider' => '',
		'source'   => '',
		'policy'   => '',
	);
$package_list_total           = isset( $package_list_total ) && is_int( $package_list_total )
	? max( count( $packages ), $package_list_total )
	: count( $packages );
$package_provider_options     = isset( $package_provider_options ) && is_array( $package_provider_options )
	? $package_provider_options
	: array();
$extension_action_renderer    = new \RAN\Admin\Component\AdminActionRenderer();
$package_admin_url            = $package_view->get_admin_url();
$activity_detail_base_url     = add_query_arg(
	array(
		'page'  => 'ran-booster',
		'tab'   => 'troubleshooting',
		'panel' => 'activity',
	),
	$package_admin_url
);
$activity_state_labels        = array(
	'queued'          => __( 'Queued', 'ran-booster' ),
	'running'         => __( 'Running', 'ran-booster' ),
	'succeeded'       => __( 'Succeeded', 'ran-booster' ),
	'failed'          => __( 'Failed', 'ran-booster' ),
	'needs_attention' => __( 'Needs attention', 'ran-booster' ),
);
$activity_badge_variants      = array(
	'queued'          => 'pending',
	'running'         => 'pending',
	'succeeded'       => 'ok',
	'failed'          => 'error',
	'needs_attention' => 'error',
);
$bulk_form_id                 = 'ran-booster-' . $package_view->get_type() . '-bulk-form';
$bulk_action_id               = 'ran-booster-' . $package_view->get_type() . '-bulk-action';
$bulk_select_all_id           = 'ran-booster-' . $package_view->get_type() . '-select-all';
$bulk_type_label              = strtolower( $package_view->get_plural_label() );
$bulk_type_singular           = strtolower( $package_view->get_singular_label() );
$is_plugin_list               = 'plugin' === $package_view->get_type();
$install_another_url          = add_query_arg( 'page', $package_view->get_create_page_slug(), $package_admin_url );
$clear_filters_url            = add_query_arg( 'page', $package_view->get_page_slug(), $package_admin_url );
$has_package_list_filters     = array() !== array_filter(
	$package_list_state,
	static fn ( mixed $value ): bool => is_string( $value ) && '' !== $value
);
$filtered_package_count       = count( $packages );
$package_list_count_label     = $has_package_list_filters
	? sprintf(
		/* translators: 1: filtered item count, 2: total item count. */
		_n( '%1$d of %2$d item', '%1$d of %2$d items', $package_list_total, 'ran-booster' ),
		$filtered_package_count,
		$package_list_total
	)
	: sprintf(
		/* translators: %d: item count. */
		_n( '%d item', '%d items', $package_list_total, 'ran-booster' ),
		$package_list_total
	);
$policy_labels = array(
	\RAN\Deployment\DeploymentPolicy::DISABLED->value  => __( 'Disabled', 'ran-booster' ),
	\RAN\Deployment\DeploymentPolicy::MANUAL->value    => __( 'Manual', 'ran-booster' ),
	\RAN\Deployment\DeploymentPolicy::AUTOMATIC->value => __( 'Automatic', 'ran-booster' ),
);

?><h2 class="wp-heading-inline ran-booster-package-heading"><?php echo esc_html( sprintf( /* translators: %s: Managed package type plural label, such as Plugins or Themes. */ __( 'Managed %s', 'ran-booster' ), $package_view->get_plural_label() ) ); ?></h2>
<?php if ( $package_list_total > 0 ) { ?>
	<a class="page-title-action" href="<?php echo esc_url( $install_another_url ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Install another %s', 'ran-booster' ), $package_view->get_type() ) ); ?></a>
<?php } ?>

<div class="ran-booster-package-intro">
	<p class="description">
		<?php esc_html_e( 'Review package health, deploy saved branches and hand published releases to WordPress.', 'ran-booster' ); ?>
	</p>

	<?php require dirname( __DIR__ ) . '/notices.php'; ?>
</div>

<?php if ( $package_list_total > 0 ) { ?>
	<div class="ran-booster-package-list-controls">
		<form class="ran-booster-package-list-filters" method="get" action="<?php echo esc_url( $package_admin_url ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( $package_view->get_page_slug() ); ?>">
			<?php if ( '' !== $package_list_state['search'] ) { ?>
				<input type="hidden" name="s" value="<?php echo esc_attr( $package_list_state['search'] ); ?>">
			<?php } ?>
			<label class="screen-reader-text" for="ran-booster-<?php echo esc_attr( $package_view->get_type() ); ?>-provider-filter"><?php esc_html_e( 'Filter by repository provider', 'ran-booster' ); ?></label>
			<select id="ran-booster-<?php echo esc_attr( $package_view->get_type() ); ?>-provider-filter" name="provider">
				<option value=""><?php esc_html_e( 'All providers', 'ran-booster' ); ?></option>
				<?php foreach ( $package_provider_options as $provider_option ) { ?>
					<option value="<?php echo esc_attr( $provider_option['code'] ); ?>" <?php selected( $provider_option['code'], $package_list_state['provider'] ); ?>><?php echo esc_html( $provider_option['label'] ); ?></option>
				<?php } ?>
			</select>
			<label class="screen-reader-text" for="ran-booster-<?php echo esc_attr( $package_view->get_type() ); ?>-source-filter"><?php esc_html_e( 'Filter by update source', 'ran-booster' ); ?></label>
			<select id="ran-booster-<?php echo esc_attr( $package_view->get_type() ); ?>-source-filter" name="source">
				<option value=""><?php esc_html_e( 'All update sources', 'ran-booster' ); ?></option>
				<option value="branch" <?php selected( 'branch', $package_list_state['source'] ); ?>><?php esc_html_e( 'Branch', 'ran-booster' ); ?></option>
				<option value="release_asset" <?php selected( 'release_asset', $package_list_state['source'] ); ?>><?php esc_html_e( 'Releases', 'ran-booster' ); ?></option>
			</select>
			<label class="screen-reader-text" for="ran-booster-<?php echo esc_attr( $package_view->get_type() ); ?>-policy-filter"><?php esc_html_e( 'Filter by updates', 'ran-booster' ); ?></label>
			<select id="ran-booster-<?php echo esc_attr( $package_view->get_type() ); ?>-policy-filter" name="policy">
				<option value=""><?php esc_html_e( 'All updates', 'ran-booster' ); ?></option>
				<option value="automatic" <?php selected( 'automatic', $package_list_state['policy'] ); ?>><?php esc_html_e( 'Automatic', 'ran-booster' ); ?></option>
				<option value="manual" <?php selected( 'manual', $package_list_state['policy'] ); ?>><?php esc_html_e( 'Manual', 'ran-booster' ); ?></option>
				<option value="disabled" <?php selected( 'disabled', $package_list_state['policy'] ); ?>><?php esc_html_e( 'Disabled', 'ran-booster' ); ?></option>
			</select>
			<button class="button" type="submit"><?php esc_html_e( 'Filter', 'ran-booster' ); ?></button>
			<?php if ( $has_package_list_filters ) { ?>
				<a class="ran-booster-package-list-filters__clear" href="<?php echo esc_url( $clear_filters_url ); ?>"><?php esc_html_e( 'Clear filters', 'ran-booster' ); ?></a>
			<?php } ?>
		</form>

		<form class="ran-booster-package-list-search search-form" method="get" action="<?php echo esc_url( $package_admin_url ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( $package_view->get_page_slug() ); ?>">
			<?php foreach ( array( 'provider', 'source', 'policy' ) as $filter_key ) { ?>
				<?php if ( '' !== $package_list_state[ $filter_key ] ) { ?>
					<input type="hidden" name="<?php echo esc_attr( $filter_key ); ?>" value="<?php echo esc_attr( $package_list_state[ $filter_key ] ); ?>">
				<?php } ?>
			<?php } ?>
			<p class="search-box">
				<label for="ran-booster-<?php echo esc_attr( $package_view->get_type() ); ?>-search"><?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( 'Search managed %s', 'ran-booster' ), strtolower( $package_view->get_plural_label() ) ) ); ?></label>
				<input id="ran-booster-<?php echo esc_attr( $package_view->get_type() ); ?>-search" class="wp-filter-search" type="search" name="s" value="<?php echo esc_attr( $package_list_state['search'] ); ?>">
				<button class="button" type="submit"><?php esc_html_e( 'Search', 'ran-booster' ); ?></button>
			</p>
		</form>
	</div>
<?php } ?>

<?php if ( $package_list_total > 0 ) { ?>
	<div class="tablenav top ran-booster-package-toolbar">
		<?php if ( count( $packages ) > 0 ) { ?>
		<form
			id="<?php echo esc_attr( $bulk_form_id ); ?>"
			class="alignleft actions bulkactions ran-booster-bulk-actions"
			action=""
			method="POST"
			data-ran-booster-package-mutation
			data-ran-booster-bulk-form
			data-package-type-label="<?php echo esc_attr( $bulk_type_label ); ?>"
			data-package-type-singular="<?php echo esc_attr( $bulk_type_singular ); ?>"
			data-reinstall-confirm-singular="<?php esc_attr_e( 'Reinstall the selected branch and overwrite local changes?', 'ran-booster' ); ?>"
			data-reinstall-confirm-plural="<?php esc_attr_e( 'Reinstall {count} selected branches and overwrite local changes?', 'ran-booster' ); ?>"
		>
			<?php wp_nonce_field( $package_view->get_action( 'bulk' ) ); ?>
			<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $package_view->get_action( 'bulk' ) ); ?>">
			<label class="screen-reader-text" for="<?php echo esc_attr( $bulk_action_id ); ?>"><?php esc_html_e( 'Select bulk action', 'ran-booster' ); ?></label>
			<select id="<?php echo esc_attr( $bulk_action_id ); ?>" name="ran_booster[bulk_action]" required>
				<option value=""><?php esc_html_e( 'Bulk actions', 'ran-booster' ); ?></option>
				<option value="queue-update"><?php esc_html_e( 'Reinstall selected branches', 'ran-booster' ); ?></option>
				<option value="policy-manual"><?php esc_html_e( 'Set updates: Manual', 'ran-booster' ); ?></option>
				<option value="policy-disabled"><?php esc_html_e( 'Set updates: Disabled', 'ran-booster' ); ?></option>
				<option value="policy-automatic"><?php esc_html_e( 'Set updates: Automatic', 'ran-booster' ); ?></option>
				<?php if ( $is_plugin_list ) { ?>
					<option value="activate-plugins"><?php esc_html_e( 'Enable in WordPress', 'ran-booster' ); ?></option>
					<option value="deactivate-plugins"><?php esc_html_e( 'Disable in WordPress', 'ran-booster' ); ?></option>
				<?php } ?>
			</select>
			<button type="submit" class="button action" data-ran-booster-bulk-apply disabled><?php esc_html_e( 'Apply', 'ran-booster' ); ?></button>
			<span class="ran-booster-bulk-actions__status" aria-live="polite" data-ran-booster-selection-status><?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( '0 %s selected', 'ran-booster' ), $bulk_type_label ) ); ?></span>
		</form>
		<?php } ?>
		<div class="tablenav-pages one-page">
			<span class="displaying-num"><?php echo esc_html( $package_list_count_label ); ?></span>
		</div>
		<br class="clear">
	</div>
<?php } ?>

<table class="wp-list-table widefat plugins ran-booster-package-table">
	<thead>
	<tr>
		<th scope="col" class="manage-column column-cb check-column">
			<?php if ( count( $packages ) > 0 ) { ?>
				<input id="<?php echo esc_attr( $bulk_select_all_id ); ?>" type="checkbox" data-ran-booster-select-all>
				<label class="screen-reader-text" for="<?php echo esc_attr( $bulk_select_all_id ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( 'Select all %s', 'ran-booster' ), $bulk_type_label ) ); ?></label>
			<?php } else { ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Selection', 'ran-booster' ); ?></span>
			<?php } ?>
		</th>
		<th scope="col" class="manage-column column-primary ran-booster-package-table__package-header"><?php echo esc_html( $package_view->get_singular_label() ); ?></th>
		<th scope="col" class="manage-column ran-booster-package-table__deploy-info-header"><?php esc_html_e( 'Management', 'ran-booster' ); ?></th>
		<th scope="col" class="manage-column ran-booster-package-table__actions-header"><?php esc_html_e( 'Actions', 'ran-booster' ); ?></th>
	</tr>
	</thead>

	<tbody id="the-list">
		<?php if ( count( $packages ) < 1 ) { ?>
			<tr>
				<?php if ( $package_list_total > 0 ) { ?>
					<td></td>
					<td colspan="3">
						<?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( 'No managed %s match the current filters.', 'ran-booster' ), strtolower( $package_view->get_plural_label() ) ) ); ?>
					</td>
				<?php } else { ?>
					<td colspan="4" class="ran-booster-package-empty-state">
						<h3><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Add your first %s', 'ran-booster' ), $package_view->get_type() ) ); ?></h3>
						<p><?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( 'No %s are managed by RAN Booster yet.', 'ran-booster' ), strtolower( $package_view->get_plural_label() ) ) ); ?></p>
						<a class="button button-primary" href="<?php echo esc_url( $install_another_url ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Add your first %s', 'ran-booster' ), $package_view->get_type() ) ); ?></a>
					</td>
				<?php } ?>
			</tr>
		<?php } ?>
		<?php $package_row_number = 0; ?>
		<?php foreach ( $packages as $package ) { ?>
			<?php
			++$package_row_number;
			$provider_code             = (string) ( $package->get_provider_code() ?? '' );
			$package_provider          = $package_providers_by_code[ $provider_code ] ?? null;
			$provider_unavailable      = null === $package_provider;
			$deployment_policy         = $package->get_deployment_policy();
			$policy_disabled           = \RAN\Deployment\DeploymentPolicy::DISABLED === $deployment_policy;
			$package_identifier        = (string) $package->get_identifier();
			$release_managed           = \RAN\PackageSource::RELEASE_ASSET === $package->get_source();
			$package_extension_row     = isset( $package_extension_rows[ $package_identifier ] ) && is_array( $package_extension_rows[ $package_identifier ] )
				? $package_extension_rows[ $package_identifier ]
				: array();
			$package_actions           = isset( $package_extension_actions[ $package_identifier ] ) && is_array( $package_extension_actions[ $package_identifier ] )
				? $package_extension_actions[ $package_identifier ]
				: array();
			$word_press_plugin_active  = $is_plugin_list && is_plugin_active( $package_identifier );
			$package_activity_summary  = ! $release_managed && isset( $package_activity[ $package_identifier ] ) && is_array( $package_activity[ $package_identifier ] )
				? $package_activity[ $package_identifier ]
				: array();
			$latest_attempt            = $package_activity_summary['latest'] ?? null;
			$last_successful_attempt   = $package_activity_summary['last_successful'] ?? null;
			$latest_activity           = $latest_attempt instanceof \RAN\Deployment\DeploymentAttempt ? $latest_attempt->safe_data() : null;
			$last_successful_activity  = $last_successful_attempt instanceof \RAN\Deployment\DeploymentAttempt ? $last_successful_attempt->safe_data() : null;
			$latest_activity_state     = is_array( $latest_activity ) && is_string( $latest_activity['state'] ?? null )
				? $latest_activity['state']
				: '';
			$latest_activity_id        = is_array( $latest_activity ) && is_int( $latest_activity['id'] ?? null )
				? $latest_activity['id']
				: 0;
			$latest_activity_reference = is_array( $latest_activity ) && is_string( $latest_activity['correlation_id'] ?? null )
				? $latest_activity['correlation_id']
				: '';
			$last_successful_at        = is_array( $last_successful_activity ) && is_string( $last_successful_activity['finished_at'] ?? null )
				? $last_successful_activity['finished_at']
				: '';
			$installed_version         = $package->get_version();
			$credential_profiles       = is_array( $package_provider['credentials'] ?? null ) ? $package_provider['credentials'] : array();
			$credentials_by_id         = array_column( $credential_profiles, null, 'id' );
			$stored_credential_id      = $package->get_credential_id();
			$effective_credential_id   = '' !== $stored_credential_id
				? $stored_credential_id
				: (string) ( $package_provider['default_credential_id'] ?? '' );
			$configured_credential     = $credentials_by_id[ $effective_credential_id ] ?? null;
			$credential_available      = ! $package->get_private() || is_array( $configured_credential );
			$provider_can_deploy       = ! $provider_unavailable && true === $package_provider['deploy'];
			$deployment_available      = $provider_can_deploy && $credential_available;
			$update_can_run            = $deployment_available && ! $policy_disabled;
			$update_in_progress        = ! $release_managed && in_array( $latest_activity_state, array( 'queued', 'running' ), true );
			$update_needs_attention    = ! $release_managed
				&& $latest_attempt instanceof \RAN\Deployment\DeploymentAttempt
				&& $latest_attempt->requires_operator_resolution();
			if ( $policy_disabled ) {
				$update_label = __( 'Deployment disabled', 'ran-booster' );
			} elseif ( $provider_unavailable ) {
				$update_label = __( 'Provider unavailable', 'ran-booster' );
			} elseif ( ! $credential_available ) {
				$update_label = __( 'Credential unavailable', 'ran-booster' );
			} else {
				/* translators: %s is the managed package type, such as plugin or theme. */
				$update_label = sprintf( __( 'Reinstall %s', 'ran-booster' ), $package_view->get_type() );
			}
			$idle_update_label = __( 'Reinstall', 'ran-booster' );
			if ( ! $release_managed ) {
				if ( 'queued' === $latest_activity_state ) {
					$update_label = __( 'Reinstall queued', 'ran-booster' );
				} elseif ( 'running' === $latest_activity_state ) {
					$update_label = __( 'Reinstall in progress…', 'ran-booster' );
				} elseif ( $update_needs_attention ) {
					$update_label = __( 'Needs attention', 'ran-booster' );
				}
			}
			$provider_label = ! $provider_unavailable ? (string) $package_provider['label'] : $provider_code;
			$source_label   = $release_managed
				? __( 'Releases', 'ran-booster' )
				: sprintf(
					/* translators: %s is the repository branch name. */
					__( 'Branch: %s', 'ran-booster' ),
					$package->get_branch()
				);
			$access_label = __( 'Public repository', 'ran-booster' );
			if ( $package->get_private() ) {
				if ( $provider_unavailable ) {
					$access_label = __( 'Private; provider unavailable', 'ran-booster' );
				} elseif ( is_array( $configured_credential ) ) {
					$access_label = sprintf(
						/* translators: %s is the saved credential label. */
						__( 'Private via %s', 'ran-booster' ),
						(string) $configured_credential['label']
					);
				} else {
					$access_label = __( 'Private; credential missing', 'ran-booster' );
				}
			}
			$prominent_status          = null;
			$prominent_status_activity = false;
			if ( $provider_unavailable ) {
				$prominent_status = array(
					'label' => __( 'Provider unavailable', 'ran-booster' ),
					'tone'  => 'error',
				);
			} elseif ( ! $provider_can_deploy ) {
				$prominent_status = array(
					'label' => __( 'Integration unavailable', 'ran-booster' ),
					'tone'  => 'error',
				);
			} elseif ( ! $credential_available ) {
				$prominent_status = array(
					'label' => __( 'Credential unavailable', 'ran-booster' ),
					'tone'  => 'error',
				);
			} elseif ( ! $release_managed
				&& ! $policy_disabled
				&& ( in_array( $latest_activity_state, array( 'queued', 'running', 'failed' ), true ) || $update_needs_attention )
			) {
				$prominent_status          = array(
					'label' => $activity_state_labels[ $latest_activity_state ] ?? $latest_activity_state,
					'tone'  => $activity_badge_variants[ $latest_activity_state ] ?? 'error',
				);
				$prominent_status_activity = true;
			} else {
				foreach ( $package_extension_row['badges'] ?? array() as $extension_badge ) {
					if ( is_array( $extension_badge )
						&& is_string( $extension_badge['label'] ?? null )
						&& is_string( $extension_badge['tone'] ?? null )
					) {
						$prominent_status = $extension_badge;
						break;
					}
				}
			}
			$edit_url               = add_query_arg(
				array(
					'page'    => $package_view->get_page_slug(),
					'package' => $package->get_identifier(),
				),
				$package_admin_url
			);
			$automation_state_label = match ( $deployment_policy ) {
				\RAN\Deployment\DeploymentPolicy::AUTOMATIC => __( 'Automatic', 'ran-booster' ),
				\RAN\Deployment\DeploymentPolicy::DISABLED => __( 'Disabled', 'ran-booster' ),
				default => __( 'Manual', 'ran-booster' ),
			};
			$management_line = $release_managed
				? __( 'Releases', 'ran-booster' )
				: sprintf(
					/* translators: %s is the repository branch name. */
					__( 'Branch · %s', 'ran-booster' ),
					'' !== $package->get_branch() ? $package->get_branch() : __( 'provider default', 'ran-booster' )
				);
			if ( $provider_unavailable ) {
				$status_line = __( 'The saved provider is unavailable. Restore it before deploying this package.', 'ran-booster' );
			} elseif ( ! $credential_available ) {
				$status_line = __( 'The saved repository credential is unavailable.', 'ran-booster' );
			} elseif ( '' !== ( $package_extension_row['status'] ?? '' ) ) {
				$status_line = (string) $package_extension_row['status'];
			} elseif ( $policy_disabled ) {
				$status_line = __( 'Booster will not overwrite this package or respond to repository pushes.', 'ran-booster' );
			} elseif ( $release_managed ) {
				$status_line = \RAN\Deployment\DeploymentPolicy::AUTOMATIC === $deployment_policy
					? __( 'WordPress controls when validated published releases are installed.', 'ran-booster' )
					: __( 'Installed package is current. Release checks run only when requested.', 'ran-booster' );
			} elseif ( $update_needs_attention ) {
				$status_line = __( 'A prior branch deployment is awaiting operator review. It is not running. Open deployment activity and record the review before retrying.', 'ran-booster' );
			} elseif ( 'failed' === $latest_activity_state ) {
				$status_line = __( 'The latest branch deployment failed. Open deployment activity for details before retrying.', 'ran-booster' );
			} elseif ( \RAN\Deployment\DeploymentPolicy::AUTOMATIC === $deployment_policy ) {
				$status_line = __( 'Signed repository pushes deploy automatically. Review setup if pushes are not arriving.', 'ran-booster' );
			} else {
				$status_line = __( 'Ready. Deployments run only when an administrator requests one.', 'ran-booster' );
			}
			$details_label = $release_managed
				? __( 'Release details', 'ran-booster' )
				: ( $policy_disabled ? __( 'Package details', 'ran-booster' ) : __( 'Deployment activity', 'ran-booster' ) );
			?>
		<tr
			class="ran-booster-package-row ran-booster-package-row--primary<?php echo $word_press_plugin_active ? ' ran-booster-package-row--wordpress-active' : ''; ?>"
			data-package-source="<?php echo esc_attr( $package->get_source()->value ); ?>"
			<?php if ( ! $release_managed ) { ?>
				data-ran-booster-package-progress
				data-attempt-id="<?php echo esc_attr( (string) $latest_activity_id ); ?>"
				data-attempt-reference="<?php echo esc_attr( $latest_activity_reference ); ?>"
				data-attempt-state="<?php echo esc_attr( $latest_activity_state ); ?>"
			<?php } ?>
		>
			<th scope="row" rowspan="2" class="check-column">
				<?php $package_checkbox_id = 'ran-booster-select-' . $package_view->get_type() . '-' . $package_row_number; ?>
				<input id="<?php echo esc_attr( $package_checkbox_id ); ?>" type="checkbox" name="ran_booster[identifiers][]" value="<?php echo esc_attr( $package_identifier ); ?>" form="<?php echo esc_attr( $bulk_form_id ); ?>" data-ran-booster-package-checkbox data-ran-booster-branch-reinstall-eligible="<?php echo esc_attr( ( ! $release_managed && ! $update_needs_attention ) ? '1' : '0' ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( $package_checkbox_id ); ?>"><?php echo esc_html( sprintf( /* translators: %s is a package name. */ __( 'Select %s', 'ran-booster' ), $package->name ) ); ?></label>
			</th>
			<td class="column-primary ran-booster-package-row__identity">
				<h3 class="ran-booster-package-row__title">
					<span class="ran-booster-package-row__name"><?php echo esc_html( $package->name ); ?></span>
					<span class="ran-booster-package-row__repo"><?php echo esc_html( (string) $package->repository ); ?></span>
				</h3>
				<div class="ran-booster-package-row__states">
					<?php if ( $is_plugin_list ) { ?>
						<p class="ran-booster-package-row__state ran-booster-package-row__wordpress-state<?php echo $word_press_plugin_active ? ' is-enabled' : ' is-disabled'; ?>">
							<span class="ran-booster-package-row__state-label"><?php esc_html_e( 'WordPress', 'ran-booster' ); ?></span>
							<span class="ran-booster-package-row__state-value"><?php echo esc_html( $word_press_plugin_active ? __( 'Enabled', 'ran-booster' ) : __( 'Disabled', 'ran-booster' ) ); ?></span>
						</p>
					<?php } ?>
					<p class="ran-booster-package-row__state ran-booster-package-row__update-state is-<?php echo esc_attr( $deployment_policy->value ); ?>">
						<span class="ran-booster-package-row__state-label"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></span>
						<span class="ran-booster-package-row__state-value"><?php echo esc_html( $automation_state_label ); ?></span>
					</p>
				</div>
			</td>
			<td class="ran-booster-package-row__summary">
				<?php if ( is_array( $prominent_status ) ) { ?>
					<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $prominent_status['tone'] ); ?>"<?php echo $prominent_status_activity ? ' data-ran-booster-activity-badge' : ''; ?>><?php echo esc_html( $prominent_status['label'] ); ?></span>
				<?php } elseif ( ! $release_managed ) { ?>
					<span class="ran-booster-badge ran-booster-badge--neutral" data-ran-booster-activity-badge hidden></span>
				<?php } ?>
				<p class="ran-booster-package-row__management"><?php echo esc_html( $management_line ); ?></p>
				<p class="ran-booster-package-row__status-line"><?php echo esc_html( $status_line ); ?></p>
			</td>
			<td class="ran-booster-package-row__actions">
				<div class="ran-booster-package-row__action-group">
					<?php if ( $release_managed ) { ?>
						<?php $extension_action_renderer->render( $package_actions, true ); ?>
					<?php } else { ?>
						<form action="" method="POST" class="ran-booster-package-row__update-form" data-ran-booster-package-mutation>
							<?php wp_nonce_field( $package_view->get_action( 'update' ) ); ?>
							<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $package_view->get_action( 'update' ) ); ?>">
							<input type="hidden" name="ran_booster[repository]" value="<?php echo esc_attr( (string) $package->repository ); ?>">
							<input type="hidden" name="ran_booster[<?php echo esc_attr( $package_view->get_identifier_field() ); ?>]" value="<?php echo esc_attr( (string) $package->get_identifier() ); ?>">
							<?php require __DIR__ . '/expected-package.php'; ?>
							<button type="submit" class="button button-primary button-update-package<?php echo $update_in_progress ? ' ran-booster-update-is-active' : ''; ?>" <?php disabled( ! $update_can_run || $update_in_progress || $update_needs_attention ); ?> data-ran-booster-update-button data-idle-label="<?php echo esc_attr( $idle_update_label ); ?>" data-update-can-run="<?php echo esc_attr( $update_can_run ? '1' : '0' ); ?>" data-reinstall-confirm-message="<?php esc_attr_e( 'Reinstall from the saved branch and overwrite local changes?', 'ran-booster' ); ?>"<?php echo $update_in_progress ? ' aria-busy="true"' : ''; ?>>
								<span data-ran-booster-update-label><?php esc_html_e( 'Reinstall', 'ran-booster' ); ?></span>
							</button>
							<span class="screen-reader-text" aria-live="polite" data-ran-booster-update-message></span>
						</form>
					<?php } ?>
					<a href="<?php echo esc_url( $edit_url ); ?>" class="button"><?php esc_html_e( 'Edit settings', 'ran-booster' ); ?></a>
					<?php if ( ! $release_managed ) { ?>
						<?php $extension_action_renderer->render( $package_actions ); ?>
					<?php } ?>
				</div>
			</td>
		</tr>
		<tr class="ran-booster-package-row ran-booster-package-row--details<?php echo $word_press_plugin_active ? ' ran-booster-package-row--wordpress-active' : ''; ?>">
			<td colspan="3" class="ran-booster-package-row__details">
				<details>
					<summary><?php echo esc_html( $details_label ); ?></summary>
					<dl class="ran-booster-package-row__details-grid<?php echo $release_managed ? '' : ' ran-booster-package-row__details-grid--branch'; ?>">
						<div>
							<dt><?php esc_html_e( 'Provider', 'ran-booster' ); ?></dt>
							<dd><?php echo esc_html( $provider_label ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Version', 'ran-booster' ); ?></dt>
							<dd><?php echo esc_html( '' !== $installed_version ? $installed_version : __( 'Not available', 'ran-booster' ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Access', 'ran-booster' ); ?></dt>
							<dd><?php echo esc_html( $access_label ); ?></dd>
						</div>
						<?php if ( ! $release_managed ) { ?>
							<div>
								<dt><?php esc_html_e( 'Last activity', 'ran-booster' ); ?></dt>
								<dd class="ran-booster-package-row__activity-summary">
									<?php if ( $package_activity_unavailable ) { ?>
										<?php esc_html_e( 'Temporarily unavailable', 'ran-booster' ); ?>
									<?php } elseif ( is_array( $latest_activity ) ) { ?>
										<?php $active_detail_url = $activity_detail_base_url . '&attempt=' . rawurlencode( (string) $latest_activity['id'] ) . '&reference=' . rawurlencode( (string) $latest_activity['correlation_id'] ); ?>
										<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $activity_badge_variants[ $latest_activity['state'] ] ?? 'neutral' ); ?> ran-booster-deployment-state ran-booster-deployment-state--<?php echo esc_attr( (string) $latest_activity['state'] ); ?>" data-ran-booster-activity-state><?php echo esc_html( $activity_state_labels[ $latest_activity['state'] ] ?? (string) $latest_activity['state'] ); ?></span>
										<a href="<?php echo esc_url( $active_detail_url ); ?>"><?php esc_html_e( 'View details', 'ran-booster' ); ?></a>
									<?php } else { ?>
										<?php esc_html_e( 'No activity recorded', 'ran-booster' ); ?>
									<?php } ?>
								</dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Last succeeded', 'ran-booster' ); ?></dt>
								<dd><?php echo esc_html( '' !== $last_successful_at ? $last_successful_at : __( 'Not recorded', 'ran-booster' ) ); ?></dd>
							</div>
						<?php } ?>
					</dl>
				</details>
			</td>
		</tr>
		<?php } ?>
	</tbody>
</table>
