<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\DeploymentOutcomeMessage;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentState;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class DeploymentOutcomeMessageTest extends TestCase {

	public function test_rate_limit_message_is_actionable_and_contains_no_provider_evidence(): void {
		$message = DeploymentOutcomeMessage::for_code( 'provider_rate_limited' );

		self::assertStringContainsString( 'rate limit', $message );
		self::assertStringContainsString( 'quota to reset', $message );
		self::assertStringNotContainsString( 'Authorization', $message );
	}

	public function test_unknown_outcome_uses_safe_fallback_copy(): void {
		self::assertSame(
			'Booster recorded an unavailable deployment outcome. Open Troubleshooting, verify the package state before retrying, and submit a redacted report if it repeats.',
			DeploymentOutcomeMessage::for_code( 'provider said Authorization: Bearer secret-canary' )
		);
	}

	public function test_every_failed_outcome_has_independent_actionable_remediation(): void {
		$expected_remediation = array(
			'provider_failed'                   => 'Check the repository and credential settings',
			'provider_request_invalid'          => 'Review the managed package settings',
			'provider_credential_rejected'      => 'Replace or update it',
			'provider_access_denied'            => 'Check the credential permissions',
			'provider_repository_missing'       => 'Check that it exists',
			'provider_reference_unavailable'    => 'Review the configured branch or reference',
			'provider_rate_limited'             => 'Wait for its quota to reset',
			'provider_unavailable'              => 'Try again later',
			'archive_compressed_too_large'      => 'Reduce the repository size',
			'archive_expanded_too_large'        => 'Reduce the repository contents',
			'archive_limit_invalid'             => 'Set it in wp-config.php',
			'preflight_failed'                  => 'enable Troubleshooting logging',
			'downgrade_blocked'                 => 'Restore a full-site backup',
			'lock_unavailable'                  => 'Wait for it to finish',
			'policy_blocked'                    => 'Review the package Updates settings',
			'repository_source_conflict'        => 'Review the repository package settings',
			'repository_source_unavailable'     => 'Check package storage',
			'stale_event'                       => 'Refresh the package source',
			'upgrader_failed'                   => 'Check Activity',
			'activation_failed'                 => 'Check the plugin\'s current activation state',
			'worker_stopped'                    => 'Check Activity',
			'interrupted'                       => 'Inspect the package',
			'restoration_uncertain'             => 'Inspect the package',
			'maintenance_remaining'             => 'Inspect the package',
			'installed_version_mismatch'        => 'Inspect the installed package header',
			'activation_state_changed'          => 'Check its current activation state',
			'persistence_uncertain'             => 'Review the deployment activity',
			'package_version_missing'           => 'Add a value such as Version: 0.1.0',
			'package_version_invalid'           => 'Set a valid value such as Version: 0.1.0',
			'package_header_unreadable'         => 'Check that the main plugin header',
			'package_header_missing'            => 'Add the main plugin header',
			'package_compatibility_invalid'     => 'Correct its Requires PHP or Requires at least value',
			'package_requires_newer_php'        => 'Update the site PHP version',
			'package_requires_newer_wordpress'  => 'Update WordPress',
			'package_subdirectory_missing'      => 'Correct the package source or subdirectory setting',
			'package_plugin_missing'            => 'Include the intended main plugin file',
			'package_theme_missing'             => 'Include the intended theme style.css',
			'package_multiple_plugins'          => 'Leave exactly one main plugin header',
			'package_identity_mismatch'         => 'Check the selected repository and package settings',
			'package_single_file_unsupported'   => 'root-level single file',
			'deployment_zip_extension_missing'  => 'Enable the ZIP extension',
			'deployment_multisite_unsupported'  => 'single-site WordPress installation',
			'deployment_file_mods_disabled'     => 'Allow WordPress file modifications',
			'deployment_filesystem_unsupported' => 'direct WordPress filesystem method',
			'deployment_directory_unwritable'   => 'temporary, upgrade, or destination directory',
			'deployment_disk_space_low'         => 'Free sufficient space safely',
			'archive_temporary_file_failed'     => 'Check the server temporary directory',
			'archive_integrity_failed'          => 'download and local temporary-file storage',
			'archive_download_failed'           => 'local temporary-file storage',
			'archive_url_invalid'               => 'Correct the release source or repository configuration',
			'archive_revision_invalid'          => 'Choose an available repository revision',
			'archive_zip_invalid'               => 'Publish a valid ZIP archive',
			'archive_layout_invalid'            => 'Publish a package with the expected plugin or theme layout',
			'archive_path_unsafe'               => 'Rebuild the archive without unsafe paths',
			'archive_path_collision'            => 'Rebuild the archive with unique paths',
			'archive_entry_invalid'             => 'valid file and directory entries',
			'archive_entry_limit'               => 'Reduce the archive contents',
			'archive_encrypted'                 => 'Publish an unencrypted ZIP archive',
			'archive_entry_unsupported'         => 'only regular files and directories',
			'archive_cleanup_failed'            => 'temporary-file access and the repository provider integration',
			'deployment_snapshot_changed'       => 'Review the current package source',
			'deployment_destination_exists'     => 'Link the installed plugin or theme',
			'deployment_self_update_blocked'    => 'Update Booster separately',
			'deployment_release_source_blocked' => 'Use Published releases or WordPress Updates',
			'deployment_maintenance_active'     => 'Wait for the current update to finish',
		);
		$defined_failed_codes  = array_filter(
			( new \ReflectionClass( DeploymentOutcome::class ) )->getConstants(),
			static function ( mixed $code ): bool {
				if ( ! is_string( $code ) ) {
					return false;
				}

				return in_array(
					DeploymentOutcome::from_code( $code )->get_state(),
					array( DeploymentState::FAILED, DeploymentState::NEEDS_ATTENTION ),
					true
				);
			}
		);
		$expected_codes       = array_keys( $expected_remediation );

		sort( $defined_failed_codes );
		sort( $expected_codes );

		self::assertSame( $expected_codes, $defined_failed_codes );

		foreach ( $expected_remediation as $code => $fragment ) {
			self::assertStringContainsString( $fragment, DeploymentOutcomeMessage::for_code( $code ), $code );
		}
	}

	public function test_new_preflight_codes_describe_the_specific_problem(): void {
		self::assertStringContainsString( 'Version: 0.1.0', DeploymentOutcomeMessage::for_code( 'package_version_missing' ) );
		self::assertStringContainsString( 'subdirectory is missing', DeploymentOutcomeMessage::for_code( 'package_subdirectory_missing' ) );
		self::assertStringContainsString( 'newer PHP version', DeploymentOutcomeMessage::for_code( 'package_requires_newer_php' ) );
		self::assertStringContainsString( 'integrity check', DeploymentOutcomeMessage::for_code( 'archive_integrity_failed' ) );
	}

	public function test_generic_failures_escalate_without_leaking_input(): void {
		$preflight = DeploymentOutcomeMessage::for_code( 'preflight_failed' );
		$unknown   = DeploymentOutcomeMessage::for_code( 'Authorization: Bearer secret-canary' );

		self::assertStringContainsString( 'no exact failed check was recorded', $preflight );
		self::assertStringContainsString( 'Troubleshooting logging', $preflight );
		self::assertStringContainsString( 'redacted report', $preflight );
		self::assertStringContainsString( 'verify the package state', $unknown );
		self::assertStringNotContainsString( 'Authorization', $unknown );
		self::assertStringNotContainsString( 'secret-canary', $unknown );
	}

	public function test_archive_limit_messages_explain_the_target_local_remedy(): void {
		$compressed = DeploymentOutcomeMessage::for_code( 'archive_compressed_too_large' );
		$expanded   = DeploymentOutcomeMessage::for_code( 'archive_expanded_too_large' );
		$invalid    = DeploymentOutcomeMessage::for_code( 'archive_limit_invalid' );

		self::assertStringContainsString( 'repository ZIP', $compressed );
		self::assertStringContainsString( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', $compressed );
		self::assertStringContainsString( 'expands beyond', $expanded );
		self::assertStringContainsString( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', $expanded );
		self::assertStringContainsString( '1 MiB and 536,870,911 bytes', $invalid );
		self::assertStringContainsString( 'just under 512 MiB', $invalid );
		self::assertStringNotContainsString( 'Authorization', $compressed . $expanded . $invalid );
	}

	public function test_postcondition_messages_explain_the_specific_safe_failure(): void {
		self::assertStringContainsString( 'maintenance mode', DeploymentOutcomeMessage::for_code( 'maintenance_remaining' ) );
		self::assertStringContainsString( 'version', DeploymentOutcomeMessage::for_code( 'installed_version_mismatch' ) );
		self::assertStringContainsString( 'activation state', DeploymentOutcomeMessage::for_code( 'activation_state_changed' ) );
	}

	public function test_persistence_uncertain_message_directs_the_operator_to_both_recovery_surfaces(): void {
		$message = DeploymentOutcomeMessage::for_code( 'persistence_uncertain' );

		self::assertStringContainsString( 'activity', $message );
		self::assertStringContainsString( 'existing package settings', $message );
		self::assertStringContainsString( 'before retrying', $message );
	}

	public function test_already_managed_outcome_does_not_claim_the_requested_bytes_were_installed(): void {
		$message = DeploymentOutcomeMessage::for_code( 'already_managed' );

		self::assertStringContainsString( 'already manages', $message );
		self::assertStringNotContainsString( 'requested package bytes', $message );
	}
}
