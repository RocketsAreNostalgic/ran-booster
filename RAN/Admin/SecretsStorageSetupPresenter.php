<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Secrets\SecretsStorageProvisioningResult;

/**
 * Builds the privileged Overview payload without exposing private paths to
 * redirects, logs, notices or other admin screens.
 */
final readonly class SecretsStorageSetupPresenter {

	/**
	 * @return array{
	 *     status: string,
	 *     reason_code: string,
	 *     message: string,
	 *     candidate_path: string|null,
	 *     candidate_directory: string|null,
	 *     path_source: string|null,
	 *     discarded_candidates: list<array{directory:string,code:string,reason:string,component:string|null}>,
	 *     can_provision: bool,
	 *     action_url: string,
	 *     recovery: array{
	 *         state: string,
	 *         message: string,
	 *         candidate_path: string|null,
	 *         candidate_directory: string|null,
	 *         token: string|null,
	 *         can_adopt: bool,
	 *         can_reset: bool,
	 *         reset_confirmation: string|null
	 *     }|null,
	 *     manual_preflight: string|null,
	 *     directory_commands: list<string>,
	 *     config_alternatives: array{define: string, wp_cli: string}|null
	 * }
	 */
	public function build(
		SecretsStorageProvisioningResult $result,
		string $action_url,
		string $wordpress_root = '',
		bool $include_sensitive_details = true,
		?array $recovery = null
	): array {

		$candidate           = $include_sensitive_details ? $result->candidate_path() : null;
		$directory_commands  = array();
		$config_alternatives = null;
		$manual_preflight    = null;
		if ( null !== $candidate
			&& ! $result->has_configured_path()
			&& ! $result->requires_next_request_verification()
		) {
			$directory = dirname( $candidate );
			$parent    = dirname( $directory );
			$quoted    = escapeshellarg( $directory );
			$php_path  = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $directory );

			$root = realpath( $wordpress_root );

			$manual_preflight    = __( 'Before running these commands, verify every existing path component is a real directory owned by the WordPress account and is not a symbolic link.', 'ran-booster' );
			$directory_commands  = array(
				'test ! -L ' . escapeshellarg( $parent )
					. ' && test ! -L ' . escapeshellarg( $directory )
					. ' && install -d -m 700 -- ' . escapeshellarg( $parent ) . ' ' . escapeshellarg( $directory ),
			);
			$config_alternatives = array(
				'define' => "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . $php_path . "' );",
				'wp_cli' => false === $root
					? ''
					: 'wp --path=' . escapeshellarg( $root )
						. ' config set RAN_BOOSTER_ENCRYPTED_SECRETS_DIR ' . $quoted . ' --type=constant',
			);
		}

		$recovery_payload = null;

		if ( $include_sensitive_details && null !== $recovery ) {
			$recovery_candidate = is_string( $recovery['candidate_path'] ?? null )
				? $recovery['candidate_path']
				: null;
			$recovery_token     = is_string( $recovery['token'] ?? null )
				? $recovery['token']
				: null;
			$recovery_state     = is_string( $recovery['state'] ?? null )
				? $recovery['state']
				: 'blocked';
			$reset_confirmation = is_string( $recovery['confirmation'] ?? null )
				? $recovery['confirmation']
				: null;
			$recovery_payload   = array(
				'state'               => $recovery_state,
				'message'             => is_string( $recovery['message'] ?? null ) ? $recovery['message'] : '',
				'candidate_path'      => $recovery_candidate,
				'candidate_directory' => null === $recovery_candidate ? null : dirname( $recovery_candidate ),
				'token'               => $recovery_token,
				'can_adopt'           => 'available' === $recovery_state
					&& null !== $recovery_candidate
					&& null !== $recovery_token,
				'can_reset'           => 'reset_available' === $recovery_state
					&& null !== $reset_confirmation,
				'reset_confirmation'  => $reset_confirmation,
			);
		}

		$discarded_candidates = $include_sensitive_details
			? $this->localized_discarded_candidates( $result->discarded_candidates() )
			: array();

		return array(
			'status'               => $result->status(),
			'reason_code'          => $result->code(),
			'message'              => $result->message(),
			'candidate_path'       => $candidate,
			'candidate_directory'  => null === $candidate ? null : dirname( $candidate ),

			'path_source'          => $include_sensitive_details ? $result->path_source() : null,
			'discarded_candidates' => $discarded_candidates,

			'can_provision'        => $include_sensitive_details && $result->can_provision_automatically(),

			'action_url'           => $action_url,
			'recovery'             => $recovery_payload,
			'manual_preflight'     => $manual_preflight,
			'directory_commands'   => $directory_commands,
			'config_alternatives'  => $config_alternatives,
		);
	}

	/**
	 * @param list<array{directory:string,code:string,reason:string,component:string|null}> $candidates
	 * @return list<array{directory:string,code:string,reason:string,component:string|null}>
	 */
	private function localized_discarded_candidates( array $candidates ): array {
		foreach ( $candidates as $index => $candidate ) {
			$candidates[ $index ]['reason'] = match ( $candidate['code'] ) {
				'invalid_candidate_path' => __( 'The candidate is not a valid absolute secrets.json path.', 'ran-booster' ),
				'temporary_storage' => __( 'The candidate is inside the operating system temporary directory.', 'ran-booster' ),
				'inside_unsafe_boundary' => __( 'The candidate is inside a public web or version-control directory.', 'ran-booster' ),
				'private_anchor_unavailable' => __( 'The private account directory is missing, is not a directory or is a symbolic link.', 'ran-booster' ),
				'symlink_or_unreadable_component' => __( 'A path component is a symbolic link or could not be inspected.', 'ran-booster' ),
				'storage_file_not_regular' => __( 'The existing storage target is not a regular file.', 'ran-booster' ),
				'storage_file_hard_linked' => __( 'The existing storage target has more than one hard link.', 'ran-booster' ),
				'path_component_not_directory' => __( 'A path component is not a directory.', 'ran-booster' ),
				'world_writable_host_ancestor' => __( 'A host directory is writable by every local user, so the private account path could be replaced.', 'ran-booster' ),
				'php_accessible_group_writable_ancestor' => __( 'A group-writable host directory is owned by, writable by or grouped with the PHP process, so the private account path could be replaced.', 'ran-booster' ),
				'broad_private_path_permissions' => __( 'A private path component is writable by its group or by other users.', 'ran-booster' ),
				'private_anchor_not_owned' => __( 'The private account directory is not writable and owned by the PHP process user.', 'ran-booster' ),
				default => $candidate['reason'],
			};
		}

		return $candidates;
	}
}
