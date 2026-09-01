<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Logging\BoosterLogger;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagement;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use Throwable;

/** @internal WordPress request and signed PRG boundary for release workflows. */
final class ReleaseWorkflowRequestController {
	private const RESULT_QUERY_KEY                      = 'ran_booster_release_workflow_result';
	private const RESULT_SUCCESS_QUERY_KEY              = 'ran_booster_release_workflow_success';
	private const RESULT_TYPE_QUERY_KEY                 = 'ran_booster_release_workflow_type';
	private const RESULT_PACKAGE_QUERY_KEY              = 'ran_booster_release_workflow_package';
	private const RESULT_REVISION_QUERY_KEY             = 'ran_booster_release_workflow_source_revision';
	private const RESULT_PROVIDER_QUERY_KEY             = 'ran_booster_release_workflow_provider';
	private const RESULT_REPOSITORY_QUERY_KEY           = 'ran_booster_release_workflow_repository';
	private const RESULT_NONCE_QUERY_KEY                = 'ran_booster_release_workflow_result_nonce';
	private const RESULT_STAGE_QUERY_KEY                = 'ran_booster_release_workflow_failure_stage';
	private const RESULT_DIAGNOSTIC_QUERY_KEY           = 'ran_booster_release_workflow_diagnostic';
	private const RESULT_DIAGNOSTIC_AVAILABLE_QUERY_KEY = 'ran_booster_release_workflow_diagnostic_available';
	private const RESULT_REFERENCE_QUERY_KEY            = 'ran_booster_release_workflow_reference';
	private const RESULT_MESSAGE_QUERY_KEY              = 'ran_booster_release_workflow_message';
	private const RESULT_REMEDIATION_QUERY_KEY          = 'ran_booster_release_workflow_remediation';
	private const FAILURE_DIAGNOSTIC_CODES              = array( 'malformed_request', 'permissions_unavailable', 'package_source_changed', 'nonce_expired', 'credential_authorisation_unavailable', 'preflight_contract_unavailable', 'provider_unavailable', 'repository_source_conflict', 'repository_source_unavailable', 'repository_release_owner_exists', 'no_releases', 'invalid_release', 'release_identity_mismatch', 'release_incompatible', 'release_version_mismatch', 'package_header_missing', 'package_header_invalid', 'package_archive_unreadable', 'package_zip_extension_unavailable', 'package_archive_size_invalid', 'package_archive_too_large', 'package_archive_path_unsafe', 'package_archive_path_duplicate', 'package_archive_root_invalid', 'package_archive_entry_duplicate', 'package_archive_entry_limit', 'release_version_invalid', 'package_update_uri_missing', 'package_update_uri_invalid', 'package_compatibility_missing', 'package_compatibility_invalid', 'package_header_ambiguous', 'release_automation_detected', 'repository_snapshot_unavailable', 'template_pack_unavailable', 'preview_storage_unavailable', 'repository_mutation_unverified', 'local_persistence_unavailable', 'unexpected_runtime_failure' );
	private const RESULT_NONCE_ACTION                   = 'ran-booster-release-workflow-result-';
	private const PREVIEW_QUERY_KEY                     = 'ran_booster_release_workflow_preview';
	private const CHANNEL_QUERY_KEY                     = 'ran_booster_release_workflow_channel';

	public function __construct(
		private readonly ReleaseTrackingFacade $releases,
		private readonly PluginRepository $plugins,
		private readonly ThemeRepository $themes,
		private readonly ProviderRegistry $providers,
		private readonly RepositorySourceGuard $sourceGuard
	) {}

	public function handleWorkflow(): never {
		// This controller validates the exact local authority and purpose nonce before reading request-only secrets.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request = is_array( $_POST ) ? $_POST : array();
		$this->redirectTo( $this->processWorkflowRequest( $request ) );
	}

