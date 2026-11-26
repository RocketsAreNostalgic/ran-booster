<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingEligibility;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Admin\Feedback\FeedbackFlash;
use RAN\Admin\ProviderTabLinks;
use RAN\Deployment\PackageMutationGuard;
use RAN\Deployment\PackageSourceGate;
use RAN\PackageSource;
use RAN\Storage\RepositorySourceGuard;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;
use Throwable;

/**
 * @internal Fixed Core placement for repository release capabilities.
 * @phpstan-type SwitchOption array{relationship:'newer'|'same'|'older',version_relationship:'newer'|'same'|'older',channel:'stable'|'prerelease',installed_version:string,release_version:string,release_identity:string,release_tag:string,confirmed_release_nonce:string,fallback_message:string,confirmed_installed_version:string}
 * @phpstan-type SwitchError array{successful:false,code:string,data:array{message:string,reason?:string,detail?:string}}
 */
final class ReleaseManagementControls {
	private const RESULT_QUERY_KEY = 'ran_booster_release_result';


	private const RESULT_SUCCESS_QUERY_KEY = 'ran_booster_release_success';
	private const RESULT_TYPE_QUERY_KEY    = 'ran_booster_release_type';
	private const RESULT_PACKAGE_QUERY_KEY = 'ran_booster_release_package';
	private const RESULT_NONCE_QUERY_KEY   = 'ran_booster_release_result_nonce';
	private const RESULT_NONCE_ACTION      = 'ran-booster-result-';
	private const CHANNEL_QUERY_KEY        = 'ran_booster_release_channel';
	private const REASON_QUERY_KEY         = 'ran_booster_release_reason';
	private const DETAIL_QUERY_KEY         = 'ran_booster_release_detail';

	private readonly ProspectiveReleaseOperations $prospective_operations;
	private readonly ReleaseTrackingOperations $tracking;
	private readonly ManagedReleaseBrowserOperations $managed_browser;
	private readonly ReleaseManagementDisplay $display;
	private readonly RepositorySourceGuard $source_guard;

	/** @var \Closure(): (?array<string, mixed>) */
	private \Closure $native_hold;

	/** @var \Closure(): bool */
	private \Closure $updates_forced_off;

	/**
	 * @param (callable(): (?array<string, mixed>))|null $native_hold
	 * @param (callable(): bool)|null                     $updates_forced_off
	 * @param (callable(string,string,int,string,string,string,string,?array{identity:string,tag:string,version:string,installed_version?:string}):\RAN\AddOn\ReleaseTracking\ReleaseTrackingResult)|null $confirmed_enable
	 */
	public function __construct(
		ReleaseTrackingFacade $releases,
		ProspectiveReleaseFacade $prospective,
		callable $read_candidates,
		ManagedReleaseBrowser $managed_browser,
		?RepositorySourceGuard $source_guard = null,
		private readonly ?ProviderTabLinks $links = null,
		?callable $native_hold = null,
		?callable $updates_forced_off = null,
		?callable $confirmed_enable = null
	) {
		$this->display  = new ReleaseManagementDisplay();
		$this->tracking = new ReleaseTrackingOperations( $releases, $confirmed_enable );

		$this->managed_browser = new ManagedReleaseBrowserOperations( $managed_browser, $releases );

		$this->prospective_operations = new ProspectiveReleaseOperations( $prospective, $read_candidates );

		$this->source_guard       = $source_guard ?? new RepositorySourceGuard();
		$this->native_hold        = null === $native_hold ? static fn (): ?array => null : \Closure::fromCallable( $native_hold );
		$this->updates_forced_off = null === $updates_forced_off ? static fn (): bool => PackageMutationGuard::file_mods_disabled() : \Closure::fromCallable( $updates_forced_off );
	}

