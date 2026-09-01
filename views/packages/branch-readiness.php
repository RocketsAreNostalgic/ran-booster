<?php

defined( 'WPINC' ) || die;

$providerBaseUrl              = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $providerCode ) );
$repositoryReadiness          = is_array( $packageBranchReadiness['repository'] ?? null )
	? $packageBranchReadiness['repository']
	: null;
$repositoryReasons            = is_array( $repositoryReadiness['reason_codes'] ?? null )
	? $repositoryReadiness['reason_codes']
	: array();
$readinessRepositoryId        = is_string( $repositoryReadiness['repository_id'] ?? null )
	? trim( $repositoryReadiness['repository_id'] )
	: '';
$persistedRepositoryId        = trim( (string) ( $providerRepositoryId ?? '' ) );
$identityConflict             = in_array( 'repository_identity_conflict', $repositoryReasons, true );
$repositoryLocatorInvalid     = in_array( 'repository_locator_invalid', $repositoryReasons, true );
$repositoryId                 = '' !== $readinessRepositoryId
	? $readinessRepositoryId
	: ( ! $identityConflict && ! $repositoryLocatorInvalid ? $persistedRepositoryId : '' );
$providerSettingsUrl          = add_query_arg(
	array_filter(
		array(
			'panel'           => 'repositories',
			'repository'      => $repositoryId,
			'repository_view' => 'branch',
		),
		static fn ( string $value ): bool => '' !== $value
	),
	$providerBaseUrl
);
$checkReturnUrl               = $isPackageEdit
	? add_query_arg( array( 'source_view' => 'branch' ), $settingsUrl ) . '#ran-booster-branch-readiness'
	: '';
$siteReadiness                = is_array( $packageBranchReadiness['site'] ?? null )
	? $packageBranchReadiness['site']
	: null;
$siteReasons                  = is_array( $siteReadiness['reason_codes'] ?? null )
	? $siteReadiness['reason_codes']
	: array();
$receiverReady                = 'ready' === ( $siteReadiness['status'] ?? null );
$repositoryBranchCheckOutcome = isset( $repositoryBranchCheckOutcome ) && is_string( $repositoryBranchCheckOutcome )
	? $repositoryBranchCheckOutcome
	: null;
$savedIdentityReady           = ! $identityConflict
	&& ! $repositoryLocatorInvalid
	&& '' !== $persistedRepositoryId
	&& '' !== trim( (string) ( $repositoryValue ?? '' ) );
$identityReady                = $savedIdentityReady || ( null !== $repositoryReadiness
	&& array() === array_intersect(
		array( 'repository_locator_invalid', 'repository_identity_unavailable', 'repository_identity_conflict' ),
		$repositoryReasons
	) );
$repositoryDetailAvailable    = '' !== $repositoryId && $identityReady;
$secretCoverage               = (string) ( $repositoryReadiness['local_secret_coverage'] ?? 'unknown' );
$secretReady                  = in_array( $secretCoverage, array( 'repository', 'shared' ), true );
$publishedReleaseSource       = true === ( $releaseManaged ?? false )
	|| 'release_asset' === ( $packageCurrentSource ?? null )
	|| 'release_asset' === ( $packageSourceView ?? null );