	private function redirectTo( string $url ): never {
		$hxRequest = $_SERVER['HTTP_HX_REQUEST'] ?? null;
		if ( is_string( $hxRequest ) && 'true' === strtolower( $hxRequest ) ) {
			$location = wp_json_encode(
				array(
					'path'   => wp_make_link_relative( $url ),
					'target' => '#wpbody-content',
					'select' => '#wpbody-content',
					'swap'   => 'outerHTML show:none',
				)
			);
			if ( is_string( $location ) ) {
				header( 'HX-Location: ' . $location );
				exit;
			}
		}

		wp_safe_redirect( $url );
		exit;
	}

	/** @param array<string,mixed> $request */
	public function processWorkflowRequest( #[\SensitiveParameter] array $request ): string {
		$operation    = is_string( $request['workflow_operation'] ?? null ) ? wp_unslash( $request['workflow_operation'] ) : '';
		$providerCode = is_string( $request['expected_provider'] ?? null ) ? wp_unslash( $request['expected_provider'] ) : '';
		$repositoryId = is_string( $request['expected_repository_id'] ?? null ) ? wp_unslash( $request['expected_repository_id'] ) : '';
		$type         = $this->workflowType( $request );
		$identifier   = $this->workflowIdentifier( $request );
		$revision     = $this->workflowRevision( $request );
		$previewKey   = $this->workflowPreview( $request );
		$nonce        = is_string( $request['_wpnonce'] ?? null ) ? wp_unslash( $request['_wpnonce'] ) : '';
		$channel      = 'inspect' === $operation ? $this->releaseChannelFrom( $request ) : '';
		$outcome      = $this->workflowResult( $type, $identifier, 'workflow_invalid_request', false, '', 'request_validation', 'malformed_request' );
		$exact        = false;
		try {
			do {
				if ( ! in_array( $operation, array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ), true )
					|| '' === $type || '' === $identifier || $revision < 1 || null === $previewKey || '' === $nonce
					|| '' === $providerCode || strlen( $providerCode ) > 32 || $providerCode !== sanitize_key( $providerCode )
					|| '' === $repositoryId || strlen( $repositoryId ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $repositoryId )
					|| ( 'inspect' === $operation && '' === $channel ) ) {
					break; }
				if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' ) ) {
					$outcome['diagnostic_code'] = 'permissions_unavailable';
					break;
				}
				$package = $this->workflowPackage( $type, $identifier, $revision );
				if ( null === $package || $providerCode !== (string) $package->getProviderCode() || $repositoryId !== $package->getProviderRepositoryId() ) {
					$outcome['diagnostic_code'] = 'package_source_changed';
					break;
				}
				$exact  = true;
				$status = $this->workflowStatus( $type, $identifier, $revision );
				if ( null === $status || ! $this->packageMatchesStatus( $package, $status ) ) {
					$outcome['diagnostic_code'] = 'package_source_changed';
					break;
				}
				if ( 1 !== wp_verify_nonce( $nonce, $this->workflowNonceAction( $operation, $status, $previewKey ) ) ) {
					$outcome['diagnostic_code'] = 'nonce_expired';
					break;
				}
				$provider = $this->workflowProvider( $providerCode );
				if ( null === $provider ) {
					$outcome['diagnostic_code'] = 'provider_unavailable';
					break; }
				$sourceGuard = $this->workflowSourceGuard( $type, $identifier, $package );
				if ( ! $sourceGuard['allowed'] ) {
					$outcome['diagnostic_code'] = $sourceGuard['code'];
					break;
				}
				$write              = in_array( $operation, array( 'setup', 'update_setup' ), true );
				$credentialId       = is_string( $request['booster_credential_id'] ?? null ) ? wp_unslash( $request['booster_credential_id'] ) : '';
				$local              = $this->workflowProviderStatus( $status );
				$credentialRequired = $write || ! $this->anonymousWorkflowInspectionAllowed( $package );
				if ( null === $local || strlen( $credentialId ) > 191 || ( '' === $credentialId && $credentialRequired )
					|| ( '' !== $credentialId && ! in_array( $credentialId, array_column( $local->credentialChoices(), 'id' ), true ) ) ) {
					$outcome = $this->workflowResult( $type, $identifier, 'workflow_unauthorised', false, '', 'credential_authorisation', 'credential_authorisation_unavailable' );
					break;
				}
				$confirmation = is_string( $request['confirm_repository'] ?? null ) ? wp_unslash( $request['confirm_repository'] ) : '';
				if ( $write ) {
					$preview = $provider->workflowPreview( $status, $previewKey );
					if ( null === $preview || $preview->key() !== $previewKey || $preview->providerCode() !== $providerCode
						|| $preview->repositoryId() !== $repositoryId || $preview->confirmation() !== $confirmation
						|| $preview->kind() !== ( 'setup' === $operation ? 'bootstrap' : 'template_update' ) ) {
						$outcome['diagnostic_code'] = 'package_source_changed';
						break;
					}
					$channel = $preview->channel();
				}
				if ( in_array( $operation, array( 'outcome', 'update_inspect', 'update_setup' ), true ) && ! $this->recordMatchesPackageStatus( $local, $status ) ) {
					$outcome['diagnostic_code'] = 'package_source_changed';
					break;
				}
				$preflight = null;
				if ( in_array( $operation, array( 'inspect', 'setup' ), true ) ) {
					$preflight = $this->releases->assessmentPreflight( $type, $identifier, $revision, $channel, $this->workflowPreflightNonce( $request, $channel ) );
					if ( null === $preflight || ! in_array( $preflight->code(), array( 'ready', 'release_unavailable' ), true ) ) {
						$outcome = $this->workflowResult( $type, $identifier, 'workflow_preflight_unavailable', false, $previewKey, 'release_preflight', null === $preflight ? 'preflight_contract_unavailable' : ( '' !== $preflight->reasonCode() ? $preflight->reasonCode() : 'provider_unavailable' ) );
						break;
					}
				}
				$result = match ( $operation ) {
					'inspect' => $provider->workflowInspect( $status, $channel, $preflight, '' === $credentialId ? null : $credentialId ),
					'setup' => $provider->workflowSetup( $status, $previewKey, $confirmation, $preflight, $credentialId ),
					'outcome' => $provider->workflowOutcome( $status, '' === $credentialId ? null : $credentialId ),
					'update_inspect' => $provider->workflowInspectUpdate( $status, '' === $credentialId ? null : $credentialId ),
					'update_setup' => $provider->workflowSetupUpdate( $status, $previewKey, $confirmation, $credentialId ),
				};
				$outcome = $this->workflowResult( $type, $identifier, $result->workflowCode(), $result->successful(), $result->previewKey(), $result->failureStage(), $result->diagnosticCode(), '' !== $result->correlationReference(), $result->correlationReference(), $result->message(), $result->remediation() );
			} while ( false );
		} catch ( Throwable ) {
			$outcome = $this->workflowResult( $type, $identifier, 'workflow_remote_unavailable', false, '', 'unexpected', 'unexpected_runtime_failure' );
		}
		if ( ! $outcome['successful'] && '' === $outcome['correlation_reference'] && '' !== $outcome['failure_stage'] ) {
			$outcome = $this->preserveRequestFailure( $operation, $outcome, $providerCode );
		}
		$args = $this->resultQueryArguments( $outcome, $channel, $revision, $providerCode, $repositoryId );
		if ( '' !== $outcome['preview_key'] ) {
			$args[ self::PREVIEW_QUERY_KEY ] = $outcome['preview_key']; }
		if ( $exact ) {
			return add_query_arg( $args, $this->repositoryReleaseUrl( $repositoryId, $providerCode ) ) . '#ran-booster-repository-release-workflows';
		}

		$args['source_view']               = 'release_asset';
		$args['ran_booster_open_advanced'] = '1';

		return add_query_arg( $args, $this->returnUrl( $type, $identifier ) ) . '#ran-booster-advanced-source-settings';
	}

	/** @param array<string, mixed> $request */
	private function workflowType( array $request ): string {
		$type = is_string( $request['expected_type'] ?? null ) ? sanitize_key( wp_unslash( $request['expected_type'] ) ) : '';

		return in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : '';
	}

	/** @param array<string, mixed> $request */
	private function workflowIdentifier( array $request ): string {
		$identifier = is_string( $request['expected_identifier'] ?? null )
			? sanitize_text_field( wp_unslash( $request['expected_identifier'] ) ) : '';

		return strlen( $identifier ) <= 255 ? $identifier : '';
	}

	/** @param array<string, mixed> $request */
	private function workflowRevision( array $request ): int {
		$revision = $request['expected_source_revision'] ?? null;
		if ( is_int( $revision ) ) {
			return $revision > 0 ? $revision : 0;
		}

		$revision = is_string( $revision ) ? wp_unslash( $revision ) : null;

		return is_string( $revision ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $revision ) ? (int) $revision : 0;
	}

	/** @param array<string, mixed> $request */
	private function workflowPreview( array $request ): ?string {
		if ( ! array_key_exists( 'preview_key', $request ) || '' === $request['preview_key'] ) {
			return '';
		}

		$preview = is_string( $request['preview_key'] ) ? wp_unslash( $request['preview_key'] ) : null;

		return is_string( $preview ) && 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $preview ) ? $preview : null;
	}

	private function workflowStatus( string $type, string $identifier, int $revision ): ?ReleaseTrackingStatus {
		$status = $this->releases?->status( $type, $identifier );
		if ( ! $status instanceof ReleaseTrackingStatus || ! $status->eligible() || $revision !== $status->sourceRevision()
			|| ! hash_equals( $type, $status->type() ) || ! hash_equals( $identifier, $status->identifier() ) ) {
			return null;
		}

		return $status;
	}

	/** @return array{allowed:bool,code:string,relationship_count:int,release_count:int,owner_type:?int,owner_package:?string} */
	private function workflowSourceGuard( string $type, string $identifier, object $package ): array {
		$failure = array(
			'allowed'            => false,
			'code'               => 'repository_source_unavailable',
			'relationship_count' => 0,
			'release_count'      => 0,
			'owner_type'         => null,
			'owner_package'      => null,
		);
		if ( ! is_callable( array( $package, 'getProviderCode' ) )
			|| ! is_callable( array( $package, 'getProviderRepositoryId' ) )
			|| ! is_string( $package->getProviderRepositoryId() ) ) {
			return $failure;
		}
		$typeId = 'plugin' === $type ? 1 : ( 'theme' === $type ? 2 : 0 );
		return $this->requestBoundary(
			fn (): array => $this->sourceGuard->assess( $package->getProviderCode(), $package->getProviderRepositoryId(), $typeId, $identifier, PackageSource::RELEASE_ASSET ),
			$failure
		);
	}

	public function workflowNonceAction( string $operation, ReleaseTrackingStatus $status, string $preview = '' ): string {
		return 'ran-booster-release-workflow-' . $operation . '-' . hash(
			'sha256',
			(string) wp_json_encode( array( $this->workflowProviderCode( $status ), $status->providerRepositoryId(), $status->type(), $status->identifier(), $status->sourceRevision(), $preview ) )
		);
	}

	/** @param array<string, mixed> $request */
	private function workflowPreflightNonce( array $request, string $channel ): string {
		$key = 'core_preflight_nonce_' . $channel;

		return in_array( $channel, array( 'stable', 'prerelease' ), true ) && is_string( $request[ $key ] ?? null )
			? wp_unslash( $request[ $key ] ) : '';
	}

	/** @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string,message:string,remediation:string} */
	private function workflowResult( string $type, string $identifier, string $code, bool $successful, string $preview = '', string $stage = '', string $diagnostic = '', bool $diagnosticAvailable = false, string $reference = '', string $message = '', string $remediation = '' ): array {
		return array(
			'type'                  => $type,
			'identifier'            => $identifier,
			'code'                  => $code,
			'successful'            => $successful,
			'preview_key'           => $preview,
			'failure_stage'         => $stage,
			'diagnostic_code'       => $diagnostic,
			'diagnostic_available'  => $diagnosticAvailable,
			'correlation_reference' => $reference,
			'message'               => $message,
			'remediation'           => $remediation,
		);
	}

	/** @param array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string} $outcome */
	private function preserveRequestFailure( string $operation, array $outcome, string $providerCode ): array {
		$diagnostic                       = $this->failureDiagnosticCode( $outcome['diagnostic_code'], $outcome['failure_stage'] );
		$reference                        = $this->failureReference();
		$available                        = BoosterLogger::log(
			'Provider release workflow request refused',
			array(
				'provider'       => $providerCode,
				'operation'      => in_array( $operation, array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ), true ) ? $operation : 'invalid',
				'outcome_code'   => $outcome['code'],
				'diagnostic_id'  => $diagnostic,
				'step'           => $outcome['failure_stage'],
				'correlation_id' => $reference,
			)
		);
		$outcome['diagnostic_code']       = $diagnostic;
		$outcome['diagnostic_available']  = $available;
		$outcome['correlation_reference'] = $available ? $reference : '';
		return $outcome;
	}

	private function failureDiagnosticCode( mixed $diagnostic, string $stage ): string {
		if ( is_string( $diagnostic ) && in_array( $diagnostic, self::FAILURE_DIAGNOSTIC_CODES, true ) ) {
			return $diagnostic;
		}
		return match ( $stage ) {
			'request_validation' => 'malformed_request',
			'credential_authorisation' => 'credential_authorisation_unavailable',
			'release_preflight' => 'preflight_contract_unavailable',
			'repository_snapshot' => 'repository_snapshot_unavailable',
			'template_pack' => 'template_pack_unavailable',
			'preview_storage' => 'preview_storage_unavailable',
			'repository_mutation' => 'repository_mutation_unverified',
			'local_persistence' => 'local_persistence_unavailable',
			default => 'unexpected_runtime_failure',
		};
	}

	private function failureReference(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			return substr( hash( 'sha256', uniqid( 'ran-booster-release-workflow-', true ) ), 0, 32 );
		}
	}

	private function workflowPackage( string $type, string $identifier, int $revision ): ?object {
		$package = $this->requestBoundary(
			fn (): object => 'plugin' === $type ? $this->plugins->boosterPluginFromFile( $identifier ) : $this->themes->boosterThemeFromStylesheet( $identifier ),
			null
		);
		return null !== $package && $revision === $package->getSourceRevision()
			&& is_string( $package->getProviderRepositoryId() ) && '' !== $package->getProviderRepositoryId() ? $package : null;
	}

	private function packageMatchesStatus( object $package, ReleaseTrackingStatus $status ): bool {
		return $status->providerRepositoryId() === $package->getProviderRepositoryId()
			&& $status->sourceRevision() === $package->getSourceRevision();
	}

	private function anonymousWorkflowInspectionAllowed( object $package ): bool {
		return is_callable( array( $package, 'isPrivate' ) )
			&& false === $this->requestBoundary( fn (): mixed => $package->isPrivate(), null );
	}

	private function recordMatchesPackageStatus( ?\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus $record, ReleaseTrackingStatus $status ): bool {
		return $record instanceof \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus
			&& $record->recordOccupied()
			&& hash_equals( $this->workflowProviderCode( $status ), $record->providerCode() )
			&& hash_equals( $status->providerRepositoryId(), $record->repositoryId() )
			&& hash_equals( $status->type(), $record->packageType() )
			&& hash_equals( $status->identifier(), $record->packageIdentifier() );
	}

	private function workflowProvider( string $providerCode ): ?RepositoryReleaseWorkflowManagement {
		try {
			$provider = $this->providers->requireCapability( $providerCode, RepositoryReleaseWorkflowManagement::class );
			$release  = $this->providers->get( $providerCode );
			return 1 === $provider::RELEASE_WORKFLOW_API_VERSION
				&& null !== ( ( $this->providers->metadata()[ $providerCode ] ?? null )?->admin ?? null )
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseMetadata
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseCandidateListing
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseInspector
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseAcquirer
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseNativeTargets ? $provider : null;
		} catch ( Throwable ) {
			return null;
		}
	}

	private function workflowProviderStatus( ReleaseTrackingStatus $status ): ?\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus {
		$providerCode = $this->workflowProviderCode( $status );
		$provider     = $this->workflowProvider( $providerCode );
		$value        = null === $provider ? null : $this->requestBoundary( fn () => $provider->workflowStatus( $status ), null );
		if ( null !== $value && ( $value->providerCode() !== $providerCode
			|| $value->repositoryId() !== $status->providerRepositoryId()
			|| ( $value->recordExact() && ( $value->packageType() !== $status->type() || $value->packageIdentifier() !== $status->identifier() || $value->sourceRevision() !== $status->sourceRevision() ) ) ) ) {
			return null;
		}
		return $value;
	}

	private function workflowProviderCode( ReleaseTrackingStatus $status ): string {
		$package = $this->workflowPackage( $status->type(), $status->identifier(), $status->sourceRevision() );
		return null !== $package && $this->packageMatchesStatus( $package, $status ) ? (string) $package->getProviderCode() : '';
	}

	/** @param array<string,string> $exceptionContext */
	private function requestBoundary( callable $operation, mixed $failure, array $exceptionContext = array() ): mixed {
		$bufferLevel = ob_get_level();
		ob_start();
		try {
			$result = $operation();
			ob_end_clean();
			return $result;
		} catch ( Throwable $exception ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}
			if ( array() !== $exceptionContext ) {
				BoosterLogger::logException( 'Provider release workflow request failed', $exception, $exceptionContext );
			}
			return $failure;
		}
	}

	private function returnUrl( string $type, string $identifier ): string {
		$args = array( 'page' => 'plugin' === $type ? 'ran-booster-plugins' : 'ran-booster-themes' );
		if ( '' !== $identifier ) {
			$args['package'] = $identifier;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function repositoryReleaseUrl( string $repositoryId, string $providerCode = '' ): string {
		return add_query_arg(
			array(
				'page'            => 'ran-booster',
				'tab'             => $providerCode,
				'panel'           => 'repositories',
				'repository'      => $repositoryId,
				'repository_view' => 'releases',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available?:bool,correlation_reference:string,message:string,remediation:string} $outcome
	 * @return array<string, string>
	 */
	private function resultQueryArguments( array $outcome, string $channel = '', int $sourceRevision = 0, string $providerCode = '', string $repositoryId = '' ): array {
		$type                                 = in_array( $outcome['type'], array( 'plugin', 'theme' ), true ) ? $outcome['type'] : 'plugin';
		$identifier                           = strlen( $outcome['identifier'] ) <= 255 ? $outcome['identifier'] : '';
		$code                                 = sanitize_key( $outcome['code'] );
		$code                                 = strlen( $code ) <= 64 ? $code : 'invalid_request';
		$successful                           = $outcome['successful'];
		$channel                              = in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : '';
		$stage                                = in_array( $outcome['failure_stage'], array( 'request_validation', 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) ? $outcome['failure_stage'] : '';
		$diagnostic                           = $this->failureDiagnosticCode( $outcome['diagnostic_code'], $stage );
		$diagnosticAvailable                  = true === ( $outcome['diagnostic_available'] ?? false );
		$reference                            = $diagnosticAvailable && is_string( $outcome['correlation_reference'] ) && 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $outcome['correlation_reference'] ) ? $outcome['correlation_reference'] : '';
		$message                              = $this->resultDisplayText( $outcome['message'] ?? '' );
		$remediation                          = $this->resultDisplayText( $outcome['remediation'] ?? '' );
		$args                                 = array(
			self::RESULT_QUERY_KEY                      => $code,
			self::RESULT_SUCCESS_QUERY_KEY              => $successful ? '1' : '0',
			self::RESULT_TYPE_QUERY_KEY                 => $type,
			self::RESULT_PACKAGE_QUERY_KEY              => $identifier,
			self::RESULT_REVISION_QUERY_KEY             => (string) max( 0, $sourceRevision ),
			self::RESULT_PROVIDER_QUERY_KEY             => $providerCode,
			self::RESULT_REPOSITORY_QUERY_KEY           => $repositoryId,
			self::RESULT_STAGE_QUERY_KEY                => $stage,
			self::RESULT_DIAGNOSTIC_QUERY_KEY           => $diagnostic,
			self::RESULT_DIAGNOSTIC_AVAILABLE_QUERY_KEY => $diagnosticAvailable ? '1' : '0',
			self::RESULT_REFERENCE_QUERY_KEY            => $reference,
			self::RESULT_MESSAGE_QUERY_KEY              => $message,
			self::RESULT_REMEDIATION_QUERY_KEY          => $remediation,
		);
		$args[ self::CHANNEL_QUERY_KEY ]      = $channel;
		$args[ self::RESULT_NONCE_QUERY_KEY ] = wp_create_nonce(
			$this->resultNonceAction( $code, $successful, $type, $identifier, max( 0, $sourceRevision ), $channel, $stage, $diagnostic, $diagnosticAvailable, $reference, $providerCode, $repositoryId, $message, $remediation )
		);

		return $args;
	}

	/** @return array{code:string,successful:bool,type:string,identifier:string,source_revision:int,channel:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string,message:string,remediation:string}|null */
	public function requestedResult(): ?array {
		$values = array();
		foreach ( array( self::RESULT_QUERY_KEY, self::RESULT_SUCCESS_QUERY_KEY, self::RESULT_TYPE_QUERY_KEY, self::RESULT_PACKAGE_QUERY_KEY, self::RESULT_REVISION_QUERY_KEY, self::RESULT_PROVIDER_QUERY_KEY, self::RESULT_REPOSITORY_QUERY_KEY, self::CHANNEL_QUERY_KEY, self::RESULT_STAGE_QUERY_KEY, self::RESULT_DIAGNOSTIC_QUERY_KEY, self::RESULT_DIAGNOSTIC_AVAILABLE_QUERY_KEY, self::RESULT_REFERENCE_QUERY_KEY, self::RESULT_MESSAGE_QUERY_KEY, self::RESULT_REMEDIATION_QUERY_KEY, self::RESULT_NONCE_QUERY_KEY ) as $key ) {
			$value = $_GET[ $key ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
			if ( ! is_string( $value ) ) {
				return null;
			}
			$values[] = wp_unslash( $value );
		}
		[ $code, $success, $type, $identifier, $revision, $provider, $repository, $channel, $stage, $diagnostic, $available, $reference, $message, $remediation, $nonce ] = $values;
		if ( $code !== sanitize_key( $code ) || '' === $code || strlen( $code ) > 64
			|| $provider !== sanitize_key( $provider ) || strlen( $provider ) > 32
			|| strlen( $repository ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $repository )
			|| ! in_array( $success, array( '0', '1' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| $identifier !== sanitize_text_field( $identifier ) || strlen( $identifier ) > 255
			|| 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,9})\z/D', $revision )
			|| ! in_array( $channel, array( '', 'stable', 'prerelease' ), true )
			|| ! in_array( $stage, array( '', 'request_validation', 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true )
			|| ! in_array( $diagnostic, array( '', ...self::FAILURE_DIAGNOSTIC_CODES ), true )
			|| ! in_array( $available, array( '0', '1' ), true )
			|| $message !== $this->resultDisplayText( $message ) || $remediation !== $this->resultDisplayText( $remediation )
			|| ( '' !== $reference && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $reference ) ) ) {
			return null;
		}

		$successful          = '1' === $success;
		$diagnosticAvailable = '1' === $available;
		if ( 1 !== wp_verify_nonce( $nonce, $this->resultNonceAction( $code, $successful, $type, $identifier, (int) $revision, $channel, $stage, $diagnostic, $diagnosticAvailable, $reference, $provider, $repository, $message, $remediation ) ) ) {
			return null;
		}

		return array(
			'code'                  => $code,
			'successful'            => $successful,
			'type'                  => $type,
			'identifier'            => $identifier,
			'source_revision'       => (int) $revision,
			'provider'              => $provider,
			'repository'            => $repository,
			'channel'               => $channel,
			'failure_stage'         => $stage,
			'diagnostic_code'       => $diagnostic,
			'diagnostic_available'  => $diagnosticAvailable,
			'correlation_reference' => $reference,
			'message'               => $message,
			'remediation'           => $remediation,
		);
	}

	/** Return the normalized opaque preview key for GET-side projection. */
	public function requestedPreviewKey(): string {
		$value = $_GET['ran_booster_release_workflow_preview'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opaque read-only preview lookup.
		$value = is_string( $value ) ? sanitize_key( wp_unslash( $value ) ) : '';

		return 1 === preg_match( '/\\A[a-f0-9]{32}\\z/D', $value ) ? $value : '';
	}

	private function resultNonceAction( string $code, bool $successful, string $type, string $identifier, int $sourceRevision, string $channel, string $stage = '', string $diagnostic = '', bool $diagnosticAvailable = false, string $reference = '', string $providerCode = '', string $repositoryId = '', string $message = '', string $remediation = '' ): string {
		$payload = wp_json_encode( array( $code, $successful, $type, $identifier, $sourceRevision, $channel, $stage, $diagnostic, $diagnosticAvailable, $reference, $providerCode, $repositoryId, $message, $remediation ) );

		return self::RESULT_NONCE_ACTION . hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	private function resultDisplayText( mixed $value ): string {
		return is_string( $value ) && strlen( $value ) <= 512 && 0 === preg_match( '/[<>\x00-\x1F\x7F]/', $value ) ? $value : '';
	}

	/** @param array{code:string,successful:bool,type:string,identifier:string,channel:string} $result */
	public function resultMatchesCurrentScreen( array $result ): bool {
		$pageValue = $_GET['page'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen binding for a verified result.
		$package   = $_GET['package'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen binding for a verified result.
		if ( ! is_string( $pageValue ) ) {
			return false;
		}

		$page         = sanitize_key( wp_unslash( $pageValue ) );
		$packagePage  = 'plugin' === $result['type'] ? 'ran-booster-plugins' : 'ran-booster-themes';
		$creationPage = $packagePage . '-create';
		if ( $creationPage === $page ) {
			return ! $result['successful'];
		}
		if ( $packagePage !== $page ) {
			return false;
		}

		return ! is_string( $package ) || '' === $package
			|| $result['identifier'] === sanitize_text_field( wp_unslash( $package ) );
	}

	/** @param array<string, mixed> $request */
	private function releaseChannelFrom( array $request ): string {
		$channel = is_string( $request['release_channel'] ?? null ) ? sanitize_key( wp_unslash( $request['release_channel'] ) ) : '';

		return in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : '';
	}
}
