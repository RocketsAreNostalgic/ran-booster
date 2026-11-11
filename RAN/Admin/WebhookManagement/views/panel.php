<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array<string, mixed> $model */
/** @var string $form_attributes */
$disabled         = true === ( $model['disabled'] ?? false );
$profile_disabled = $disabled || true === ( $model['webhook_profile_disabled'] ?? false );
$result_notices   = array_values( array_filter( array( $model['result'] ?? null, $model['recovery_warning'] ?? null ), 'is_array' ) );
// The persistent Manual webhook notice is static page content; a fresh operation result or recovery notice replaces it for this load.
if ( array() === $result_notices && is_array( $model['manual_notice'] ?? null ) ) {
	$result_notices = array( $model['manual_notice'] );
}
$render_result_notices = static function () use ( $result_notices ): void {
	?>
		<?php if ( array() !== $result_notices ) : ?>
			<div class="ran-booster-repository-webhook-management__result">
			<?php foreach ( $result_notices as $notice ) : ?>
				<?php $intent = substr( (string) $notice['class'], 7 ); ?>
				<div class="notice <?php echo esc_attr( $notice['class'] ); ?> inline ran-booster-repository-webhook-management__notice ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( $intent ); ?>" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo esc_attr( $intent ); ?>"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
					<?php if ( isset( $notice['heading'] ) ) : ?>
						<p><strong><?php echo esc_html( $notice['heading'] ); ?></strong></p>
					<?php endif; ?>
					<?php if ( isset( $notice['lines'] ) ) : ?>
						<?php foreach ( $notice['lines'] as $line ) : ?>
							<p><strong><?php echo esc_html( $line['label'] ); ?>:</strong> <?php echo esc_html( $line['text'] ); ?></p>
						<?php endforeach; ?>
					<?php else : ?>
						<p><?php echo esc_html( $notice['message'] ); ?></p>
					<?php endif; ?>
					<?php foreach ( $notice['details'] ?? array() as $detail ) : ?>
						<p><?php echo esc_html( $detail ); ?></p>
					<?php endforeach; ?>
				</div><?php \RAN\Admin\Component\NoticeIcon::print_intent( $intent ); ?></div>
			<?php endforeach; ?>
			</div>
		<?php endif; ?>
	<?php
};

// 'all' renders the form with its actions (package settings); 'form' and 'actions' render the same management form and its footer band as two parts
// so the band can finish the Webhook setup disclosure outside the form and span its body; the band's buttons then name the form with form=.
$panel_part = isset( $panel_part ) && in_array( $panel_part, array( 'form', 'actions' ), true ) ? $panel_part : 'all';
$form_id    = 'repository-webhook-management-form';

$render_operation_buttons = static function ( bool $external ) use ( $model, $disabled, $form_id ): void {
	foreach ( $model['operations'] as $operation ) {
		$operation_disabled = $disabled || true === ( $operation['disabled'] ?? false );
		$description_id     = 'repository-webhook-management-description-' . $operation['key'];
		$reason             = $operation_disabled && is_string( $operation['disabled_reason'] ?? null ) ? $operation['disabled_reason'] : '';
		$reason_id          = 'repository-webhook-management-disabled-reason-' . $operation['key'];
		$describedby        = $description_id . ( '' !== $reason ? ' ' . $reason_id : '' );
		if ( $operation['primary'] ) {
			$button_classes = \RAN\Admin\Component\ButtonClasses::primary();
		} elseif ( $operation['destructive'] ) {
			$button_classes = \RAN\Admin\Component\ButtonClasses::for( 'danger', 'outline' );
		} else {
			$button_classes = \RAN\Admin\Component\ButtonClasses::secondary();
		}
		$button_title = $operation['description'];
		if ( '' !== $reason ) {
			$button_title .= ' ' . $reason;
		}
		?>
		<button type="submit" class="<?php echo esc_attr( $button_classes ); ?>"<?php echo $external ? ' form="' . esc_attr( $form_id ) . '"' : ''; ?> name="repository_webhook_management_operation" value="<?php echo esc_attr( $operation['key'] ); ?>" formaction="<?php echo esc_url( $operation['url'] ); ?>" title="<?php echo esc_attr( $button_title ); ?>" aria-describedby="<?php echo esc_attr( $describedby ); ?>" data-ran-booster-busy-label="<?php echo esc_attr( $operation['busy_label'] ); ?>" data-ran-booster-busy-message="<?php echo esc_attr( $operation['busy_message'] ); ?>"<?php echo is_string( $operation['confirm'] ) ? ' data-confirm="' . esc_attr( $operation['confirm'] ) . '"' : ''; ?><?php disabled( $operation_disabled ); ?> aria-disabled="<?php echo $operation_disabled ? 'true' : 'false'; ?>"><?php echo esc_html( $operation['label'] ); ?></button>
		<span class="screen-reader-text" id="<?php echo esc_attr( $description_id ); ?>"><?php echo esc_html( $operation['description'] ); ?></span>
		<?php if ( ! $external && is_array( $operation['companion_nonce'] ?? null ) ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $operation['companion_nonce']['name'] ); ?>" value="<?php echo esc_attr( $operation['companion_nonce']['value'] ); ?>">
		<?php endif; ?>
		<?php if ( '' !== $reason ) : ?>
			<span class="screen-reader-text" id="<?php echo esc_attr( $reason_id ); ?>"><?php echo esc_html( $reason ); ?></span>
		<?php endif; ?>
		<?php
	}
};

