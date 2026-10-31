<?php

declare(strict_types=1);

namespace RAN\Admin;

/** Administrator-facing copy for the closed deployment outcome set. */
final class DeploymentOutcomeMessage {

	public static function for_activity( string $status, string $reason, string $message = '' ): string {
		if ( 'incomplete' === $status ) {
			return __( 'Completion not recorded', 'ran-booster' );
		}
		if ( '' !== $message ) {
			return $message;
		}
		return match ( $reason ) {
			'diagnostics.partial' => __( 'Diagnostics returned a partial report.', 'ran-booster' ),
			'diagnostics.passed' => __( 'Diagnostics passed.', 'ran-booster' ),
			'diagnostics.failed' => __( 'Diagnostics reported failures.', 'ran-booster' ),
			'diagnostics.warning' => __( 'Diagnostics reported warnings.', 'ran-booster' ),
			'diagnostics.not_configured' => __( 'Diagnostics found missing configuration.', 'ran-booster' ),
			'webhook.operation_succeeded' => __( 'Webhook operation completed.', 'ran-booster' ),
			'webhook.operation_failed' => __( 'Webhook operation failed.', 'ran-booster' ),
			'webhook.remote_unconfirmed' => __( 'The remote webhook outcome is unconfirmed.', 'ran-booster' ),
			'webhook.local_persistence_conflict', 'webhook.local_persistence_unconfirmed' => __( 'Remote webhook work completed; local persistence is unconfirmed.', 'ran-booster' ),
			'subdirectory_unavailable' => __( 'The configured package subdirectory could not be found or inspected in the repository archive.', 'ran-booster' ),
			'subdirectory_unverified' => __( 'The configured repository subdirectory could not be verified.', 'ran-booster' ),
			'unable_to_check' => __( 'The branch check could not complete. Review the package settings and try again.', 'ran-booster' ),
			'provider_unavailable' => self::for_code( 'provider_unavailable' ),
			'verified' => __( 'Repository branch and archive verified.', 'ran-booster' ),
			'update_available' => __( 'A newer release is available.', 'ran-booster' ),
			'no_candidates' => __( 'No release candidates were found.', 'ran-booster' ),
			'no_newer_candidate' => __( 'No newer release was found.', 'ran-booster' ),
			'candidate_rejected', 'candidate_not_eligible' => __( 'The release candidate is not eligible for installation.', 'ran-booster' ),
			'policy_disabled', 'policy_forced_off' => __( 'Release checks are disabled by update policy.', 'ran-booster' ),
			'discovery_not_admitted' => __( 'Release discovery could not start.', 'ran-booster' ),
			'release_list_failed' => __( 'The available releases could not be retrieved.', 'ran-booster' ),
			'runtime_identity_invalid' => __( 'The installed package identity could not be verified.', 'ran-booster' ),
			'filesystem_unavailable' => __( 'The package filesystem is unavailable.', 'ran-booster' ),
			'offer_token_unavailable' => __( 'The release offer could not be recorded.', 'ran-booster' ),
			'candidate_invalid', 'candidate_inspection_failed', 'candidate_descriptor_mismatch', 'candidate_validation_failed' => __( 'The release candidate could not be verified.', 'ran-booster' ),
			default => match ( $status ) {
				'succeeded' => __( 'Check completed.', 'ran-booster' ),
				'skipped' => __( 'Check skipped.', 'ran-booster' ),
				'rejected' => __( 'Check rejected.', 'ran-booster' ),
				default => __( 'Check could not complete.', 'ran-booster' ),
			},
		};
	}

