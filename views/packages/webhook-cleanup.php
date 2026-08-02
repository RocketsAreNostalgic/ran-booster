<?php

declare(strict_types=1);

defined( 'WPINC' ) || die;

use RAN\Admin\WebhookCleanupContext;

$webhookCleanupContext = $packageWebhookCleanup['context'] ?? null;
if ( ! $webhookCleanupContext instanceof WebhookCleanupContext ) {
	return;
}
$webhookCleanupActions = is_array( $packageWebhookCleanup['actions'] ?? null )
	? array_values( array_filter( $packageWebhookCleanup['actions'], 'is_string' ) )
	: array();
$hasRetainedEvidence   = in_array( $webhookCleanupContext->localSecretCoverage(), array( 'repository', 'shared' ), true )
	|| array() !== $webhookCleanupActions;
if ( ! $hasRetainedEvidence ) {
	return;
}
$branchConsumers     = $webhookCleanupContext->branchPackageReferences();
$coverageDescription = match ( $webhookCleanupContext->localSecretCoverage() ) {
	'repository' => __( 'A repository-specific signing secret remains available locally.', 'ran-booster' ),
	'shared'     => __( 'A shared owner signing secret remains available locally.', 'ran-booster' ),
	'none'       => __( 'No matching local signing secret is currently saved.', 'ran-booster' ),
	default      => __( 'Local signing setup could not be checked.', 'ran-booster' ),
};
// Read-only presentation state; every add-on cleanup operation retains its own nonce.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reviewRequested = isset( $_GET['webhook_cleanup'] ) && is_scalar( $_GET['webhook_cleanup'] )
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	&& '1' === (string) wp_unslash( $_GET['webhook_cleanup'] );
?>
<section id="ran-booster-webhook-cleanup" class="ran-booster-settings-section ran-booster-webhook-cleanup" aria-labelledby="ran-booster-webhook-cleanup-heading">
	<header class="ran-booster-settings-section__header">
		<h3 id="ran-booster-webhook-cleanup-heading" class="ran-booster-section__title"><?php esc_html_e( 'Webhook setup retained', 'ran-booster' ); ?></h3>
		<p class="ran-booster-section__description"><?php esc_html_e( 'This package ignores pushes while using Published releases. Keeping its setup makes returning to Branch easier.', 'ran-booster' ); ?></p>
	</header>
	<div class="ran-booster-settings-section__body">
		<p><strong><?php esc_html_e( 'Signing setup', 'ran-booster' ); ?>:</strong> <?php echo esc_html( $coverageDescription ); ?></p>
		<details class="ran-booster-webhook-cleanup__review"<?php echo $reviewRequested ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'Review webhook cleanup', 'ran-booster' ); ?></summary>
			<div class="ran-booster-webhook-cleanup__content">
				<?php if ( ! $webhookCleanupContext->branchEvidenceAvailable() ) { ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'Cleanup is unavailable because Booster could not confirm whether branch-managed packages still use this repository setup.', 'ran-booster' ); ?></p>
					</div>
				<?php } elseif ( array() !== $branchConsumers ) { ?>
					<div class="notice notice-info inline">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d is the number of branch-managed packages sharing this repository. */
									_n(
										'Cleanup is unavailable because %d branch-managed package still uses this repository setup.',
										'Cleanup is unavailable because %d branch-managed packages still use this repository setup.',
										count( $branchConsumers ),
										'ran-booster'
									),
									count( $branchConsumers )
								)
							);
							?>
						</p>
					</div>
				<?php } else { ?>
					<p><?php esc_html_e( 'Cleanup is optional. Keep this setup if you expect to return to Branch; remove the remote webhook first when retiring it long term.', 'ran-booster' ); ?></p>
				<?php } ?>
				<div class="ran-booster-webhook-cleanup__actions ran-booster-action-row">
					<?php foreach ( $webhookCleanupActions as $webhookCleanupAction ) { ?>
						<?php echo $webhookCleanupAction; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted add-on output is isolated to the bounded cleanup action hook. ?>
					<?php } ?>
					<?php if ( '' !== $webhookCleanupContext->providerWebhooksUrl() ) { ?>
						<a class="button" href="<?php echo esc_url( $webhookCleanupContext->providerWebhooksUrl() ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open provider webhooks', 'ran-booster' ); ?></a>
					<?php } ?>
					<a class="button" href="<?php echo esc_url( $webhookCleanupContext->secretsUrl() ); ?>"><?php esc_html_e( 'Manage signing secrets', 'ran-booster' ); ?></a>
					<a href="<?php echo esc_url( $webhookCleanupContext->documentationUrl() ); ?>"><?php esc_html_e( 'Webhook cleanup guidance', 'ran-booster' ); ?></a>
				</div>
			</div>
		</details>
	</div>
</section>
