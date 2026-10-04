<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\Logging\BoosterLogger;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintCredentialAction;
use RAN\Portability\BlueprintExportPackageFailure;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\LocalSecretStoreUnavailable;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Portability\PackageBlueprint;
use RAN\Portability\PortabilityApplicationService;
use RAN\Portability\TargetPackageAction;
use RAN\Portability\TargetPackageReason;
use RAN\Portability\UnsupportedBlueprintPackages;
use RAN\Runtime\RuntimeSupport;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PackageStorageFailure;
use Throwable;

/** Narrow administrator boundary for live portability export and review. */
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
		private ProviderSettingsPresenter $provider_settings
	) {
	}

	public function handle_export(): mixed {
		RuntimeSupport::assert_managed_operations_allowed();

		if ( ! $this->is_allowed( self::EXPORT_NONCE_ACTION ) ) {
			return $this->export_failure( __( 'Your Transporter export session expired. Reload the page and try again.', 'ran-booster' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- isAllowed validates this purpose-specific nonce before reading input.
		try {
			$credentials = $this->selected_credentials();
		} catch ( InvalidArgumentException ) {
			return $this->export_failure( __( 'The selected packages or repository credentials changed or are invalid. Reload this page and try again.', 'ran-booster' ), 400 );
		}
		$password       = $this->password_from_request( 'password' );
		$confirmation   = $this->password_from_request( 'password_confirmation' );
		$password_error = $this->export_password_error( array() !== $credentials, $password, $confirmation );

		if ( null !== $password_error ) {
			return $this->export_failure( $password_error, 400 );
		}

		try {
			$blueprint = $this->exporter->export( $credentials, $this->selected_packages() );
		} catch ( LocalSecretStoreUnavailable ) {
			return $this->export_failure( __( 'Encrypted credential storage is unavailable, so Booster did not export credentials.', 'ran-booster' ), 409 );
		} catch ( PackageStorageFailure $failure ) {
			return $this->export_failure( $failure->getMessage(), $failure->is_database_unsupported() ? 503 : 500 );
		} catch ( UnsupportedBlueprintPackages $failure ) {
			return $this->export_failure( $this->export_validation_failure_message( $failure ), 400 );
		} catch ( InvalidArgumentException ) {
			return $this->export_failure( __( 'The selected packages or repository credentials changed or are invalid. Reload this page and try again.', 'ran-booster' ), 400 );
		} catch ( Throwable ) {
			return $this->export_failure( __( 'Booster could not read the managed package selection. Please try again.', 'ran-booster' ), 500 );
		}

		try {
			if ( array() === $blueprint->credentials ) {
				$password = null;
			}
			$path = tempnam( sys_get_temp_dir(), 'ran-booster-blueprint-' );
			if ( false === $path ) {
				throw new InvalidArgumentException();
			}
			$this->archive->write_to( $path, $blueprint, $password );
		} catch ( Throwable ) {
			return $this->export_failure( __( 'Booster could not create the Transporter Blueprint ZIP. Please try again.', 'ran-booster' ), 500 );
		}

		try {
			$bytes = $this->archive_bytes( $path );
			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . BlueprintArchive::FILENAME . '"' );
			header( 'Content-Length: ' . (string) strlen( $bytes ) );
			echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Emits only the fully read, bounded ZIP after validation.
		} catch ( Throwable ) {
			return $this->export_failure( __( 'Booster could not read the Transporter Blueprint ZIP. Please try again.', 'ran-booster' ), 500 );
		} finally {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only this request's generated ZIP.
				unlink( $path );
			}
		}

		exit;
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
			return wp_send_json_error( array( 'message' => __( 'Your Transporter review session expired. Reload the page and try again.', 'ran-booster' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- isAllowed validates this purpose-specific nonce before reading the native upload.
		$upload = $this->uploaded_blueprint();
		if ( null === $upload ) {
			return wp_send_json_error( array( 'message' => __( 'Choose a valid Transporter Blueprint ZIP to review.', 'ran-booster' ) ), 400 );
		}

		try {
			$blueprint = $this->archive->read_from( $upload['tmp_name'], $this->password_from_request( 'password' ) );
			try {
				$decisions = $this->credential_decisions( $blueprint );
			} catch ( InvalidArgumentException ) {
				return wp_send_json_error( array( 'message' => __( 'The repository credential decisions are invalid. Review the Transporter Blueprint again.', 'ran-booster' ) ), 400 );
			}
			return $this->preview_success( $this->review_blueprint( $blueprint, $decisions, $this->target_credential_ids() ) );
		} catch ( PackageStorageFailure $failure ) {
			return wp_send_json_error( array( 'message' => $failure->getMessage() ), $failure->is_database_unsupported() ? 503 : 500 );
		} catch ( Throwable ) {
			return wp_send_json_error( array( 'message' => __( 'Booster could not read this Transporter Blueprint. If it includes credentials, enter its ZIP password and try again.', 'ran-booster' ) ), 400 );
		}
	}

	public function handle_apply(): mixed {
		RuntimeSupport::assert_managed_operations_allowed();

		if ( ! $this->is_allowed( self::APPLY_NONCE_ACTION ) ) {
			return wp_send_json_error( array( 'message' => __( 'Your Transporter apply session expired. Reload the page and try again.', 'ran-booster' ) ), 403 );
		}

		$upload = $this->uploaded_blueprint();
		$row    = $this->requested_row();
		if ( null === $upload || null === $row ) {
			return wp_send_json_error( array( 'message' => __( 'Choose the same Transporter Blueprint and package row to apply.', 'ran-booster' ) ), 400 );
		}

		try {
			$target_credentials = $this->target_credential_ids();
			$blueprint          = $this->archive->read_from( $upload['tmp_name'], $this->password_from_request( 'password' ) );
			$package            = $blueprint->packages[ $row ] ?? null;
			try {
				$decisions = $this->credential_decisions( $blueprint );
				if ( $package instanceof BlueprintPackage && $this->credential_ordinal_for_package( $blueprint, $package ) !== null && isset( $target_credentials[ $row ] ) ) {
					throw new InvalidArgumentException();
				}
			} catch ( InvalidArgumentException ) {
				return wp_send_json_error( array( 'message' => __( 'The repository credential decisions are invalid. Review the Transporter Blueprint again.', 'ran-booster' ) ), 400 );
			}
			$can_install = $package instanceof BlueprintPackage
				&& current_user_can( 'plugin' === $package->type ? 'install_plugins' : 'install_themes' );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_apply validates its purpose-specific nonce before reading adopt.
			return wp_send_json_success( $this->application->apply( $blueprint, $row, $this->requested_action(), $decisions, $target_credentials[ $row ] ?? null, ( '1' === (string) ( $_POST['adopt'] ?? '' ) ), $can_install ) );
		} catch ( PackageStorageFailure $failure ) {
			return wp_send_json_error( array( 'message' => $failure->getMessage() ), $failure->is_database_unsupported() ? 503 : 500 );
		} catch ( Throwable $failure ) {
			return wp_send_json_success( $this->apply_failure( $failure ) );
		}
	}

	/**
	 * @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $credential_decisions
	 * @param array<int, string> $target_credential_ids
	 */

	public function preview_file( string $path, ?string $password = null, array $credential_decisions = array(), array $target_credential_ids = array() ): string {
		RuntimeSupport::assert_managed_operations_allowed();

		$blueprint = $this->archive->read_from( $path, $password );

		return $this->review_blueprint( $blueprint, $credential_decisions, $target_credential_ids );
	}

	/**
	 * @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $credential_decisions
	 * @param array<int, string> $target_credential_ids
	 */
	private function review_blueprint( PackageBlueprint $blueprint, array $credential_decisions, array $target_credential_ids ): string {
		$items = $this->application->review( $blueprint, $credential_decisions, $target_credential_ids );
		$rows  = array();

		foreach ( $items as $index => $item ) {
			$credential_ordinal = $this->credential_ordinal_for_package( $blueprint, $item->package );
			$rows[]             = $this->row(
				$item,
				$credential_ordinal,
				$target_credential_ids[ $index ] ?? null,
				null !== $credential_ordinal
					&& BlueprintCredentialAction::IMPORT === ( $credential_decisions[ $credential_ordinal ]['action'] ?? null )
					&& TargetPackageAction::MANAGED === $item->action
			);
		}

		return $this->render_review( $rows, array() === $blueprint->credentials ? array() : $this->credential_rows( $blueprint, $credential_decisions, $items ) );
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

	private function is_allowed( string $nonce_action ): bool {
		return current_user_can( 'manage_options' ) && check_ajax_referer( $nonce_action, 'nonce', false );
	}

	private function export_failure( string $message, int $status ): mixed {
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
			return __( 'Choose a Transporter Blueprint password before exporting credentials.', 'ran-booster' );
		}
		if ( $password !== $confirmation ) {
			return __( 'The Transporter Blueprint passwords do not match. Nothing was exported.', 'ran-booster' );
		}

		return null;
	}

	private function export_validation_failure_message( UnsupportedBlueprintPackages $failure ): string {
		$items = array_map(
			function ( BlueprintExportPackageFailure $package_failure ): string {
				/* translators: 1: managed package type, such as Plugin. 2: managed package display name. */
				$message = __( '%1$s “%2$s” manages its own updates and cannot also be managed by Booster', 'ran-booster' );

				return sprintf(
					$message,
					'plugin' === $package_failure->type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' ),
					$package_failure->display_name
				);
			},
			$failure->failures
		);

		return sprintf(
			/* translators: %s: semicolon-separated list of selected packages that cannot be exported. */
			__( 'Transporter Blueprint export cannot include: %s. Deselect those packages and try again.', 'ran-booster' ),
			implode( '; ', $items )
		);
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only from the nonce-guarded Preview handler.
		$input = $_POST['target_credentials'] ?? array();
		if ( ! is_array( $input ) ) {
			return array();
		}

		$ids = array();
		foreach ( $input as $index => $value ) {
			if ( ( ! is_int( $index ) && ! ctype_digit( $index ) )
				|| (int) $index > PackageBlueprint::MAX_PACKAGES - 1
				|| ! is_string( $value ) || strlen( $value ) > 128 ) {
				continue;
			}
			$ids[ (int) $index ] = sanitize_text_field( wp_unslash( $value ) );
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

	/** @return array<string, mixed> */
	private function row( BlueprintPlanItem $item, ?int $credential_ordinal = null, ?string $selected_credential_id = null, bool $credential_recovery = false ): array {
		$row = array(
			'name'       => $item->package->display_name,
			'identifier' => $item->package->identifier,
			'type'       => 'plugin' === $item->package->type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' ),
			'action'     => $item->action->value,
			'category'   => $item->reason->value,
			'reason'     => $item->reason->message(),
		);
		if ( null !== $credential_ordinal ) {
			$row['credential_ordinal'] = $credential_ordinal;
		}
		if ( $credential_recovery ) {
			$row['credential_recovery'] = true;
		}

		if ( null === $credential_ordinal && TargetPackageReason::CREDENTIAL_REQUIRED === $item->reason ) {
			$row['credential'] = array(
				'choices'      => $this->credential_choices( $item->package->provider ),
				'selected_id'  => $selected_credential_id,
				'settings_url' => admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $item->package->provider ) ),
			);
		}

		return $row;
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
				'message'          => __( 'Target encrypted credential storage is unavailable. Configure secure storage, then review and apply this row again.', 'ran-booster' ),
				'credential_state' => 'unavailable',
			);
		}

		return array(
			'status'  => 'failed',
			'message' => $failure instanceof PackageStorageFailure
				? $failure->getMessage()
				: __( 'Booster could not apply this package. Review the Transporter Blueprint again and check repository access.', 'ran-booster' ),
		);
	}

	/** @return list<array{id:string,label:string,source:string}> */
	private function credential_choices( string $provider ): array {

		foreach ( $this->provider_settings->build_package_list() as $candidate ) {
			if ( ( $candidate['code'] ?? null ) === $provider && is_array( $candidate['credentials'] ?? null ) ) {
				return array_values( array_filter( $candidate['credentials'], static fn ( array $credential ): bool => 'file' === ( $credential['source'] ?? null ) ) );
			}
		}

		return array();
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param list<array<string, mixed>> $credentials
	 */
	private function render_review( array $rows, array $credentials = array() ): string {

		$portability_review_rows = $rows;

		$portability_credential_rows = $credentials;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/portability-review.php';

		return (string) ob_get_clean();
	}

	/**
	 * @param array<int, array{action:BlueprintCredentialAction,target_id:?string}> $decisions
	 * @param list<BlueprintPlanItem> $items
	 * @return list<array<string, mixed>>
	 */
	private function credential_rows( PackageBlueprint $blueprint, array $decisions, array $items ): array {
		$providers = array();
		try {

			$provider_list = $this->provider_settings->build_package_list();
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
			$provider        = $providers[ $credential->provider ] ?? array();
			$kind_labels     = is_array( $provider['credential_kind_labels'] ?? null ) ? $provider['credential_kind_labels'] : array();
			$packages        = array();
			$proposed_count  = 0;
			$recovery_count  = 0;
			$unchanged_count = 0;
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
						'row'  => $package_row,

						'name' => $package->display_name,
						'type' => 'plugin' === $package->type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' ),
					);
					$packages[] = $projected;
					$action     = $items[ $package_row ]->action->value ?? null;
					if ( 'managed' === $action ) {
						++$recovery_count;
						++$unchanged_count;
					} elseif ( 'protected' === $action ) {
						++$unchanged_count;
					} else {
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
				'unchanged_count'   => $unchanged_count,
				'action'            => $decision['action']->value ?? null,
				'target_id'         => $decision['target_id'] ?? null,
				'target_choices'    => array_values( array_filter( $provider['credentials'] ?? array(), static fn ( array $candidate ): bool => 'file' === ( $candidate['source'] ?? null ) ) ),
				'settings_url'      => admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $credential->provider ) . '&view=credentials' ),
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
