<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$providerProfileInteractionValues = static function ( string $action ): string {
	$values = wp_json_encode(
		array(
			'ran_booster_interaction[operation]' => 'core:' . $action,
			'ran_booster_interaction[target]'    => \RAN\Admin\ProviderProfileAdminController::TARGET_KEY,
		)
	);

	return is_string( $values ) ? $values : '{}';
};

if ( $hasCredentialSettings ) {
	?>
	<div class="ran-booster-credential-modal ran-booster-dialog" data-credential-modal="access" data-provider-label="<?php echo esc_attr( $provider['label'] ); ?>" hidden>
		<div class="ran-booster-credential-modal__dialog ran-booster-dialog__surface" role="dialog" aria-modal="true" aria-labelledby="ran-booster-access-modal-title">
			<div class="ran-booster-dialog__header">
				<h2 id="ran-booster-access-modal-title" class="ran-booster-dialog__title"><?php esc_html_e( 'Add repository credential', 'ran-booster' ); ?></h2>
				<button type="button" class="ran-booster-dialog__close ran-booster-close-credential-modal" aria-label="<?php esc_attr_e( 'Close', 'ran-booster' ); ?>"><span aria-hidden="true">&times;</span></button>
			</div>
			<form method="post" action="" class="ran-booster-credential-modal__form" autocomplete="off" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-access-profile-error" data-ran-booster-interaction-operation="core:save-access-profile" hx-post="" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop" hx-vals="<?php echo esc_attr( $providerProfileInteractionValues( 'save-access-profile' ) ); ?>">
				<?php wp_nonce_field( 'ran-booster-save-secrets' ); ?>
					<input type="hidden" name="ran_booster[action]" value="save-access-profile">
					<input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>">
					<input type="hidden" name="ran_booster[id]" value="">
					<p class="description"><?php echo esc_html( sprintf( /* translators: 1: provider label, 2: provider code. */ __( 'Saving this credential authorizes the active %1$s provider to read every credential saved under provider code %2$s. Booster does not authenticate a third-party publisher.', 'ran-booster' ), $provider['label'], $provider['code'] ) ); ?></p>
					<p><label><?php esc_html_e( 'Label', 'ran-booster' ); ?> <input type="text" name="ran_booster[label]" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Deployment access', 'ran-booster' ); ?>"></label></p>
				<div class="ran-booster-credential-modal__field-row">
					<p><label><?php esc_html_e( 'Credential type', 'ran-booster' ); ?> <select name="ran_booster[kind]" class="ran-booster-credential-kind">
						<?php foreach ( $provider['credential_kinds'] as $kind ) { ?>
							<option value="<?php echo esc_attr( $kind['code'] ); ?>" data-secret-label="<?php echo esc_attr( $kind['secret_label'] ); ?>" data-secret-placeholder="<?php echo esc_attr( $kind['secret_placeholder'] ); ?>"><?php echo esc_html( $kind['short_label'] ?? $kind['label'] ); ?></option>
						<?php } ?>
					</select></label></p>
					<p>
						<label><?php esc_html_e( 'Expiry / removal date', 'ran-booster' ); ?> <input type="date" name="ran_booster[expires_on]" class="regular-text ran-booster-expiry-date" aria-describedby="ran-booster-expiry-removal-help"></label>
					</p>
				</div>
				<?php
				$fieldKinds = array();
				$fieldData  = array();
				foreach ( $provider['credential_kinds'] as $kind ) {
					foreach ( $kind['fields'] as $field ) {
						$fieldKinds[ $field['key'] ][] = $kind['code'];
						$fieldData[ $field['key'] ]    = $field;
					}
				}
				foreach ( $fieldData as $field ) {
					$kinds = implode( ',', $fieldKinds[ $field['key'] ] );
					?>
					<p class="ran-booster-credential-config-field" data-kinds="<?php echo esc_attr( $kinds ); ?>" data-required="<?php echo $field['required'] ? '1' : '0'; ?>" hidden>
						<label><?php echo esc_html( $field['label'] ); ?> <input type="<?php echo esc_attr( $field['type'] ); ?>" name="ran_booster[configuration][<?php echo esc_attr( $field['key'] ); ?>]" class="regular-text" autocapitalize="none" spellcheck="false" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" disabled></label>
						<?php if ( '' !== $field['description'] ) { ?>
							<span class="description"><?php echo esc_html( $field['description'] ); ?></span>
						<?php } ?>
					</p>
				<?php } ?>
				<p>
					<label><input type="checkbox" name="ran_booster[self_destruct]" value="1" class="ran-booster-credential-self-destruct" aria-describedby="ran-booster-expiry-removal-help"> <?php esc_html_e( 'Automatically remove this saved credential after this date', 'ran-booster' ); ?></label>
				</p>
				<p id="ran-booster-expiry-removal-help" class="description"><?php esc_html_e( 'Enter the provider expiry date, or choose an earlier one. If automatic removal is enabled, Booster removes the saved credential after this date. A known provider expiry is the latest date allowed.', 'ran-booster' ); ?></p>
				<p data-access-secret-tools>
					<label for="ran-booster-access-secret"><span class="ran-booster-secret-label"><?php esc_html_e( 'Credential secret', 'ran-booster' ); ?></span></label>
					<span class="ran-booster-portability__password-input">
						<input id="ran-booster-access-secret" type="password" name="ran_booster[secret]" class="regular-text ran-booster-secret-input" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="ran-booster-access-secret-help">
						<button type="button" class="ran-booster-portability__password-visibility" data-access-secret-visibility data-show-label="<?php esc_attr_e( 'Show credential secret', 'ran-booster' ); ?>" data-hide-label="<?php esc_attr_e( 'Hide credential secret', 'ran-booster' ); ?>" aria-controls="ran-booster-access-secret" aria-label="<?php esc_attr_e( 'Show credential secret', 'ran-booster' ); ?>" aria-pressed="false" title="<?php esc_attr_e( 'Show credential secret', 'ran-booster' ); ?>" hidden disabled><span class="dashicons dashicons-visibility" data-access-secret-visibility-icon aria-hidden="true"></span></button>
					</span>
					<span id="ran-booster-access-secret-help" class="screen-reader-text ran-booster-secret-help" data-access-secret-help data-add-message="<?php esc_attr_e( 'Required when adding.', 'ran-booster' ); ?>" data-edit-message="<?php esc_attr_e( 'A credential secret is already saved. Leave this field unchanged to keep it, or enter a replacement.', 'ran-booster' ); ?>"><?php esc_html_e( 'Required when adding.', 'ran-booster' ); ?></span>
				</p>
				<div id="ran-booster-access-profile-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>
				<div class="ran-booster-credential-modal__actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save credential', 'ran-booster' ); ?></button><button type="button" class="button ran-booster-close-credential-modal"><?php esc_html_e( 'Cancel', 'ran-booster' ); ?></button></div>
			</form>
		</div>
	</div>

	<div class="ran-booster-credential-modal ran-booster-dialog" data-credential-delete-modal hidden>
		<div id="ran-booster-delete-access-modal" class="ran-booster-credential-modal__dialog ran-booster-dialog__surface" role="dialog" aria-modal="true" aria-labelledby="ran-booster-delete-access-modal-title" aria-describedby="ran-booster-delete-access-modal-description">
			<div class="ran-booster-dialog__header">
				<h2 id="ran-booster-delete-access-modal-title" class="ran-booster-dialog__title"><?php esc_html_e( 'Delete repository credential?', 'ran-booster' ); ?></h2>
				<button type="button" class="ran-booster-dialog__close ran-booster-close-credential-modal" aria-label="<?php esc_attr_e( 'Close', 'ran-booster' ); ?>"><span aria-hidden="true">&times;</span></button>
			</div>
			<form method="post" action="" class="ran-booster-credential-modal__form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-delete-access-profile-error" data-ran-booster-interaction-operation="core:delete-access-profile" hx-post="" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop" hx-vals="<?php echo esc_attr( $providerProfileInteractionValues( 'delete-access-profile' ) ); ?>">
				<?php wp_nonce_field( 'ran-booster-save-secrets' ); ?>
				<input type="hidden" name="ran_booster[action]" value="delete-access-profile">
				<input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>">
				<input type="hidden" name="ran_booster[id]" value="">
				<p id="ran-booster-delete-access-modal-description"><?php esc_html_e( 'You are about to delete', 'ran-booster' ); ?> <strong data-delete-credential-label></strong> <?php esc_html_e( 'from this site. This removes its saved secret and cannot be undone.', 'ran-booster' ); ?></p>
				<p data-delete-credential-unused><?php esc_html_e( 'Booster has verified that no managed package currently uses this credential.', 'ran-booster' ); ?></p>
				<p data-delete-credential-in-use hidden></p>
				<div class="ran-booster-delete-credential-packages" data-delete-credential-packages aria-labelledby="ran-booster-delete-credential-packages-title" hidden>
					<p id="ran-booster-delete-credential-packages-title" class="ran-booster-delete-credential-packages__title"><strong><?php esc_html_e( 'Connected packages', 'ran-booster' ); ?></strong></p>
					<div data-delete-credential-package-list></div>
				</div>
				<p data-delete-credential-public-default hidden><?php echo esc_html( sprintf( /* translators: %s: provider label. */ __( 'This credential is the default for public repository lookup. Deleting it returns %s public lookup to Anonymous and the provider’s public API limits.', 'ran-booster' ), $provider['label'] ) ); ?></p>
				<div id="ran-booster-delete-access-profile-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>
				<div class="ran-booster-credential-modal__actions">
					<button type="submit" class="button button-delete" data-delete-credential-confirm><?php esc_html_e( 'Yes, delete credential', 'ran-booster' ); ?></button>
					<button type="button" class="button ran-booster-close-credential-modal" data-delete-credential-cancel><?php esc_html_e( 'Cancel', 'ran-booster' ); ?></button>
				</div>
			</form>
		</div>
	</div>
	<?php
}