	public function register(): void {
		add_filter( 'ran_booster_admin_package_management_rows', array( $this, 'filter_management_rows' ), 10, 3 );
		add_filter( 'ran_booster_admin_package_management_actions', array( $this, 'filter_management_actions' ), 10, 3 );
		add_filter( 'ran_booster_admin_package_source_choices', array( $this, 'filter_source_choices' ), 10, 5 );
		add_filter( 'ran_booster_admin_package_advanced_source_summary', array( $this, 'filter_advanced_source_summary' ), 10, 5 );
		add_filter( 'ran_booster_admin_package_advanced_source_summary_projection', array( $this, 'filter_advanced_source_summary_projection' ), 10, 5 );
		add_filter( 'ran_booster_documentation_sections_before_about', array( $this, 'filter_documentation_sections' ), 10, 3 );
		add_action( 'ran_booster_admin_package_advanced_source_sections', array( $this, 'render_advanced_source_section' ), 10, 5 );
		add_action( 'ran_booster_admin_package_operation_actions', array( $this, 'render_package_operation_actions' ), 10, 5 );
		add_action( 'admin_notices', array( $this, 'render_operation_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_prospective_assets' ) );

		add_action( 'admin_post_ran_booster_release_enable', array( $this, 'handle_enable' ) );
		add_action( 'admin_post_ran_booster_release_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_ran_booster_release_return_to_branch', array( $this, 'handle_return_to_branch' ) );
		add_action( 'admin_post_ran_booster_release_change_channel', array( $this, 'handle_change_channel' ) );
		add_action( 'admin_post_ran_booster_release_install', array( $this, 'handle_prospective_install' ) );
		add_action( 'wp_ajax_ran_booster_release_list_candidates', array( $this, 'handle_prospective_list_candidates' ) );
		add_action( 'wp_ajax_ran_booster_release_inspect', array( $this, 'handle_prospective_inspect' ) );
		add_action( 'wp_ajax_ran_booster_release_switch_preflight', array( $this, 'handle_switch_preflight' ) );
		add_action( 'wp_ajax_ran_booster_managed_release_list_candidates', array( $this, 'handle_managed_list_candidates' ) );
		add_action( 'wp_ajax_ran_booster_managed_release_inspect', array( $this, 'handle_managed_inspect' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param list<object>                        $packages
	 * @return array<string, array<string, mixed>>
	 */

	public function filter_management_rows( array $rows, string $surface, array $packages ): array {
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
		if ( ! isset( $choices['release_asset'] ) ) {
			return $choices;
		}

		$choices['release_asset'] = array_merge(
			$choices['release_asset'],
			array(
				'heading'           => __( 'Releases', 'ran-booster' ),
				'description'       => __( 'Deploy published releases.', 'ran-booster' ),
				'url'               => 'edit' === $mode ? add_query_arg( 'source_view', 'release_asset', $page_url ) : '',
				'disabled'          => 'create' === $mode,
				'hydrated'          => true,
				'client_hydratable' => 'create' === $mode,
			)
		);
		if ( 'edit' === $mode && null !== $package ) {
			$status                    = $this->package_status( $package );
			$release_source_is_current = is_callable( array( $package, 'source' ) )
				&& 'release_asset' === $package->source();
			if ( ! $release_source_is_current
				&& ReleaseTrackingEligibility::SUBDIRECTORY_NOT_SUPPORTED === $status?->eligibility()->code() ) {
				$choices['release_asset']['description'] = __( 'Published releases require this plugin or theme to be at the repository root. This package uses a repository subdirectory, so continue using Branch deployments.', 'ran-booster' );
				$choices['release_asset']['disabled']    = true;
			} elseif ( ! $release_source_is_current
				&& false === $this->display->release_provider_supported( $package, $status ) ) {
				$choices['release_asset']['description'] = __( 'Published releases are not available for this Git provider.', 'ran-booster' );
				$choices['release_asset']['disabled']    = true;
			}
		}

		return $choices;
	}


	public function render_package_operation_actions( string $current_source, string $selected_source, string $type = '', string $identifier = '', int $revision = 0 ): void {
		if ( 'release_asset' === $current_source && 'release_asset' === $selected_source ) {
			$status             = $revision > 0 ? $this->request_boundary( fn () => $this->tracking->status( $type, $identifier, $revision ), null ) : null;
			$updates_forced_off = $this->updates_forced_off_for_display();
			$this->request_boundary( fn () => $this->display->render_native_update_action( $updates_forced_off ? null : $status ), null );
		}
	}

	private function updates_forced_off_for_display(): bool {
		return $this->request_boundary( $this->updates_forced_off, true );
	}

	public function render_advanced_source_section(
		string $mode,
		string $type,
		string $selected_source,
		?object $package,
		string $page_url
	): void {

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
				<p class="ran-booster-package-source-pane__description"><?php esc_html_e( 'Deploy when new packages are released.', 'ran-booster' ); ?></p>
			<?php
		}

		$status                = null === $package ? null : $this->package_status( $package );
		$repository_conflict   = null === $package ? array() : $this->repository_conflict_packages( $package, $status );
		$nonces                = null === $package ? array() : $this->package_nonce_actions( $package, $status );
		$native_hold_read      = $this->request_boundary( $this->native_hold, false );
		$native_hold           = is_array( $native_hold_read ) ? $native_hold_read : null;
		$updates_forced_off    = $this->updates_forced_off_for_display();
		$switch_confirmation   = null;
		$operation_notice_html = '';
		$duplicate_conflict    = in_array( $code, array( 'release_repository_conflict', 'repository_release_owner_exists' ), true )
			&& null !== $status && in_array( $status->failure_code(), array( 'release_repository_conflict', 'repository_release_owner_exists' ), true )
			&& $release_pane;
		if ( 'edit' === $mode && null !== $result && '' !== $code && ! $duplicate_conflict ) {
			if ( $this->is_switch_confirmation_code( $code ) ) {
				if ( $release_pane && null !== $status && $this->can_confirm_switch( $status, $nonces, $updates_forced_off, $native_hold_read ) ) {
					if ( (string) $status->source_revision() === $result['detail'] ) {
						$chooser = $this->switch_choices( $type, $status->identifier(), $status->source_revision(), $result['channel'], $status );
						if ( $chooser['successful'] ) {
							$switch_confirmation = $chooser['data'];
						} else {
							$operation_notice_html = esc_html( $chooser['data']['message'] );
						}
					} else {
						$operation_notice_html = $this->display->preflight_failure_message( 'release_selection_changed' );
					}
				}
			} elseif ( ! $this->is_top_level_switch_outcome( $code ) ) {
				[ $code, $successful ] = ReleaseTrackingOperations::settled( $code, $result['successful'], $status, $native_hold_read, $result['type'], $result['identifier'], $result['detail'] );
				$reload_url            = '' !== $result['identifier'] ? $this->return_url( $result['type'], $result['identifier'], true ) : '';
				ob_start();
				$this->request_boundary( fn () => $this->display->render_operation_notice( $code, $successful, $result['type'], $result['identifier'], $result['channel'], $status, $result['reason'], $result['detail'], $reload_url, $native_hold ), null );
				$operation_notice_html = (string) ob_get_clean();
			}
		}
		$browse_candidates = array();
		$browse_message    = '';
		$readiness_message = '';
		$recheck_request   = isset( $_GET['ran_booster_release_recheck'] ) && '1' === $_GET['ran_booster_release_recheck'];
		$list_request      = $_GET['ran_booster_release_list'] ?? '';
		$list_requests     = $recheck_request ? array( 'stable', 'prerelease' ) : ( is_string( $list_request ) && '' !== $list_request ? array( $list_request ) : array() );
		foreach ( $list_requests as $requested_track ) {
			if ( ! $release_pane || null === $status ) {
				continue;
			}
			$channel     = sanitize_key( wp_unslash( $requested_track ) );
			$list_nonces = $_GET['_ran_booster_release_list_nonce'] ?? array();
			$nonce       = is_array( $list_nonces ) ? ( $list_nonces[ $channel ] ?? null ) : null;
			$action      = in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $this->package_nonce_action( 'list_candidates', $package, $channel ) : null;
			$label       = 'stable' === $channel ? __( 'Stable', 'ran-booster' ) : __( 'Preview', 'ran-booster' );
			/* translators: Track name and, where applicable, a version or release tags. */
			$message = sprintf( __( '%s track: release lookup unavailable. Refresh the page and try again.', 'ran-booster' ), $label );
			if ( null !== $action && is_string( $nonce ) && current_user_can( 'manage_options' ) && current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' )
				&& false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), $action ) && '' === ReleaseTrackingOperations::repository_blocker( $status ) ) {
				$listing = $this->request_boundary( fn () => $this->managed_browser->list_candidates( $type, $status->identifier(), $status->source_revision(), $channel, sanitize_text_field( wp_unslash( $nonce ) ), 'branch' === $status->source() ), null );
				/* translators: Track name and, where applicable, a version or release tags. */
				$message = sprintf( __( '%s track: Booster could not list releases. Try again.', 'ran-booster' ), $label );
				if ( null !== $listing && $listing['successful'] && is_array( $listing['data']['candidates'] ?? null ) ) {
					$candidates = $listing['data']['candidates'];
					if ( $recheck_request ) {
						$version    = '';
						$comparable = class_exists( ReleaseVersion::class );
						foreach ( $candidates as $candidate ) {
							$found = $candidate['version'] ?? '';
							if ( ! is_string( $found ) || ! $comparable || null === ReleaseVersion::normalize_header( $found ) ) {
								$comparable = false;
								break;
							}
							if ( '' === $version || 1 === ReleaseVersion::compare( $found, $version ) ) {
								$version = $found;
							}
						}
						if ( ! $comparable && array() !== $candidates ) {
							$version = '';
						}

						/* translators: Track name and, where applicable, a version or release tags. */
						$message = '' !== $version ? sprintf( __( 'Latest %1$s release found: %2$s.', 'ran-booster' ), $label, $version ) : ( array() === $candidates ? sprintf( __( '%s track: no published version found in this lookup.', 'ran-booster' ), $label ) : sprintf( __( '%s track: the latest version could not be determined.', 'ran-booster' ), $label ) );
					} else {
						$browse_candidates = $candidates;
						/* translators: Track name. */
						$message = array() === $candidates ? sprintf( __( '%s track: no published releases found in this lookup.', 'ran-booster' ), $label ) : '';
					}
				} elseif ( isset( $listing['data']['rate_limited_provider'] ) ) {
					/* translators: Track name and, where applicable, a version or release tags. */
					$message = sprintf( __( '%s track: the Git provider rate-limited this lookup. Try again later.', 'ran-booster' ), $label );
				}
			}
			if ( $recheck_request ) {
				$readiness_message .= ( '' === $readiness_message ? '' : ' ' ) . $message;
			} else {
				$browse_message = $message;
			}
		}

		$prospective    = $this->prospective_projection( $type );
		$browse_channel = 'prerelease' === $list_request ? 'prerelease' : 'stable';

		$this->request_boundary( fn () => $this->display->render_advanced_source_section( $mode, $type, $selected_source, $package, $status, $page_url, $result['channel'] ?? '', $nonces, $prospective, $recheck_request, $operation_notice_html, $repository_conflict, $updates_forced_off, $switch_confirmation, $browse_message, $readiness_message, $browse_channel, $browse_candidates ), null );
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

		return $this->request_boundary( fn (): string => $this->display->advanced_source_summary( $summary, $mode, $selected_source, $package, null === $package ? null : $this->package_status( $package ) ), $summary );
	}

