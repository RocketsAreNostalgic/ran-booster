<?php

declare(strict_types=1);

namespace RAN\Admin;

/** Administrator-facing copy for the closed deployment outcome set. */
final class DeploymentOutcomeMessage {

	public static function forCode( string $code ): string {
		return match ( $code ) {
			'deployed'                       => __( 'WordPress completed and Booster verified the package deployment.', 'ran-booster' ),
			'no_change'                      => __( 'The requested package bytes were already installed.', 'ran-booster' ),
			'provider_failed'                => __( 'The repository provider could not prepare this deployment; no more specific reason was recorded.', 'ran-booster' ),
			'provider_request_invalid'       => __( 'The repository or credential configuration is invalid. Review the managed package settings.', 'ran-booster' ),
			'provider_credential_rejected'   => __( 'The repository provider rejected the selected credential. Replace or update it.', 'ran-booster' ),
			'provider_access_denied'         => __( 'The repository provider denied access. Check the credential permissions and repository access.', 'ran-booster' ),
			'provider_repository_missing'    => __( 'The repository provider could not find the repository or revision. Check that it exists and that the selected credential can access it.', 'ran-booster' ),
			'provider_reference_unavailable' => __( 'The requested repository revision is no longer available. Review the configured branch or reference.', 'ran-booster' ),
			'provider_rate_limited'          => __( 'The repository provider rate limit was reached. Wait for its quota to reset or use an authenticated credential.', 'ran-booster' ),
			'provider_unavailable'           => __( 'The repository provider could not be reached or returned an invalid response. Try again later.', 'ran-booster' ),
			'archive_compressed_too_large'   => __( 'The repository ZIP exceeds this site\'s configured archive download limit. Reduce the repository size or raise RAN_BOOSTER_MAX_ARCHIVE_BYTES in wp-config.php.', 'ran-booster' ),
			'archive_expanded_too_large'     => __( 'The repository ZIP expands beyond this site\'s configured archive safety limit. Reduce the repository contents or raise RAN_BOOSTER_MAX_ARCHIVE_BYTES in wp-config.php.', 'ran-booster' ),
			'archive_limit_invalid'          => __( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES is invalid. Set it in wp-config.php to an integer between 1 MiB and 512 MiB.', 'ran-booster' ),
			'preflight_failed'               => __( 'The package did not pass Booster\'s safe deployment checks.', 'ran-booster' ),
			'downgrade_blocked'              => __( 'Booster blocked an older branch package before changing files. Restore a full-site backup or use a publisher-provided down-migration for intentional recovery.', 'ran-booster' ),
			'lock_unavailable'               => __( 'Another update is currently using the WordPress package lock.', 'ran-booster' ),
			'policy_blocked'                 => __( 'WordPress or the package policy blocked this deployment.', 'ran-booster' ),
			'stale_event'                    => __( 'The requested repository reference is no longer current.', 'ran-booster' ),
			'upgrader_failed'                => __( 'WordPress could not complete the package update; the earlier package remains intact.', 'ran-booster' ),
			'activation_failed'              => __( 'WordPress could not restore the plugin activation state.', 'ran-booster' ),
			'worker_stopped'                 => __( 'The deployment stopped before WordPress changed the package.', 'ran-booster' ),
			'interrupted'                    => __( 'The deployment crossed the package mutation boundary and needs inspection.', 'ran-booster' ),
			'restoration_uncertain'          => __( 'WordPress completed the update, but Booster could not prove the final package state. Open the activity record for the available recovery evidence.', 'ran-booster' ),
			'maintenance_remaining'          => __( 'WordPress left maintenance mode active after the deployment. Inspect the package and maintenance state before trying again.', 'ran-booster' ),
			'installed_version_mismatch'     => __( 'WordPress completed the deployment, but the installed package version does not match the verified archive. Inspect it before trying again.', 'ran-booster' ),
			'activation_state_changed'       => __( 'WordPress completed the deployment, but the package activation state changed. Inspect it before trying again.', 'ran-booster' ),
			'persistence_uncertain'          => __( 'The package changed, but Booster could not verify its management record.', 'ran-booster' ),
			default                          => __( 'Booster recorded an unavailable deployment outcome.', 'ran-booster' ),
		};
	}
}
