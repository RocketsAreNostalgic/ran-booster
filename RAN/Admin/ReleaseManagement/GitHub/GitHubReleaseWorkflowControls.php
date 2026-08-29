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
use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Throwable;

/** @internal GitHub-specific release workflow routes and presentation. */
final class GitHubReleaseWorkflowControls {
	private const RESULT_QUERY_KEY                      = 'ran_booster_github_release_workflow_result';
	private const RESULT_SUCCESS_QUERY_KEY              = 'ran_booster_github_release_workflow_success';
	private const RESULT_TYPE_QUERY_KEY                 = 'ran_booster_github_release_workflow_type';
	private const RESULT_PACKAGE_QUERY_KEY              = 'ran_booster_github_release_workflow_package';
	private const RESULT_REVISION_QUERY_KEY             = 'ran_booster_github_release_workflow_source_revision';
	private const RESULT_NONCE_QUERY_KEY                = 'ran_booster_github_release_workflow_result_nonce';
	private const RESULT_STAGE_QUERY_KEY                = 'ran_booster_github_release_workflow_failure_stage';
	private const RESULT_DIAGNOSTIC_QUERY_KEY           = 'ran_booster_github_release_workflow_diagnostic';
	private const RESULT_DIAGNOSTIC_AVAILABLE_QUERY_KEY = 'ran_booster_github_release_workflow_diagnostic_available';
	private const RESULT_REFERENCE_QUERY_KEY            = 'ran_booster_github_release_workflow_reference';
	private const FAILURE_DIAGNOSTIC_CODES              = array( 'malformed_request', 'permissions_unavailable', 'package_source_changed', 'nonce_expired', 'credential_authorisation_unavailable', 'preflight_contract_unavailable', 'provider_unavailable', 'no_releases', 'invalid_release', 'release_identity_mismatch', 'release_incompatible', 'release_version_mismatch', 'package_header_missing', 'package_header_invalid', 'package_archive_unreadable', 'package_zip_extension_unavailable', 'package_archive_size_invalid', 'package_archive_too_large', 'package_archive_path_unsafe', 'package_archive_path_duplicate', 'package_archive_root_invalid', 'package_archive_entry_duplicate', 'package_archive_entry_limit', 'release_version_invalid', 'package_update_uri_missing', 'package_update_uri_invalid', 'package_compatibility_missing', 'package_compatibility_invalid', 'package_header_ambiguous', 'release_automation_detected', 'repository_snapshot_unavailable', 'template_pack_unavailable', 'preview_storage_unavailable', 'repository_mutation_unverified', 'local_persistence_unavailable', 'unexpected_runtime_failure' );
	private const RESULT_NONCE_ACTION                   = 'ran-booster-github-release-workflow-result-';
	private const PREVIEW_QUERY_KEY                     = 'ran_booster_github_release_workflow_preview';
	private const CHANNEL_QUERY_KEY                     = 'ran_booster_github_release_workflow_channel';

	private readonly ReleaseTrackingOperations $tracking;
	private readonly WorkflowApplicationCoordinator $applications;
	private readonly SetupRecordStore $workflowRecords;
	private readonly GitHubReleaseWorkflowDisplay $display;

	public function __construct(
		private readonly ReleaseTrackingFacade $releases,
		private readonly PluginRepository $plugins,
		private readonly ThemeRepository $themes,
		private readonly ProviderCredentialStore $credentials
	) {
		$this->tracking        = new ReleaseTrackingOperations( $releases );
		$this->workflowRecords = new SetupRecordStore();
		$this->applications    = new WorkflowApplicationCoordinator( $releases, new GitHubRepositoryClient(), new TemplatePackRepositoryClient(), new SourceReadyAssessor(), $this->workflowRecords );
		$this->display         = new GitHubReleaseWorkflowDisplay();
	}

	public function register(): void {
		add_filter( 'ran_booster_admin_package_source_choices', array( $this, 'keepReleaseSettingsDiscoverable' ), 20, 5 );
		add_action( 'ran_booster_admin_package_release_readiness_actions', array( $this, 'renderPackageReleaseAutomationLink' ), 20, 2 );
		add_filter( 'ran_booster_provider_repository_rows', array( $this, 'enrichRepositoryRows' ), 20, 4 );
		add_action( 'ran_booster_admin_repository_release_sections', array( $this, 'renderRepositoryReleaseSections' ), 20, 2 );
		add_action( 'admin_post_ran_booster_github_release_workflow_inspect', array( $this, 'handleWorkflowInspect' ) );
		add_action( 'admin_post_ran_booster_github_release_workflow_setup', array( $this, 'handleWorkflowSetup' ) );
		add_action( 'admin_post_ran_booster_github_release_workflow_outcome', array( $this, 'handleWorkflowOutcome' ) );
		add_action( 'admin_post_ran_booster_github_release_workflow_update_inspect', array( $this, 'handleWorkflowUpdateInspect' ) );
		add_action( 'admin_post_ran_booster_github_release_workflow_update_setup', array( $this, 'handleWorkflowUpdateSetup' ) );
	}

	/**
	 * Keep GitHub's release-automation explanation reachable when Core disables the release transition.
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
			|| ! is_callable( array( $package, 'providerCode' ) ) || 'gh' !== (string) $package->providerCode() ) {
			return $choices;
		}

		$choices['release_asset']['disabled'] = false;
		return $choices;
	}

	/**
	 * Add local GitHub release-automation status and navigation to managed repository rows.
	 *
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $repositoryProjections
	 * @return array<string, array<string, mixed>>
	 */
	public function enrichRepositoryRows( array $rows, string $providerCode, array $repositoryProjections, string $returnUrl ): array {
		unset( $repositoryProjections, $returnUrl );
		if ( 'gh' !== $providerCode ) {
			return $rows;
		}

		foreach ( $rows as &$row ) {
			if ( ! is_array( $row ) || true === ( $row['historical'] ?? false ) ) {
				continue;
			}
			$summaries = is_array( $row['package_summaries'] ?? null )
				? array_values( array_filter( $row['package_summaries'], 'is_array' ) )
				: array();
			$multiple  = 1 < count( $summaries );
			foreach ( $summaries as $summary ) {
				$projection = $this->repositoryReleaseAutomationProjection( $row, $summary, $multiple );
				if ( null === $projection ) {
					continue;
				}
				$row['details'][]                               = $projection['detail'];
				$row['actions'][ $projection['action']['key'] ] = $projection['action'];
			}
		}
		unset( $row );

		return $rows;
	}