if ( 'actions' === $panel_part ) {
	?>
<div class="ran-booster-card-footer ran-booster-repository-webhook-management__actions" role="group" aria-label="<?php echo esc_attr( __( 'Webhook', 'ran-booster' ) ); ?>">
	<?php $render_operation_buttons( true ); ?>
</div>
	<?php
	return;
}
?>
<div class="ran-booster-repository-webhook-management">
	<div class="ran-booster-repository-webhook-management__panel">
		<div class="ran-booster-public-lookup-profile__layout ran-booster-repository-webhook-management__layout">
			<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( $model['form_action'] ); ?>" class="ran-booster-public-lookup-profile__form ran-booster-repository-webhook-management__form" data-ran-booster-submit-busy<?php echo $form_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact Core Admin Interaction facade owns these form attributes. ?>>
				<input type="hidden" name="action" value="<?php echo esc_attr( $model['admin_action'] ); ?>">
				<input type="hidden" name="provider_code" value="<?php echo esc_attr( $model['provider_code'] ); ?>">
				<input type="hidden" name="repository_id" value="<?php echo esc_attr( $model['repository_id'] ); ?>">
				<input type="hidden" name="return_url" value="<?php echo esc_url( $model['return_url'] ); ?>">
				<div id="repository-webhook-management-error"></div>
					<div class="ran-booster-repository-webhook-management__field ran-booster-repository-webhook-management__field--wide">
						<label class="ran-booster-public-lookup-profile__label" for="repository-webhook-management-saved-credential"><?php esc_html_e( 'Management credential', 'ran-booster' ); ?></label>
						<div class="ran-booster-repository-webhook-management__select-action">
							<select id="repository-webhook-management-saved-credential" name="booster_credential_id"<?php disabled( $disabled ); ?><?php echo $disabled ? '' : ' required'; ?>>
								<option value=""><?php esc_html_e( 'Choose a saved credential', 'ran-booster' ); ?></option>
								<?php foreach ( $model['credential_choices'] as $choice ) : ?>
									<option value="<?php echo esc_attr( $choice['id'] ); ?>"<?php selected( $model['management_credential_id'] ?? null, $choice['id'] ); ?>><?php echo esc_html( $choice['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) ); ?>" href="<?php echo esc_url( $model['credentials_url'] ); ?>"><?php esc_html_e( 'Manage credentials', 'ran-booster' ); ?></a>
						</div>
						<span class="description"><?php esc_html_e( 'Choose the saved credential used to manage this repository webhook. A current selection does not prove Git provider authority until the operation checks it.', 'ran-booster' ); ?></span>
					</div>

					<div class="ran-booster-repository-webhook-management__field ran-booster-repository-webhook-management__field--wide">
						<label class="ran-booster-public-lookup-profile__label" for="repository-webhook-management-webhook-profile"><?php esc_html_e( 'Signing secret', 'ran-booster' ); ?></label>
						<div class="ran-booster-repository-webhook-management__select-action">
							<select id="repository-webhook-management-webhook-profile" name="webhook_profile_id"<?php disabled( $profile_disabled ); ?><?php echo $profile_disabled ? '' : ' required'; ?>>
								<option value="" selected disabled><?php echo esc_html( $model['webhook_profile_placeholder'] ); ?></option>
								<option value="create_repository_secret"><?php esc_html_e( 'Create a repository signing secret', 'ran-booster' ); ?></option>
								<?php foreach ( $model['webhook_profile_choices'] as $choice ) : ?>
									<option value="<?php echo esc_attr( $choice['id'] ); ?>"><?php echo esc_html( $choice['label'] . ' (' . $choice['scope'] . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) ); ?>" href="<?php echo esc_url( $model['secrets_url'] ); ?>"><?php esc_html_e( 'Manage signing secrets', 'ran-booster' ); ?></a>
						</div>
						<span class="description"><?php esc_html_e( 'Choose an applicable saved signing secret, or create one only for this repository.', 'ran-booster' ); ?></span>
					</div>

				<?php if ( 'all' === $panel_part ) : ?>
				<div class="ran-booster-repository-webhook-management__field ran-booster-repository-webhook-management__field--wide">
					<p class="ran-booster-public-lookup-profile__label" id="repository-webhook-management-actions-label"><?php esc_html_e( 'Webhook', 'ran-booster' ); ?></p>
					<div class="ran-booster-action-row ran-booster-repository-webhook-management__actions" role="group" aria-labelledby="repository-webhook-management-actions-label">
						<?php $render_operation_buttons( false ); ?>
					</div>
					<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>
					<?php $render_result_notices(); ?>
				</div>
				<?php else : ?>
					<?php foreach ( $model['operations'] as $operation ) : ?>
						<?php if ( is_array( $operation['companion_nonce'] ?? null ) ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $operation['companion_nonce']['name'] ); ?>" value="<?php echo esc_attr( $operation['companion_nonce']['value'] ); ?>">
						<?php endif; ?>
					<?php endforeach; ?>
					<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>
					<?php $render_result_notices(); ?>
				<?php endif; ?>
				<?php if ( is_string( $model['action_help'] ) ) : ?>
					<p class="description ran-booster-repository-webhook-management__action-help"><?php echo esc_html( $model['action_help'] ); ?></p>
				<?php endif; ?>
				<?php if ( is_string( $model['webhooks_url'] ?? null ) && '' !== $model['webhooks_url'] ) : ?>
					<?php /* translators: %s: repository provider name. */ ?>
					<p class="ran-booster-standalone-link-row"><a href="<?php echo esc_url( $model['webhooks_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( sprintf( __( 'Manage repo webhooks on %s', 'ran-booster' ), 'GitHub' === $model['provider_label'] ? 'Github' : $model['provider_label'] ) ); ?> <?php \RAN\Admin\Component\ExternalLinkMarker::print(); ?></a></p>
				<?php endif; ?>
			</form>

		</div>
	</div>
</div>