	/**
	 * @return array{heading:string,badges:list<array{label:string}>,status?:string}
	 * @param array{heading:string,badges:list<array{label:string}>,status?:string} $projection
	 */
	public function filter_advanced_source_summary_projection(
		array $projection,
		string $mode,
		string $type,
		string $selected_source,
		?object $package
	): array {

		unset( $type, $selected_source );

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
		if ( null === $result || '' === $code || $this->is_switch_confirmation_code( $code )
			|| ! $this->result_matches_current_screen( $result )
			|| ( $this->is_package_settings_request() && ! $this->is_top_level_switch_outcome( $code ) ) ) {
			return;
		}

		$status                = $this->request_boundary( fn (): ?ReleaseTrackingStatus => $this->tracking->fresh_status( $result['type'], $result['identifier'] ), null );
		$native_hold_read      = $this->request_boundary( $this->native_hold, false );
		$native_hold           = is_array( $native_hold_read ) ? $native_hold_read : null;
		[ $code, $successful ] = ReleaseTrackingOperations::settled( $code, $result['successful'], $status, $native_hold_read, $result['type'], $result['identifier'], $result['detail'] );
		$reload_url            = '' !== $result['identifier'] ? $this->return_url( $result['type'], $result['identifier'], true ) : '';
		\RAN\Admin\BoosterNoticeScope::render( fn () => $this->request_boundary( fn () => $this->display->render_operation_notice( $code, $successful, $result['type'], $result['identifier'], $result['channel'], $status, $result['reason'], $result['detail'], $reload_url, $native_hold ), null ) );
	}

