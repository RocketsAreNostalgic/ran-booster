<?php

/**
 * Inherited from the including package template.
 *
 * @var string $branch_value
 * @var string $deployment_policy
 * @var array<string, mixed>|null $package_branch_readiness
 * @var string $provider_code
 * @var bool $provider_webhook_available
 * @var string $settings_url
 */

defined( 'WPINC' ) || die;

$is_package_edit                 = true === ( $is_package_edit ?? false );
$provider_base_url               = admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider_code ) );
$repository_readiness            = is_array( $package_branch_readiness['repository'] ?? null )
	? $package_branch_readiness['repository']
	: null;
$repository_reasons              = is_array( $repository_readiness['reason_codes'] ?? null )
	? $repository_readiness['reason_codes']
	: array();
$readiness_repository_id         = is_string( $repository_readiness['repository_id'] ?? null )
	? trim( $repository_readiness['repository_id'] )
	: '';
$persisted_repository_id         = trim( (string) ( $provider_repository_id ?? '' ) );
$identity_conflict               = in_array( 'repository_identity_conflict', $repository_reasons, true );
$repository_locator_invalid      = in_array( 'repository_locator_invalid', $repository_reasons, true );
$repository_id                   = '' !== $readiness_repository_id
	? $readiness_repository_id
	: ( ! $identity_conflict && ! $repository_locator_invalid ? $persisted_repository_id : '' );
$provider_settings_url           = add_query_arg(
	array_filter(
		array(
			'panel'           => 'repositories',
			'repository'      => $repository_id,
			'repository_view' => 'branch',
		),
		static fn ( string $value ): bool => '' !== $value
	),
	$provider_base_url
);
$check_return_url                = $is_package_edit
	? add_query_arg( array( 'source_view' => 'branch' ), $settings_url ) . '#ran-booster-branch-readiness'
	: '';
$site_readiness                  = is_array( $package_branch_readiness['site'] ?? null )
	? $package_branch_readiness['site']
	: null;
$site_reasons                    = is_array( $site_readiness['reason_codes'] ?? null )
	? $site_readiness['reason_codes']
	: array();
$receiver_ready                  = 'ready' === ( $site_readiness['status'] ?? null );
$repository_branch_check_outcome = isset( $repository_branch_check_outcome ) && is_string( $repository_branch_check_outcome )
	? $repository_branch_check_outcome
	: null;
$saved_identity_ready            = ! $identity_conflict
	&& ! $repository_locator_invalid
	&& '' !== $persisted_repository_id
	&& '' !== trim( (string) ( $repository_value ?? '' ) );
$identity_ready                  = $saved_identity_ready || ( null !== $repository_readiness
	&& array() === array_intersect(
		array( 'repository_locator_invalid', 'repository_identity_unavailable', 'repository_identity_conflict' ),
		$repository_reasons
	) );
$repository_detail_available     = '' !== $repository_id && $identity_ready;
$secret_coverage                 = (string) ( $repository_readiness['local_secret_coverage'] ?? 'unknown' );
$secret_ready                    = in_array( $secret_coverage, array( 'repository', 'shared' ), true );
$published_release_source        = true === ( $release_managed ?? false )
	|| 'release_asset' === ( $package_current_source ?? null )
	|| 'release_asset' === ( $package_source_view ?? null );
