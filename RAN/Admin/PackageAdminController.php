<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use LogicException;
use RAN\Dashboard;
use RAN\Deployment\DeploymentStorageFailure;
use RAN\Deployment\PackageMutationGuard;
use RAN\Package;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\RepositoryProvider\{InvalidProviderCode, ProviderRegistry, UnknownProvider, UnsupportedProviderCapability};
use RAN\Storage\{PackageStorageFailure, PluginRepository, ThemeRepository};
use RuntimeException;
use Throwable;
use WP_Error;

/** @internal Core package-mutation request and signed-feedback owner. */
final class PackageAdminController {

	public function __construct(
		private ?PackageOperationService $operations = null,
		private ?PackageRepositoryRequestResolver $repositories = null,
		private ?PluginRepository $plugins = null,
		private ?ThemeRepository $themes = null,
		private ?ProviderRegistry $providers = null,
		private ?DeploymentAdminPresenter $deployments = null,
		private ?BulkPackageActionService $bulkActions = null
	) {
	}

	/** @param array<string, mixed> $request */
	public function manage( Dashboard $dashboard, string $action, array $request, bool $postRequest ): bool|string {
		if ( ! $postRequest ) {
			return false;
		}
		$capabilities = match ( $action ) {
			'install-plugin' => array( 'install_plugins' ),
			'install-theme' => array( 'install_themes' ),
			'edit-plugin', 'update-plugin', 'unlink-plugin' => array( 'update_plugins' ),
			'edit-theme', 'update-theme', 'unlink-theme' => array( 'update_themes' ),
			'unlink-delete-plugin' => array( 'update_plugins', 'delete_plugins', 'activate_plugins' ),
			'unlink-delete-theme' => array( 'update_themes', 'delete_themes' ),
			default => array(),
		};
		if ( array() === $capabilities ) {
			return false;
		}
		foreach ( $capabilities as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ran-booster' ) );
			}
		}

		check_admin_referer( $action );
		$reinstall = in_array( $action, array( 'edit-plugin', 'edit-theme' ), true )
			&& $this->enabled( $request, 'reinstall_after_save' );
		if ( $reinstall ) {
			check_admin_referer( str_replace( 'edit-', 'update-', $action ), '_ran_booster_reinstall_nonce' );
		}
		try {
			PackageMutationGuard::assertAdminActionAllowed( $action, $request );
		} catch ( RuntimeException $failure ) {
			$dashboard->addFailureMessage(
				new WP_Error( 'ran_booster_unsupported_package_operation', $failure->getMessage() ),
				$failure,
				array(
					'operation' => $action,
					'step'      => 'package_mutation_guard',
				)
			);
			return false;
		}
		if ( in_array( $action, array( 'edit-plugin', 'edit-theme' ), true ) && ! $this->storedProviderAvailable( $dashboard, $action, $request ) ) {
			return false;
		}
		if ( in_array( $action, array( 'install-plugin', 'install-theme', 'edit-plugin', 'edit-theme' ), true ) ) {
			$request = $this->resolve( $dashboard, $request );
			if ( null === $request ) {
				return false;
			}
		}

