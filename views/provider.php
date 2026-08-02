<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$kindLabels        = array_column( $provider['credential_kinds'], 'label', 'code' );
$scopeLabels       = array_column( $provider['webhook_scopes'], 'label', 'code' );
$fieldLabels       = array();
$packageTypeLabels = array(
	'plugin' => __( 'Plugin', 'ran-booster' ),
	'theme'  => __( 'Theme', 'ran-booster' ),
);

foreach ( $provider['credential_kinds'] as $credentialKind ) {
	foreach ( $credentialKind['fields'] as $field ) {
		$fieldLabels[ $field['key'] ] = $field['label'];
	}
}

$storageUnavailable                   = ! empty( $secrets_storage_unavailable );
$hasCredentialSettings                = ! $storageUnavailable && ! empty( $provider['credential_kinds'] );
$providerHasWebhookSettings           = ! empty( $provider['capabilities']['webhooks'] ) && ! empty( $provider['webhook_scopes'] );
$hasWebhookSettings                   = ! $storageUnavailable && $providerHasWebhookSettings;
$providerView                         = isset( $providerView ) && in_array( $providerView, array( 'credentials', 'secrets' ), true )
	? $providerView
	: 'overview';
$providerTask                         = isset( $providerTask ) && in_array( $providerTask, array( 'repositories', 'setup' ), true )
	? $providerTask
	: 'status';
$providerListState                    = isset( $providerListState ) && is_array( $providerListState )
	? array_merge(
		array(
			'search'   => '',
			'kind'     => '',
			'scope'    => '',
			'status'   => '',
			'orderby'  => 'name',
			'order'    => 'asc',
			'paged'    => 1,
			'per_page' => 20,
		),
		$providerListState
	)
	: array(
		'search'   => '',
		'kind'     => '',
		'scope'    => '',
		'status'   => '',
		'orderby'  => 'name',
		'order'    => 'asc',
		'paged'    => 1,
		'per_page' => 20,
	);
$managedRepositories                  = isset( $managed_webhook_repositories ) && is_array( $managed_webhook_repositories )
	? $managed_webhook_repositories
	: array(
		'available'    => false,
		'owners'       => array(),
		'repositories' => array(),
	);
$managedRepositories['owners']        = isset( $managedRepositories['owners'] ) && is_array( $managedRepositories['owners'] )
	? $managedRepositories['owners']
	: array();
$managedRepositories['repositories']  = isset( $managedRepositories['repositories'] ) && is_array( $managedRepositories['repositories'] )
	? $managedRepositories['repositories']
	: array();
$providerRepositories                 = isset( $provider_repositories ) && is_array( $provider_repositories )
	? $provider_repositories
	: $managedRepositories;
$providerRepositories['repositories'] = isset( $providerRepositories['repositories'] ) && is_array( $providerRepositories['repositories'] )
	? $providerRepositories['repositories']
	: array();
$publicLookupProfile                  = isset( $public_lookup_profile ) && is_array( $public_lookup_profile )
	? $public_lookup_profile
	: null;
$webhookSetup                         = isset( $provider['webhook_setup'] ) && is_array( $provider['webhook_setup'] )
	? $provider['webhook_setup']
	: null;
$repositoryComposition                = new \RAN\Admin\ProviderRepositoryCompositionRenderer();
$statusSummaryRenderer                = new \RAN\Admin\Component\AdminStatusSummaryRenderer();
$providerManagementTableRenderer      = new \RAN\Admin\Component\ProviderManagementTableRenderer();
$providerWebhookAssistance            = $repositoryComposition->assistancePresentation( $provider['webhook_assistance'] ?? null );
$providerAssistanceDescriptionId      = null === $providerWebhookAssistance
	? ''
	: 'ran-booster-provider-assistance-description';
$webhookEndpoint                      = rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $provider['code'] ) );
$webhookAssistanceReadiness           = isset( $webhook_assistance_readiness ) && is_array( $webhook_assistance_readiness )
	? $webhook_assistance_readiness
	: null;
$webhookAssistanceSite                = is_array( $webhookAssistanceReadiness['site'] ?? null )
	? $webhookAssistanceReadiness['site']
	: null;
$webhookAssistanceRepositories        = is_array( $webhookAssistanceReadiness['repositories'] ?? null )
	? $webhookAssistanceReadiness['repositories']
	: array();
$webhookAssistanceSiteEndpoint        = is_string( $webhookAssistanceSite['callback_url'] ?? null )
	? $webhookAssistanceSite['callback_url']
	: $webhookEndpoint;
$webhookAssistanceProviderCapable     = null !== $webhookAssistanceSite;
$webhookAssistanceSiteReady           = $webhookAssistanceProviderCapable
	&& 'ready' === ( $webhookAssistanceSite['status'] ?? null );
$webhookAssistanceSiteReasonCodes     = is_array( $webhookAssistanceSite['reason_codes'] ?? null )
	? $webhookAssistanceSite['reason_codes']
	: array();
// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The display-safe Core endpoint is parsed without network I/O.
$webhookEndpointHost            = parse_url( $webhookAssistanceSiteEndpoint, PHP_URL_HOST );
$webhookUsesLocalUrl            = is_string( $webhookEndpointHost )
	&& (
		in_array( strtolower( $webhookEndpointHost ), array( 'localhost', '127.0.0.1', '::1' ), true )
		|| str_ends_with( strtolower( $webhookEndpointHost ), '.local' )
	);
$webhookSiteReasonLabels        = array(
	'database_unavailable'           => __( 'Booster database storage must be healthy before Push-to-Deploy can run.', 'ran-booster' ),
	'secrets_storage_unavailable'    => __( 'Encrypted credential storage must be healthy before Push-to-Deploy can verify signed deliveries.', 'ran-booster' ),
	'callback_requires_public_https' => $webhookUsesLocalUrl
		? __( 'This site uses a local URL, so providers cannot deliver webhooks to it. Configure a public HTTPS site URL before using Push-to-Deploy.', 'ran-booster' )
		: __( 'The payload URL must use public HTTPS before providers can deliver webhooks to it.', 'ran-booster' ),
	'managed_packages_unavailable'   => __( 'Booster could not read the managed package inventory needed for Push-to-Deploy.', 'ran-booster' ),
);
$webhookRepositoryIssueLabels   = array(
	'repository_identity_unavailable' => __( 'Repository identity unavailable', 'ran-booster' ),
	'repository_identity_conflict'    => __( 'Repository identity conflict', 'ran-booster' ),
	'repository_locator_invalid'      => __( 'Repository address invalid', 'ran-booster' ),
);
$webhookSiteReasons             = array_values(
	array_filter(
		array_map(
			static fn ( mixed $code ): ?string => is_string( $code )
				? ( $webhookSiteReasonLabels[ $code ] ?? null )
				: null,
			$webhookAssistanceSiteReasonCodes
		)
	)
);
$webhookHasHardFailure          = array() !== array_intersect(
	$webhookAssistanceSiteReasonCodes,
	array( 'database_unavailable', 'secrets_storage_unavailable', 'managed_packages_unavailable' )
);
$webhookReadinessByRepositoryId = array();
$webhookReadinessByRepository   = array();
foreach ( $webhookAssistanceRepositories as $candidate ) {
	if ( ! is_array( $candidate ) || $provider['code'] !== ( $candidate['provider_code'] ?? null ) ) {
		continue;
	}
	$repositoryId = is_string( $candidate['repository_id'] ?? null ) ? $candidate['repository_id'] : '';
	$repository   = is_string( $candidate['repository'] ?? null ) ? strtolower( $candidate['repository'] ) : '';
	if ( '' !== $repositoryId ) {
		$webhookReadinessByRepositoryId[ $repositoryId ] = $candidate;
	}
	if ( '' !== $repository ) {
		$webhookReadinessByRepository[ $repository ] = $candidate;
	}
}

$providerAdminUrl         = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider['code'] ) );
$providerOwnerLabel       = is_string( $provider['owner_label'] ?? null ) && '' !== trim( $provider['owner_label'] )
	? $provider['owner_label']
	: __( 'Owner', 'ran-booster' );
$providerUrl              = static fn ( array $args = array() ): string => add_query_arg( $args, $providerAdminUrl );
$overviewUrl              = $providerUrl();
$credentialsUrl           = $providerUrl( array( 'view' => 'credentials' ) );
$secretsUrl               = $providerUrl( array( 'view' => 'secrets' ) );
$taskUrl                  = static fn ( string $task ): string => $providerUrl( array( 'panel' => $task ) );
$taskRequestUrl           = static fn ( string $task ): string => add_query_arg(
	array(
		'page'  => 'ran-booster',
		'tab'   => $provider['code'],
		'panel' => $task,
	),
	'admin.php'
);
$sharedWebhookSecretLabel = sprintf(
	/* translators: %s is the provider owner label, such as Owner or Workspace. */
	__( '%s secret', 'ran-booster' ),
	$providerOwnerLabel
);
$providerWebhookSettingsLabel = sprintf(
	/* translators: %s is the repository provider name. */
	__( '%s webhooks', 'ran-booster' ),
	$provider['label']
);

$managedRepositoryCount = count( $managedRepositories['repositories'] );
$managedPackageCount    = 0;
$automaticPackageCount  = 0;
foreach ( $managedRepositories['repositories'] as $repository ) {
	$managedPackageCount   += (int) ( $repository['package_count'] ?? 0 );
	$automaticPackageCount += (int) ( $repository['automatic_count'] ?? 0 );
}