$retained_readiness              = true === ( $package_branch_readiness['retained'] ?? false );
$secret_label                    = match ( $secret_coverage ) {
	'repository' => __( 'A repository-specific signing secret is saved.', 'ran-booster' ),
	'shared' => __( 'A shared owner signing secret covers this repository.', 'ran-booster' ),
	'none' => __( 'No matching local signing secret is saved.', 'ran-booster' ),
	default => __( 'Local signing-secret status is unavailable.', 'ran-booster' ),
};
$receiver_label        = __( 'The site exposes a structurally valid HTTPS webhook endpoint.', 'ran-booster' );
$receiver_action_url   = null;
$receiver_action_label = null;
if ( ! $receiver_ready ) {
	$receiver_action_url   = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting' );
	$receiver_action_label = __( 'Review Booster diagnostics', 'ran-booster' );
	$receiver_label        = match ( true ) {
		in_array( 'callback_requires_public_https', $site_reasons, true )
			=> __( 'This WordPress URL cannot receive provider webhooks. Use a public HTTPS WordPress URL or a secure tunnel. Manual deployments remain available.', 'ran-booster' ),
		in_array( 'database_unavailable', $site_reasons, true )
			=> __( 'Booster could not access the local data required for Push-to-Deploy. Manual deployments remain available.', 'ran-booster' ),
		in_array( 'secrets_storage_unavailable', $site_reasons, true )
			=> __( 'Booster could not access the saved signing setup required for Push-to-Deploy. Manual deployments remain available.', 'ran-booster' ),
		in_array( 'managed_packages_unavailable', $site_reasons, true )
			=> __( 'Booster could not check the managed packages required for Push-to-Deploy. Manual deployments remain available.', 'ran-booster' ),
		default
			=> __( 'Booster could not confirm the local webhook receiver. Manual deployments remain available.', 'ran-booster' ),
	};
	if ( in_array( 'callback_requires_public_https', $site_reasons, true ) ) {
		$receiver_action_url   = admin_url( 'options-general.php' );
		$receiver_action_label = __( 'Review WordPress URLs', 'ran-booster' );
	}
}
$needs_attention                  = ! $published_release_source
	&& \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value === $deployment_policy
	&& ( ! $receiver_ready || ! $identity_ready || ! $secret_ready );
$repository_branch_check_evidence = is_array( $repository_branch_check_evidence ?? null )
	? $repository_branch_check_evidence
	: null;
$repository_branch_verified       = in_array( $repository_branch_check_outcome ?? null, array( 'verified', 'subdirectory_unavailable', 'subdirectory_unverified' ), true )
	|| ( null === $repository_branch_check_outcome && 'verified' === ( $repository_branch_check_evidence['outcome'] ?? null ) );
$saved_subdirectory_value         = isset( $saved_subdirectory_value ) && is_string( $saved_subdirectory_value )
	? trim( $saved_subdirectory_value )
	: '';
$repository_branch_check_message  = match ( $repository_branch_check_outcome ?? null ) {
	'provider_unavailable' => __( 'The saved provider is unavailable, so Booster could not check the repository and branch.', 'ran-booster' ),
	'unable_to_check'      => __( 'Booster could not access the saved repository and branch. Check the branch name and repository access, then try again.', 'ran-booster' ),
	'subdirectory_unavailable' => __( 'The saved branch is accessible, but Booster could not find the configured subdirectory. Check the path and try again.', 'ran-booster' ),
	'subdirectory_unverified'  => __( 'The saved branch is accessible, but Booster could not check the configured subdirectory. Try again later.', 'ran-booster' ),
	default                => null,
};
$repository_branch_check_notice_class = null !== $repository_branch_check_message ? 'notice-warning' : 'notice-error';
$repository_state_class               = match ( true ) {
	! $identity_ready                         => 'is-warning',
	$repository_branch_verified                => 'is-ok',
	$saved_identity_ready                      => 'is-ok',
	null !== $repository_branch_check_outcome   => 'is-warning',
	default                                  => 'is-pending',
};
if ( in_array( $repository_branch_check_outcome ?? null, array( 'verified', 'subdirectory_unavailable', 'subdirectory_unverified' ), true ) ) {
	$saved_repository_label = __( 'is accessible with the saved repository settings.', 'ran-booster' );
} elseif ( $repository_branch_verified ) {
	$saved_repository_label = __( 'was accessible at the last check.', 'ran-booster' );
} elseif ( 'provider_unavailable' === ( $repository_branch_check_outcome ?? null ) ) {
	$saved_repository_label = __( 'is saved, but the provider is unavailable.', 'ran-booster' );
} elseif ( 'unable_to_check' === ( $repository_branch_check_outcome ?? null ) ) {
	$saved_repository_label = __( 'is saved, but access could not be verified.', 'ran-booster' );
} else {
	$saved_repository_label = __( 'is saved. Access has not been checked.', 'ran-booster' );
}
$saved_repository_message = $identity_ready
	? sprintf(
		/* translators: 1: branch name, 2: repository check status. */
		__( 'The branch <code>%1$s</code> %2$s', 'ran-booster' ),
		esc_html( '' !== $branch_value ? $branch_value : __( 'The provider default branch', 'ran-booster' ) ),
		esc_html( $saved_repository_label )
	)
	: __( 'The saved repository needs one stable provider identity.', 'ran-booster' );
