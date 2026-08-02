<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$packageProvidersByCode     = array_column( $packageProviders, null, 'code' );
$packageActivityPayload     = isset( $packageActivity ) && is_array( $packageActivity ) ? $packageActivity : array();
$packageActivityUnavailable = true === ( $packageActivityPayload['unavailable'] ?? false );
$packageActivity            = isset( $packageActivityPayload['items'] ) && is_array( $packageActivityPayload['items'] )
	? $packageActivityPayload['items']
	: $packageActivityPayload;
$packageExtensionRows       = isset( $packageExtensionRows ) && is_array( $packageExtensionRows )
	? $packageExtensionRows
	: array();
$packageExtensionActions    = isset( $packageExtensionActions ) && is_array( $packageExtensionActions )
	? $packageExtensionActions
	: array();
$packageListState           = isset( $packageListState ) && is_array( $packageListState )
	? array_merge(
		array(
			'search'   => '',
			'provider' => '',
			'source'   => '',
			'policy'   => '',
		),
		$packageListState
	)
	: array(
		'search'   => '',
		'provider' => '',
		'source'   => '',
		'policy'   => '',
	);
$packageListTotal           = isset( $packageListTotal ) && is_int( $packageListTotal )
	? max( count( $packages ), $packageListTotal )
	: count( $packages );
$packageProviderOptions     = isset( $packageProviderOptions ) && is_array( $packageProviderOptions )
	? $packageProviderOptions
	: array();
$extensionActionRenderer    = new \RAN\Admin\Component\AdminActionRenderer();
$activityDetailBaseUrl      = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' );
$activityStateLabels        = array(
	'queued'          => __( 'Queued', 'ran-booster' ),
	'running'         => __( 'Running', 'ran-booster' ),
	'succeeded'       => __( 'Succeeded', 'ran-booster' ),
	'failed'          => __( 'Failed', 'ran-booster' ),
	'needs_attention' => __( 'Needs attention', 'ran-booster' ),
);
$activityBadgeVariants      = array(
	'queued'          => 'pending',
	'running'         => 'pending',
	'succeeded'       => 'ok',
	'failed'          => 'error',
	'needs_attention' => 'error',
);
$bulkFormId                 = 'ran-booster-' . $packageView->getType() . '-bulk-form';
$bulkActionId               = 'ran-booster-' . $packageView->getType() . '-bulk-action';
$bulkSelectAllId            = 'ran-booster-' . $packageView->getType() . '-select-all';
$bulkTypeLabel              = strtolower( $packageView->getPluralLabel() );
$bulkTypeSingular           = strtolower( $packageView->getSingularLabel() );
$isPluginList               = 'plugin' === $packageView->getType();
$installAnotherUrl          = add_query_arg( 'page', $packageView->getCreatePageSlug(), admin_url( 'admin.php' ) );
$clearFiltersUrl            = add_query_arg( 'page', $packageView->getPageSlug(), admin_url( 'admin.php' ) );
$hasPackageListFilters      = array() !== array_filter(
	$packageListState,
	static fn ( mixed $value ): bool => is_string( $value ) && '' !== $value
);
$filteredPackageCount       = count( $packages );
$packageListCountLabel      = $hasPackageListFilters
	? sprintf(
		/* translators: 1: filtered item count, 2: total item count. */
		_n( '%1$d of %2$d item', '%1$d of %2$d items', $packageListTotal, 'ran-booster' ),
		$filteredPackageCount,
		$packageListTotal
	)
	: sprintf(
		/* translators: %d: item count. */
		_n( '%d item', '%d items', $packageListTotal, 'ran-booster' ),
		$packageListTotal
	);
$policyLabels = array(
	\RAN\Deployment\DeploymentPolicy::DISABLED->value  => __( 'Disabled', 'ran-booster' ),
	\RAN\Deployment\DeploymentPolicy::MANUAL->value    => __( 'Manual', 'ran-booster' ),
	\RAN\Deployment\DeploymentPolicy::AUTOMATIC->value => __( 'Automatic', 'ran-booster' ),
);

