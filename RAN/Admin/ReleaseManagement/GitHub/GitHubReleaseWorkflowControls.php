<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement\GitHub;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Admin\ReleaseManagement\ReleaseTrackingOperations;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\GitHubRepositoryClient;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\TemplatePackRepositoryClient;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\WorkflowApplicationCoordinator;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Throwable;

/** @internal GitHub-specific release workflow routes and presentation. */
final class GitHubReleaseWorkflowControls {
	private const RESULT_QUERY_KEY         = 'ran_booster_github_release_workflow_result';
	private const RESULT_SUCCESS_QUERY_KEY = 'ran_booster_github_release_workflow_success';
	private const RESULT_TYPE_QUERY_KEY    = 'ran_booster_github_release_workflow_type';
	private const RESULT_PACKAGE_QUERY_KEY = 'ran_booster_github_release_workflow_package';
	private const RESULT_NONCE_QUERY_KEY   = 'ran_booster_github_release_workflow_result_nonce';
	private const RESULT_NONCE_ACTION      = 'ran-booster-github-release-workflow-result-';
	private const PREVIEW_QUERY_KEY        = 'ran_booster_github_release_workflow_preview';
	private const CHANNEL_QUERY_KEY        = 'ran_booster_github_release_workflow_channel';

	private readonly ReleaseTrackingOperations $tracking;
	private readonly WorkflowApplicationCoordinator $applications;
	private readonly SetupRecordStore $workflowRecords;
	private readonly GitHubReleaseWorkflowDisplay $display;

	public function __construct(
		private readonly ReleaseTrackingFacade $releases,
		private readonly PluginRepository $plugins,
		private readonly ThemeRepository $themes
	) {
		$this->tracking        = new ReleaseTrackingOperations( $releases );
		$this->workflowRecords = new SetupRecordStore();
		$this->applications    = new WorkflowApplicationCoordinator( $releases, new GitHubRepositoryClient(), new TemplatePackRepositoryClient(), new SourceReadyAssessor(), $this->workflowRecords );
		$this->display         = new GitHubReleaseWorkflowDisplay();
	}

	public function register(): void {
		add_action( 'ran_booster_admin_package_advanced_source_sections', array( $this, 'renderAdvancedSourceSection' ), 20, 5 );
		add_action( 'admin_post_ran_booster_github_release_workflow_inspect', array( $this, 'handleWorkflowInspect' ) );
		add_action( 'admin_post_ran_booster_github_release_workflow_setup', array( $this, 'handleWorkflowSetup' ) );
		add_action( 'admin_post_ran_booster_github_release_workflow_outcome', array( $this, 'handleWorkflowOutcome' ) );
		add_action( 'admin_post_ran_booster_github_release_workflow_update_inspect', array( $this, 'handleWorkflowUpdateInspect' ) );
		add_action( 'admin_post_ran_booster_github_release_workflow_update_setup', array( $this, 'handleWorkflowUpdateSetup' ) );
	}