$saved_subdirectory_message = '' === $saved_subdirectory_value
	? __( 'Repository root (no subdirectory).', 'ran-booster' )
	: match ( $repository_branch_check_outcome ?? null ) {
		'verified' => sprintf(
			/* translators: %s: configured repository subdirectory. */
			__( 'The subdirectory <code>%s</code> is accessible at this branch.', 'ran-booster' ),
			esc_html( $saved_subdirectory_value )
		),
		'subdirectory_unavailable' => sprintf(
			/* translators: %s: configured repository subdirectory. */
			__( 'The subdirectory <code>%s</code> was not found at this branch.', 'ran-booster' ),
			esc_html( $saved_subdirectory_value )
		),
		'subdirectory_unverified' => sprintf(
			/* translators: %s: configured repository subdirectory. */
			__( 'The subdirectory <code>%s</code> could not be checked.', 'ran-booster' ),
			esc_html( $saved_subdirectory_value )
		),
		default => sprintf(
			/* translators: %s: configured repository subdirectory. */
			__( 'The subdirectory <code>%s</code> will be checked when Booster prepares the deployment archive.', 'ran-booster' ),
			esc_html( $saved_subdirectory_value )
		),
	};
$subdirectory_state_class = '' === $saved_subdirectory_value
	? 'is-ok'
	: match ( $repository_branch_check_outcome ?? null ) {
		'verified' => 'is-ok',
		'subdirectory_unavailable', 'subdirectory_unverified' => 'is-warning',
		default => 'is-pending',
	};
$automatic_updates_ready = $receiver_ready && $identity_ready && $secret_ready;
$webhook_state_class     = match ( true ) {
	$published_release_source => 'is-pending',
	$automatic_updates_ready  => 'is-ok',
	default                 => 'is-warning',
};
$webhook_message = match ( true ) {
	$published_release_source => __( 'Pushes are ignored while Releases is active.', 'ran-booster' ),
	$automatic_updates_ready  => __( 'Local webhook requirements are ready.', 'ran-booster' ),
	default                 => __( 'Local webhook requirements need attention.', 'ran-booster' ),
};
$webhook_action_label = __( 'Manage webhooks', 'ran-booster' );

