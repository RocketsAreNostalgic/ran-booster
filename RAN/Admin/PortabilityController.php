<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\Deployment\PackageMutationGuard;
use RAN\Logging\BoosterLogger;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintCredentialAction;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\LocalSecretStoreUnavailable;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Portability\PackageBlueprint;
use RAN\Portability\PortabilityApplicationService;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\Runtime\RuntimeSupport;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PackageStorageFailure;
use RAN\UpdaterSupport\V1\PackageDirectory;
use Throwable;

/**
 * Narrow administrator boundary for live portability export and review.
 *
 * @phpstan-import-type PackageProviderChoice from ProviderSettingsPresenter
 */
final readonly class PortabilityController {

	public const EXPORT_ACTION        = 'ran_booster_export_blueprint';
	public const PREVIEW_ACTION       = 'ran_booster_preview_blueprint';
	public const APPLY_ACTION         = 'ran_booster_apply_blueprint';
	public const EXPORT_NONCE_ACTION  = 'ran-booster-export-blueprint';
	public const PREVIEW_NONCE_ACTION = 'ran-booster-preview-blueprint';
	public const APPLY_NONCE_ACTION   = 'ran-booster-apply-blueprint';

	public function __construct(
		private ManagedPackageBlueprintExporter $exporter,
		private BlueprintArchive $archive,
		private PortabilityApplicationService $application,
		private ProviderSettingsPresenter $provider_settings,
		private ?ProviderTabLinks $links = null
	) {
	}

	public function handle_export(): mixed {
		RuntimeSupport::assert_managed_operations_allowed();

		if ( ! $this->is_allowed( self::EXPORT_NONCE_ACTION ) ) {
			return $this->export_failure( __( 'Your session expired. Reload the page and try again.', 'ran-booster' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- isAllowed validates this purpose-specific nonce before reading input.
		try {
			$credentials = $this->selected_credentials();
		} catch ( InvalidArgumentException $failure ) {
			return $this->export_failure( __( 'The selected packages or repository credentials changed or are invalid. Reload this page and try again.', 'ran-booster' ), 400, 'selection', $failure );
		}
		// Exporting secret material needs the secret authority that changing it does; nothing on this site changes.
		if ( array() !== $credentials && ! current_user_can( 'activate_plugins' ) ) {
			return $this->export_failure( __( 'You do not have permission to export repository credentials.', 'ran-booster' ), 403, 'authority' );
		}
		$password       = $this->password_from_request( 'password' );
		$confirmation   = $this->password_from_request( 'password_confirmation' );
		$password_error = $this->export_password_error( array() !== $credentials, $password, $confirmation );

		if ( null !== $password_error ) {
			return $this->export_failure( $password_error, 400, 'password' );
		}

		try {
			$report    = array();
			$blueprint = $this->exporter->export( $credentials, $this->selected_packages(), $report );
		} catch ( LocalSecretStoreUnavailable $failure ) {
			return $this->export_failure( __( 'Encrypted credential storage is unavailable, so Booster did not export credentials.', 'ran-booster' ), 409, 'export', $failure );
		} catch ( PackageStorageFailure $failure ) {
			return $this->export_failure( $failure->getMessage(), $failure->is_database_unsupported() ? 503 : 500, 'export', $failure );

		} catch ( InvalidArgumentException $failure ) {
			return $this->export_failure( __( 'The selected packages or repository credentials changed or are invalid. Reload this page and try again.', 'ran-booster' ), 400, 'export', $failure );
		} catch ( Throwable $failure ) {
			return $this->export_failure( __( 'Booster could not read the managed package selection. Try again.', 'ran-booster' ), 500, 'export', $failure );
		}

		$root = get_temp_dir();
		// tempnam() falls back to the system directory with a notice when a writable root is still unusable; contain the notice and refuse below.
		set_error_handler( static fn (): bool => true, E_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped to this one native call.
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Native probe before native tempnam(); WP_Filesystem is not loaded for this request.
			$path = is_dir( $root ) && is_writable( $root ) ? tempnam( $root, 'ran-booster-blueprint-' ) : false;
		} finally {
			restore_error_handler();
		}
		if ( false === $path || realpath( dirname( $path ) ) !== realpath( $root ) ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only this request's misplaced staging file.
			}
			return $this->export_failure( __( 'Booster could not create the Blueprint ZIP. Try again.', 'ran-booster' ), 500, 'temp_dir' );
		}
		// A fatal error after this point skips the finally below; never leave the archive behind.
		register_shutdown_function( static fn () => is_file( $path ) && unlink( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only this request's generated ZIP.
		try {
			try {
				$this->archive->write_to( $path, $blueprint, array() === $blueprint->credentials ? null : $password );
			} catch ( Throwable $failure ) {
				return $this->export_failure( __( 'Booster could not create the Blueprint ZIP. Try again.', 'ran-booster' ), 500, 'archive_write', $failure );
			}
			$bytes = $this->archive_bytes( $path );
			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'X-RAN-Blueprint-Requirement-Warnings: ' . (string) count( $report ) );
			header( 'Content-Disposition: attachment; filename="' . $this->export_filename( home_url(), time() ) . '"' );
			header( 'Content-Length: ' . (string) strlen( $bytes ) );
			echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Emits only the fully read, bounded ZIP after validation.
		} catch ( Throwable $failure ) {
			return $this->export_failure( __( 'Booster could not read the Blueprint ZIP. Try again.', 'ran-booster' ), 500, 'archive_read', $failure );
		} finally {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only this request's generated ZIP.
				unlink( $path );
			}
		}

		BoosterLogger::log(
			'Blueprint export finished.',
			array(
				'operation'    => 'portability_export',
				'outcome_code' => 'exported',
			)
		);
		exit;
	}

	private function export_filename( string $site_url, int $timestamp ): string {
		$parts = wp_parse_url( $site_url );
		$host  = is_array( $parts ) && is_string( $parts['host'] ?? null ) ? $parts['host'] : '';
		// Parse the original host before IDNA: parse_url may replace UTF-8 bytes in some locales.
		if ( is_array( $parts ) && preg_match( '~\Ahttps?://([^/?#@:]+)(?::[0-9]+)?(?:[/?#]|\z)~i', $site_url, $matches ) ) {
			$host = $matches[1];
		} elseif ( is_array( $parts ) && ( isset( $parts['user'] ) || isset( $parts['pass'] ) || preg_match( '/[^\x00-\x7f]/', $host ) ) ) {
			$host = '';
		}
		$ip      = trim( $host, '[]' );
		$encoded = false;
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$host    = 'ipv6-' . str_replace( ':', '-', $ip );
			$encoded = true;
		} elseif ( preg_match( '/[^\x00-\x7f]/', $host ) ) {
			try {
				if ( ! preg_match( '//u', $host ) || preg_match( '/[^a-z0-9.\-\x80-\xff]/i', $host ) ) {
					$host = '';
				} elseif ( function_exists( 'idn_to_ascii' ) && defined( 'IDNA_NONTRANSITIONAL_TO_ASCII' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
					$host = (string) idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
				} else {
					$host    = 'utf8-' . bin2hex( $host );
					$encoded = true;
				}
			} catch ( Throwable ) {
				$host = '';
			}
		} elseif ( preg_match( '/[^a-z0-9.-]/i', $host ) ) {
			$host = '';
		}
		$host  = strtolower( $host );
		$host  = str_ends_with( $host, '.' ) ? substr( $host, 0, -1 ) : $host;
		$valid = $encoded
			? preg_match( '/^(?:utf8-(?:[0-9a-f]{2})+|ipv6-[a-f0-9.-]{2,})$/D', $host )
			: preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $host );
		$host  = str_replace( '.', '', $host );
		if ( strlen( $host ) > 213 || ! $valid ) {
			$host = 'site';
		}

		return gmdate( 'Ymd-His', $timestamp ) . '_' . $host . '_ran_booster_blueprint.zip';
	}

	private function archive_bytes( string $path ): string {
		$size = is_file( $path ) ? filesize( $path ) : false;
		if ( ! is_int( $size ) || $size < 1 || $size > BlueprintArchive::MAX_BYTES ) {
			throw new InvalidArgumentException();
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Contains private temporary-file warnings inside the fixed administrator error boundary.
		set_error_handler( static fn(): bool => true );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads only this request's bounded private temporary ZIP before headers are sent.
			$bytes = file_get_contents( $path );
		} finally {
			restore_error_handler();
		}
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $size ) {
			throw new InvalidArgumentException();
		}

		return $bytes;
	}

	public function handle_preview(): mixed {
		RuntimeSupport::assert_managed_operations_allowed();

		if ( ! $this->is_allowed( self::PREVIEW_NONCE_ACTION ) ) {
			return wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'ran-booster' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- isAllowed validates this purpose-specific nonce before reading the native upload.
		$upload = $this->uploaded_blueprint();
		if ( null === $upload ) {
			return wp_send_json_error( array( 'message' => __( 'Choose a valid Blueprint ZIP.', 'ran-booster' ) ), 400 );
		}

		try {
			$blueprint = $this->archive->read_from( $upload['tmp_name'], $this->password_from_request( 'password' ) );
			try {
				$decisions = $this->credential_decisions( $blueprint );
				$targets   = $this->target_credential_ids();
			} catch ( InvalidArgumentException ) {
				return wp_send_json_error( array( 'message' => __( 'The repository credential decisions are invalid. Review the Blueprint again.', 'ran-booster' ) ), 400 );
			}
			$refusal = $this->credential_refusal( $decisions, $targets, false );
			if ( null !== $refusal ) {
				return $this->credential_refused( $refusal );
			}
			return $this->preview_success( $this->review_blueprint( $blueprint, $decisions, $targets ) );
		} catch ( PackageStorageFailure $failure ) {
			return wp_send_json_error( array( 'message' => $failure->getMessage() ), $failure->is_database_unsupported() ? 503 : 500 );
		} catch ( Throwable ) {
			return wp_send_json_error( array( 'message' => __( 'Booster could not read this Blueprint. If it includes credentials, enter its ZIP password and try again.', 'ran-booster' ) ), 400 );
		}
	}

	public function handle_apply(): mixed {
		RuntimeSupport::assert_managed_operations_allowed();

		if ( ! $this->is_allowed( self::APPLY_NONCE_ACTION ) ) {
			return wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'ran-booster' ) ), 403 );
		}

		$upload = $this->uploaded_blueprint();
		$row    = $this->requested_row();
		if ( null === $upload || null === $row ) {
			return wp_send_json_error( array( 'message' => __( 'Choose the same Blueprint and package row to apply.', 'ran-booster' ) ), 400 );
		}

		try {
			$target_credentials = $this->target_credential_ids();
			$blueprint          = $this->archive->read_from( $upload['tmp_name'], $this->password_from_request( 'password' ) );
			$package            = $blueprint->packages[ $row ] ?? null;
			$ordinal            = $package instanceof BlueprintPackage ? $this->credential_ordinal_for_package( $blueprint, $package ) : null;
			try {
				$decisions = $this->credential_decisions( $blueprint );
				if ( null !== $ordinal && isset( $target_credentials[ $row ] ) ) {
					throw new InvalidArgumentException();
				}
			} catch ( InvalidArgumentException ) {
				return wp_send_json_error( array( 'message' => __( 'The repository credential decisions are invalid. Review the Blueprint again.', 'ran-booster' ) ), 400 );
			}
			// Each Apply submits every row's choices but uses only this row's credential.
			$refusal = $this->credential_refusal(
				null === $ordinal ? array() : array_intersect_key( $decisions, array( $ordinal => true ) ),
				array_intersect_key( $target_credentials, array( $row => true ) ),
				null !== $ordinal && BlueprintCredentialAction::IMPORT === ( $decisions[ $ordinal ]['action'] ?? null )
			);
			if ( null !== $refusal ) {
				return $this->credential_refused( $refusal );
			}
			$release_review = null;
			if ( $package instanceof BlueprintPackage && null !== $package->release_configuration && 'install' === $this->requested_action() ) {
				try {
					$release_review = $this->release_review_from_request();
				} catch ( InvalidArgumentException ) {
					return wp_send_json_success( $this->review_again( $package ) );
				}
				if ( null === $release_review || ! $this->valid_release_review_nonce( $blueprint, $row, $release_review, $target_credentials[ $row ] ?? null, $decisions ) ) {
					return wp_send_json_success( $this->review_again( $package ) );
				}
			}
			$can_install = $package instanceof BlueprintPackage
				&& current_user_can( 'plugin' === $package->type ? 'install_plugins' : 'install_themes' );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_apply validates its purpose-specific nonce before reading adopt.
			$result = $this->application->apply( $blueprint, $row, $this->requested_action(), $decisions, $target_credentials[ $row ] ?? null, ( '1' === (string) ( $_POST['adopt'] ?? '' ) ), $can_install, $this->selected_rows(), $release_review );
			$this->log_apply_result( $package, $result['status'], $result['credential_state'] );
			return wp_send_json_success( $result );
		} catch ( PackageStorageFailure $failure ) {
			BoosterLogger::log_exception(
				'portability package apply failed',
				$failure,
				array(
					'operation' => 'portability_apply',
					'step'      => 'apply',
				)
			);
			return wp_send_json_error( array( 'message' => $failure->getMessage() ), $failure->is_database_unsupported() ? 503 : 500 );
		} catch ( Throwable $failure ) {
			return wp_send_json_success( $this->apply_failure( $failure ) );
		}
	}

	/**
	 * @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $credential_decisions
	 * @param array<int, string> $target_credential_ids
	 */
	private function review_blueprint( PackageBlueprint $blueprint, array $credential_decisions, array $target_credential_ids ): string {
		$items         = $this->application->review( $blueprint, $credential_decisions, $target_credential_ids );
		$rows          = array();
		$provider_list = null;
		$local_report  = $this->application->local_report( $blueprint );

		foreach ( $items as $index => $item ) {
			$credential_ordinal         = $this->credential_ordinal_for_package( $blueprint, $item->package );
			$row                        = $this->row(
				$item,
				$credential_ordinal,
				$target_credential_ids[ $index ] ?? null,
				null !== $credential_ordinal
					&& BlueprintCredentialAction::IMPORT === ( $credential_decisions[ $credential_ordinal ]['action'] ?? null )
					&& TargetPackageAction::MANAGED === $item->action,
				$provider_list
			);
			$row['comparison']          = $this->application->compare_local( $item->package );
			$row['requirements']        = $local_report['requirements']['packages'][ $index ];
			$row['repository_conflict'] = isset( $local_report['repository_conflicts'][ $index ] );
			if ( isset( $row['release_review'] ) ) {
				$row['release_review_nonce'] = wp_create_nonce( $this->release_review_nonce_action( $blueprint, $index, $row['release_review'], $target_credential_ids[ $index ] ?? null, $credential_decisions ) );
			}
			$rows[] = $row;
		}

		return $this->render_review( $rows, array() === $blueprint->credentials ? array() : $this->credential_rows( $blueprint, $credential_decisions, $items, $provider_list ), $local_report['requirements']['summary'] );
	}

	/** @return list<int>|null */
	private function selected_rows(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_apply validates the purpose-specific nonce before reading the selection.
		$rows = $_POST['selected_rows'] ?? null;
		if ( null === $rows ) {
			return null;
		}
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || array() === $rows || count( $rows ) > PackageBlueprint::MAX_PACKAGES ) {
			throw new InvalidArgumentException();
		}
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! is_string( $row ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,2})\z/D', $row ) || (int) $row >= PackageBlueprint::MAX_PACKAGES ) {
				throw new InvalidArgumentException();
			}
			$result[] = (int) $row;
		}
		return $result;
	}

	/** @return array{release_id:string,tag:string,version:string,channel:string,fingerprint:string,package_root:string,main_file:string}|null */
	private function release_review_from_request(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_apply validates its operation nonce before accepting bounded review evidence.
		$value = $_POST['release_review'] ?? null;
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || strlen( $value ) > 4096 ) {
			throw new InvalidArgumentException();
		}
		$review = json_decode( wp_unslash( $value ), true );
		if ( ! is_array( $review ) || array_keys( $review ) !== array( 'release_id', 'tag', 'version', 'channel', 'fingerprint', 'package_root', 'main_file' ) ) {
			throw new InvalidArgumentException();
		}
		$release_id   = $review['release_id'];
		$tag          = $review['tag'];
		$version      = $review['version'];
		$channel      = $review['channel'];
		$fingerprint  = $review['fingerprint'];
		$package_root = $review['package_root'];
		$main_file    = $review['main_file'];
		foreach ( array( $release_id, $tag, $version, $channel, $fingerprint, $package_root, $main_file ) as $field ) {
			if ( ! is_string( $field ) || '' === $field || strlen( $field ) > 255 ) {
				throw new InvalidArgumentException();
			}
		}

		return array(
			'release_id'   => $release_id,
			'tag'          => $tag,
			'version'      => $version,
			'channel'      => $channel,
			'fingerprint'  => $fingerprint,
			'package_root' => $package_root,
			'main_file'    => $main_file,
		);
	}

	/** @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $credential_decisions
	 * @param array{release_id:string,tag:string,version:string,channel:string,fingerprint:string,package_root:string,main_file:string} $release_review */
	private function valid_release_review_nonce( PackageBlueprint $blueprint, int $row, array $release_review, ?string $target_credential_id, array $credential_decisions ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the purpose-specific review nonce bound to the complete release-install intent.
		$nonce = $_POST['release_review_nonce'] ?? null;

		return is_string( $nonce ) && false !== wp_verify_nonce( wp_unslash( $nonce ), $this->release_review_nonce_action( $blueprint, $row, $release_review, $target_credential_id, $credential_decisions ) );
	}

	/** @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $credential_decisions
	 * @param array{release_id:string,tag:string,version:string,channel:string,fingerprint:string,package_root:string,main_file:string} $release_review */
	private function release_review_nonce_action( PackageBlueprint $blueprint, int $row, array $release_review, ?string $target_credential_id, array $credential_decisions ): string {
		$decisions = array();
		foreach ( $credential_decisions as $ordinal => $decision ) {
			$decisions[ $ordinal ] = array(
				'action'    => $decision['action']->value,
				'target_id' => $decision['target_id'],
			);
		}
		ksort( $decisions, SORT_NUMERIC );
		$scope = array(
			'artifact_identity'    => $blueprint->artifact_identity(),
			'row'                  => $row,
			'release_review'       => $release_review,
			'target_credential_id' => $target_credential_id,
			'credential_decisions' => $decisions,
		);

		return 'ran-booster-portability-release-review|' . hash( 'sha256', (string) wp_json_encode( $scope ) );
	}

	/** @return array{status:string,message:string,credential_state:string} */
	private function review_again( BlueprintPackage $package ): array {
		$this->log_apply_result( $package, 'skipped', 'none' );
		return array(
			'status'           => 'skipped',
			'message'          => __( 'The reviewed release evidence changed or expired. Review the Blueprint again.', 'ran-booster' ),
			'credential_state' => 'none',
		);
	}

	private function preview_success( string $html ): mixed {
		if ( ! $this->is_htmx_request() ) {
			return wp_send_json_success( array( 'html' => $html ) );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The existing review partial escapes its display-safe controller projection.
		echo $html;
		wp_die();
	}

	private function is_htmx_request(): bool {
		$value = $_SERVER['HTTP_HX_REQUEST'] ?? null;

		return is_string( $value ) && 'true' === strtolower( trim( $value ) );
	}

	/**
	 * Using any credential, saved or carried, needs the secret authority; importing also needs file changes allowed.
	 *
	 * @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $decisions
	 * @param array<int, string>                                                    $targets
	 */
	private function credential_refusal( array $decisions, array $targets, bool $imports ): ?string {
		$uses = array() !== $targets || array() !== array_filter( $decisions, static fn ( array $decision ): bool => BlueprintCredentialAction::LEAVE !== $decision['action'] );
		if ( ( $uses || $imports ) && ! current_user_can( 'activate_plugins' ) ) {
			return 'credential_authority_missing';
		}

		return $imports && PackageMutationGuard::file_mods_disabled() ? 'file_mods_disallowed' : null;
	}

	private function credential_refused( string $code ): mixed {
		BoosterLogger::log(
			'Blueprint credential use refused.',
			array(
				'operation'    => 'portability_credentials',
				'outcome_code' => $code,
			)
		);
		return wp_send_json_error( array( 'message' => __( 'You cannot use or import repository credentials on this site. Choose another credential decision.', 'ran-booster' ) ), 403 );
	}

	private function is_allowed( string $nonce_action ): bool {
		return current_user_can( 'manage_options' ) && check_ajax_referer( $nonce_action, 'nonce', false );
	}

	/** A step logs the failure, codes only; the session refusal, which any visitor can cause, passes none. */
	private function export_failure( string $message, int $status, ?string $step = null, ?Throwable $failure = null ): mixed {
		if ( null !== $step ) {
			$context = array(
				'operation'   => 'portability_export',
				'step'        => $step,
				'http_status' => (string) $status,
			);
			if ( null === $failure ) {
				BoosterLogger::log( 'Blueprint export failed.', $context );
			} else {
				BoosterLogger::log_exception( 'Blueprint export failed.', $failure, $context );
			}
		}
		if ( $this->is_inline_export_request() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_send_json_error serialises the message and applies the integer HTTP status.
			return wp_send_json_error( array( 'message' => $message ), $status );
		}

		wp_die( esc_html( $message ), '', array( 'response' => absint( $status ) ) );
	}

	private function is_inline_export_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only selects the response shape after handle_export has validated its nonce.
		$format = $_POST['response_format'] ?? null;

		return is_string( $format ) && 'json' === $format;
	}

	private function password_from_request( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only from handlers guarded by isAllowed.
		$value = $_POST[ $key ] ?? null;

		return is_string( $value ) && '' !== $value ? wp_unslash( $value ) : null;
	}

	private function export_password_error( bool $include_credentials, ?string $password, ?string $confirmation ): ?string {
		if ( ! $include_credentials ) {
			return null;
		}
		if ( null === $password ) {
			return __( 'Choose a Blueprint password before exporting credentials.', 'ran-booster' );
		}
		if ( $password !== $confirmation ) {
			return __( 'The passwords do not match. Nothing was exported.', 'ran-booster' );
		}
		if ( ! BlueprintArchive::accepts_password( $password ) ) {
			return __( 'Use at least 20 characters, or generate one. Nothing was exported.', 'ran-booster' );
		}

		return null;
	}


	/** @return array{tmp_name:string}|null */
	private function uploaded_blueprint(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller validates its operation nonce first.
		$upload = $_FILES['blueprint'] ?? null;
		if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== ( $upload['error'] ?? null )
			|| ! is_string( $upload['tmp_name'] ?? null ) || ! is_uploaded_file( $upload['tmp_name'] )
			|| ! is_string( $upload['name'] ?? null ) || ! str_ends_with( strtolower( $upload['name'] ), '.zip' ) ) {
			return null;
		}

		return array( 'tmp_name' => $upload['tmp_name'] );
	}

	private function requested_row(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_apply validates its purpose-specific nonce first.
		$value = $_POST['row'] ?? null;
		if ( ! is_string( $value ) || ! ctype_digit( $value ) || (int) $value >= PackageBlueprint::MAX_PACKAGES ) {
			return null;
		}

		return (int) $value;
	}

	/** @return list<array{type:string,identifier:string}> */
	private function selected_packages(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_export validates its purpose-specific nonce first.
		$input = $_POST['packages'] ?? null;
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'plugin', 'theme' ) ) ) {
			throw new InvalidArgumentException();
		}

		$selected = array();
		$seen     = array();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$identifiers = $input[ $type ] ?? array();
			if ( ! is_array( $identifiers ) || ! array_is_list( $identifiers ) ) {
				throw new InvalidArgumentException();
			}
			foreach ( $identifiers as $identifier ) {
				if ( ! is_string( $identifier ) ) {
					throw new InvalidArgumentException();
				}
				$identifier = wp_unslash( $identifier );
				$key        = $type . "\0" . $identifier;
				if ( '' === $identifier || strlen( $identifier ) > 255 || 1 !== preg_match( '//u', $identifier )
					|| preg_match( '/[\x00-\x1F\x7F]/', $identifier ) || isset( $seen[ $key ] )
					|| count( $selected ) >= PackageBlueprint::MAX_PACKAGES ) {
					throw new InvalidArgumentException();
				}
				$seen[ $key ] = true;
				$selected[]   = compact( 'type', 'identifier' );
			}
		}
		if ( array() === $selected ) {
			throw new InvalidArgumentException();
		}

		return $selected;
	}

	/** @return array<string, list<string>> */
	private function selected_credentials(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_export validates its purpose-specific nonce first.
		$input = $_POST['credentials'] ?? array();
		if ( ! is_array( $input ) ) {
			throw new InvalidArgumentException();
		}

		$selected = array();
		$count    = 0;
		foreach ( $input as $provider => $ids ) {
			if ( ! is_string( $provider ) || 1 !== preg_match( '/\A[a-z][a-z0-9-]{0,31}\z/', $provider )
				|| ! is_array( $ids ) || ! array_is_list( $ids ) || array() === $ids ) {
				throw new InvalidArgumentException();
			}
			foreach ( $ids as $id ) {
				$id = is_string( $id ) ? wp_unslash( $id ) : '';
				if ( SecretsFile::CONSTANT_PROFILE === $id || 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/', $id )
					|| isset( $selected[ $provider ][ $id ] ) || ++$count > PackageBlueprint::MAX_CREDENTIALS ) {
					throw new InvalidArgumentException();
				}
				$selected[ $provider ][ $id ] = true;
			}
		}

		return array_map( 'array_keys', $selected );
	}

	private function requested_action(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_apply validates its purpose-specific nonce first.
		$value = $_POST['review_action'] ?? null;

		return is_string( $value ) && in_array( $value, array( 'install', 'adopt', 'managed', 'protected', 'blocked' ), true ) ? $value : null;
	}

	/** @return array<int, string> */
	private function target_credential_ids(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only from nonce-guarded Preview and Apply handlers.
		$input = $_POST['target_credentials'] ?? array();
		if ( ! is_array( $input ) || count( $input ) > PackageBlueprint::MAX_PACKAGES ) {
			throw new InvalidArgumentException();
		}

		$ids = array();
		foreach ( $input as $index => $value ) {
			$canonical = is_int( $index ) ? (string) $index : $index;
			if ( ! ctype_digit( $canonical ) || (string) (int) $canonical !== $canonical
				|| (int) $canonical >= PackageBlueprint::MAX_PACKAGES
				|| ! is_string( $value ) || SecretsFile::CONSTANT_PROFILE === wp_unslash( $value )
				|| ( '' !== $value && 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/', wp_unslash( $value ) ) ) ) {
				throw new InvalidArgumentException();
			}
			if ( '' !== $value ) {
				$ids[ (int) $index ] = wp_unslash( $value );
			}
		}

		return $ids;
	}

	/** @return array<int, array{action:BlueprintCredentialAction,target_id:?string}> */
	private function credential_decisions( PackageBlueprint $blueprint ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only from nonce-guarded Preview and Apply handlers.
		$input = $_POST['credential_decisions'] ?? array();
		if ( ! is_array( $input ) || count( $input ) > PackageBlueprint::MAX_CREDENTIALS ) {
			throw new InvalidArgumentException();
		}

		$decisions = array();
		foreach ( $input as $ordinal => $value ) {
			$canonical = is_int( $ordinal ) ? (string) $ordinal : $ordinal;
			if ( ! ctype_digit( $canonical ) || (string) (int) $canonical !== $canonical
				|| (int) $canonical >= count( $blueprint->credentials ) || ! is_array( $value ) || array_is_list( $value )
				|| array_diff( array_keys( $value ), array( 'action', 'target_id' ) ) ) {
				throw new InvalidArgumentException();
			}
			$action_value = $value['action'] ?? null;
			$action       = is_string( $action_value ) ? BlueprintCredentialAction::tryFrom( wp_unslash( $action_value ) ) : null;
			$target       = $value['target_id'] ?? null;
			if ( null === $action || ( BlueprintCredentialAction::TARGET === $action
					? ! is_string( $target ) || SecretsFile::CONSTANT_PROFILE === $target || 1 !== preg_match( '/\A[A-Za-z0-9_-]{3,64}\z/', wp_unslash( $target ) )
					: null !== $target ) ) {
				throw new InvalidArgumentException();
			}
			$decisions[ (int) $canonical ] = array(
				'action'    => $action,
				'target_id' => BlueprintCredentialAction::TARGET === $action ? wp_unslash( $target ) : null,
			);
		}

		return $decisions;
	}

	/**
	 * @param list<PackageProviderChoice>|null $provider_list
	 * @return array<string, mixed>
	 */
	private function row( BlueprintPlanItem $item, ?int $credential_ordinal = null, ?string $selected_credential_id = null, bool $credential_recovery = false, ?array &$provider_list = null ): array {
		$reason = $item->reason->message();
		if ( TargetPackageAction::ADOPT === $item->action
			&& TargetPackageReason::NONE === $item->reason
			&& null !== $item->package->release_configuration
			&& null === $item->package->selection ) {
			$reason = __( 'Repository verified. Release readiness will be checked when applying; files and activation will be preserved.', 'ran-booster' );
		}
		$row = array(
			'name'       => $item->package->display_name,
			'identifier' => $item->package->identifier,
			'type'       => 'plugin' === $item->package->type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' ),
			'action'     => $item->action->value,
			'reason'     => $reason,
		);
		if ( null !== $item->release_review ) {
			$row['release_review'] = $item->release_review;
		}
		if ( null !== $credential_ordinal ) {
			$row['credential_ordinal'] = $credential_ordinal;
		}
		if ( $credential_recovery ) {
			$row['credential_recovery'] = true;
		}

		if ( null === $credential_ordinal
			&& ( TargetPackageReason::CREDENTIAL_REQUIRED === $item->reason
				|| ( null !== $selected_credential_id && '' !== $selected_credential_id
					&& in_array( $item->action, array( TargetPackageAction::INSTALL, TargetPackageAction::ADOPT, TargetPackageAction::BLOCKED ), true ) ) ) ) {
			$provider_list   ??= $this->provider_settings->build_package_list();
			$row['credential'] = array(
				'choices'      => $this->credential_choices( $item->package->provider, $provider_list ),
				'selected_id'  => $selected_credential_id,
				'settings_url' => ProviderTabLinks::link( $this->links, admin_url( 'admin.php' ), $item->package->provider, '&view=credentials' ),
			);
		}

		return $row;
	}

	/**
	 * One codes-only line per applied row. The slug is derived as Core derives a stored package's slug, from an
	 * identifier that passed Core's package-identifier validation when the Blueprint was read.
	 */
	private function log_apply_result( ?BlueprintPackage $package, string $status, string $credential_state ): void {
		try {
			$slug = null === $package ? null : PackageDirectory::installation_slug( PackageDirectory::installed_slug( $package->type, $package->identifier ), null );
		} catch ( InvalidArgumentException ) {
			$slug = null;
		}
		BoosterLogger::log(
			'Blueprint package apply finished.',
			array_filter(
				array(
					'operation'    => 'portability_apply',
					'outcome_code' => $status,
					'state'        => $credential_state,
					'package_slug' => $slug,
				)
			)
		);
	}

	/** @return array{status:string,message:string,category?:string} */
	private function apply_failure( Throwable $failure ): array {
		BoosterLogger::log_exception(
			'portability package apply failed',
			$failure,
			array(
				'operation' => 'portability_apply',
				'step'      => 'apply',
			)
		);

		if ( $failure instanceof LocalSecretStoreUnavailable ) {
			return array(
				'status'           => 'failed',
				'category'         => LocalSecretStoreUnavailable::CATEGORY,
				'message'          => __( 'Target credential storage is unavailable. Configure secure storage, then review and apply this row again.', 'ran-booster' ),
				'credential_state' => 'unavailable',
			);
		}

		return array(
			'status'  => 'failed',
			'message' => $failure instanceof PackageStorageFailure
				? $failure->getMessage()
				: __( 'Booster could not apply this package. Review the Blueprint again and check repository access.', 'ran-booster' ),
		);
	}

	/**
	 * @param list<PackageProviderChoice> $provider_list
	 * @return list<array{id:string,label:string,source:string}>
	 */
	private function credential_choices( string $provider, array $provider_list ): array {

		foreach ( $provider_list as $candidate ) {
			if ( ( $candidate['code'] ?? null ) === $provider && is_array( $candidate['credential_profiles'] ?? null ) ) {
				return array_values( array_filter( $candidate['credential_profiles'], static fn ( array $credential ): bool => 'file' === ( $credential['source'] ?? null ) ) );
			}
		}

		return array();
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param list<array<string, mixed>> $credentials
	 * @param array<string,array{minimum:?string,declared:int,not_declared:int,missing:int}> $requirements_summary
	 */
	private function render_review( array $rows, array $credentials = array(), array $requirements_summary = array() ): string {

		$portability_review_rows          = $rows;
		$portability_requirements_summary = $requirements_summary;

		$portability_credential_rows = $credentials;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/portability-review.php';

		return (string) ob_get_clean();
	}

	/**
	 * @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $decisions
	 * @param list<BlueprintPlanItem> $items
	 * @param list<PackageProviderChoice>|null $provider_list
	 * @return list<array<string, mixed>>
	 */
	private function credential_rows( PackageBlueprint $blueprint, array $decisions, array $items, ?array $provider_list = null ): array {
		$providers = array();
		try {

			$provider_list ??= $this->provider_settings->build_package_list();
		} catch ( Throwable ) {
			$provider_list = array();
		}
		foreach ( $provider_list as $provider ) {
			if ( is_string( $provider['code'] ?? null ) ) {
				$providers[ $provider['code'] ] = $provider;
			}
		}
		$rows = array();
		foreach ( $blueprint->credentials as $ordinal => $credential ) {
			$provider       = $providers[ $credential->provider ] ?? array();
			$kind_labels    = is_array( $provider['credential_kind_labels'] ?? null ) ? $provider['credential_kind_labels'] : array();
			$packages       = array();
			$proposed_count = 0;
			$recovery_count = 0;
			foreach ( $blueprint->packages as $package_row => $package ) {
				if ( in_array(
					array(
						'type'       => $package->type,
						'identifier' => $package->identifier,
					),
					$credential->packages,
					true
				) ) {
					$projected  = array(
						'name' => $package->display_name,
						'type' => 'plugin' === $package->type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' ),
					);
					$packages[] = $projected;
					$action     = $items[ $package_row ]->action->value ?? null;
					if ( 'managed' === $action ) {
						++$recovery_count;
					} elseif ( 'protected' !== $action ) {
						++$proposed_count;
					}
				}
			}
			$decision = $decisions[ $ordinal ] ?? null;
			$rows[]   = array(
				'ordinal'           => $ordinal,
				'provider_label'    => is_string( $provider['label'] ?? null ) ? $provider['label'] : $credential->provider,
				'label'             => $credential->label,
				'kind'              => $credential->kind,
				'kind_label'        => is_string( $kind_labels[ $credential->kind ] ?? null ) ? $kind_labels[ $credential->kind ] : $credential->kind,
				'packages'          => $packages,
				'decision_required' => 0 < $proposed_count || 0 < $recovery_count,
				'proposed_count'    => $proposed_count,
				'recovery_count'    => $recovery_count,
				'action'            => $decision['action']->value ?? null,
				'target_id'         => $decision['target_id'] ?? null,
				'target_choices'    => array_values( array_filter( $provider['credential_profiles'] ?? array(), static fn ( array $candidate ): bool => 'file' === ( $candidate['source'] ?? null ) ) ),
				'settings_url'      => ProviderTabLinks::link( $this->links, admin_url( 'admin.php' ), $credential->provider, '&view=credentials' ),
			);
		}

		return $rows;
	}

	private function credential_ordinal_for_package( PackageBlueprint $blueprint, BlueprintPackage $package ): ?int {
		foreach ( $blueprint->credentials as $ordinal => $credential ) {
			if ( $credential->provider === $package->provider
				&& in_array(
					array(
						'type'       => $package->type,
						'identifier' => $package->identifier,
					),
					$credential->packages,
					true
				) ) {
				return $ordinal;
			}
		}

		return null;
	}
}