$retainedReadiness            = true === ( $packageBranchReadiness['retained'] ?? false );
$secretLabel                  = match ( $secretCoverage ) {
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
$needsAttention                = ! $publishedReleaseSource
	&& \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value === $deploymentPolicy
	&& ( ! $receiverReady || ! $identityReady || ! $secretReady );
$repositoryBranchCheckEvidence = is_array( $repositoryBranchCheckEvidence ?? null )
	? $repositoryBranchCheckEvidence
	: null;
$repositoryBranchVerified      = in_array( $repositoryBranchCheckOutcome ?? null, array( 'verified', 'subdirectory_unavailable', 'subdirectory_unverified' ), true )
	|| ( null === $repositoryBranchCheckOutcome && 'verified' === ( $repositoryBranchCheckEvidence['outcome'] ?? null ) );
$savedSubdirectoryValue        = isset( $savedSubdirectoryValue ) && is_string( $savedSubdirectoryValue )
	? trim( $savedSubdirectoryValue )
	: '';
$repositoryBranchCheckMessage  = match ( $repositoryBranchCheckOutcome ?? null ) {
	'provider_unavailable' => __( 'The saved provider is unavailable, so Booster could not check the repository and branch.', 'ran-booster' ),
	'unable_to_check'      => __( 'Booster could not access the saved repository and branch. Check the branch name and repository access, then try again.', 'ran-booster' ),
	'subdirectory_unavailable' => __( 'The saved branch is accessible, but Booster could not find the configured subdirectory. Check the path and try again.', 'ran-booster' ),
	'subdirectory_unverified'  => __( 'The saved branch is accessible, but Booster could not check the configured subdirectory. Try again later.', 'ran-booster' ),
	default                => null,
};
$repositoryBranchCheckNoticeClass = null !== $repositoryBranchCheckMessage ? 'notice-warning' : 'notice-error';
$repositoryStateClass             = match ( true ) {
	! $identityReady                         => 'is-warning',
	$repositoryBranchVerified                => 'is-ok',
	$savedIdentityReady                      => 'is-ok',
	null !== $repositoryBranchCheckOutcome   => 'is-warning',
	default                                  => 'is-pending',
};
if ( in_array( $repositoryBranchCheckOutcome ?? null, array( 'verified', 'subdirectory_unavailable', 'subdirectory_unverified' ), true ) ) {
	$savedRepositoryLabel = __( 'is accessible with the saved repository settings.', 'ran-booster' );
} elseif ( $repositoryBranchVerified ) {
	$savedRepositoryLabel = __( 'was accessible at the last check.', 'ran-booster' );
} elseif ( 'provider_unavailable' === ( $repositoryBranchCheckOutcome ?? null ) ) {
	$savedRepositoryLabel = __( 'is saved, but the provider is unavailable.', 'ran-booster' );
} elseif ( 'unable_to_check' === ( $repositoryBranchCheckOutcome ?? null ) ) {
	$savedRepositoryLabel = __( 'is saved, but access could not be verified.', 'ran-booster' );
} else {
	$savedRepositoryLabel = __( 'is saved. Access has not been checked.', 'ran-booster' );
}
$savedRepositoryMessage = $identityReady
	? sprintf(
		/* translators: 1: branch name, 2: repository check status. */
		__( 'The branch <code>%1$s</code> %2$s', 'ran-booster' ),
		esc_html( '' !== $branchValue ? $branchValue : __( 'The provider default branch', 'ran-booster' ) ),
		esc_html( $savedRepositoryLabel )
	)
	: __( 'The saved repository needs one stable provider identity.', 'ran-booster' );
$savedSubdirectoryMessage = '' === $savedSubdirectoryValue
	? __( 'Repository root (no subdirectory).', 'ran-booster' )
	: match ( $repositoryBranchCheckOutcome ?? null ) {
		'verified' => sprintf(
			/* translators: %s: configured repository subdirectory. */
			__( 'The subdirectory <code>%s</code> is accessible at this branch.', 'ran-booster' ),
			esc_html( $savedSubdirectoryValue )
		),
		'subdirectory_unavailable' => sprintf(
			/* translators: %s: configured repository subdirectory. */
			__( 'The subdirectory <code>%s</code> was not found at this branch.', 'ran-booster' ),
			esc_html( $savedSubdirectoryValue )
		),
		'subdirectory_unverified' => sprintf(
			/* translators: %s: configured repository subdirectory. */
			__( 'The subdirectory <code>%s</code> could not be checked.', 'ran-booster' ),
			esc_html( $savedSubdirectoryValue )
		),
		default => sprintf(
			/* translators: %s: configured repository subdirectory. */
			__( 'The subdirectory <code>%s</code> will be checked when Booster prepares the deployment archive.', 'ran-booster' ),
			esc_html( $savedSubdirectoryValue )
		),
	};
$subdirectoryStateClass = '' === $savedSubdirectoryValue
	? 'is-ok'
	: match ( $repositoryBranchCheckOutcome ?? null ) {
		'verified' => 'is-ok',
		'subdirectory_unavailable', 'subdirectory_unverified' => 'is-warning',
		default => 'is-pending',
	};
$automaticUpdatesReady = $receiverReady && $identityReady && $secretReady;
$webhookStateClass     = match ( true ) {
	$publishedReleaseSource => 'is-pending',
	$automaticUpdatesReady  => 'is-ok',
	default                 => 'is-warning',
};
$webhookMessage = match ( true ) {
	$publishedReleaseSource => __( 'Pushes are ignored while Releases is active.', 'ran-booster' ),
	$automaticUpdatesReady  => __( 'Local webhook requirements are ready.', 'ran-booster' ),
	default                 => __( 'Local webhook requirements need attention.', 'ran-booster' ),
};
$webhookActionLabel = __( 'Manage webhooks', 'ran-booster' );

?>
<section id="ran-booster-branch-readiness" class="ran-booster-package-source-readiness" aria-labelledby="ran-booster-branch-readiness-heading">
	<div>
		<div class="ran-booster-readiness-panel">
			<div class="ran-booster-readiness-panel__top">
				<div>
					<h4 id="ran-booster-branch-readiness-heading"><?php esc_html_e( 'Branch readiness', 'ran-booster' ); ?></h4>
				</div>
				<?php if ( $needsAttention ) { ?>
					<span class="ran-booster-badge ran-booster-badge--error"><?php esc_html_e( 'Needs attention', 'ran-booster' ); ?></span>
				<?php } ?>
			</div>
			<ul class="ran-booster-readiness-list">
				<li class="ran-booster-readiness-item <?php echo esc_attr( $repositoryStateClass ); ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Saved repository', 'ran-booster' ); ?></strong>
					<span>
						<?php echo wp_kses_post( $savedRepositoryMessage ); ?>
						<?php if ( $repositoryBranchVerified && null !== $repositoryBranchCheckEvidence ) { ?>
							<br/><span>
							<?php
							/* translators: %s: UTC timestamp of the last successful repository branch check. */
							echo esc_html( sprintf( __( 'Last checked: %s.', 'ran-booster' ), (string) $repositoryBranchCheckEvidence['checked_at'] ) );
							?>
							</span>
						<?php } ?>
					</span>
				</li>
				<li class="ran-booster-readiness-item <?php echo esc_attr( $subdirectoryStateClass ); ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Repository subdirectory', 'ran-booster' ); ?></strong>
					<span><?php echo wp_kses_post( $savedSubdirectoryMessage ); ?></span>
				</li>
				<?php if ( $retainedReadiness ) { ?>
				<li class="ran-booster-readiness-item <?php echo $secretReady ? 'is-ok' : 'is-warning'; ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Signing secret', 'ran-booster' ); ?></strong>
					<span>
						<?php echo esc_html( $secretLabel ); ?>
						<?php if ( 'none' === $secretCoverage && $providerWebhookAvailable ) { ?>
							<br/><a href="<?php echo esc_url( $providerSettingsUrl ); ?>"><?php esc_html_e( 'Manage signing secrets', 'ran-booster' ); ?></a>
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
				<?php } ?>
				<li class="ran-booster-readiness-item <?php echo esc_attr( $webhookStateClass ); ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Webhook health', 'ran-booster' ); ?></strong>
					<span>
						<?php echo esc_html( $webhookMessage ); ?>
						<?php if ( $repositoryDetailAvailable ) { ?>
							<br/><a href="<?php echo esc_url( $providerSettingsUrl ); ?>"><?php echo esc_html( $webhookActionLabel ); ?></a>
						<?php } ?>
					</span>
				</li>
			</ul>
			<div class="ran-booster-readiness-actions">
				<div
					id="ran-booster-repository-branch-check-error"
					class="notice <?php echo esc_attr( $repositoryBranchCheckNoticeClass ); ?> inline"
					role="alert"
					tabindex="-1"
					<?php if ( null !== $repositoryBranchCheckMessage ) { ?>
						data-ran-booster-repository-branch-check
					<?php } else { ?>
						hidden
					<?php } ?>
				><p><?php echo esc_html( $repositoryBranchCheckMessage ?? '' ); ?></p></div>
				<?php if ( $isPackageEdit ) { ?>
					<button
					type="submit"
					name="ran_booster[check_repository_branch_after_save]"
					value="1"
					form="ran-booster-package-edit-form"
					class="button button-primary ran-booster-branch-readiness-check-form"
					data-ran-booster-enhanced-mutation
					data-ran-booster-error-target="#ran-booster-repository-branch-check-error"
					data-ran-booster-relocate-rendered-error
					hx-post="<?php echo esc_url( wp_make_link_relative( (string) $settingsUrl ) ); ?>"
					hx-target="#wpbody-content"
					hx-select="#wpbody-content"
					hx-swap="outerHTML show:#ran-booster-branch-readiness:top"
					hx-push-url="<?php echo esc_url( wp_make_link_relative( (string) $checkReturnUrl ) ); ?>"
					hx-sync="this:drop"
					hx-include="#ran-booster-package-edit-form, [form=&quot;ran-booster-package-edit-form&quot;]"
					<?php disabled( isset( $packageMutationAvailable ) && false === $packageMutationAvailable ); ?>
					><?php esc_html_e( 'Save settings and check', 'ran-booster' ); ?></button>
				<?php } ?>
				<?php if ( $repositoryDetailAvailable ) { ?>
					<a class="button" href="<?php echo esc_url( $providerSettingsUrl ); ?>"><?php echo esc_html( $webhookActionLabel ); ?></a>
				<?php } else { ?>
					<button type="button" class="button" disabled aria-disabled="true"><?php echo esc_html( $webhookActionLabel ); ?></button>
				<?php } ?>
			</div>
		</div>
	</div>
</section>