?>
<section id="ran-booster-branch-readiness" class="ran-booster-package-source-readiness" aria-labelledby="ran-booster-branch-readiness-heading">
	<div>
		<div class="ran-booster-readiness-panel">
			<div class="ran-booster-readiness-panel__top">
				<div>
					<h4 id="ran-booster-branch-readiness-heading"><?php esc_html_e( 'Branch readiness', 'ran-booster' ); ?></h4>
				</div>
				<?php if ( $needs_attention ) { ?>
					<span class="ran-booster-badge ran-booster-badge--error"><?php esc_html_e( 'Needs attention', 'ran-booster' ); ?></span>
				<?php } ?>
			</div>
			<ul class="ran-booster-readiness-list">
				<li class="ran-booster-readiness-item <?php echo esc_attr( $repository_state_class ); ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Saved repository', 'ran-booster' ); ?></strong>
					<span>
						<?php echo wp_kses_post( $saved_repository_message ); ?>
						<?php if ( $repository_branch_verified && null !== $repository_branch_check_evidence ) { ?>
							<br/><span>
							<?php
							/* translators: %s: UTC timestamp of the last successful repository branch check. */
							echo esc_html( sprintf( __( 'Last checked: %s.', 'ran-booster' ), (string) $repository_branch_check_evidence['checked_at'] ) );
							?>
							</span>
						<?php } ?>
					</span>
				</li>
				<li class="ran-booster-readiness-item <?php echo esc_attr( $subdirectory_state_class ); ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Repository subdirectory', 'ran-booster' ); ?></strong>
					<span><?php echo wp_kses_post( $saved_subdirectory_message ); ?></span>
				</li>
				<?php if ( $retained_readiness ) { ?>
				<li class="ran-booster-readiness-item <?php echo $secret_ready ? 'is-ok' : 'is-warning'; ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Signing secret', 'ran-booster' ); ?></strong>
					<span>
						<?php echo esc_html( $secret_label ); ?>
						<?php if ( 'none' === $secret_coverage && $provider_webhook_available ) { ?>
							<br/><a href="<?php echo esc_url( $provider_settings_url ); ?>"><?php esc_html_e( 'Manage signing secrets', 'ran-booster' ); ?></a>
						<?php } ?>
					</span>
				</li>
				<li class="ran-booster-readiness-item <?php echo $receiver_ready ? 'is-ok' : 'is-warning'; ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Local receiver', 'ran-booster' ); ?></strong>
					<span>
						<?php echo esc_html( $receiver_label ); ?>
						<?php if ( null !== $receiver_action_url && null !== $receiver_action_label ) { ?>
							<br/><a href="<?php echo esc_url( $receiver_action_url ); ?>"><?php echo esc_html( $receiver_action_label ); ?></a>
						<?php } ?>
					</span>
				</li>
				<?php } ?>
				<li class="ran-booster-readiness-item <?php echo esc_attr( $webhook_state_class ); ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Webhook health', 'ran-booster' ); ?></strong>
					<span>
						<?php echo esc_html( $webhook_message ); ?>
						<?php if ( $repository_detail_available ) { ?>
							<br/><a href="<?php echo esc_url( $provider_settings_url ); ?>"><?php echo esc_html( $webhook_action_label ); ?></a>
						<?php } ?>
					</span>
				</li>
			</ul>
			<div class="ran-booster-readiness-actions">
				<div
					id="ran-booster-repository-branch-check-error"
					class="notice <?php echo esc_attr( $repository_branch_check_notice_class ); ?> inline"
					role="alert"
					tabindex="-1"
					<?php if ( null !== $repository_branch_check_message ) { ?>
						data-ran-booster-repository-branch-check
					<?php } else { ?>
						hidden
					<?php } ?>
				><p><?php echo esc_html( $repository_branch_check_message ?? '' ); ?></p></div>
				<?php if ( $is_package_edit ) { ?>
					<button
					type="submit"
					name="ran_booster[check_repository_branch_after_save]"
					value="1"
					form="ran-booster-package-edit-form"
					class="button button-primary ran-booster-branch-readiness-check-form"
					data-ran-booster-enhanced-mutation
					data-ran-booster-error-target="#ran-booster-repository-branch-check-error"
					data-ran-booster-relocate-rendered-error
					hx-post="<?php echo esc_url( wp_make_link_relative( (string) $settings_url ) ); ?>"
					hx-target="#wpbody-content"
					hx-select="#wpbody-content"
					hx-swap="outerHTML show:#ran-booster-branch-readiness:top"
					hx-push-url="<?php echo esc_url( wp_make_link_relative( (string) $check_return_url ) ); ?>"
					hx-sync="this:drop"
					hx-include="#ran-booster-package-edit-form, [form=&quot;ran-booster-package-edit-form&quot;]"
					<?php disabled( isset( $package_mutation_available ) && false === $package_mutation_available ); ?>
					><?php esc_html_e( 'Save settings and check', 'ran-booster' ); ?></button>
				<?php } ?>
				<?php if ( $repository_detail_available ) { ?>
					<a class="button" href="<?php echo esc_url( $provider_settings_url ); ?>"><?php echo esc_html( $webhook_action_label ); ?></a>
				<?php } else { ?>
					<button type="button" class="button" disabled aria-disabled="true"><?php echo esc_html( $webhook_action_label ); ?></button>
				<?php } ?>
			</div>
		</div>
	</div>
</section>