	/** @return array<string, string> Closed, display-safe observation details. */
	public static function activity_facts( mixed $encoded ): array {
		$facts = is_string( $encoded ) && strlen( $encoded ) <= 4096 ? json_decode( $encoded, true ) : null;
		if ( ! is_array( $facts ) ) {
			return array();
		}
		$details = array();
		foreach ( array(
			'diagnostic_passed'         => array( __( 'Diagnostics passed', 'ran-booster' ), 'count' ),
			'diagnostic_warning'        => array( __( 'Diagnostic warnings', 'ran-booster' ), 'count' ),
			'diagnostic_failed'         => array( __( 'Diagnostics failed', 'ran-booster' ), 'count' ),
			'diagnostic_not_configured' => array( __( 'Diagnostics not configured', 'ran-booster' ), 'count' ),
			'expiry_state'              => array( __( 'Credential expiry', 'ran-booster' ), array( 'not_reported', 'unknown', 'known' ) ),
			'expires_at'                => array( __( 'Credential expires (UTC)', 'ran-booster' ), 'date' ),
			'webhook_state'             => array( __( 'Webhook operation', 'ran-booster' ), array( 'succeeded', 'partial', 'ambiguous', 'failed' ) ),
			'webhook_delivery'          => array( __( 'Webhook delivery', 'ran-booster' ), array( 'configured_pending_delivery', 'verified', 'unverified', 'unknown', 'absent' ) ),
			'webhook_persistence'       => array( __( 'Webhook local record', 'ran-booster' ), array( 'partial', 'unconfirmed', 'confirmed', 'not_confirmed' ) ),
			'webhook_local_code'        => array( __( 'Webhook local result code', 'ran-booster' ), 'code' ),
			'partial_reason'            => array( __( 'Diagnostic partial-result reason', 'ran-booster' ), array( 'local_incomplete', 'deadline_exhausted', 'remote_calls_exhausted', 'provider_unavailable', 'provider_results_invalid', 'result_limit_exhausted' ) ),
		) as $key => [ $label, $allowed ] ) {
			$value = $facts[ $key ] ?? null;
			$date  = 'date' === $allowed && is_string( $value ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value ) : false;
			if ( ( 'count' === $allowed && is_int( $value ) && $value >= 0 && $value <= 8 )
				|| ( 'date' === $allowed && is_string( $value ) && $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value && 'known' === ( $facts['expiry_state'] ?? null ) )
				|| ( 'code' === $allowed && is_string( $value ) && 1 === preg_match( '/\A[a-z0-9][a-z0-9_.-]{0,95}\z/', $value ) )
				|| ( is_array( $allowed ) && is_string( $value ) && in_array( $value, $allowed, true ) ) ) {
				$details[ $label ] = match ( $value ) {
					'unknown' => __( 'Unknown', 'ran-booster' ), 'known' => __( 'Known', 'ran-booster' ), 'not_reported' => __( 'Not reported', 'ran-booster' ),
					'succeeded' => __( 'Completed', 'ran-booster' ), 'failed' => __( 'Failed', 'ran-booster' ), 'partial' => __( 'Partially completed', 'ran-booster' ), 'ambiguous' => __( 'Outcome uncertain', 'ran-booster' ),
					'configured_pending_delivery' => __( 'Configured; delivery not yet verified', 'ran-booster' ), 'verified' => __( 'Verified', 'ran-booster' ), 'unverified' => __( 'Not verified', 'ran-booster' ), 'absent' => __( 'Absent', 'ran-booster' ),
					'confirmed' => __( 'Confirmed', 'ran-booster' ), 'unconfirmed', 'not_confirmed' => __( 'Not confirmed', 'ran-booster' ),
					default => (string) $value,
				};
			}
		}
		return $details;
	}

