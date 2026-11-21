<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\DeploymentOutcomeMessage;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentState;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class DeploymentOutcomeMessageTest extends TestCase {

	public function test_only_a_failed_run_the_pause_refused_displays_as_skipped(): void {
		self::assertSame( 'skipped', DeploymentOutcomeMessage::display_state( 'failed', DeploymentOutcome::CODE_SOURCE_PAUSED ) );
		self::assertSame( 'failed', DeploymentOutcomeMessage::display_state( 'failed', DeploymentOutcome::CODE_POLICY_BLOCKED ) );
		self::assertSame( 'failed', DeploymentOutcomeMessage::display_state( 'failed', null ) );
		self::assertSame( 'needs_attention', DeploymentOutcomeMessage::display_state( 'needs_attention', DeploymentOutcome::CODE_SOURCE_PAUSED ) );
		self::assertSame( 'queued', DeploymentOutcomeMessage::display_state( 'queued', null ) );
	}

	public function test_only_a_terminal_pre_fence_working_copy_fact_refines_policy_copy(): void {
		$facts = array(
			'branch_refusal'                 => 'target_is_working_copy',
			'deployment_attempt_id'          => 17,
			'deployment_source'              => 'manual',
			'deployment_state'               => 'failed',
			'deployment_outcome'             => 'policy_blocked',
			'deployment_mutation_started_at' => null,
		);
		$copy  = 'This package is a version-controlled working copy, so the update was blocked; update it through its version-control workflow.';
		self::assertSame( $copy, DeploymentOutcomeMessage::for_code( 'policy_blocked', wp_json_encode( $facts ) ) );
		$generic = DeploymentOutcomeMessage::for_code( 'policy_blocked' );
		foreach ( array(
			array( 'branch_refusal' => 'future_reason' ),
			array( 'branch_refusal' => true ),
			array( 'branch_refusal' => 'target_is_working_copy password=canary' ),
			array( 'deployment_state' => 'succeeded' ),
			array( 'deployment_outcome' => 'deployed' ),
			array( 'deployment_mutation_started_at' => '2026-11-17 00:00:00' ),
			array( 'deployment_attempt_id' => '17' ),
			array( 'deployment_source' => 'unknown' ),
			array( 'deployment_settled_by' => 'runner_throw' ),
			array( 'deployment_resolved_by' => 7 ),
		) as $invalid ) {
			self::assertSame( $generic, DeploymentOutcomeMessage::for_code( 'policy_blocked', wp_json_encode( array_replace( $facts, $invalid ) ) ) );
		}
		unset( $facts['deployment_mutation_started_at'] );
		foreach ( array( wp_json_encode( $facts ), null, false, array(), '{invalid', '[]', str_repeat( 'x', 4097 ) ) as $invalid ) {
			self::assertSame( $generic, DeploymentOutcomeMessage::for_code( 'policy_blocked', $invalid ) );
		}
		self::assertSame( DeploymentOutcomeMessage::for_code( 'deployed' ), DeploymentOutcomeMessage::for_code( 'deployed', wp_json_encode( $facts ) ) );
	}

	public function test_each_unmatched_push_reason_has_its_own_sentence_and_the_shared_note(): void {
		$note      = ' Repeats within an hour are not listed; turn on debug logging to see them.';
		$sentences = array();
		foreach ( \RAN\Deployment\DeploymentCoordinator::UNMATCHED_REASONS as $reason ) {
			$copy = DeploymentOutcomeMessage::for_activity( 'skipped', 'webhook.unmatched.' . $reason );
			self::assertStringStartsWith( 'Push received; ', $copy, $reason );
			self::assertStringEndsWith( $note, $copy, $reason );
			$sentences[] = $copy;
		}
		self::assertCount( 6, array_unique( $sentences ) );
		self::assertStringNotContainsString( $note, DeploymentOutcomeMessage::for_activity( 'skipped', 'policy_disabled' ) );
	}

	public function test_each_recorded_delivery_refusal_has_its_approved_sentence_and_the_shared_note(): void {
		$note = ' Repeats within an hour are not listed; turn on debug logging to see them.';
		$copy = array();
		foreach ( \RAN\Deployment\DeploymentCoordinator::REJECTED_DELIVERY_REASONS as $reason ) {
			$copy[ $reason ] = DeploymentOutcomeMessage::for_activity( 'rejected', 'webhook.rejected.' . $reason );
		}
		self::assertSame(
			array(
				'secret_store_unavailable' => 'Delivery refused; Booster could not read its signing secrets.' . $note,
				'profile_invalid'          => 'Delivery refused; a saved signing secret is not valid. Save it again.' . $note,
				'secret_invalid'           => 'Delivery refused; a saved signing secret is not valid. Save it again.' . $note,
			),
			$copy
		);
	}

	public function test_uncertain_outcomes_do_not_claim_unrecorded_results(): void {
		self::assertStringNotContainsString( 'completed the update', DeploymentOutcomeMessage::for_code( 'restoration_uncertain' ) );
		self::assertStringNotContainsString( 'restore the plugin activation state', DeploymentOutcomeMessage::for_code( 'activation_failed' ) );
		self::assertStringContainsString( 'maintenance file', DeploymentOutcomeMessage::for_code( 'deployment_maintenance_active' ) );
	}

	public function test_rate_limit_message_is_actionable_and_contains_no_provider_evidence(): void {
		$message = DeploymentOutcomeMessage::for_code( 'provider_rate_limited' );

		self::assertStringContainsString( 'rate limit', $message );
		self::assertStringContainsString( 'quota to reset', $message );
		self::assertStringNotContainsString( 'Authorization', $message );
	}

	public function test_unknown_outcome_uses_safe_fallback_copy(): void {
		self::assertSame(
			'Booster recorded an unavailable deployment outcome. Verify the package state before retrying. If it repeats, turn on Troubleshooting logging and retry to capture the cause.',
			DeploymentOutcomeMessage::for_code( 'provider said Authorization: Bearer secret-canary' )
		);
	}

	public function test_every_failed_outcome_has_independent_actionable_remediation(): void {
		$expected_remediation = array(
			'provider_failed'                   => 'Check the repository and credential settings',
			'provider_request_invalid'          => 'Review the managed package settings',
			'provider_credential_rejected'      => 'Replace or update it',
			'provider_credential_unavailable'   => 'could not read the saved credential',
			'provider_access_denied'            => 'Check the credential permissions',
			'provider_repository_missing'       => 'Check that it exists',
			'provider_reference_unavailable'    => 'Review the configured branch or reference',
			'provider_repository_changed'       => 'Review the package source',
			'provider_redirected'               => 'Check the current name on the provider',
			'provider_rate_limited'             => 'Wait for its quota to reset',
			'provider_selection_changed'        => 'submit a new deployment',
			'provider_unavailable'              => 'Try again later',
			'archive_compressed_too_large'      => 'Reduce the repository size',
			'archive_expanded_too_large'        => 'Reduce the repository contents',
			'archive_limit_invalid'             => 'Set the configured constant',
			'preflight_failed'                  => 'turn on Troubleshooting logging',
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
			'archive_cleanup_failed'            => 'temporary-file access and the Git provider integration',
			'deployment_snapshot_changed'       => 'Review the current package source',
			'deployment_destination_exists'     => 'Link the installed plugin or theme',
			'deployment_self_update_blocked'    => 'Update Booster separately',
			'deployment_release_source_blocked' => 'Use Published releases or WordPress Updates',
			'deployment_maintenance_active'     => 'Check whether an update is still running',
			'source_paused'                     => 'paused by a site setting',
		);
		$defined_failed_codes = array_filter(
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
		self::assertStringContainsString( 'contents', DeploymentOutcomeMessage::for_code( 'archive_integrity_failed' ) );
	}

	public function test_generic_failures_escalate_without_leaking_input(): void {
		$preflight = DeploymentOutcomeMessage::for_code( 'preflight_failed' );
		$unknown   = DeploymentOutcomeMessage::for_code( 'Authorization: Bearer secret-canary' );

		self::assertStringContainsString( 'no exact failed check was recorded', $preflight );
		self::assertStringContainsString( 'Troubleshooting logging', $preflight );
		self::assertStringContainsString( 'retry to capture the cause', $preflight );
		self::assertStringNotContainsString( 'report', $preflight );
		self::assertStringContainsString( 'Verify the package state', $unknown );
		self::assertStringNotContainsString( 'Authorization', $unknown );
		self::assertStringNotContainsString( 'secret-canary', $unknown );
	}

	public function test_html_surfaces_link_only_troubleshooting_logging_and_only_for_those_who_can_open_it(): void {
		foreach ( array( 'provider_failed', 'preflight_failed', 'Authorization: Bearer secret-canary' ) as $code ) {
			$html = DeploymentOutcomeMessage::for_code_html( $code );
			self::assertSame( 1, substr_count( $html, '<a ' ), $code );
			self::assertStringContainsString( 'panel=logging">Troubleshooting logging</a>', $html, $code );
			self::assertStringNotContainsString( 'secret-canary', $html );
		}
		self::assertStringNotContainsString( '<a ', DeploymentOutcomeMessage::for_code_html( 'lock_unavailable' ) );
		foreach ( array( 'archive_compressed_too_large', 'archive_expanded_too_large' ) as $code ) {
			$html = DeploymentOutcomeMessage::for_code_html( $code );
			self::assertSame( 1, substr_count( $html, '<a ' ), $code );
			self::assertStringContainsString( 'review <a href="https://example.test/wp-admin/admin.php?page=ran-booster-settings#ran-booster-general-numeric">Download limits in Booster Settings</a>;', $html, $code );
			self::assertStringNotContainsString( '<a ', DeploymentOutcomeMessage::for_code( $code ) );
		}

		$capabilities = $GLOBALS['ran_booster_test_capabilities'] ?? null;

		$GLOBALS['ran_booster_test_capabilities'] = array( 'manage_options' => false );
		try {
			$plain = DeploymentOutcomeMessage::for_code_html( 'provider_failed' );
		} finally {
			if ( null === $capabilities ) {
				unset( $GLOBALS['ran_booster_test_capabilities'] );
			} else {
				$GLOBALS['ran_booster_test_capabilities'] = $capabilities;
			}
		}
		self::assertStringNotContainsString( '<a ', $plain );
		self::assertStringContainsString( 'turn on Troubleshooting logging and retry', $plain );
	}

	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_the_troubleshooting_logging_link_follows_the_network_admin_on_multisite(): void {
		// Booster's pages exist only in the network admin on multisite.
		$GLOBALS['ran_booster_package_view_multisite'] = true;

		self::assertStringContainsString(
			'<a href="https://example.test/wp-admin/network/admin.php?page=ran-booster&amp;tab=troubleshooting&amp;panel=logging">Troubleshooting logging</a>',
			DeploymentOutcomeMessage::for_code_html( 'provider_failed' )
		);
		self::assertStringContainsString(
			'<a href="https://example.test/wp-admin/network/admin.php?page=ran-booster-settings#ran-booster-general-numeric">Download limits in Booster Settings</a>',
			DeploymentOutcomeMessage::for_code_html( 'archive_compressed_too_large' )
		);
	}

	public function test_archive_limit_messages_explain_the_target_local_remedy(): void {
		$compressed = DeploymentOutcomeMessage::for_code( 'archive_compressed_too_large' );
		$expanded   = DeploymentOutcomeMessage::for_code( 'archive_expanded_too_large' );
		$invalid    = DeploymentOutcomeMessage::for_code( 'archive_limit_invalid' );

		self::assertStringContainsString( 'repository ZIP', $compressed );
		self::assertStringContainsString( 'Download limits in Booster Settings', $compressed );
		self::assertStringContainsString( 'expands beyond', $expanded );
		self::assertStringContainsString( 'stricter extraction safeguards', $expanded );
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
