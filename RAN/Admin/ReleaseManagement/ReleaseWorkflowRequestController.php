<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Logging\BoosterLogger;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
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
		private readonly RepositorySourceGuard $source_guard
	) {}


	public function handle_workflow(): never {
		// This controller validates the exact local authority and purpose nonce before reading request-only secrets.
		// Read the mutable global defensively: another plugin may have replaced it.
		$request = $GLOBALS['_POST'] ?? null;
		$request = is_array( $request ) ? $request : array();
		$this->redirect_to( $this->process_workflow_request( $request ) );
	}

	private function redirect_to( string $url ): never {
		$hx_request = $_SERVER['HTTP_HX_REQUEST'] ?? null;
		if ( is_string( $hx_request ) && 'true' === strtolower( $hx_request ) ) {
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

	public function process_workflow_request( #[\SensitiveParameter] array $request ): string {
		$operation     = is_string( $request['workflow_operation'] ?? null ) ? wp_unslash( $request['workflow_operation'] ) : '';
		$provider_code = is_string( $request['expected_provider'] ?? null ) ? wp_unslash( $request['expected_provider'] ) : '';
		$repository_id = is_string( $request['expected_repository_id'] ?? null ) ? wp_unslash( $request['expected_repository_id'] ) : '';
		$type          = $this->workflow_type( $request );
		$identifier    = $this->workflow_identifier( $request );
		$revision      = $this->workflow_revision( $request );
		$preview_key   = $this->workflow_preview( $request );
		$nonce         = is_string( $request['_wpnonce'] ?? null ) ? wp_unslash( $request['_wpnonce'] ) : '';
		$channel       = 'inspect' === $operation ? $this->release_channel_from( $request ) : '';
		$outcome       = $this->workflow_result( $type, $identifier, 'workflow_invalid_request', false, '', 'request_validation', 'malformed_request' );
		$exact         = false;
		try {
			do {
				if ( ! in_array( $operation, array( 'inspect', 'setup', 'outcome' ), true )
					|| '' === $type || '' === $identifier || $revision < 1 || null === $preview_key || '' === $nonce
					|| '' === $provider_code || strlen( $provider_code ) > 32 || sanitize_key( $provider_code ) !== $provider_code
					|| '' === $repository_id || strlen( $repository_id ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $repository_id )
					|| ( 'inspect' === $operation && 'stable' !== $channel ) ) {
					break; }
				if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' ) ) {
					$outcome['diagnostic_code'] = 'permissions_unavailable';
					break;
				}
				$package = $this->workflow_package( $type, $identifier, $revision );
				if ( null === $package || $provider_code !== (string) $package->get_provider_code() || $repository_id !== $package->get_provider_repository_id() ) {
					$outcome['diagnostic_code'] = 'package_source_changed';
					break;
				}
				$exact  = true;
				$status = $this->workflow_status( $type, $identifier, $revision );
				if ( null === $status || ! $this->package_matches_status( $package, $status ) ) {
					$outcome['diagnostic_code'] = 'package_source_changed';
					break;
				}
				if ( 1 !== wp_verify_nonce( $nonce, $this->workflow_nonce_action( $operation, $status, $preview_key ) ) ) {
					$outcome['diagnostic_code'] = 'nonce_expired';
					break;
				}
				$provider = $this->workflow_provider( $provider_code );
				if ( null === $provider ) {
					$outcome['diagnostic_code'] = 'provider_unavailable';
					break; }
				$provider_target = ReleaseWorkflowProviderProjection::target( $status );
				$source_guard    = $this->workflow_source_guard( $type, $identifier, $package );
				if ( ! $source_guard['allowed'] ) {
					$outcome['diagnostic_code'] = $source_guard['code'];
					break;
				}
				$write               = ( 'setup' === $operation );
				$credential_id       = is_string( $request['booster_credential_id'] ?? null ) ? wp_unslash( $request['booster_credential_id'] ) : '';
				$local               = $this->workflow_provider_status( $status, $provider_target );
				$credential_required = $write || ! $this->anonymous_workflow_inspection_allowed( $package );
				if ( null === $local || strlen( $credential_id ) > 191 || ( '' === $credential_id && $credential_required )
					|| ( '' !== $credential_id && ! in_array( $credential_id, array_column( $local->credential_choices(), 'id' ), true ) ) ) {
					$outcome = $this->workflow_result( $type, $identifier, 'workflow_unauthorised', false, '', 'credential_authorisation', 'credential_authorisation_unavailable' );
					break;
				}
				$confirmation = is_string( $request['confirm_repository'] ?? null ) ? wp_unslash( $request['confirm_repository'] ) : '';
				if ( $write ) {
					$preview = $provider->workflow_preview( $provider_target, $preview_key );
					if ( null === $preview || $preview->key() !== $preview_key || $preview->provider_code() !== $provider_code
						|| $preview->repository_id() !== $repository_id || $preview->confirmation() !== $confirmation
						|| $preview->kind() !== 'bootstrap' ) {
						$outcome['diagnostic_code'] = 'package_source_changed';
						break;
					}
					$channel = $preview->channel();
				}
				if ( ( 'outcome' === $operation ) && ! $this->record_matches_package_status( $local, $status ) ) {
					$outcome['diagnostic_code'] = 'package_source_changed';
					break;
				}
				$provider_preflight = null;
				if ( in_array( $operation, array( 'inspect', 'setup' ), true ) ) {
					$preflight = $this->releases->assessment_preflight( $type, $identifier, $revision, $channel, $this->workflow_preflight_nonce( $request, $channel ) );
					if ( null === $preflight || ! in_array( $preflight->code(), array( 'ready', 'release_unavailable' ), true ) ) {
						$outcome = $this->workflow_result( $type, $identifier, 'workflow_preflight_unavailable', false, $preview_key, 'release_preflight', null === $preflight ? 'preflight_contract_unavailable' : ( '' !== $preflight->reason_code() ? $preflight->reason_code() : 'provider_unavailable' ) );
						break;
					}
					$provider_preflight = ReleaseWorkflowProviderProjection::preflight( $preflight );
				}
				$result = match ( $operation ) {
					'inspect' => $provider->workflow_inspect( $provider_target, $channel, $provider_preflight ?? throw new \RuntimeException( 'Release workflow preflight projection is unavailable.' ), '' === $credential_id ? null : $credential_id ),
					'setup' => $provider->workflow_setup( $provider_target, $preview_key, $confirmation, $provider_preflight ?? throw new \RuntimeException( 'Release workflow preflight projection is unavailable.' ), $credential_id ),
					'outcome' => $provider->workflow_outcome( $provider_target, '' === $credential_id ? null : $credential_id ),
				};
				$outcome = $this->workflow_result( $type, $identifier, $result->workflow_code(), $result->successful(), $result->preview_key(), $result->failure_stage(), $result->diagnostic_code(), '' !== $result->correlation_reference(), $result->correlation_reference(), $result->message(), $result->remediation() );
			} while ( false );
		} catch ( Throwable ) {
			$outcome = $this->workflow_result( $type, $identifier, 'workflow_remote_unavailable', false, '', 'unexpected', 'unexpected_runtime_failure' );
		}
		if ( ! $outcome['successful'] && '' === $outcome['correlation_reference'] && '' !== $outcome['failure_stage'] ) {
			$outcome = $this->preserve_request_failure( $operation, $outcome, $provider_code );
		}
		$args = $this->result_query_arguments( $outcome, $channel, $revision, $provider_code, $repository_id );
		if ( '' !== $outcome['preview_key'] ) {
			$args[ self::PREVIEW_QUERY_KEY ] = $outcome['preview_key']; }
		if ( $exact ) {
			return add_query_arg( $args, $this->repository_release_url( $repository_id, $provider_code ) ) . '#ran-booster-repository-release-workflows';
		}

		$args['source_view']               = 'release_asset';
		$args['ran_booster_open_advanced'] = '1';

		return add_query_arg( $args, $this->return_url( $type, $identifier ) ) . '#ran-booster-advanced-source-settings';
	}

	/** @param array<string, mixed> $request */
	private function workflow_type( array $request ): string {
		$type = is_string( $request['expected_type'] ?? null ) ? sanitize_key( wp_unslash( $request['expected_type'] ) ) : '';

		return in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : '';
	}

	/** @param array<string, mixed> $request */
	private function workflow_identifier( array $request ): string {
		$identifier = is_string( $request['expected_identifier'] ?? null )
			? sanitize_text_field( wp_unslash( $request['expected_identifier'] ) ) : '';

		return strlen( $identifier ) <= 255 ? $identifier : '';
	}

	/** @param array<string, mixed> $request */
	private function workflow_revision( array $request ): int {
		$revision = $request['expected_source_revision'] ?? null;
		if ( is_int( $revision ) ) {
			return $revision > 0 ? $revision : 0;
		}

		$revision = is_string( $revision ) ? wp_unslash( $revision ) : null;

		return is_string( $revision ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $revision ) ? (int) $revision : 0;
	}

	/** @param array<string, mixed> $request */
	private function workflow_preview( array $request ): ?string {
		if ( ! array_key_exists( 'preview_key', $request ) || '' === $request['preview_key'] ) {
			return '';
		}

		$preview = is_string( $request['preview_key'] ) ? wp_unslash( $request['preview_key'] ) : null;

		return is_string( $preview ) && 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $preview ) ? $preview : null;
	}

	private function workflow_status( string $type, string $identifier, int $revision ): ?ReleaseTrackingStatus {
		$status = $this->releases->status( $type, $identifier );
		if ( ! $status->eligible() || $revision !== $status->source_revision()
			|| ! hash_equals( $type, $status->type() ) || ! hash_equals( $identifier, $status->identifier() ) ) {
			return null;
		}

		return $status;
	}

	/** @return array{allowed:bool,code:string,relationship_count:int,release_count:int,owner_type:?int,owner_package:?string} */
	private function workflow_source_guard( string $type, string $identifier, object $package ): array {
		$failure = array(
			'allowed'            => false,
			'code'               => 'repository_source_unavailable',
			'relationship_count' => 0,
			'release_count'      => 0,
			'owner_type'         => null,
			'owner_package'      => null,
		);
		if ( ! is_callable( array( $package, 'get_provider_code' ) )
			|| ! is_callable( array( $package, 'get_provider_repository_id' ) )
			|| ! is_string( $package->get_provider_repository_id() ) ) {
			return $failure;
		}
		$type_id = 'plugin' === $type ? 1 : ( 'theme' === $type ? 2 : 0 );
		return $this->request_boundary(
			fn (): array => $this->source_guard->assess( $package->get_provider_code(), $package->get_provider_repository_id(), $type_id, $identifier, PackageSource::RELEASE_ASSET ),
			$failure
		);
	}


	public function workflow_nonce_action( string $operation, ReleaseTrackingStatus $status, string $preview = '' ): string {
		return 'ran-booster-release-workflow-' . $operation . '-' . hash(
			'sha256',
			(string) wp_json_encode( array( $this->workflow_provider_code( $status ), $status->provider_repository_id(), $status->type(), $status->identifier(), $status->source_revision(), $preview ) )
		);
	}

	/** @param array<string, mixed> $request */
	private function workflow_preflight_nonce( array $request, string $channel ): string {
		$key = 'core_preflight_nonce_' . $channel;

		return ( 'stable' === $channel ) && is_string( $request[ $key ] ?? null )
			? wp_unslash( $request[ $key ] ) : '';
	}

	/** @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string,message:string,remediation:string} */
	private function workflow_result( string $type, string $identifier, string $code, bool $successful, string $preview = '', string $stage = '', string $diagnostic = '', bool $diagnostic_available = false, string $reference = '', string $message = '', string $remediation = '' ): array {
		return array(
			'type'                  => $type,
			'identifier'            => $identifier,
			'code'                  => $code,
			'successful'            => $successful,
			'preview_key'           => $preview,
			'failure_stage'         => $stage,
			'diagnostic_code'       => $diagnostic,
			'diagnostic_available'  => $diagnostic_available,
			'correlation_reference' => $reference,
			'message'               => $message,
			'remediation'           => $remediation,
		);
	}

	/**
	 * @param array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string,message:string,remediation:string} $outcome
	 * @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string,message:string,remediation:string}
	 */
	private function preserve_request_failure( string $operation, array $outcome, string $provider_code ): array {
		$diagnostic                       = $this->failure_diagnostic_code( $outcome['diagnostic_code'], $outcome['failure_stage'] );
		$reference                        = $this->failure_reference();
		$available                        = BoosterLogger::log(
			'Provider release workflow request refused',
			array(
				'provider'       => $provider_code,
				'operation'      => in_array( $operation, array( 'inspect', 'setup', 'outcome' ), true ) ? $operation : 'invalid',
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

	private function failure_diagnostic_code( mixed $diagnostic, string $stage ): string {
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

	private function failure_reference(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			return substr( hash( 'sha256', uniqid( 'ran-booster-release-workflow-', true ) ), 0, 32 );
		}
	}

	private function workflow_package( string $type, string $identifier, int $revision ): ?object {
		$package = $this->request_boundary(
			fn (): object => 'plugin' === $type ? $this->plugins->booster_plugin_from_file( $identifier ) : $this->themes->booster_theme_from_stylesheet( $identifier ),
			null
		);
		return null !== $package && $revision === $package->get_source_revision()
			&& is_string( $package->get_provider_repository_id() ) && '' !== $package->get_provider_repository_id() ? $package : null;
	}

	private function package_matches_status( object $package, ReleaseTrackingStatus $status ): bool {
		return $status->provider_repository_id() === $package->get_provider_repository_id()
			&& $status->source_revision() === $package->get_source_revision();
	}

	private function anonymous_workflow_inspection_allowed( object $package ): bool {
		return is_callable( array( $package, 'is_private' ) )
			&& false === $this->request_boundary( fn (): mixed => $package->is_private(), null );
	}

	private function record_matches_package_status( ?\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus $record, ReleaseTrackingStatus $status ): bool {
		return $record instanceof \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus
			&& $record->record_occupied()
			&& 'bootstrap' === $record->record_operation()
			&& hash_equals( $this->workflow_provider_code( $status ), $record->provider_code() )
			&& hash_equals( $status->provider_repository_id(), $record->repository_id() )
			&& hash_equals( $status->type(), $record->package_type() )
			&& hash_equals( $status->identifier(), $record->package_identifier() );
	}

	private function workflow_provider( string $provider_code ): ?RepositoryReleaseWorkflowManagementV3 {
		try {
			$provider = $this->providers->require_capability( $provider_code, RepositoryReleaseWorkflowManagementV3::class );
			$release  = $this->providers->get( $provider_code );
			return 3 === $provider::RELEASE_WORKFLOW_API_VERSION
				&& null !== ( ( $this->providers->metadata()[ $provider_code ] ?? null )->admin ?? null )
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseMetadata
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseCandidateListing
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseInspector
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseAcquirer
				&& $release instanceof \RAN\RepositoryProvider\RepositoryReleaseNativeTargets ? $provider : null;
		} catch ( Throwable ) {
			return null;
		}
	}

	private function workflow_provider_status( ReleaseTrackingStatus $status, RepositoryReleaseWorkflowTarget $target ): ?\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus {
		$provider_code = $this->workflow_provider_code( $status );
		$provider      = $this->workflow_provider( $provider_code );
		$value         = null === $provider ? null : $this->request_boundary( fn () => $provider->workflow_status( $target ), null );
		if ( null !== $value && ( $value->provider_code() !== $provider_code
			|| $value->repository_id() !== $status->provider_repository_id()
			|| ( $value->record_exact() && ( $value->package_type() !== $status->type() || $value->package_identifier() !== $status->identifier() || $value->source_revision() !== $status->source_revision() ) ) ) ) {
			return null;
		}
		return $value;
	}

	private function workflow_provider_code( ReleaseTrackingStatus $status ): string {
		$package = $this->workflow_package( $status->type(), $status->identifier(), $status->source_revision() );
		return null !== $package && $this->package_matches_status( $package, $status ) ? (string) $package->get_provider_code() : '';
	}

	/** @param array<string,string> $exception_context */
	private function request_boundary( callable $operation, mixed $failure, array $exception_context = array() ): mixed {
		$buffer_level = ob_get_level();
		ob_start();
		try {
			$result = $operation();
			ob_end_clean();
			return $result;
		} catch ( Throwable $exception ) {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			if ( array() !== $exception_context ) {
				BoosterLogger::log_exception( 'Provider release workflow request failed', $exception, $exception_context );
			}
			return $failure;
		}
	}

	private function return_url( string $type, string $identifier ): string {
		$args = array( 'page' => 'plugin' === $type ? 'ran-booster-plugins' : 'ran-booster-themes' );
		if ( '' !== $identifier ) {
			$args['package'] = $identifier;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function repository_release_url( string $repository_id, string $provider_code = '' ): string {
		return add_query_arg(
			array(
				'page'            => 'ran-booster',
				'tab'             => $provider_code,
				'panel'           => 'repositories',
				'repository'      => $repository_id,
				'repository_view' => 'releases',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available?:bool,correlation_reference:string,message:string,remediation:string} $outcome
	 * @return array<string, string>
	 */
	private function result_query_arguments( array $outcome, string $channel = '', int $source_revision = 0, string $provider_code = '', string $repository_id = '' ): array {
		$type                                 = in_array( $outcome['type'], array( 'plugin', 'theme' ), true ) ? $outcome['type'] : 'plugin';
		$identifier                           = strlen( $outcome['identifier'] ) <= 255 ? $outcome['identifier'] : '';
		$code                                 = sanitize_key( $outcome['code'] );
		$code                                 = strlen( $code ) <= 64 ? $code : 'invalid_request';
		$successful                           = $outcome['successful'];
		$channel                              = ( 'stable' === $channel ) ? $channel : '';
		$stage                                = in_array( $outcome['failure_stage'], array( 'request_validation', 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) ? $outcome['failure_stage'] : '';
		$diagnostic                           = $this->failure_diagnostic_code( $outcome['diagnostic_code'], $stage );
		$diagnostic_available                 = true === ( $outcome['diagnostic_available'] ?? false );
		$reference                            = $diagnostic_available && is_string( $outcome['correlation_reference'] ) && 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $outcome['correlation_reference'] ) ? $outcome['correlation_reference'] : '';
		$message                              = $this->result_display_text( $outcome['message'] ?? '' );
		$remediation                          = $this->result_display_text( $outcome['remediation'] ?? '' );
		$args                                 = array(
			self::RESULT_QUERY_KEY                      => $code,
			self::RESULT_SUCCESS_QUERY_KEY              => $successful ? '1' : '0',
			self::RESULT_TYPE_QUERY_KEY                 => $type,
			self::RESULT_PACKAGE_QUERY_KEY              => $identifier,
			self::RESULT_REVISION_QUERY_KEY             => (string) max( 0, $source_revision ),
			self::RESULT_PROVIDER_QUERY_KEY             => $provider_code,
			self::RESULT_REPOSITORY_QUERY_KEY           => $repository_id,
			self::RESULT_STAGE_QUERY_KEY                => $stage,
			self::RESULT_DIAGNOSTIC_QUERY_KEY           => $diagnostic,
			self::RESULT_DIAGNOSTIC_AVAILABLE_QUERY_KEY => $diagnostic_available ? '1' : '0',
			self::RESULT_REFERENCE_QUERY_KEY            => $reference,
			self::RESULT_MESSAGE_QUERY_KEY              => $message,
			self::RESULT_REMEDIATION_QUERY_KEY          => $remediation,
		);
		$args[ self::CHANNEL_QUERY_KEY ]      = $channel;
		$args[ self::RESULT_NONCE_QUERY_KEY ] = wp_create_nonce(
			$this->result_nonce_action( $code, $successful, $type, $identifier, max( 0, $source_revision ), $channel, $stage, $diagnostic, $diagnostic_available, $reference, $provider_code, $repository_id, $message, $remediation )
		);

		return $args;
	}

	/** @return array{code:string,successful:bool,type:string,identifier:string,source_revision:int,channel:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string,message:string,remediation:string}|null */

	public function requested_result(): ?array {
		$values = array();
		foreach ( array( self::RESULT_QUERY_KEY, self::RESULT_SUCCESS_QUERY_KEY, self::RESULT_TYPE_QUERY_KEY, self::RESULT_PACKAGE_QUERY_KEY, self::RESULT_REVISION_QUERY_KEY, self::RESULT_PROVIDER_QUERY_KEY, self::RESULT_REPOSITORY_QUERY_KEY, self::CHANNEL_QUERY_KEY, self::RESULT_STAGE_QUERY_KEY, self::RESULT_DIAGNOSTIC_QUERY_KEY, self::RESULT_DIAGNOSTIC_AVAILABLE_QUERY_KEY, self::RESULT_REFERENCE_QUERY_KEY, self::RESULT_MESSAGE_QUERY_KEY, self::RESULT_REMEDIATION_QUERY_KEY, self::RESULT_NONCE_QUERY_KEY ) as $key ) {
			$value = $_GET[ $key ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
			if ( ! is_string( $value ) ) {
				return null;
			}
			$values[] = wp_unslash( $value );
		}
		[ $code, $success, $type, $identifier, $revision, $provider, $repository, $channel, $stage, $diagnostic, $available, $reference, $message, $remediation, $nonce ] = $values;
		if ( sanitize_key( $code ) !== $code || '' === $code || strlen( $code ) > 64
			|| sanitize_key( $provider ) !== $provider || strlen( $provider ) > 32
			|| strlen( $repository ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $repository )
			|| ! in_array( $success, array( '0', '1' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| sanitize_text_field( $identifier ) !== $identifier || strlen( $identifier ) > 255
			|| 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,9})\z/D', $revision )
			|| ! in_array( $channel, array( '', 'stable' ), true )
			|| ! in_array( $stage, array( '', 'request_validation', 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true )
			|| ! in_array( $diagnostic, array( '', ...self::FAILURE_DIAGNOSTIC_CODES ), true )
			|| ! in_array( $available, array( '0', '1' ), true )
			|| $message !== $this->result_display_text( $message ) || $remediation !== $this->result_display_text( $remediation )
			|| ( '' !== $reference && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $reference ) ) ) {
			return null;
		}

		$successful           = '1' === $success;
		$diagnostic_available = '1' === $available;
		if ( 1 !== wp_verify_nonce( $nonce, $this->result_nonce_action( $code, $successful, $type, $identifier, (int) $revision, $channel, $stage, $diagnostic, $diagnostic_available, $reference, $provider, $repository, $message, $remediation ) ) ) {
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
			'diagnostic_available'  => $diagnostic_available,
			'correlation_reference' => $reference,
			'message'               => $message,
			'remediation'           => $remediation,
		);
	}

	/** Return the normalized opaque preview key for GET-side projection. */

	public function requested_preview_key(): string {
		$value = $_GET['ran_booster_release_workflow_preview'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opaque read-only preview lookup.
		$value = is_string( $value ) ? sanitize_key( wp_unslash( $value ) ) : '';

		return 1 === preg_match( '/\\A[a-f0-9]{32}\\z/D', $value ) ? $value : '';
	}

	private function result_nonce_action( string $code, bool $successful, string $type, string $identifier, int $source_revision, string $channel, string $stage = '', string $diagnostic = '', bool $diagnostic_available = false, string $reference = '', string $provider_code = '', string $repository_id = '', string $message = '', string $remediation = '' ): string {
		$payload = wp_json_encode( array( $code, $successful, $type, $identifier, $source_revision, $channel, $stage, $diagnostic, $diagnostic_available, $reference, $provider_code, $repository_id, $message, $remediation ) );

		return self::RESULT_NONCE_ACTION . hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	private function result_display_text( mixed $value ): string {
		return is_string( $value ) && strlen( $value ) <= 512 && 0 === preg_match( '/[<>\x00-\x1F\x7F]/', $value ) ? $value : '';
	}

	/** @param array{code:string,successful:bool,type:string,identifier:string,channel:string} $result */

	public function result_matches_current_screen( array $result ): bool {
		$page_value = $_GET['page'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen binding for a verified result.
		$package    = $_GET['package'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen binding for a verified result.
		if ( ! is_string( $page_value ) ) {
			return false;
		}

		$page          = sanitize_key( wp_unslash( $page_value ) );
		$package_page  = 'plugin' === $result['type'] ? 'ran-booster-plugins' : 'ran-booster-themes';
		$creation_page = $package_page . '-create';
		if ( $creation_page === $page ) {
			return ! $result['successful'];
		}
		if ( $package_page !== $page ) {
			return false;
		}

		return ! is_string( $package ) || '' === $package
			|| sanitize_text_field( wp_unslash( $package ) ) === $result['identifier'];
	}

	/** @param array<string, mixed> $request */
	private function release_channel_from( array $request ): string {
		$channel = is_string( $request['release_channel'] ?? null ) ? sanitize_key( wp_unslash( $request['release_channel'] ) ) : '';

		return ( 'stable' === $channel ) ? $channel : '';
	}
}