if ( $hasWebhookSettings ) {
	$configuredWebhookTargets = array();
	foreach ( $webhook_profiles as $webhookProfile ) {
		$scope     = is_string( $webhookProfile['scope'] ?? null ) ? $webhookProfile['scope'] : '';
		$target    = is_string( $webhookProfile['target'] ?? null )
			? strtolower( trim( $webhookProfile['target'], " \t\n\r\0\x0B/" ) )
			: '';
		$profileId = is_string( $webhookProfile['id'] ?? null ) ? $webhookProfile['id'] : '';
		if ( in_array( $scope, array( 'owner', 'repository' ), true ) && '' !== $target && '' !== $profileId ) {
			$configuredWebhookTargets[ $scope . ':' . $target ] = $profileId;
		}
	}
	?>
	<div class="ran-booster-credential-modal ran-booster-dialog" data-credential-modal="webhook" data-provider-label="<?php echo esc_attr( $provider['label'] ); ?>" hidden>
		<div class="ran-booster-credential-modal__dialog ran-booster-dialog__surface" role="dialog" aria-modal="true" aria-labelledby="ran-booster-webhook-modal-title">
			<div class="ran-booster-dialog__header">
				<h2 id="ran-booster-webhook-modal-title" class="ran-booster-dialog__title"><?php esc_html_e( 'Add Push-to-Deploy secret', 'ran-booster' ); ?></h2>
				<button type="button" class="ran-booster-dialog__close ran-booster-close-credential-modal" aria-label="<?php esc_attr_e( 'Close', 'ran-booster' ); ?>"><span aria-hidden="true">&times;</span></button>
			</div>
			<form method="post" action="" class="ran-booster-credential-modal__form" autocomplete="off" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-webhook-profile-error" data-ran-booster-interaction-operation="core:save-webhook-profile" hx-post="" hx-target="#ran-booster-provider-profile-region" hx-select="#ran-booster-provider-profile-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop" hx-vals="<?php echo esc_attr( $providerProfileInteractionValues( 'save-webhook-profile' ) ); ?>">
				<?php wp_nonce_field( 'ran-booster-save-secrets' ); ?>
				<input type="hidden" name="ran_booster[action]" value="save-webhook-profile">
				<input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>">
				<input type="hidden" name="ran_booster[id]" value="">
				<p><label><?php esc_html_e( 'Label', 'ran-booster' ); ?> <input type="text" name="ran_booster[label]" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Organisation webhooks', 'ran-booster' ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Scope', 'ran-booster' ); ?> <select name="ran_booster[scope]" class="ran-booster-webhook-scope">
					<?php foreach ( $provider['webhook_scopes'] as $scope ) { ?>
						<option value="<?php echo esc_attr( $scope['code'] ); ?>" data-requires-target="<?php echo $scope['requires_target'] ? '1' : '0'; ?>" data-target-label="<?php echo esc_attr( $scope['target_label'] ); ?>" data-target-placeholder="<?php echo esc_attr( $scope['target_placeholder'] ); ?>" data-description="<?php echo esc_attr( $scope['description'] ); ?>"><?php echo esc_html( $scope['label'] ); ?></option>
					<?php } ?>
				</select></label></p>
				<p class="ran-booster-webhook-target-field" hidden>
					<label><span class="ran-booster-webhook-target-label"><?php esc_html_e( 'Target', 'ran-booster' ); ?></span>
						<select name="ran_booster[target]" class="regular-text" data-webhook-target>
							<optgroup label="<?php esc_attr_e( 'Managed owners', 'ran-booster' ); ?>" data-webhook-target-options="owner">
								<option value="" disabled><?php echo esc_html( empty( $managedRepositories['owners'] ) ? __( 'No managed owners available', 'ran-booster' ) : __( 'Choose a managed owner', 'ran-booster' ) ); ?></option>
								<?php
								foreach ( $managedRepositories['owners'] ?? array() as $owner ) {
									$configuredId = $configuredWebhookTargets[ 'owner:' . strtolower( trim( $owner, " \t\n\r\0\x0B/" ) ) ] ?? '';
									?>
									<option value="<?php echo esc_attr( $owner ); ?>"<?php echo '' !== $configuredId ? ' data-webhook-profile-id="' . esc_attr( $configuredId ) . '" disabled' : ''; ?>><?php echo esc_html( '' !== $configuredId ? sprintf( /* translators: %s: configured webhook target. */ __( '%s — already configured', 'ran-booster' ), $owner ) : $owner ); ?></option>
								<?php } ?>
							</optgroup>
							<optgroup label="<?php esc_attr_e( 'Managed repositories', 'ran-booster' ); ?>" data-webhook-target-options="repository">
								<option value="" disabled><?php echo esc_html( empty( $managedRepositories['repositories'] ) ? __( 'No managed repositories available', 'ran-booster' ) : __( 'Choose a managed repository', 'ran-booster' ) ); ?></option>
								<?php
								foreach ( $managedRepositories['repositories'] ?? array() as $repository ) {
									$repositoryTarget = is_string( $repository['target'] ?? null ) ? $repository['target'] : '';
									$configuredId     = $configuredWebhookTargets[ 'repository:' . strtolower( trim( $repositoryTarget, " \t\n\r\0\x0B/" ) ) ] ?? '';
									?>
									<option value="<?php echo esc_attr( $repositoryTarget ); ?>"<?php echo '' !== $configuredId ? ' data-webhook-profile-id="' . esc_attr( $configuredId ) . '" disabled' : ''; ?>><?php echo esc_html( '' !== $configuredId ? sprintf( /* translators: %s: configured webhook target. */ __( '%s — already configured', 'ran-booster' ), $repositoryTarget ) : $repositoryTarget ); ?></option>
								<?php } ?>
							</optgroup>
						</select>
					</label>
					<span class="description ran-booster-webhook-target-help"></span>
				</p>
				<p class="ran-booster-portability__password-primary" data-webhook-secret-tools>
					<label for="ran-booster-webhook-secret"><?php esc_html_e( 'Webhook secret', 'ran-booster' ); ?></label>
					<span class="ran-booster-portability__password-control">
						<span class="ran-booster-portability__password-input">
							<input id="ran-booster-webhook-secret" type="password" name="ran_booster[secret]" class="ran-booster-secret-input" minlength="32" maxlength="512" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" placeholder="<?php esc_attr_e( 'Long random secret', 'ran-booster' ); ?>" data-add-placeholder="<?php esc_attr_e( 'Long random secret', 'ran-booster' ); ?>" aria-describedby="ran-booster-webhook-secret-guidance" data-webhook-secret-input>
							<button type="button" class="ran-booster-portability__password-visibility" data-webhook-secret-visibility data-show-label="<?php esc_attr_e( 'Show secret', 'ran-booster' ); ?>" data-hide-label="<?php esc_attr_e( 'Hide secret', 'ran-booster' ); ?>" aria-controls="ran-booster-webhook-secret" aria-label="<?php esc_attr_e( 'Show secret', 'ran-booster' ); ?>" aria-pressed="false" title="<?php esc_attr_e( 'Show secret', 'ran-booster' ); ?>" hidden disabled><span class="dashicons dashicons-visibility" data-webhook-secret-visibility-icon aria-hidden="true"></span></button>
						</span>
						<span class="ran-booster-portability__password-actions">
							<button type="button" class="button" data-webhook-secret-generate><?php esc_html_e( 'Generate secret', 'ran-booster' ); ?></button>
							<button type="button" class="button ran-booster-portability__icon-button" data-webhook-secret-copy data-copy-label="<?php esc_attr_e( 'Copy secret', 'ran-booster' ); ?>" data-copied-label="<?php esc_attr_e( 'Secret copied', 'ran-booster' ); ?>" aria-label="<?php esc_attr_e( 'Copy secret', 'ran-booster' ); ?>" title="<?php esc_attr_e( 'Copy secret', 'ran-booster' ); ?>" disabled>
								<svg class="ran-booster-portability__button-icon" data-webhook-secret-copy-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
									<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
									<rect x="9" y="3" width="6" height="4" rx="1"/>
								</svg>
								<svg class="ran-booster-portability__button-icon" data-webhook-secret-copy-success-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" hidden>
									<path d="m5 12 4 4L19 6"/>
								</svg>
								<span class="screen-reader-text"><?php esc_html_e( 'Copy secret', 'ran-booster' ); ?></span>
							</button>
						</span>
					</span>
					<span id="ran-booster-webhook-secret-guidance" class="description ran-booster-secret-help ran-booster-portability__password-guidance"><strong><?php esc_html_e( 'Copy the secret before saving.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Paste the same value into the provider webhook. Booster will not reveal a saved secret again, and saving it here does not create or verify the remote webhook. On edit, leave the field unchanged to retain the saved secret.', 'ran-booster' ); ?></span>
					<span class="ran-booster-portability__password-status" data-webhook-secret-status data-generated-message="<?php esc_attr_e( 'A secure 64-character webhook secret was generated.', 'ran-booster' ); ?>" data-copied-message="<?php esc_attr_e( 'Webhook secret copied to the clipboard.', 'ran-booster' ); ?>" data-generation-failed-message="<?php esc_attr_e( 'Booster could not generate a webhook secret securely in this browser. Enter one manually.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The webhook secret is selected; use your browser’s copy command.', 'ran-booster' ); ?>" role="status" aria-live="polite" aria-atomic="true"></span>
				</p>
				<div id="ran-booster-webhook-profile-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" hidden><p></p></div>
				<div class="ran-booster-credential-modal__actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save webhook secret', 'ran-booster' ); ?></button><button type="button" class="button ran-booster-close-credential-modal"><?php esc_html_e( 'Cancel', 'ran-booster' ); ?></button></div>
			</form>
		</div>
	</div>
	<?php
}
