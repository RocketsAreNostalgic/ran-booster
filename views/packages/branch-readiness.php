<?php

defined( 'WPINC' ) || die;

$providerBaseUrl               = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $providerCode ) );
$repositoryReadiness           = is_array( $packageBranchReadiness['repository'] ?? null )
	? $packageBranchReadiness['repository']
	: null;
$repositoryReasons             = is_array( $repositoryReadiness['reason_codes'] ?? null )
	? $repositoryReadiness['reason_codes']
	: array();
$readinessRepositoryId         = is_string( $repositoryReadiness['repository_id'] ?? null )
	? trim( $repositoryReadiness['repository_id'] )
	: '';
$persistedRepositoryId         = trim( (string) ( $providerRepositoryId ?? '' ) );
$identityConflict              = in_array( 'repository_identity_conflict', $repositoryReasons, true );
$repositoryId                  = '' !== $readinessRepositoryId
	? $readinessRepositoryId
	: ( ! $identityConflict ? $persistedRepositoryId : '' );
$providerSettingsUrl           = add_query_arg(
	array_filter(
		array(
			'panel'      => 'repositories',
			'repository' => $repositoryId,
		),
		static fn ( string $value ): bool => '' !== $value
	),
	$providerBaseUrl
);
$checkReturnUrl                = $isPackageEdit
	? add_query_arg( array( 'source_view' => 'branch' ), $settingsUrl ) . '#ran-booster-branch-readiness'
	: '';
$siteReadiness                 = is_array( $packageBranchReadiness['site'] ?? null )
	? $packageBranchReadiness['site']
	: null;
$receiverReady                 = 'ready' === ( $siteReadiness['status'] ?? null );
$repositoryBranchCheckOutcome  = isset( $repositoryBranchCheckOutcome ) && is_string( $repositoryBranchCheckOutcome )
	? $repositoryBranchCheckOutcome
	: null;
$savedIdentityReady            = ! $identityConflict
	&& '' !== $persistedRepositoryId
	&& '' !== trim( (string) ( $repositoryValue ?? '' ) );
$identityReady                 = $savedIdentityReady || ( null !== $repositoryReadiness
	&& array() === array_intersect(
		array( 'repository_locator_invalid', 'repository_identity_unavailable', 'repository_identity_conflict' ),
		$repositoryReasons
	) );
$repositoryDetailAvailable     = '' !== $repositoryId && $identityReady;
$secretCoverage                = (string) ( $repositoryReadiness['local_secret_coverage'] ?? 'unknown' );
$secretReady                   = in_array( $secretCoverage, array( 'repository', 'shared' ), true );
$needsAttention                = \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value === $deploymentPolicy
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
$publishedReleaseSource = 'release_asset' === ( $packageCurrentSource ?? null )
	|| 'release_asset' === ( $packageSourceView ?? null );
$automaticUpdatesReady  = $receiverReady && $identityReady && $secretReady;
$webhookStateClass      = match ( true ) {
	$publishedReleaseSource => 'is-pending',
	$automaticUpdatesReady  => 'is-ok',
	default                 => 'is-warning',
};
$webhookMessage = match ( true ) {
	$publishedReleaseSource => __( 'Pushes are ignored while Releases is active.', 'ran-booster' ),
	$automaticUpdatesReady  => __( 'Local webhook requirements are ready.', 'ran-booster' ),
	default                 => __( 'Local webhook requirements need attention.', 'ran-booster' ),
};
$webhookActionLabel = $publishedReleaseSource
	? __( 'View repository webhook status', 'ran-booster' )
	: __( 'Review repository webhook settings', 'ran-booster' );

?>
<section id="ran-booster-branch-readiness" class="ran-booster-package-source-readiness" aria-labelledby="ran-booster-branch-readiness-heading">
	<div>
		<div class="ran-booster-readiness-panel">
			<div class="ran-booster-readiness-panel__top">
				<div>
					<h4 id="ran-booster-branch-readiness-heading"><?php echo esc_html( $needsAttention ? __( 'Automatic branch deployment setup needs attention', 'ran-booster' ) : __( 'Saved branch setup', 'ran-booster' ) ); ?></h4>
					<p><?php echo esc_html( $setupSummary ); ?></p>
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
				<a
					class="button<?php echo $repositoryDetailAvailable ? '' : ' disabled'; ?>"
					<?php if ( $repositoryDetailAvailable ) { ?>
						href="<?php echo esc_url( $providerSettingsUrl ); ?>"
					<?php } else { ?>
						aria-disabled="true"
						tabindex="-1"
					<?php } ?>
				><?php echo esc_html( $webhookActionLabel ); ?></a>
			</div>
		</div>
	</div>
</section>
