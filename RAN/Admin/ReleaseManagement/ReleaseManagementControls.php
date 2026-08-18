<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use Throwable;

/** @internal Fixed Core placement for repository release capabilities. */
final class ReleaseManagementControls {
	private const RESULT_QUERY_KEY = 'ran_booster_release_result';

	private const RESULT_SUCCESS_QUERY_KEY = 'ran_booster_release_success';
	private const RESULT_TYPE_QUERY_KEY    = 'ran_booster_release_type';
	private const RESULT_PACKAGE_QUERY_KEY = 'ran_booster_release_package';
	private const RESULT_NONCE_QUERY_KEY   = 'ran_booster_release_result_nonce';
	private const RESULT_NONCE_ACTION      = 'ran-booster-result-';
	private const CHANNEL_QUERY_KEY        = 'ran_booster_release_channel';

	private readonly ProspectiveReleaseOperations $prospectiveOperations;
	private readonly ReleaseTrackingFacade $releases;
	private readonly ReleaseTrackingOperations $tracking;
	private readonly ReleaseManagementDisplay $display;

	public function __construct(
		ReleaseTrackingFacade $releases,
		ProspectiveReleaseFacade $prospective
	) {
		$this->display               = new ReleaseManagementDisplay();
		$this->releases              = $releases;
		$this->tracking              = new ReleaseTrackingOperations( $releases );
		$this->prospectiveOperations = new ProspectiveReleaseOperations( $prospective );
	}