	/**
	 * @param list<array<string, mixed>> $sections
	 * @return list<array<string, mixed>>
	 */

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
					<li><?php esc_html_e( 'Use your Git provider’s release guidance to publish an eligible installable plugin or theme ZIP.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Keep package identity consistent from release selection through installation. Booster rejects an archive it cannot verify.', 'ran-booster' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'Treat a published release asset as immutable. If a release is blocked, publish a corrected release instead of replacing its ZIP or changing installed metadata.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Choose and operate the source', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Prospective installation uses the Stable track by default. Choose Preview only when alpha, beta or release-candidate builds are acceptable. Preview still excludes drafts.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'For a not-yet-managed package, candidate listing is metadata-only. Choose one of at most eight eligible releases; inspection downloads, validates and discards that exact ZIP, then binds the reviewed choice to installation with a fingerprint.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Install performs a fresh exact acquisition. Before WordPress changes files, Booster rechecks fingerprint continuity, archive shape and size, Git provider and local digests, headers and package identity. WordPress installs synchronously, then Booster verifies the installed identity and unchanged target activation before adoption.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Open the package settings, choose Releases, review Release readiness, then choose Use releases. Booster checks and installs the release in one step; viewing settings changes nothing.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Switching to releases installs the newest release on the selected track. A package with Updates set to Disabled can\'t switch to releases. Switching in either direction turns Automatic into Manual; Manual and Disabled stay as they are. Webhook settings don\'t change.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Use Check releases on the managed Plugins or Themes screen to refresh release metadata. Booster validates the candidate, but WordPress remains the installer. Open WordPress updates to use the administrator’s normal WordPress update workflow.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Recovery and support', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'If an install can\'t complete, GitHub installs on this site go on hold. Open the package\'s page, check it, then release the hold.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Installed but unmanaged means a package now exists but Booster did not adopt it. Verify its installed version and activation state before using Link an already installed plugin (or theme) or retrying. An uncertain state or cleanup failure does not claim installation success: inspect installed packages and Booster management before retrying.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'If a release is blocked, publish a corrected release rather than editing installed metadata. You can return the package to branch management from its settings. If the selected Git provider capability becomes unavailable, Booster preserves package source state while suppressing release offers, downloads and mutations.', 'ran-booster' ); ?></p>
				<p><a href="<?php echo esc_url( 'https://github.com/RocketsAreNostalgic/ran-booster/blob/main/docs/package-update-orchestration.md' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Package update orchestration guide', 'ran-booster' ); ?> <?php \RAN\Admin\Component\ExternalLinkMarker::print(); ?></a></p>
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
		// Read the mutable global defensively: another plugin may have replaced it.
		$request = $GLOBALS['_POST'] ?? null;
		$request = is_array( $request ) ? $request : array();
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
		if ( ! in_array( $page, array( 'ran-booster-plugins-create', 'ran-booster-themes-create', 'ran-booster-plugins', 'ran-booster-themes' ), true ) ) {
			return;
		}

		$package_page = in_array( $page, array( 'ran-booster-plugins', 'ran-booster-themes' ), true );
		$dependencies = $package_page ? array( 'ran-booster-packages', 'wp-i18n', 'ran-booster-dialog' ) : array( 'ran-booster-packages', 'wp-i18n' );
		$type         = str_contains( $page, 'themes' ) ? 'theme' : 'plugin';
		$plugin_root  = dirname( __DIR__, 3 );
		$asset_root   = $plugin_root . '/assets';
		$script_path  = $asset_root . '/ran-booster-release-management.js';
		wp_enqueue_script(
			'ran-booster-release-management',
			plugins_url( 'assets/ran-booster-release-management.js', $plugin_root . '/ran-booster.php' ),
			$dependencies,
			is_file( $script_path ) ? (string) filemtime( $script_path ) : '1',
			true
		);
		wp_set_script_translations( 'ran-booster-release-management', 'ran-booster', $plugin_root . '/languages' );
		if ( $package_page ) {
			wp_enqueue_style( 'ran-booster-release-management', plugins_url( 'assets/ran-booster-release-management.css', $plugin_root . '/ran-booster.php' ), array( 'ran-booster-styles' ), is_file( $asset_root . '/ran-booster-release-management.css' ) ? (string) filemtime( $asset_root . '/ran-booster-release-management.css' ) : '1' );
			return;
		}
		$projection = $this->request_boundary(
			fn (): array => array(
				'providers' => $this->prospective_operations->supported_provider_codes( $type ),
				'nonces'    => array(
					'listCandidates' => $this->prospective_operations->nonce_action( 'list_candidates', $type, null ),
					'inspect'        => $this->prospective_operations->nonce_action( 'inspect', $type, null ),
					'install'        => '',
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
					'install'        => '',
				),
			)
		);
	}