	public function renderAdvancedSourceSection( string $mode, string $type, string $selectedSource, ?object $package, string $pageUrl ): void {
		unset( $pageUrl );
		if ( 'edit' !== $mode || 'release_asset' !== $selectedSource || null === $package
			|| ! is_callable( array( $package, 'providerCode' ) ) || 'gh' !== (string) $package->providerCode() ) {
			return;
		}
		$result = $this->requestedResult();
		if ( null !== $result && ! $this->resultMatchesCurrentScreen( $result ) ) {
			$result = null;
		}
		$code = is_array( $result ) && str_starts_with( (string) ( $result['code'] ?? '' ), 'workflow_' ) ? (string) $result['code'] : '';
		$view = $this->requestBoundary(
			fn (): ?array => $this->workflowView( $package, $code, true === ( $result['successful'] ?? false ), $this->requestedPreviewKey(), (string) ( $result['channel'] ?? '' ) ),
			$this->unavailableWorkflowView( __( 'Booster could not read the local Published release readiness for this package. Try again after reviewing its settings.', 'ran-booster' ) )
		);
		if ( is_array( $view ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The display owner escapes its complete projection.
			echo $this->display->workflow( $view );
		}
	}

	public function handleWorkflowInspect(): never {
		$this->handleWorkflow( 'inspect' );
	}

	public function handleWorkflowSetup(): never {
		$this->handleWorkflow( 'setup' );
	}

	public function handleWorkflowOutcome(): never {
		$this->handleWorkflow( 'outcome' );
	}

	public function handleWorkflowUpdateInspect(): never {
		$this->handleWorkflow( 'update_inspect' );
	}

	public function handleWorkflowUpdateSetup(): never {
		$this->handleWorkflow( 'update_setup' );
	}


	private function handleWorkflow( string $operation ): never {
		// This controller validates the exact local authority and purpose nonce before reading request-only secrets.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request = is_array( $_POST ) ? $_POST : array();
		$this->redirectTo( $this->processWorkflowRequest( $operation, $request ) );
	}

	private function redirectTo( string $url ): never {
		$hxRequest = $_SERVER['HTTP_HX_REQUEST'] ?? null;
		if ( is_string( $hxRequest ) && 'true' === strtolower( $hxRequest ) ) {
			$location = wp_json_encode(
				array(
					'path'   => $url,
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
	public function processWorkflowRequest( string $operation, #[\SensitiveParameter] array $request ): string {
		$type       = $this->workflowType( $request );
		$identifier = $this->workflowIdentifier( $request );
		$revision   = $this->workflowRevision( $request );
		$preview    = $this->workflowPreview( $request );
		$nonce      = is_string( $request['_wpnonce'] ?? null ) ? wp_unslash( $request['_wpnonce'] ) : '';
		$channel    = 'inspect' === $operation ? $this->releaseChannelFrom( $request ) : '';
		$outcome    = $this->workflowResult( $type, $identifier, 'workflow_invalid_request', false );
		$operations = array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' );

		if ( in_array( $operation, $operations, true ) && '' !== $type && '' !== $identifier && $revision > 0
			&& null !== $preview && '' !== $nonce && ( 'inspect' !== $operation || '' !== $channel )
			&& current_user_can( 'manage_options' ) && current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' )
			&& $this->packageUsesBundledGitHub( $type, $identifier, $revision ) ) {
			if ( null === $this->releases || null === $this->applications ) {
				$outcome = $this->workflowResult( $type, $identifier, 'workflow_remote_unavailable', false );
			} else {
				$bootstrap = in_array( $operation, array( 'inspect', 'setup' ), true );
				$status    = $this->requestBoundary( fn (): ?ReleaseTrackingStatus => $this->workflowStatus( $type, $identifier, $revision, $bootstrap ), null );
				if ( null !== $status && 1 === wp_verify_nonce( $nonce, $this->workflowNonceAction( $operation, $status, $preview ) ) ) {
					// Request-only secrets and Core preflight nonces are deliberately unread until local authority is proven.
					$token        = is_string( $request['github_token'] ?? null ) ? wp_unslash( $request['github_token'] ) : '';
					$confirmation = is_string( $request['confirm_repository'] ?? null ) ? wp_unslash( $request['confirm_repository'] ) : '';
					$retry        = $this->workflowResult( $type, $identifier, 'workflow_remote_unavailable', false, $preview );
					if ( 'setup' === $operation ) {
						$projection = $this->requestBoundary( fn (): ?array => $this->applications?->preview( $preview, $status ), null );
						$channel    = is_array( $projection ) && is_string( $projection['preflight_channel'] ?? null )
							? $this->releaseChannelFrom( array( 'release_channel' => $projection['preflight_channel'] ) ) : '';
					}
					$outcome = $this->requestBoundary(
						fn (): array => match ( $operation ) {
							'inspect' => $this->applications->inspect( $status, $channel, $this->workflowPreflightNonce( $request, $channel ), $token ),
							'setup' => $this->applications->setup(
								$status,
								$preview,
								$confirmation,
								array(
									'stable'     => $this->workflowPreflightNonce( $request, 'stable' ),
									'prerelease' => $this->workflowPreflightNonce( $request, 'prerelease' ),
								),
								$token
							),
							'outcome' => $this->applications->outcome( $status, $token ),
							'update_inspect' => $this->applications->inspectUpdate( $status, $token ),
							'update_setup' => $this->applications->setupUpdate( $status, $preview, $confirmation, $token ),
						},
						$retry
					);
				}
			}
		}

		$args = $this->resultQueryArguments( $outcome, $channel );
		if ( '' !== $outcome['preview_key'] ) {
			$args[ self::PREVIEW_QUERY_KEY ] = $outcome['preview_key'];
		}
		$args['source_view'] = 'release_asset';

		return add_query_arg( $args, $this->returnUrl( $outcome['type'], $outcome['identifier'], true ) )
			. '#ran-booster-advanced-source-settings';
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

	private function workflowStatus( string $type, string $identifier, int $revision, bool $requireBranch ): ?ReleaseTrackingStatus {
		$status = $this->releases?->status( $type, $identifier );
		if ( ! $status instanceof ReleaseTrackingStatus || ! $status->eligible() || $revision !== $status->sourceRevision()
			|| ! hash_equals( $type, $status->type() ) || ! hash_equals( $identifier, $status->identifier() )
			|| ( $requireBranch && 'branch' !== $status->source() ) ) {
			return null;
		}

		return $status;
	}

	private function workflowNonceAction( string $operation, ReleaseTrackingStatus $status, string $preview = '' ): string {
		return 'ran-booster-github-release-workflow-' . $operation . '-' . hash(
			'sha256',
			implode( '|', array( $status->type(), $status->identifier(), $status->sourceRevision(), $status->providerRepositoryId(), $preview ) )
		);
	}

	/** @param array<string, mixed> $request */
	private function workflowPreflightNonce( array $request, string $channel ): string {
		$key = 'core_preflight_nonce_' . $channel;

		return in_array( $channel, array( 'stable', 'prerelease' ), true ) && is_string( $request[ $key ] ?? null )
			? wp_unslash( $request[ $key ] ) : '';
	}

	/** @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string} */
	private function workflowResult( string $type, string $identifier, string $code, bool $successful, string $preview = '' ): array {
		return array(
			'type'        => $type,
			'identifier'  => $identifier,
			'code'        => $code,
			'successful'  => $successful,
			'preview_key' => $preview,
		);
	}

	private function packageUsesBundledGitHub( string $type, string $identifier, int $revision ): bool {
		try {
			$package = 'plugin' === $type
				? $this->plugins->boosterPluginFromFile( $identifier )
				: $this->themes->boosterThemeFromStylesheet( $identifier );

			return $revision === $package->getSourceRevision()
				&& 'gh' === (string) $package->getProviderCode();
		} catch ( Throwable ) {
			return false;
		}
	}

	/** @return array<string,mixed>|null */
	private function workflowView( object $package, string $code, bool $successful, string $previewKey, string $channel ): ?array {
		if ( null === $this->applications || null === $this->workflowRecords
			|| ! is_callable( array( $package, 'type' ) ) || ! is_callable( array( $package, 'identifier' ) )
			|| ! is_callable( array( $package, 'sourceRevision' ) ) ) {
			return null;
		}
		$type       = $package->type();
		$identifier = $package->identifier();
		$revision   = $package->sourceRevision();
		if ( ! is_string( $type ) || ! is_string( $identifier ) || ! is_int( $revision ) ) {
			return null;
		}
		$status = $this->workflowDisplayStatus( $type, $identifier, $revision );
		if ( null === $status ) {
			return $this->unavailableWorkflowView( __( 'Booster could not confirm the local Published release readiness for this package. Try again after reviewing its settings.', 'ran-booster' ) );
		}
		if ( ! $status->eligible() ) {
			return $this->unavailableWorkflowView( $this->workflowUnavailableReason( $status ), $code, $successful );
		}

		$channel = in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : $status->channel();
		$preview = '' === $previewKey ? null : $this->applications->preview( $previewKey, $status );
		$record  = $this->workflowRecords->find( $status->providerRepositoryId() );
		$legacy  = null === $record
			? $this->workflowRecords->legacyEvidence( $status->providerRepositoryId(), $status->type(), $status->identifier(), $status->sourceRevision() ) : null;
		if ( null === $record && null === $legacy && 'release_asset' === $status->source() ) {
			return $this->unavailableWorkflowView(
				__( 'Release workflow setup is available before switching from Branch deployments. Return to Branch before assessing setup again.', 'ran-booster' ),
				$code,
				$successful
			);
		}
		$forms = array();
		if ( null !== $preview ) {
			$operation           = 'template_update' === $preview['kind'] ? 'update_setup' : 'setup';
			$forms[ $operation ] = $this->workflowForm(
				$operation,
				$status,
				$previewKey,
				$preview['repository'],
				$preview['preflight_channel']
			);
		} elseif ( null === $record && 'branch' === $status->source() ) {
			$forms['inspect'] = $this->workflowForm( 'inspect', $status, '', '', $channel );
		}
		if ( null !== $record ) {
			$forms['outcome']        = $this->workflowForm( 'outcome', $status );
			$forms['update_inspect'] = $this->workflowForm( 'update_inspect', $status );
		}

		return array(
			'result_code'       => $code,
			'result_successful' => $successful,
			'preview'           => $preview,
			'record'            => $record,
			'legacy'            => $legacy,
			'forms'             => array_filter( $forms, 'is_array' ),
		);
	}

	/** @return array<string,mixed> */
	private function unavailableWorkflowView( string $reason, string $code = '', bool $successful = false ): array {
		return array(
			'result_code'        => $code,
			'result_successful'  => $successful,
			'unavailable'        => true,
			'unavailable_reason' => $reason,
			'preview'            => null,
			'record'             => null,
			'legacy'             => null,
			'forms'              => array(),
		);
	}

	/** Render-only identity check. POST requests continue through workflowStatus(). */
	private function workflowDisplayStatus( string $type, string $identifier, int $revision ): ?ReleaseTrackingStatus {
		$status = $this->releases?->status( $type, $identifier );
		if ( ! $status instanceof ReleaseTrackingStatus || $revision !== $status->sourceRevision()
			|| ! hash_equals( $type, $status->type() ) || ! hash_equals( $identifier, $status->identifier() ) ) {
			return null;
		}

		return $status;
	}

	private function workflowUnavailableReason( ReleaseTrackingStatus $status ): string {
		return match ( $status->eligibility()->code() ) {
			'missing_update_uri' => __( 'This package needs the exact Update URI shown in Published release readiness above.', 'ran-booster' ),
			'mismatched_update_uri' => __( 'This package Update URI must match the configured repository.', 'ran-booster' ),
			'unsupported_provider' => __( 'This repository provider cannot use published-release tracking.', 'ran-booster' ),
			'invalid_repository' => __( 'The saved repository needs attention before release automation can be assessed.', 'ran-booster' ),
			'invalid_package_identity' => __( 'The installed package identity must match the configured repository.', 'ran-booster' ),
			'subdirectory_not_supported' => __( 'Published releases require this package at the repository root; continue using Branch deployments for a repository subdirectory.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'This package already has its own release updater, so Booster cannot manage published releases as well.', 'ran-booster' ),
			default => __( 'Resolve the Published release readiness requirements above before assessing release automation.', 'ran-booster' ),
		};
	}

	/** @return array<string,mixed>|null */
	private function workflowForm(
		string $operation,
		ReleaseTrackingStatus $status,
		string $preview = '',
		string $confirmation = '',
		string $channel = ''
	): ?array {
		$preflight = '';
		if ( in_array( $operation, array( 'inspect', 'setup' ), true ) ) {
			if ( null === $this->releases || ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
				return null;
			}
			$action = $this->releases->nonceAction( 'preflight', $status->type(), $status->identifier(), $status->sourceRevision(), $channel );
			if ( '' === $action ) {
				return null;
			}
			$preflight = wp_create_nonce( $action );
		}
		$fields = array(
			'action'                   => 'ran_booster_github_release_workflow_' . $operation,
			'_wpnonce'                 => wp_create_nonce( $this->workflowNonceAction( $operation, $status, $preview ) ),
			'expected_type'            => $status->type(),
			'expected_identifier'      => $status->identifier(),
			'expected_source_revision' => (string) $status->sourceRevision(),
		);
		if ( '' !== $preview ) {
			$fields['preview_key'] = $preview;
		}
		if ( 'inspect' === $operation ) {
			$fields['release_channel'] = $channel;
		}
		if ( '' !== $preflight ) {
			$fields[ 'core_preflight_nonce_' . $channel ] = $preflight;
		}

		return array(
			'operation' => $operation,
			'action'    => admin_url( 'admin-post.php' ),
			'fields'    => $fields,
			'confirm'   => $confirmation,
		);
	}


	private function requestBoundary( callable $operation, mixed $failure ): mixed {
		$bufferLevel = ob_get_level();
		ob_start();
		try {
			$result = $operation();
			$output = ob_get_clean();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured output was escaped by the component renderer.
			echo $output;
			return $result;
		} catch ( Throwable ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}
			return $failure;
		}
	}


	private function returnUrl( string $type, string $identifier, bool $settings ): string {
		$page = 'plugin' === $type ? 'ran-booster-plugins' : 'ran-booster-themes';
		$args = array( 'page' => $page );
		if ( $settings && '' !== $identifier ) {
			$args['package'] = $identifier;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * @param array{type:string,identifier:string,code:string,successful:bool} $outcome
	 * @return array<string, string>
	 */
	private function resultQueryArguments( array $outcome, string $channel = '' ): array {
		$type                                 = in_array( $outcome['type'], array( 'plugin', 'theme' ), true ) ? $outcome['type'] : 'plugin';
		$identifier                           = strlen( $outcome['identifier'] ) <= 255 ? $outcome['identifier'] : '';
		$code                                 = sanitize_key( $outcome['code'] );
		$code                                 = strlen( $code ) <= 64 ? $code : 'invalid_request';
		$successful                           = $outcome['successful'];
		$channel                              = in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : '';
		$args                                 = array(
			self::RESULT_QUERY_KEY         => $code,
			self::RESULT_SUCCESS_QUERY_KEY => $successful ? '1' : '0',
			self::RESULT_TYPE_QUERY_KEY    => $type,
			self::RESULT_PACKAGE_QUERY_KEY => $identifier,
		);
		$args[ self::CHANNEL_QUERY_KEY ]      = $channel;
		$args[ self::RESULT_NONCE_QUERY_KEY ] = wp_create_nonce(
			$this->resultNonceAction( $code, $successful, $type, $identifier, $channel )
		);

		return $args;
	}

	/** @return array{code:string,successful:bool,type:string,identifier:string,channel:string}|null */
	private function requestedResult(): ?array {
		$rawCode       = $_GET[ self::RESULT_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawSuccess    = $_GET[ self::RESULT_SUCCESS_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawType       = $_GET[ self::RESULT_TYPE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawIdentifier = $_GET[ self::RESULT_PACKAGE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawChannel    = $_GET[ self::CHANNEL_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawNonce      = $_GET[ self::RESULT_NONCE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification value for this PRG result.
		if ( ! is_string( $rawCode ) || ! is_string( $rawSuccess ) || ! is_string( $rawType )
			|| ! is_string( $rawIdentifier ) || ! is_string( $rawChannel ) || ! is_string( $rawNonce ) ) {
			return null;
		}

		$code       = wp_unslash( $rawCode );
		$success    = wp_unslash( $rawSuccess );
		$type       = wp_unslash( $rawType );
		$identifier = wp_unslash( $rawIdentifier );
		$channel    = wp_unslash( $rawChannel );
		$nonce      = wp_unslash( $rawNonce );
		if ( $code !== sanitize_key( $code ) || '' === $code || strlen( $code ) > 64
			|| ! in_array( $success, array( '0', '1' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| $identifier !== sanitize_text_field( $identifier ) || strlen( $identifier ) > 255
			|| ! in_array( $channel, array( '', 'stable', 'prerelease' ), true ) ) {
			return null;
		}

		$successful = '1' === $success;
		if ( 1 !== wp_verify_nonce( $nonce, $this->resultNonceAction( $code, $successful, $type, $identifier, $channel ) ) ) {
			return null;
		}

		return array(
			'code'       => $code,
			'successful' => $successful,
			'type'       => $type,
			'identifier' => $identifier,
			'channel'    => $channel,
		);
	}

	private function resultNonceAction( string $code, bool $successful, string $type, string $identifier, string $channel ): string {
		$payload = wp_json_encode( array( $code, $successful, $type, $identifier, $channel ) );

		return self::RESULT_NONCE_ACTION . hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	/** @param array{code:string,successful:bool,type:string,identifier:string,channel:string} $result */
	private function resultMatchesCurrentScreen( array $result ): bool {
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

	private function requestedPreviewKey(): string {
		$value = isset( $_GET[ self::PREVIEW_QUERY_KEY ] ) && is_string( $_GET[ self::PREVIEW_QUERY_KEY ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Opaque read-only preview lookup.
			? sanitize_key( wp_unslash( $_GET[ self::PREVIEW_QUERY_KEY ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		return 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $value ) ? $value : '';
	}

	/** @param array<string, mixed> $request */
	private function releaseChannelFrom( array $request ): string {
		$channel = is_string( $request['release_channel'] ?? null ) ? sanitize_key( wp_unslash( $request['release_channel'] ) ) : '';

		return in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : '';
	}
}
