<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Admin\ReleaseManagement\ReleaseTrackingOperations;
use RAN\PackageSource;
use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagement;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use Throwable;

/** @internal Provider-neutral release workflow routes and presentation. */
final class ReleaseWorkflowControls {
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
	private const FAILURE_DIAGNOSTIC_CODES              = array( 'malformed_request', 'permissions_unavailable', 'package_source_changed', 'nonce_expired', 'credential_authorisation_unavailable', 'preflight_contract_unavailable', 'provider_unavailable', 'no_releases', 'invalid_release', 'release_identity_mismatch', 'release_incompatible', 'release_version_mismatch', 'package_header_missing', 'package_header_invalid', 'package_archive_unreadable', 'package_zip_extension_unavailable', 'package_archive_size_invalid', 'package_archive_too_large', 'package_archive_path_unsafe', 'package_archive_path_duplicate', 'package_archive_root_invalid', 'package_archive_entry_duplicate', 'package_archive_entry_limit', 'release_version_invalid', 'package_update_uri_missing', 'package_update_uri_invalid', 'package_compatibility_missing', 'package_compatibility_invalid', 'package_header_ambiguous', 'release_automation_detected', 'repository_snapshot_unavailable', 'template_pack_unavailable', 'preview_storage_unavailable', 'repository_mutation_unverified', 'local_persistence_unavailable', 'unexpected_runtime_failure' );
	private const RESULT_NONCE_ACTION                   = 'ran-booster-release-workflow-result-';
	private const PREVIEW_QUERY_KEY                     = 'ran_booster_release_workflow_preview';
	private const CHANNEL_QUERY_KEY                     = 'ran_booster_release_workflow_channel';

	private readonly ReleaseTrackingOperations $tracking;
	private readonly ReleaseWorkflowDisplay $display;
	private readonly RepositorySourceGuard $sourceGuard;
	/** @var array<string,\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus|null> */
	private array $workflowStatuses = array();

	public function __construct(
		private readonly ReleaseTrackingFacade $releases,
		private readonly PluginRepository $plugins,
		private readonly ThemeRepository $themes,
		private readonly ProviderRegistry $providers,
		?RepositorySourceGuard $sourceGuard = null
	) {
		$this->tracking    = new ReleaseTrackingOperations( $releases );
		$this->display     = new ReleaseWorkflowDisplay();
		$this->sourceGuard = $sourceGuard ?? new RepositorySourceGuard();
	}

	public function register(): void {
		add_filter( 'ran_booster_admin_package_source_choices', array( $this, 'keepReleaseSettingsDiscoverable' ), 20, 5 );
		add_action( 'ran_booster_admin_package_release_readiness_actions', array( $this, 'renderPackageReleaseAutomationLink' ), 20, 2 );
		add_filter( 'ran_booster_provider_repository_rows', array( $this, 'enrichRepositoryRows' ), 20, 4 );
		add_action( 'admin_post_ran_booster_release_workflow', array( $this, 'handleWorkflow' ) );
	}

	/**
	 * Keep the provider release-workflow explanation reachable when Core disables the release transition.
	 *
	 * @param array<string, array<string, mixed>> $choices
	 * @return array<string, array<string, mixed>>
	 */
	public function keepReleaseSettingsDiscoverable(
		array $choices,
		string $mode,
		string $type,
		?object $package,
		string $pageUrl
	): array {
		unset( $type, $pageUrl );
		if ( 'edit' !== $mode || null === $package || ! isset( $choices['release_asset'] )
			|| ! is_callable( array( $package, 'providerCode' ) ) || ! $this->releaseProviderSupported( (string) $package->providerCode() ) ) {
			return $choices;
		}

		$choices['release_asset']['disabled'] = false;
		return $choices;
	}

	/**
	 * Add local release-workflow status and navigation to managed repository rows.
	 *
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $repositoryProjections
	 * @return array<string, array<string, mixed>>
	 */
	public function enrichRepositoryRows( array $rows, string $providerCode, array $repositoryProjections, string $returnUrl ): array {
		unset( $repositoryProjections, $returnUrl );
		if ( null === $this->workflowProvider( $providerCode ) ) {
			return $rows;
		}

		foreach ( $rows as &$row ) {
			if ( ! is_array( $row ) || true === ( $row['historical'] ?? false ) ) {
				continue;
			}
			$rowDetails     = is_array( $row['details'] ?? null ) ? $row['details'] : array();
			$availableSlots = 20 - count( $rowDetails );
			$summaries      = is_array( $row['package_summaries'] ?? null )
				? array_values( array_filter( $row['package_summaries'], 'is_array' ) )
				: array();
			$multiple       = 1 < count( $summaries );
			foreach ( $summaries as $summary ) {
				$projection = $this->repositoryReleaseAutomationProjection( $row, $summary, $multiple );
				if ( null === $projection ) {
					continue;
				}
				if ( 0 >= $availableSlots ) {
					continue;
				}
				$row['details'][]                               = $projection['detail'];
				$row['actions'][ $projection['action']['key'] ] = $projection['action'];
				--$availableSlots;
			}
		}
		unset( $row );

		return $rows;
	}

	public function renderPackageReleaseAutomationLink( object $package, ReleaseTrackingStatus $status ): void {
		if ( ! is_callable( array( $package, 'providerCode' ) )
			|| ! is_callable( array( $package, 'type' ) ) || ! is_callable( array( $package, 'identifier' ) )
			|| ! is_callable( array( $package, 'sourceRevision' ) ) ) {
			return;
		}
		$providerCode = (string) $package->providerCode();
		if ( null === $this->workflowProvider( $providerCode ) ) {
			return;
		}
		if ( ! hash_equals( $providerCode, $this->workflowProviderCode( $status ) )
			|| ! hash_equals( $status->type(), (string) $package->type() )
			|| ! hash_equals( $status->identifier(), (string) $package->identifier() )
			|| $status->sourceRevision() !== (int) $package->sourceRevision() ) {
			return;
		}

		echo '<a href="' . esc_url( $this->repositoryReleaseUrl( $status->providerRepositoryId(), $providerCode ) ) . '">'
			. esc_html__( 'Manage release workflow', 'ran-booster' ) . '</a>';
	}

