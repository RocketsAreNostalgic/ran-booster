<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\PackageSource;
use RAN\Storage\RepositorySourceGuard;
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

	private readonly ProspectiveReleaseOperations $prospective_operations;
	private readonly ReleaseTrackingFacade $releases;
	private readonly ReleaseTrackingOperations $tracking;
	private readonly ManagedReleaseBrowserOperations $managed_browser;
	private readonly ReleaseManagementDisplay $display;
	private readonly RepositorySourceGuard $source_guard;

	public function __construct(
		ReleaseTrackingFacade $releases,
		ProspectiveReleaseFacade $prospective,

		callable $read_candidates,

		ManagedReleaseBrowser $managed_browser,

		?RepositorySourceGuard $source_guard = null
	) {
		$this->display  = new ReleaseManagementDisplay();
		$this->releases = $releases;
		$this->tracking = new ReleaseTrackingOperations( $releases );

		$this->managed_browser = new ManagedReleaseBrowserOperations( $managed_browser, $releases );

		$this->prospective_operations = new ProspectiveReleaseOperations( $prospective, $read_candidates );

		$this->source_guard = $source_guard ?? new RepositorySourceGuard();
	}

	public function register(): void {
		add_filter( 'ran_booster_admin_package_management_rows', array( $this, 'filter_management_rows' ), 10, 3 );
		add_filter( 'ran_booster_admin_package_management_actions', array( $this, 'filter_management_actions' ), 10, 3 );
		add_filter( 'ran_booster_admin_package_source_choices', array( $this, 'filter_source_choices' ), 10, 5 );
		add_filter( 'ran_booster_admin_package_advanced_source_summary', array( $this, 'filter_advanced_source_summary' ), 10, 5 );
		add_filter( 'ran_booster_admin_package_advanced_source_summary_projection', array( $this, 'filter_advanced_source_summary_projection' ), 10, 5 );
		add_filter( 'ran_booster_documentation_sections_before_about', array( $this, 'filter_documentation_sections' ), 10, 3 );
		add_action( 'ran_booster_admin_package_advanced_source_sections', array( $this, 'render_advanced_source_section' ), 10, 5 );
		add_action( 'admin_notices', array( $this, 'render_operation_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_prospective_assets' ) );

		add_action( 'admin_post_ran_booster_release_enable', array( $this, 'handle_enable' ) );
		add_action( 'admin_post_ran_booster_release_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_ran_booster_release_return_to_branch', array( $this, 'handle_return_to_branch' ) );
		add_action( 'admin_post_ran_booster_release_change_channel', array( $this, 'handle_change_channel' ) );
		add_action( 'admin_post_ran_booster_release_install', array( $this, 'handle_prospective_install' ) );
		add_action( 'wp_ajax_ran_booster_release_list_candidates', array( $this, 'handle_prospective_list_candidates' ) );
		add_action( 'wp_ajax_ran_booster_release_inspect', array( $this, 'handle_prospective_inspect' ) );
		add_action( 'wp_ajax_ran_booster_managed_release_list_candidates', array( $this, 'handle_managed_list_candidates' ) );
		add_action( 'wp_ajax_ran_booster_managed_release_inspect', array( $this, 'handle_managed_inspect' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param list<object>                        $packages
	 * @return array<string, array<string, mixed>>
	 */

	public function filter_management_rows( array $rows, string $surface, array $packages ): array {
		if ( null === $this->tracking ) {
			return $rows;
		}
		$coordinates = $this->request_boundary( fn (): array => $this->management_coordinates( $surface, $rows, $packages ), array() );
		if ( array() === $coordinates ) {
			return $rows;
		}
		$statuses = $this->request_boundary( fn (): array => $this->tracking->statuses( $surface, $coordinates['identifiers'], $coordinates['revisions'] ), null );
		if ( ! is_array( $statuses ) ) {
			return $rows;
		}

		return $this->request_boundary( fn (): array => $this->display->present_management( $rows, $surface, $packages, $statuses ), $rows );
	}

	/**
	 * @param array<string, array<string, mixed>> $actions
	 * @return array<string, array<string, mixed>>
	 */

	public function filter_management_actions( array $actions, string $surface, object $package ): array {
		if ( null === $this->tracking ) {
			return $actions;
		}
		$status = $this->package_status( $package );
		$action = null !== $status ? $this->package_nonce_action( 'refresh', $package ) : null;
		$nonce  = null === $action ? null : wp_create_nonce( $action );

		return $this->request_boundary( fn (): array => $this->display->present_management_actions( $actions, $surface, $package, $status, $nonce ), $actions );
	}

	/**
	 * Hydrate Core's published-release source choice without replacing its layout.
	 *
	 * @param array<string, array<string, mixed>> $choices
	 * @return array<string, array<string, mixed>>
	 */

	public function filter_source_choices(
		array $choices,
		string $mode,
		string $type,
		?object $package,

		string $page_url
	): array {
		unset( $type );
		if ( null === $this->tracking || ! isset( $choices['release_asset'] ) ) {
			return $choices;
		}
		if ( 'create' === $mode && null === $this->prospective_operations ) {
			return $choices;
		}

		$choices['release_asset'] = array_merge(
			$choices['release_asset'],
			array(
				'heading'           => __( 'Releases', 'ran-booster' ),
				'description'       => 'create' === $mode
					? __( 'Choose a repository, then view its eligible published releases.', 'ran-booster' )
					: __( 'Install published releases through WordPress.', 'ran-booster' ),
				'meta'              => 'create' === $mode
					? __( 'Choose repository first', 'ran-booster' )
					: __( 'Releases', 'ran-booster' ),

				'url'               => 'edit' === $mode ? add_query_arg( 'source_view', 'release_asset', $page_url ) : '',
				'disabled'          => 'create' === $mode,
				'hydrated'          => true,
				'client_hydratable' => 'create' === $mode,
			)
		);
		if ( 'edit' === $mode && null !== $package ) {
			$status                           = $this->package_status( $package );
			$choices['release_asset']['meta'] = $this->request_boundary( fn (): string => $this->display->release_track_meta( $choices['release_asset']['meta'], $package, $status ), $choices['release_asset']['meta'] );
			$release_source_is_current        = is_callable( array( $package, 'source' ) )
				&& 'release_asset' === $package->source();
			if ( ! $release_source_is_current
				&& ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED === $status?->eligibility()->code() ) {
				$choices['release_asset']['description'] = __( 'Published releases require this plugin or theme to be at the repository root. This package uses a repository subdirectory, so continue using Branch deployments.', 'ran-booster' );
				$choices['release_asset']['meta']        = __( 'Repository subdirectory not supported', 'ran-booster' );
				$choices['release_asset']['disabled']    = true;
			} elseif ( ! $release_source_is_current
				&& false === $this->display->release_provider_supported( $package, $status ) ) {
				$choices['release_asset']['description'] = __( 'Published releases are not available for this repository provider.', 'ran-booster' );
				$choices['release_asset']['meta']        = __( 'Provider capability unavailable', 'ran-booster' );
				$choices['release_asset']['disabled']    = true;
			}
		}

		return $choices;
	}


	public function render_advanced_source_section(
		string $mode,
		string $type,

		string $selected_source,
		?object $package,

		string $page_url
	): void {
		if ( 'create' === $mode && null === $this->prospective_operations ) {
			return;
		}

		$result = $this->requested_result();
		if ( null !== $result && ( 'edit' !== $mode || null === $package
			|| $result['type'] !== $type || ! is_callable( array( $package, 'identifier' ) )
			|| $result['identifier'] !== $package->identifier() ) ) {
			$result = null;
		}
		$code = $result['code'] ?? '';

		$release_pane = 'edit' === $mode && 'release_asset' === $selected_source && null !== $package;
		if ( $release_pane ) {
			?>
			<div
				id="ran-booster-source-pane-release_asset"
				class="ran-booster-package-source-pane ran-booster-release-pane"
				aria-labelledby="ran-booster-source-tab-release_asset"
				data-ran-booster-source-pane="release_asset"
			>
				<p class="ran-booster-package-source-pane__description"><?php esc_html_e( 'Install published releases through WordPress.', 'ran-booster' ); ?></p>
			<?php
		}

		$status                = null === $package ? null : $this->package_status( $package );
		$repository_conflict   = null === $package ? array() : $this->repository_conflict_packages( $package, $status );
		$nonces                = null === $package ? array() : $this->package_nonce_actions( $package, $status );
		$operation_notice_html = '';
		$duplicate_conflict    = in_array( $code, array( 'release_repository_conflict', 'repository_release_owner_exists' ), true )
			&& null !== $status && in_array( $status->failure_code(), array( 'release_repository_conflict', 'repository_release_owner_exists' ), true )
			&& $release_pane;
		if ( 'edit' === $mode && '' !== $code && ! $duplicate_conflict ) {
			ob_start();
			$this->request_boundary( fn () => $this->display->render_operation_notice( $code, $result['successful'], $result['type'], $result['identifier'], $result['channel'], $status ), null );
			$operation_notice_html = (string) ob_get_clean();
		}
		$prospective = $this->prospective_projection( $type );
		$recheck     = isset( $_GET['ran_booster_release_recheck'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI marker.
			&& is_scalar( $_GET['ran_booster_release_recheck'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& '1' === (string) $_GET['ran_booster_release_recheck']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->request_boundary( fn () => $this->display->render_advanced_source_section( $mode, $type, $selected_source, $package, $status, $page_url, $result['channel'] ?? '', $nonces, $prospective, $recheck, $operation_notice_html, $repository_conflict ), null );
		if ( $release_pane ) {
			?>
			</div>
			<?php
		}
	}


	public function filter_advanced_source_summary(
		string $summary,
		string $mode,
		string $type,

		string $selected_source,
		?object $package
	): string {
		unset( $type );
		if ( null === $this->tracking ) {
			return $summary;
		}


		return $this->request_boundary( fn (): string => $this->display->advanced_source_summary( $summary, $mode, $selected_source, $package, null === $package ? null : $this->package_status( $package ) ), $summary );
	}

	/**
	 * @return array{heading:string,badges:list<array{label:string}>,status:string}
	 */

	public function filter_advanced_source_summary_projection(
		array $projection,
		string $mode,
		string $type,

		string $selected_source,
		?object $package
	): array {

		unset( $type, $selected_source );
		if ( null === $this->tracking ) {
			return $projection;
		}

		return $this->request_boundary(
			fn (): array => $this->display->advanced_source_summary_projection(
				$projection,
				$mode,
				$package,
				null === $package ? null : $this->package_status( $package )
			),
			$projection
		);
	}


	public function render_operation_notice(): void {
		$result = $this->requested_result();
		$code   = $result['code'] ?? '';
		if ( '' === $code || null === $this->tracking || ! $this->result_matches_current_screen( $result ) || $this->is_package_settings_request() ) {
			return;
		}

		$status = $this->request_boundary( fn (): ?ReleaseTrackingStatus => $this->tracking->fresh_status( $result['type'], $result['identifier'] ), null );
		$this->request_boundary( fn () => $this->display->render_operation_notice( $code, $result['successful'], $result['type'], $result['identifier'], $result['channel'], $status ), null );
	}

	/** @param list<array<string, mixed>> $sections @return list<array<string, mixed>> */

	public function filter_documentation_sections( array $sections, string $documentation_url, string $scope ): array {

		unset( $documentation_url, $scope );
		$sections[] = array(
			'id'      => 'ran-booster-documentation-published-releases',
			'summary' => __( 'Releases', 'ran-booster' ),
			'content' => array( $this, 'render_documentation_content' ),
		);

		return $sections;
	}


	public function render_documentation_content(): void {
		?>
				<p><?php esc_html_e( 'Published release management lets an eligible Booster-managed plugin or theme follow exact uploaded release ZIPs instead of a repository branch.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Prepare an eligible release', 'ran-booster' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Publish exactly one installable plugin or theme ZIP as a release asset, not as a generated source archive.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Keep the release tag and canonical WordPress plugin or theme Version header aligned. A mismatch fails closed.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Declare the exact repository in the package Update URI expected by its provider. Plugins use one eligible top-level plugin header; themes use root style.css.', 'ran-booster' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'Read GitHub’s ', 'ran-booster' ); ?><a href="<?php echo esc_url( 'https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'About releases', 'ran-booster' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span></a><?php esc_html_e( ' guidance. GitHub also documents ', 'ran-booster' ); ?><a href="<?php echo esc_url( 'https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'immutable releases', 'ran-booster' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span></a><?php esc_html_e( ' as optional supply-chain hardening. Booster’s setup pull request does not enable that repository setting.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Choose and operate the source', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Prospective installation uses the Stable track by default. Choose Preview only when alpha, beta or release-candidate builds are acceptable. Preview still excludes drafts.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'For a not-yet-managed package, candidate listing is metadata-only. Choose one of at most eight eligible releases; inspection downloads, validates and discards that exact ZIP, then binds the reviewed choice to installation with a fingerprint.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Install performs a fresh exact acquisition. Before WordPress changes files, Booster rechecks fingerprint continuity, archive shape and size, provider and local digests, headers, Update URI and package identity. WordPress installs synchronously, then Booster verifies the installed identity and unchanged target activation before adoption.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Open the package settings, choose Releases, review Release readiness, then choose Use releases. Booster validates the release before switching; viewing settings does not change the source.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Switching either source preserves Disabled and Manual automation, resets Automatic to Manual, and leaves repository webhook configuration unchanged.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Use Check releases on the managed Plugins or Themes screen to refresh release metadata. Booster validates the candidate, but WordPress remains the installer. Open WordPress updates to use the administrator’s normal WordPress update workflow.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Recovery and support', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Installed but unmanaged means a package now exists but Booster did not adopt it. Verify its installed version and activation state before using Link installed or retrying. An uncertain state or cleanup failure does not claim installation success: inspect installed packages and Booster management before retrying.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'If a release is blocked, publish a corrected release rather than editing installed metadata. You can return the package to branch management from its settings. If the selected provider capability becomes unavailable, Booster preserves package source state while suppressing release offers, downloads and mutations.', 'ran-booster' ); ?></p>
				<p><a href="<?php echo esc_url( 'https://github.com/RocketsAreNostalgic/ran-booster/blob/main/docs/package-update-orchestration.md' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Package update orchestration guide', 'ran-booster' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span></a></p>
		<?php
	}


	public function handle_enable(): void {
		$this->handle_admin_post( 'enable' );
	}


	public function handle_refresh(): void {
		$this->handle_admin_post( 'refresh' );
	}


	public function handle_return_to_branch(): void {
		$this->handle_admin_post( 'return_to_branch' );
	}


	public function handle_change_channel(): void {
		$this->handle_admin_post( 'change_channel' );
	}


	public function handle_prospective_list_candidates(): void {
		$this->handle_prospective_ajax( 'list_candidates' );
	}


	public function handle_prospective_inspect(): void {
		$this->handle_prospective_ajax( 'inspect' );
	}


	public function handle_managed_list_candidates(): void {
		$this->handle_managed_browser_ajax( 'list_candidates' );
	}


	public function handle_managed_inspect(): void {
		$this->handle_managed_browser_ajax( 'inspect_candidate' );
	}


	public function handle_prospective_install(): never {
		// This controller selects and validates the exact purpose nonce before reading prospective domain values.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request = is_array( $_POST ) ? $_POST : array();
		$outcome = $this->process_prospective_request( 'install', $request );
		$url     = 'installed' === $outcome['code'] && $outcome['successful']
			? $this->return_url( $outcome['type'], $outcome['identifier'], true )
			: admin_url( 'admin.php?page=ran-booster-' . ( 'plugin' === $outcome['type'] ? 'plugins' : 'themes' ) . '-create' );
		$url     = add_query_arg( $this->result_query_arguments( $outcome, $this->release_channel_from( $request ) ), $url );

		$this->redirect_to( $url );
	}


	public function enqueue_prospective_assets(): void {
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
			? sanitize_key( wp_unslash( $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
			: '';
		if ( null === $this->tracking || ! in_array( $page, array( 'ran-booster-plugins-create', 'ran-booster-themes-create', 'ran-booster-plugins', 'ran-booster-themes' ), true ) ) {
			return;
		}

		$type        = str_contains( $page, 'themes' ) ? 'theme' : 'plugin';
		$plugin_root = dirname( __DIR__, 3 );
		$asset_root  = $plugin_root . '/assets';
		$script_path = $asset_root . '/ran-booster-release-management.js';
		wp_enqueue_script(
			'ran-booster-release-management',
			plugins_url( 'assets/ran-booster-release-management.js', $plugin_root . '/ran-booster.php' ),
			array( 'ran-booster-packages', 'wp-i18n' ),
			is_file( $script_path ) ? (string) filemtime( $script_path ) : '1',
			true
		);
		wp_set_script_translations( 'ran-booster-release-management', 'ran-booster', $plugin_root . '/languages' );
		if ( in_array( $page, array( 'ran-booster-plugins', 'ran-booster-themes' ), true ) ) {
			wp_enqueue_style( 'ran-booster-release-management', plugins_url( 'assets/ran-booster-release-management.css', $plugin_root . '/ran-booster.php' ), array( 'ran-booster-styles' ), is_file( $asset_root . '/ran-booster-release-management.css' ) ? (string) filemtime( $asset_root . '/ran-booster-release-management.css' ) : '1' );
			return;
		}
		if ( null === $this->prospective_operations ) {
			return;
		}
		$projection = $this->request_boundary(
			fn (): array => array(
				'providers' => $this->prospective_operations->supported_provider_codes( $type ),
				'nonces'    => array(
					'listCandidates' => $this->prospective_operations->nonce_action( 'list_candidates', $type ),
					'inspect'        => $this->prospective_operations->nonce_action( 'inspect', $type ),
					'install'        => $this->prospective_operations->nonce_action( 'install', $type ),
				),
			),
			null
		);
		if ( null === $projection ) {
			return;
		}
		$supported_providers = $projection['providers'];
		$style_path          = $asset_root . '/ran-booster-release-management.css';
		$nonce_actions       = $projection['nonces'];
		wp_enqueue_style(
			'ran-booster-release-management',
			plugins_url( 'assets/ran-booster-release-management.css', $plugin_root . '/ran-booster.php' ),
			array( 'ran-booster-styles' ),
			is_file( $style_path ) ? (string) filemtime( $style_path ) : '1'
		);
		wp_localize_script(
			'ran-booster-release-management',
			'ranBoosterReleaseManagement',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'adminPostUrl'       => admin_url( 'admin-post.php' ),
				'type'               => $type,
				'supportedProviders' => $supported_providers,
				'actions'            => array(
					'listCandidates' => 'ran_booster_release_list_candidates',
					'inspect'        => 'ran_booster_release_inspect',
					'install'        => 'ran_booster_release_install',
				),
				'nonces'             => array(
					'listCandidates' => wp_create_nonce( $nonce_actions['listCandidates'] ),
					'inspect'        => wp_create_nonce( $nonce_actions['inspect'] ),
					'install'        => wp_create_nonce( $nonce_actions['install'] ),
				),
			)
		);
	}

	private function handle_admin_post( string $operation ): never {
		// This controller validates local authority and the operation-specific nonce before optional values.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request = is_array( $_POST ) ? $_POST : array();
		$url     = $this->process_admin_post_request( $operation, $request );

		$this->redirect_to( $url );
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

	/**
	 * Process one namespaced handler request and build its canonical PRG target.
	 *
	 * @param array<string, mixed> $request
	 */

	public function process_admin_post_request( string $operation, array $request ): string {
		$type       = $this->strict_requested_type( $request );
		$identifier = $this->requested_identifier( $request );
		$revision   = $this->requested_revision( $request );
		$nonce      = is_string( $request['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $request['_wpnonce'] ) ) : '';
		$outcome    = $this->package_outcome( $type, $identifier, 'invalid_request', false );
		$operations = array( 'enable', 'refresh', 'change_channel', 'return_to_branch' );
		if ( null === $this->tracking ) {
			$outcome = $this->package_outcome( $type, $identifier, 'service_unavailable', false );
		}
		if ( null !== $this->tracking && in_array( $operation, $operations, true ) && '' !== $type && '' !== $identifier && $revision > 0 ) {
			if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' ) ) {
				$outcome = $this->package_outcome( $type, $identifier, 'forbidden', false );
			} else {
				$nonce_action = $this->request_boundary( fn (): string => $this->tracking->nonce_action( $operation, $type, $identifier, $revision ), '' );
				if ( '' === $nonce_action ) {
					$outcome = $this->package_outcome( $type, $identifier, 'service_unavailable', false );
				} elseif ( '' !== $nonce && 1 === wp_verify_nonce( $nonce, $nonce_action ) ) {
					$channel = in_array( $operation, array( 'enable', 'change_channel' ), true )
						? $this->release_channel_from( $request ) : '';
					if ( 'enable' === $operation && '' === $channel && ! array_key_exists( 'release_channel', $request ) ) {
						$channel = 'stable';
					}
					if ( ! in_array( $operation, array( 'enable', 'change_channel' ), true ) || '' !== $channel ) {
						$outcome = $this->request_boundary(
							fn (): array => $this->tracking->execute( $operation, $type, $identifier, $revision, $channel, $nonce ),
							$this->package_outcome( $type, $identifier, 'service_unavailable', false )
						);
					}
				}
			}
		}

		// These bounded fields affect only the signed result's local redirect projection; they are not forwarded to an operation owner.
		$settings = 'refresh' !== $operation
			|| ( is_string( $request['return_to_settings'] ?? null ) && '1' === wp_unslash( $request['return_to_settings'] ) );
		$url      = $this->return_url( $outcome['type'], $outcome['identifier'], $settings );
		if ( $settings ) {
			$url = add_query_arg(
				array(
					'source_view'               => 'return_to_branch' === $operation ? 'branch' : 'release_asset',
					'ran_booster_open_advanced' => '1',
				),
				$url
			);
		}
		$channel = in_array( $operation, array( 'enable', 'change_channel' ), true )
			? $this->release_channel_from( $request )
			: '';
		$url     = add_query_arg( $this->result_query_arguments( $outcome, $channel ), $url );

		return $settings ? $url . '#ran-booster-advanced-source-settings' : $url;
	}

	private function handle_prospective_ajax( string $operation ): never {
		// This controller selects and validates the exact purpose nonce before reading prospective domain values.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request = is_array( $_POST ) ? $_POST : array();
		$outcome = $this->process_prospective_request( $operation, $request );

		wp_send_json(
			array(
				'successful' => $outcome['successful'],
				'code'       => $outcome['code'],
				'data'       => $outcome['data'] ?? array(),
			)
		);
	}

	private function handle_managed_browser_ajax( string $operation ): never {
		// This route reads only identity/revision values until the purpose nonce is proven.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request = is_array( $_POST ) ? $_POST : array();
		$outcome = $this->process_managed_browser_request( $operation, $request );

		wp_send_json(
			array(
				'successful' => $outcome['successful'],
				'code'       => $outcome['code'],
				'data'       => $outcome['data'],
			)
		);
	}

	/** @param array<string,mixed> $request @return array{code:string,successful:bool,data:array<mixed>} */

	public function process_managed_browser_request( string $operation, array $request ): array {
		$type       = $this->strict_requested_type( $request );
		$identifier = $this->requested_identifier( $request );
		$revision   = $this->requested_revision( $request );
		$channel    = $this->release_channel_from( $request );
		$nonce      = is_string( $request['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $request['_wpnonce'] ) ) : '';
		$fallback   = array(
			'code'       => 'invalid_request',
			'successful' => false,
			'data'       => array(),
		);
		if ( ! in_array( $operation, array( 'list_candidates', 'inspect_candidate' ), true )
			|| '' === $type || '' === $identifier || $revision < 1 || '' === $channel ) {
			return $fallback;
		}
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' ) ) {
			return array(
				'code'       => 'forbidden',
				'successful' => false,
				'data'       => array(),
			);
		}
		$action = $this->request_boundary( fn (): string => $this->tracking->nonce_action( $operation, $type, $identifier, $revision, $channel ), '' );
		if ( '' === $action || '' === $nonce || 1 !== wp_verify_nonce( $nonce, $action ) ) {
			return $fallback;
		}
		$release_id = is_string( $request['release_id'] ?? null ) ? wp_unslash( $request['release_id'] ) : '';
		$tag        = is_string( $request['release_tag'] ?? null ) ? wp_unslash( $request['release_tag'] ) : '';

		return $this->request_boundary(
			fn (): array => 'list_candidates' === $operation
				? $this->managed_browser->list_candidates( $type, $identifier, $revision, $channel, $nonce )
				: $this->managed_browser->inspect( $type, $identifier, $revision, $release_id, $tag, $channel, $nonce ),
			array(
				'code'       => 'unable_to_check',
				'successful' => false,
				'data'       => array(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $request
	 * @return array{type:string,identifier:string,code:string,successful:bool,data:array<mixed>}
	 */

	public function process_prospective_request( string $operation, array $request ): array {
		$type        = $this->strict_requested_type( $request );
		$nonce_field = 'install' === $operation ? 'ran_booster_release_install_nonce' : '_wpnonce';
		$nonce       = is_string( $request[ $nonce_field ] ?? null ) ? sanitize_text_field( wp_unslash( $request[ $nonce_field ] ) ) : '';
		$fallback    = $this->prospective_outcome( $type, 'invalid_request' );
		if ( ! in_array( $operation, array( 'list_candidates', 'inspect', 'install' ), true ) || '' === $type ) {
			return $fallback;
		}
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'install_plugins' : 'install_themes' ) ) {
			return $this->prospective_outcome( $type, 'forbidden' );
		}
		if ( null === $this->prospective_operations ) {
			return $this->prospective_outcome( $type, 'service_unavailable' );
		}
		$nonce_action = $this->request_boundary( fn (): string => $this->prospective_operations->nonce_action( $operation, $type ), null );
		if ( null === $nonce_action || '' === $nonce_action ) {
			return $this->prospective_outcome( $type, 'service_unavailable' );
		}
		if ( '' === $nonce || 1 !== wp_verify_nonce( $nonce, $nonce_action ) ) {
			return $fallback;
		}

		// Domain fields, including the repository credential selector and fingerprint, are unread until authority is proven.
		$repository  = is_array( $request['ran_booster'] ?? null ) ? wp_unslash( $request['ran_booster'] ) : array();
		$release_id  = is_string( $request['release_id'] ?? null )
			? wp_unslash( $request['release_id'] ) : '';
		$tag         = is_string( $request['release_tag'] ?? null ) ? wp_unslash( $request['release_tag'] ) : '';
		$fingerprint = is_string( $request['release_fingerprint'] ?? null ) ? wp_unslash( $request['release_fingerprint'] ) : '';
		$channel     = array_key_exists( 'release_channel', $request ) ? $this->release_channel_from( $request ) : 'stable';

		return $this->request_boundary(
			fn (): array => 'list_candidates' === $operation ? $this->prospective_operations->list_candidates( $type, $repository, $channel ) : $this->prospective_operations->execute( $operation, $type, $repository, $release_id, $tag, $fingerprint, $channel, $nonce ),
			$this->prospective_outcome( $type, 'service_unavailable' )
		);
	}

	/** @return array{type:string,identifier:string,code:string,successful:bool,data:array<mixed>} */
	private function prospective_outcome( string $type, string $code ): array {
		return array(
			'type'       => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
			'identifier' => '',
			'code'       => $code,
			'successful' => false,
			'data'       => array(),
		);
	}

	private function strict_requested_type( array $request ): string {
		$type = is_string( $request['expected_type'] ?? null ) ? sanitize_key( wp_unslash( $request['expected_type'] ) ) : '';

		return in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : '';
	}

	private function requested_revision( array $request ): int {
		$value = $request['expected_source_revision'] ?? null;
		$value = is_string( $value ) ? wp_unslash( $value ) : $value;

		return is_scalar( $value ) && 1 === preg_match( '/\A[1-9][0-9]*\z/D', (string) $value ) ? (int) $value : 0;
	}

	/** @return array{type:string,identifier:string,code:string,successful:bool} */
	private function package_outcome( string $type, string $identifier, string $code, bool $successful ): array {
		return array(
			'type'       => in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin',
			'identifier' => strlen( $identifier ) <= 255 ? $identifier : '',
			'code'       => $code,
			'successful' => $successful,
		);
	}

	/** @param array<string, mixed> $request */
	private function package_status( object $package ): ?ReleaseTrackingStatus {
		return $this->request_boundary(
			function () use ( $package ): ?ReleaseTrackingStatus {
				if ( null === $this->tracking || ! is_callable( array( $package, 'type' ) )
					|| ! is_callable( array( $package, 'identifier' ) ) || ! is_callable( array( $package, 'source_revision' ) ) ) {
					return null;
				}
				$type       = $package->type();
				$identifier = $package->identifier();
				$revision   = $package->source_revision();
				if ( ! is_string( $type ) || ! is_string( $identifier ) || ! is_int( $revision ) ) {
					return null;
				}

				return $this->tracking->status( $type, $identifier, $revision );
			},
			null
		);
	}

	/** @return list<array{name:string,url:string,type:string}> */
	private function repository_conflict_packages( object $package, ?ReleaseTrackingStatus $status ): array {
		if ( null === $status || ! in_array( $status->failure_code(), array( 'release_repository_conflict', 'repository_release_owner_exists' ), true )
			|| ! is_callable( array( $package, 'provider_code' ) ) ) {
			return array();
		}
		$provider = $package->provider_code();
		$type_id  = 'plugin' === $status->type() ? 1 : ( 'theme' === $status->type() ? 2 : 0 );
		if ( ! is_string( $provider ) || '' === $provider || 0 === $type_id ) {
			return array();
		}
		$result = $this->request_boundary(
			fn (): array => $this->source_guard->assess( $provider, $status->provider_repository_id(), $type_id, $status->identifier(), PackageSource::RELEASE_ASSET ),
			array()
		);
		if ( ! in_array( $result['code'] ?? null, array( 'repository_source_conflict', 'repository_release_owner_exists' ), true ) || ! is_array( $result['other_packages'] ?? null ) ) {
			return array();
		}

		$packages = array_map(
			fn ( array $other ): array => array(
				'name' => $this->conflict_package_name( (int) $other['type'], (string) $other['identifier'] ),
				'url'  => admin_url( 'admin.php?page=ran-booster-' . ( 2 === $other['type'] ? 'themes' : 'plugins' ) . '&package=' . rawurlencode( (string) $other['identifier'] ) ),
				'type' => 2 === $other['type'] ? 'theme' : 'plugin',
			),
			$result['other_packages']
		);
		if ( (int) ( $result['relationship_count'] ?? 0 ) > count( $packages ) + 1 ) {
			$packages[] = array(
				'name'     => __( 'View all conflicting packages', 'ran-booster' ),
				'url'      => add_query_arg(
					array(
						'page'            => 'ran-booster',
						'tab'             => $provider,
						'panel'           => 'repositories',
						'repository'      => $status->provider_repository_id(),
						'repository_view' => 'status',
					),
					admin_url( 'admin.php' )
				),
				'type'     => '',
				'view_all' => true,
			);
		}

		return $packages;
	}

	private function conflict_package_name( int $type, string $identifier ): string {
		if ( 1 === $type && function_exists( 'get_plugins' ) ) {
			$plugins = get_plugins();
			$name    = is_array( $plugins ) ? $plugins[ $identifier ]['Name'] ?? null : null;
			if ( is_string( $name ) && '' !== trim( $name ) && strlen( $name ) <= 255 ) {
				return $name;
			}
		}
		if ( 2 === $type && function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme( $identifier );
			$name  = is_object( $theme ) && is_callable( array( $theme, 'get' ) ) ? $theme->get( 'Name' ) : null;
			if ( is_string( $name ) && '' !== trim( $name ) && strlen( $name ) <= 255 ) {
				return $name;
			}
		}

		return $identifier;
	}

	private function package_nonce_action( string $operation, object $package, string $channel = '' ): ?string {
		$action = $this->request_boundary(
			function () use ( $operation, $package, $channel ): string {
				if ( null === $this->tracking || ! is_callable( array( $package, 'type' ) )
					|| ! is_callable( array( $package, 'identifier' ) ) || ! is_callable( array( $package, 'source_revision' ) ) ) {
					return '';
				}
				$type       = $package->type();
				$identifier = $package->identifier();
				$revision   = $package->source_revision();
				if ( ! is_string( $type ) || ! is_string( $identifier ) || ! is_int( $revision ) || $revision < 1 ) {
					return '';
				}

				return $this->tracking->nonce_action( $operation, $type, $identifier, $revision, $channel );
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
	private function management_coordinates( string $surface, array $rows, array $packages ): array {
		$identifiers = array();
		$revisions   = array();
		foreach ( $packages as $package ) {
			if ( ! is_object( $package ) || ! is_callable( array( $package, 'type' ) ) || ! is_callable( array( $package, 'source' ) )
				|| ! is_callable( array( $package, 'identifier' ) ) || ! is_callable( array( $package, 'source_revision' ) ) ) {
				continue;
			}
			$type       = $package->type();
			$source     = $package->source();
			$identifier = $package->identifier();
			$revision   = $package->source_revision();
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
	private function package_nonce_actions( object $package, ?ReleaseTrackingStatus $status ): array {
		$actions = array();
		foreach ( array( 'enable', 'refresh', 'change_channel', 'return_to_branch' ) as $operation ) {
			$nonce = $this->package_nonce_action( $operation, $package );
			if ( null !== $nonce ) {
				$actions[ $operation ] = wp_create_nonce( $nonce );
			}
		}
		if ( null !== $status && 'release_asset' === $status->source() ) {
			foreach ( array( 'list_candidates', 'inspect_candidate' ) as $operation ) {
				$nonce = $this->package_nonce_action( $operation, $package, $status->channel() );
				if ( null !== $nonce ) {
					$actions[ $operation ] = wp_create_nonce( $nonce );
				}
			}
		}

		return $actions;
	}

	/** @return array<string,mixed> */
	private function prospective_projection( string $type ): array {
		if ( null === $this->prospective_operations || ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array();
		}

		return $this->request_boundary(
			fn (): array => array(
				'providers'       => $this->prospective_operations?->supported_provider_codes( $type ) ?? array(),
				'list_candidates' => wp_create_nonce( $this->prospective_operations?->nonce_action( 'list_candidates', $type ) ?? '' ),
				'inspect'         => wp_create_nonce( $this->prospective_operations?->nonce_action( 'inspect', $type ) ?? '' ),
				'install'         => wp_create_nonce( $this->prospective_operations?->nonce_action( 'install', $type ) ?? '' ),
			),
			array()
		);
	}

	private function request_boundary( callable $operation, mixed $failure ): mixed {
		$buffer_level = ob_get_level();
		ob_start();
		try {
			$result = $operation();
			$output = ob_get_clean();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured output was escaped by the component renderer.
			echo $output;
			return $result;
		} catch ( Throwable ) {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			return $failure;
		}
	}

	/** @param array<string, mixed> $request */
	private function requested_identifier( array $request ): string {
		$identifier = is_string( $request['expected_identifier'] ?? null )
			? sanitize_text_field( wp_unslash( $request['expected_identifier'] ) )
			: '';

		return strlen( $identifier ) <= 255 ? $identifier : '';
	}

	private function return_url( string $type, string $identifier, bool $settings ): string {
		$page = 'plugin' === $type ? 'ran-booster-plugins' : 'ran-booster-themes';
		$args = array( 'page' => $page );
		if ( $settings && '' !== $identifier ) {
			$args['package'] = $identifier;
		}

		return add_query_arg( $args, is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) );
	}

	/**
	 * @param array{type:string,identifier:string,code:string,successful:bool} $outcome
	 * @return array<string, string>
	 */
	private function result_query_arguments( array $outcome, string $channel = '' ): array {
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
			$this->result_nonce_action( $code, $successful, $type, $identifier, $channel )
		);

		return $args;
	}

	/** @return array{code:string,successful:bool,type:string,identifier:string,channel:string}|null */
	private function requested_result(): ?array {
		$raw_code       = $_GET[ self::RESULT_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_success    = $_GET[ self::RESULT_SUCCESS_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_type       = $_GET[ self::RESULT_TYPE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_identifier = $_GET[ self::RESULT_PACKAGE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_channel    = $_GET[ self::CHANNEL_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_nonce      = $_GET[ self::RESULT_NONCE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification value for this PRG result.
		if ( ! is_string( $raw_code ) || ! is_string( $raw_success ) || ! is_string( $raw_type )
			|| ! is_string( $raw_identifier ) || ! is_string( $raw_channel ) || ! is_string( $raw_nonce ) ) {
			return null;
		}

		$code       = wp_unslash( $raw_code );
		$success    = wp_unslash( $raw_success );
		$type       = wp_unslash( $raw_type );
		$identifier = wp_unslash( $raw_identifier );
		$channel    = wp_unslash( $raw_channel );
		$nonce      = wp_unslash( $raw_nonce );
		if ( sanitize_key( $code ) !== $code || '' === $code || strlen( $code ) > 64
			|| ! in_array( $success, array( '0', '1' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| sanitize_text_field( $identifier ) !== $identifier || strlen( $identifier ) > 255
			|| ! in_array( $channel, array( '', 'stable', 'prerelease' ), true ) ) {
			return null;
		}

		$successful = '1' === $success;
		if ( 1 !== wp_verify_nonce( $nonce, $this->result_nonce_action( $code, $successful, $type, $identifier, $channel ) ) ) {
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

	private function result_nonce_action( string $code, bool $successful, string $type, string $identifier, string $channel ): string {
		$payload = wp_json_encode( array( $code, $successful, $type, $identifier, $channel ) );

		return self::RESULT_NONCE_ACTION . hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	/** @param array{code:string,successful:bool,type:string,identifier:string,channel:string} $result */
	private function result_matches_current_screen( array $result ): bool {
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

		return in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : '';
	}

	private function is_package_settings_request(): bool {
		$page    = $_GET['page'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$package = $_GET['package'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.

		return is_string( $page )
			&& in_array( sanitize_key( wp_unslash( $page ) ), array( 'ran-booster-plugins', 'ran-booster-themes' ), true )
			&& is_string( $package )
			&& '' !== sanitize_text_field( wp_unslash( $package ) );
	}
}