?><h2 class="wp-heading-inline ran-booster-package-heading">Managed <?php echo esc_html( $packageView->getPluralLabel() ); ?></h2>
<a class="page-title-action" href="<?php echo esc_url( $installAnotherUrl ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugin or theme. */ __( 'Install another %s', 'ran-booster' ), $packageView->getType() ) ); ?></a>

<div class="ran-booster-package-intro">
	<p class="description">
		<?php esc_html_e( 'Review package health, deploy saved branches and hand published releases to WordPress.', 'ran-booster' ); ?>
	</p>

	<?php require dirname( __DIR__ ) . '/notices.php'; ?>
</div>

<?php if ( $packageListTotal > 0 ) { ?>
	<div class="ran-booster-package-list-controls">
		<form class="ran-booster-package-list-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( $packageView->getPageSlug() ); ?>">
			<?php if ( '' !== $packageListState['search'] ) { ?>
				<input type="hidden" name="s" value="<?php echo esc_attr( $packageListState['search'] ); ?>">
			<?php } ?>
			<label class="screen-reader-text" for="ran-booster-<?php echo esc_attr( $packageView->getType() ); ?>-provider-filter"><?php esc_html_e( 'Filter by repository provider', 'ran-booster' ); ?></label>
			<select id="ran-booster-<?php echo esc_attr( $packageView->getType() ); ?>-provider-filter" name="provider">
				<option value=""><?php esc_html_e( 'All providers', 'ran-booster' ); ?></option>
				<?php foreach ( $packageProviderOptions as $providerOption ) { ?>
					<option value="<?php echo esc_attr( $providerOption['code'] ); ?>" <?php selected( $providerOption['code'], $packageListState['provider'] ); ?>><?php echo esc_html( $providerOption['label'] ); ?></option>
				<?php } ?>
			</select>
			<label class="screen-reader-text" for="ran-booster-<?php echo esc_attr( $packageView->getType() ); ?>-source-filter"><?php esc_html_e( 'Filter by package source', 'ran-booster' ); ?></label>
			<select id="ran-booster-<?php echo esc_attr( $packageView->getType() ); ?>-source-filter" name="source">
				<option value=""><?php esc_html_e( 'All sources', 'ran-booster' ); ?></option>
				<option value="branch" <?php selected( 'branch', $packageListState['source'] ); ?>><?php esc_html_e( 'Branch', 'ran-booster' ); ?></option>
				<option value="release_asset" <?php selected( 'release_asset', $packageListState['source'] ); ?>><?php esc_html_e( 'Published release', 'ran-booster' ); ?></option>
			</select>
			<label class="screen-reader-text" for="ran-booster-<?php echo esc_attr( $packageView->getType() ); ?>-policy-filter"><?php esc_html_e( 'Filter by updates', 'ran-booster' ); ?></label>
			<select id="ran-booster-<?php echo esc_attr( $packageView->getType() ); ?>-policy-filter" name="policy">
				<option value=""><?php esc_html_e( 'All updates', 'ran-booster' ); ?></option>
				<option value="automatic" <?php selected( 'automatic', $packageListState['policy'] ); ?>><?php esc_html_e( 'Automatic', 'ran-booster' ); ?></option>
				<option value="manual" <?php selected( 'manual', $packageListState['policy'] ); ?>><?php esc_html_e( 'Manual', 'ran-booster' ); ?></option>
				<option value="disabled" <?php selected( 'disabled', $packageListState['policy'] ); ?>><?php esc_html_e( 'Disabled', 'ran-booster' ); ?></option>
			</select>
			<button class="button" type="submit"><?php esc_html_e( 'Filter', 'ran-booster' ); ?></button>
			<?php if ( $hasPackageListFilters ) { ?>
				<a class="ran-booster-package-list-filters__clear" href="<?php echo esc_url( $clearFiltersUrl ); ?>"><?php esc_html_e( 'Clear filters', 'ran-booster' ); ?></a>
			<?php } ?>
		</form>

		<form class="ran-booster-package-list-search search-form" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( $packageView->getPageSlug() ); ?>">
			<?php foreach ( array( 'provider', 'source', 'policy' ) as $filterKey ) { ?>
				<?php if ( '' !== $packageListState[ $filterKey ] ) { ?>
					<input type="hidden" name="<?php echo esc_attr( $filterKey ); ?>" value="<?php echo esc_attr( $packageListState[ $filterKey ] ); ?>">
				<?php } ?>
			<?php } ?>
			<p class="search-box">
				<label for="ran-booster-<?php echo esc_attr( $packageView->getType() ); ?>-search"><?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( 'Search managed %s', 'ran-booster' ), strtolower( $packageView->getPluralLabel() ) ) ); ?></label>
				<input id="ran-booster-<?php echo esc_attr( $packageView->getType() ); ?>-search" class="wp-filter-search" type="search" name="s" value="<?php echo esc_attr( $packageListState['search'] ); ?>">
				<button class="button" type="submit"><?php esc_html_e( 'Search', 'ran-booster' ); ?></button>
			</p>
		</form>
	</div>