$credentialRows   = array();
$credentialScopes = array();
foreach ( $credential_profiles as $profileIndex => $profile ) {
	$configurationSummary = array();
	$scopeValue           = '';
	foreach ( $profile['configuration'] as $key => $value ) {
		if ( '' === $value ) {
			continue;
		}
		$configurationSummary[] = ( $fieldLabels[ $key ] ?? ucfirst( $key ) ) . ': ' . $value;
		if ( '' === $scopeValue && in_array( $key, array( 'owner', 'workspace' ), true ) ) {
			$scopeValue = $value;
		}
	}
	$scopeKey                      = '' === $scopeValue ? 'account' : sanitize_key( $scopeValue );
	$scopeLabel                    = '' === $scopeValue
		? __( 'Account', 'ran-booster' )
		: $providerOwnerLabel . ' · ' . $scopeValue;
	$credentialScopes[ $scopeKey ] = $scopeLabel;
	$usage                         = is_array( $profile['usage'] ?? null )
		? $profile['usage']
		: array(
			'available' => false,
			'total'     => null,
			'packages'  => array(),
		);
	$usageTotal                    = $usage['available'] ? (int) $usage['total'] : -1;
	$usageLabel                    = $usage['available']
		? sprintf(
			/* translators: %d is the number of managed packages. */
			_n( '%d package', '%d packages', $usageTotal, 'ran-booster' ),
			$usageTotal
		)
		: __( 'Usage unavailable', 'ran-booster' );
	$healthLabel      = $profile['configured']
		? (string) ( $profile['expiry_status']['badge_label'] ?? __( 'Saved', 'ran-booster' ) )
		: __( 'Not configured', 'ran-booster' );
	$statusKey        = $profile['configured']
		&& ! str_contains( (string) ( $profile['expiry_status']['badge_class'] ?? '' ), 'error' )
		&& ! str_contains( (string) ( $profile['expiry_status']['badge_class'] ?? '' ), 'warning' )
			? 'ready'
			: 'attention';
	$credentialRows[] = $profile + array(
		'profile_index'       => $profileIndex,
		'kind_label'          => $kindLabels[ $profile['kind'] ] ?? $profile['kind'],
		'configuration_label' => implode( ' · ', $configurationSummary ),
		'scope_key'           => $scopeKey,
		'scope_label'         => $scopeLabel,
		'usage_total'         => $usageTotal,
		'usage_label'         => $usageLabel,
		'health_label'        => $healthLabel,
		'status_key'          => $statusKey,
		'search_value'        => strtolower(
			implode(
				' ',
				array_merge(
					array( $profile['label'], $kindLabels[ $profile['kind'] ] ?? $profile['kind'], $scopeLabel, $healthLabel ),
					array_values( $profile['configuration'] )
				)
			)
		),
	);
}

$webhookRows = array();
foreach ( $webhook_profiles as $profile ) {
	$usage      = is_array( $profile['usage'] ?? null )
		? $profile['usage']
		: array(
			'available'    => false,
			'total'        => null,
			'repositories' => array(),
		);
	$usageTotal = $usage['available'] ? (int) $usage['total'] : -1;
	$usageLabel = $usage['available']
		? sprintf(
			/* translators: %d is the number of managed packages. */
			_n( '%d package', '%d packages', $usageTotal, 'ran-booster' ),
			$usageTotal
		)
		: __( 'Usage unavailable', 'ran-booster' );
	$statusKey     = $profile['configured'] && $usage['available'] ? 'ready' : 'attention';
	$health        = $profile['configured']
		? __( 'Saved · Remote delivery not verified by Core', 'ran-booster' )
		: __( 'Local secret not configured', 'ran-booster' );
	$webhookRows[] = $profile + array(
		'scope_label'  => $scopeLabels[ $profile['scope'] ] ?? ucfirst( $profile['scope'] ),
		'usage_total'  => $usageTotal,
		'usage_label'  => $usageLabel,
		'health_label' => $health,
		'status_key'   => $statusKey,
		'search_value' => strtolower(
			implode( ' ', array( $profile['label'], $profile['scope'], $profile['target'], $usageLabel, $health ) )
		),
	);
}

$credentialRowsNeedAttention = array() !== array_filter(
	$credentialRows,
	static fn ( array $row ): bool => 'attention' === $row['status_key']
);
$webhookRowsNeedAttention    = array() !== array_filter(
	$webhookRows,
	static fn ( array $row ): bool => 'attention' === $row['status_key']
);

$filterAndPage  = static function ( array $rows, string $view ) use ( $providerListState ): array {
	$search  = strtolower( trim( (string) $providerListState['search'] ) );
	$rows    = array_values(
		array_filter(
			$rows,
			static function ( array $row ) use ( $providerListState, $search, $view ): bool {
				if ( '' !== $search && ! str_contains( $row['search_value'], $search ) ) {
					return false;
				}
				if ( 'credentials' === $view ) {
					if ( '' !== $providerListState['kind'] && $providerListState['kind'] !== $row['kind'] ) {
						return false;
					}
					if ( '' !== $providerListState['scope'] && $providerListState['scope'] !== $row['scope_key'] ) {
						return false;
					}
				} else {
					if ( '' !== $providerListState['scope'] && $providerListState['scope'] !== $row['scope'] ) {
						return false;
					}
					if ( '' !== $providerListState['status'] && $providerListState['status'] !== $row['status_key'] ) {
						return false;
					}
				}

				return true;
			}
		)
	);
	$sortKey = match ( $providerListState['orderby'] ) {
		'kind'   => 'kind_label',
		'scope'  => 'scope_label',
		'usage'  => 'usage_total',
		'health' => 'health_label',
		default  => 'label',
	};
	usort(
		$rows,
		static function ( array $left, array $right ) use ( $providerListState, $sortKey ): int {
			$comparison = is_int( $left[ $sortKey ] ) && is_int( $right[ $sortKey ] )
				? $left[ $sortKey ] <=> $right[ $sortKey ]
				: strnatcasecmp( (string) $left[ $sortKey ], (string) $right[ $sortKey ] );

			return 'desc' === $providerListState['order'] ? -$comparison : $comparison;
		}
	);
	$total     = count( $rows );
	$pages     = max( 1, (int) ceil( $total / $providerListState['per_page'] ) );
	$current   = min( $providerListState['paged'], $pages );
	$offset    = ( $current - 1 ) * $providerListState['per_page'];
	$pagedRows = array_slice( $rows, $offset, $providerListState['per_page'] );

	return array(
		'rows'    => $pagedRows,
		'total'   => $total,
		'pages'   => $pages,
		'current' => $current,
	);
};
$credentialList = $filterAndPage( $credentialRows, 'credentials' );
$webhookList    = $filterAndPage( $webhookRows, 'secrets' );
$sortUrl        = static function ( string $view, string $orderby ) use ( $providerListState, $providerUrl ): string {
	$order = $providerListState['orderby'] === $orderby && 'asc' === $providerListState['order'] ? 'desc' : 'asc';

	return $providerUrl(
		array_filter(
			array(
				'view'     => $view,
				's'        => $providerListState['search'],
				'kind'     => $providerListState['kind'],
				'scope'    => $providerListState['scope'],
				'status'   => $providerListState['status'],
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $providerListState['per_page'],
			),
			static fn ( mixed $value ): bool => '' !== $value
		)
	);
};
$pageUrl        = static function ( string $view, int $page ) use ( $providerListState, $providerUrl ): string {
	return $providerUrl(
		array_filter(
			array(
				'view'     => $view,
				's'        => $providerListState['search'],
				'kind'     => $providerListState['kind'],
				'scope'    => $providerListState['scope'],
				'status'   => $providerListState['status'],
				'orderby'  => $providerListState['orderby'],
				'order'    => $providerListState['order'],
				'per_page' => $providerListState['per_page'],
				'paged'    => max( 1, $page ),
			),
			static fn ( mixed $value ): bool => '' !== $value
		)
	);
};