	public function renderPackageReleaseAutomationLink( object $package, ReleaseTrackingStatus $status ): void {
		if ( ! is_callable( array( $package, 'providerCode' ) ) || 'gh' !== (string) $package->providerCode()
			|| ! is_callable( array( $package, 'type' ) ) || ! is_callable( array( $package, 'identifier' ) )
			|| ! is_callable( array( $package, 'sourceRevision' ) ) ) {
			return;
		}
		if ( ! hash_equals( $status->type(), (string) $package->type() )
			|| ! hash_equals( $status->identifier(), (string) $package->identifier() )
			|| $status->sourceRevision() !== (int) $package->sourceRevision() ) {
			return;
		}

		echo '<a href="' . esc_url( $this->repositoryReleaseUrl( $status->providerRepositoryId() ) ) . '">'
			. esc_html__( 'Manage release workflow', 'ran-booster' ) . '</a>';
	}

	/** @param array<string,mixed> $row */
	public function renderRepositoryReleaseSections( array $row, string $returnUrl ): void {
		unset( $returnUrl );
		if ( 'gh' !== ( $row['provider_code'] ?? null ) || true === ( $row['historical'] ?? false ) ) {
			return;
		}
		$repositoryId                 = is_string( $row['repository_id'] ?? null ) ? $row['repository_id'] : '';
		$repository                   = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$existingAutomationUrl        = $this->githubActionsUrl( $repository );
		$summaries                    = is_array( $row['package_summaries'] ?? null ) ? array_values( array_filter( $row['package_summaries'], 'is_array' ) ) : array();
		$packagesForReleaseAutomation = array();
		$exactPackageRelationships    = 0;
		$record                       = $this->requestBoundary( fn (): ?array => $this->workflowRecords->find( $repositoryId ), null );
		$recordOccupied               = $this->requestBoundary( fn (): bool => $this->workflowRecords->occupied( $repositoryId ), true );
		$workflowOwner                = '';
		$workflowReadyToAssess        = false;
		$exactReleaseRelationships    = 0;
		$workingReleasePackages       = 0;
		$exactPackageStatuses         = 0;
		$allExactPackagesEligible     = true;
		$releaseTrackingPackages      = 0;
		$packageReadiness             = array();
		$automationObservationKinds   = array();
		$workflowResultNotice         = '';
		$workflowUnavailableNotice    = '';
		$result                       = $this->requestedResult();
		foreach ( $summaries as $summary ) {
			$type          = is_string( $summary['type'] ?? null ) ? $summary['type'] : '';
			$identifier    = is_string( $summary['identifier'] ?? null ) ? $summary['identifier'] : '';
			$summarySource = is_string( $summary['source'] ?? null ) ? $summary['source'] : '';
			$revision      = is_int( $summary['source_revision'] ?? null ) ? $summary['source_revision'] : 0;
			$package       = $this->localPackage( $type, $identifier );
			if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || '' === $identifier
				|| ! in_array( $summarySource, array( 'branch', 'release_asset' ), true ) || 1 > $revision || null === $package
				|| ! is_callable( array( $package, 'getProviderRepositoryId' ) ) || ! is_callable( array( $package, 'getRepository' ) )
				|| ! is_string( $package->getProviderRepositoryId() ) || ! hash_equals( $repositoryId, $package->getProviderRepositoryId() )
				|| ! hash_equals( $repository, (string) $package->getRepository() ) || $revision !== $package->getSourceRevision() ) {
				continue;
			}
			++$exactPackageRelationships;
			if ( 'release_asset' === $summarySource ) {
				++$exactReleaseRelationships;
			}
			$status      = $this->requestBoundary( fn (): ?ReleaseTrackingStatus => $this->workflowDisplayStatus( $type, $identifier, $revision ), null );
			$exactStatus = $status instanceof ReleaseTrackingStatus
				&& $this->statusMatchesSummary( $status, $type, $identifier, $summarySource, $revision, $repositoryId );
			if ( $exactStatus ) {
				++$exactPackageStatuses;
				if ( ! $status->eligible() ) {
					$allExactPackagesEligible = false;
				}
				if ( 'release_asset' === $summarySource ) {
					++$releaseTrackingPackages;
				}
				if ( $this->recordMatchesStatus( $record, $status, $repository ) ) {
					$workflowOwner = ucfirst( $type ) . ' · ' . ( is_string( $summary['display_name'] ?? null ) ? $summary['display_name'] : $identifier );
				} elseif ( null === $record && $status->eligible() && 'branch' === $status->source() ) {
					$workflowReadyToAssess = true;
				}
				if ( 'release_asset' === $summarySource && $this->publishedReleasesWorking( $status ) ) {
					++$workingReleasePackages;
				}
				$packageReadiness[] = array(
					'name'         => is_string( $summary['display_name'] ?? null ) ? $summary['display_name'] : $identifier,
					'type'         => $type,
					'eligible'     => $status->eligible(),
					'message'      => $this->repositoryPackageReadinessMessage( $status ),
					'tracking'     => 'release_asset' === $summarySource,
					'channel'      => $status->channel(),
					'settings_url' => is_string( $summary['settings_url'] ?? null ) ? $summary['settings_url'] : '',
				);
			} else {
				$allExactPackagesEligible = false;
				$packageReadiness[]       = array(
					'name'         => is_string( $summary['display_name'] ?? null ) ? $summary['display_name'] : $identifier,
					'type'         => $type,
					'eligible'     => false,
					'message'      => __( 'Booster could not confirm this package’s exact local release status.', 'ran-booster' ),
					'tracking'     => false,
					'channel'      => 'stable',
					'settings_url' => is_string( $summary['settings_url'] ?? null ) ? $summary['settings_url'] : '',
				);
			}
			$matchingResult = is_array( $result ) && hash_equals( $type, (string) ( $result['type'] ?? '' ) )
				&& hash_equals( $identifier, (string) ( $result['identifier'] ?? '' ) )
				&& $revision === (int) ( $result['source_revision'] ?? 0 ) ? $result : null;
			$view           = $exactStatus
				? $this->requestBoundary(
					fn (): ?array => $this->workflowViewFor(
						$type,
						$identifier,
						$revision,
						is_array( $matchingResult ) ? (string) $matchingResult['code'] : '',
						true === ( $matchingResult['successful'] ?? false ),
						$this->requestedPreviewKey(),
						is_array( $matchingResult ) ? (string) $matchingResult['channel'] : '',
						is_array( $matchingResult ) ? (string) $matchingResult['failure_stage'] : '',
						is_array( $matchingResult ) ? (string) $matchingResult['diagnostic_code'] : '',
						true === ( $matchingResult['diagnostic_available'] ?? false ),
						is_array( $matchingResult ) ? (string) $matchingResult['correlation_reference'] : ''
					),
					$this->unavailableWorkflowView( __( 'Booster could not read the local release-automation status for this package.', 'ran-booster' ) )
				)
				: $this->unavailableWorkflowView( __( 'Booster could not confirm that this release status belongs to the exact saved package and source.', 'ran-booster' ) );
			if ( ! is_array( $view ) ) {
				continue;
			}
			if ( '' === $workflowUnavailableNotice && true === ( $view['unavailable'] ?? false ) ) {
				$workflowUnavailableNotice = $this->display->stateNotice( $view );
			}
			$observation                                    = is_array( $view['assessment_observation'] ?? null ) ? $view['assessment_observation'] : null;
			$observationKind                                = is_array( $observation ) && is_string( $observation['kind'] ?? null )
				? $observation['kind']
				: 'unassessed';
			$automationObservationKinds[ $observationKind ] = true;
			if ( is_array( $matchingResult ) ) {
				$resultObservationKind = $this->observationKindForResult( $matchingResult['code'] );
				if ( '' === $resultObservationKind || ! is_array( $observation ) || ! hash_equals( $resultObservationKind, (string) ( $observation['kind'] ?? '' ) ) ) {
					$workflowResultNotice = $this->display->resultNotice( $view );
				}
			}
			$packagesForReleaseAutomation[] = array(
				'name'           => is_string( $summary['display_name'] ?? null ) ? $summary['display_name'] : $identifier,
				'settings_url'   => is_string( $summary['settings_url'] ?? null ) ? $summary['settings_url'] : '',
				'summary'        => ucfirst( $type ) . ' · ' . ( 'release_asset' === $summarySource ? __( 'Releases', 'ran-booster' ) : __( 'Branch', 'ran-booster' ) ),
				'needs_settings' => true === ( $view['unavailable'] ?? false ),
				'observation'    => $observationKind,
				'view'           => $view,
			);
		}
		$automationObservationKind = 1 < count( $automationObservationKinds )
			? 'mixed_observations'
			: (string) array_key_first( $automationObservationKinds );
		$packageReady              = 0 < $exactPackageRelationships
			&& $exactPackageRelationships === $exactPackageStatuses
			&& $allExactPackagesEligible;
		$publishedReleasesWorking  = 0 < $exactReleaseRelationships
			&& $exactReleaseRelationships === $workingReleasePackages;
		$blockedPackageReadiness   = array_values(
			array_filter(
				$packageReadiness,
				static fn( array $packageFact ): bool => ! $packageFact['eligible']
			)
		);
		$releaseBlocker            = 0 === count( $blockedPackageReadiness )
			? ''
			: ( 1 === count( $blockedPackageReadiness )
				? $blockedPackageReadiness[0]['message']
				: __( 'One or more packages need attention before using published releases.', 'ran-booster' ) );
		$singlePackageSettings     = 1 === $exactPackageRelationships && 1 === count( $packageReadiness )
			? $packageReadiness[0]
			: null;
		$automationState           = $this->repositoryReleaseAutomationState(
			$workflowOwner,
			$workflowReadyToAssess,
			$publishedReleasesWorking,
			$recordOccupied,
			$automationObservationKind
		);
		$automationNotice          = '' !== $workflowUnavailableNotice
			? $workflowUnavailableNotice
			: '<div class="notice ' . esc_attr( $automationState['notice_tone'] ) . ' inline"><p>' . esc_html( $automationState['message'] ) . '</p>'
				. ( 'existing_automation_detected' === $automationObservationKind && '' !== $existingAutomationUrl
					? '<p><a href="' . esc_url( $existingAutomationUrl ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Review existing workflow on GitHub', 'ran-booster' ) . '</a></p>'
					: '' )
				. '</div>';
		?>
		<section class="ran-booster-settings-section ran-booster-repository-release-section" aria-labelledby="ran-booster-repository-release-heading">
			<header class="ran-booster-settings-section__header ran-booster-repository-release-section__header">
				<h3 id="ran-booster-repository-release-heading"><?php echo esc_html__( 'Release publishing', 'ran-booster' ); ?></h3>
				<?php if ( is_array( $singlePackageSettings ) && '' !== $singlePackageSettings['settings_url'] ) { ?>
					<a href="<?php echo esc_url( $singlePackageSettings['settings_url'] ); ?>"><?php echo esc_html( 'plugin' === $singlePackageSettings['type'] ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ) ); ?></a>
				<?php } ?>
			</header>
			<div class="ran-booster-settings-section__body">
				<?php if ( '' !== $releaseBlocker ) { ?>
					<div class="notice notice-warning inline ran-booster-repository-release-section__notice"><p><?php echo esc_html( $releaseBlocker ); ?></p></div>
				<?php } ?>
				<?php $this->renderRepositoryReleaseLifecycle( $exactPackageRelationships, $packageReady, $releaseTrackingPackages, $workflowOwner, $workflowReadyToAssess, $publishedReleasesWorking, $automationObservationKind ); ?>
				<?php $this->renderRepositoryReadiness( $repository, $exactPackageRelationships, $packageReadiness ); ?>
				<section class="ran-booster-readiness-panel ran-booster-repository-release-automation" aria-labelledby="ran-booster-repository-release-automation-heading">
					<header class="ran-booster-readiness-panel__top ran-booster-repository-release-automation__header"><div>
						<div class="ran-booster-release-automation-heading"><h4 id="ran-booster-repository-release-automation-heading"><?php echo esc_html__( 'Release workflow', 'ran-booster' ); ?></h4><span class="ran-booster-badge <?php echo esc_attr( $automationState['tone'] ); ?>"><?php echo esc_html( $automationState['label'] ); ?></span></div>
						<p class="description"><?php echo esc_html( $automationState['provenance'] ); ?></p>
					</div>
					</header>
					<div class="ran-booster-repository-release-automation__notices">
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display projection escapes complete output. ?><?php echo $automationNotice; ?>
						<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display projection escapes complete output. ?><?php echo $workflowResultNotice; ?>
					</div>
					<div class="ran-booster-repository-release-automation__body">
		<?php if ( array() === $packagesForReleaseAutomation ) { ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html__( 'No exact package release-automation authority is available for this repository.', 'ran-booster' ); ?></p></div>
			<?php
		} else {
			?>
			<?php $multiplePackages = 1 < count( $packagesForReleaseAutomation ); ?>
			<?php foreach ( $packagesForReleaseAutomation as $packageForReleaseAutomation ) { ?>
				<div class="ran-booster-readiness-panel ran-booster-repository-release-package">
					<?php if ( $multiplePackages ) { ?>
						<div class="ran-booster-readiness-panel__top"><div>
							<p><?php echo esc_html( $packageForReleaseAutomation['summary'] . ' · ' . $packageForReleaseAutomation['name'] ); ?></p>
							<?php $this->renderPackageAutomationObservation( $packageForReleaseAutomation['observation'] ); ?>
						</div>
						<?php if ( '' !== $packageForReleaseAutomation['settings_url'] ) { ?>
							<a href="<?php echo esc_url( $packageForReleaseAutomation['settings_url'] ); ?>"><?php echo esc_html__( 'Open package settings', 'ran-booster' ); ?></a>
						<?php } ?></div>
					<?php } ?>
				<div class="ran-booster-repository-release-package__body"><?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Display projection escapes complete output. ?><?php echo $this->display->workflow( $packageForReleaseAutomation['view'], false ); ?></div>
			</div>
			<?php } ?>
		<?php } ?>
					</div>
				</section>
			</div>
		</section>
		<?php
	}

	private function renderRepositoryReleaseLifecycle( int $exactPackageRelationships, bool $packageReady, int $releaseTrackingPackages, string $workflowOwner, bool $workflowReadyToAssess, bool $publishedReleasesWorking, string $observationKind ): void {
		$trackingReady = 0 < $releaseTrackingPackages && $releaseTrackingPackages === $exactPackageRelationships;
		$trackingLabel = 0 < $releaseTrackingPackages
			? sprintf(
				/* translators: 1: packages using Published releases, 2: exact managed package relationships. */
				__( '%1$d of %2$d packages use Published releases.', 'ran-booster' ),
				$releaseTrackingPackages,
				$exactPackageRelationships
			)
			: __( 'No package uses Published releases yet.', 'ran-booster' );
		$automationReady = '' !== $workflowOwner || in_array( $observationKind, array( 'existing_automation_detected', 'booster_setup_verified' ), true );
		$automationLabel = '' !== $workflowOwner
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
				'label'   => __( 'Prepare packages', 'ran-booster' ),
				'message' => $packageReady ? __( 'Every exact package is ready for published-release tracking.', 'ran-booster' ) : __( 'One or more packages need attention before using published releases.', 'ran-booster' ),
				'state'   => $packageReady ? 'is-ok' : 'is-warning',
			),
			array(
				'label'   => __( 'Track published releases', 'ran-booster' ),
				'message' => $trackingLabel,
				'state'   => $trackingReady ? 'is-ok' : 'is-pending',
			),
			array(
				'label'   => __( 'Automate releases (optional)', 'ran-booster' ),
				'message' => $automationLabel,
				'state'   => $automationReady ? 'is-ok' : ( $publishedReleasesWorking ? 'is-pending' : 'is-warning' ),
			),
		);
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
	private function renderRepositoryReadiness( string $repository, int $exactPackageRelationships, array $packageReadiness ): void {
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
					<li class="ran-booster-readiness-item is-ok">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php echo esc_html__( 'Provider capability', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html__( 'GitHub supports published releases.', 'ran-booster' ); ?></span>
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
	private function repositoryReleaseAutomationState( string $workflowOwner, bool $workflowReadyToAssess, bool $publishedReleasesWorking, bool $recordOccupied, string $observationKind ): array {
		$state   = __( 'Needs attention', 'ran-booster' );
		$tone    = 'ran-booster-badge--error';
		$message = __( 'Release workflow status is unavailable.', 'ran-booster' );
		$notice  = 'notice-warning';
		$origin  = __( 'Booster setup: Not recorded.', 'ran-booster' );
		if ( '' !== $workflowOwner ) {
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

	private function githubActionsUrl( string $repository ): string {
		if ( 1 !== preg_match( '#\A[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z#D', $repository ) ) {
			return '';
		}
		$parts = explode( '/', $repository, 2 );
		return 'https://github.com/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] ) . '/actions';
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
	public function processWorkflowRequest( string $operation, #[\SensitiveParameter] array $request ): string {
		$type             = $this->workflowType( $request );
		$identifier       = $this->workflowIdentifier( $request );
		$revision         = $this->workflowRevision( $request );
		$preview          = $this->workflowPreview( $request );
		$nonce            = is_string( $request['_wpnonce'] ?? null ) ? wp_unslash( $request['_wpnonce'] ) : '';
		$channel          = 'inspect' === $operation ? $this->releaseChannelFrom( $request ) : '';
		$outcome          = $this->workflowResult( $type, $identifier, 'workflow_invalid_request', false );
		$operations       = array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' );
		$package          = null;
		$status           = null;
		$observationSaved = false;

		if ( ! in_array( $operation, $operations, true ) || '' === $type || '' === $identifier || $revision < 1
			|| null === $preview || '' === $nonce || ( 'inspect' === $operation && '' === $channel ) ) {
			$outcome = $this->workflowResult( $type, $identifier, 'workflow_invalid_request', false, '', 'request_validation', 'malformed_request' );
		} elseif ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'plugin' === $type ? 'update_plugins' : 'update_themes' ) ) {
			$outcome = $this->workflowResult( $type, $identifier, 'workflow_invalid_request', false, $preview, 'request_validation', 'permissions_unavailable' );
		} else {
			$package = $this->bundledGitHubPackage( $type, $identifier, $revision );
			if ( ! is_object( $package ) ) {
				$outcome = $this->workflowResult( $type, $identifier, 'workflow_invalid_request', false, $preview, 'request_validation', 'package_source_changed' );
			} elseif ( null === $this->releases || null === $this->applications ) {
					$outcome = $this->workflowResult( $type, $identifier, 'workflow_remote_unavailable', false );
			} else {
				$status = $this->requestBoundary( fn (): ?ReleaseTrackingStatus => $this->workflowStatus( $type, $identifier, $revision ), null );
				if ( ! $status instanceof ReleaseTrackingStatus || ! $this->packageMatchesStatus( $package, $status ) ) {
					$outcome = $this->workflowResult( $type, $identifier, 'workflow_invalid_request', false, $preview, 'request_validation', 'package_source_changed' );
				} elseif ( 1 !== wp_verify_nonce( $nonce, $this->workflowNonceAction( $operation, $status, $preview ) ) ) {
					$outcome = $this->workflowResult( $type, $identifier, 'workflow_invalid_request', false, $preview, 'request_validation', 'nonce_expired' );
				} else {
					// Saved credential secrets are deliberately unread until local authority is proven.
					$credentialId       = is_string( $request['booster_credential_id'] ?? null ) ? wp_unslash( $request['booster_credential_id'] ) : '';
					$write              = in_array( $operation, array( 'setup', 'update_setup' ), true );
					$credentialRequired = $write || ! $this->anonymousWorkflowInspectionAllowed( $package );
					$token              = $this->credentialToken( $credentialId, $credentialRequired );
					$confirmation       = is_string( $request['confirm_repository'] ?? null ) ? wp_unslash( $request['confirm_repository'] ) : '';
					$reference          = $this->failureReference();
					$retry              = $this->workflowResult( $type, $identifier, 'workflow_remote_unavailable', false, $preview, 'unexpected', 'unexpected_runtime_failure', false, $reference );
					if ( ( $credentialRequired || '' !== $credentialId ) && '' === $token ) {
						$outcome = $this->workflowResult( $type, $identifier, 'workflow_unauthorised', false, $preview, 'credential_authorisation' );
					} else {
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
							$retry,
							array(
								'operation'      => $operation,
								'outcome_code'   => $retry['code'],
								'provider'       => 'gh',
								'step'           => 'unexpected',
								'package_slug'   => $status->identifier(),
								'correlation_id' => $reference,
							)
						);
					}
				}
			}
		}
		if ( $status instanceof ReleaseTrackingStatus ) {
			$observationSaved = $this->preserveWorkflowObservation( $status, $outcome );
		}
		if ( 'request_validation' === $outcome['failure_stage'] && ! $outcome['successful'] ) {
			$outcome = $this->preserveRequestFailure( $operation, $outcome );
		} elseif ( $status instanceof ReleaseTrackingStatus && ! $outcome['successful']
			&& ! ( $observationSaved && 'workflow_release_automation_conflict' === $outcome['code'] )
			&& is_string( $outcome['failure_stage'] ?? null )
			&& in_array( $outcome['failure_stage'], array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) ) {
			$outcome = $this->preserveWorkflowFailure( $operation, $status, $outcome );
		}

		$args = $this->resultQueryArguments( $outcome, $channel, $revision );
		if ( '' !== $outcome['preview_key'] ) {
			$args[ self::PREVIEW_QUERY_KEY ] = $outcome['preview_key'];
		}
		$repositoryId = is_object( $package ) && is_callable( array( $package, 'getProviderRepositoryId' ) )
			? $package->getProviderRepositoryId() : '';
		if ( is_string( $repositoryId ) && '' !== $repositoryId ) {
			return add_query_arg( $args, $this->repositoryReleaseUrl( $repositoryId ) ) . '#ran-booster-repository-release-workflows';
		}

		$args['source_view']               = 'release_asset';
		$args['ran_booster_open_advanced'] = '1';

		return add_query_arg( $args, $this->returnUrl( $outcome['type'], $outcome['identifier'], true ) ) . '#ran-booster-advanced-source-settings';
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

	private function credentialToken( string $credentialId, bool $required ): string {
		if ( '' === $credentialId ) {
			return '';
		}

		try {
			$profile = $this->credentials->credentialProfiles()[ $credentialId ] ?? null;
			if ( ! is_array( $profile ) || 'file' !== ( $profile['source'] ?? null ) || ! empty( $profile['immutable'] ) || empty( $profile['configured'] ) ) {
				return '';
			}
			$material = $this->credentials->credentialMaterial( $credentialId );
			$secret   = is_array( $material ) && is_string( $material['secret'] ?? null ) ? trim( $material['secret'] ) : '';

			return '' === $secret && $required ? '' : $secret;
		} catch ( Throwable ) {
			return '';
		}
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

	/** @return array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string} */
	private function workflowResult( string $type, string $identifier, string $code, bool $successful, string $preview = '', string $stage = '', string $diagnostic = '', bool $diagnosticAvailable = false, string $reference = '' ): array {
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
		);
	}

	/** @param array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string} $outcome */
	private function preserveWorkflowFailure( string $operation, ReleaseTrackingStatus $status, array $outcome ): array {
		$stage      = $outcome['failure_stage'];
		$diagnostic = $this->failureDiagnosticCode( $outcome['diagnostic_code'] ?? '', $stage );
		$reference  = is_string( $outcome['correlation_reference'] ?? null ) && 1 === preg_match( '/\A[a-f0-9]{32}\z/D', $outcome['correlation_reference'] )
			? $outcome['correlation_reference'] : $this->failureReference();
		$available  = BoosterLogger::log(
			'GitHub release workflow failed',
			array(
				'provider'       => 'gh',
				'operation'      => $operation,
				'outcome_code'   => $outcome['code'],
				'diagnostic_id'  => $diagnostic,
				'step'           => $stage,
				'package_slug'   => $status->identifier(),
				'correlation_id' => $reference,
			)
		);
		$failure    = array(
			'operation'             => $operation,
			'outcome_code'          => $outcome['code'],
			'failure_stage'         => $stage,
			'package_type'          => $status->type(),
			'package_identifier'    => $status->identifier(),
			'source_revision'       => $status->sourceRevision(),
			'repository_id'         => $status->providerRepositoryId(),
			'diagnostic_code'       => $diagnostic,
			'diagnostic_available'  => $available,
			'correlation_reference' => $reference,
			'recorded_at'           => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
		);
		$this->workflowRecords->recordFailure( $failure );
		$outcome['failure_stage']         = $stage;
		$outcome['diagnostic_code']       = $diagnostic;
		$outcome['diagnostic_available']  = $available;
		$outcome['correlation_reference'] = $available ? $reference : '';
		return $outcome;
	}

	/** @param array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string} $outcome */
	private function preserveWorkflowObservation( ReleaseTrackingStatus $status, array $outcome ): bool {
		$kind = match ( $outcome['code'] ) {
			'workflow_release_automation_conflict' => 'existing_automation_detected',
			'workflow_release_automation_present'  => 'booster_setup_verified',
			'workflow_inspected'                   => 'no_recognisable_automation',
			default                                => '',
		};
		if ( '' === $kind ) {
			return false;
		}

		$saved = $this->workflowRecords->saveAssessmentObservation(
			array(
				'kind'               => $kind,
				'repository_id'      => $status->providerRepositoryId(),
				'package_type'       => $status->type(),
				'package_identifier' => $status->identifier(),
				'source_revision'    => $status->sourceRevision(),
				'observed_at'        => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
			)
		);
		if ( $saved ) {
			BoosterLogger::log(
				'GitHub release automation assessment recorded',
				array(
					'provider'     => 'gh',
					'operation'    => 'inspect',
					'outcome_code' => $outcome['code'],
					'package_slug' => $status->identifier(),
					'state'        => $kind,
				)
			);
		}

		return $saved;
	}

	/** @param array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string} $outcome */
	private function preserveRequestFailure( string $operation, array $outcome ): array {
		$diagnostic                       = $this->failureDiagnosticCode( $outcome['diagnostic_code'], 'request_validation' );
		$reference                        = $this->failureReference();
		$available                        = BoosterLogger::log(
			'GitHub release workflow request refused',
			array(
				'provider'       => 'gh',
				'operation'      => in_array( $operation, array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ), true ) ? $operation : 'invalid',
				'outcome_code'   => $outcome['code'],
				'diagnostic_id'  => $diagnostic,
				'step'           => 'request_validation',
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

	private function bundledGitHubPackage( string $type, string $identifier, int $revision ): ?object {
		try {
			$package = 'plugin' === $type
				? $this->plugins->boosterPluginFromFile( $identifier )
				: $this->themes->boosterThemeFromStylesheet( $identifier );

			return is_object( $package ) && is_callable( array( $package, 'getProviderRepositoryId' ) )
				&& $revision === $package->getSourceRevision() && 'gh' === (string) $package->getProviderCode()
				? $package
				: null;
		} catch ( Throwable ) {
			return null;
		}
	}

	private function packageMatchesStatus( object $package, ReleaseTrackingStatus $status ): bool {
		try {
			$repositoryId = $package->getProviderRepositoryId();

			return is_string( $repositoryId ) && hash_equals( $repositoryId, $status->providerRepositoryId() );
		} catch ( Throwable ) {
			return false;
		}
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
		$package    = $this->localPackage( $type, $reference );
		$status     = null;
		$repository = is_string( $row['repository_id'] ?? null ) ? $row['repository_id'] : '';
		$locator    = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$exact      = null !== $package && '' !== $repository
			&& is_callable( array( $package, 'getIdentifier' ) )
			&& is_callable( array( $package, 'getProviderCode' ) )
			&& is_callable( array( $package, 'getProviderRepositoryId' ) )
			&& is_callable( array( $package, 'getRepository' ) )
			&& is_callable( array( $package, 'getSourceRevision' ) )
			&& is_string( $package->getIdentifier() )
			&& hash_equals( $reference, $package->getIdentifier() )
			&& 'gh' === (string) $package->getProviderCode()
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
			$record   = $this->requestBoundary( fn (): ?array => $this->workflowRecords->find( $repository ), null );
			$occupied = $this->requestBoundary( fn (): bool => $this->workflowRecords->occupied( $repository ), true );
			if ( $this->recordMatchesStatus( $record, $status, $locator ) ) {
				$value = __( 'Setup recorded', 'ran-booster' );
				$tone  = 'pending';
			} elseif ( $this->publishedReleasesWorking( $status ) ) {
				$value = __( 'Published releases working', 'ran-booster' );
				$tone  = 'ok';
			} elseif ( ! $occupied && $status->eligible() ) {
				$value = 'branch' === $status->source()
					? __( 'Ready to assess', 'ran-booster' )
					: __( 'Published releases selected', 'ran-booster' );
				$tone  = 'branch' === $status->source() ? 'ok' : 'pending';
			}
		}

		$settingsUrl = $this->repositoryReleaseUrl( $repository );
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
		$key = 'gh:release-automation-' . substr( hash( 'sha256', $type . '|' . $reference ), 0, 16 );

		return array(
			'detail' => array(
				'key'   => $key,
				'label' => $detailLabel,
				'value' => $value,
				'tone'  => $tone,
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

	/** @param array<string, mixed>|null $record */
	private function recordMatchesStatus( ?array $record, ReleaseTrackingStatus $status, string $repository ): bool {
		return is_array( $record )
			&& is_string( $record['repo_id'] ?? null )
			&& hash_equals( $status->providerRepositoryId(), $record['repo_id'] )
			&& is_string( $record['package_type'] ?? null )
			&& hash_equals( $status->type(), $record['package_type'] )
			&& is_string( $record['package_identifier'] ?? null )
			&& hash_equals( $status->identifier(), $record['package_identifier'] )
			&& is_string( $record['repository'] ?? null )
			&& hash_equals( $repository, $record['repository'] )
			&& $status->sourceRevision() === ( $record['source_revision'] ?? null );
	}

	/** @param array<string,int|string>|null $record */
	private function recordMatchesPackageStatus( ?array $record, ReleaseTrackingStatus $status ): bool {
		return is_array( $record )
			&& is_string( $record['repo_id'] ?? null )
			&& hash_equals( $status->providerRepositoryId(), $record['repo_id'] )
			&& is_string( $record['package_type'] ?? null )
			&& hash_equals( $status->type(), $record['package_type'] )
			&& is_string( $record['package_identifier'] ?? null )
			&& hash_equals( $status->identifier(), $record['package_identifier'] )
			&& $status->sourceRevision() === ( $record['source_revision'] ?? null );
	}

	private function boundedReference( string $reference, int $maximum ): string {
		return strlen( $reference ) <= $maximum
			? $reference
			: substr( $reference, 0, $maximum - 3 ) . '...';
	}

	/** @return array<string,mixed>|null */
	private function workflowView( object $package, string $code, bool $successful, string $previewKey, string $channel, string $stage = '', string $reference = '' ): ?array {
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

		return $this->workflowViewFor( $type, $identifier, $revision, $code, $successful, $previewKey, $channel, $stage, $reference );
	}

	/** @return array<string,mixed>|null */
	private function workflowViewFor( string $type, string $identifier, int $revision, string $code, bool $successful, string $previewKey, string $channel, string $stage = '', string $diagnostic = '', bool $diagnosticAvailable = false, string $reference = '' ): ?array {
		$package   = $this->bundledGitHubPackage( $type, $identifier, $revision );
		$anonymous = is_object( $package ) && $this->anonymousWorkflowInspectionAllowed( $package );
		$status    = $this->workflowDisplayStatus( $type, $identifier, $revision );
		if ( null === $status ) {
			return $this->unavailableWorkflowView( __( 'Booster could not confirm the local Published release readiness for this package. Try again after reviewing its settings.', 'ran-booster' ), anonymousInspection: $anonymous );
		}
		if ( ! $status->eligible() ) {
			return $this->unavailableWorkflowView( $this->workflowUnavailableReason( $status ), $code, $successful, 'blocked', $anonymous );
		}
		$channel = in_array( $channel, array( 'stable', 'prerelease' ), true ) ? $channel : $status->channel();
		$preview = '' === $previewKey ? null : $this->applications->preview( $previewKey, $status );
		$record  = $this->workflowRecords->find( $status->providerRepositoryId() );
		if ( null !== $record && ! $this->recordMatchesPackageStatus( $record, $status ) ) {
			return $this->unavailableWorkflowView(
				__( 'A local workflow record is occupied by a different package or revision. Review it before setup.', 'ran-booster' ),
				$code,
				$successful,
				'blocked',
				$anonymous
			);
		}
		$legacy           = null === $record
			? $this->workflowRecords->legacyEvidence( $status->providerRepositoryId(), $status->type(), $status->identifier(), $status->sourceRevision() ) : null;
		$failureHistory   = $this->workflowRecords->failureHistory( $status->providerRepositoryId(), $status->type(), $status->identifier(), $status->sourceRevision() );
		$observation      = $this->workflowRecords->assessmentObservation( $status->providerRepositoryId(), $status->type(), $status->identifier(), $status->sourceRevision() );
		$forms            = array();
		$credentials      = $this->credentialChoices();
		$anonymous        = is_object( $package ) && $this->packageMatchesStatus( $package, $status )
			&& $this->anonymousWorkflowInspectionAllowed( $package );
		$forms['inspect'] = $this->workflowForm( 'inspect', $status, '', '', $channel, $credentials, $anonymous );
		if ( is_array( $forms['inspect'] ) && ( null !== $preview || null !== $record || 'workflow_release_automation_present' === $code ) ) {
			$forms['inspect']['disabled'] = true;
		}
		if ( null !== $preview ) {
			$operation           = 'template_update' === $preview['kind'] ? 'update_setup' : 'setup';
			$forms[ $operation ] = $this->workflowForm(
				$operation,
				$status,
				$previewKey,
				$preview['repository'],
				$preview['preflight_channel'],
				$credentials,
				$anonymous
			);
		}
		if ( null !== $record ) {
			$forms['outcome']        = $this->workflowForm( 'outcome', $status, credentials: $credentials, anonymousInspection: $anonymous );
			$forms['update_inspect'] = $this->workflowForm( 'update_inspect', $status, credentials: $credentials, anonymousInspection: $anonymous );
		}

		return array(
			'result_code'            => $code,
			'result_successful'      => $successful,
			'failure_stage'          => $stage,
			'diagnostic_code'        => $diagnostic,
			'diagnostic_available'   => $diagnosticAvailable,
			'correlation_reference'  => $reference,
			'preview'                => $preview,
			'record'                 => $record,
			'legacy'                 => $legacy,
			'failure_history'        => $failureHistory,
			'assessment_observation' => $observation,
			'automation_state'       => null !== $record ? 'setup_recorded' : ( null !== $legacy ? 'blocked' : ( null !== $preview ? 'preview' : ( str_starts_with( $code, 'workflow_' ) && ! $successful ? 'needs_attention' : 'ready' ) ) ),
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
					'credentials'          => $this->credentialChoices(),
					'anonymous_inspection' => $anonymousInspection,
					'credentials_url'      => admin_url( 'admin.php?page=ran-booster&tab=gh&view=credentials' ),
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
			'operation'            => $operation,
			'action'               => admin_url( 'admin-post.php' ),
			'fields'               => $fields,
			'confirm'              => $confirmation,
			'credentials'          => $credentials,
			'anonymous_inspection' => $anonymousInspection,
			'credentials_url'      => admin_url( 'admin.php?page=ran-booster&tab=gh&view=credentials' ),
		);
	}

	/** @return list<array{id:string,label:string}> */
	private function credentialChoices(): array {
		try {
			$profiles = $this->credentials->credentialProfiles();
		} catch ( Throwable ) {
			return array();
		}
		$choices = array();
		foreach ( $profiles as $profile ) {
			if ( ! is_array( $profile ) || 'file' !== ( $profile['source'] ?? null ) || ! empty( $profile['immutable'] )
				|| empty( $profile['configured'] ) || ! is_string( $profile['id'] ?? null ) || ! is_string( $profile['label'] ?? null ) || ! is_string( $profile['kind'] ?? null ) ) {
				continue;
			}
			$choices[] = array(
				'id'    => $profile['id'],
				'label' => $profile['label'] . ' (' . $profile['kind'] . ')',
			);
		}

		return $choices;
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
				BoosterLogger::logException( 'GitHub release workflow request failed', $exception, $exceptionContext );
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

	private function repositoryReleaseUrl( string $repositoryId ): string {
		return add_query_arg(
			array(
				'page'            => 'ran-booster',
				'tab'             => 'gh',
				'panel'           => 'repositories',
				'repository'      => $repositoryId,
				'repository_view' => 'releases',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @param array{type:string,identifier:string,code:string,successful:bool,preview_key:string,failure_stage:string,diagnostic_code:string,diagnostic_available?:bool,correlation_reference:string} $outcome
	 * @return array<string, string>
	 */
	private function resultQueryArguments( array $outcome, string $channel = '', int $sourceRevision = 0 ): array {
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
		$args                                 = array(
			self::RESULT_QUERY_KEY                      => $code,
			self::RESULT_SUCCESS_QUERY_KEY              => $successful ? '1' : '0',
			self::RESULT_TYPE_QUERY_KEY                 => $type,
			self::RESULT_PACKAGE_QUERY_KEY              => $identifier,
			self::RESULT_REVISION_QUERY_KEY             => (string) max( 0, $sourceRevision ),
			self::RESULT_STAGE_QUERY_KEY                => $stage,
			self::RESULT_DIAGNOSTIC_QUERY_KEY           => $diagnostic,
			self::RESULT_DIAGNOSTIC_AVAILABLE_QUERY_KEY => $diagnosticAvailable ? '1' : '0',
			self::RESULT_REFERENCE_QUERY_KEY            => $reference,
		);
		$args[ self::CHANNEL_QUERY_KEY ]      = $channel;
		$args[ self::RESULT_NONCE_QUERY_KEY ] = wp_create_nonce(
			$this->resultNonceAction( $code, $successful, $type, $identifier, max( 0, $sourceRevision ), $channel, $stage, $diagnostic, $diagnosticAvailable, $reference )
		);

		return $args;
	}

	/** @return array{code:string,successful:bool,type:string,identifier:string,source_revision:int,channel:string,failure_stage:string,diagnostic_code:string,diagnostic_available:bool,correlation_reference:string}|null */
	private function requestedResult(): ?array {
		$rawCode       = $_GET[ self::RESULT_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawSuccess    = $_GET[ self::RESULT_SUCCESS_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawType       = $_GET[ self::RESULT_TYPE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawIdentifier = $_GET[ self::RESULT_PACKAGE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawRevision   = $_GET[ self::RESULT_REVISION_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawChannel    = $_GET[ self::CHANNEL_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawStage      = $_GET[ self::RESULT_STAGE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawDiagnostic = $_GET[ self::RESULT_DIAGNOSTIC_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawAvailable  = $_GET[ self::RESULT_DIAGNOSTIC_AVAILABLE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawReference  = $_GET[ self::RESULT_REFERENCE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified display-only PRG result.
		$rawNonce      = $_GET[ self::RESULT_NONCE_QUERY_KEY ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification value for this PRG result.
		if ( ! is_string( $rawCode ) || ! is_string( $rawSuccess ) || ! is_string( $rawType )
			|| ! is_string( $rawIdentifier ) || ! is_string( $rawRevision ) || ! is_string( $rawChannel ) || ! is_string( $rawStage ) || ! is_string( $rawDiagnostic ) || ! is_string( $rawAvailable ) || ! is_string( $rawReference ) || ! is_string( $rawNonce ) ) {
			return null;
		}

		$code       = wp_unslash( $rawCode );
		$success    = wp_unslash( $rawSuccess );
		$type       = wp_unslash( $rawType );
		$identifier = wp_unslash( $rawIdentifier );
		$revision   = wp_unslash( $rawRevision );
		$channel    = wp_unslash( $rawChannel );
		$stage      = wp_unslash( $rawStage );
		$diagnostic = wp_unslash( $rawDiagnostic );
		$available  = wp_unslash( $rawAvailable );
		$reference  = wp_unslash( $rawReference );
		$nonce      = wp_unslash( $rawNonce );
		if ( $code !== sanitize_key( $code ) || '' === $code || strlen( $code ) > 64
			|| ! in_array( $success, array( '0', '1' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| $identifier !== sanitize_text_field( $identifier ) || strlen( $identifier ) > 255
			|| 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,9})\z/D', $revision )
			|| ! in_array( $channel, array( '', 'stable', 'prerelease' ), true )
			|| ! in_array( $stage, array( '', 'request_validation', 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true )
			|| ! in_array( $diagnostic, array( '', ...self::FAILURE_DIAGNOSTIC_CODES ), true )
			|| ! in_array( $available, array( '0', '1' ), true )
			|| ( '' !== $reference && 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $reference ) ) ) {
			return null;
		}

		$successful          = '1' === $success;
		$diagnosticAvailable = '1' === $available;
		if ( 1 !== wp_verify_nonce( $nonce, $this->resultNonceAction( $code, $successful, $type, $identifier, (int) $revision, $channel, $stage, $diagnostic, $diagnosticAvailable, $reference ) ) ) {
			return null;
		}

		return array(
			'code'                  => $code,
			'successful'            => $successful,
			'type'                  => $type,
			'identifier'            => $identifier,
			'source_revision'       => (int) $revision,
			'channel'               => $channel,
			'failure_stage'         => $stage,
			'diagnostic_code'       => $diagnostic,
			'diagnostic_available'  => $diagnosticAvailable,
			'correlation_reference' => $reference,
		);
	}

	private function resultNonceAction( string $code, bool $successful, string $type, string $identifier, int $sourceRevision, string $channel, string $stage = '', string $diagnostic = '', bool $diagnosticAvailable = false, string $reference = '' ): string {
		$payload = wp_json_encode( array( $code, $successful, $type, $identifier, $sourceRevision, $channel, $stage, $diagnostic, $diagnosticAvailable, $reference ) );

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
