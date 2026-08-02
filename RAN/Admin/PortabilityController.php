<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\Logging\BoosterLogger;
use RAN\Portability\BlueprintArchive;
use RAN\Portability\BlueprintExportPackageFailure;
use RAN\Portability\BlueprintPackage;
use RAN\Portability\BlueprintPlanItem;
use RAN\Portability\LocalSecretStoreUnavailable;
use RAN\Portability\ManagedPackageBlueprintExporter;
use RAN\Portability\PackageBlueprint;
use RAN\Portability\PortabilityApplicationService;
use RAN\Portability\TargetPackageReason;
use RAN\Portability\UnsupportedBlueprintPackages;
use RAN\Runtime\RuntimeSupport;
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
		private ProviderSettingsPresenter $providerSettings
	) {
	}

	public function handleExport(): mixed {
		RuntimeSupport::assertManagedOperationsAllowed();

		if ( ! $this->isAllowed( self::EXPORT_NONCE_ACTION ) ) {
			return $this->exportFailure( __( 'Your Transporter export session expired. Reload the page and try again.', 'ran-booster' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- isAllowed validates this purpose-specific nonce before reading input.
		$includeCredentials = isset( $_POST['include_credentials'] ) && '1' === $_POST['include_credentials'];
		$password           = $this->passwordFromRequest( 'password' );
		$confirmation       = $this->passwordFromRequest( 'password_confirmation' );
		$passwordError      = $this->exportPasswordError( $includeCredentials, $password, $confirmation );

		if ( null !== $passwordError ) {
			return $this->exportFailure( $passwordError, 400 );
		}

		try {
			$blueprint = $this->exporter->export( $includeCredentials, $this->selectedPackages() );
		} catch ( LocalSecretStoreUnavailable ) {
			return $this->exportFailure( __( 'Encrypted credential storage is unavailable, so Booster did not export credentials.', 'ran-booster' ), 409 );
		} catch ( PackageStorageFailure $failure ) {
			return $this->exportFailure( $failure->getMessage(), $failure->isDatabaseUnsupported() ? 503 : 500 );
		} catch ( UnsupportedBlueprintPackages $failure ) {
			return $this->exportFailure( $this->exportValidationFailureMessage( $failure ), 400 );
		} catch ( InvalidArgumentException ) {
			return $this->exportFailure( __( 'The selected managed packages changed or are invalid. Reload this page and try again.', 'ran-booster' ), 400 );
		} catch ( Throwable ) {
			return $this->exportFailure( __( 'Booster could not read the managed package selection. Please try again.', 'ran-booster' ), 500 );
		}

		try {
			if ( array() === $blueprint->credentials ) {
				$password = null;
			}
			$path = tempnam( sys_get_temp_dir(), 'ran-booster-blueprint-' );
			if ( false === $path ) {
				throw new InvalidArgumentException();
			}
			$this->archive->writeTo( $path, $blueprint, $password );
		} catch ( Throwable ) {
			return $this->exportFailure( __( 'Booster could not create the Transporter Blueprint ZIP. Please try again.', 'ran-booster' ), 500 );
		}

		try {
			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . BlueprintArchive::FILENAME . '"' );
			header( 'Content-Length: ' . (string) filesize( $path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams only this just-created, administrator-requested ZIP.
			readfile( $path );
		} finally {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only this request's generated ZIP.
				unlink( $path );
			}
		}

		exit;
	}

	public function handlePreview(): mixed {
		RuntimeSupport::assertManagedOperationsAllowed();

		if ( ! $this->isAllowed( self::PREVIEW_NONCE_ACTION ) ) {
			return wp_send_json_error( array( 'message' => __( 'Your Transporter review session expired. Reload the page and try again.', 'ran-booster' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- isAllowed validates this purpose-specific nonce before reading the native upload.
		$upload = $this->uploadedBlueprint();
		if ( null === $upload ) {
			return wp_send_json_error( array( 'message' => __( 'Choose a valid Transporter Blueprint ZIP to review.', 'ran-booster' ) ), 400 );
		}

		try {
			return wp_send_json_success( array( 'html' => $this->previewFile( $upload['tmp_name'], $this->passwordFromRequest( 'password' ), $this->targetCredentialIds() ) ) );
		} catch ( PackageStorageFailure $failure ) {
			return wp_send_json_error( array( 'message' => $failure->getMessage() ), $failure->isDatabaseUnsupported() ? 503 : 500 );
		} catch ( Throwable ) {
			return wp_send_json_error( array( 'message' => __( 'Booster could not read this Transporter Blueprint. If it includes credentials, enter its ZIP password and try again.', 'ran-booster' ) ), 400 );
		}
	}

	public function handleApply(): mixed {
		RuntimeSupport::assertManagedOperationsAllowed();

		if ( ! $this->isAllowed( self::APPLY_NONCE_ACTION ) ) {
			return wp_send_json_error( array( 'message' => __( 'Your Transporter apply session expired. Reload the page and try again.', 'ran-booster' ) ), 403 );
		}

		$upload = $this->uploadedBlueprint();
		$row    = $this->requestedRow();
		if ( null === $upload || null === $row ) {
			return wp_send_json_error( array( 'message' => __( 'Choose the same Transporter Blueprint and package row to apply.', 'ran-booster' ) ), 400 );
		}

		try {
			$targetCredentials = $this->targetCredentialIds();
			$blueprint         = $this->archive->readFrom( $upload['tmp_name'], $this->passwordFromRequest( 'password' ) );
			$package           = $blueprint->packages[ $row ] ?? null;
			$canInstall        = $package instanceof BlueprintPackage
				&& current_user_can( 'plugin' === $package->type ? 'install_plugins' : 'install_themes' );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handleApply validates its purpose-specific nonce before reading adopt.
			return wp_send_json_success( $this->application->apply( $blueprint, $row, $this->requestedAction(), $targetCredentials[ $row ] ?? null, '1' === (string) ( $_POST['adopt'] ?? '' ), $canInstall ) );
		} catch ( PackageStorageFailure $failure ) {
			return wp_send_json_error( array( 'message' => $failure->getMessage() ), $failure->isDatabaseUnsupported() ? 503 : 500 );
		} catch ( Throwable $failure ) {
			return wp_send_json_success( $this->applyFailure( $failure ) );
		}
	}

	/** @param array<int, string> $targetCredentialIds */
	public function previewFile( string $path, ?string $password = null, array $targetCredentialIds = array() ): string {
		RuntimeSupport::assertManagedOperationsAllowed();

		$blueprint = $this->archive->readFrom( $path, $password );
		$items     = $this->application->review( $blueprint, $targetCredentialIds );
		$rows      = array();

		foreach ( $items as $index => $item ) {
			$rows[] = $this->row( $item, $targetCredentialIds[ $index ] ?? null );
		}

		return $this->renderReview( $rows );
	}

	private function isAllowed( string $nonceAction ): bool {
		return current_user_can( 'manage_options' ) && check_ajax_referer( $nonceAction, 'nonce', false );
	}

	private function exportFailure( string $message, int $status ): mixed {
		if ( $this->isInlineExportRequest() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_send_json_error serialises the message and applies the integer HTTP status.
			return wp_send_json_error( array( 'message' => $message ), $status );
		}

		wp_die( esc_html( $message ), '', array( 'response' => absint( $status ) ) );
	}

	private function isInlineExportRequest(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This only selects the response shape after handleExport has validated its nonce.
		$format = $_POST['response_format'] ?? null;

		return is_string( $format ) && 'json' === $format;
	}

	private function passwordFromRequest( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only from handlers guarded by isAllowed.
		$value = $_POST[ $key ] ?? null;

		return is_string( $value ) && '' !== $value ? wp_unslash( $value ) : null;
	}

	private function exportPasswordError( bool $includeCredentials, ?string $password, ?string $confirmation ): ?string {
		if ( ! $includeCredentials ) {
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

	private function exportValidationFailureMessage( UnsupportedBlueprintPackages $failure ): string {
		$items = array_map(
			function ( BlueprintExportPackageFailure $packageFailure ): string {
				/* translators: 1: managed package type, such as Plugin. 2: managed package display name. */
				$message = __( '%1$s “%2$s” manages its own updates and cannot also be managed by Booster', 'ran-booster' );

				return sprintf(
					$message,
					'plugin' === $packageFailure->type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' ),
					$packageFailure->displayName
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
	private function uploadedBlueprint(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller validates its operation nonce first.
		$upload = $_FILES['blueprint'] ?? null;
		if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== ( $upload['error'] ?? null )
			|| ! is_string( $upload['tmp_name'] ?? null ) || ! is_uploaded_file( $upload['tmp_name'] )
			|| ! is_string( $upload['name'] ?? null ) || ! str_ends_with( strtolower( $upload['name'] ), '.zip' ) ) {
			return null;
		}

		return array( 'tmp_name' => $upload['tmp_name'] );
	}

	private function requestedRow(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handleApply validates its purpose-specific nonce first.
		$value = $_POST['row'] ?? null;
		if ( ! is_string( $value ) || ! ctype_digit( $value ) || (int) $value >= PackageBlueprint::MAX_PACKAGES ) {
			return null;
		}

		return (int) $value;
	}

	/** @return list<array{type:string,identifier:string}> */
	private function selectedPackages(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handleExport validates its purpose-specific nonce first.
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

	private function requestedAction(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handleApply validates its purpose-specific nonce first.
		$value = $_POST['review_action'] ?? null;

		return is_string( $value ) && in_array( $value, array( 'install', 'adopt', 'managed', 'protected', 'blocked' ), true ) ? $value : null;
	}

	/** @return array<int, string> */
	private function targetCredentialIds(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only from the nonce-guarded Preview handler.
		$input = $_POST['target_credentials'] ?? array();
		if ( ! is_array( $input ) ) {
			return array();
		}

		$ids = array();
		foreach ( $input as $index => $value ) {
			if ( ( ! is_int( $index ) && ( ! is_string( $index ) || ! ctype_digit( $index ) ) )
				|| (int) $index > PackageBlueprint::MAX_PACKAGES - 1
				|| ! is_string( $value ) || strlen( $value ) > 128 ) {
				continue;
			}
			$ids[ (int) $index ] = sanitize_text_field( wp_unslash( $value ) );
		}

		return $ids;
	}

	/** @return array<string, mixed> */
	private function row( BlueprintPlanItem $item, ?string $selectedCredentialId = null ): array {
		$row = array(
			'name'       => $item->package->displayName,
			'identifier' => $item->package->identifier,
			'type'       => 'plugin' === $item->package->type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' ),
			'action'     => $item->action->value,
			'category'   => $item->reason->value,
			'reason'     => $item->reason->message(),
		);

		if ( TargetPackageReason::CREDENTIAL_REQUIRED === $item->reason ) {
			$row['credential'] = array(
				'choices'      => $this->credentialChoices( $item->package->provider ),
				'selected_id'  => $selectedCredentialId,
				'settings_url' => admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( $item->package->provider ) ),
			);
		}

		return $row;
	}

	/** @return array{status:string,message:string,category?:string} */
	private function applyFailure( Throwable $failure ): array {
		BoosterLogger::logException(
			'portability package apply failed',
			$failure,
			array(
				'operation' => 'portability_apply',
				'step'      => 'apply',
			)
		);

		if ( $failure instanceof LocalSecretStoreUnavailable ) {
			return array(
				'status'   => 'failed',
				'category' => LocalSecretStoreUnavailable::CATEGORY,
				'message'  => __( 'Target encrypted credential storage is unavailable. Configure secure storage, then review and apply this row again.', 'ran-booster' ),
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
	private function credentialChoices( string $provider ): array {
		foreach ( $this->providerSettings->buildPackageList() as $candidate ) {
			if ( $provider === ( $candidate['code'] ?? null ) && is_array( $candidate['credentials'] ?? null ) ) {
				return array_values( $candidate['credentials'] );
			}
		}

		return array();
	}

	/** @param list<array<string, mixed>> $rows */
	private function renderReview( array $rows ): string {
		$portabilityReviewRows = $rows;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/portability-review.php';

		return (string) ob_get_clean();
	}
}
