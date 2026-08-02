<?php

defined( 'WPINC' ) || die;

$providerBaseUrl      = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $providerCode ) );
$providerSettingsUrl  = add_query_arg(
	array( 'panel' => 'repositories' ),
	$providerBaseUrl
);
$providerSettingsUrl .= '#ran-booster-webhook-secrets-heading';
$providerSecretsUrl   = add_query_arg(
	array( 'view' => 'secrets' ),
	$providerBaseUrl
);
$activityUrl          = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' );
$setupUrl             = admin_url( 'admin.php?page=ran-booster&tab=documentation#ran-booster-push-to-deploy' );
$refreshBaseUrl       = add_query_arg( array( 'source_view' => 'branch' ), $settingsUrl );
$refreshUrl           = $refreshBaseUrl . '#ran-booster-branch-readiness';
$refreshRequestUrl    = add_query_arg( 'ran_booster_branch_readiness_check', '1', $refreshBaseUrl );
$refreshUrlParts      = wp_parse_url( $refreshRequestUrl );
$refreshArguments     = array();
if ( is_array( $refreshUrlParts ) ) {
	wp_parse_str( (string) ( $refreshUrlParts['query'] ?? '' ), $refreshArguments );
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display marker for the GET refresh.
$readinessCheckDone  = isset( $_GET['ran_booster_branch_readiness_check'] ) && '1' === (string) $_GET['ran_booster_branch_readiness_check'];
$siteReadiness       = is_array( $packageBranchReadiness['site'] ?? null )
	? $packageBranchReadiness['site']
	: null;
$siteReasons         = is_array( $siteReadiness['reason_codes'] ?? null )
	? $siteReadiness['reason_codes']
	: array();
$repositoryReadiness = is_array( $packageBranchReadiness['repository'] ?? null )
	? $packageBranchReadiness['repository']
	: null;
$receiverReady       = 'ready' === ( $siteReadiness['status'] ?? null );
$repositoryReasons   = is_array( $repositoryReadiness['reason_codes'] ?? null )
	? $repositoryReadiness['reason_codes']
	: array();
$identityReady       = null !== $repositoryReadiness
	&& array() === array_intersect(
		array( 'repository_locator_invalid', 'repository_identity_unavailable', 'repository_identity_conflict' ),
		$repositoryReasons
	);
$providerWebhookUrl  = trim( (string) ( $packageBranchReadiness['webhook_settings_url'] ?? '' ) );
$secretCoverage      = (string) ( $repositoryReadiness['local_secret_coverage'] ?? 'unknown' );
$secretReady         = in_array( $secretCoverage, array( 'repository', 'shared' ), true );
$secretLabel         = match ( $secretCoverage ) {
	'repository' => __( 'A repository-specific signing secret is saved.', 'ran-booster' ),
	'shared' => __( 'A shared owner signing secret covers this repository.', 'ran-booster' ),
	'none' => __( 'No matching local signing secret is saved.', 'ran-booster' ),
	default => __( 'Local signing-secret status is unavailable.', 'ran-booster' ),
};
$receiverLabel       = __( 'The site exposes a structurally valid HTTPS webhook endpoint.', 'ran-booster' );
$receiverActionUrl   = null;
$receiverActionLabel = null;
if ( ! $receiverReady ) {
	$receiverActionUrl   = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting' );
	$receiverActionLabel = __( 'Review Booster diagnostics', 'ran-booster' );
	$receiverLabel       = match ( true ) {
		in_array( 'callback_requires_public_https', $siteReasons, true )
			=> __( 'This WordPress URL cannot receive provider webhooks. Use a public HTTPS WordPress URL or a secure tunnel. Manual deployments remain available.', 'ran-booster' ),
		in_array( 'database_unavailable', $siteReasons, true )
			=> __( 'Booster could not access the local data required for Push-to-Deploy. Manual deployments remain available.', 'ran-booster' ),
		in_array( 'secrets_storage_unavailable', $siteReasons, true )
			=> __( 'Booster could not access the saved signing setup required for Push-to-Deploy. Manual deployments remain available.', 'ran-booster' ),
		in_array( 'managed_packages_unavailable', $siteReasons, true )
			=> __( 'Booster could not check the managed packages required for Push-to-Deploy. Manual deployments remain available.', 'ran-booster' ),
		default
			=> __( 'Booster could not confirm the local webhook receiver. Manual deployments remain available.', 'ran-booster' ),
	};
	if ( in_array( 'callback_requires_public_https', $siteReasons, true ) ) {
		$receiverActionUrl   = admin_url( 'options-general.php' );
		$receiverActionLabel = __( 'Review WordPress URLs', 'ran-booster' );
	}
}
$needsAttention = \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value === $deploymentPolicy
	&& ( ! $receiverReady || ! $identityReady || ! $secretReady );

?>
<section id="ran-booster-branch-readiness" class="ran-booster-package-source-readiness" aria-labelledby="ran-booster-branch-readiness-heading">
	<header>
		<h3 id="ran-booster-branch-readiness-heading" class="ran-booster-section__title"><?php esc_html_e( 'Branch readiness', 'ran-booster' ); ?></h3>
		<p class="ran-booster-section__description"><?php esc_html_e( 'Check the saved branch and webhook requirements. Manual deployments remain available when Push-to-Deploy is incomplete.', 'ran-booster' ); ?></p>
	</header>
	<div>
		<div class="ran-booster-readiness-panel">
			<?php if ( $readinessCheckDone ) { ?>
				<div class="notice <?php echo is_array( $packageBranchReadiness ) ? 'notice-success' : 'notice-warning'; ?> inline"<?php echo is_array( $packageBranchReadiness ) ? ' data-ran-booster-package-success' : ''; ?> data-ran-booster-branch-readiness-check>
					<p><strong><?php esc_html_e( 'Readiness check complete.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'The current local readiness evidence is shown below.', 'ran-booster' ); ?></p>
				</div>
			<?php } ?>
			<div class="ran-booster-readiness-panel__top">
				<div>
					<h4><?php echo esc_html( $needsAttention ? __( 'Automatic branch deployments need attention', 'ran-booster' ) : __( 'Branch is ready for manual deployments', 'ran-booster' ) ); ?></h4>
					<p><?php echo esc_html( $needsAttention ? __( 'Local Push-to-Deploy requirements are incomplete. Confirm the remote repository webhook separately.', 'ran-booster' ) : __( 'Booster can use the saved branch for manual deployments. Remote webhook delivery is not inferred here.', 'ran-booster' ) ); ?></p>
				</div>
				<?php if ( $needsAttention ) { ?>
					<span class="ran-booster-badge ran-booster-badge--error"><?php esc_html_e( 'Needs attention', 'ran-booster' ); ?></span>
				<?php } ?>
			</div>
			<ul class="ran-booster-readiness-list">
				<li class="ran-booster-readiness-item <?php echo $identityReady ? 'is-ok' : 'is-warning'; ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Saved branch', 'ran-booster' ); ?></strong>
					<span>
						<?php
						echo esc_html(
							$identityReady
								? sprintf(
									/* translators: %s is a branch name. */
									__( '%s is ready for manual deployments.', 'ran-booster' ),
									'' !== $branchValue ? $branchValue : __( 'The provider default branch', 'ran-booster' )
								)
								: __( 'The saved repository needs one stable provider identity.', 'ran-booster' )
						);
						?>
					</span>
				</li>
				<li class="ran-booster-readiness-item <?php echo $secretReady ? 'is-ok' : 'is-warning'; ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Signing secret', 'ran-booster' ); ?></strong>
					<span>
						<?php echo esc_html( $secretLabel ); ?>
						<?php if ( 'none' === $secretCoverage && $providerWebhookAvailable ) { ?>
							<br/><a href="<?php echo esc_url( $providerSecretsUrl ); ?>"><?php esc_html_e( 'Manage signing secrets', 'ran-booster' ); ?></a>
						<?php } ?>
					</span>
				</li>
				<li class="ran-booster-readiness-item <?php echo $receiverReady ? 'is-ok' : 'is-warning'; ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Local receiver', 'ran-booster' ); ?></strong>
					<span>
						<?php echo esc_html( $receiverLabel ); ?>
						<?php if ( null !== $receiverActionUrl && null !== $receiverActionLabel ) { ?>
							<br/><a href="<?php echo esc_url( $receiverActionUrl ); ?>"><?php echo esc_html( $receiverActionLabel ); ?></a>
						<?php } ?>
					</span>
				</li>
				<li class="ran-booster-readiness-item is-warning">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Remote webhook', 'ran-booster' ); ?></strong>
					<span>
						<?php esc_html_e( 'Booster cannot verify the remote webhook here. Confirm it on the repository provider.', 'ran-booster' ); ?>
						<?php if ( '' !== $providerWebhookUrl ) { ?>
							<br/><a href="<?php echo esc_url( $providerWebhookUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage repository webhooks', 'ran-booster' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span></a>
						<?php } ?>
					</span>
				</li>
			</ul>
			<div class="ran-booster-readiness-actions">
				<form
					action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
					method="get"
					class="ran-booster-branch-readiness-check-form"
					data-ran-booster-enhanced-mutation
					data-ran-booster-error-target="#ran-booster-package-mutation-error"
					hx-get="<?php echo esc_url( $refreshRequestUrl ); ?>"
					hx-target="#wpbody-content"
					hx-select="#wpbody-content"
					hx-swap="outerHTML show:#ran-booster-branch-readiness:top"
					hx-push-url="<?php echo esc_url( $refreshUrl ); ?>"
					hx-sync="this:drop"
				>
					<?php foreach ( $refreshArguments as $name => $value ) { ?>
						<?php if ( is_scalar( $value ) ) { ?>
							<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
						<?php } ?>
					<?php } ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Run readiness check', 'ran-booster' ); ?></button>
				</form>
				<a class="button" href="<?php echo esc_url( $providerSettingsUrl ); ?>"><?php esc_html_e( 'Manage repository webhook', 'ran-booster' ); ?></a>
				<span class="ran-booster-readiness-actions__links">
					<a href="<?php echo esc_url( $setupUrl ); ?>"><?php esc_html_e( 'Setup instructions', 'ran-booster' ); ?></a>
					<a href="<?php echo esc_url( $activityUrl ); ?>"><?php esc_html_e( 'Booster Activity', 'ran-booster' ); ?></a>
				</span>
			</div>
		</div>
	</div>
</section>