		return $dashboard->postPackageOperation( $action, $request );
	}

	/** @param array<string, mixed> $request */
	public function manageBulk(
		Dashboard $dashboard,
		string $action,
		array $request,
		bool $postRequest
	): ?string {
		if ( ! $postRequest || ! in_array( $action, array( 'bulk-plugin', 'bulk-theme' ), true ) ) {
			return null;
		}
		$type       = 'bulk-plugin' === $action ? 'plugin' : 'theme';
		$request    = wp_unslash( $request );
		$operation  = is_string( $request['bulk_action'] ?? null ) ? sanitize_key( $request['bulk_action'] ) : BulkPackageAction::QUEUE_UPDATE;
		$capability = 'plugin' === $type
			? match ( $operation ) {
				BulkPackageAction::ACTIVATE_PLUGINS => 'activate_plugins',
				BulkPackageAction::DEACTIVATE_PLUGINS => 'deactivate_plugins',
				default => 'update_plugins',
			}
			: 'update_themes';
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to manage these packages.', 'ran-booster' ) );
		}
		check_admin_referer( $action );
		$selected = is_array( $request['identifiers'] ?? null )
			? min( count( $request['identifiers'] ), BulkPackageAction::MAX_IDENTIFIERS )
			: 0;
		try {
			if ( null === $this->bulkActions ) {
				throw new RuntimeException( 'Bulk package actions are unavailable.' );
			}
			$result = $this->bulkActions->execute( BulkPackageAction::fromInput( $type, $request ) );
		} catch ( InvalidArgumentException $failure ) {
			\RAN\Logging\BoosterLogger::logException(
				'bulk package action rejected',
				$failure,
				array(
					'operation' => $operation,
					'step'      => 'bulk_package_action',
				)
			);
			$result = BulkPackageResult::error( $operation, $selected, 'invalid_request' );
		} catch ( BulkPackageActionFailure $failure ) {
			\RAN\Logging\BoosterLogger::logException(
				'bulk package action failed',
				$failure,
				array(
					'operation'    => $operation,
					'outcome_code' => $failure->reason,
					'step'         => 'bulk_package_action',
				)
			);
			$result = BulkPackageResult::error( $operation, $selected, $failure->reason );
		} catch ( Throwable $failure ) {
			\RAN\Logging\BoosterLogger::logException( 'bulk package action failed', $failure, array( 'step' => 'bulk_package_action' ) );
			$result = BulkPackageResult::error( $operation, $selected, 'unavailable' );
		}

		return $dashboard->bulkPackageRedirect( $type, $result );
	}

	/**
	 * @param array<string, mixed>  $request
	 * @param array<string, string> $listArguments
	 * @param \Closure(WP_Error|array<string, mixed>, array<string, string>): void $addContextMessage
	 */
	public function perform(
		Dashboard $dashboard,
		string $action,
		array $request,
		array $listArguments,
		\Closure $addContextMessage
	): bool|string {
		try {
			if ( null === $this->operations ) {
				throw new LogicException( 'Package operations are not configured.' );
			}
			$operation = PackageOperation::fromInput( $action, $request );
			$reinstall = 'edit' === $operation->operation
				&& $this->enabled( $request, 'reinstall_after_save' );
			$result    = $this->operations->execute( $operation );
			if ( $reinstall && 'edited' === ( $result['status'] ?? null ) && ( $result['package'] ?? null ) instanceof Package ) {
				$dashboard->addMessage(
					array(
						'type'    => 'info',
						'message' => __( 'Package settings were saved before the reinstall.', 'ran-booster' ),
					)
				);
				$operation = PackageOperation::updateFromSavedPackage( $operation, $result['package'] );
				$action    = 'update-' . $operation->packageType;
				$result    = $this->operations->execute( $operation );
			}
		} catch ( PackageStorageFailure $failure ) {
			status_header( 400 );
			$dashboard->addFailureMessage(
				new WP_Error( $failure->getDiagnosticId(), $failure->getMessage(), array( 'recovery_required' => $failure->isRecoveryRequired() ) ),
				$failure,
				array(
					'operation' => $action,
					'step'      => 'package_storage',
				)
			);
			return false;
		} catch ( DeploymentStorageFailure $failure ) {
			return null !== $failure->getActiveCorrelationId()
				? $this->activeDeployment( $dashboard, $failure, $action )
				: $this->manualFailure( $dashboard, $addContextMessage, $failure, $action );
		} catch ( Throwable $failure ) {
			return $this->manualFailure( $dashboard, $addContextMessage, $failure, $action );
		}

		$installAnother   = 'install' === $operation->operation && $this->enabled( $request, 'install_another' );
		$returnToSettings = $reinstall || ( 'update' === $operation->operation && $this->enabled( $request, 'return_to_settings' ) );
		$status           = $result['status'] ?? null;
		if ( in_array( $status, array( 'succeeded', 'edited', 'linked' ), true ) && ( $result['package'] ?? null ) instanceof Package ) {
			return $this->successRedirect( $operation, $result['package'], $listArguments, $installAnother, $returnToSettings || 'edited' === $status );
		}
		if ( in_array( $status, array( 'unlinked', 'deleted' ), true ) ) {
			return $this->successRedirect( $operation, (string) $operation->identifier, $listArguments );
		}
		if ( 'conflict' === $status ) {
			status_header( 409 );
			$addContextMessage(
				new WP_Error( 'ran_booster_package_edit_conflict', 'Package settings changed after this page was loaded. No settings were saved. Review the refreshed current settings, then resubmit your attempted changes.' ),
				array(
					'operation'    => $operation->operation,
					'package_type' => $operation->packageType,
					'step'         => 'package_edit_conflict',
				)
			);
			return false;
		}
		if ( 'failed' === $status && array_key_exists( 'correlation_id', $result ) ) {
			return $this->terminalDeploymentFailure( $dashboard, $addContextMessage, $result, $action );
		}
		if ( 'failed' === $status && is_string( $result['outcome_code'] ?? null ) ) {
			$this->removalFailure( $operation, $result['outcome_code'], $addContextMessage );
			return false;
		}

		return $this->manualFailure( $dashboard, $addContextMessage, null, $action );
	}

	/** @return array{operation: string, identifier: string}|null */
	public function addSuccessNotice( Dashboard $dashboard, string $type ): ?array {
		foreach ( array( 'ran_booster_result', 'ran_booster_package', '_ran_booster_notice_nonce' ) as $key ) {
			if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The complete marker is verified below.
				return null;
			}
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only signed feedback.
		$operation  = sanitize_key( wp_unslash( (string) $_GET['ran_booster_result'] ) );
		$identifier = sanitize_text_field( wp_unslash( (string) $_GET['ran_booster_package'] ) );
		$nonce      = wp_unslash( (string) $_GET['_ran_booster_notice_nonce'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $operation, array( 'install', 'update', 'edit', 'unlink', 'unlink-and-delete' ), true )
			|| false === wp_verify_nonce( $nonce, 'ran-booster-package-success|' . $type . '|' . $operation . '|' . $identifier ) ) {
			return null;
		}
		$completed = match ( $operation ) {
			'install' => __( 'installed', 'ran-booster' ),
			'update' => __( 'updated', 'ran-booster' ),
			'edit' => __( 'saved', 'ran-booster' ),
			'unlink' => __( 'unlinked', 'ran-booster' ),
			default => __( 'unlinked and deleted', 'ran-booster' ),
		};
		$dashboard->addMessage(
			array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: package type, 2: completed operation. */
					__( '%1$s was successfully %2$s.', 'ran-booster' ),
					'plugin' === $type ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' ),
					$completed
				),
			)
		);

		return compact( 'operation', 'identifier' );
	}

	/** @param array<string, string> $listArguments */
	public function bulkRedirect( string $type, BulkPackageResult $result, array $listArguments ): string {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| ( 'theme' === $type && in_array( $result->operation, BulkPackageAction::pluginActivationOperations(), true ) ) ) {
			throw new LogicException( 'The bulk package redirect type is invalid.' );
		}
		$data = $result->noticeData();
		$args = array();
		foreach ( $data as $key => $value ) {
			$args[ 'ran_booster_bulk_' . $key ] = $value;
		}
		$args['_ran_booster_bulk_notice_nonce'] = wp_create_nonce( $this->bulkNoticeAction( $type, $data ) );
		$adminUrl                               = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );

		return $adminUrl . '?' . http_build_query(
			array( 'page' => 'ran-booster-' . $type . 's' ) + $listArguments + $args,
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/** @param \Closure(array<string, mixed>, array<string, string>): void $addContextMessage */
	public function addBulkNotice( Dashboard $dashboard, string $type, \Closure $addContextMessage ): void {
		$data = array();
		foreach ( array( 'operation', 'selected', 'changed', 'unchanged', 'queued', 'skips', 'runner', 'error' ) as $key ) {
			$queryKey = 'ran_booster_bulk_' . $key;
			if ( ! isset( $_GET[ $queryKey ] ) || ! is_scalar( $_GET[ $queryKey ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The complete marker is verified below.
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The complete marker is verified below.
			$data[ $key ] = wp_unslash( (string) $_GET[ $queryKey ] );
		}
		if ( ! isset( $_GET['_ran_booster_bulk_notice_nonce'] ) || ! is_scalar( $_GET['_ran_booster_bulk_notice_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The complete marker is verified below.
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification is the purpose of this read.
		$nonce = wp_unslash( (string) $_GET['_ran_booster_bulk_notice_nonce'] );
		if ( false === wp_verify_nonce( $nonce, $this->bulkNoticeAction( $type, $data ) ) ) {
			return;
		}
		try {
			$result = BulkPackageResult::fromNoticeData( $data );
		} catch ( InvalidArgumentException ) {
			return;
		}

		$plural = 'plugin' === $type ? __( 'plugins', 'ran-booster' ) : __( 'themes', 'ran-booster' );
		if ( '' !== $result->errorCode ) {
			$errors = array(
				'credential_unavailable' => __( 'A selected package does not have its required repository credential.', 'ran-booster' ),
				'invalid_request'        => __( 'Choose a bulk action and a supported number of managed packages.', 'ran-booster' ),
				'provider_unavailable'   => __( 'A selected package uses an unavailable repository provider.', 'ran-booster' ),
				'stale'                  => __( 'A selected managed package changed or is no longer available. Refresh and try again.', 'ran-booster' ),
				'unavailable'            => __( 'Booster could not safely complete this bulk action. No success was reported.', 'ran-booster' ),
				'webhook_unavailable'    => __( 'A selected package provider does not support Automatic deployment.', 'ran-booster' ),
			);
			$addContextMessage(
				array(
					'type'    => 'error',
					'message' => $errors[ $result->errorCode ] ?? $errors['unavailable'],
					'code'    => 'ran_booster_bulk_' . $result->errorCode,
				),
				array(
					'operation'    => $result->operation,
					'outcome_code' => $result->errorCode,
					'step'         => 'bulk_package_action',
				)
			);
			return;
		}
		if ( BulkPackageAction::QUEUE_UPDATE === $result->operation ) {
			$message = sprintf(
				/* translators: 1: queued count, 2: package type, 3: skipped count. */
				__( 'Queued %1$d %2$s for sequential branch reinstall. Skipped: %3$d.', 'ran-booster' ),
				$result->queued,
				$plural,
				$result->skipped()
			);
			$message = $this->appendBulkReasons(
				$message,
				$result->skippedByReason,
				array(
					'busy'                   => __( 'already queued, running, or needs attention', 'ran-booster' ),
					'credential_unavailable' => __( 'credential unavailable', 'ran-booster' ),
					'disabled'               => __( 'deployment disabled', 'ran-booster' ),
					'provider_unavailable'   => __( 'provider unavailable', 'ran-booster' ),
					'release_source'         => __( 'published-release source', 'ran-booster' ),
					'self_update'            => __( 'Booster self-update blocked', 'ran-booster' ),
					'stale'                  => __( 'selection stale', 'ran-booster' ),
				)
			);
			if ( 'unavailable' === $result->runnerStatus && $result->queued > 0 ) {
				$message .= ' ' . __( 'The updates remain queued, but WordPress could not schedule the deployment runner. Open Troubleshooting to request it.', 'ran-booster' );
			}
			$addContextMessage(
				array(
					'type'            => $result->skipped() > 0 || 'unavailable' === $result->runnerStatus ? 'warning' : 'success',
					'message'         => $message,
					'code'            => 'bulk_update_queue',
					'queued_updates'  => $result->queued,
					'skipped_updates' => $result->skipped(),
				),
				array(
					'operation' => $result->operation,
					'step'      => 'bulk_package_action',
				)
			);
			return;
		}
		if ( in_array( $result->operation, BulkPackageAction::pluginActivationOperations(), true ) ) {
			$enabled = BulkPackageAction::ACTIVATE_PLUGINS === $result->operation;
			$message = sprintf(
				/* translators: 1: changed count, 2: enabled or disabled label, 3: unchanged count, 4: skipped count. */
				__( 'Changed %1$d plugins to %2$s in WordPress. Already in that state: %3$d. Skipped: %4$d.', 'ran-booster' ),
				$result->changed,
				$enabled ? __( 'Enabled', 'ran-booster' ) : __( 'Disabled', 'ran-booster' ),
				$result->unchanged,
				$result->skipped()
			);
			$message = $this->appendBulkReasons(
				$message,
				$result->skippedByReason,
				array(
					'active_dependents'   => __( 'required by active plugins', 'ran-booster' ),
					'activation_failed'   => __( 'activation failed', 'ran-booster' ),
					'deactivation_failed' => __( 'deactivation failed', 'ran-booster' ),
					'permission'          => __( 'permission denied', 'ran-booster' ),
					'self_deactivation'   => __( 'Booster cannot disable itself', 'ran-booster' ),
					'stale'               => __( 'selection stale', 'ran-booster' ),
				)
			);
			$addContextMessage(
				array(
					'type'    => $result->skipped() > 0 ? 'warning' : 'success',
					'message' => $message,
					'code'    => 'bulk_plugin_state',
				),
				array(
					'operation' => $result->operation,
					'step'      => 'bulk_package_action',
				)
			);
			return;
		}

		$policyLabel = match ( $result->operation ) {
			BulkPackageAction::POLICY_DISABLED => __( 'Disabled', 'ran-booster' ),
			BulkPackageAction::POLICY_AUTOMATIC => __( 'Automatic', 'ran-booster' ),
			default => __( 'Manual', 'ran-booster' ),
		};
		$dashboard->addMessage(
			array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: changed count, 2: package type, 3: policy label, 4: unchanged count. */
					__( 'Changed %1$d %2$s to %3$s. Already in that state: %4$d.', 'ran-booster' ),
					$result->changed,
					$plural,
					$policyLabel,
					$result->unchanged
				),
			)
		);
	}

	/** @param array<string, mixed> $request */
	private function storedProviderAvailable( Dashboard $dashboard, string $action, array $request ): bool {
		try {
			$identifier = $request[ 'edit-plugin' === $action ? 'file' : 'stylesheet' ] ?? null;
			if ( ! is_string( $identifier ) || '' === trim( $identifier ) ) {
				throw new InvalidArgumentException( 'The managed package identifier is required.' );
			}
			if ( null === $this->providers ) {
				throw new RuntimeException( 'The managed package provider registry is unavailable.' );
			}
			$package = 'edit-plugin' === $action
				? $this->plugins?->boosterPluginFromFile( $identifier )
				: $this->themes?->boosterThemeFromStylesheet( $identifier );
			if ( ! $package instanceof Package ) {
				throw new RuntimeException( 'The managed package repository is unavailable.' );
			}
			$this->providers->get( $package->getProviderCode() );
			return true;
		} catch ( InvalidProviderCode | UnknownProvider $failure ) {
			$message = 'This package cannot be edited until its stored repository provider is registered again.';
		} catch ( Throwable $failure ) {
			$message = 'Booster could not verify the managed package provider. No changes were made.';
		}
		$dashboard->addFailureMessage(
			new WP_Error( 'ran_booster_unavailable_package_provider', $message ),
			$failure,
			array(
				'operation' => $action,
				'step'      => 'package_edit_guard',
			)
		);
		return false;
	}

	/** @param array<string, mixed> $request @return array<string, mixed>|null */
	private function resolve( Dashboard $dashboard, array $request ): ?array {
		try {
			return $this->repositories?->resolve( $request ) ?? throw new RuntimeException( 'Package repository resolution is unavailable.' );
		} catch ( InvalidProviderCode | UnknownProvider $failure ) {
			$message = 'The selected repository provider is not available.';
		} catch ( UnsupportedProviderCapability $failure ) {
			$message = 'The selected repository provider does not yet support this package operation.';
		} catch ( InvalidArgumentException $failure ) {
			$message = 'Check the repository provider, account, repository, and credential fields.';
		} catch ( RuntimeException $failure ) {
			$message = match ( $failure->getCode() ) {
				401 => 'The repository provider rejected the selected credential.',
				403 => 'The repository provider denied access. Check credential permissions or rate limits.',
				404 => 'The repository could not be found, or the selected credential cannot access it.',
				429 => 'The repository provider rate limit has been reached. Try again later.',
				default => 'Booster could not verify the repository. Please try again.',
			};
		} catch ( Throwable $failure ) {
			$message = 'Booster could not verify the repository. Please try again.';
		}
		$context = array(
			'operation' => is_string( $request['action'] ?? null ) ? sanitize_key( wp_unslash( $request['action'] ) ) : '',
			'step'      => 'package_repository_resolve',
		);
		if ( is_string( $request['provider'] ?? null ) && preg_match( '/^[a-z0-9][a-z0-9_-]{0,31}$/D', $request['provider'] ) === 1 ) {
			$context['provider'] = $request['provider'];
		}
		$dashboard->addFailureMessage( new WP_Error( 'ran_booster_repository_error', $message ), $failure, $context );
		return null;
	}

	/** @param array<string, string> $listArguments */
	private function successRedirect( PackageOperation $operation, Package|string $package, array $listArguments, bool $installAnother = false, bool $returnToSettings = false ): string {
		$identifier = $package instanceof Package ? $package->getIdentifier() : $package;
		if ( ! is_string( $identifier ) || '' === $identifier ) {
			throw new LogicException( 'The deployed package identity is unavailable.' );
		}
		$type = $operation->packageType;
		$args = array(
			'page'                      => $installAnother ? 'ran-booster-' . $type . 's-create' : 'ran-booster-' . $type . 's',
			'ran_booster_result'        => $operation->operation,
			'ran_booster_package'       => $identifier,
			'_ran_booster_notice_nonce' => wp_create_nonce( 'ran-booster-package-success|' . $type . '|' . $operation->operation . '|' . $identifier ),
		);
		if ( $installAnother ) {
			$args['provider']    = (string) $operation->providerCode;
			$args['open_picker'] = '1';
		} elseif ( in_array( $operation->operation, array( 'install', 'edit' ), true ) || $returnToSettings ) {
			$args['package'] = $identifier;
		} elseif ( 'update' === $operation->operation ) {
			$args = array_merge( $listArguments, $args );
		}
		$adminUrl = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		return $adminUrl . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	private function enabled( array $request, string $key ): bool {
		return isset( $request[ $key ] ) && is_scalar( $request[ $key ] ) && '1' === (string) $request[ $key ];
	}

	/** @param array<string, string> $data */
	private function bulkNoticeAction( string $type, array $data ): string {
		ksort( $data, SORT_STRING );

		return 'ran-booster-bulk-result|' . $type . '|' . hash(
			'sha256',
			http_build_query( $data, '', '&', PHP_QUERY_RFC3986 )
		);
	}

	/** @param array<string, int> $reasons @param array<string, string> $labels */
	private function appendBulkReasons( string $message, array $reasons, array $labels ): string {
		$details = array();
		foreach ( $reasons as $reason => $count ) {
			$details[] = ( $labels[ $reason ] ?? $reason ) . ': ' . $count;
		}

		return array() === $details ? $message : $message . ' ' . implode( '; ', $details ) . '.';
	}

	private function activeDeployment( Dashboard $dashboard, DeploymentStorageFailure $failure, string $action ): bool {
		$notice = $this->deployments?->activeDeployment( $failure, $action );
		if ( null === $notice ) {
			return $this->manualFailure( $dashboard, static function (): void {}, $failure, $action );
		}
		$dashboard->addFailureMessage( $notice['message'], $failure, $notice['context'] );
		return false;
	}

	private function terminalDeploymentFailure( Dashboard $dashboard, \Closure $addContextMessage, array $result, string $action ): bool {
		$notice = $this->deployments?->deploymentFailure( $result['outcome_code'] ?? null, $result['correlation_id'], $action );
		if ( null === $notice ) {
			return $this->manualFailure( $dashboard, $addContextMessage, null, $action );
		}
		$addContextMessage( $notice['message'], $notice['context'] );
		return false;
	}

	/** @param \Closure(WP_Error|array<string, mixed>, array<string, string>): void $addContextMessage */
	private function manualFailure( Dashboard $dashboard, \Closure $addContextMessage, ?Throwable $failure, string $action ): bool {
		status_header( 400 );
		$message = new WP_Error( 'ran_booster_manual_action_failed', __( 'RAN Booster could not complete this action. Reference: ran_booster_manual_action_failed.', 'ran-booster' ) );
		$context = array(
			'operation' => $action,
			'step'      => 'manual_package_operation',
		);
		if ( null === $failure ) {
			$addContextMessage( $message, $context );
		} else {
			$dashboard->addFailureMessage( $message, $failure, $context );
		}
		return false;
	}

	/** @param \Closure(WP_Error|array<string, mixed>, array<string, string>): void $addContextMessage */
	private function removalFailure( PackageOperation $operation, string $code, \Closure $addContextMessage ): void {
		$type    = 'plugin' === $operation->packageType ? 'Plugin' : 'Theme';
		$message = match ( $code ) {
			'active_dependents' => 'Plugin was not removed because an active plugin depends on it.',
			'deactivation_failed' => 'Plugin was disabled in Booster, but WordPress could not deactivate it. No files were deleted.',
			'deletion_failed' => sprintf( '%s was disabled in Booster, but WordPress could not delete it.', $type ),
			'files_still_present' => sprintf( '%s was disabled in Booster, but its files are still present.', $type ),
			'management_state_uncertain' => sprintf( '%s files were deleted, but Booster could not verify removal of its management record.', $type ),
			'operation_in_progress' => sprintf( '%s was not removed because another Booster operation still owns it.', $type ),
			'operation_lock_failed' => sprintf( '%s removal could not safely acquire or release the WordPress updater lock.', $type ),
			'shared_plugin_directory' => 'Plugin was not removed because its directory contains another registered plugin.',
			'stale' => sprintf( '%s settings changed before this request. Refresh the package settings and try again.', $type ),
			'theme_active' => 'Theme was not removed because it is the active theme.',
			'theme_has_children' => 'Theme was not removed because an installed child theme depends on it.',
			'theme_parent_in_use' => 'Theme was not removed because the active theme depends on it.',
			'unsafe_path' => sprintf( '%s was not removed because WordPress could not verify a safe installed path.', $type ),
			default => sprintf( '%s removal could not be completed safely.', $type ),
		};
		status_header( 400 );
		$addContextMessage(
			new WP_Error( 'ran_booster_package_removal_' . $code, $message ),
			array(
				'operation'    => $operation->operation,
				'package_type' => $operation->packageType,
				'step'         => 'package_removal',
			)
		);
	}
}