	/** @param array<string,mixed> $row */
	public function renderRepositoryReleaseSections( array $row, string $returnUrl ): void {
		$providerCode = is_string( $row['provider_code'] ?? null ) ? $row['provider_code'] : '';
		if ( '' === $providerCode || true === ( $row['historical'] ?? false ) ) {
			return;
		}
		$repositoryId     = is_string( $row['repository_id'] ?? null ) ? $row['repository_id'] : '';
		$repository       = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$summaries        = is_array( $row['package_summaries'] ?? null ) ? array_values( array_filter( $row['package_summaries'], 'is_array' ) ) : array();
		$summary          = $summaries[0] ?? array();
		$type             = is_string( $summary['type'] ?? null ) ? $summary['type'] : '';
		$identifier       = is_string( $summary['identifier'] ?? null ) ? $summary['identifier'] : '';
		$revision         = is_int( $summary['source_revision'] ?? null ) ? $summary['source_revision'] : 0;
		$guard            = $this->repositorySourceGuard( $providerCode, $repositoryId, $type, $identifier, PackageSource::RELEASE_ASSET );
		$sharedBranch     = 0 === $guard['release_count'] && 1 < $guard['relationship_count'];
		$conflicted       = ! $guard['allowed'] && ! $sharedBranch;
		$single           = $guard['allowed'] && 1 === $guard['relationship_count'] && 1 === count( $summaries );
		$package          = $single ? $this->localPackage( $type, $identifier ) : null;
		$status           = $single
			? $this->requestBoundary( fn (): ?ReleaseTrackingStatus => $this->workflowDisplayStatus( $type, $identifier, $revision ), null )
			: null;
		$exact            = $status instanceof ReleaseTrackingStatus
			&& $this->statusMatchesSummary( $status, $type, $identifier, (string) ( $summary['source'] ?? '' ), $revision, $repositoryId )
			&& is_object( $package )
			&& is_callable( array( $package, 'getRepository' ) )
			&& hash_equals( $repository, (string) $package->getRepository() );
		$workflowStatus   = $exact ? $this->workflowProviderStatus( $status ) : null;
		$result           = $this->requestedResult();
		$matchingResult   = $exact && is_array( $result )
			&& hash_equals( $providerCode, (string) ( $result['provider'] ?? '' ) )
			&& hash_equals( $repositoryId, (string) ( $result['repository'] ?? '' ) )
			&& hash_equals( $type, (string) ( $result['type'] ?? '' ) )
			&& hash_equals( $identifier, (string) ( $result['identifier'] ?? '' ) )
			&& $revision === (int) ( $result['source_revision'] ?? 0 ) ? $result : null;
		$view             = $exact
			? $this->requestBoundary(
				fn (): ?array => $this->workflowViewFor(
					$type,
					$identifier,
					$revision,
					(string) ( $matchingResult['code'] ?? '' ),
					true === ( $matchingResult['successful'] ?? false ),
					$this->requestedPreviewKey(),
					(string) ( $matchingResult['channel'] ?? '' ),
					(string) ( $matchingResult['failure_stage'] ?? '' ),
					(string) ( $matchingResult['diagnostic_code'] ?? '' ),
					true === ( $matchingResult['diagnostic_available'] ?? false ),
					(string) ( $matchingResult['correlation_reference'] ?? '' ),
					(string) ( $matchingResult['message'] ?? '' ),
					(string) ( $matchingResult['remediation'] ?? '' )
				),
				$this->unavailableWorkflowView( __( 'Booster could not read the local release-workflow status for this package.', 'ran-booster' ) )
			)
			: $this->unavailableWorkflowView( $sharedBranch || $conflicted ? '' : __( 'Booster could not confirm that this release status belongs to the exact saved package and source.', 'ran-booster' ) );
		$packageReadiness = $exact ? array(
			array(
				'name'         => is_string( $summary['display_name'] ?? null ) ? $summary['display_name'] : $identifier,
				'type'         => $type,
				'eligible'     => $status->eligible(),
				'message'      => $this->repositoryPackageReadinessMessage( $status ),
				'tracking'     => 'release_asset' === $status->source(),
				'channel'      => $status->channel(),
				'settings_url' => is_string( $summary['settings_url'] ?? null ) ? $summary['settings_url'] : '',
			),
		) : array();
		$observation      = is_array( $view['assessment_observation'] ?? null ) ? $view['assessment_observation'] : null;
		$observationKind  = is_array( $observation ) && is_string( $observation['kind'] ?? null ) ? $observation['kind'] : 'unassessed';
		$automationState  = $this->repositoryReleaseAutomationState(
			$exact && $this->recordMatchesPackageStatus( $workflowStatus, $status ) ? $identifier : '',
			$exact && $status->eligible() && 'branch' === $status->source(),
			$exact && $this->publishedReleasesWorking( $status ),
			$workflowStatus?->recordOccupied() ?? false,
			$observationKind,
			$sharedBranch
		);
		$automationNotice = '';
		if ( ! $sharedBranch && ! $conflicted ) {
			$automationNotice = true === ( $view['unavailable'] ?? false )
				? $this->display->stateNotice( $view )
				: '<div class="notice ' . esc_attr( $automationState['notice_tone'] ) . ' inline"><p>' . esc_html( $automationState['message'] ) . '</p>'
					. ( 'existing_automation_detected' === $observationKind && '' !== ( $workflowStatus?->providerWorkflowUrl() ?? '' )
						? '<p><a href="' . esc_url( $workflowStatus->providerWorkflowUrl() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review existing workflow', 'ran-booster' ) . '</a></p>'
						: '' ) . '</div>';
		}
		$workflowResultNotice = '';
		if ( is_array( $matchingResult ) ) {
			$resultObservationKind = $this->observationKindForResult( (string) $matchingResult['code'] );
			if ( '' === $resultObservationKind || ! hash_equals( $resultObservationKind, $observationKind ) ) {
				$workflowResultNotice = $this->display->resultNotice( $view );
			}
		}
		?>
		<section class="ran-booster-settings-section ran-booster-repository-release-section" aria-labelledby="ran-booster-repository-release-heading">
			<header class="ran-booster-settings-section__header ran-booster-repository-release-section__header">
				<h3 id="ran-booster-repository-release-heading"><?php echo esc_html__( 'Release publishing', 'ran-booster' ); ?></h3>
				<?php if ( $single && '' !== ( $summary['settings_url'] ?? '' ) ) { ?>
					<a href="<?php echo esc_url( (string) $summary['settings_url'] ); ?>"><?php echo esc_html( 'plugin' === $type ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ) ); ?></a>
				<?php } ?>
			</header>
			<div class="ran-booster-settings-section__body">
			<?php if ( $sharedBranch ) { ?>
				<div class="notice notice-info inline"><p><?php // translators: %d is the number of managed packages using this repository. ?><?php echo esc_html( sprintf( __( 'Releases require a repository used by only one managed package. This repository is shared by %d packages.', 'ran-booster' ), $guard['relationship_count'] ) ); ?> <a href="<?php echo esc_url( $returnUrl ); ?>"><?php echo esc_html__( 'Status', 'ran-booster' ); ?></a></p></div>
				<?php } elseif ( $conflicted ) { ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Release workflow is unavailable until this repository uses one allowed package source.', 'ran-booster' ); ?>
					<?php foreach ( $summaries as $packageSummary ) { ?>
						<?php
						if ( '' !== ( $packageSummary['settings_url'] ?? '' ) ) {
							?>
							<a href="<?php echo esc_url( (string) $packageSummary['settings_url'] ); ?>"><?php // translators: %s is a managed package name. ?><?php echo esc_html( sprintf( __( 'Open %s settings', 'ran-booster' ), (string) ( $packageSummary['display_name'] ?? $packageSummary['identifier'] ?? '' ) ) ); ?></a><?php } ?>
					<?php } ?>
					</p></div>
				<?php } elseif ( $exact && ! $status->eligible() ) { ?>
					<div class="notice notice-warning inline ran-booster-repository-release-section__notice"><p><?php echo esc_html( $this->repositoryPackageReadinessMessage( $status ) ); ?></p></div>
				<?php } ?>
				<?php $this->renderRepositoryReleaseLifecycle( $exact && $status->eligible(), $exact && 'release_asset' === $status->source(), $exact && $this->recordMatchesStatus( $workflowStatus, $status ), $exact && $status->eligible() && 'branch' === $status->source(), $exact && $this->publishedReleasesWorking( $status ), $observationKind, null !== $this->workflowProvider( $providerCode ) ); ?>
				<?php $this->renderRepositoryReadiness( $repository, $guard['relationship_count'], $packageReadiness, $this->releaseProviderSupported( $providerCode ) ); ?>
				<?php if ( null !== $this->workflowProvider( $providerCode ) || null !== $this->workflowCapability( $providerCode ) ) { ?>
				<section class="ran-booster-readiness-panel ran-booster-repository-release-automation" aria-labelledby="ran-booster-repository-release-automation-heading">
					<header class="ran-booster-readiness-panel__top ran-booster-repository-release-automation__header"><div><div class="ran-booster-release-automation-heading"><h4 id="ran-booster-repository-release-automation-heading"><?php echo esc_html__( 'Release workflow', 'ran-booster' ); ?></h4><span class="ran-booster-badge <?php echo esc_attr( $automationState['tone'] ); ?>"><?php echo esc_html( $automationState['label'] ); ?></span></div><p class="description"><?php echo esc_html( $automationState['provenance'] ); ?></p></div></header>
					<?php if ( '' !== $automationNotice || '' !== $workflowResultNotice ) { ?>
						<div class="ran-booster-repository-release-automation__notices">
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Notice markup is escaped above or by the display projection. ?><?php echo $automationNotice; ?>
							<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display projection escapes complete output. ?><?php echo $workflowResultNotice; ?>
						</div>
					<?php } ?>
					<div class="ran-booster-repository-release-automation__body"><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display projection escapes complete output. ?><?php echo $this->display->workflow( $view, false ); ?></div>
				</section>
				<?php } ?>
			</div>
		</section>
		<?php
	}

	/** @return array{allowed:bool,code:string,relationship_count:int,release_count:int,owner_type:?int,owner_package:?string} */
	private function repositorySourceGuard( string $providerCode, string $repositoryId, string $type, string $identifier, PackageSource $source ): array {
		$typeId = 'plugin' === $type ? 1 : ( 'theme' === $type ? 2 : 0 );

		return $this->requestBoundary(
			fn (): array => $this->sourceGuard->assess( $providerCode, $repositoryId, $typeId, $identifier, $source ),
			array(
				'allowed'            => false,
				'code'               => 'repository_source_unavailable',
				'relationship_count' => 0,
				'release_count'      => 0,
				'owner_type'         => null,
				'owner_package'      => null,
			)
		);
	}

	private function renderRepositoryReleaseLifecycle( bool $packageReady, bool $trackingReady, bool $workflowRecorded, bool $workflowReadyToAssess, bool $publishedReleasesWorking, string $observationKind, bool $workflowAvailable ): void {
		$automationReady = $workflowRecorded || in_array( $observationKind, array( 'existing_automation_detected', 'booster_setup_verified' ), true );
		$automationLabel = $workflowRecorded
			? __( 'Setup pull request recorded; check its outcome.', 'ran-booster' )
			: match ( $observationKind ) {
				'existing_automation_detected' => __( 'Existing workflow found.', 'ran-booster' ),
				'booster_setup_verified'       => __( 'Compatible workflow configuration verified.', 'ran-booster' ),
				'no_recognisable_automation'   => __( 'No workflow found; setup is available.', 'ran-booster' ),
				default                        => $workflowReadyToAssess
					? __( 'Ready to assess.', 'ran-booster' )
					: ( $publishedReleasesWorking
						? __( 'Releases are available; workflow not assessed.', 'ran-booster' )
						: __( 'Workflow setup needs attention.', 'ran-booster' ) ),
			};
		$items = array(
			array(
				'label'   => __( 'Prepare package', 'ran-booster' ),
				'message' => $packageReady ? __( 'This package is ready for published-release tracking.', 'ran-booster' ) : __( 'This package needs attention before using published releases.', 'ran-booster' ),
				'state'   => $packageReady ? 'is-ok' : 'is-warning',
			),
			array(
				'label'   => __( 'Track releases', 'ran-booster' ),
				'message' => $trackingReady ? __( 'Published releases are selected.', 'ran-booster' ) : __( 'Published releases are not selected.', 'ran-booster' ),
				'state'   => $trackingReady ? 'is-ok' : 'is-pending',
			),
		);
		if ( $workflowAvailable ) {
			$items[] = array(
				'label'   => __( 'Release workflow — optional', 'ran-booster' ),
				'message' => $automationLabel,
				'state'   => $automationReady ? 'is-ok' : ( $publishedReleasesWorking ? 'is-pending' : 'is-warning' ),
			);
		}
		?>
		<ol class="ran-booster-webhook-steps ran-booster-repository-webhook-lifecycle ran-booster-repository-release-lifecycle" aria-label="<?php echo esc_attr( __( 'Published release lifecycle', 'ran-booster' ) ); ?>">
		<?php foreach ( $items as $number => $item ) { ?>
			<li class="ran-booster-webhook-step <?php echo esc_attr( $item['state'] ); ?>">
				<span aria-hidden="true"><?php echo esc_html( (string) ( $number + 1 ) ); ?></span>
				<strong><?php echo esc_html( $item['label'] ); ?></strong>
				<p><?php echo esc_html( $item['message'] ); ?></p>
			</li>
		<?php } ?>
		</ol>
		<?php
	}

	/** @param list<array{name:string,type:string,eligible:bool,message:string,tracking:bool,channel:string,settings_url:string}> $packageReadiness */
	private function renderRepositoryReadiness( string $repository, int $exactPackageRelationships, array $packageReadiness, bool $workflowAvailable ): void {
		$relationshipReady   = '' !== $repository && 0 < $exactPackageRelationships;
		$relationshipMessage = __( 'No exact package relationship is available for this saved repository.', 'ran-booster' );
		if ( $relationshipReady ) {
			/* translators: 1: package relationship count, 2: repository name. */
			$relationshipFormat  = _n( '%1$d exact package relationship is recorded for %2$s.', '%1$d exact package relationships are recorded for %2$s.', $exactPackageRelationships, 'ran-booster' );
			$relationshipMessage = sprintf(
				$relationshipFormat,
				$exactPackageRelationships,
				$repository
			);
		}
		?>
		<section class="ran-booster-readiness-panel ran-booster-repository-release-readiness" aria-labelledby="ran-booster-repository-release-readiness-heading">
			<div class="ran-booster-readiness-panel__top"><div>
				<h4 id="ran-booster-repository-release-readiness-heading"><?php echo esc_html__( 'Release readiness', 'ran-booster' ); ?></h4>
				<p><?php echo esc_html__( 'Saved repository facts; no live provider check.', 'ran-booster' ); ?></p>
			</div></div>
			<div class="ran-booster-repository-release-readiness__body">
				<ul class="ran-booster-readiness-list">
					<li class="ran-booster-readiness-item <?php echo $workflowAvailable ? 'is-ok' : 'is-pending'; ?>">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php echo esc_html__( 'Provider capability', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html( $workflowAvailable ? __( 'This provider supports published releases.', 'ran-booster' ) : __( 'This provider does not implement all required release capabilities.', 'ran-booster' ) ); ?></span>
					</li>
					<li class="ran-booster-readiness-item <?php echo $relationshipReady ? 'is-ok' : 'is-warning'; ?>">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php echo esc_html__( 'Repository relationship', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html( $relationshipMessage ); ?></span>
					</li>
					<?php
					foreach ( $packageReadiness as $packageFact ) {
						$typeLabel = 'plugin' === $packageFact['type'] ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' );
						/* translators: 1: package type, 2: package display name. */
						$readinessLabel = sprintf( __( '%1$s readiness — %2$s', 'ran-booster' ), $typeLabel, $packageFact['name'] );
						/* translators: 1: package type, 2: package display name. */
						$sourceLabel = sprintf( __( '%1$s source — %2$s', 'ran-booster' ), $typeLabel, $packageFact['name'] );
						$trackLabel  = 'prerelease' === $packageFact['channel'] ? __( 'Preview', 'ran-booster' ) : __( 'Stable', 'ran-booster' );
						/* translators: %s: Stable or Preview release track. */
						$sourceMessage = $packageFact['tracking'] ? sprintf( __( 'Releases · %s track.', 'ran-booster' ), $trackLabel ) : __( 'Branch. Change source and track in package settings.', 'ran-booster' );
						?>
						<li class="ran-booster-readiness-item <?php echo $packageFact['eligible'] ? 'is-ok' : 'is-warning'; ?>">
							<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
							<strong><?php echo esc_html( $readinessLabel ); ?></strong>
							<span><?php echo esc_html( $packageFact['message'] ); ?>
							<?php if ( ! $packageFact['eligible'] && '' !== $packageFact['settings_url'] ) { ?>
								<a href="<?php echo esc_url( $packageFact['settings_url'] ); ?>"><?php echo esc_html__( 'Review package settings', 'ran-booster' ); ?></a>
							<?php } ?></span>
						</li>
						<li class="ran-booster-readiness-item <?php echo $packageFact['tracking'] ? 'is-ok' : 'is-pending'; ?>">
							<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
							<strong><?php echo esc_html( $sourceLabel ); ?></strong>
							<span><?php echo esc_html( $sourceMessage ); ?>
							<?php if ( ! $packageFact['tracking'] && '' !== $packageFact['settings_url'] ) { ?>
								<a href="<?php echo esc_url( $packageFact['settings_url'] ); ?>"><?php echo esc_html__( 'Open package source settings', 'ran-booster' ); ?></a>
							<?php } ?></span>
						</li>
					<?php } ?>
				</ul>
			</div>
		</section>
		<?php
	}

	/** @return array{label:string,tone:string,message:string,notice_tone:string,provenance:string} */
	private function repositoryReleaseAutomationState( string $workflowOwner, bool $workflowReadyToAssess, bool $publishedReleasesWorking, bool $recordOccupied, string $observationKind, bool $unavailable = false ): array {
		$state   = __( 'Needs attention', 'ran-booster' );
		$tone    = 'ran-booster-badge--error';
		$message = __( 'Release workflow status is unavailable.', 'ran-booster' );
		$notice  = 'notice-warning';
		$origin  = __( 'Booster setup: Not recorded.', 'ran-booster' );
		if ( $unavailable ) {
			$state   = __( 'Unavailable', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'Release workflow is unavailable for this shared repository.', 'ran-booster' );
			$notice  = 'notice-info';
		} elseif ( '' !== $workflowOwner ) {
			$state   = __( 'Setup recorded', 'ran-booster' );
			$tone    = 'ran-booster-badge--warning';
			$message = sprintf(
				/* translators: %s: exact package type and name. */
				__( 'A setup pull request is recorded for %s. Check its outcome before relying on the workflow.', 'ran-booster' ),
				$workflowOwner
			);
			$origin = __( 'Booster setup: Draft pull request recorded.', 'ran-booster' );
		} elseif ( 'existing_automation_detected' === $observationKind ) {
			$state   = __( 'Existing workflow found', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'An existing release workflow was found in this repository. Booster will not overwrite it.', 'ran-booster' );
			$notice  = 'notice-info';
			$origin  = __( 'Booster setup: Not recorded.', 'ran-booster' );
		} elseif ( 'booster_setup_verified' === $observationKind ) {
			$state   = __( 'Compatible workflow verified', 'ran-booster' );
			$tone    = 'ran-booster-badge--success';
			$message = __( 'A Booster-compatible workflow configuration was verified. Execution has not been checked.', 'ran-booster' );
			$notice  = 'notice-success';
			$origin  = __( 'Booster setup: No local setup pull-request record.', 'ran-booster' );
		} elseif ( 'mixed_observations' === $observationKind ) {
			$state   = __( 'Multiple assessments', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'Workflow assessments differ. Review each package below.', 'ran-booster' );
			$notice  = 'notice-info';
		} elseif ( 'no_recognisable_automation' === $observationKind ) {
			$state   = __( 'No workflow found', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'No recognizable release workflow was found. Booster can prepare a setup pull request.', 'ran-booster' );
			$notice  = 'notice-info';
			$origin  = __( 'Booster setup: Not recorded.', 'ran-booster' );
		} elseif ( $workflowReadyToAssess ) {
			$state   = __( 'Ready to assess', 'ran-booster' );
			$tone    = 'ran-booster-badge--success';
			$message = __( 'Assess this repository before preparing a setup pull request.', 'ran-booster' );
		} elseif ( $recordOccupied ) {
			$state   = __( 'Blocked', 'ran-booster' );
			$message = __( 'A local workflow record is occupied by a different package or revision. Review it before setup.', 'ran-booster' );
		} elseif ( $publishedReleasesWorking ) {
			$state   = __( 'Not assessed', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'Releases are available; their publishing method has not been assessed.', 'ran-booster' );
			$notice  = 'notice-info';
			$origin  = __( 'Booster setup: Not recorded.', 'ran-booster' );
		}

		return array(
			'label'       => $state,
			'tone'        => $tone,
			'message'     => $message,
			'notice_tone' => $notice,
			'provenance'  => $origin,
		);
	}

	private function renderPackageAutomationObservation( string $kind ): void {
		$state = match ( $kind ) {
			'existing_automation_detected' => array( __( 'Existing workflow found', 'ran-booster' ), 'ran-booster-badge--info' ),
			'booster_setup_verified'       => array( __( 'Compatible workflow verified', 'ran-booster' ), 'ran-booster-badge--success' ),
			'no_recognisable_automation'   => array( __( 'No workflow found', 'ran-booster' ), 'ran-booster-badge--info' ),
			'unassessed'                   => array( __( 'Not assessed', 'ran-booster' ), 'ran-booster-badge--info' ),
			default                        => null,
		};
		if ( ! is_array( $state ) ) {
			return;
		}
		?>
		<span class="ran-booster-badge <?php echo esc_attr( $state[1] ); ?>"><?php echo esc_html( $state[0] ); ?></span>
		<?php
	}

	private function observationKindForResult( string $code ): string {
		return match ( $code ) {
			'workflow_release_automation_conflict' => 'existing_automation_detected',
			'workflow_release_automation_present'  => 'booster_setup_verified',
			'workflow_inspected'                   => 'no_recognisable_automation',
			default                                => '',
		};
	}

	private function publishedReleasesWorking( ReleaseTrackingStatus $status ): bool {
		return $status->eligible()
			&& 'release_asset' === $status->source()
			&& '' === $status->failureCode();
	}

	private function statusMatchesSummary( ReleaseTrackingStatus $status, string $type, string $identifier, string $source, int $revision, string $repositoryId ): bool {
		return hash_equals( $type, $status->type() )
			&& hash_equals( $identifier, $status->identifier() )
			&& hash_equals( $source, $status->source() )
			&& $revision === $status->sourceRevision()
			&& hash_equals( $repositoryId, $status->providerRepositoryId() );
	}

	private function repositoryPackageReadinessMessage( ReleaseTrackingStatus $status ): string {
		return match ( $status->eligibility()->code() ) {
			'eligible' => __( 'Installed identity and Update URI match the configured repository.', 'ran-booster' ),
			'missing_update_uri' => __( 'The installed package does not declare the required Update URI.', 'ran-booster' ),
			'mismatched_update_uri' => __( 'The installed package Update URI does not match this repository.', 'ran-booster' ),
			'invalid_package_identity' => __( 'The installed package identity does not match this repository.', 'ran-booster' ),
			'subdirectory_not_supported' => __( 'This package uses a repository subdirectory. Published releases require the repository root.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'This package already uses another release updater.', 'ran-booster' ),
			default => __( 'The saved package or repository relationship needs attention.', 'ran-booster' ),
		};
	}

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
				if ( ! $this->workflowSourceAllowed( $type, $identifier, $package ) ) {
					$outcome['diagnostic_code'] = 'repository_release_owner_exists';
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
		$this->workflowStatuses = array();
		$args                   = $this->resultQueryArguments( $outcome, $channel, $revision, $providerCode, $repositoryId );
		if ( '' !== $outcome['preview_key'] ) {
			$args[ self::PREVIEW_QUERY_KEY ] = $outcome['preview_key']; }
		$url = $exact ? $this->repositoryReleaseUrl( $repositoryId, $providerCode ) : $this->returnUrl( $type, $identifier, true );
		return add_query_arg( $args, $url ) . '#ran-booster-repository-release-workflows';
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

	private function workflowSourceAllowed( string $type, string $identifier, object $package ): bool {
		if ( ! is_callable( array( $package, 'getProviderCode' ) )
			|| ! is_callable( array( $package, 'getProviderRepositoryId' ) )
			|| ! is_string( $package->getProviderRepositoryId() ) ) {
			return false;
		}

		return $this->repositorySourceGuard(
			$package->getProviderCode(),
			$package->getProviderRepositoryId(),
			$type,
			$identifier,
			PackageSource::RELEASE_ASSET
		)['allowed'];
	}

	private function workflowNonceAction( string $operation, ReleaseTrackingStatus $status, string $preview = '' ): string {
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
		$package = $this->localPackage( $type, $identifier );
		return null !== $package && $revision === $package->getSourceRevision()
			&& is_string( $package->getProviderRepositoryId() ) && '' !== $package->getProviderRepositoryId()
			? $package : null;
	}
	private function packageMatchesStatus( object $package, ReleaseTrackingStatus $status ): bool {
		return $status->providerRepositoryId() === $package->getProviderRepositoryId()
			&& $status->sourceRevision() === $package->getSourceRevision();
	}
	private function anonymousWorkflowInspectionAllowed( object $package ): bool {
		return is_callable( array( $package, 'isPrivate' ) )
			&& false === $this->requestBoundary( fn (): mixed => $package->isPrivate(), null );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $summary
	 * @return array{detail:array{label:string,value:string,tone:string},action:array<string,mixed>}|null
	 */
	private function repositoryReleaseAutomationProjection( array $row, array $summary, bool $multiple ): ?array {
		$type            = is_string( $summary['type'] ?? null ) ? $summary['type'] : '';
		$reference       = is_string( $summary['identifier'] ?? null ) ? $summary['identifier'] : '';
		$summarySource   = is_string( $summary['source'] ?? null ) ? $summary['source'] : '';
		$summaryRevision = is_int( $summary['source_revision'] ?? null ) ? $summary['source_revision'] : 0;
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || '' === $reference
			|| ! in_array( $summarySource, array( 'branch', 'release_asset' ), true ) || 1 > $summaryRevision ) {
			return null;
		}
		$package      = $this->localPackage( $type, $reference );
		$status       = null;
		$providerCode = is_string( $row['provider_code'] ?? null ) ? $row['provider_code'] : '';
		$repository   = is_string( $row['repository_id'] ?? null ) ? $row['repository_id'] : '';
		$locator      = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$exact        = null !== $this->workflowProvider( $providerCode ) && null !== $package && '' !== $repository
			&& is_callable( array( $package, 'getIdentifier' ) )
			&& is_callable( array( $package, 'getProviderCode' ) )
			&& is_callable( array( $package, 'getProviderRepositoryId' ) )
			&& is_callable( array( $package, 'getRepository' ) )
			&& is_callable( array( $package, 'getSourceRevision' ) )
			&& is_string( $package->getIdentifier() )
			&& hash_equals( $reference, $package->getIdentifier() )
			&& hash_equals( $providerCode, (string) $package->getProviderCode() )
			&& is_string( $package->getProviderRepositoryId() )
			&& hash_equals( $repository, $package->getProviderRepositoryId() )
			&& hash_equals( $locator, (string) $package->getRepository() )
			&& $summaryRevision === $package->getSourceRevision();
		if ( $exact ) {
			$status = $this->requestBoundary(
				fn (): ?ReleaseTrackingStatus => $this->tracking->status( $type, $reference, $summaryRevision ),
				null
			);
			$exact  = $status instanceof ReleaseTrackingStatus
				&& hash_equals( $repository, $status->providerRepositoryId() )
				&& hash_equals( $type, $status->type() )
				&& hash_equals( $reference, $status->identifier() )
				&& $summaryRevision === $status->sourceRevision()
				&& hash_equals( $summarySource, $status->source() );
		}

		$value = __( 'Unavailable', 'ran-booster' );
		$tone  = 'warning';
		if ( $exact && $status instanceof ReleaseTrackingStatus ) {
			$workflowStatus = $this->workflowProviderStatus( $status );
			if ( $this->recordMatchesStatus( $workflowStatus, $status ) ) {
				$value = __( 'Setup recorded', 'ran-booster' );
				$tone  = 'pending';
			} elseif ( $this->publishedReleasesWorking( $status ) ) {
				$value = __( 'Published releases working', 'ran-booster' );
				$tone  = 'ok';
			} elseif ( ! ( $workflowStatus?->recordOccupied() ?? true ) && $status->eligible() ) {
				$value = 'branch' === $status->source()
					? __( 'Ready to assess', 'ran-booster' )
					: __( 'Published releases selected', 'ran-booster' );
				$tone  = 'branch' === $status->source() ? 'ok' : 'pending';
			}
		}

		$settingsUrl = $this->repositoryReleaseUrl( $repository, $providerCode );
		$label       = $multiple
			? sprintf(
				/* translators: %s is a managed plugin file or theme stylesheet. */
				__( 'Release workflow: %s', 'ran-booster' ),
				$this->boundedReference( $reference, 74 )
			)
			: __( 'Release workflow', 'ran-booster' );
		$detailLabel = $multiple
			? sprintf(
				/* translators: %s is a managed plugin file or theme stylesheet. */
				__( 'Release workflow — %s', 'ran-booster' ),
				$this->boundedReference( $reference, 70 )
			)
			: __( 'Release workflow', 'ran-booster' );
		$key = 'core:release-workflow-' . substr( hash( 'sha256', $providerCode . '|' . $type . '|' . $reference ), 0, 16 );

		return array(
			'detail' => array(
				'key'            => $key,
				'label'          => $detailLabel,
				'value'          => $value,
				'tone'           => $tone,
				'category'       => 'release_workflow',
				'review_summary' => $exact && $status instanceof ReleaseTrackingStatus && null !== $this->workflowProviderStatus( $status ),
			),
			'action' => array(
				'key'           => $key,
				'label'         => $label,
				'type'          => 'link',
				'url'           => $settingsUrl,
				'hidden'        => array(),
				'disabled'      => false,
				'external'      => false,
				'described_by'  => '',
				'screen_reader' => $reference,
			),
		);
	}

	private function localPackage( string $type, string $identifier ): ?object {
		return $this->requestBoundary(
			fn (): object => 'plugin' === $type
				? $this->plugins->boosterPluginFromFile( $identifier )
				: $this->themes->boosterThemeFromStylesheet( $identifier ),
			null
		);
	}

	private function recordMatchesStatus( ?\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus $record, ReleaseTrackingStatus $status ): bool {
		return $record instanceof \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus
			&& $record->recordExact()
			&& hash_equals( $status->providerRepositoryId(), $record->repositoryId() )
			&& hash_equals( $status->type(), $record->packageType() )
			&& hash_equals( $status->identifier(), $record->packageIdentifier() )
			&& $status->sourceRevision() === $record->sourceRevision();
	}

	private function recordMatchesPackageStatus( ?\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus $record, ReleaseTrackingStatus $status ): bool {
		return $record instanceof \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus
			&& $record->recordOccupied()
			&& hash_equals( $this->workflowProviderCode( $status ), $record->providerCode() )
			&& hash_equals( $status->providerRepositoryId(), $record->repositoryId() )
			&& hash_equals( $status->type(), $record->packageType() )
			&& hash_equals( $status->identifier(), $record->packageIdentifier() );
	}

	private function boundedReference( string $reference, int $maximum ): string {
		return strlen( $reference ) <= $maximum
			? $reference
			: substr( $reference, 0, $maximum - 3 ) . '...';
	}

	/** @return array<string,mixed>|null */
	private function workflowView( object $package, string $code, bool $successful, string $previewKey, string $channel, string $stage = '', string $reference = '' ): ?array {
		if ( ! is_callable( array( $package, 'type' ) ) || ! is_callable( array( $package, 'identifier' ) ) || ! is_callable( array( $package, 'sourceRevision' ) ) ) {
			return null;
		}
		return $this->workflowViewFor( $package->type(), $package->identifier(), $package->sourceRevision(), $code, $successful, $previewKey, $channel, $stage, reference: $reference );
	}
	/** @return array<string,mixed>|null */
	private function workflowViewFor( string $type, string $identifier, int $revision, string $code, bool $successful, string $previewKey, string $channel, string $stage = '', string $diagnostic = '', bool $diagnosticAvailable = false, string $reference = '', string $message = '', string $remediation = '' ): ?array {
		$status  = $this->workflowDisplayStatus( $type, $identifier, $revision );
		$package = $this->workflowPackage( $type, $identifier, $revision );
		if ( null === $status || null === $package || ! $this->packageMatchesStatus( $package, $status ) ) {
			return $this->unavailableWorkflowView( __( 'Booster could not confirm this package. Reload its settings and try again.', 'ran-booster' ) );
		}
		$providerCode = (string) $package->getProviderCode();
		if ( null === $this->workflowCapability( $providerCode ) ) {
			return null;
		}
		$provider  = $this->workflowProvider( $providerCode );
		$state     = null === $provider ? null : $this->workflowProviderStatus( $status );
		$anonymous = $this->anonymousWorkflowInspectionAllowed( $package );
		$metadata  = $this->providers->metadata()[ $providerCode ] ?? null;
		$extra     = array(
			'provider_label'        => $metadata?->label ?? $providerCode,
			'documentation_links'   => $state?->documentationLinks() ?? array(),
			'provider_workflow_url' => $state?->providerWorkflowUrl() ?? '',
			'write_guidance'        => $state?->writeGuidance() ?? '',
		);
		$reason    = null === $provider
			? __( 'This provider claims release workflow management but does not implement all required release capabilities. Update or correct the provider plugin; no operation is available.', 'ran-booster' )
			: ( null === $state ? __( 'The provider could not supply local workflow status. Retry after checking the provider plugin.', 'ran-booster' ) : '' );
		if ( '' === $reason && ! $status->eligible() ) {
			$reason = $this->workflowUnavailableReason( $status );
		}
		if ( '' === $reason && ! $this->workflowSourceAllowed( $type, $identifier, $package ) ) {
			$reason = __( 'Releases require a repository used by only one managed package. Review the repository package list.', 'ran-booster' );
		}
		if ( '' === $reason && $state->recordOccupied() && ! $this->recordMatchesPackageStatus( $state, $status ) ) {
			$reason = __( 'A workflow record belongs to a different package. Review the recorded repository state before setup.', 'ran-booster' );
		}
		$credentials = $state?->credentialChoices() ?? array();
		$channel     = in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : $status->channel();
		$preview     = null;
		if ( '' === $reason && '' !== $previewKey ) {
			$preview = $this->requestBoundary( fn () => $provider->workflowPreview( $status, $previewKey ), null );
			if ( null !== $preview && ( $preview->key() !== $previewKey || $preview->providerCode() !== $providerCode || $preview->repositoryId() !== $status->providerRepositoryId() ) ) {
				$preview = null;
			}
		}
		$forms = array( 'inspect' => $this->workflowForm( 'inspect', $status, '', '', $channel, $credentials, $anonymous ) );
		if ( '' !== $reason || null === $forms['inspect'] ) {
			$forms                           = $this->unavailableWorkflowView( $reason, anonymousInspection: $anonymous )['forms'];
			$forms['inspect']['credentials'] = $credentials;
		}
		if ( null !== $preview ) {
			$operation           = 'template_update' === $preview->kind() ? 'update_setup' : 'setup';
			$forms[ $operation ] = $this->workflowForm( $operation, $status, $previewKey, $preview->confirmation(), $preview->channel(), $credentials, $anonymous );
		}
		if ( '' === $reason && $this->recordMatchesPackageStatus( $state, $status ) ) {
			$forms['outcome']        = $this->workflowForm( 'outcome', $status, credentials: $credentials, anonymousInspection: $anonymous );
			$forms['update_inspect'] = $this->workflowForm( 'update_inspect', $status, credentials: $credentials, anonymousInspection: $anonymous );
		}
		foreach ( $forms as &$form ) {
			if ( is_array( $form ) ) {
				$form['provider_label']  = $extra['provider_label'];
				$form['write_guidance']  = $extra['write_guidance'];
				$form['credentials_url'] = add_query_arg(
					array(
						'page' => 'ran-booster',
						'tab'  => $providerCode,
						'view' => 'credentials',
					),
					admin_url( 'admin.php' )
				);
			}
		}
		unset( $form );
		return $extra + array(
			'result_code'            => $code,
			'result_successful'      => $successful,
			'failure_stage'          => $stage,
			'diagnostic_code'        => $diagnostic,
			'diagnostic_available'   => $diagnosticAvailable,
			'correlation_reference'  => $reference,
			'result_message'         => $message,
			'result_remediation'     => $remediation,
			'unavailable'            => '' !== $reason,
			'unavailable_reason'     => $reason,
			'preview'                => null === $preview ? null : $preview->summary() + array(
				'kind'    => $preview->kind(),
				'changes' => $preview->changedPaths(),
			),
			'record'                 => $this->recordMatchesPackageStatus( $state, $status ) ? array( 'pull_request_url' => $state->pullRequestUrl() ) : null,
			'legacy'                 => true === $state?->recordOccupied() && ! $this->recordMatchesPackageStatus( $state, $status ) ? array( 'unsupported' => true ) : null,
			'failure_history'        => $state?->failureHistory() ?? array(),
			'assessment_observation' => null !== $state && '' !== $state->observationKind() ? array(
				'kind'        => $state->observationKind(),
				'recorded_at' => $state->observedAt(),
			) : null,
			'automation_state'       => '' !== $reason ? 'blocked' : ( $this->recordMatchesPackageStatus( $state, $status ) ? 'setup_recorded' : ( null !== $preview ? 'preview' : 'ready' ) ),
			'forms'                  => array_filter( $forms, 'is_array' ),
		);
	}
	/** @return array<string,mixed> */
	private function unavailableWorkflowView( string $reason, string $code = '', bool $successful = false, string $automationState = 'blocked', bool $anonymousInspection = false ): array {
		return array(
			'result_code'        => $code,
			'result_successful'  => $successful,
			'unavailable'        => true,
			'unavailable_reason' => $reason,
			'preview'            => null,
			'record'             => null,
			'legacy'             => null,
			'automation_state'   => $automationState,
			'forms'              => array(
				'inspect' => array(
					'operation'            => 'inspect',
					'action'               => admin_url( 'admin-post.php' ),
					'fields'               => array(),
					'credentials'          => array(),
					'anonymous_inspection' => $anonymousInspection,
					'credentials_url'      => admin_url( 'admin.php?page=ran-booster' ),
					'disabled'             => true,
				),
			),
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
			'missing_update_uri' => __( 'Open package settings for the required Update URI, add it to the package header, then deploy the corrected package.', 'ran-booster' ),
			'mismatched_update_uri' => __( 'This package Update URI must match the configured repository.', 'ran-booster' ),
			'unsupported_provider' => __( 'This repository provider cannot use published-release tracking.', 'ran-booster' ),
			'invalid_repository' => __( 'The saved repository needs attention before a release workflow can be assessed.', 'ran-booster' ),
			'invalid_package_identity' => __( 'The installed package identity must match the configured repository.', 'ran-booster' ),
			'subdirectory_not_supported' => __( 'Published releases require this package at the repository root; continue using Branch for a repository subdirectory.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'This package already has its own release updater, so Booster cannot manage published releases as well.', 'ran-booster' ),
			default => __( 'Resolve Release readiness before assessing a workflow.', 'ran-booster' ),
		};
	}

	/** @return array<string,mixed>|null */
	private function workflowForm(
		string $operation,
		ReleaseTrackingStatus $status,
		string $preview = '',
		string $confirmation = '',
		string $channel = '',
		array $credentials = array(),
		bool $anonymousInspection = false
	): ?array {
		$preflight = '';
		if ( in_array( $operation, array( 'inspect', 'setup' ), true ) ) {
			if ( null === $this->releases || ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
				return null;
			}
			$action = $this->releases->nonceAction( 'assessment_preflight', $status->type(), $status->identifier(), $status->sourceRevision(), $channel );
			if ( '' === $action ) {
				return null;
			}
			$preflight = wp_create_nonce( $action );
		}
		$fields = array(
			'action'                   => 'ran_booster_release_workflow',
			'workflow_operation'       => $operation,
			'expected_provider'        => $this->workflowProviderCode( $status ),
			'expected_repository_id'   => $status->providerRepositoryId(),
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
			'operation'            => $operation,
			'action'               => admin_url( 'admin-post.php' ),
			'fields'               => $fields,
			'confirm'              => $confirmation,
			'credentials'          => $credentials,
			'anonymous_inspection' => $anonymousInspection,
			'credentials_url'      => add_query_arg(
				array(
					'page' => 'ran-booster',
					'tab'  => $this->workflowProviderCode( $status ),
					'view' => 'credentials',
				),
				admin_url( 'admin.php' )
			),
		);
	}

	/** @return list<array{id:string,label:string}> */
	private function workflowCapability( string $providerCode ): ?RepositoryReleaseWorkflowManagement {
		try {
			return $this->providers->requireCapability( $providerCode, RepositoryReleaseWorkflowManagement::class );
		} catch ( Throwable ) {
			return null;
		}
	}

	private function workflowProvider( string $providerCode ): ?RepositoryReleaseWorkflowManagement {
		$provider = $this->workflowCapability( $providerCode );
		return null !== $provider && 1 === $provider::RELEASE_WORKFLOW_API_VERSION && $this->releaseProviderSupported( $providerCode ) ? $provider : null;
	}

	private function releaseProviderSupported( string $providerCode ): bool {
		try {
			$provider = $this->providers->get( $providerCode );
			return $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseMetadata
				&& $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseCandidateListing
				&& $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseInspector
				&& $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseAcquirer
				&& $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
		} catch ( Throwable ) {
			return false;
		}
	}

	private function workflowProviderStatus( ReleaseTrackingStatus $status ): ?\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus {
		$providerCode = $this->workflowProviderCode( $status );
		$provider     = $this->workflowProvider( $providerCode );
		if ( null === $provider ) {
			return null;
		}
		$key = hash( 'sha256', (string) wp_json_encode( array( $providerCode, $status->providerRepositoryId(), $status->type(), $status->identifier(), $status->sourceRevision() ) ) );
		if ( ! array_key_exists( $key, $this->workflowStatuses ) ) {
			$value = $this->requestBoundary( fn () => $provider->workflowStatus( $status ), null );
			if ( null !== $value && ( $value->providerCode() !== $providerCode
				|| $value->repositoryId() !== $status->providerRepositoryId()
				|| ( $value->recordExact() && ( $value->packageType() !== $status->type() || $value->packageIdentifier() !== $status->identifier() || $value->sourceRevision() !== $status->sourceRevision() ) ) ) ) {
				$value = null;
			}
			$this->workflowStatuses[ $key ] = $value;
		}
		return $this->workflowStatuses[ $key ];
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
			$output = ob_get_clean();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured output was escaped by the component renderer.
			echo $output;
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


	private function returnUrl( string $type, string $identifier, bool $settings ): string {
		$page = 'plugin' === $type ? 'ran-booster-plugins' : 'ran-booster-themes';
		$args = array( 'page' => $page );
		if ( $settings && '' !== $identifier ) {
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
	private function requestedResult(): ?array {
		$rawCode        = $_GET[ self::RESULT_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawSuccess     = $_GET[ self::RESULT_SUCCESS_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawType        = $_GET[ self::RESULT_TYPE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawIdentifier  = $_GET[ self::RESULT_PACKAGE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawRevision    = $_GET[ self::RESULT_REVISION_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawProvider    = $_GET[ self::RESULT_PROVIDER_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawRepository  = $_GET[ self::RESULT_REPOSITORY_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawChannel     = $_GET[ self::CHANNEL_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawStage       = $_GET[ self::RESULT_STAGE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawDiagnostic  = $_GET[ self::RESULT_DIAGNOSTIC_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawAvailable   = $_GET[ self::RESULT_DIAGNOSTIC_AVAILABLE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawReference   = $_GET[ self::RESULT_REFERENCE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawMessage     = $_GET[ self::RESULT_MESSAGE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawRemediation = $_GET[ self::RESULT_REMEDIATION_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawNonce       = $_GET[ self::RESULT_NONCE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification value for this PRG result.
		if ( ! is_string( $rawCode ) || ! is_string( $rawSuccess ) || ! is_string( $rawType )
			|| ! is_string( $rawProvider ) || ! is_string( $rawRepository )
			|| ! is_string( $rawIdentifier ) || ! is_string( $rawRevision ) || ! is_string( $rawChannel ) || ! is_string( $rawStage ) || ! is_string( $rawDiagnostic ) || ! is_string( $rawAvailable ) || ! is_string( $rawReference ) || ! is_string( $rawMessage ) || ! is_string( $rawRemediation ) || ! is_string( $rawNonce ) ) {
			return null;
		}

		$code        = wp_unslash( $rawCode );
		$success     = wp_unslash( $rawSuccess );
		$type        = wp_unslash( $rawType );
		$identifier  = wp_unslash( $rawIdentifier );
		$revision    = wp_unslash( $rawRevision );
		$provider    = wp_unslash( $rawProvider );
		$repository  = wp_unslash( $rawRepository );
		$channel     = wp_unslash( $rawChannel );
		$stage       = wp_unslash( $rawStage );
		$diagnostic  = wp_unslash( $rawDiagnostic );
		$available   = wp_unslash( $rawAvailable );
		$reference   = wp_unslash( $rawReference );
		$message     = wp_unslash( $rawMessage );
		$remediation = wp_unslash( $rawRemediation );
		$nonce       = wp_unslash( $rawNonce );
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

	private function resultNonceAction( string $code, bool $successful, string $type, string $identifier, int $sourceRevision, string $channel, string $stage = '', string $diagnostic = '', bool $diagnosticAvailable = false, string $reference = '', string $providerCode = '', string $repositoryId = '', string $message = '', string $remediation = '' ): string {
		$payload = wp_json_encode( array( $code, $successful, $type, $identifier, $sourceRevision, $channel, $stage, $diagnostic, $diagnosticAvailable, $reference, $providerCode, $repositoryId, $message, $remediation ) );

		return self::RESULT_NONCE_ACTION . hash( 'sha256', is_string( $payload ) ? $payload : '' );
	}

	private function resultDisplayText( mixed $value ): string {
		return is_string( $value ) && strlen( $value ) <= 512 && 0 === preg_match( '/[<>\x00-\x1F\x7F]/', $value ) ? $value : '';
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
