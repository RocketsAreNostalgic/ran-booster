<?php

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $model */
/** @var string $formAttributes */
$disabled        = true === ( $model['disabled'] ?? false );
$profileDisabled = $disabled || true === ( $model['webhook_profile_disabled'] ?? false );
?>
<div class="ran-booster-repository-webhook-management">

	<?php if ( is_array( $model['result'] ) ) : ?>
		<div class="notice <?php echo esc_attr( $model['result']['class'] ); ?> inline ran-booster-repository-webhook-management__notice"><p><?php echo esc_html( $model['result']['message'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( is_string( $model['recovery_warning'] ) ) : ?>
		<div class="notice notice-warning inline ran-booster-repository-webhook-management__notice"><p><?php echo esc_html( $model['recovery_warning'] ); ?></p></div>
	<?php endif; ?>
	<div class="ran-booster-repository-webhook-management__panel">
		<div class="ran-booster-public-lookup-profile__layout ran-booster-repository-webhook-management__layout">
			<form method="post" action="<?php echo esc_url( $model['form_action'] ); ?>" class="ran-booster-public-lookup-profile__form ran-booster-repository-webhook-management__form"<?php echo $formAttributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact Core Admin Interaction facade owns these form attributes. ?>>
				<input type="hidden" name="action" value="<?php echo esc_attr( $model['admin_action'] ); ?>">
				<input type="hidden" name="provider_code" value="<?php echo esc_attr( $model['provider_code'] ); ?>">
				<input type="hidden" name="repository_id" value="<?php echo esc_attr( $model['repository_id'] ); ?>">
				<input type="hidden" name="return_url" value="<?php echo esc_url( $model['return_url'] ); ?>">
				<div id="repository-webhook-management-error"></div>
					<div class="ran-booster-repository-webhook-management__field ran-booster-repository-webhook-management__field--wide">
						<label class="ran-booster-eyebrow ran-booster-eyebrow--compact ran-booster-public-lookup-profile__label" for="repository-webhook-management-saved-credential"><?php esc_html_e( 'Management credential', 'ran-booster' ); ?></label>
						<div class="ran-booster-repository-webhook-management__select-action">
							<select id="repository-webhook-management-saved-credential" name="booster_credential_id"<?php disabled( $disabled ); ?><?php echo $disabled ? '' : ' required'; ?>>
								<option value=""><?php esc_html_e( 'Choose a saved credential', 'ran-booster' ); ?></option>
								<?php foreach ( $model['credential_choices'] as $choice ) : ?>
									<option value="<?php echo esc_attr( $choice['id'] ); ?>"<?php selected( $model['management_credential_id'] ?? null, $choice['id'] ); ?>><?php echo esc_html( $choice['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<a class="button" href="<?php echo esc_url( $model['credentials_url'] ); ?>"><?php esc_html_e( 'Manage credentials', 'ran-booster' ); ?></a>
						</div>
						<span class="description"><?php esc_html_e( 'Choose the saved credential used to manage this repository webhook. A current selection does not prove provider authority until the operation checks it.', 'ran-booster' ); ?></span>
					</div>

					<div class="ran-booster-repository-webhook-management__field ran-booster-repository-webhook-management__field--wide">
						<label class="ran-booster-eyebrow ran-booster-eyebrow--compact ran-booster-public-lookup-profile__label" for="repository-webhook-management-webhook-profile"><?php esc_html_e( 'Signing secret', 'ran-booster' ); ?></label>
						<div class="ran-booster-repository-webhook-management__select-action">
							<select id="repository-webhook-management-webhook-profile" name="webhook_profile_id"<?php disabled( $profileDisabled ); ?><?php echo $profileDisabled ? '' : ' required'; ?>>
								<option value="" selected disabled><?php echo esc_html( $model['webhook_profile_placeholder'] ); ?></option>
								<option value="create_repository_secret"><?php esc_html_e( 'Create a repository signing secret', 'ran-booster' ); ?></option>
								<?php foreach ( $model['webhook_profile_choices'] as $choice ) : ?>
									<option value="<?php echo esc_attr( $choice['id'] ); ?>"><?php echo esc_html( $choice['label'] . ' (' . $choice['scope'] . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<a class="button" href="<?php echo esc_url( $model['secrets_url'] ); ?>"><?php esc_html_e( 'Manage signing secrets', 'ran-booster' ); ?></a>
						</div>
						<span class="description"><?php esc_html_e( 'Choose an applicable saved signing secret, or create one only for this repository.', 'ran-booster' ); ?></span>
					</div>

				<div class="ran-booster-action-row ran-booster-repository-webhook-management__actions">
					<?php foreach ( $model['operations'] as $operation ) : ?>
						<?php $operationDisabled = $disabled || true === ( $operation['disabled'] ?? false ); ?>
						<button class="<?php echo esc_attr( $operation['primary'] ? 'button button-primary' : 'button' ); ?>" name="repository_webhook_management_operation" value="<?php echo esc_attr( str_replace( 'disabled:', '', $operation['key'] ) ); ?>" formaction="<?php echo esc_url( $operation['url'] ); ?>"<?php disabled( $operationDisabled ); ?> aria-disabled="<?php echo $operationDisabled ? 'true' : 'false'; ?>"><?php echo esc_html( $operation['label'] ); ?></button>
					<?php endforeach; ?>
					<?php if ( is_string( $model['webhooks_url'] ?? null ) && '' !== $model['webhooks_url'] ) : ?>
						<?php /* translators: %s: repository provider name. */ ?>
						<a class="button" href="<?php echo esc_url( $model['webhooks_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( __( 'Open %s webhooks', 'ran-booster' ), $model['provider_label'] ) ); ?></a>
					<?php endif; ?>
				</div>
				<?php if ( is_string( $model['action_help'] ) ) : ?>
					<p class="description ran-booster-repository-webhook-management__action-help"><?php echo esc_html( $model['action_help'] ); ?></p>
				<?php endif; ?>
				<?php if ( null === ( $model['management_credential_id'] ?? null ) ) : ?>
					<p class="description ran-booster-repository-webhook-management__action-help"><?php esc_html_e( 'Test webhook is disabled until Booster has an exact recorded hook for this repository.', 'ran-booster' ); ?></p>
				<?php endif; ?>
			</form>

		</div>
	</div>
</div>