	public function register(): void {
		add_filter( 'ran_booster_admin_package_management_rows', array( $this, 'filterManagementRows' ), 10, 3 );
		add_filter( 'ran_booster_admin_package_management_actions', array( $this, 'filterManagementActions' ), 10, 3 );
		add_filter( 'ran_booster_admin_package_source_choices', array( $this, 'filterSourceChoices' ), 10, 5 );
		add_filter( 'ran_booster_admin_package_advanced_source_summary', array( $this, 'filterAdvancedSourceSummary' ), 10, 5 );
		add_filter( 'ran_booster_documentation_sections_before_about', array( $this, 'filterDocumentationSections' ), 10, 3 );
		add_action( 'ran_booster_admin_package_advanced_source_sections', array( $this, 'renderAdvancedSourceSection' ), 10, 5 );
		add_action( 'admin_notices', array( $this, 'renderOperationNotice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueProspectiveAssets' ) );

		add_action( 'admin_post_ran_booster_release_enable', array( $this, 'handleEnable' ) );
		add_action( 'admin_post_ran_booster_release_refresh', array( $this, 'handleRefresh' ) );
		add_action( 'admin_post_ran_booster_release_return_to_branch', array( $this, 'handleReturnToBranch' ) );
		add_action( 'admin_post_ran_booster_release_change_channel', array( $this, 'handleChangeChannel' ) );
		add_action( 'admin_post_ran_booster_release_install', array( $this, 'handleProspectiveInstall' ) );
		add_action( 'wp_ajax_ran_booster_release_list_candidates', array( $this, 'handleProspectiveListCandidates' ) );
		add_action( 'wp_ajax_ran_booster_release_inspect', array( $this, 'handleProspectiveInspect' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param list<object>                        $packages
	 * @return array<string, array<string, mixed>>
	 */
	public function filterManagementRows( array $rows, string $surface, array $packages ): array {
		if ( null === $this->tracking ) {
			return $rows;
		}
		$coordinates = $this->requestBoundary( fn (): array => $this->managementCoordinates( $surface, $rows, $packages ), array() );
		if ( array() === $coordinates ) {
			return $rows;
		}
		$statuses = $this->requestBoundary( fn (): array => $this->tracking->statuses( $surface, $coordinates['identifiers'], $coordinates['revisions'] ), null );
		if ( ! is_array( $statuses ) ) {
			return $rows;
		}

		return $this->requestBoundary( fn (): array => $this->display->presentManagement( $rows, $surface, $packages, $statuses ), $rows );
	}

	/**
	 * @param array<string, array<string, mixed>> $actions
	 * @return array<string, array<string, mixed>>
	 */
	public function filterManagementActions( array $actions, string $surface, object $package ): array {
		if ( null === $this->tracking ) {
			return $actions;
		}
		$status = $this->packageStatus( $package );
		$action = null !== $status ? $this->packageNonceAction( 'refresh', $package ) : null;
		$nonce  = null === $action ? null : wp_create_nonce( $action );

		return $this->requestBoundary( fn (): array => $this->display->presentManagementActions( $actions, $surface, $package, $status, $nonce ), $actions );
	}

	/**
	 * Hydrate Core's published-release source choice without replacing its layout.
	 *
	 * @param array<string, array<string, mixed>> $choices
	 * @return array<string, array<string, mixed>>
	 */
	public function filterSourceChoices(
		array $choices,
		string $mode,
		string $type,
		?object $package,
		string $pageUrl
	): array {
		unset( $type );
		if ( null === $this->tracking || ! isset( $choices['release_asset'] ) ) {
			return $choices;
		}
		if ( 'create' === $mode && null === $this->prospectiveOperations ) {
			return $choices;
		}

		$choices['release_asset'] = array_merge(
			$choices['release_asset'],
			array(
				'heading'           => __( 'Published releases', 'ran-booster' ),
				'description'       => 'create' === $mode
					? __( 'Choose a repository, then view its eligible published releases.', 'ran-booster' )
					: __( 'Track verified release assets and install them through WordPress.', 'ran-booster' ),
				'meta'              => 'create' === $mode
					? __( 'Choose repository first', 'ran-booster' )
					: __( 'Published releases', 'ran-booster' ),
				'url'               => 'edit' === $mode ? add_query_arg( 'source_view', 'release_asset', $pageUrl ) : '',
				'disabled'          => 'create' === $mode,
				'hydrated'          => true,
				'client_hydratable' => 'create' === $mode,
			)
		);
		if ( 'edit' === $mode && null !== $package ) {
			$status                           = $this->packageStatus( $package );
			$choices['release_asset']['meta'] = $this->requestBoundary( fn (): string => $this->display->releaseTrackMeta( $choices['release_asset']['meta'], $package, $status ), $choices['release_asset']['meta'] );
			$releaseSourceIsCurrent           = is_callable( array( $package, 'source' ) )
				&& 'release_asset' === $package->source();
			if ( ! $releaseSourceIsCurrent
				&& false === $this->display->releaseProviderSupported( $package, $status ) ) {
				$choices['release_asset']['description'] = __( 'Published releases are not available for this repository provider.', 'ran-booster' );
				$choices['release_asset']['meta']        = __( 'Provider capability unavailable', 'ran-booster' );
				$choices['release_asset']['disabled']    = true;
			}
		}

		return $choices;
	}

	public function renderAdvancedSourceSection(
		string $mode,
		string $type,
		string $selectedSource,
		?object $package,
		string $pageUrl
	): void {
		if ( null === $this->tracking || ( 'create' === $mode && null === $this->prospectiveOperations ) ) {
			return;
		}

		$result = $this->requestedResult();
		if ( null !== $result && ( 'edit' !== $mode || null === $package
			|| $result['type'] !== $type || ! is_callable( array( $package, 'identifier' ) )
			|| $result['identifier'] !== $package->identifier() ) ) {
			$result = null;
		}
		$code        = $result['code'] ?? '';
		$releasePane = 'edit' === $mode && 'release_asset' === $selectedSource && null !== $package;
		if ( $releasePane ) {
			?>
			<div
				id="ran-booster-source-pane-release_asset"
				class="ran-booster-package-source-pane ran-booster-release-pane"
				aria-labelledby="ran-booster-source-tab-release_asset"
				data-ran-booster-source-pane="release_asset"
			>
			<?php
		}

		if ( 'edit' === $mode && '' !== $code ) {
			$this->requestBoundary( fn () => $this->display->renderOperationNotice( $code, $result['successful'], $result['type'], $result['identifier'], $result['channel'], null === $package ? null : $this->packageStatus( $package ) ), null );
		}
		$status      = null === $package ? null : $this->packageStatus( $package );
		$nonces      = null === $package ? array() : $this->packageNonceActions( $package );
		$prospective = $this->prospectiveProjection( $type );
		$recheck     = isset( $_GET['ran_booster_release_recheck'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI marker.
			&& is_scalar( $_GET['ran_booster_release_recheck'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& '1' === (string) $_GET['ran_booster_release_recheck']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->requestBoundary( fn () => $this->display->renderAdvancedSourceSection( $mode, $type, $selectedSource, $package, $status, $pageUrl, $result['channel'] ?? '', $nonces, $prospective, $recheck ), null );
		if ( $releasePane ) {
			?>
			</div>
			<?php
		}
	}

	public function filterAdvancedSourceSummary(
		string $summary,
		string $mode,
		string $type,
		string $selectedSource,
		?object $package
	): string {
		unset( $type );
		if ( null === $this->tracking ) {
			return $summary;
		}

		return $this->requestBoundary( fn (): string => $this->display->advancedSourceSummary( $summary, $mode, $selectedSource, $package, null === $package ? null : $this->packageStatus( $package ) ), $summary );
	}

	public function renderOperationNotice(): void {
		$result = $this->requestedResult();
		$code   = $result['code'] ?? '';
		if ( '' === $code || null === $this->tracking || ! $this->resultMatchesCurrentScreen( $result ) || $this->isPackageSettingsRequest() ) {
			return;
		}

		$status = $this->requestBoundary( fn (): ?ReleaseTrackingStatus => $this->tracking->freshStatus( $result['type'], $result['identifier'] ), null );
		$this->requestBoundary( fn () => $this->display->renderOperationNotice( $code, $result['successful'], $result['type'], $result['identifier'], $result['channel'], $status ), null );
	}

	/** @param list<array<string, mixed>> $sections @return list<array<string, mixed>> */
	public function filterDocumentationSections( array $sections, string $documentationUrl, string $scope ): array {
		unset( $documentationUrl, $scope );
		$sections[] = array(
			'id'      => 'ran-booster-documentation-published-releases',
			'summary' => __( 'Published releases', 'ran-booster' ),
			'content' => array( $this, 'renderDocumentationContent' ),
		);

		return $sections;
	}

	public function renderDocumentationContent(): void {
		?>
				<p><?php esc_html_e( 'Published release management lets an eligible Booster-managed plugin or theme follow exact uploaded release ZIPs instead of a repository branch.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Prepare an eligible release', 'ran-booster' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Publish exactly one installable plugin or theme ZIP as a release asset, not as a generated source archive.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Keep the release tag and canonical WordPress plugin or theme Version header aligned. A mismatch fails closed.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Declare the exact repository in the package Update URI expected by its provider. Plugins use one eligible top-level plugin header; themes use root style.css.', 'ran-booster' ); ?></li>
				</ul>
				<h3><?php esc_html_e( 'Choose and operate the source', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Prospective installation uses the Stable track by default. Choose Preview only when alpha, beta or release-candidate builds are acceptable. Preview still excludes drafts.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'For a not-yet-managed package, candidate listing is metadata-only. Choose one of at most eight eligible releases; inspection downloads, validates and discards that exact ZIP, then binds the reviewed choice to installation with a fingerprint.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Install performs a fresh exact acquisition. Before WordPress changes files, Booster rechecks fingerprint continuity, archive shape and size, provider and local digests, headers, Update URI and package identity. WordPress installs synchronously, then Booster verifies the installed identity and unchanged target activation before adoption.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Open the managed package settings, review eligibility and package root, then validate and switch to published releases. Enabling the source always performs a fresh exact preflight; merely viewing the page cannot authorize the transition.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Switching either source preserves Disabled and Manual automation, resets Automatic to Manual, and leaves repository webhook configuration unchanged.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Use Check published releases on the managed Plugins or Themes screen to refresh release metadata. Booster validates the candidate, but WordPress remains the installer. Open WordPress updates to use the administrator’s normal WordPress update workflow.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Recovery and support', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Installed but unmanaged means a package now exists but Booster did not adopt it. Verify its installed version and activation state before using Link installed or retrying. An uncertain state or cleanup failure does not claim installation success: inspect installed packages and Booster management before retrying.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'If a release is blocked, publish a corrected release rather than editing installed metadata. You can return the package to branch management from its settings. If the selected provider capability becomes unavailable, Booster preserves package source state while suppressing release offers, downloads and mutations.', 'ran-booster' ); ?></p>
				<p><a href="<?php echo esc_url( 'https://github.com/RocketsAreNostalgic/ran-booster/blob/main/docs/package-update-orchestration.md' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Package update orchestration guide', 'ran-booster' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span></a></p>
		<?php
	}

	public function handleEnable(): void {
		$this->handleAdminPost( 'enable' );
	}

	public function handleRefresh(): void {
		$this->handleAdminPost( 'refresh' );
	}

	public function handleReturnToBranch(): void {
		$this->handleAdminPost( 'return_to_branch' );
	}

	public function handleChangeChannel(): void {
		$this->handleAdminPost( 'change_channel' );
	}

	public function handleProspectiveListCandidates(): void {
		$this->handleProspectiveAjax( 'list_candidates' );
	}

	public function handleProspectiveInspect(): void {
		$this->handleProspectiveAjax( 'inspect' );
	}

	public function handleProspectiveInstall(): never {
		// This controller selects and validates the exact purpose nonce before reading prospective domain values.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request = is_array( $_POST ) ? $_POST : array();
		$outcome = $this->processProspectiveRequest( 'install', $request );
		$url     = 'installed' === $outcome['code'] && $outcome['successful']
			? $this->returnUrl( $outcome['type'], $outcome['identifier'], true )
			: admin_url( 'admin.php?page=ran-booster-' . ( 'plugin' === $outcome['type'] ? 'plugins' : 'themes' ) . '-create' );
		$url     = add_query_arg( $this->resultQueryArguments( $outcome, $this->releaseChannelFrom( $request ) ), $url );

		$this->redirectTo( $url );
	}

	public function enqueueProspectiveAssets(): void {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
			? sanitize_key( wp_unslash( $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
			: '';
		if ( null === $this->tracking || ! in_array( $page, array( 'ran-booster-plugins-create', 'ran-booster-themes-create' ), true ) ) {
			return;
		}

		$type       = str_contains( $page, 'themes' ) ? 'theme' : 'plugin';
		$pluginRoot = dirname( __DIR__, 3 );
		$assetRoot  = $pluginRoot . '/assets';
		$scriptPath = $assetRoot . '/ran-booster-release-management.js';
		wp_enqueue_script(
			'ran-booster-release-management',
			plugins_url( 'assets/ran-booster-release-management.js', $pluginRoot . '/ran-booster.php' ),
			array( 'ran-booster-packages' ),
			is_file( $scriptPath ) ? (string) filemtime( $scriptPath ) : '1',
			true
		);
		if ( null === $this->prospectiveOperations ) {
			return;
		}
		$projection = $this->requestBoundary(
			fn (): array => array(
				'providers' => $this->prospectiveOperations->supportedProviderCodes( $type ),
				'nonces'    => array(
					'listCandidates' => $this->prospectiveOperations->nonceAction( 'list_candidates', $type ),
					'inspect'        => $this->prospectiveOperations->nonceAction( 'inspect', $type ),
					'install'        => $this->prospectiveOperations->nonceAction( 'install', $type ),
				),
			),
			null
		);
		if ( null === $projection ) {
			return;
		}
		$supportedProviders = $projection['providers'];
		$stylePath          = $assetRoot . '/ran-booster-release-management.css';
		$nonceActions       = $projection['nonces'];
		wp_enqueue_style(
			'ran-booster-release-management',
			plugins_url( 'assets/ran-booster-release-management.css', $pluginRoot . '/ran-booster.php' ),
			array( 'ran-booster-styles' ),
			is_file( $stylePath ) ? (string) filemtime( $stylePath ) : '1'
		);
		wp_localize_script(
			'ran-booster-release-management',
			'ranBoosterReleaseManagement',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'adminPostUrl'       => admin_url( 'admin-post.php' ),
				'type'               => $type,
				'supportedProviders' => $supportedProviders,
				'actions'            => array(
					'listCandidates' => 'ran_booster_release_list_candidates',
					'inspect'        => 'ran_booster_release_inspect',
					'install'        => 'ran_booster_release_install',
				),
				'nonces'             => array(
					'listCandidates' => wp_create_nonce( $nonceActions['listCandidates'] ),
					'inspect'        => wp_create_nonce( $nonceActions['inspect'] ),
					'install'        => wp_create_nonce( $nonceActions['install'] ),
				),
			)
		);
	}

	private function handleAdminPost( string $operation ): never {
		// This controller validates local authority and the operation-specific nonce before optional values.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request = is_array( $_POST ) ? $_POST : array();
		$url     = $this->processAdminPostRequest( $operation, $request );

		$this->redirectTo( $url );
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

	/**
	 * Process one namespaced handler request and build its canonical PRG target.
	 *
	 * @param array<string, mixed> $request
	 */
	public function processAdminPostRequest( string $operation, array $request ): string {
		$type       = $this->strictRequestedType( $request );
		$identifier = $this->requestedIdentifier( $request );
		$revision   = $this->requestedRevision( $request );
		$nonce      = is_string( $request['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $request['_wpnonce'] ) ) : '';
		$outcome    = $this->packageOutcome( $type, $identifier, 'invalid_request', false );
		$operations = array( 'enable', 'refresh', 'change_channel', 'return_to_branch' );
		if ( null === $this->tracking ) {
			$outcome = $this->packageOutcome( $type, $identifier, 'service_unavailable', false );
		}
		if ( null !== $this->tracking && in_array( $operation, $operations, true ) && '' !== $type && '' !== $identifier && $revision > 0 ) {
			if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' ) ) {
				$outcome = $this->packageOutcome( $type, $identifier, 'forbidden', false );
			} else {
				$nonceAction = $this->requestBoundary( fn (): string => $this->tracking->nonceAction( $operation, $type, $identifier, $revision ), '' );
				if ( '' === $nonceAction ) {
					$outcome = $this->packageOutcome( $type, $identifier, 'service_unavailable', false );
				} elseif ( '' !== $nonce && 1 === wp_verify_nonce( $nonce, $nonceAction ) ) {
					$channel = in_array( $operation, array( 'enable', 'change_channel' ), true )
						? $this->releaseChannelFrom( $request ) : '';
					if ( 'enable' === $operation && '' === $channel && ! array_key_exists( 'release_channel', $request ) ) {
						$channel = 'stable';
					}
					if ( ! in_array( $operation, array( 'enable', 'change_channel' ), true ) || '' !== $channel ) {
						$outcome = $this->requestBoundary(
							fn (): array => $this->tracking->execute( $operation, $type, $identifier, $revision, $channel, $nonce ),
							$this->packageOutcome( $type, $identifier, 'service_unavailable', false )
						);
					}
				}
			}
		}

		// These bounded fields affect only the signed result's local redirect projection; they are not forwarded to an operation owner.
		$settings = 'refresh' !== $operation
			|| ( is_string( $request['return_to_settings'] ?? null ) && '1' === wp_unslash( $request['return_to_settings'] ) );
		$url      = $this->returnUrl( $outcome['type'], $outcome['identifier'], $settings );
		if ( $settings ) {
			$url = add_query_arg( 'source_view', 'return_to_branch' === $operation ? 'branch' : 'release_asset', $url );
		}
		$channel = in_array( $operation, array( 'enable', 'change_channel' ), true )
			? $this->releaseChannelFrom( $request )
			: '';
		$url     = add_query_arg( $this->resultQueryArguments( $outcome, $channel ), $url );

		return $settings ? $url . '#ran-booster-advanced-source-settings' : $url;
	}

	private function handleProspectiveAjax( string $operation ): never {
		// This controller selects and validates the exact purpose nonce before reading prospective domain values.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request = is_array( $_POST ) ? $_POST : array();
		$outcome = $this->processProspectiveRequest( $operation, $request );

		wp_send_json(
			array(
				'successful' => $outcome['successful'],
				'code'       => $outcome['code'],
				'data'       => $outcome['data'] ?? array(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $request
	 * @return array{type:string,identifier:string,code:string,successful:bool,data:array<mixed>}
	 */
	public function processProspectiveRequest( string $operation, array $request ): array {
		$type       = $this->strictRequestedType( $request );
		$nonceField = 'install' === $operation ? 'ran_booster_release_install_nonce' : '_wpnonce';
		$nonce      = is_string( $request[ $nonceField ] ?? null ) ? sanitize_text_field( wp_unslash( $request[ $nonceField ] ) ) : '';
		$fallback   = $this->prospectiveOutcome( $type, 'invalid_request' );
		if ( ! in_array( $operation, array( 'list_candidates', 'inspect', 'install' ), true ) || '' === $type ) {
			return $fallback;
		}
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'install_plugins' : 'install_themes' ) ) {
			return $this->prospectiveOutcome( $type, 'forbidden' );
		}
		if ( null === $this->prospectiveOperations ) {
			return $this->prospectiveOutcome( $type, 'service_unavailable' );
		}
		$nonceAction = $this->requestBoundary( fn (): string => $this->prospectiveOperations->nonceAction( $operation, $type ), null );
		if ( null === $nonceAction || '' === $nonceAction ) {
			return $this->prospectiveOutcome( $type, 'service_unavailable' );
		}
		if ( '' === $nonce || 1 !== wp_verify_nonce( $nonce, $nonceAction ) ) {
			return $fallback;
		}

		// Domain fields, including the repository credential selector and fingerprint, are unread until authority is proven.
		$repository  = is_array( $request['ran_booster'] ?? null ) ? wp_unslash( $request['ran_booster'] ) : array();
		$releaseId   = is_scalar( $request['release_id'] ?? null ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', (string) $request['release_id'] )
			? (int) $request['release_id'] : 0;
		$tag         = is_string( $request['release_tag'] ?? null ) ? wp_unslash( $request['release_tag'] ) : '';
		$fingerprint = is_string( $request['release_fingerprint'] ?? null ) ? wp_unslash( $request['release_fingerprint'] ) : '';
		$channel     = array_key_exists( 'release_channel', $request ) ? $this->releaseChannelFrom( $request ) : 'stable';

		return $this->requestBoundary(
			fn (): array => $this->prospectiveOperations->execute( $operation, $type, $repository, $releaseId, $tag, $fingerprint, $channel, $nonce ),
			$this->prospectiveOutcome( $type, 'service_unavailable' )
		);
	}

	/** @return array{type:string,identifier:string,code:string,successful:bool,data:array<mixed>} */
	private function prospectiveOutcome( string $type, string $code ): array {
		return array(
			'type'       => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
			'identifier' => '',
			'code'       => $code,
			'successful' => false,
			'data'       => array(),
		);
	}

	private function strictRequestedType( array $request ): string {
		$type = is_string( $request['expected_type'] ?? null ) ? sanitize_key( wp_unslash( $request['expected_type'] ) ) : '';

		return in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : '';
	}

	private function requestedRevision( array $request ): int {
		$value = $request['expected_source_revision'] ?? null;
		$value = is_string( $value ) ? wp_unslash( $value ) : $value;

		return is_scalar( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', (string) $value ) ? (int) $value : 0;
	}

	/** @return array{type:string,identifier:string,code:string,successful:bool} */
	private function packageOutcome( string $type, string $identifier, string $code, bool $successful ): array {
		return array(
			'type'       => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
			'identifier' => strlen( $identifier ) <= 255 ? $identifier : '',
			'code'       => $code,
			'successful' => $successful,
		);
	}

	/** @param array<string, mixed> $request */
	private function packageStatus( object $package ): ?ReleaseTrackingStatus {
		return $this->requestBoundary(
			function () use ( $package ): ?ReleaseTrackingStatus {
				if ( null === $this->tracking || ! is_callable( array( $package, 'type' ) )
					|| ! is_callable( array( $package, 'identifier' ) ) || ! is_callable( array( $package, 'sourceRevision' ) ) ) {
					return null;
				}
				$type       = $package->type();
				$identifier = $package->identifier();
				$revision   = $package->sourceRevision();
				if ( ! is_string( $type ) || ! is_string( $identifier ) || ! is_int( $revision ) ) {
					return null;
				}

				return $this->tracking->status( $type, $identifier, $revision );
			},
			null
		);
	}

	private function packageNonceAction( string $operation, object $package ): ?string {
		$action = $this->requestBoundary(
			function () use ( $operation, $package ): string {
				if ( null === $this->tracking || ! is_callable( array( $package, 'type' ) )
					|| ! is_callable( array( $package, 'identifier' ) ) || ! is_callable( array( $package, 'sourceRevision' ) ) ) {
					return '';
				}
				$type       = $package->type();
				$identifier = $package->identifier();
				$revision   = $package->sourceRevision();
				if ( ! is_string( $type ) || ! is_string( $identifier ) || ! is_int( $revision ) || $revision < 1 ) {
					return '';
				}

				return $this->tracking->nonceAction( $operation, $type, $identifier, $revision );
			},
			''
		);

		return '' === $action ? null : $action;
	}

	/**
	 * @param array<string,array<string,mixed>> $rows
	 * @param list<object> $packages
	 * @return array{identifiers:list<string>,revisions:array<string,int>}|array{}
	 */
	private function managementCoordinates( string $surface, array $rows, array $packages ): array {
		$identifiers = array();
		$revisions   = array();
		foreach ( $packages as $package ) {
			if ( ! is_object( $package ) || ! is_callable( array( $package, 'type' ) ) || ! is_callable( array( $package, 'source' ) )
				|| ! is_callable( array( $package, 'identifier' ) ) || ! is_callable( array( $package, 'sourceRevision' ) ) ) {
				continue;
			}
			$type       = $package->type();
			$source     = $package->source();
			$identifier = $package->identifier();
			$revision   = $package->sourceRevision();
			if ( $surface === $type && 'release_asset' === $source && is_string( $identifier )
				&& is_int( $revision ) && isset( $rows[ $identifier ] ) ) {
				$identifiers[]            = $identifier;
				$revisions[ $identifier ] = $revision;
			}
		}

		return array() === $identifiers ? array() : array(
			'identifiers' => $identifiers,
			'revisions'   => $revisions,
		);
	}

	/** @return array<string,string> */
	private function packageNonceActions( object $package ): array {
		$actions = array();
		foreach ( array( 'enable', 'refresh', 'change_channel', 'return_to_branch' ) as $operation ) {
			$nonce = $this->packageNonceAction( $operation, $package );
			if ( null !== $nonce ) {
				$actions[ $operation ] = wp_create_nonce( $nonce );
			}
		}

		return $actions;
	}

	/** @return array<string,mixed> */
	private function prospectiveProjection( string $type ): array {
		if ( null === $this->prospectiveOperations || ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array();
		}

		return $this->requestBoundary(
			fn (): array => array(
				'providers'       => $this->prospectiveOperations?->supportedProviderCodes( $type ) ?? array(),
				'list_candidates' => wp_create_nonce( $this->prospectiveOperations?->nonceAction( 'list_candidates', $type ) ?? '' ),
				'inspect'         => wp_create_nonce( $this->prospectiveOperations?->nonceAction( 'inspect', $type ) ?? '' ),
				'install'         => wp_create_nonce( $this->prospectiveOperations?->nonceAction( 'install', $type ) ?? '' ),
			),
			array()
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

	/** @param array<string, mixed> $request */
	private function requestedIdentifier( array $request ): string {
		$identifier = is_string( $request['expected_identifier'] ?? null )
			? sanitize_text_field( wp_unslash( $request['expected_identifier'] ) )
			: '';

		return strlen( $identifier ) <= 255 ? $identifier : '';
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

	/** @param array<string, mixed> $request */
	private function releaseChannelFrom( array $request ): string {
		$channel = is_string( $request['release_channel'] ?? null ) ? sanitize_key( wp_unslash( $request['release_channel'] ) ) : '';

		return in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : '';
	}

	private function isPackageSettingsRequest(): bool {
		$page    = $_GET['page'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$package = $_GET['package'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.

		return is_string( $page )
			&& in_array( sanitize_key( wp_unslash( $page ) ), array( 'ran-booster-plugins', 'ran-booster-themes' ), true )
			&& is_string( $package )
			&& '' !== sanitize_text_field( wp_unslash( $package ) );
	}
}
