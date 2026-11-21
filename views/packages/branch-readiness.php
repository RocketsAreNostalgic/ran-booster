<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var string $branch_value
 * @var string $deployment_policy
 * @var string $identifier_value
 * @var array<string, mixed>|null $package_branch_readiness
 * @var \RAN\Admin\PackagePagePresenter $package_view
 * @var string $provider_code
 * @var bool $provider_webhook_available
 */

defined( 'WPINC' ) || die;

$is_package_edit                 = true === ( $is_package_edit ?? false );
$provider_base_url               = \RAN\Admin\WebhookManagement\WebhookManagementAdminUrl::for_path( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider_code ) );
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
// An unregistered provider has no admin tab to link to.
$repository_detail_available = '' !== $repository_id && $identity_ready && empty( $provider_unavailable );
$secret_coverage             = (string) ( $repository_readiness['local_secret_coverage'] ?? 'unknown' );
$secret_ready                = in_array( $secret_coverage, array( 'repository', 'shared' ), true );
$published_release_source    = true === ( $release_managed ?? false )
	|| 'release_asset' === ( $package_current_source ?? null )
	|| 'release_asset' === ( $package_source_view ?? null );
$retained_readiness          = true === ( $package_branch_readiness['retained'] ?? false );
// Labels are escaped HTML; only the signing-secret label carries a link, to the repository's webhook setup.
$secret_url   = true === ( $provider_webhook_available ?? false ) ? $provider_settings_url : null;
$secret_label = match ( $secret_coverage ) {
	'repository' => esc_html__( 'A repository-specific signing secret is saved.', 'ran-booster' ),
	'shared' => esc_html__( 'A shared owner signing secret covers this repository.', 'ran-booster' ),
	/* translators: 1: opening link tag to the repository's webhook setup; 2: closing link tag. */
	'none' => \RAN\Admin\WebhookManagement\Display\WebhookDisplayModel::link_sentence( __( 'No matching local %1$ssigning secret%2$s is saved.', 'ran-booster' ), $secret_url, true ),
	/* translators: 1: opening link tag to the repository's webhook setup; 2: closing link tag. */
	default => \RAN\Admin\WebhookManagement\Display\WebhookDisplayModel::link_sentence( __( 'Local %1$ssigning-secret%2$s status is unavailable.', 'ran-booster' ), $secret_url, true ),
};
// The webhook screens' primary site reason; an unknown cause has no destination yet.
$receiver_reason = \RAN\Admin\WebhookManagement\Display\WebhookDisplayModel::primary_site_reason( $site_reasons );
$receiver_label  = match ( true ) {
	$receiver_ready           => esc_html__( 'The site exposes a structurally valid HTTPS webhook endpoint.', 'ran-booster' ),
	null !== $receiver_reason => (string) \RAN\Admin\WebhookManagement\Display\WebhookDisplayModel::site_reason_message( $receiver_reason, true ),
	default                   => esc_html__( 'Webhook setup is unavailable until Booster can confirm this managed repository.', 'ran-booster' ),
};
$needs_attention                  = ! $published_release_source
	&& \RAN\Deployment\DeploymentPolicy::AUTOMATIC->value === $deployment_policy
	&& ( ! $receiver_ready || ! $identity_ready || ! $secret_ready );
$repository_branch_check_evidence = is_array( $repository_branch_check_evidence ?? null )
	? $repository_branch_check_evidence
	: null;
$repository_branch_verified       = in_array( $repository_branch_check_outcome ?? null, array( 'verified', 'subdirectory_deferred', 'subdirectory_unavailable', 'subdirectory_unverified' ), true )
	|| ( null === $repository_branch_check_outcome && 'verified' === ( $repository_branch_check_evidence['outcome'] ?? null ) );
$saved_subdirectory_value         = isset( $saved_subdirectory_value ) && is_string( $saved_subdirectory_value )
	? trim( $saved_subdirectory_value )
	: '';
$branch_check_sentences           = \RAN\Admin\PackageAdminController::repository_branch_check_sentences( $repository_branch_check_outcome ?? null );
$repository_branch_check_message  = null !== $branch_check_sentences ? implode( ' ', $branch_check_sentences ) : null;