	private function handle_admin_post( string $operation ): never {
		// This controller validates local authority and the operation-specific nonce before optional values.
		// Read the mutable global defensively: another plugin may have replaced it.
		$request = $GLOBALS['_POST'] ?? null;
		$request = is_array( $request ) ? $request : array();
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
					'swap'   => str_contains( $url, '#ran-booster-advanced-source-settings' ) ? 'outerHTML show:none' : 'outerHTML show:window:top',
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
		$channel    = in_array( $operation, array( 'enable', 'change_channel' ), true )
			? $this->release_channel_from( $request )
			: '';
		$operations = array( 'enable', 'refresh', 'change_channel', 'return_to_branch' );
		if ( in_array( $operation, $operations, true ) && '' !== $type && '' !== $identifier && $revision > 0 ) {
			if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' ) ) {
				$outcome = $this->package_outcome( $type, $identifier, 'forbidden', false );
			} else {
				$nonce_action = $this->request_boundary( fn (): string => $this->tracking->nonce_action( $operation, $type, $identifier, $revision ), '' );
				if ( '' === $nonce_action ) {
					$outcome = $this->package_outcome( $type, $identifier, 'service_unavailable', false );
				} elseif ( '' !== $nonce && 1 === wp_verify_nonce( $nonce, $nonce_action ) ) {
					if ( 'enable' === $operation && '' === $channel && ! array_key_exists( 'release_channel', $request ) ) {
						$channel = 'stable';
					}
					if ( 'enable' === $operation && '' !== $channel ) {
						$confirmed = is_string( $request['confirm_switch'] ?? null ) && '1' === wp_unslash( $request['confirm_switch'] );
						$choice    = $request['switch_release_choice'] ?? 'latest';
						if ( ! in_array( $choice, array( 'latest', 'installed' ), true ) ) {
							$outcome = $this->package_outcome( $type, $identifier, 'invalid_request', false );
						} elseif ( ! $confirmed ) {
							$outcome = $this->package_outcome( $type, $identifier, 'switch_choose', false ) + array( 'detail' => (string) $revision );
						} else {
							$release_id      = $this->bounded_release_token( is_string( $request['confirmed_release_identity'] ?? null ) ? wp_unslash( $request['confirmed_release_identity'] ) : '', 191 );
							$release_tag     = $this->bounded_release_token( is_string( $request['confirmed_release_tag'] ?? null ) ? wp_unslash( $request['confirmed_release_tag'] ) : '', 128 );
							$release_version = $this->bounded_release_token( is_string( $request['confirmed_release_version'] ?? null ) ? wp_unslash( $request['confirmed_release_version'] ) : '', 64 );
							$release_nonce   = is_string( $request['confirmed_release_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( $request['confirmed_release_nonce'] ) ) : '';
							$older_value     = is_string( $request['confirmed_release_older'] ?? null ) ? wp_unslash( $request['confirmed_release_older'] ) : '';
							$installed_proof = $this->bounded_release_token( is_string( $request['confirmed_installed_version'] ?? null ) ? wp_unslash( $request['confirmed_installed_version'] ) : '', 64 );
							$release_action  = $this->confirmed_release_nonce_action( $type, $identifier, $revision, $channel, $release_id, $release_tag, $release_version, '1' === $older_value, $installed_proof );
							$older_confirmed = is_string( $request['confirm_older_release'] ?? null ) && '1' === wp_unslash( $request['confirm_older_release'] );

							if ( '' === $installed_proof || '' === $release_id || '' === $release_tag || '' === $release_version || '' === $release_nonce || ! in_array( $older_value, array( '0', '1' ), true ) || 1 !== wp_verify_nonce( $release_nonce, $release_action ) ) {
								$outcome = $this->package_outcome( $type, $identifier, 'release_selection_changed', false );
							} elseif ( ( '1' === $older_value ) !== $older_confirmed ) {
								$outcome = $this->package_outcome( $type, $identifier, 'release_older', false );
							} else {
								$confirmed_release                      = array(
									'identity' => $release_id,
									'tag'      => $release_tag,
									'version'  => $release_version,
								);
								$confirmed_release['installed_version'] = $installed_proof;
								$outcome                                = $this->request_boundary(
									fn (): array => $this->tracking->execute( $operation, $type, $identifier, $revision, $channel, $nonce, $older_confirmed ? $release_id : '', $older_confirmed ? $release_tag : '', $confirmed_release ),
									$this->package_outcome( $type, $identifier, 'service_unavailable', false )
								);
							}
							if ( 'wordpress_failed' === $outcome['code'] ) {
								$outcome['code'] = 'switch_failed';
							} elseif ( 'management_state_uncertain' === $outcome['code'] ) {
								$outcome['code'] = 'switch_uncertain';
							}
						}
					} elseif ( ! in_array( $operation, array( 'enable', 'change_channel' ), true ) || '' !== $channel ) {
						$outcome = $this->request_boundary(
							fn (): array => $this->tracking->execute( $operation, $type, $identifier, $revision, $channel, $nonce ),
							$this->package_outcome( $type, $identifier, 'service_unavailable', false )
						);
					}
				}
			}
		}

		if ( 'enable' === $operation && $this->is_top_level_switch_outcome( $outcome['code'] ) ) {
			// Carry the attempt's source revision in the already signed result detail.
			$outcome['detail'] = (string) $revision;
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
		// A confirmed Check releases result travels as a closed-code flash, not as query arguments.
		$flash = 'refresh' === $operation && $outcome['successful']
			&& in_array( $outcome['code'], array( 'release_current', 'release_update_available' ), true )
			&& null !== $this->request_boundary( fn (): ?ReleaseTrackingStatus => $this->tracking->fresh_status( $outcome['type'], $outcome['identifier'] ), null );
		if ( $flash ) {
			FeedbackFlash::enqueue( 'release_current' === $outcome['code'] ? 'release_check_current' : 'release_check_update' );
		} elseif ( $outcome['successful'] && in_array( $operation, array( 'change_channel', 'return_to_branch' ), true ) && null !== FeedbackFlash::release_operation_success( $outcome['code'] ) ) {
			// A confirmed settings operation travels as a closed-code flash too; the new state is its own record.
			FeedbackFlash::enqueue( $outcome['code'] );
		} else {
			// A result that is not a success is its inline notice, which the host reads and which stays the record.
			// The landing page also reads the current source and native hold after a switch to Releases.
			$url = add_query_arg( $this->result_query_arguments( $outcome, $channel ), $url );
		}

		$top_of_page = 'enable' === $operation && $this->is_top_level_switch_outcome( $outcome['code'] );

		return $settings && ! $top_of_page ? $url . '#ran-booster-advanced-source-settings' : $url;
	}

	public function handle_switch_preflight(): void {
		$request = $GLOBALS['_POST'] ?? null;
		$request = is_array( $request ) ? $request : array();

		wp_send_json( $this->process_switch_preflight_request( $request ) );
	}

	/**
	 * Run read-only preflight before opening the interactive Branch-to-Releases confirmation dialog.
	 *
	 * @param array<string, mixed> $request
	 * @return array{successful:bool,code:string,data:array<string, mixed>}
	 */
	public function process_switch_preflight_request( array $request ): array {
		$type       = $this->strict_requested_type( $request );
		$identifier = $this->requested_identifier( $request );
		$revision   = $this->requested_revision( $request );
		$channel    = $this->release_channel_from( $request );
		$nonce      = is_string( $request['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $request['_wpnonce'] ) ) : '';
		$choice     = $request['switch_release_choice'] ?? 'latest';
		if ( ! in_array( $choice, array( 'latest', 'installed' ), true ) || '' === $type || '' === $identifier || $revision < 1 || '' === $channel ) {
			return $this->preflight_error_payload( 'invalid_request' );
		}
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' ) ) {
			return $this->preflight_error_payload( 'forbidden' );
		}
		$action = $this->request_boundary( fn (): string => $this->tracking->nonce_action( 'preflight', $type, $identifier, $revision, $channel ), '' );
		if ( '' === $action || '' === $nonce || 1 !== wp_verify_nonce( $nonce, $action ) ) {
			return $this->preflight_error_payload( 'invalid_request' );
		}

		$status = $this->request_boundary( fn (): ?ReleaseTrackingStatus => $this->tracking->status( $type, $identifier, $revision ), null );
		if ( null === $status || PackageSource::BRANCH->value !== $status->source() ) {
			return $this->preflight_error_payload( 'source_changed' );
		}
		if ( (bool) $this->request_boundary( $this->updates_forced_off, false ) ) {
			return $this->preflight_error_payload( 'policy_forced_off' );
		}
		if ( 'disabled' === $status->deployment_policy() ) {
			return $this->preflight_error_payload( 'policy_disabled' );
		}
		if ( PackageSourceGate::pauses( PackageSource::RELEASE_ASSET, $channel ) ) {
			return $this->preflight_error_payload( 'source_paused' );
		}

		$native_hold = $this->request_boundary( $this->native_hold, false );
		if ( false === $native_hold ) {
			return $this->preflight_error_payload( 'unable_to_check' );
		}
		if ( is_array( $native_hold ) ) {
			return $this->display->switch_hold_preflight_response( $type, $identifier, $native_hold );
		}

		return $this->switch_choices( $type, $identifier, $revision, $channel, $status );
	}

	/** @return array{successful:true,code:string,data:SwitchOption&array{matching_release?:(SwitchOption&array{relationship:'same'})}}|SwitchError */
	private function switch_choices( string $type, string $identifier, int $revision, string $channel, ReleaseTrackingStatus $status ): array {
		if ( ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			return $this->preflight_error_payload( 'invalid_request' );
		}
		if ( ! class_exists( ReleaseVersion::class ) ) {
			return $this->preflight_error_payload( 'version_comparison_unavailable' );
		}
		if ( ! $status->eligible() || '' !== ReleaseTrackingOperations::repository_blocker( $status ) ) {
			return $this->preflight_error_payload( 'source_changed' );
		}
		$listing = $this->request_boundary(
			fn () => $this->managed_browser->list_candidates( $type, $identifier, $revision, $channel, wp_create_nonce( $this->tracking->nonce_action( 'list_candidates', $type, $identifier, $revision, $channel ) ), true ),
			null
		);
		if ( null === $listing || ! $listing['successful'] || ! is_array( $listing['data']['candidates'] ?? null ) ) {
			if ( 'unable_to_check' === ( $listing['code'] ?? '' ) && is_string( $listing['data']['rate_limited_provider'] ?? null ) ) {
				return $this->preflight_error_payload( 'lookup_rate_limited' );
			}
			return $this->preflight_error_payload( $listing['code'] ?? 'unable_to_check' );
		}
		$installed = $status->installed_version();
		if ( '' === $this->bounded_release_token( $installed, 64 ) || ( $listing['data']['installed_version'] ?? '' ) !== $installed ) {
			return $this->preflight_error_payload( 'release_selection_changed' );
		}
		$latest          = null;
		$match           = null;
		$skipped_version = '';
		$skipped_reason  = '';
		foreach ( $listing['data']['candidates'] as $candidate ) {
			if ( ! is_array( $candidate ) || ( 'stable' === $channel && true === ( $candidate['prerelease'] ?? null ) ) ) {
				continue;
			}
			$id       = $this->bounded_release_token( $candidate['release_id'] ?? null, 191 );
			$tag      = $this->bounded_release_token( $candidate['tag'] ?? null, 128 );
			$version  = $this->bounded_release_token( $candidate['version'] ?? null, 64 );
			$relation = ReleaseVersion::relationship( $version, $installed );
			if ( '' === $id || '' === $tag ) {
				return $this->preflight_error_payload( 'invalid_release_assets' );
			}
			if ( ! in_array( $relation, array( 'same', 'newer', 'older' ), true ) ) {
				return $this->preflight_error_payload( 'version_comparison_unavailable' );
			}
			if ( 1 !== ( $candidate['zip_count'] ?? null ) ) {
				if ( '' === $skipped_version || 1 === ReleaseVersion::compare( $version, $skipped_version ) ) {
					$skipped_version = $version;
					$skipped_reason  = 0 === ( $candidate['zip_count'] ?? null ) ? 'package_asset_missing' : 'package_asset_ambiguous';
				}
				continue;
			}
			$option = array(
				'release_identity'            => $id,
				'release_tag'                 => $tag,
				'release_version'             => $version,
				'relationship'                => $relation,
				'version_relationship'        => $relation,
				'channel'                     => $channel,
				'installed_version'           => $installed,
				'confirmed_installed_version' => $installed,
				'fallback_message'            => '',
				'confirmed_release_nonce'     => wp_create_nonce( $this->confirmed_release_nonce_action( $type, $identifier, $revision, $channel, $id, $tag, $version, 'older' === $relation, $installed ) ),
			);
			if ( null === $latest || 1 === ReleaseVersion::compare( $version, $latest['release_version'] ) ) {
				$latest = $option;
			}
			if ( null === $match && 'same' === $relation ) {
				$match                 = $option;
				$match['relationship'] = 'same';
			}
		}
		if ( null === $latest ) {
			return $this->preflight_error_payload( 'release_unavailable', '' === $skipped_reason ? 'no_releases' : $skipped_reason, $skipped_version );
		}
		if ( '' !== $skipped_version && 1 === ReleaseVersion::compare( $skipped_version, $latest['release_version'] ) ) {
			$latest['fallback_message'] = $this->display->switch_fallback_message( $skipped_reason, $skipped_version );
		}
		if ( null !== $match && 'newer' === $latest['relationship'] ) {
			$latest['matching_release'] = $match;
		}
		return array(
			'successful' => true,
			'code'       => 'ready',
			'data'       => $latest,
		);
	}

	/** @return SwitchError */
	private function preflight_error_payload( string $code, string $reason = '', string $detail = '' ): array {
		$data = array(
			'message' => $this->display->preflight_failure_message( $code, $reason, $detail ),
		);
		if ( '' !== $reason ) {
			$data['reason'] = $reason;
		}
		if ( '' !== $detail ) {
			$data['detail'] = $detail;
		}

		return array(
			'successful' => false,
			'code'       => $code,
			'data'       => $data,
		);
	}

	private function is_top_level_switch_outcome( string $code ): bool {
		return in_array(
			$code,
			array(
				'release_enabled',
				'switch_failed',
				'switch_refused',
				'switch_uncertain',
				'native_hold',
				'policy_disabled',
				'policy_forced_off',
				'switch_held',
				'switch_not_confirmed',
				'switch_other_held',
				'switch_failed_unchanged',
			),
			true
		);
	}

	private function is_switch_confirmation_code( string $code ): bool {
		return 'switch_choose' === $code;
	}

	/**
	 * @param array<string, string> $nonces
	 * @param array<string, mixed>|false|null $native_hold
	 */
	private function can_confirm_switch( ?ReleaseTrackingStatus $status, array $nonces, bool $updates_forced_off, array|false|null $native_hold ): bool {
		return null !== $status
			&& PackageSource::BRANCH->value === $status->source()
			&& $status->eligible()
			&& 'disabled' !== $status->deployment_policy()
			&& ! $updates_forced_off
			&& null === $native_hold
			&& '' !== ( $nonces['enable'] ?? '' );
	}

	private function handle_prospective_ajax( string $operation ): never {
		// This controller selects and validates the exact purpose nonce before reading prospective domain values.
		// Read the mutable global defensively: another plugin may have replaced it.
		$request = $GLOBALS['_POST'] ?? null;
		$request = is_array( $request ) ? $request : array();
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
		// Read the mutable global defensively: another plugin may have replaced it.
		$request = $GLOBALS['_POST'] ?? null;
		$request = is_array( $request ) ? $request : array();
		$outcome = $this->process_managed_browser_request( $operation, $request );

		wp_send_json(
			array(
				'successful' => $outcome['successful'],
				'code'       => $outcome['code'],
				'data'       => $outcome['data'],
			)
		);
	}

	/**
	 * @param array<string,mixed> $request
	 * @return array{code:string,successful:bool,data:array<mixed>}
	 */

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

		$view_source = $request['view_source'] ?? '';
		$outcome     = $this->request_boundary(
			fn (): array => 'list_candidates' === $operation
				? $this->managed_browser->list_candidates( $type, $identifier, $revision, $channel, $nonce, 'branch' === $view_source )
				: $this->managed_browser->inspect( $type, $identifier, $revision, $release_id, $tag, $channel, $nonce ),
			array(
				'code'       => 'unable_to_check',
				'successful' => false,
				'data'       => array(),
			)
		);

		$outcome['data'] = $this->lookup_data( $outcome['code'], $outcome['data'] );

		return $outcome;
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
		$expectation = null;
		if ( 'install' === $operation ) {
			$raw = $request['provider_expectation'] ?? null;
			if ( ! is_string( $raw ) || strlen( $raw ) > 512 ) {
				return $fallback;
			}
			try {
				$expectation = json_decode( wp_unslash( $raw ), true, 4, JSON_THROW_ON_ERROR );
			} catch ( \JsonException ) {
				return $fallback;
			}
			if ( null !== $expectation && ! is_array( $expectation ) ) {
				return $fallback;
			}
		}
		$nonce_action = $this->request_boundary( fn (): string => $this->prospective_operations->nonce_action( $operation, $type, $expectation ), null );
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

		$outcome         = $this->request_boundary(
			fn (): array => 'list_candidates' === $operation ? $this->prospective_operations->list_candidates( $type, $repository, $channel ) : $this->prospective_operations->execute( $operation, $type, $repository, $release_id, $tag, $fingerprint, $channel, $nonce, $expectation ),
			$this->prospective_outcome( $type, 'service_unavailable' )
		);
		$outcome['data'] = $this->lookup_data( $outcome['code'], $outcome['data'] );

		return $outcome;
	}

	/**
	 * Replaces the internal rate-limit marker with the shared lookup-credential hint. The marker is only
	 * set for an anonymous public lookup, so a selected lookup credential never reaches this branch.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	private function lookup_data( string $code, array $data ): array {
		$provider = $data['rate_limited_provider'] ?? null;
		unset( $data['rate_limited_provider'] );
		if ( 'unable_to_check' === $code && is_string( $provider ) ) {
			$data['lookup_hint'] = ProviderTabLinks::lookup_hint( $this->links, admin_url( 'admin.php' ), $provider );
		}

		return $data;
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

	/**
	 * @param array<string, mixed> $request
	 */
	private function strict_requested_type( array $request ): string {
		$type = is_string( $request['expected_type'] ?? null ) ? sanitize_key( wp_unslash( $request['expected_type'] ) ) : '';

		return in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : '';
	}

	/**
	 * @param array<string, mixed> $request
	 */
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

	/** Read tracking status from a validated package projection. */
	private function package_status( object $package ): ?ReleaseTrackingStatus {
		return $this->request_boundary(
			function () use ( $package ): ?ReleaseTrackingStatus {
				if ( ! is_callable( array( $package, 'type' ) )
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
				if ( ! is_callable( array( $package, 'type' ) )
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
		if ( null !== $status && in_array( $status->source(), array( 'branch', 'release_asset' ), true ) ) {
			foreach ( array( 'stable', 'prerelease' ) as $channel ) {
				$nonce = $this->package_nonce_action( 'list_candidates', $package, $channel );
				if ( null !== $nonce ) {
					$actions[ 'branch_list_' . $channel ] = wp_create_nonce( $nonce );
				}
			}
		}
		if ( null !== $status && 'release_asset' === $status->source() ) {
			foreach ( array( 'list_candidates', 'inspect_candidate' ) as $operation ) {
				$nonce = $this->package_nonce_action( $operation, $package, $status->channel() );
				if ( null !== $nonce ) {
					$actions[ $operation ] = wp_create_nonce( $nonce );
				}
			}
		} elseif ( '' !== ( $actions['enable'] ?? '' ) && null !== $status && 'branch' === $status->source() && $status->eligible() ) {
			foreach ( array( 'stable', 'prerelease' ) as $channel ) {
				$nonce = $this->package_nonce_action( 'preflight', $package, $channel );
				if ( null !== $nonce ) {
					$actions[ 'preflight_' . $channel ] = wp_create_nonce( $nonce );
				}
			}
		}

		return $actions;
	}

	/** @return array<string,mixed> */
	private function prospective_projection( string $type ): array {
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return array();
		}

		return $this->request_boundary(
			fn (): array => array(
				'providers'       => $this->prospective_operations->supported_provider_codes( $type ),
				'list_candidates' => wp_create_nonce( $this->prospective_operations->nonce_action( 'list_candidates', $type, null ) ),
				'inspect'         => wp_create_nonce( $this->prospective_operations->nonce_action( 'inspect', $type, null ) ),
				'install'         => '',
			),
			array()
		);
	}

	/**
	 * @template TResult
	 * @template TFailure
	 * @param callable(): TResult $operation
	 * @param TFailure $failure
	 * @return TResult|TFailure
	 */
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
	 * @param array{type:string,identifier:string,code:string,successful:bool,reason?:string,check_reason?:string,detail?:string} $outcome
	 * @return array<string, string>
	 */
	private function result_query_arguments( array $outcome, string $channel = '' ): array {
		$type                            = in_array( $outcome['type'], array( 'plugin', 'theme' ), true ) ? $outcome['type'] : 'plugin';
		$identifier                      = strlen( $outcome['identifier'] ) <= 255 ? $outcome['identifier'] : '';
		$code                            = sanitize_key( $outcome['code'] );
		$code                            = strlen( $code ) <= 64 ? $code : 'invalid_request';
		$successful                      = $outcome['successful'];
		$channel                         = in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : '';
		$reason                          = $outcome['reason'] ?? '';
		$reason                          = in_array( $reason, ReleaseTrackingPreflight::reason_codes(), true ) ? $reason : '';
		$detail                          = ReleaseTrackingPreflight::detail( $outcome['detail'] ?? '' );
		$args                            = array(
			self::RESULT_QUERY_KEY         => $code,
			self::RESULT_SUCCESS_QUERY_KEY => $successful ? '1' : '0',
			self::RESULT_TYPE_QUERY_KEY    => $type,
			self::RESULT_PACKAGE_QUERY_KEY => $identifier,
		);
		$args[ self::CHANNEL_QUERY_KEY ] = $channel;
		if ( '' !== $reason ) {
			$args[ self::REASON_QUERY_KEY ] = $reason;
		}
		if ( '' !== $detail ) {
			$args[ self::DETAIL_QUERY_KEY ] = $detail;
		}
		$args[ self::RESULT_NONCE_QUERY_KEY ] = wp_create_nonce(
			$this->result_nonce_action( $code, $successful, $type, $identifier, $channel, $reason, $detail )
		);

		return $args;
	}

	/** @return array{code:string,successful:bool,type:string,identifier:string,channel:string,reason:string,detail:string}|null */
	private function requested_result(): ?array {
		$raw_code       = $_GET[ self::RESULT_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_success    = $_GET[ self::RESULT_SUCCESS_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_type       = $_GET[ self::RESULT_TYPE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_identifier = $_GET[ self::RESULT_PACKAGE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_channel    = $_GET[ self::CHANNEL_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_reason     = $_GET[ self::REASON_QUERY_KEY ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_detail     = $_GET[ self::DETAIL_QUERY_KEY ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$raw_nonce      = $_GET[ self::RESULT_NONCE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification value for this PRG result.
		if ( ! is_string( $raw_code ) || ! is_string( $raw_success ) || ! is_string( $raw_type )
			|| ! is_string( $raw_identifier ) || ! is_string( $raw_channel ) || ! is_string( $raw_reason ) || ! is_string( $raw_detail )
			|| ! is_string( $raw_nonce ) ) {
			return null;
		}

		$code       = wp_unslash( $raw_code );
		$success    = wp_unslash( $raw_success );
		$type       = wp_unslash( $raw_type );
		$identifier = wp_unslash( $raw_identifier );
		$channel    = wp_unslash( $raw_channel );
		$reason     = wp_unslash( $raw_reason );
		$detail     = wp_unslash( $raw_detail );
		$nonce      = wp_unslash( $raw_nonce );
		if ( sanitize_key( $code ) !== $code || '' === $code || strlen( $code ) > 64
			|| ! in_array( $success, array( '0', '1' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| sanitize_text_field( $identifier ) !== $identifier || strlen( $identifier ) > 255
			|| ! in_array( $channel, array( '', 'stable', 'prerelease' ), true )
			|| ( '' !== $reason && ! in_array( $reason, ReleaseTrackingPreflight::reason_codes(), true ) )
			|| ReleaseTrackingPreflight::detail( $detail ) !== $detail ) {
			return null;
		}

		$successful = '1' === $success;
		if ( 1 !== wp_verify_nonce( $nonce, $this->result_nonce_action( $code, $successful, $type, $identifier, $channel, $reason, $detail ) ) ) {
			return null;
		}

		return array(
			'code'       => $code,
			'successful' => $successful,
			'type'       => $type,
			'identifier' => $identifier,
			'channel'    => $channel,
			'reason'     => $reason,
			'detail'     => $detail,
		);
	}

	private function bounded_release_token( mixed $value, int $max_bytes ): string {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $max_bytes || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return '';
		}

		return sanitize_text_field( $value ) === $value ? $value : '';
	}

	private function confirmed_release_nonce_action( string $type, string $identifier, int $revision, string $channel, string $release_identity, string $release_tag, string $release_version, bool $older, string $installed_proof ): string {
		$facts   = array( $type, $identifier, $revision, $channel, $release_identity, $release_tag, $release_version, $older, $installed_proof );
		$payload = wp_json_encode( $facts );

		return 'ran-booster-older-switch-' . hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	private function result_nonce_action(
		string $code,
		bool $successful,
		string $type,
		string $identifier,
		string $channel,
		string $reason = '',
		string $detail = ''
	): string {
		// Absent optional values keep the original signed payload, so adding, removing or swapping one invalidates the nonce.
		// A detail without a reason is signed with an empty reason slot, so it cannot match a reason-only payload.
		$payload = wp_json_encode(
			match ( true ) {
				'' !== $detail => array( $code, $successful, $type, $identifier, $channel, $reason, $detail ),
				'' !== $reason => array( $code, $successful, $type, $identifier, $channel, $reason ),
				default => array( $code, $successful, $type, $identifier, $channel ),
			}
		);

		return self::RESULT_NONCE_ACTION . hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	/** @param array{code:string,successful:bool,type:string,identifier:string,channel:string,reason:string,detail:string} $result */
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
