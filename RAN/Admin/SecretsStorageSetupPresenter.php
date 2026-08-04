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
	 *     path_source: string|null,
	 *     can_provision: bool,
	 *     action_url: string,
	 *     manual_preflight: string|null,
	 *     directory_commands: list<string>,
	 *     config_alternatives: array{define: string, wp_cli: string}|null
	 * }
	 */
	public function build(
		SecretsStorageProvisioningResult $result,
		string $actionUrl,
		string $wordpressRoot = '',
		bool $includeSensitiveDetails = true
	): array {
		$candidate          = $includeSensitiveDetails ? $result->candidatePath() : null;
		$directoryCommands  = array();
		$configAlternatives = null;
		$manualPreflight    = null;
		if ( null !== $candidate
			&& ! $result->hasConfiguredPath()
			&& ! $result->requiresNextRequestVerification()
		) {
			$directory = dirname( $candidate );
			$parent    = dirname( $directory );
			$quoted    = escapeshellarg( $directory );
			$phpPath   = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $directory );
			$root      = realpath( $wordpressRoot );

			$manualPreflight    = 'Before running these commands, verify every existing path component is a real directory owned by the WordPress account and is not a symbolic link.';
			$directoryCommands  = array(
				'test ! -L ' . escapeshellarg( $parent )
					. ' && test ! -L ' . escapeshellarg( $directory )
					. ' && install -d -m 700 -- ' . escapeshellarg( $parent ) . ' ' . escapeshellarg( $directory ),
			);
			$configAlternatives = array(
				'define' => "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . $phpPath . "' );",
				'wp_cli' => false === $root
					? ''
					: 'wp --path=' . escapeshellarg( $root )
						. ' config set RAN_BOOSTER_ENCRYPTED_SECRETS_DIR ' . $quoted . ' --type=constant',
			);
		}

		return array(
			'status'              => $result->status(),
			'reason_code'         => $result->code(),
			'message'             => $result->message(),
			'candidate_path'      => $candidate,
			'path_source'         => $includeSensitiveDetails ? $result->pathSource() : null,
			'can_provision'       => $includeSensitiveDetails && $result->canProvisionAutomatically(),
			'action_url'          => $actionUrl,
			'manual_preflight'    => $manualPreflight,
			'directory_commands'  => $directoryCommands,
			'config_alternatives' => $configAlternatives,
		);
	}
}