<?php } ?>

<?php if ( $packageListTotal > 0 ) { ?>
	<div class="tablenav top ran-booster-package-toolbar">
		<?php if ( count( $packages ) > 0 ) { ?>
		<form
			id="<?php echo esc_attr( $bulkFormId ); ?>"
			class="alignleft actions bulkactions ran-booster-bulk-actions"
			action=""
			method="POST"
			data-ran-booster-package-mutation
			data-ran-booster-bulk-form
			data-package-type-label="<?php echo esc_attr( $bulkTypeLabel ); ?>"
			data-package-type-singular="<?php echo esc_attr( $bulkTypeSingular ); ?>"
			data-reinstall-confirm-singular="<?php esc_attr_e( 'Reinstall the selected branch and overwrite local changes?', 'ran-booster' ); ?>"
			data-reinstall-confirm-plural="<?php esc_attr_e( 'Reinstall {count} selected branches and overwrite local changes?', 'ran-booster' ); ?>"
		>
			<?php wp_nonce_field( $packageView->getAction( 'bulk' ) ); ?>
			<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $packageView->getAction( 'bulk' ) ); ?>">
			<label class="screen-reader-text" for="<?php echo esc_attr( $bulkActionId ); ?>"><?php esc_html_e( 'Select bulk action', 'ran-booster' ); ?></label>
			<select id="<?php echo esc_attr( $bulkActionId ); ?>" name="ran_booster[bulk_action]" required>
				<option value=""><?php esc_html_e( 'Bulk actions', 'ran-booster' ); ?></option>
				<option value="queue-update"><?php esc_html_e( 'Reinstall selected branches', 'ran-booster' ); ?></option>
				<option value="policy-manual"><?php esc_html_e( 'Set updates: Manual', 'ran-booster' ); ?></option>
				<option value="policy-disabled"><?php esc_html_e( 'Set updates: Disabled', 'ran-booster' ); ?></option>
				<option value="policy-automatic"><?php esc_html_e( 'Set updates: Automatic', 'ran-booster' ); ?></option>
				<?php if ( $isPluginList ) { ?>
					<option value="activate-plugins"><?php esc_html_e( 'Enable in WordPress', 'ran-booster' ); ?></option>
					<option value="deactivate-plugins"><?php esc_html_e( 'Disable in WordPress', 'ran-booster' ); ?></option>
				<?php } ?>
			</select>
			<button type="submit" class="button action" data-ran-booster-bulk-apply disabled><?php esc_html_e( 'Apply', 'ran-booster' ); ?></button>
			<span class="ran-booster-bulk-actions__status" aria-live="polite" data-ran-booster-selection-status><?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( '0 %s selected', 'ran-booster' ), $bulkTypeLabel ) ); ?></span>
		</form>
		<?php } ?>
		<div class="tablenav-pages one-page">
			<span class="displaying-num"><?php echo esc_html( $packageListCountLabel ); ?></span>
		</div>
		<br class="clear">
	</div>
<?php } ?>