	public static function for_code( string $code ): string {
		return match ( $code ) {
			'deployed'                       => __( 'WordPress completed and Booster verified the package deployment.', 'ran-booster' ),
			'no_change'                      => __( 'The requested package bytes were already installed.', 'ran-booster' ),
			'already_managed'                => __( 'Booster already manages the installed package. Its existing package settings were left unchanged.', 'ran-booster' ),
			'provider_failed'                => __( 'The Git provider could not prepare the deployment, but no exact cause was recorded. Check the repository and credential settings, then retry once; enable Troubleshooting logging and submit a redacted report if it repeats.', 'ran-booster' ),
			'provider_request_invalid'       => __( 'The repository or credential configuration is invalid. Review the managed package settings.', 'ran-booster' ),
			'provider_credential_rejected'   => __( 'The Git provider rejected the selected credential. Replace or update it.', 'ran-booster' ),
			'provider_access_denied'         => __( 'The Git provider denied access. Check the credential permissions and repository access.', 'ran-booster' ),
			'provider_repository_missing'    => __( 'The Git provider could not find the repository or revision. Check that it exists and that the selected credential can access it.', 'ran-booster' ),
			'provider_reference_unavailable' => __( 'The requested repository revision is no longer available. Review the configured branch or reference.', 'ran-booster' ),
			'provider_rate_limited'          => __( 'The Git provider rate limit was reached. Wait for its quota to reset or use an authenticated credential.', 'ran-booster' ),
			'provider_selection_changed'     => __( 'The GitHub implementation changed after this deployment was requested. Review the current Git provider and submit a new deployment.', 'ran-booster' ),
			'provider_unavailable'           => __( 'The Git provider could not be reached or returned an invalid response. Try again later.', 'ran-booster' ),
			'archive_compressed_too_large'   => __( 'The repository ZIP exceeds this site\'s configured archive download limit. Reduce the repository size or review Archive limits in Booster Settings; configuration overrides may lock the value.', 'ran-booster' ),
			'archive_expanded_too_large'     => __( 'The repository ZIP expands beyond this site\'s configured archive safety limit. Reduce the repository contents or review Archive limits in Booster Settings; stricter extraction safeguards still apply.', 'ran-booster' ),
			'archive_limit_invalid'          => __( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES is invalid. Set the configured constant to an integer between 1 MiB and 536,870,911 bytes (just under 512 MiB).', 'ran-booster' ),
			'preflight_failed'               => __( 'Booster stopped the package before changing files, but no exact failed check was recorded. Check the package and settings, then retry once; enable Troubleshooting logging and submit a redacted report if it repeats.', 'ran-booster' ),
			'downgrade_blocked'              => __( 'Booster blocked an older branch package before changing files. Restore a full-site backup or use a publisher-provided down-migration for intentional recovery.', 'ran-booster' ),
			'lock_unavailable'               => __( 'Another deployment is using the WordPress package lock. Wait for it to finish, check Activity for a pending recovery, then retry.', 'ran-booster' ),
			'policy_blocked'                 => __( 'A package update policy blocked this deployment. Review the package Updates settings and WordPress file modification configuration, then retry.', 'ran-booster' ),
			'repository_source_conflict'     => __( 'This repository conflicts with another managed package source. Review the repository package settings before retrying.', 'ran-booster' ),
			'repository_source_unavailable'  => __( 'Booster could not safely read the repository source relationship. Check package storage and retry.', 'ran-booster' ),
			'stale_event'                    => __( 'The requested repository reference is no longer current. Refresh the package source or start a new deployment from the current reference.', 'ran-booster' ),
			'upgrader_failed'                => __( 'WordPress could not complete the package update; the earlier package should remain intact. Check Activity and WordPress update health, then retry.', 'ran-booster' ),
			'activation_failed'              => __( 'WordPress could not restore the plugin activation state. Check the plugin\'s current activation state and its error log, then retry.', 'ran-booster' ),
			'worker_stopped'                 => __( 'The deployment stopped before WordPress changed the package. Check Activity for a pending recovery, then retry.', 'ran-booster' ),
			'interrupted'                    => __( 'The deployment may have changed package files before it stopped. Inspect the package and Activity recovery evidence before allowing another deployment.', 'ran-booster' ),
			'restoration_uncertain'          => __( 'WordPress completed the update, but Booster could not prove the final package state. Inspect the package and Activity recovery evidence before allowing another deployment.', 'ran-booster' ),
			'maintenance_remaining'          => __( 'WordPress left maintenance mode active after the deployment. Inspect the package and maintenance state before trying again.', 'ran-booster' ),
			'installed_version_mismatch'     => __( 'WordPress completed the deployment, but the installed package version does not match the verified archive. Inspect the installed package header and Activity before retrying.', 'ran-booster' ),
			'activation_state_changed'       => __( 'WordPress completed the deployment, but the package activation state changed. Check its current activation state before retrying.', 'ran-booster' ),
			'persistence_uncertain'          => __( 'The package changed, but Booster could not verify its management record. Review the deployment activity and any existing package settings before retrying.', 'ran-booster' ),
			'package_version_missing'        => __( 'The package header has no Version value. Add a value such as Version: 0.1.0 to the main plugin header or theme style.css, rebuild the archive, and retry.', 'ran-booster' ),
			'package_version_invalid'        => __( 'The package Version value is invalid. Set a valid value such as Version: 0.1.0 in the main plugin header or theme style.css, rebuild the archive, and retry.', 'ran-booster' ),
			'package_header_unreadable'      => __( 'Booster could not safely read the package header. Check that the main plugin header or theme style.css is readable text, rebuild the archive, and retry.', 'ran-booster' ),
			'package_header_missing'         => __( 'The expected package header was not found. Add the main plugin header or theme style.css, rebuild the archive, and retry.', 'ran-booster' ),
			'package_compatibility_invalid'  => __( 'The package compatibility header is invalid. Correct its Requires PHP or Requires at least value, rebuild the archive, and retry.', 'ran-booster' ),
			'package_requires_newer_php'     => __( 'The package requires a newer PHP version than this site uses. Update the site PHP version or use a compatible package, then retry.', 'ran-booster' ),
			'package_requires_newer_wordpress' => __( 'The package requires a newer WordPress version than this site uses. Update WordPress or use a compatible package, then retry.', 'ran-booster' ),
			'package_subdirectory_missing'   => __( 'The configured package subdirectory is missing from the archive. Correct the package source or subdirectory setting, rebuild the archive, and retry.', 'ran-booster' ),
			'package_plugin_missing'         => __( 'No plugin package was found in the archive. Include the intended main plugin file, rebuild the archive, and retry.', 'ran-booster' ),
			'package_theme_missing'          => __( 'No theme package was found in the archive. Include the intended theme style.css, rebuild the archive, and retry.', 'ran-booster' ),
			'package_multiple_plugins'       => __( 'The selected package directory contains more than one main plugin header. Leave exactly one main plugin header in that directory, rebuild the archive, and retry.', 'ran-booster' ),
			'package_identity_mismatch'      => __( 'The archive package does not match the managed package identity. Check the selected repository and package settings, then retry.', 'ran-booster' ),
			'package_single_file_unsupported' => __( 'The installed plugin is a root-level single file, which Booster cannot update safely. Install or manage a directory-based plugin package instead.', 'ran-booster' ),
			'deployment_zip_extension_missing' => __( 'Booster cannot inspect deployment archives because the PHP ZIP extension is unavailable. Enable the ZIP extension on this site, then retry.', 'ran-booster' ),
			'deployment_multisite_unsupported' => __( 'Archive deployment is supported only on a single-site WordPress installation. Use a supported single-site installation for this deployment.', 'ran-booster' ),
			'deployment_file_mods_disabled'  => __( 'WordPress file modifications are disabled. Allow WordPress file modifications in the site configuration, then retry.', 'ran-booster' ),
			'deployment_filesystem_unsupported' => __( 'Booster requires the direct WordPress filesystem method. Configure the direct filesystem method, then retry.', 'ran-booster' ),
			'deployment_directory_unwritable' => __( 'WordPress cannot write to a deployment temporary, upgrade, or destination directory. Correct the directory ownership or permissions, then retry.', 'ran-booster' ),
			'deployment_disk_space_low'      => __( 'The site does not have enough disk space for the deployment. Free sufficient space safely, then retry.', 'ran-booster' ),
			'archive_temporary_file_failed'  => __( 'WordPress could not create a temporary archive file. Check the server temporary directory and available disk space, then retry.', 'ran-booster' ),
			'archive_integrity_failed'       => __( 'The downloaded ZIP could not pass its integrity check. Check the download and local temporary-file storage, then retry.', 'ran-booster' ),
			'archive_download_failed'        => __( 'Booster could not download the package archive. Check the repository source, local temporary-file storage permissions, available disk space, and server network access, then retry.', 'ran-booster' ),
			'archive_url_invalid'            => __( 'The package archive URL is invalid. Correct the release source or repository configuration, then retry.', 'ran-booster' ),
			'archive_revision_invalid'       => __( 'The requested archive revision is invalid. Choose an available repository revision, then retry.', 'ran-booster' ),
			'archive_zip_invalid'            => __( 'The downloaded package is not a valid ZIP archive. Publish a valid ZIP archive, then retry.', 'ran-booster' ),
			'archive_layout_invalid'         => __( 'The package archive layout is not supported. Publish a package with the expected plugin or theme layout, then retry.', 'ran-booster' ),
			'archive_path_unsafe'            => __( 'The package archive contains an unsafe path, such as ../, an absolute path, or a symlink. Rebuild the archive without unsafe paths, then retry.', 'ran-booster' ),
			'archive_path_collision'         => __( 'The package archive contains conflicting paths. Rebuild the archive with unique paths, then retry.', 'ran-booster' ),
			'archive_entry_invalid'          => __( 'The package archive contains invalid ZIP entry metadata. Rebuild the ZIP with valid file and directory entries, then retry.', 'ran-booster' ),
			'archive_entry_limit'            => __( 'The package archive contains too many entries. Reduce the archive contents, rebuild it, and retry.', 'ran-booster' ),
			'archive_encrypted'              => __( 'The package archive is encrypted and cannot be installed. Publish an unencrypted ZIP archive, then retry.', 'ran-booster' ),
			'archive_entry_unsupported'      => __( 'The package archive contains a link or device entry. Rebuild the archive with only regular files and directories, then retry.', 'ran-booster' ),
			'archive_cleanup_failed'         => __( 'Booster could not safely clean up a temporary archive or Git provider authentication. Check server temporary-file access and the Git provider integration, then retry.', 'ran-booster' ),
			'deployment_snapshot_changed'    => __( 'The package changed while Booster prepared the deployment. Review the current package source, then start a new deployment.', 'ran-booster' ),
			'deployment_destination_exists'  => __( 'The package destination already exists and cannot be replaced safely. Link the installed plugin or theme in Booster if it is correct; otherwise inspect it and Activity before retrying.', 'ran-booster' ),
			'deployment_self_update_blocked' => __( 'Booster cannot update itself during this deployment. Update Booster separately before retrying the managed package.', 'ran-booster' ),
			'deployment_release_source_blocked' => __( 'Branch deployment is unavailable for this release-managed package. Use Published releases or WordPress Updates for the release source, or explicitly switch the package source back to Branch.', 'ran-booster' ),
			'deployment_maintenance_active'  => __( 'WordPress is already in maintenance mode. Wait for the current update to finish, inspect Activity, then retry.', 'ran-booster' ),
			default                          => __( 'Booster recorded an unavailable deployment outcome. Open Troubleshooting, verify the package state before retrying, and submit a redacted report if it repeats.', 'ran-booster' ),
		};
	}
}