/* translators: %s is the repository provider label. */
$providerBackLabel = sprintf( __( 'Back to %s overview', 'ran-booster' ), $provider['label'] );
/* translators: %s is the repository provider label. */
$credentialManagementDescription = sprintf( __( 'Manage saved credentials used for %s repository access.', 'ran-booster' ), $provider['label'] );
/* translators: %s is the repository provider label. */
$secretManagementDescription = sprintf( __( 'Manage local signing material used to verify %s webhook deliveries.', 'ran-booster' ), $provider['label'] );
/* translators: %s is the repository provider label. */
$providerPushDescription = sprintf( __( '%s push webhooks can trigger managed branch deployments whose Updates setting is Automatic.', 'ran-booster' ), $provider['label'] );
$automaticPackageLabel   = __( 'None set to Automatic', 'ran-booster' );
if ( 0 < $automaticPackageCount ) {
	/* translators: %d is the number of packages using Automatic deployment. */
	$automaticPackageLabel = sprintf( _n( '%d package is Automatic', '%d packages are Automatic', $automaticPackageCount, 'ran-booster' ), $automaticPackageCount );
}
$managedPackageDescription = sprintf(
	/* translators: 1: number of repositories, 2: number of managed packages. */
	_n( '%1$d repository contains %2$d managed package.', '%1$d repositories contain %2$d managed packages.', $managedRepositoryCount, 'ran-booster' ),
	$managedRepositoryCount,
	$managedPackageCount
);
/* translators: %s is the repository provider label. */
$providerInstructionsLabel = sprintf( __( 'Open %s instructions', 'ran-booster' ), $provider['label'] );
/* translators: %s is the provider-specific shared secret label. */
$secretChoiceDescription = sprintf( __( 'Use a saved %s or create a repository-scoped secret when isolation is required.', 'ran-booster' ), strtolower( $sharedWebhookSecretLabel ) );
/* translators: %s is the repository provider label. */
$createProviderWebhookLabel = sprintf( __( 'Create the %s webhook', 'ran-booster' ), $provider['label'] );
$manualSetupDescription     = '';
if ( null !== $webhookSetup ) {
	/* translators: 1: repository provider label, 2: provider settings location. */
	$manualSetupDescription = sprintf( __( 'In %1$s, go to %2$s and create the remote webhook.', 'ran-booster' ), $provider['label'], $webhookSetup['location'] );
}
/* translators: %s is the repository provider label. */
$repositoryWebhookDescription = sprintf( __( 'Each repository needs its own %s webhook. A saved shared secret may serve multiple repositories.', 'ran-booster' ), $provider['label'] );
/* translators: %d is the number of repository rows shown after filtering. */
$repositoryCountSingular = __( '%d repository shown', 'ran-booster' );
/* translators: %d is the number of repository rows shown after filtering. */
$repositoryCountPlural = __( '%d repositories shown', 'ran-booster' );
/* translators: %s is the repository provider label. */
$emptyRepositoryDescription = sprintf( __( 'No managed %s repositories are available yet. Install a package to add its repository.', 'ran-booster' ), $provider['label'] );
/* translators: %d is the number of matching credentials. */
$credentialItemCountLabel = sprintf( _n( '%d item', '%d items', $credentialList['total'], 'ran-booster' ), $credentialList['total'] );
/* translators: 1: current page number, 2: total page count. */
$credentialPageLabel = sprintf( __( 'Page %1$d of %2$d', 'ran-booster' ), $credentialList['current'], $credentialList['pages'] );
/* translators: %d is the number of matching webhook secrets. */
$webhookItemCountLabel = sprintf( _n( '%d item', '%d items', $webhookList['total'], 'ran-booster' ), $webhookList['total'] );
/* translators: 1: current page number, 2: total page count. */
$webhookPageLabel     = sprintf( __( 'Page %1$d of %2$d', 'ran-booster' ), $webhookList['current'], $webhookList['pages'] );
$renderCredentialCell = static function ( array $profile, string $column ) use ( $packageTypeLabels, $provider ): void {
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

	$usage           = $profile['usage'];
	$usageTemplateId = 'ran-booster-delete-credential-usage-' . (string) $profile['profile_index'];
	if ( $profile['configured'] && $provider['capabilities']['credentials'] ) {
		?>
		<form method="post" action="" class="ran-booster-inline-form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" hx-post="" hx-target="#ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" hx-swap="outerHTML" hx-sync="this:drop"><?php wp_nonce_field( 'ran-booster-save-secrets' ); ?><input type="hidden" name="ran_booster[action]" value="validate-access-profile"><input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="ran_booster[id]" value="<?php echo esc_attr( $profile['id'] ); ?>"><button type="submit" class="button"><?php esc_html_e( 'Validate', 'ran-booster' ); ?></button></form><div id="ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>
		<?php
	}
	if ( ! $profile['editable'] ) {
		?>
		<span class="description"><?php esc_html_e( 'Managed by deployment configuration', 'ran-booster' ); ?></span>
		<?php
		return;
	}
	?>
	<button type="button" class="button ran-booster-open-credential-modal" data-modal="access" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-kind="<?php echo esc_attr( $profile['kind'] ); ?>" data-configuration="<?php echo esc_attr( wp_json_encode( $profile['configuration'] ) ); ?>" data-expires-on="<?php echo esc_attr( $profile['expiry']['manual_expires_on'] ?? '' ); ?>" data-self-destruct="<?php echo ! empty( $profile['self_destruct'] ) ? '1' : '0'; ?>" data-destroy-on="<?php echo esc_attr( $profile['destroy_on'] ?? '' ); ?>"><?php esc_html_e( 'Edit', 'ran-booster' ); ?></button><button type="button" class="button button-delete ran-booster-open-delete-credential-modal" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-usage-total="<?php echo esc_attr( $usage['available'] ? (string) $usage['total'] : '' ); ?>" data-usage-listed="<?php echo esc_attr( (string) count( $usage['packages'] ) ); ?>" data-usage-template="<?php echo esc_attr( $usageTemplateId ); ?>" data-public-lookup-default="<?php echo ! empty( $profile['public_lookup_default'] ) ? '1' : '0'; ?>" aria-haspopup="dialog" aria-controls="ran-booster-delete-access-modal" <?php disabled( ! $usage['available'] ); ?>><?php esc_html_e( 'Delete', 'ran-booster' ); ?></button>
	<template id="<?php echo esc_attr( $usageTemplateId ); ?>">
		<ul class="ran-booster-delete-credential-package-list">
			<?php foreach ( $usage['packages'] as $packageUsage ) { ?>
				<li class="ran-booster-delete-credential-package-list__item">
					<?php if ( null !== $packageUsage['edit_url'] ) { ?>
						<a class="ran-booster-pill ran-booster-pill--label ran-booster-pill--info ran-booster-delete-credential-package-pill" href="<?php echo esc_url( $packageUsage['edit_url'] ); ?>"><?php echo esc_html( $packageTypeLabels[ $packageUsage['type'] ] . ': ' . $packageUsage['identifier'] ); ?></a>
					<?php } else { ?>
						<span class="ran-booster-pill ran-booster-pill--label ran-booster-delete-credential-package-pill ran-booster-delete-credential-package-pill--unavailable"><?php echo esc_html( $packageTypeLabels[ $packageUsage['type'] ] . ': ' . $packageUsage['identifier'] ); ?> <?php esc_html_e( '(not installed)', 'ran-booster' ); ?></span>
					<?php } ?>
				</li>
			<?php } ?>
		</ul>
	</template>
	<?php
};
$deleteWebhookInteractionValues = wp_json_encode(
	array(
		'ran_booster_interaction[operation]' => 'core:delete-webhook-profile',
		'ran_booster_interaction[target]'    => \RAN\Admin\Interaction\CoreProviderProfileInteraction::TARGET_KEY,
	)
);
$deleteWebhookInteractionValues = is_string( $deleteWebhookInteractionValues )
	? $deleteWebhookInteractionValues
	: '{}';
$renderWebhookCell              = static function ( array $profile, string $column ) use ( $provider, $deleteWebhookInteractionValues ): void {
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

	$webhookDeleteConfirm = __( 'Remove this local secret? Remote provider webhooks will not be removed.', 'ran-booster' );
	if ( $profile['usage']['available'] && 0 < $profile['usage_total'] ) {
		$webhookDeleteConfirm = sprintf(
			/* translators: %d is the number of managed packages that may be affected. */
			_n( 'Remove this local secret? %d managed package may be affected. Remote provider webhooks will not be removed.', 'Remove this local secret? %d managed packages may be affected. Remote provider webhooks will not be removed.', $profile['usage_total'], 'ran-booster' ),
			$profile['usage_total']
		);
	}
	?>
	<button type="button" class="button ran-booster-open-credential-modal" data-modal="webhook" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-scope="<?php echo esc_attr( $profile['scope'] ); ?>" data-target="<?php echo esc_attr( $profile['target'] ); ?>"><?php esc_html_e( 'Edit', 'ran-booster' ); ?></button><form method="post" action="" class="ran-booster-inline-form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-delete-webhook-profile-error" data-ran-booster-interaction-operation="core:delete-webhook-profile" hx-post="" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop" hx-vals="<?php echo esc_attr( $deleteWebhookInteractionValues ); ?>"><?php wp_nonce_field( 'ran-booster-save-secrets' ); ?><input type="hidden" name="ran_booster[action]" value="delete-webhook-profile"><input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="ran_booster[id]" value="<?php echo esc_attr( $profile['id'] ); ?>"><button type="submit" class="button button-delete" data-confirm="<?php echo esc_attr( $webhookDeleteConfirm ); ?>"><?php esc_html_e( 'Delete', 'ran-booster' ); ?></button></form>
	<?php
};

?>