<table class="wp-list-table widefat plugins ran-booster-package-table">
	<thead>
	<tr>
		<th scope="col" class="manage-column column-cb check-column">
			<?php if ( count( $packages ) > 0 ) { ?>
				<input id="<?php echo esc_attr( $bulkSelectAllId ); ?>" type="checkbox" data-ran-booster-select-all>
				<label class="screen-reader-text" for="<?php echo esc_attr( $bulkSelectAllId ); ?>"><?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( 'Select all %s', 'ran-booster' ), $bulkTypeLabel ) ); ?></label>
			<?php } else { ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Selection', 'ran-booster' ); ?></span>
			<?php } ?>
		</th>
		<th scope="col" class="manage-column column-primary ran-booster-package-table__package-header"><?php echo esc_html( $packageView->getSingularLabel() ); ?></th>
		<th scope="col" class="manage-column ran-booster-package-table__deploy-info-header"><?php esc_html_e( 'Management', 'ran-booster' ); ?></th>
		<th scope="col" class="manage-column ran-booster-package-table__actions-header"><?php esc_html_e( 'Actions', 'ran-booster' ); ?></th>
	</tr>
	</thead>

	<tbody id="the-list">
		<?php if ( count( $packages ) < 1 ) { ?>
			<tr>
				<td></td>
				<td colspan="3">
					<?php if ( $packageListTotal > 0 ) { ?>
						<?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( 'No managed %s match the current filters.', 'ran-booster' ), strtolower( $packageView->getPluralLabel() ) ) ); ?>
					<?php } else { ?>
						<?php echo esc_html( sprintf( /* translators: %s is plugins or themes. */ __( 'No %s managed by RAN Booster yet.', 'ran-booster' ), strtolower( $packageView->getPluralLabel() ) ) ); ?>
					<?php } ?>
				</td>
			</tr>
		<?php } ?>
		<?php $packageRowNumber = 0; ?>
		<?php foreach ( $packages as $package ) { ?>
			<?php
			++$packageRowNumber;
			$providerCode            = (string) ( $package->getProviderCode() ?? '' );
			$packageProvider         = $packageProvidersByCode[ $providerCode ] ?? null;
			$providerUnavailable     = null === $packageProvider;
			$deploymentPolicy        = $package->getDeploymentPolicy();
			$policyDisabled          = \RAN\Deployment\DeploymentPolicy::DISABLED === $deploymentPolicy;
			$packageIdentifier       = (string) $package->getIdentifier();
			$releaseManaged          = \RAN\PackageSource::RELEASE_ASSET === $package->getSource();
			$packageExtensionRow     = isset( $packageExtensionRows[ $packageIdentifier ] ) && is_array( $packageExtensionRows[ $packageIdentifier ] )
				? $packageExtensionRows[ $packageIdentifier ]
				: array();
			$packageActions          = isset( $packageExtensionActions[ $packageIdentifier ] ) && is_array( $packageExtensionActions[ $packageIdentifier ] )
				? $packageExtensionActions[ $packageIdentifier ]
				: array();
			$wordPressPluginActive   = $isPluginList && is_plugin_active( $packageIdentifier );
			$packageActivitySummary  = ! $releaseManaged && isset( $packageActivity[ $packageIdentifier ] ) && is_array( $packageActivity[ $packageIdentifier ] )
				? $packageActivity[ $packageIdentifier ]
				: array();
			$latestAttempt           = $packageActivitySummary['latest'] ?? null;
			$lastSuccessfulAttempt   = $packageActivitySummary['last_successful'] ?? null;
			$latestActivity          = $latestAttempt instanceof \RAN\Deployment\DeploymentAttempt ? $latestAttempt->safeData() : null;
			$lastSuccessfulActivity  = $lastSuccessfulAttempt instanceof \RAN\Deployment\DeploymentAttempt ? $lastSuccessfulAttempt->safeData() : null;
			$latestActivityState     = is_array( $latestActivity ) && is_string( $latestActivity['state'] ?? null )
				? $latestActivity['state']
				: '';
			$latestActivityId        = is_array( $latestActivity ) && is_int( $latestActivity['id'] ?? null )
				? $latestActivity['id']
				: 0;
			$latestActivityReference = is_array( $latestActivity ) && is_string( $latestActivity['correlation_id'] ?? null )
				? $latestActivity['correlation_id']
				: '';
			$lastSuccessfulAt        = is_array( $lastSuccessfulActivity ) && is_string( $lastSuccessfulActivity['finished_at'] ?? null )
				? $lastSuccessfulActivity['finished_at']
				: '';
			$installedVersion        = $package->getVersion();
			$credentialProfiles      = is_array( $packageProvider['credentials'] ?? null ) ? $packageProvider['credentials'] : array();
			$credentialsById         = array_column( $credentialProfiles, null, 'id' );
			$storedCredentialId      = $package->getCredentialId();
			$effectiveCredentialId   = '' !== $storedCredentialId
				? $storedCredentialId
				: (string) ( $packageProvider['default_credential_id'] ?? '' );
			$configuredCredential    = $credentialsById[ $effectiveCredentialId ] ?? null;
			$credentialAvailable     = ! $package->getPrivate() || is_array( $configuredCredential );
			$providerCanDeploy       = ! $providerUnavailable && true === $packageProvider['deploy'];
			$deploymentAvailable     = $providerCanDeploy && $credentialAvailable;
			$updateCanRun            = $deploymentAvailable && ! $policyDisabled;
			$updateInProgress        = ! $releaseManaged && in_array( $latestActivityState, array( 'queued', 'running' ), true );
			$updateNeedsAttention    = ! $releaseManaged
				&& $latestAttempt instanceof \RAN\Deployment\DeploymentAttempt
				&& $latestAttempt->requiresOperatorResolution();
			if ( $policyDisabled ) {
				$updateLabel = __( 'Deployment disabled', 'ran-booster' );
			} elseif ( $providerUnavailable ) {
				$updateLabel = __( 'Provider unavailable', 'ran-booster' );
			} elseif ( ! $credentialAvailable ) {
				$updateLabel = __( 'Credential unavailable', 'ran-booster' );
			} else {
				/* translators: %s is the managed package type, such as plugin or theme. */
				$updateLabel = sprintf( __( 'Reinstall %s', 'ran-booster' ), $packageView->getType() );
			}
			$idleUpdateLabel = __( 'Reinstall', 'ran-booster' );
			if ( ! $releaseManaged ) {
				if ( 'queued' === $latestActivityState ) {
					$updateLabel = __( 'Reinstall queued', 'ran-booster' );
				} elseif ( 'running' === $latestActivityState ) {
					$updateLabel = __( 'Reinstall in progress…', 'ran-booster' );
				} elseif ( $updateNeedsAttention ) {
					$updateLabel = __( 'Needs attention', 'ran-booster' );
				}
			}
			$providerLabel = ! $providerUnavailable ? (string) $packageProvider['label'] : $providerCode;
			$sourceLabel   = $releaseManaged
				? __( 'Published release', 'ran-booster' )
				: sprintf(
					/* translators: %s is a branch name. */
					__( 'Branch: %s', 'ran-booster' ),
					$package->getBranch()
				);
			$accessLabel = __( 'Public repository', 'ran-booster' );
			if ( $package->getPrivate() ) {
				if ( $providerUnavailable ) {
					$accessLabel = __( 'Private; provider unavailable', 'ran-booster' );
				} elseif ( is_array( $configuredCredential ) ) {
					$accessLabel = sprintf(
						/* translators: %s is the saved credential label. */
						__( 'Private via %s', 'ran-booster' ),
						(string) $configuredCredential['label']
					);
				} else {
					$accessLabel = __( 'Private; credential missing', 'ran-booster' );
				}
			}
			$prominentStatus         = null;
			$prominentStatusActivity = false;
			if ( $providerUnavailable ) {
				$prominentStatus = array(
					'label' => __( 'Provider unavailable', 'ran-booster' ),
					'tone'  => 'error',
				);
			} elseif ( ! $providerCanDeploy ) {
				$prominentStatus = array(
					'label' => __( 'Integration unavailable', 'ran-booster' ),
					'tone'  => 'error',
				);
			} elseif ( ! $credentialAvailable ) {
				$prominentStatus = array(
					'label' => __( 'Credential unavailable', 'ran-booster' ),
					'tone'  => 'error',
				);
			} elseif ( ! $releaseManaged
				&& ! $policyDisabled
				&& ( in_array( $latestActivityState, array( 'queued', 'running', 'failed' ), true ) || $updateNeedsAttention )
			) {
				$prominentStatus         = array(
					'label' => $activityStateLabels[ $latestActivityState ] ?? $latestActivityState,
					'tone'  => $activityBadgeVariants[ $latestActivityState ] ?? 'error',
				);
				$prominentStatusActivity = true;
			} else {
				foreach ( $packageExtensionRow['badges'] ?? array() as $extensionBadge ) {
					if ( is_array( $extensionBadge )
						&& is_string( $extensionBadge['label'] ?? null )
						&& is_string( $extensionBadge['tone'] ?? null )
					) {
						$prominentStatus = $extensionBadge;
						break;
					}
				}
			}
			$editUrl              = add_query_arg(
				array(
					'page'    => $packageView->getPageSlug(),
					'package' => $package->getIdentifier(),
				),
				admin_url( 'admin.php' )
			);
			$automationStateLabel = match ( $deploymentPolicy ) {
				\RAN\Deployment\DeploymentPolicy::AUTOMATIC => __( 'Automatic', 'ran-booster' ),
				\RAN\Deployment\DeploymentPolicy::DISABLED => __( 'Disabled', 'ran-booster' ),
				default => __( 'Manual', 'ran-booster' ),
			};
			$managementLine = $releaseManaged
				? __( 'Published releases', 'ran-booster' )
				: sprintf(
					/* translators: %s is a branch name. */
					__( 'Branch · %s', 'ran-booster' ),
					'' !== $package->getBranch() ? $package->getBranch() : __( 'provider default', 'ran-booster' )
				);
			if ( $providerUnavailable ) {
				$statusLine = __( 'The saved provider is unavailable. Restore it before deploying this package.', 'ran-booster' );
			} elseif ( ! $credentialAvailable ) {
				$statusLine = __( 'The saved repository credential is unavailable.', 'ran-booster' );
			} elseif ( '' !== ( $packageExtensionRow['status'] ?? '' ) ) {
				$statusLine = (string) $packageExtensionRow['status'];
			} elseif ( $policyDisabled ) {
				$statusLine = __( 'Booster will not overwrite this package or respond to repository pushes.', 'ran-booster' );
			} elseif ( $releaseManaged ) {
				$statusLine = \RAN\Deployment\DeploymentPolicy::AUTOMATIC === $deploymentPolicy
					? __( 'WordPress controls when validated published releases are installed.', 'ran-booster' )
					: __( 'Installed package is current. Release checks run only when requested.', 'ran-booster' );
			} elseif ( $updateNeedsAttention ) {
				$statusLine = __( 'A prior branch deployment is awaiting operator review. It is not running. Open deployment activity and record the review before retrying.', 'ran-booster' );
			} elseif ( 'failed' === $latestActivityState ) {
				$statusLine = __( 'The latest branch deployment failed. Open deployment activity for details before retrying.', 'ran-booster' );
			} elseif ( \RAN\Deployment\DeploymentPolicy::AUTOMATIC === $deploymentPolicy ) {
				$statusLine = __( 'Signed repository pushes deploy automatically. Review setup if pushes are not arriving.', 'ran-booster' );
			} else {
				$statusLine = __( 'Ready. Deployments run only when an administrator requests one.', 'ran-booster' );
			}
			$detailsLabel = $releaseManaged
				? __( 'Release details', 'ran-booster' )
				: ( $policyDisabled ? __( 'Package details', 'ran-booster' ) : __( 'Deployment activity', 'ran-booster' ) );
			?>
		<tr
			class="ran-booster-package-row ran-booster-package-row--primary<?php echo $wordPressPluginActive ? ' ran-booster-package-row--wordpress-active' : ''; ?>"
			data-package-source="<?php echo esc_attr( $package->getSource()->value ); ?>"
			<?php if ( ! $releaseManaged ) { ?>
				data-ran-booster-package-progress
				data-attempt-id="<?php echo esc_attr( (string) $latestActivityId ); ?>"
				data-attempt-reference="<?php echo esc_attr( $latestActivityReference ); ?>"
				data-attempt-state="<?php echo esc_attr( $latestActivityState ); ?>"
			<?php } ?>
		>
			<th scope="row" rowspan="2" class="check-column">
				<?php $packageCheckboxId = 'ran-booster-select-' . $packageView->getType() . '-' . $packageRowNumber; ?>
				<input id="<?php echo esc_attr( $packageCheckboxId ); ?>" type="checkbox" name="ran_booster[identifiers][]" value="<?php echo esc_attr( $packageIdentifier ); ?>" form="<?php echo esc_attr( $bulkFormId ); ?>" data-ran-booster-package-checkbox data-ran-booster-branch-reinstall-eligible="<?php echo esc_attr( ( ! $releaseManaged && ! $updateNeedsAttention ) ? '1' : '0' ); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr( $packageCheckboxId ); ?>"><?php echo esc_html( sprintf( /* translators: %s is a package name. */ __( 'Select %s', 'ran-booster' ), $package->name ) ); ?></label>
			</th>
			<td class="column-primary ran-booster-package-row__identity">
				<h3 class="ran-booster-package-row__title">
					<span class="ran-booster-package-row__name"><?php echo esc_html( $package->name ); ?></span>
					<span class="ran-booster-package-row__repo"><?php echo esc_html( (string) $package->repository ); ?></span>
				</h3>
				<div class="ran-booster-package-row__states">
					<?php if ( $isPluginList ) { ?>
						<p class="ran-booster-package-row__state ran-booster-package-row__wordpress-state<?php echo $wordPressPluginActive ? ' is-enabled' : ' is-disabled'; ?>">
							<span class="ran-booster-package-row__state-label"><?php esc_html_e( 'WordPress', 'ran-booster' ); ?></span>
							<span class="ran-booster-package-row__state-value"><?php echo esc_html( $wordPressPluginActive ? __( 'Enabled', 'ran-booster' ) : __( 'Disabled', 'ran-booster' ) ); ?></span>
						</p>
					<?php } ?>
					<p class="ran-booster-package-row__state ran-booster-package-row__update-state is-<?php echo esc_attr( $deploymentPolicy->value ); ?>">
						<span class="ran-booster-package-row__state-label"><?php esc_html_e( 'Updates', 'ran-booster' ); ?></span>
						<span class="ran-booster-package-row__state-value"><?php echo esc_html( $automationStateLabel ); ?></span>
					</p>
				</div>
			</td>
			<td class="ran-booster-package-row__summary">
				<?php if ( is_array( $prominentStatus ) ) { ?>
					<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $prominentStatus['tone'] ); ?>"<?php echo $prominentStatusActivity ? ' data-ran-booster-activity-badge' : ''; ?>><?php echo esc_html( $prominentStatus['label'] ); ?></span>
				<?php } elseif ( ! $releaseManaged ) { ?>
					<span class="ran-booster-badge ran-booster-badge--neutral" data-ran-booster-activity-badge hidden></span>
				<?php } ?>
				<p class="ran-booster-package-row__management"><?php echo esc_html( $managementLine ); ?></p>
				<p class="ran-booster-package-row__status-line"><?php echo esc_html( $statusLine ); ?></p>
			</td>
			<td class="ran-booster-package-row__actions">
				<div class="ran-booster-package-row__action-group">
					<?php if ( $releaseManaged ) { ?>
						<?php $extensionActionRenderer->render( $packageActions, true ); ?>
					<?php } else { ?>
						<form action="" method="POST" class="ran-booster-package-row__update-form" data-ran-booster-package-mutation>
							<?php wp_nonce_field( $packageView->getAction( 'update' ) ); ?>
							<input type="hidden" name="ran_booster[action]" value="<?php echo esc_attr( $packageView->getAction( 'update' ) ); ?>">
							<input type="hidden" name="ran_booster[repository]" value="<?php echo esc_attr( (string) $package->repository ); ?>">
							<input type="hidden" name="ran_booster[<?php echo esc_attr( $packageView->getIdentifierField() ); ?>]" value="<?php echo esc_attr( (string) $package->getIdentifier() ); ?>">
							<?php require __DIR__ . '/expected-package.php'; ?>
							<button type="submit" class="button button-primary button-update-package<?php echo $updateInProgress ? ' ran-booster-update-is-active' : ''; ?>" <?php disabled( ! $updateCanRun || $updateInProgress || $updateNeedsAttention ); ?> data-ran-booster-update-button data-idle-label="<?php echo esc_attr( $idleUpdateLabel ); ?>" data-update-can-run="<?php echo esc_attr( $updateCanRun ? '1' : '0' ); ?>" data-reinstall-confirm-message="<?php esc_attr_e( 'Reinstall from the saved branch and overwrite local changes?', 'ran-booster' ); ?>"<?php echo $updateInProgress ? ' aria-busy="true"' : ''; ?>>
								<span data-ran-booster-update-label><?php esc_html_e( 'Reinstall', 'ran-booster' ); ?></span>
							</button>
							<span class="screen-reader-text" aria-live="polite" data-ran-booster-update-message></span>
						</form>
					<?php } ?>
					<a href="<?php echo esc_url( $editUrl ); ?>" class="button"><?php esc_html_e( 'Edit settings', 'ran-booster' ); ?></a>
					<?php if ( ! $releaseManaged ) { ?>
						<?php $extensionActionRenderer->render( $packageActions ); ?>
					<?php } ?>
				</div>
			</td>
		</tr>
		<tr class="ran-booster-package-row ran-booster-package-row--details<?php echo $wordPressPluginActive ? ' ran-booster-package-row--wordpress-active' : ''; ?>">
			<td colspan="3" class="ran-booster-package-row__details">
				<details>
					<summary><?php echo esc_html( $detailsLabel ); ?></summary>
					<dl class="ran-booster-package-row__details-grid<?php echo $releaseManaged ? '' : ' ran-booster-package-row__details-grid--branch'; ?>">
						<div>
							<dt><?php esc_html_e( 'Provider', 'ran-booster' ); ?></dt>
							<dd><?php echo esc_html( $providerLabel ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Version', 'ran-booster' ); ?></dt>
							<dd><?php echo esc_html( '' !== $installedVersion ? $installedVersion : __( 'Not available', 'ran-booster' ) ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Access', 'ran-booster' ); ?></dt>
							<dd><?php echo esc_html( $accessLabel ); ?></dd>
						</div>
						<?php if ( ! $releaseManaged ) { ?>
							<div>
								<dt><?php esc_html_e( 'Last activity', 'ran-booster' ); ?></dt>
								<dd class="ran-booster-package-row__activity-summary">
									<?php if ( $packageActivityUnavailable ) { ?>
										<?php esc_html_e( 'Temporarily unavailable', 'ran-booster' ); ?>
									<?php } elseif ( is_array( $latestActivity ) ) { ?>
										<?php $activeDetailUrl = $activityDetailBaseUrl . '&attempt=' . rawurlencode( (string) $latestActivity['id'] ) . '&reference=' . rawurlencode( (string) $latestActivity['correlation_id'] ); ?>
										<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $activityBadgeVariants[ $latestActivity['state'] ] ?? 'neutral' ); ?> ran-booster-deployment-state ran-booster-deployment-state--<?php echo esc_attr( (string) $latestActivity['state'] ); ?>" data-ran-booster-activity-state><?php echo esc_html( $activityStateLabels[ $latestActivity['state'] ] ?? (string) $latestActivity['state'] ); ?></span>
										<a href="<?php echo esc_url( $activeDetailUrl ); ?>"><?php esc_html_e( 'View details', 'ran-booster' ); ?></a>
									<?php } else { ?>
										<?php esc_html_e( 'No activity recorded', 'ran-booster' ); ?>
									<?php } ?>
								</dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Last succeeded', 'ran-booster' ); ?></dt>
								<dd><?php echo esc_html( '' !== $lastSuccessfulAt ? $lastSuccessfulAt : __( 'Not recorded', 'ran-booster' ) ); ?></dd>
							</div>
						<?php } ?>
					</dl>
				</details>
			</td>
		</tr>
		<?php } ?>
	</tbody>
</table>