$repository_branch_check_intent = null !== $repository_branch_check_message ? 'warning' : 'error';
$repository_state_class         = match ( true ) {
	! $identity_ready                         => 'is-warning',
	$repository_branch_verified                => 'is-ok',
	$saved_identity_ready && ! in_array( $repository_branch_check_outcome, array( 'branch_unavailable', 'branch_redirected' ), true ) => 'is-ok',
	null !== $repository_branch_check_outcome   => 'is-warning',
	default                                  => 'is-pending',
};
if ( in_array( $repository_branch_check_outcome ?? null, array( 'verified', 'subdirectory_deferred', 'subdirectory_unavailable', 'subdirectory_unverified' ), true ) ) {
	$saved_repository_label = __( 'is accessible with the saved repository settings.', 'ran-booster' );
} elseif ( $repository_branch_verified ) {
	$saved_repository_label = __( 'was accessible at the last check.', 'ran-booster' );
} elseif ( 'provider_unavailable' === ( $repository_branch_check_outcome ?? null ) ) {
	$saved_repository_label = __( 'is saved, but the Git provider is unavailable.', 'ran-booster' );
} elseif ( in_array( $repository_branch_check_outcome ?? null, array( 'unable_to_check', 'branch_unavailable', 'branch_redirected' ), true ) ) {
	$saved_repository_label = __( 'is saved, but access could not be verified.', 'ran-booster' );
} else {
	$saved_repository_label = __( 'is saved. Access has not been checked.', 'ran-booster' );
}
$saved_repository_message = $identity_ready
	? sprintf(
		/* translators: 1: branch name, 2: repository check status. */
		__( 'The branch <code>%1$s</code> %2$s', 'ran-booster' ),
		esc_html( '' !== $branch_value ? $branch_value : __( 'The repository default branch', 'ran-booster' ) ),
		esc_html( $saved_repository_label )
	)
	: __( 'The saved repository needs one stable Git provider identity.', 'ran-booster' );
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
// Escaped HTML; the attention state links to the repository's webhook setup, which the Manage webhooks button also opens.
$webhook_message = match ( true ) {
	$published_release_source => esc_html__( 'Pushes are ignored while Releases is active.', 'ran-booster' ),
	$automatic_updates_ready  => esc_html__( 'Local webhook requirements are ready.', 'ran-booster' ),
	/* translators: 1: opening link tag to the repository's webhook setup; 2: closing link tag. */
	default                 => \RAN\Admin\WebhookManagement\Display\WebhookDisplayModel::link_sentence( __( 'Local %1$swebhook requirements%2$s need attention.', 'ran-booster' ), $repository_detail_available ? $provider_settings_url : null, true ),
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
					<?php $needs_attention_label = __( 'Needs attention', 'ran-booster' ); ?>
					<?php echo \RAN\Admin\Component\StatusBadge::for_intent( (string) $needs_attention_label, 'high' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StatusBadge escapes every value. ?>
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
						<?php echo wp_kses_post( $secret_label ); ?>
					</span>
				</li>
				<li class="ran-booster-readiness-item <?php echo $receiver_ready ? 'is-ok' : 'is-warning'; ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Local receiver', 'ran-booster' ); ?></strong>
					<span>
						<?php echo wp_kses_post( $receiver_label ); ?>
					</span>
				</li>
				<?php } ?>
				<li class="ran-booster-readiness-item <?php echo esc_attr( $webhook_state_class ); ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Webhook health', 'ran-booster' ); ?></strong>
					<span>
						<?php echo wp_kses_post( $webhook_message ); ?>
					</span>
				</li>
			</ul>
			<div class="ran-booster-readiness-actions">
				<div
					id="ran-booster-repository-branch-check-error"
					class="notice notice-<?php echo esc_attr( $repository_branch_check_intent ); ?> inline ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( $repository_branch_check_intent ); ?>"
					data-ran-booster-ui-notice
					data-ran-booster-ui-notice-intent="<?php echo esc_attr( $repository_branch_check_intent ); ?>"
					role="alert"
					tabindex="-1"
					<?php if ( null !== $repository_branch_check_message ) { ?>
						data-ran-booster-repository-branch-check
					<?php } else { ?>
						hidden
					<?php } ?>
				><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
						<p data-ran-booster-ui-notice-primary><?php echo esc_html( $repository_branch_check_message ?? '' ); ?></p>
					</div>
					<?php \RAN\Admin\Component\NoticeIcon::print_intent( $repository_branch_check_intent ); ?>
				</div>
				<?php if ( $is_package_edit && is_array( $repository_branch_check_args ?? null ) ) { ?>
					<form method="get" action="<?php echo esc_url( $package_view->get_admin_url() . '#ran-booster-branch-readiness' ); ?>" class="ran-booster-branch-readiness-check-form" data-ran-booster-submit-busy>
						<?php
						foreach ( array(
							'page'        => $package_view->get_page_slug(),
							'package'     => $identifier_value,
							'source_view' => 'branch',
						) + $repository_branch_check_args + ( $package_return_args ?? array() ) as $check_field => $check_value ) {
							?>
							<input type="hidden" name="<?php echo esc_attr( (string) $check_field ); ?>" value="<?php echo esc_attr( (string) $check_value ); ?>">
						<?php } ?>
						<button
						type="submit"
						class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>"
						data-ran-booster-busy-label="<?php echo esc_attr( __( 'Checking…', 'ran-booster' ) ); ?>"
						data-ran-booster-busy-message="<?php echo esc_attr( __( 'Checking the branch. This can take a few seconds.', 'ran-booster' ) ); ?>"
						><?php esc_html_e( 'Check branch', 'ran-booster' ); ?></button>
						<span class="screen-reader-text" role="status" aria-live="polite" data-ran-booster-submit-busy-status></span>
					</form>
				<?php } ?>
				<?php if ( $repository_detail_available ) { ?>
					<a class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary( false, true ) ); ?>" href="<?php echo esc_url( $provider_settings_url ); ?>"><?php echo esc_html( $webhook_action_label ); ?></a>
				<?php } else { ?>
					<button type="button" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>" disabled aria-disabled="true"><?php echo esc_html( $webhook_action_label ); ?></button>
				<?php } ?>
			</div>
		</div>
	</div>
</section>