<div id="ran-booster-provider-profile-region" data-ran-booster-admin-mutation-region="provider-profiles">
<?php settings_errors(); ?>
<section class="ran-booster-page-shell ran-booster-provider ran-booster-panel" aria-labelledby="ran-booster-provider-heading">
	<?php if ( 'overview' === $providerView ) { ?>
		<header class="ran-booster-page-shell__header ran-booster-provider__header">
			<p class="ran-booster-provider__eyebrow ran-booster-eyebrow"><?php esc_html_e( 'Repository provider', 'ran-booster' ); ?></p>
			<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title" data-ran-booster-provider-profile-focus tabindex="-1"><?php echo esc_html( $provider['label'] ); ?></h2>
			<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Configure private repository access and optional Push-to-Deploy updates.', 'ran-booster' ); ?></p>
		</header>
	<?php } else { ?>
		<a class="ran-booster-provider-management__back" href="<?php echo esc_url( $overviewUrl ); ?>">&larr; <?php echo esc_html( $providerBackLabel ); ?></a>
		<header class="ran-booster-provider-management__header">
			<div>
				<p class="ran-booster-provider__eyebrow ran-booster-eyebrow"><?php echo esc_html( 'credentials' === $providerView ? __( 'Repository access', 'ran-booster' ) : __( 'Push-to-Deploy prerequisite', 'ran-booster' ) ); ?></p>
				<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title" data-ran-booster-provider-profile-focus tabindex="-1"><?php echo esc_html( 'credentials' === $providerView ? __( 'Credentials', 'ran-booster' ) : __( 'Webhook secrets', 'ran-booster' ) ); ?></h2>
				<p class="ran-booster-page-heading__description">
					<?php
					echo esc_html(
						'credentials' === $providerView
							? $credentialManagementDescription
							: $secretManagementDescription
					);
					?>
				</p>
			</div>
			<?php if ( 'credentials' === $providerView && $hasCredentialSettings ) { ?>
				<button type="button" class="button button-primary ran-booster-open-credential-modal" data-modal="access"><?php esc_html_e( 'Add credential', 'ran-booster' ); ?></button>
			<?php } elseif ( 'secrets' === $providerView && $hasWebhookSettings ) { ?>
				<button type="button" class="button button-primary ran-booster-open-credential-modal" data-modal="webhook"><?php esc_html_e( 'Add webhook secret', 'ran-booster' ); ?></button>
			<?php } ?>
		</header>
	<?php } ?>

	<?php if ( $storageUnavailable ) { ?>
		<div class="notice notice-error inline ran-booster-provider__notice" data-ran-booster-provider-storage-notice>
			<p><strong><?php esc_html_e( 'Encrypted credential storage is unavailable.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Restore the matching sidecar and site key from the same backup before changing credentials.', 'ran-booster' ); ?></p>
		</div>
	<?php } ?>

	<?php if ( 'overview' === $providerView ) { ?>
		<?php if ( ! empty( $provider['credential_kinds'] ) ) { ?>
		<section class="ran-booster-provider-section" aria-labelledby="ran-booster-access-tokens-heading">
			<header class="ran-booster-provider-section__header">
				<h3 id="ran-booster-access-tokens-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository access', 'ran-booster' ); ?></h3>
				<p class="ran-booster-section__description"><?php echo esc_html( $provider['capabilities']['browse'] ? __( 'Saved credentials provide access to private repositories. Public repository discovery does not require one.', 'ran-booster' ) : __( 'Saved credentials provide access to private repositories entered manually.', 'ran-booster' ) ); ?></p>
			</header>
			<div class="ran-booster-provider-section__body">
				<?php
				$statusSummaryRenderer->render(
					$storageUnavailable
						? \RAN\Admin\Component\AdminStatusSummaryRenderer::ATTENTION
						: ( $credentialRowsNeedAttention
							? \RAN\Admin\Component\AdminStatusSummaryRenderer::ATTENTION
							: ( array() !== $credentialRows
								? \RAN\Admin\Component\AdminStatusSummaryRenderer::READY
								: \RAN\Admin\Component\AdminStatusSummaryRenderer::PENDING ) ),
					$storageUnavailable || $credentialRowsNeedAttention
						? __( 'Repository access needs attention', 'ran-booster' )
						: ( array() === $credentialRows
							? __( 'No credential saved', 'ran-booster' )
							: sprintf(
								/* translators: %d is the number of saved credentials. */
								_n( 'Ready · %d credential', 'Ready · %d credentials', count( $credentialRows ), 'ran-booster' ),
								count( $credentialRows )
							) ),
					$storageUnavailable
						? __( 'Restore encrypted credential storage before reviewing or changing saved repository access.', 'ran-booster' )
						: ( $credentialRowsNeedAttention
							? __( 'Review saved credentials that are incomplete, expired, or approaching expiry.', 'ran-booster' )
							: ( array() === $credentialRows
								? __( 'Public repositories remain available through anonymous lookup. Add a credential only for private access or steadier API limits.', 'ran-booster' )
								: __( 'Private repository access is available. Open credential management to validate, replace, or review usage.', 'ran-booster' ) ) ),
					static function () use ( $credentialRows, $credentialsUrl, $hasCredentialSettings, $storageUnavailable ): void {
						if ( array() === $credentialRows && $hasCredentialSettings ) {
							?>
							<button type="button" class="button ran-booster-open-credential-modal" data-modal="access"><?php esc_html_e( 'Add credential', 'ran-booster' ); ?></button>
							<?php
						} elseif ( ! $storageUnavailable ) {
							?>
							<a class="button" href="<?php echo esc_url( $credentialsUrl ); ?>"><?php esc_html_e( 'Manage credentials', 'ran-booster' ); ?></a>
							<?php
						}
					}
				);
			?>
			</div>
		</section>
		<?php } ?>

		<?php if ( null !== $publicLookupProfile ) { ?>
			<?php require __DIR__ . '/provider-public-lookup-profile.php'; ?>
		<?php } ?>

		<?php if ( $providerHasWebhookSettings ) { ?>
			<section id="ran-booster-webhook-secrets-heading" class="ran-booster-provider-section" aria-labelledby="ran-booster-push-to-deploy-heading">
				<header class="ran-booster-provider-section__header">
				<h3 id="ran-booster-push-to-deploy-heading" class="ran-booster-section__title"><?php esc_html_e( 'Push-to-Deploy', 'ran-booster' ); ?></h3>
				<p class="ran-booster-section__description">
						<?php
						printf(
							/* translators: %s is the repository provider name. */
							esc_html__( '%s push webhooks can trigger managed branch deployments whose Updates setting is Automatic.', 'ran-booster' ),
							esc_html( $provider['label'] )
						);
						?>
					</p>
					<p><?php esc_html_e( 'Booster verifies the webhook signature, matches the repository and branch, then queues only eligible managed packages.', 'ran-booster' ); ?></p>
				</header>
				<div class="ran-booster-provider-section__body">
					<?php if ( $webhookAssistanceProviderCapable && ! $webhookAssistanceSiteReady ) { ?>
						<div class="notice <?php echo esc_attr( $webhookHasHardFailure ? 'notice-error' : 'notice-warning' ); ?> inline ran-booster-push-deploy__notice" data-ran-booster-assistance-site-notice>
							<p><strong><?php esc_html_e( 'Push-to-Deploy needs attention', 'ran-booster' ); ?></strong><br><?php echo esc_html( implode( ' ', $webhookSiteReasons ) ); ?></p>
						</div>
					<?php } ?>

					<?php
					$statusSummaryRenderer->render(
						$storageUnavailable
							? \RAN\Admin\Component\AdminStatusSummaryRenderer::ATTENTION
							: ( $webhookRowsNeedAttention || ( array() === $webhookRows && 0 < $automaticPackageCount )
								? \RAN\Admin\Component\AdminStatusSummaryRenderer::ATTENTION
								: ( array() !== $webhookRows
									? \RAN\Admin\Component\AdminStatusSummaryRenderer::READY
									: \RAN\Admin\Component\AdminStatusSummaryRenderer::PENDING ) ),
						$storageUnavailable || $webhookRowsNeedAttention || ( array() === $webhookRows && 0 < $automaticPackageCount )
							? __( 'Webhook signing · Needs attention', 'ran-booster' )
							: ( array() === $webhookRows
								? __( 'Webhook signing · No secret saved', 'ran-booster' )
								: __( 'Webhook signing · Ready locally', 'ran-booster' ) ),
						$storageUnavailable
							? __( 'Restore encrypted credential storage before Push-to-Deploy can verify signed deliveries.', 'ran-booster' )
							: ( $webhookRowsNeedAttention
								? __( 'Review saved signing material whose configuration or managed-package usage could not be confirmed.', 'ran-booster' )
								: ( array() === $webhookRows
									? ( 0 < $automaticPackageCount
										? __( 'Automatic branch deployments require local signing material before provider webhooks can be used safely.', 'ran-booster' )
										: __( 'Add local signing material before configuring a provider webhook.', 'ran-booster' ) )
									: sprintf(
								/* translators: %d is the number of local webhook secrets. */
										_n( '%d local secret can verify signed deliveries. This does not prove a matching remote webhook exists.', '%d local secrets can verify signed deliveries. This does not prove matching remote webhooks exist.', count( $webhookRows ), 'ran-booster' ),
										count( $webhookRows )
									) ) ),
						static function () use ( $hasWebhookSettings, $secretsUrl, $storageUnavailable, $webhookRows ): void {
							if ( array() === $webhookRows && $hasWebhookSettings ) {
								?>
								<button type="button" class="button ran-booster-open-credential-modal" data-modal="webhook"><?php esc_html_e( 'Add webhook secret', 'ran-booster' ); ?></button>
								<?php
							} elseif ( ! $storageUnavailable ) {
								?>
								<a class="button" href="<?php echo esc_url( $secretsUrl ); ?>"><?php esc_html_e( 'Manage secrets', 'ran-booster' ); ?></a>
								<?php
							}
						}
					);
			?>

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
						<nav class="ran-booster-provider-task-tabs" aria-label="<?php esc_attr_e( 'Push-to-Deploy tasks', 'ran-booster' ); ?>" hx-boost="true">
							<?php
							foreach ( array(
								'status'       => __( 'Status', 'ran-booster' ),
								'repositories' => __( 'Repositories', 'ran-booster' ),
								'setup'        => __( 'Webhook setup', 'ran-booster' ),
							) as $task => $label ) {
								?>
								<a class="ran-booster-provider-task-tab" href="<?php echo esc_url( $taskUrl( $task ) ); ?>" hx-get="<?php echo esc_url( $taskRequestUrl( $task ) ); ?>" data-ran-booster-provider-task="<?php echo esc_attr( $task ); ?>" aria-controls="ran-booster-provider-task-panel" <?php echo $providerTask === $task ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
							<?php } ?>
							<p class="ran-booster-provider-task-progress" data-ran-booster-provider-task-progress role="status" aria-live="polite" hidden>
								<span class="spinner is-active" aria-hidden="true"></span>
								<span><?php esc_html_e( 'Loading provider details…', 'ran-booster' ); ?></span>
							</p>
						</nav>
						<div class="notice notice-error inline ran-booster-provider-task-error" data-ran-booster-provider-task-error role="alert" tabindex="-1" hidden>
							<p><?php esc_html_e( 'Booster could not load that provider view. The current view is unchanged; choose the task again to retry.', 'ran-booster' ); ?></p>
						</div>

						<?php if ( 'status' === $providerTask ) { ?>
							<section id="ran-booster-provider-task-panel" class="ran-booster-provider-task-panel" data-ran-booster-provider-task="status" aria-labelledby="ran-booster-provider-status-heading">
							<div class="ran-booster-provider-task-panel__heading">
								<div>
									<h4 id="ran-booster-provider-status-heading" class="ran-booster-section__title"><?php esc_html_e( 'Readiness overview', 'ran-booster' ); ?></h4>
									<p class="ran-booster-section__description"><?php esc_html_e( 'Resolve site-level blockers here; manage individual repositories in the Repositories view.', 'ran-booster' ); ?></p>
								</div>
							</div>
							<div class="ran-booster-readiness-overview">
								<article>
									<p class="ran-booster-provider__eyebrow ran-booster-eyebrow"><?php esc_html_e( 'Site URL', 'ran-booster' ); ?></p>
									<strong><?php echo esc_html( $webhookAssistanceSiteReady ? __( 'Public delivery ready', 'ran-booster' ) : __( 'Public delivery unavailable', 'ran-booster' ) ); ?></strong>
									<p><?php echo esc_html( $webhookAssistanceSiteReady ? __( 'The payload URL is structurally ready to receive provider deliveries.', 'ran-booster' ) : __( 'Review the blocking reason above before testing provider delivery.', 'ran-booster' ) ); ?></p>
									<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php esc_html_e( 'Review WordPress URLs', 'ran-booster' ); ?></a>
								</article>
								<article>
									<p class="ran-booster-provider__eyebrow ran-booster-eyebrow"><?php esc_html_e( 'Managed packages', 'ran-booster' ); ?></p>
									<strong><?php echo esc_html( $automaticPackageLabel ); ?></strong>
									<p><?php echo esc_html( $managedPackageDescription ); ?> <?php esc_html_e( 'Manual and Disabled packages ignore pushes.', 'ran-booster' ); ?></p>
								<a class="button" href="<?php echo esc_url( $taskUrl( 'repositories' ) ); ?>" hx-get="<?php echo esc_url( $taskRequestUrl( 'repositories' ) ); ?>" hx-boost="true"><?php esc_html_e( 'Review repositories', 'ran-booster' ); ?></a>
							</article>
						</div>
						<div class="ran-booster-provider-next-step">
								<div>
									<strong><?php esc_html_e( 'Recommended next step', 'ran-booster' ); ?></strong>
									<p><?php echo esc_html( $webhookAssistanceSiteReady ? __( 'Configure and verify one repository webhook, then enable Automatic deployment from package settings.', 'ran-booster' ) : __( 'Configure a public HTTPS site URL before testing delivery or enabling Automatic deployment.', 'ran-booster' ) ); ?></p>
								</div>
							<a class="button button-primary" href="<?php echo esc_url( $taskUrl( 'setup' ) ); ?>" hx-get="<?php echo esc_url( $taskRequestUrl( 'setup' ) ); ?>" hx-boost="true"><?php esc_html_e( 'Review webhook setup', 'ran-booster' ); ?></a>
						</div>
					</section>
				<?php } elseif ( 'setup' === $providerTask ) { ?>
					<section id="ran-booster-provider-task-panel" class="ran-booster-provider-task-panel" data-ran-booster-provider-task="setup" aria-labelledby="ran-booster-webhook-instructions-heading">
							<div class="ran-booster-provider-task-panel__heading">
								<div>
									<h4 id="ran-booster-webhook-instructions-heading" class="ran-booster-section__title"><?php esc_html_e( 'Webhook setup', 'ran-booster' ); ?></h4>
									<p class="ran-booster-section__description"><?php esc_html_e( 'Complete these steps for one repository, verify a real provider delivery, then enable Automatic deployment deliberately.', 'ran-booster' ); ?></p>
								</div>
								<?php if ( null !== $webhookSetup ) { ?>
									<a class="button" href="<?php echo esc_url( $webhookSetup['documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $providerInstructionsLabel ); ?></a>
								<?php } ?>
							</div>
							<div class="ran-booster-webhook-steps">
								<article><span>1</span><strong><?php esc_html_e( 'Choose a signing secret', 'ran-booster' ); ?></strong><p><?php echo esc_html( $secretChoiceDescription ); ?></p></article>
								<article><span>2</span><strong><?php echo esc_html( $createProviderWebhookLabel ); ?></strong><p><?php esc_html_e( 'Paste the payload URL and shared secret, keep SSL verification enabled, and select the configured push event.', 'ran-booster' ); ?></p></article>
								<article><span>3</span><strong><?php esc_html_e( 'Verify before enabling', 'ran-booster' ); ?></strong><p><?php esc_html_e( 'Send a real delivery, confirm a successful provider response, then enable Automatic from package settings.', 'ran-booster' ); ?></p></article>
							</div>
							<dl class="ran-booster-webhook-endpoint">
								<div>
									<dt id="ran-booster-webhook-url-label"><?php esc_html_e( 'Payload URL', 'ran-booster' ); ?></dt>
									<dd><span class="ran-booster-webhook-url" data-webhook-url-tools><input type="text" class="regular-text code" value="<?php echo esc_attr( $webhookEndpoint ); ?>" readonly aria-labelledby="ran-booster-webhook-url-label" data-webhook-url><button type="button" class="button" data-webhook-url-copy data-copy-label="<?php esc_attr_e( 'Copy URL', 'ran-booster' ); ?>" data-copied-label="<?php esc_attr_e( 'URL copied', 'ran-booster' ); ?>"><?php esc_html_e( 'Copy URL', 'ran-booster' ); ?></button><span class="ran-booster-portability__password-status" data-webhook-url-status data-copied-message="<?php esc_attr_e( 'Payload URL copied to the clipboard.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The payload URL is selected; use your browser copy command.', 'ran-booster' ); ?>" role="status" aria-live="polite" aria-atomic="true"></span></span></dd>
								</div>
								<div><dt><?php esc_html_e( 'Content type', 'ran-booster' ); ?></dt><dd><code>application/json</code></dd></div>
								<?php
								if ( null !== $webhookSetup ) {
									?>
									<div><dt><?php esc_html_e( 'Event', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $webhookSetup['event'] ); ?></dd></div><?php } ?>
							</dl>
							<?php if ( null !== $webhookSetup ) { ?>
								<details class="ran-booster-provider-disclosure">
									<summary><?php esc_html_e( 'Detailed manual setup and troubleshooting', 'ran-booster' ); ?></summary>
									<div class="ran-booster-provider-disclosure__body">
										<p><?php echo esc_html( $manualSetupDescription ); ?></p>
										<ol><li><?php esc_html_e( 'Paste the payload URL and matching secret.', 'ran-booster' ); ?></li><li><?php esc_html_e( 'Keep SSL verification enabled and select the push event.', 'ran-booster' ); ?></li><li><?php esc_html_e( 'Trigger a delivery and confirm the provider records a successful response.', 'ran-booster' ); ?></li></ol>
										<p><a href="<?php echo esc_url( $webhookSetup['delivery_documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Delivery troubleshooting', 'ran-booster' ); ?></a></p>
									</div>
								</details>
							<?php } ?>
				</section>
				<?php } else { ?>
					<?php
					$requestedRepositoryId = '';
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only selection; every operation has its own nonce.
					$requestedRepositoryValue = $_GET['repository'] ?? $_GET['assisted_repository'] ?? null;
					if ( is_string( $requestedRepositoryValue ) ) {
						$candidate = trim( wp_unslash( $requestedRepositoryValue ) );
						if ( '' !== $candidate && strlen( $candidate ) <= 191 && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $candidate ) ) {
							$requestedRepositoryId = $candidate;
						}
					}
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
					?>
				<section id="ran-booster-provider-task-panel" class="ran-booster-provider-task-panel" data-ran-booster-provider-task="repositories" aria-labelledby="ran-booster-managed-webhook-repositories-heading">
							<div class="ran-booster-provider-task-panel__heading">
								<div>
									<h4 id="ran-booster-managed-webhook-repositories-heading" class="ran-booster-section__title"><?php echo esc_html( '' === $requestedRepositoryId ? __( 'Managed repositories', 'ran-booster' ) : __( 'Repository webhook', 'ran-booster' ) ); ?></h4>
									<p class="ran-booster-section__description"><?php echo esc_html( $repositoryWebhookDescription ); ?></p>
								</div>
							</div>
							<?php
							$repositoryTableRows   = array();
							$repositoryProjections = array();
							$repositoryListUrl     = $taskUrl( 'repositories' );
							$providerReturnUrl     = '' === $requestedRepositoryId
								? $repositoryListUrl
								: $providerUrl(
									array(
										'panel'      => 'repositories',
										'repository' => $requestedRepositoryId,
									)
								);
							foreach ( $providerRepositories['repositories'] as $repositoryIndex => $repository ) {
								$managedRepositoryId = is_string( $repository['repository_id'] ?? null ) ? $repository['repository_id'] : '';
								$repositoryLocator   = is_string( $repository['target'] ?? null ) ? $repository['target'] : '';
								$repositorySource    = is_string( $repository['source'] ?? null ) ? $repository['source'] : 'branch';
								$isReleaseSource     = 'release_asset' === $repositorySource;
								$retainedWebhook     = $isReleaseSource && is_array( $repository['retained_webhook'] ?? null )
									? $repository['retained_webhook']
									: array();
								$branchConsumers     = array_values( array_filter( $retainedWebhook['branch_package_references'] ?? array(), 'is_string' ) );
								$assistanceCandidate = ! $isReleaseSource && '' !== $managedRepositoryId && isset( $webhookReadinessByRepositoryId[ $managedRepositoryId ] )
									? $webhookReadinessByRepositoryId[ $managedRepositoryId ]
									: ( ! $isReleaseSource ? ( $webhookReadinessByRepository[ strtolower( $repositoryLocator ) ] ?? null ) : null );
								$repositoryId        = $isReleaseSource
									? $managedRepositoryId
									: ( is_string( $assistanceCandidate['repository_id'] ?? null ) ? $assistanceCandidate['repository_id'] : '' );
								$rowKey              = '' !== $repositoryId
									? $repositoryId
									: 'repository:' . hash( 'sha256', $provider['code'] . '|' . strtolower( $repositoryLocator ) . '|' . $repositorySource );
								$reasonCodes         = is_array( $assistanceCandidate['reason_codes'] ?? null ) ? $assistanceCandidate['reason_codes'] : array();
								$issues              = array_values( array_filter( array_map( static fn ( mixed $code ): ?string => is_string( $code ) ? ( $webhookRepositoryIssueLabels[ $code ] ?? null ) : null, $reasonCodes ) ) );
								$coverage            = $isReleaseSource
									? ( is_string( $retainedWebhook['local_secret_coverage'] ?? null ) ? $retainedWebhook['local_secret_coverage'] : 'unknown' )
									: ( is_string( $assistanceCandidate['local_secret_coverage'] ?? null ) ? $assistanceCandidate['local_secret_coverage'] : 'unknown' );
								$policies            = is_array( $assistanceCandidate['deployment_policies'] ?? null ) ? $assistanceCandidate['deployment_policies'] : ( is_array( $repository['deployment_policies'] ?? null ) ? $repository['deployment_policies'] : array() );
								$packageReferences   = is_array( $assistanceCandidate['package_references'] ?? null ) ? $assistanceCandidate['package_references'] : ( is_array( $repository['package_references'] ?? null ) ? $repository['package_references'] : array() );
								$packageReferences   = array_values( array_filter( $packageReferences, 'is_string' ) );
								$automaticCount      = (int) ( $policies['automatic'] ?? $repository['automatic_count'] ?? 0 );
								$manualCount         = (int) ( $policies['manual'] ?? 0 );
								$disabledCount       = (int) ( $policies['disabled'] ?? 0 );
								$policyBadges        = array(
									array(
										/* translators: %d is the number of Automatic packages using this repository. */
										'label' => sprintf( __( 'Automatic: %d', 'ran-booster' ), $automaticCount ),
										'tone'  => 'neutral',
									),
									array(
										/* translators: %d is the number of Manual packages using this repository. */
										'label' => sprintf( __( 'Manual: %d', 'ran-booster' ), $manualCount ),
										'tone'  => 'neutral',
									),
									array(
										/* translators: %d is the number of Disabled packages using this repository. */
										'label' => sprintf( __( 'Disabled: %d', 'ran-booster' ), $disabledCount ),
										'tone'  => 'neutral',
									),
								);
								if ( 1 === count( $packageReferences ) ) {
									$policyBadges = array(
										match ( true ) {
											1 === $automaticCount => array(
												'label' => __( 'Automatic', 'ran-booster' ),
												'tone'  => 'ok',
											),
											1 === $manualCount    => array(
												'label' => __( 'Manual', 'ran-booster' ),
												'tone'  => 'pending',
											),
											default              => array(
												'label' => __( 'Disabled', 'ran-booster' ),
												'tone'  => 'neutral',
											),
										},
									);
								}
								$packageTypes = array();
								foreach ( $packageReferences as $packageReference ) {
									$isPlugin                   = str_ends_with( strtolower( $packageReference ), '.php' );
									$typeLabel                  = $isPlugin ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' );
									$packageTypes[ $typeLabel ] = array(
										'label' => $typeLabel,
										'tone'  => 'pending',
									);
								}
								$packageTypeLabel = match ( count( $packageTypes ) ) {
									0       => __( 'Package', 'ran-booster' ),
									1       => (string) array_key_first( $packageTypes ),
									default => __( 'Plugins and themes', 'ran-booster' ),
								};
								$reasonId = 'ran-booster-provider-readiness-reason-' . (int) $repositoryIndex;
								$statuses = array();
								if ( '' !== ( $issues[0] ?? '' ) ) {
									$statuses[] = array(
										'label' => $issues[0],
										'tone'  => 'error',
										'id'    => $reasonId,
									);
								}
								$statuses[] = array(
									'label' => match ( $coverage ) {
										'repository' => __( 'Repository secret', 'ran-booster' ),
										'shared'     => $sharedWebhookSecretLabel,
										'none'       => __( 'No secret', 'ran-booster' ),
										default      => __( 'Secret coverage unavailable', 'ran-booster' ),
									},
									'tone'  => in_array( $coverage, array( 'repository', 'shared' ), true ) ? 'ok' : 'warning',
								);
								if ( ! $webhookAssistanceSiteReady ) {
									$statuses[] = array(
										'label' => __( 'Push-to-Deploy disabled', 'ran-booster' ),
										'tone'  => 'error',
										'id'    => $reasonId . '-site',
									);
								}
								if ( $isReleaseSource ) {
									$statuses = array();
								}
								$nonZeroPolicies = array_filter(
									array(
										'automatic' => $automaticCount,
										'manual'    => $manualCount,
										'disabled'  => $disabledCount,
									),
									static fn ( int $count ): bool => 0 < $count
								);
								$managementLabel = 1 === count( $nonZeroPolicies )
									? match ( (string) array_key_first( $nonZeroPolicies ) ) {
										'automatic' => __( 'Automatic', 'ran-booster' ),
										'manual'    => __( 'Manual', 'ran-booster' ),
										default     => __( 'Disabled', 'ran-booster' ),
									}
									: __( 'Mixed policies', 'ran-booster' );
								$managementDetail = match ( $coverage ) {
									'repository'     => __( 'Repository secret', 'ran-booster' ),
									'shared'         => $sharedWebhookSecretLabel,
									'none'           => __( 'No secret', 'ran-booster' ),
									'not_applicable' => '',
									default          => __( 'Secret coverage unavailable', 'ran-booster' ),
								};
								$managementTone = in_array( $coverage, array( 'repository', 'shared' ), true ) ? 'ok' : 'warning';
								$consequence    = match ( true ) {
									$isReleaseSource && array() !== $branchConsumers
										=> __( 'This package ignores pushes. Branch-managed packages in this repository still use webhook setup.', 'ran-booster' ),
									$isReleaseSource && in_array( $coverage, array( 'repository', 'shared' ), true )
										=> __( 'This package ignores pushes. Local signing setup is retained for an easier return to Branch.', 'ran-booster' ),
									$isReleaseSource
										=> __( 'Pushes are ignored.', 'ran-booster' ),
									1 === count( $nonZeroPolicies ) && isset( $nonZeroPolicies['disabled'] )
										=> __( 'Push-to-Deploy disabled; pushes are ignored.', 'ran-booster' ),
									'' !== ( $issues[0] ?? '' )
										=> (string) $issues[0],
									! $webhookAssistanceSiteReady
										=> __( 'Push-to-Deploy is unavailable until the site-level readiness issue is resolved.', 'ran-booster' ),
									'none' === $coverage
										=> __( 'Push-to-Deploy is blocked until a signing secret is selected.', 'ran-booster' ),
									1 === count( $nonZeroPolicies ) && isset( $nonZeroPolicies['automatic'] )
										=> __( 'Push-to-Deploy enabled; signed pushes can queue eligible packages.', 'ran-booster' ),
									1 === count( $nonZeroPolicies ) && isset( $nonZeroPolicies['manual'] )
										=> __( 'Push-to-Deploy remains off until the package Updates setting is Automatic.', 'ran-booster' ),
									default
										=> __( 'Only Automatic packages can respond to signed pushes.', 'ran-booster' ),
								};
								if ( $isReleaseSource ) {
									$managementLabel  = __( 'Published release', 'ran-booster' );
									$managementDetail = __( 'Push-to-Deploy unavailable', 'ran-booster' );
									$managementTone   = 'info';
								}
								$releaseReasonId = $isReleaseSource && '' !== $consequence ? $reasonId . '-release-source' : '';
								$describedBy     = array_filter( array( $providerAssistanceDescriptionId, $releaseReasonId, '' !== ( $issues[0] ?? '' ) ? $reasonId : '', ! $webhookAssistanceSiteReady && ! $isReleaseSource ? $reasonId . '-site' : '' ) );
								$actions         = array();
								if ( ! $isReleaseSource && '' !== $repositoryId && '' === $requestedRepositoryId ) {
									$actions['core:manage-repository'] = array(
										'key'           => 'core:manage-repository',
										'label'         => __( 'Manage webhook', 'ran-booster' ),
										'type'          => 'link',
										'url'           => $providerUrl(
											array(
												'panel' => 'repositories',
												'repository' => $repositoryId,
											)
										),
										'hidden'        => array(),
										'disabled'      => false,
										'external'      => false,
										'described_by'  => '',
										'screen_reader' => $repositoryLocator,
									);
								}
								if ( null !== $providerWebhookAssistance ) {
									$actions = array_merge(
										$actions,
										$repositoryComposition->dormantAssistanceAction(
											$providerWebhookAssistance,
											$repositoryLocator,
											$describedBy
										)
									);
								}
								if ( $isReleaseSource && in_array( $coverage, array( 'repository', 'shared' ), true ) ) {
									$cleanupReviewUrl = '';
									$cleanupReference = $packageReferences[0] ?? '';
									if ( is_string( $cleanupReference ) && '' !== $cleanupReference ) {
										$cleanupIsPlugin = str_ends_with( strtolower( $cleanupReference ), '.php' );
										if ( $cleanupIsPlugin || 1 === preg_match( '/^[A-Za-z0-9_.-]+$/', $cleanupReference ) ) {
											$cleanupReviewUrl = admin_url( 'admin.php?page=' . ( $cleanupIsPlugin ? 'ran-booster-plugins' : 'ran-booster-themes' ) . '&package=' . rawurlencode( $cleanupReference ) . '&webhook_cleanup=1#ran-booster-webhook-cleanup' );
										}
									}
									$actions['core:webhook-cleanup-review'] = array(
										'key'           => 'core:webhook-cleanup-review',
										'label'         => __( 'Review webhook cleanup', 'ran-booster' ),
										'type'          => 'link',
										'url'           => $cleanupReviewUrl,
										'hidden'        => array(),
										'disabled'      => '' === $cleanupReviewUrl,
										'external'      => false,
										'described_by'  => $releaseReasonId,
										'screen_reader' => $repositoryLocator,
									);
								} elseif ( $isReleaseSource ) {
									$actions['core:provider-webhooks'] = array(
										'key'           => 'core:provider-webhooks',
										'label'         => $providerWebhookSettingsLabel,
										'type'          => 'link',
										'url'           => '',
										'hidden'        => array(),
										'disabled'      => true,
										'external'      => true,
										'described_by'  => $releaseReasonId,
										'screen_reader' => $repositoryLocator,
									);
								} elseif ( is_string( $repository['webhook_settings_url'] ?? null ) ) {
									$actions['core:provider-webhooks'] = array(
										'key'           => 'core:provider-webhooks',
										'label'         => $providerWebhookSettingsLabel,
										'type'          => 'link',
										'url'           => $repository['webhook_settings_url'],
										'hidden'        => array(),
										'disabled'      => false,
										'external'      => true,
										'described_by'  => '',
										'screen_reader' => $repositoryLocator,
									);
								}
								foreach ( $packageReferences as $packageReference ) {
									$isPlugin = str_ends_with( strtolower( $packageReference ), '.php' );
									if ( ! $isPlugin && 1 !== preg_match( '/^[A-Za-z0-9_.-]+$/', $packageReference ) ) {
										continue;
									}
									$actionKey             = 'core:package-' . substr( hash( 'sha256', $packageReference ), 0, 16 );
									$actions[ $actionKey ] = array(
										'key'           => $actionKey,
										'label'         => $isPlugin ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ),
										'type'          => 'link',
										'url'           => admin_url( 'admin.php?page=' . ( $isPlugin ? 'ran-booster-plugins' : 'ran-booster-themes' ) . '&package=' . rawurlencode( $packageReference ) ),
										'hidden'        => array(),
										'disabled'      => false,
										'external'      => false,
										'described_by'  => '',
										'screen_reader' => $packageReference,
									);
								}
								$secretTarget                   = 'shared' === $coverage
									? (string) strtok( $repositoryLocator, '/' )
									: $repositoryLocator;
								$secretLink                     = 'none' === $coverage
									? array(
										'label'  => __( 'Add repository secret', 'ran-booster' ),
										'url'    => $providerUrl(
											array_filter(
												array(
													'panel'             => 'repositories',
													'repository'        => $repositoryId,
													'add_webhook_secret' => 1,
													'webhook_scope'     => 'repository',
													'webhook_target'    => $repositoryLocator,
												),
												static fn ( mixed $value ): bool => '' !== $value
											)
										),
										'modal'  => 'webhook',
										'scope'  => 'repository',
										'target' => $repositoryLocator,
									)
									: ( in_array( $coverage, array( 'repository', 'shared' ), true )
										? array(
											'label'  => 'shared' === $coverage ? __( 'Review shared owner secret', 'ran-booster' ) : __( 'Review repository secret', 'ran-booster' ),
											'url'    => $providerUrl(
												array(
													'view' => 'secrets',
													's'    => $secretTarget,
												)
											),
											'modal'  => '',
											'scope'  => '',
											'target' => '',
										)
										: null );
								$repositoryTableRows[ $rowKey ] = array(
									'key'                => $rowKey,
									'provider_code'      => $provider['code'],
									'repository_id'      => $repositoryId,
									'historical'         => false,
									'provider_label'     => $provider['label'],
									'repository'         => $repositoryLocator,
									'repository_url'     => is_string( $repository['repository_url'] ?? null ) ? $repository['repository_url'] : '',
									'package_type_label' => $packageTypeLabel,
									'source_key'         => $repositorySource,
									'source_label'       => $isReleaseSource ? __( 'Published release', 'ran-booster' ) : __( 'Branch', 'ran-booster' ),
									'management_label'   => $managementLabel,
									'management_detail'  => $managementDetail,
									'management_tone'    => $managementTone,
									'consequence'        => $consequence,
									'consequence_id'     => $releaseReasonId,
									'types'              => array_values( $packageTypes ),
									'policies'           => $policyBadges,
									'package_references' => $packageReferences,
									'statuses'           => $statuses,
									'status_links'       => null === $secretLink ? array() : array( $secretLink ),
									'actions'            => $actions,
								);
								if ( ! $isReleaseSource ) {
									$repositoryProjections[ $rowKey ] = array(
										'provider_code' => $provider['code'],
										'repository_id' => $repositoryId,
										'repository'    => $repositoryLocator,
										'label'         => $repositoryLocator,
										'package_references' => $packageReferences,
										'deployment_policies' => array(
											'automatic' => $automaticCount,
											'manual'    => $manualCount,
											'disabled'  => $disabledCount,
										),
										'endpoint'      => $webhookAssistanceSiteEndpoint,
										'eligible'      => is_array( $assistanceCandidate ) && true === ( $assistanceCandidate['eligible'] ?? false ) && $webhookAssistanceSiteReady && '' !== $repositoryId,
										'reason_codes'  => $reasonCodes,
										'local_secret_coverage' => $coverage,
									);
								}
							}
							$repositoryTableRows   = $repositoryComposition->rows( $repositoryTableRows, $provider['code'], $repositoryProjections, $providerReturnUrl );
							$selectedRepositoryRow = null;
							if ( '' !== $requestedRepositoryId ) {
								foreach ( $repositoryTableRows as $repositoryRow ) {
									if ( false === ( $repositoryRow['historical'] ?? false )
										&& $requestedRepositoryId === ( $repositoryRow['repository_id'] ?? null ) ) {
										$selectedRepositoryRow = $repositoryRow;
										break;
									}
								}
							}
							?>
							<?php if ( null !== $providerWebhookAssistance ) { ?>
								<?php $repositoryComposition->renderAssistanceNote( $providerWebhookAssistance, $provider['code'], $providerAssistanceDescriptionId ); ?>
							<?php } ?>
							<?php if ( '' !== $requestedRepositoryId ) { ?>
								<p><a href="<?php echo esc_url( $repositoryListUrl ); ?>">&larr; <?php esc_html_e( 'Back to managed repositories', 'ran-booster' ); ?></a></p>
								<?php if ( is_array( $selectedRepositoryRow ) ) { ?>
									<?php ( new \RAN\Admin\Component\RepositoryTableRenderer() )->render( 'ran-booster-managed-webhook-repositories-heading', array( $selectedRepositoryRow ) ); ?>
								<?php } else { ?>
									<div class="notice notice-error inline"><p><?php esc_html_e( 'That managed repository is no longer available. Return to the repository list and choose a current repository.', 'ran-booster' ); ?></p></div>
								<?php } ?>
							<?php } elseif ( array() !== $repositoryTableRows ) { ?>
								<?php
								$repositoryRowCount = count( $repositoryTableRows );
								/* translators: %d is the number of repository rows shown. */
								$repositoryRowCountLabel = sprintf( _n( '%d repository shown', '%d repositories shown', $repositoryRowCount, 'ran-booster' ), $repositoryRowCount );
								?>
								<div class="ran-booster-provider-repository-tools">
									<label class="screen-reader-text" for="ran-booster-provider-repository-search"><?php esc_html_e( 'Search managed repositories', 'ran-booster' ); ?></label>
									<input id="ran-booster-provider-repository-search" type="search" placeholder="<?php esc_attr_e( 'Search managed repositories…', 'ran-booster' ); ?>" data-ran-booster-provider-repository-filter>
									<span
										data-ran-booster-provider-repository-count
										data-singular="<?php echo esc_attr( $repositoryCountSingular ); ?>"
										data-plural="<?php echo esc_attr( $repositoryCountPlural ); ?>"
										aria-live="polite"
									>
										<?php echo esc_html( $repositoryRowCountLabel ); ?>
									</span>
								</div>
								<?php ( new \RAN\Admin\Component\RepositoryTableRenderer() )->render( 'ran-booster-managed-webhook-repositories-heading', array_values( $repositoryTableRows ) ); ?>
							<?php } elseif ( ! empty( $managedRepositories['available'] ) ) { ?>
								<div class="ran-booster-provider-empty-actions">
									<p><?php echo esc_html( $emptyRepositoryDescription ); ?></p>
									<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-plugins-create&provider=' . rawurlencode( $provider['code'] ) ) ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
									<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ran-booster-themes-create&provider=' . rawurlencode( $provider['code'] ) ) ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a>
								</div>
							<?php } else { ?>
								<p class="description"><?php esc_html_e( 'Managed repository status is temporarily unavailable.', 'ran-booster' ); ?></p>
							<?php } ?>
							<?php
							if ( is_array( $selectedRepositoryRow ) ) {
								$repositoryComposition->renderPanel( $provider['code'], $requestedRepositoryId, $providerReturnUrl );
							}
							?>
				</section>
			<?php } ?>
					</div>
				</div>
			</section>
		<?php } ?>
	<?php } elseif ( 'credentials' === $providerView ) { ?>
		<?php if ( $hasCredentialSettings ) { ?>
			<form class="ran-booster-provider-list-controls" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="ran-booster"><input type="hidden" name="tab" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="view" value="credentials">
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $providerListState['orderby'] ); ?>"><input type="hidden" name="order" value="<?php echo esc_attr( $providerListState['order'] ); ?>"><input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $providerListState['per_page'] ); ?>">
				<div>
					<label class="screen-reader-text" for="ran-booster-credential-kind-filter"><?php esc_html_e( 'Filter by credential type', 'ran-booster' ); ?></label>
					<select id="ran-booster-credential-kind-filter" name="kind"><option value=""><?php esc_html_e( 'All credential types', 'ran-booster' ); ?></option>
					<?php
					foreach ( $provider['credential_kinds'] as $kind ) {
						?>
						<option value="<?php echo esc_attr( $kind['code'] ); ?>" <?php selected( $kind['code'], $providerListState['kind'] ); ?>><?php echo esc_html( $kind['label'] ); ?></option><?php } ?></select>
					<label class="screen-reader-text" for="ran-booster-credential-scope-filter"><?php esc_html_e( 'Filter by scope', 'ran-booster' ); ?></label>
					<select id="ran-booster-credential-scope-filter" name="scope"><option value=""><?php esc_html_e( 'All scopes', 'ran-booster' ); ?></option>
					<?php
					foreach ( $credentialScopes as $scopeKey => $scopeLabel ) {
						?>
						<option value="<?php echo esc_attr( $scopeKey ); ?>" <?php selected( $scopeKey, $providerListState['scope'] ); ?>><?php echo esc_html( $scopeLabel ); ?></option><?php } ?></select>
				</div>
				<div><label class="screen-reader-text" for="ran-booster-credential-search"><?php esc_html_e( 'Search credentials', 'ran-booster' ); ?></label><input id="ran-booster-credential-search" type="search" name="s" value="<?php echo esc_attr( $providerListState['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search credentials…', 'ran-booster' ); ?>"><button class="button" type="submit"><?php esc_html_e( 'Search', 'ran-booster' ); ?></button></div>
			</form>
			<?php
			$providerManagementTableRenderer->render(
				\RAN\Admin\Component\ProviderManagementTableRenderer::ACCESS,
				$credentialList['rows'],
				__( 'No credentials match the current filters.', 'ran-booster' ),
				$renderCredentialCell,
				static fn ( string $orderby ): string => $sortUrl( 'credentials', $orderby ),
				array(
					'item_count_label' => $credentialItemCountLabel,
					'page_label'       => $credentialPageLabel,
					'current'          => $credentialList['current'],
					'pages'            => $credentialList['pages'],
					'per_page'         => $providerListState['per_page'],
					'action_url'       => admin_url( 'admin.php' ),
					'hidden_fields'    => array(
						'page'    => 'ran-booster',
						'tab'     => $provider['code'],
						'view'    => 'credentials',
						's'       => $providerListState['search'],
						'kind'    => $providerListState['kind'],
						'scope'   => $providerListState['scope'],
						'orderby' => $providerListState['orderby'],
						'order'   => $providerListState['order'],
					),
					'page_url'         => static fn ( int $page ): string => $pageUrl( 'credentials', $page ),
				)
			);
			?>
		<?php } ?>
	<?php } elseif ( 'secrets' === $providerView ) { ?>
		<?php if ( $hasWebhookSettings ) { ?>
			<div id="ran-booster-delete-webhook-profile-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>
			<form class="ran-booster-provider-list-controls" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"><input type="hidden" name="page" value="ran-booster"><input type="hidden" name="tab" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="view" value="secrets"><input type="hidden" name="orderby" value="<?php echo esc_attr( $providerListState['orderby'] ); ?>"><input type="hidden" name="order" value="<?php echo esc_attr( $providerListState['order'] ); ?>"><input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $providerListState['per_page'] ); ?>"><div><label class="screen-reader-text" for="ran-booster-secret-scope-filter"><?php esc_html_e( 'Filter by scope', 'ran-booster' ); ?></label><select id="ran-booster-secret-scope-filter" name="scope"><option value=""><?php esc_html_e( 'All scopes', 'ran-booster' ); ?></option>
			<?php
			foreach ( $provider['webhook_scopes'] as $scope ) {
				?>
				<option value="<?php echo esc_attr( $scope['code'] ); ?>" <?php selected( $scope['code'], $providerListState['scope'] ); ?>><?php echo esc_html( $scope['label'] ); ?></option><?php } ?></select><label class="screen-reader-text" for="ran-booster-secret-status-filter"><?php esc_html_e( 'Filter by status', 'ran-booster' ); ?></label><select id="ran-booster-secret-status-filter" name="status"><option value=""><?php esc_html_e( 'All statuses', 'ran-booster' ); ?></option><option value="ready" <?php selected( 'ready', $providerListState['status'] ); ?>><?php esc_html_e( 'Ready locally', 'ran-booster' ); ?></option><option value="attention" <?php selected( 'attention', $providerListState['status'] ); ?>><?php esc_html_e( 'Needs attention', 'ran-booster' ); ?></option></select></div><div><label class="screen-reader-text" for="ran-booster-secret-search"><?php esc_html_e( 'Search webhook secrets', 'ran-booster' ); ?></label><input id="ran-booster-secret-search" type="search" name="s" value="<?php echo esc_attr( $providerListState['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search secrets…', 'ran-booster' ); ?>"><button class="button" type="submit"><?php esc_html_e( 'Search', 'ran-booster' ); ?></button></div></form>
			<?php
			$providerManagementTableRenderer->render(
				\RAN\Admin\Component\ProviderManagementTableRenderer::WEBHOOK,
				$webhookList['rows'],
				__( 'No webhook secrets match the current filters.', 'ran-booster' ),
				$renderWebhookCell,
				static fn ( string $orderby ): string => $sortUrl( 'secrets', $orderby ),
				array(
					'item_count_label' => $webhookItemCountLabel,
					'page_label'       => $webhookPageLabel,
					'current'          => $webhookList['current'],
					'pages'            => $webhookList['pages'],
					'per_page'         => $providerListState['per_page'],
					'action_url'       => admin_url( 'admin.php' ),
					'hidden_fields'    => array(
						'page'    => 'ran-booster',
						'tab'     => $provider['code'],
						'view'    => 'secrets',
						's'       => $providerListState['search'],
						'scope'   => $providerListState['scope'],
						'status'  => $providerListState['status'],
						'orderby' => $providerListState['orderby'],
						'order'   => $providerListState['order'],
					),
					'page_url'         => static fn ( int $page ): string => $pageUrl( 'secrets', $page ),
				)
			);
			?>
		<?php } ?>
	<?php } ?>

	<?php if ( $hasCredentialSettings || $providerHasWebhookSettings ) { ?>
		<footer class="ran-booster-provider__footer"><p class="description ran-booster-secrets-location"><?php esc_html_e( 'Saved credentials use Booster encrypted local storage outside the plugin directory and WordPress database. Deployment constants appear as immutable profiles and always take precedence.', 'ran-booster' ); ?></p></footer>
	<?php } ?>
</section>

<?php require __DIR__ . '/provider/modals.php'; ?>
</div>
