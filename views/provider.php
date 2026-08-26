<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$renderCredentialCell = static function ( array $profile, string $column ) use ( $packageTypeLabels, $provider, $providerMutationFields ): void {
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
		<form method="post" action="" class="ran-booster-inline-form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" hx-post="" hx-target="#ran-booster-credential-validation-error-<?php echo esc_attr( $profile['id'] ); ?>" hx-swap="outerHTML" hx-sync="this:drop">
		<?php
		foreach ( $providerMutationFields as $name => $value ) {
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
	<button type="button" class="button ran-booster-open-credential-modal" data-modal="access" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-kind="<?php echo esc_attr( $profile['kind'] ); ?>" data-configuration="<?php echo esc_attr( $profile['configuration_json'] ); ?>" data-expires-on="<?php echo esc_attr( $profile['expiry']['manual_expires_on'] ?? '' ); ?>" data-provider-expires-on="<?php echo esc_attr( $profile['provider_expires_on'] ); ?>" data-self-destruct="<?php echo ! empty( $profile['self_destruct'] ) ? '1' : '0'; ?>" data-destroy-on="<?php echo esc_attr( $profile['destroy_on'] ?? '' ); ?>"><?php esc_html_e( 'Edit', 'ran-booster' ); ?></button><button type="button" class="button button-delete ran-booster-open-delete-credential-modal" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-usage-total="<?php echo esc_attr( $usage['available'] ? (string) $usage['total'] : '' ); ?>" data-usage-listed="<?php echo esc_attr( (string) $profile['usage_listed'] ); ?>" data-usage-template="<?php echo esc_attr( $usageTemplateId ); ?>" data-public-lookup-default="<?php echo ! empty( $profile['public_lookup_default'] ) ? '1' : '0'; ?>" aria-haspopup="dialog" aria-controls="ran-booster-delete-access-modal" <?php disabled( ! $usage['available'] ); ?>><?php esc_html_e( 'Delete', 'ran-booster' ); ?></button>
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
$renderWebhookCell  = static function ( array $profile, string $column ) use ( $provider, $deleteWebhookInteractionValues, $providerMutationFields ): void {
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
	<button type="button" class="button ran-booster-open-credential-modal" data-modal="webhook" data-id="<?php echo esc_attr( $profile['id'] ); ?>" data-label="<?php echo esc_attr( $profile['label'] ); ?>" data-scope="<?php echo esc_attr( $profile['scope'] ); ?>" data-target="<?php echo esc_attr( $profile['target'] ); ?>"><?php esc_html_e( 'Edit', 'ran-booster' ); ?></button><form method="post" action="" class="ran-booster-inline-form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-delete-webhook-profile-error" data-ran-booster-interaction-operation="core:delete-webhook-profile" hx-post="" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop" hx-vals="<?php echo esc_attr( $deleteWebhookInteractionValues ); ?>">
	<?php
	foreach ( $providerMutationFields as $name => $value ) {
		?>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"><?php } ?><input type="hidden" name="ran_booster[action]" value="delete-webhook-profile"><input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="ran_booster[id]" value="<?php echo esc_attr( $profile['id'] ); ?>"><button type="submit" class="button button-delete" data-confirm="<?php echo esc_attr( $profile['delete_confirmation'] ); ?>"><?php esc_html_e( 'Delete', 'ran-booster' ); ?></button></form>
	<?php
};
$isRepositoryDetail = 'overview' === $providerView && 'repositories' === $providerTask && '' !== $requestedRepositoryId;

?>

<div id="ran-booster-provider-profile-region" data-ran-booster-admin-mutation-region="provider-profiles">
<?php settings_errors(); ?>
<section class="ran-booster-page-shell ran-booster-provider<?php echo $isRepositoryDetail ? ' ran-booster-repository-page' : ' ran-booster-panel'; ?>" aria-labelledby="ran-booster-provider-heading">
	<?php if ( 'overview' === $providerView && ! $isRepositoryDetail ) { ?>
		<header class="ran-booster-page-shell__header ran-booster-provider__header">
			<p class="ran-booster-provider__eyebrow ran-booster-eyebrow"><?php esc_html_e( 'Repository provider', 'ran-booster' ); ?></p>
			<h2 id="ran-booster-provider-heading" class="ran-booster-page-heading__title" data-ran-booster-provider-profile-focus tabindex="-1"><?php echo esc_html( $provider['label'] ); ?></h2>
			<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Manage repository access, integrations and the packages connected to this site.', 'ran-booster' ); ?></p>
		</header>
	<?php } elseif ( 'overview' !== $providerView ) { ?>
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

	<?php if ( $storageUnavailable && ! $isRepositoryDetail ) { ?>
		<div class="notice notice-error inline ran-booster-provider__notice" data-ran-booster-provider-storage-notice>
			<p><strong><?php esc_html_e( 'Encrypted credential storage is unavailable.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Restore the matching sidecar and site key from the same backup before changing credentials.', 'ran-booster' ); ?></p>
		</div>
	<?php } ?>

	<?php if ( $isRepositoryDetail ) { ?>
		<?php if ( is_array( $selectedRepositoryRow ) ) { ?>
			<?php
			$hasBranchConsumer = in_array( $selectedRepositoryRow['source_key'] ?? null, array( 'branch', 'mixed' ), true );
			$repositoryDetailRenderer->render(
				$selectedRepositoryRow,
				$provider['label'],
				$repositoryListUrl,
				$activityUrl,
				$webhookAssistanceSiteReady,
				$webhookAssistanceSiteReady ? __( 'This site can receive provider webhook deliveries.', 'ran-booster' ) : implode( ' ', $webhookSiteReasons ),
				null !== $webhookManagement && $webhookManagement->supportsProvider( $provider['code'] )
					? static function () use ( $webhookManagement, $provider, $requestedRepositoryId, $providerReturnUrl, $hasBranchConsumer, $selectedRepositoryRow ): void {
						$webhookManagement->renderRepositoryWebhookSetup( $provider['code'], $requestedRepositoryId, $providerReturnUrl, $hasBranchConsumer, (string) ( $selectedRepositoryRow['repository'] ?? '' ) );
					}
					: null
			);
			?>
		<?php } else { ?>
			<p><a href="<?php echo esc_url( $repositoryListUrl ); ?>">&larr; <?php esc_html_e( 'Back to repositories', 'ran-booster' ); ?></a></p>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'That managed repository is no longer available. Return to the repository list and choose a current repository.', 'ran-booster' ); ?></p></div>
		<?php } ?>
	<?php } elseif ( 'overview' === $providerView ) { ?>
		<?php if ( ! empty( $provider['credential_kinds'] ) ) { ?>
		<section class="ran-booster-provider-section" aria-labelledby="ran-booster-access-tokens-heading">
				<header class="ran-booster-provider-section__header">
					<h3 id="ran-booster-access-tokens-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository access', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php echo esc_html( $provider['capabilities']['browse'] ? __( 'Saved credentials enable private repository access; public repository discovery does not require one.', 'ran-booster' ) : __( 'Saved credentials enable access to private repositories entered manually.', 'ran-booster' ) ); ?></p>
				</header>
			<div class="ran-booster-provider-section__body">
				<?php
				$statusSummaryRenderer->render(
					$credentialSummary['tone'],
					$credentialSummary['heading'],
					$credentialSummary['description'],
					static function () use ( $credentialRowCount, $credentialsUrl, $hasCredentialSettings, $storageUnavailable ): void {
						if ( 0 === $credentialRowCount && $hasCredentialSettings ) {
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

		<?php if ( $hasWebhookSettings ) { ?>
			<section class="ran-booster-provider-section" aria-labelledby="ran-booster-webhook-secrets-heading">
				<header class="ran-booster-provider-section__header">
					<h3 id="ran-booster-webhook-secrets-heading" class="ran-booster-section__title"><?php esc_html_e( 'Signing secrets', 'ran-booster' ); ?></h3>
					<p class="ran-booster-section__description"><?php esc_html_e( 'Signing secrets verify repository webhook deliveries.', 'ran-booster' ); ?></p>
				</header>
				<div class="ran-booster-provider-section__body">
					<?php
					$statusSummaryRenderer->render(
						$webhookSummary['tone'],
						$webhookSummary['heading'],
						$webhookSummary['description'],
						static function () use ( $hasWebhookSettings, $secretsUrl, $storageUnavailable, $webhookRowCount ): void {
							if ( 0 === $webhookRowCount && $hasWebhookSettings ) {
								?>
								<button type="button" class="button ran-booster-open-credential-modal" data-modal="webhook"><?php esc_html_e( 'Add webhook secret', 'ran-booster' ); ?></button>
									<?php
							} elseif ( ! $storageUnavailable ) {
								?>
								<a class="button" href="<?php echo esc_url( $secretsUrl ); ?>"><?php esc_html_e( 'Manage signing secrets', 'ran-booster' ); ?></a>
									<?php
							}
						}
					);
			?>
				</div>
			</section>
		<?php } ?>

		<?php if ( $repositoryIntegrationAvailable ) { ?>
			<section class="ran-booster-provider-section" aria-labelledby="ran-booster-repository-integrations-heading">
				<header class="ran-booster-provider-section__header">
					<h3 id="ran-booster-repository-integrations-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository integrations', 'ran-booster' ); ?></h3>
				<p class="ran-booster-section__description">
						<?php esc_html_e( 'Review site delivery readiness, repositories connected to managed packages, Published release automation, and the shared webhook receiver.', 'ran-booster' ); ?>
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
								<a class="ran-booster-provider-task-tab" href="<?php echo esc_url( $taskUrls[ $task ] ); ?>" hx-get="<?php echo esc_url( $taskRequestUrls[ $task ] ); ?>" data-ran-booster-provider-task="<?php echo esc_attr( $task ); ?>" aria-controls="ran-booster-provider-task-panel" <?php echo $providerTask === $task ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
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
									<h4 id="ran-booster-provider-status-heading" class="ran-booster-section__title"><?php esc_html_e( 'Status', 'ran-booster' ); ?></h4>
									<p class="ran-booster-section__description"><?php esc_html_e( 'Local readiness and recorded repository state. Booster does not contact the provider to build this summary.', 'ran-booster' ); ?></p>
								</div>
							</div>
							<dl class="ran-booster-repository-integration-status">
								<div><dt><?php esc_html_e( 'Site webhook delivery', 'ran-booster' ); ?></dt><dd><?php echo esc_html( $webhookAssistanceSiteReady ? __( 'Ready for public HTTPS delivery', 'ran-booster' ) : __( 'Not ready for provider delivery', 'ran-booster' ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Signing profiles', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d is the number of locally ready signing profiles. */ _n( '%d ready locally', '%d ready locally', $readyWebhookProfileCount, 'ran-booster' ), $readyWebhookProfileCount ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Repository hooks', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: number of locally recorded hooks, 2: number of hook records needing review. */ __( '%1$d recorded locally · %2$d need review', 'ran-booster' ), $repositoryIntegrationSummary['recorded_hooks'], $repositoryIntegrationSummary['needs_review'] ) ); ?></dd></div>
								<div><dt><?php esc_html_e( 'Published releases', 'ran-booster' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: 1: number of Published release packages, 2: number of repositories, 3: number of release workflows needing review. */ __( '%1$d packages in %2$d repositories · %3$d workflows need review', 'ran-booster' ), $repositoryIntegrationSummary['release_packages'], $repositoryIntegrationSummary['release_repositories'], $repositoryIntegrationSummary['release_workflows_needing_review'] ) ); ?></dd></div>
							</dl>
							<?php if ( ! $webhookAssistanceProviderCapable || ! $webhookAssistanceSiteReady ) { ?>
								<div class="notice <?php echo esc_attr( $webhookHasHardFailure ? 'notice-error' : 'notice-warning' ); ?> inline ran-booster-push-deploy__notice" data-ran-booster-assistance-site-notice>
									<p><strong><?php esc_html_e( 'Webhook delivery is not ready.', 'ran-booster' ); ?></strong> <?php echo esc_html( $webhookAssistanceProviderCapable ? implode( ' ', $webhookSiteReasons ) : sprintf( /* translators: %s is the repository provider name. */ __( '%s does not provide Booster with assisted webhook management. Repository inventory remains available.', 'ran-booster' ), $provider['label'] ) ); ?></p>
								</div>
							<?php } ?>
							<div class="ran-booster-provider-task-actions">
								<a class="button" href="<?php echo esc_url( $taskUrls['repositories'] ); ?>" hx-get="<?php echo esc_url( $taskRequestUrls['repositories'] ); ?>" hx-boost="true"><?php esc_html_e( 'Review repositories', 'ran-booster' ); ?></a>
								<a class="button" href="<?php echo esc_url( $wordpressUrlsUrl ); ?>"><?php esc_html_e( 'Review WordPress URLs', 'ran-booster' ); ?></a>
								<a class="button" href="<?php echo esc_url( $activityUrl ); ?>"><?php esc_html_e( 'View Activity', 'ran-booster' ); ?></a>
							</div>
					</section>
				<?php } elseif ( 'setup' === $providerTask ) { ?>
					<section id="ran-booster-provider-task-panel" class="ran-booster-provider-task-panel" data-ran-booster-provider-task="setup" aria-labelledby="ran-booster-webhook-instructions-heading">
							<div class="ran-booster-provider-task-panel__heading">
								<div>
									<h4 id="ran-booster-webhook-instructions-heading" class="ran-booster-section__title"><?php esc_html_e( 'Webhook receiver', 'ran-booster' ); ?></h4>
									<p class="ran-booster-section__description"><?php esc_html_e( 'This provider uses one shared receiver on this site. Configure and check each repository from Repositories.', 'ran-booster' ); ?></p>
								</div>
								<?php if ( null !== $webhookSetup ) { ?>
									<a class="button" href="<?php echo esc_url( $webhookSetup['documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $providerInstructionsLabel ); ?></a>
								<?php } ?>
							</div>
							<p><a class="button" href="<?php echo esc_url( $taskUrls['repositories'] ); ?>" hx-get="<?php echo esc_url( $taskRequestUrls['repositories'] ); ?>" hx-boost="true"><?php esc_html_e( 'Manage repositories', 'ran-booster' ); ?></a></p>
								<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Webhook signatures authorize deployment; they do not protect your host from traffic.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Use a unique generated repository secret, rotate or disable a suspected secret, and do not cache, challenge or transform this callback. For timeouts and failed responses, compare provider delivery history with the Provider request ID in Booster Activity.', 'ran-booster' ); ?> <a href="<?php echo esc_url( $webhookOperationsUrl ); ?>"><?php esc_html_e( 'Read the webhook operations guide', 'ran-booster' ); ?></a>.</p></div>
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
										<ol><li><?php esc_html_e( 'Paste the payload URL and a matching signing secret.', 'ran-booster' ); ?></li><li><?php esc_html_e( 'Keep SSL verification enabled and select the push event.', 'ran-booster' ); ?></li><li><?php esc_html_e( 'Trigger a delivery and confirm the provider records a successful response.', 'ran-booster' ); ?></li></ol>
										<p><a href="<?php echo esc_url( $webhookSetup['delivery_documentation_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Delivery troubleshooting', 'ran-booster' ); ?></a></p>
									</div>
								</details>
							<?php } ?>
				</section>
				<?php } else { ?>
					<section id="ran-booster-provider-task-panel" class="ran-booster-provider-task-panel" data-ran-booster-provider-task="repositories" aria-labelledby="ran-booster-managed-webhook-repositories-heading">
						<?php if ( '' === $requestedRepositoryId ) { ?>
						<div class="ran-booster-provider-task-panel__heading">
							<div>
								<h4 id="ran-booster-managed-webhook-repositories-heading" class="ran-booster-section__title"><?php esc_html_e( 'Managed repositories', 'ran-booster' ); ?></h4>
								<p class="ran-booster-section__description"><?php echo esc_html( $repositoryWebhookDescription ); ?></p>
							</div>
						</div>
						<?php } ?>
							<?php if ( '' !== $requestedRepositoryId ) { ?>
								<?php if ( is_array( $selectedRepositoryRow ) ) { ?>
									<?php
									$hasBranchConsumer = in_array( $selectedRepositoryRow['source_key'] ?? null, array( 'branch', 'mixed' ), true );
									$repositoryDetailRenderer->render(
										$selectedRepositoryRow,
										$provider['label'],
										$repositoryListUrl,
										$activityUrl,
										$webhookAssistanceSiteReady,
										$webhookAssistanceSiteReady ? __( 'This site can receive provider webhook deliveries.', 'ran-booster' ) : implode( ' ', $webhookSiteReasons ),
										null !== $webhookManagement && $webhookManagement->supportsProvider( $provider['code'] )
										? static function () use ( $webhookManagement, $provider, $requestedRepositoryId, $providerReturnUrl, $hasBranchConsumer, $selectedRepositoryRow ): void {
											$webhookManagement->renderRepositoryWebhookSetup( $provider['code'], $requestedRepositoryId, $providerReturnUrl, $hasBranchConsumer, (string) ( $selectedRepositoryRow['repository'] ?? '' ) );
										}
											: null
									);
									?>
								<?php } else { ?>
									<p><a href="<?php echo esc_url( $repositoryListUrl ); ?>">&larr; <?php esc_html_e( 'Back to repositories', 'ran-booster' ); ?></a></p>
									<div class="notice notice-error inline"><p><?php esc_html_e( 'That managed repository is no longer available. Return to the repository list and choose a current repository.', 'ran-booster' ); ?></p></div>
								<?php } ?>
							<?php } elseif ( array() !== $repositoryTableRows ) { ?>
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
								<?php $repositoryTableRenderer->render( 'ran-booster-managed-webhook-repositories-heading', $repositoryTableRows ); ?>
							<?php } elseif ( ! empty( $managedRepositories['available'] ) ) { ?>
								<div class="ran-booster-provider-empty-actions">
									<p><?php echo esc_html( $emptyRepositoryDescription ); ?></p>
									<a class="button button-primary" href="<?php echo esc_url( $installPluginUrl ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
									<a class="button" href="<?php echo esc_url( $installThemeUrl ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a>
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
	<?php } elseif ( 'credentials' === $providerView ) { ?>
		<?php if ( $hasCredentialSettings ) { ?>
			<form class="ran-booster-provider-list-controls" method="get" action="<?php echo esc_url( $providerListActionUrl ); ?>">
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
					$credentialSortUrls,
					$credentialPagination
				);
			?>
		<?php } ?>
	<?php } elseif ( 'secrets' === $providerView ) { ?>
		<?php if ( $hasWebhookSettings ) { ?>
			<div id="ran-booster-delete-webhook-profile-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>
			<form class="ran-booster-provider-list-controls" method="get" action="<?php echo esc_url( $providerListActionUrl ); ?>"><input type="hidden" name="page" value="ran-booster"><input type="hidden" name="tab" value="<?php echo esc_attr( $provider['code'] ); ?>"><input type="hidden" name="view" value="secrets"><input type="hidden" name="orderby" value="<?php echo esc_attr( $providerListState['orderby'] ); ?>"><input type="hidden" name="order" value="<?php echo esc_attr( $providerListState['order'] ); ?>"><input type="hidden" name="per_page" value="<?php echo esc_attr( (string) $providerListState['per_page'] ); ?>"><div><label class="screen-reader-text" for="ran-booster-secret-scope-filter"><?php esc_html_e( 'Filter by scope', 'ran-booster' ); ?></label><select id="ran-booster-secret-scope-filter" name="scope"><option value=""><?php esc_html_e( 'All scopes', 'ran-booster' ); ?></option>
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
				$webhookSortUrls,
				$webhookPagination
			);
			?>
		<?php } ?>
	<?php } ?>

	<?php if ( ! $isRepositoryDetail && ( $hasCredentialSettings || $providerHasWebhookSettings ) ) { ?>
		<footer class="ran-booster-provider__footer"><p class="description ran-booster-secrets-location"><?php esc_html_e( 'Saved credentials use Booster encrypted local storage outside the plugin directory and WordPress database. Deployment constants appear as immutable profiles and always take precedence.', 'ran-booster' ); ?></p></footer>
	<?php } ?>
</section>

<?php require __DIR__ . '/provider/modals.php'; ?>
</div>
