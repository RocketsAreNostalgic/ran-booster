<?php

declare( strict_types = 1 );

/** @var array<string, mixed> $model */
/** @var string $formAttributes */
?>
<section class="ran-booster-credential-section ran-booster-github-webhook-management" aria-labelledby="ran-booster-github-webhook-management-operation-heading">
	<div class="ran-booster-credential-section__header"><div>
		<p class="ran-booster-eyebrow ran-booster-provider__eyebrow"><?php esc_html_e( 'Bundled webhook management', 'ran-booster' ); ?></p>
		<?php /* translators: %s: repository provider name. */ ?>
		<h5 id="ran-booster-github-webhook-management-operation-heading"><?php echo esc_html( sprintf( __( 'Set up or manage a %s webhook', 'ran-booster' ), $model['provider_label'] ) ); ?></h5>
		<p><?php esc_html_e( 'Use the selected repository and one permitted operation. GitHub webhook management does not change package deployment policy.', 'ran-booster' ); ?></p>
	</div></div>

	<?php if ( is_array( $model['result'] ) ) : ?>
		<div class="notice <?php echo esc_attr( $model['result']['class'] ); ?> inline ran-booster-github-webhook-management__notice"><p><?php echo esc_html( $model['result']['message'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( is_string( $model['recovery_warning'] ) ) : ?>
		<div class="notice notice-warning inline ran-booster-github-webhook-management__notice"><p><?php echo esc_html( $model['recovery_warning'] ); ?></p></div>
	<?php endif; ?>

	<div class="ran-booster-panel ran-booster-push-deploy__panel">
		<div class="ran-booster-public-lookup-profile__layout">
			<form method="post" action="<?php echo esc_url( $model['form_action'] ); ?>" class="ran-booster-public-lookup-profile__form ran-booster-github-webhook-management__form"<?php echo $formAttributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact Core Admin Interaction facade owns these form attributes. ?>>
				<input type="hidden" name="action" value="<?php echo esc_attr( $model['admin_action'] ); ?>">
				<input type="hidden" name="provider_code" value="<?php echo esc_attr( $model['provider_code'] ); ?>">
				<input type="hidden" name="repository_id" value="<?php echo esc_attr( $model['repository_id'] ); ?>">
				<div id="github-webhook-management-error"></div>
				<div class="ran-booster-github-webhook-management__field ran-booster-github-webhook-management__field--wide"><span class="ran-booster-eyebrow ran-booster-eyebrow--compact ran-booster-public-lookup-profile__label"><?php esc_html_e( 'Repository', 'ran-booster' ); ?></span><code><?php echo esc_html( $model['repository'] ); ?></code></div>

				<?php if ( array() !== $model['credential_choices'] ) : ?>
					<div class="ran-booster-github-webhook-management__field ran-booster-github-webhook-management__field--wide">
						<label class="ran-booster-eyebrow ran-booster-eyebrow--compact ran-booster-public-lookup-profile__label" for="github-webhook-management-saved-credential"><?php esc_html_e( 'Saved Booster credential', 'ran-booster' ); ?></label>
						<select id="github-webhook-management-saved-credential" name="booster_credential_id">
							<option value=""><?php esc_html_e( 'Paste a request-only token instead', 'ran-booster' ); ?></option>
							<?php foreach ( $model['credential_choices'] as $choice ) : ?>
								<option value="<?php echo esc_attr( $choice['id'] ); ?>"><?php echo esc_html( $choice['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					<span class="description"><?php esc_html_e( 'The form sends only the selected profile ID; Core resolves the saved credential inside this fixed provider operation without exposing plaintext or storage access to the presentation layer.', 'ran-booster' ); ?></span>
					</div>
				<?php endif; ?>

				<div class="ran-booster-github-webhook-management__field ran-booster-github-webhook-management__field--wide">
					<label class="ran-booster-eyebrow ran-booster-eyebrow--compact ran-booster-public-lookup-profile__label" for="github-webhook-management-request-credential"><?php esc_html_e( 'Request-only GitHub token', 'ran-booster' ); ?></label>
					<input id="github-webhook-management-request-credential" name="github_pat" type="password" autocomplete="off" aria-describedby="github-webhook-management-request-credential-help">
					<span id="github-webhook-management-request-credential-help" class="description"><?php esc_html_e( 'Used by Core for this fixed operation only; never stored by GitHub webhook management.', 'ran-booster' ); ?></span>
				</div>

				<div class="ran-booster-action-row ran-booster-github-webhook-management__actions">
					<?php foreach ( $model['operations'] as $operation ) : ?>
						<button class="<?php echo esc_attr( $operation['primary'] ? 'button button-primary' : 'button' ); ?>" name="github_webhook_management_operation" value="<?php echo esc_attr( $operation['key'] ); ?>" formaction="<?php echo esc_url( $operation['url'] ); ?>"><?php echo esc_html( $operation['label'] ); ?></button>
					<?php endforeach; ?>
				</div>
				<?php if ( is_string( $model['action_help'] ) ) : ?>
					<p class="description ran-booster-github-webhook-management__action-help"><?php echo esc_html( $model['action_help'] ); ?></p>
				<?php endif; ?>
			</form>

			<aside class="ran-booster-public-lookup-profile__guidance">
				<?php /* translators: %s: repository provider name. */ ?>
				<strong><?php echo esc_html( sprintf( __( 'Required %s access', 'ran-booster' ), $model['provider_label'] ) ); ?></strong>
				<p><?php esc_html_e( 'Use a fine-grained token restricted to this repository with Webhooks read and write access.', 'ran-booster' ); ?></p>
				<strong><?php esc_html_e( 'Signing secret', 'ran-booster' ); ?></strong>
				<?php /* translators: %s: repository provider name. */ ?>
				<p><?php echo esc_html( sprintf( __( 'Booster reuses the most specific applicable saved signing secret. If none applies, it creates a repository-scoped secret. Only Core and the bound %s provider operation receive that secret; it is not exposed to the admin presentation layer.', 'ran-booster' ), $model['provider_label'] ) ); ?></p>
				<strong><?php esc_html_e( 'Safety boundaries', 'ran-booster' ); ?></strong>
				<ul>
					<li><?php esc_html_e( 'Only packages already set to Automatic can deploy.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Every operation reauthorizes this managed repository.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Unverified hooks are never adopted or deleted.', 'ran-booster' ); ?></li>
				</ul>
			</aside>
		</div>
	</div>
</section>
