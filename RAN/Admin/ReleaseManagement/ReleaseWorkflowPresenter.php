<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\Logging\BoosterLogger;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use Throwable;

/** @internal GET-side release-workflow projection owner. */
final class ReleaseWorkflowPresenter {
	private readonly ReleaseTrackingOperations $tracking;
	private readonly RepositorySourceGuard $source_guard;
	/** @var array<string,\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus|null> */
	private array $workflow_statuses = array();

	public function __construct(
		private readonly ReleaseTrackingFacade $releases,
		private readonly PluginRepository $plugins,
		private readonly ThemeRepository $themes,
		private readonly ProviderRegistry $providers,
		private readonly ReleaseWorkflowRequestController $requests,
		?RepositorySourceGuard $source_guard = null
	) {
		$this->tracking = new ReleaseTrackingOperations( $releases );

		$this->source_guard = $source_guard ?? new RepositorySourceGuard();
	}


	/**
	 * @param array<string,array<string,mixed>> $choices
	 * @return array<string,array<string,mixed>>
	 */
	public function keep_release_settings_discoverable(
		array $choices,
		string $mode,
		string $type,
		?object $package,
		string $page_url
	): array {

		unset( $type, $page_url );
		if ( 'edit' !== $mode || null === $package || ! isset( $choices['release_asset'] )
			|| ! is_callable( array( $package, 'provider_code' ) ) || ! $this->release_provider_supported( (string) $package->provider_code() ) ) {
			return $choices;
		}

		$choices['release_asset']['disabled'] = false;
		return $choices;
	}

	/**
	 * Add local release-workflow status and navigation to managed repository rows.
	 *
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $repository_projections
	 * @return array<string, array<string, mixed>>
	 */

	public function enrich_repository_rows( array $rows, string $provider_code, array $repository_projections, string $return_url ): array {

		unset( $repository_projections, $return_url );

		if ( null === $this->workflow_provider( $provider_code ) ) {
			return $rows;
		}

		foreach ( $rows as &$row ) {
			if ( ! is_array( $row )
				|| true === ( $row['historical'] ?? false )
				|| 0 < (int) ( $row['package_summaries_omitted'] ?? 0 ) ) {
				continue;
			}
			$row_details     = is_array( $row['details'] ?? null ) ? $row['details'] : array();
			$available_slots = 20 - count( $row_details );
			$summaries       = is_array( $row['package_summaries'] ?? null )
				? array_values( array_filter( $row['package_summaries'], 'is_array' ) )
				: array();
			$multiple        = 1 < count( $summaries );
			foreach ( $summaries as $summary ) {
				$projection = $this->repository_release_automation_projection( $row, $summary, $multiple );
				if ( null === $projection ) {
					continue;
				}
				if ( 0 >= $available_slots ) {
					continue;
				}
				$row['details'][]                               = $projection['detail'];
				$row['actions'][ $projection['action']['key'] ] = $projection['action'];
				--$available_slots;
			}
		}
		unset( $row );

		return $rows;
	}

	private function observation_kind_for_result( string $code ): string {
		return match ( $code ) {
			'workflow_release_automation_conflict' => 'existing_automation_detected',
			'workflow_release_automation_present'  => 'booster_setup_verified',
			'workflow_inspected'                   => 'no_recognisable_automation',
			default                                => '',
		};
	}

	private function published_releases_working( ReleaseTrackingStatus $status ): bool {
		return $status->eligible() && 'release_asset' === $status->source() && '' === $status->failure_code();
	}

	private function status_matches_summary( ReleaseTrackingStatus $status, string $type, string $identifier, string $source, int $revision, string $repository_id ): bool {
		return hash_equals( $type, $status->type() )
			&& hash_equals( $identifier, $status->identifier() )
			&& hash_equals( $source, $status->source() )
			&& $revision === $status->source_revision()
			&& hash_equals( $repository_id, $status->provider_repository_id() );
	}

	/**
	 * @param array<string,mixed>|null $result
	 * @return array{result_view:?array<string,mixed>,url:string}
	 */

	public function package_projection( object $package, ReleaseTrackingStatus $status, ?array $result ): array {
		if ( ! is_callable( array( $package, 'provider_code' ) )
			|| ! is_callable( array( $package, 'type' ) )
			|| ! is_callable( array( $package, 'identifier' ) )
			|| ! is_callable( array( $package, 'source_revision' ) ) ) {
			return array(
				'result_view' => null,
				'url'         => '',
			);
		}

		$result_view   = is_array( $result )
			&& hash_equals( (string) $package->type(), (string) ( $result['type'] ?? '' ) )
			&& hash_equals( (string) $package->identifier(), (string) ( $result['identifier'] ?? '' ) )
			? array(
				'result_code'           => $result['code'],
				'result_successful'     => $result['successful'],
				'failure_stage'         => $result['failure_stage'],
				'diagnostic_code'       => $result['diagnostic_code'],
				'diagnostic_available'  => $result['diagnostic_available'],
				'correlation_reference' => $result['correlation_reference'],
				'result_message'        => $result['message'],
				'result_remediation'    => $result['remediation'],
			) : null;
		$provider_code = (string) $package->provider_code();
		$url           = null !== $this->workflow_provider( $provider_code )
			&& hash_equals( $provider_code, $this->workflow_provider_code( $status ) )
			&& hash_equals( $status->type(), (string) $package->type() )
			&& hash_equals( $status->identifier(), (string) $package->identifier() )
			&& $status->source_revision() === (int) $package->source_revision()
			? $this->repository_release_url( $status->provider_repository_id(), $provider_code ) : '';

		return array(
			'result_view' => $result_view,
			'url'         => $url,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed>|null $result
	 * @return array<string,mixed>|null
	 */

	public function repository_section_projection( array $row, string $return_url, string $preview_key, ?array $result ): ?array {
		$provider_code = is_string( $row['provider_code'] ?? null ) ? $row['provider_code'] : '';
		if ( '' === $provider_code || true === ( $row['historical'] ?? false ) ) {
			return null;
		}

		$repository_id      = is_string( $row['repository_id'] ?? null ) ? $row['repository_id'] : '';
		$repository         = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$summaries          = is_array( $row['package_summaries'] ?? null ) ? array_values( array_filter( $row['package_summaries'], 'is_array' ) ) : array();
		$summary            = $summaries[0] ?? array();
		$type               = is_string( $summary['type'] ?? null ) ? $summary['type'] : '';
		$identifier         = is_string( $summary['identifier'] ?? null ) ? $summary['identifier'] : '';
		$revision           = is_int( $summary['source_revision'] ?? null ) ? $summary['source_revision'] : 0;
		$guard              = $this->repository_source_guard( $provider_code, $repository_id, $type, $identifier, PackageSource::RELEASE_ASSET );
		$shared             = 0 === $guard['release_count'] && 1 < $guard['relationship_count'];
		$conflicted         = ! $guard['allowed'] && ! $shared;
		$single             = $guard['allowed'] && 1 === $guard['relationship_count'] && 1 === count( $summaries );
		$package            = $single ? $this->local_package( $type, $identifier ) : null;
		$status             = $single ? $this->request_boundary( fn (): ?ReleaseTrackingStatus => $this->workflow_display_status( $type, $identifier, $revision ), null ) : null;
		$exact              = $status instanceof ReleaseTrackingStatus
			&& $this->status_matches_summary( $status, $type, $identifier, (string) ( $summary['source'] ?? '' ), $revision, $repository_id )
			&& is_object( $package ) && is_callable( array( $package, 'get_repository' ) )
			&& hash_equals( $repository, (string) $package->get_repository() );
		$workflow_available = null !== $this->workflow_provider( $provider_code );
		$workflow_status    = $exact ? $this->workflow_provider_status( $status ) : null;
		$matching_result    = $exact && is_array( $result )
			&& hash_equals( $provider_code, (string) ( $result['provider'] ?? '' ) )
			&& hash_equals( $repository_id, (string) ( $result['repository'] ?? '' ) )
			&& hash_equals( $type, (string) ( $result['type'] ?? '' ) )
			&& hash_equals( $identifier, (string) ( $result['identifier'] ?? '' ) )
			&& (int) ( $result['source_revision'] ?? 0 ) === $revision ? $result : null;
		$view               = $exact
			? $this->request_boundary(
				fn (): ?array => $this->workflow_view_for(
					$type,
					$identifier,
					$revision,
					(string) ( $matching_result['code'] ?? '' ),
					true === ( $matching_result['successful'] ?? false ),
					$preview_key,
					(string) ( $matching_result['channel'] ?? '' ),
					(string) ( $matching_result['failure_stage'] ?? '' ),
					(string) ( $matching_result['diagnostic_code'] ?? '' ),
					true === ( $matching_result['diagnostic_available'] ?? false ),
					(string) ( $matching_result['correlation_reference'] ?? '' ),
					(string) ( $matching_result['message'] ?? '' ),
					(string) ( $matching_result['remediation'] ?? '' )
				),
				$this->unavailable_workflow_view( __( 'Booster could not read the local release-workflow status for this package.', 'ran-booster' ) )
			)
			: $this->unavailable_workflow_view( $shared || $conflicted ? '' : __( 'Booster could not confirm that this release status belongs to the exact saved package and source.', 'ran-booster' ) );
		$readiness          = $exact ? array(
			'name'         => is_string( $summary['display_name'] ?? null ) ? $summary['display_name'] : $identifier,
			'type'         => $type,
			'eligible'     => $status->eligible(),
			'message'      => $this->repository_package_readiness_message( $status ),
			'tracking'     => 'release_asset' === $status->source(),
			'channel'      => $status->channel(),
			'settings_url' => is_string( $summary['settings_url'] ?? null ) ? $summary['settings_url'] : '',
		) : null;
		$observation        = is_array( $view['assessment_observation'] ?? null ) ? $view['assessment_observation'] : null;
		$observation_kind   = is_array( $observation ) && is_string( $observation['kind'] ?? null ) ? $observation['kind'] : 'unassessed';
		$automation         = $this->repository_release_automation_state(
			$exact && $this->record_matches_package_status( $workflow_status, $status ) ? $identifier : '',
			$workflow_available && $exact && $status->eligible() && 'branch' === $status->source(),
			$exact && $this->published_releases_working( $status ),
			$workflow_status?->record_occupied() ?? false,
			$observation_kind,
			$shared || ! $workflow_available
		);
		$result_observation = is_array( $matching_result ) ? $this->observation_kind_for_result( (string) $matching_result['code'] ) : null;

		return array(
			'settings_url'           => $single && is_string( $summary['settings_url'] ?? null ) ? $summary['settings_url'] : '',
			'settings_label'         => 'plugin' === $type ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ),
			'shared'                 => $shared,
			'conflicted'             => $conflicted,
			'relationship_count'     => $guard['relationship_count'],

			'return_url'             => $return_url,
			'conflict_packages'      => $summaries,
			'ineligible_message'     => $exact && ! $status->eligible() ? $this->repository_package_readiness_message( $status ) : '',
			'lifecycle'              => $this->repository_lifecycle_projection(
				$exact && $status->eligible(),
				$exact && 'release_asset' === $status->source(),
				$exact && $this->record_matches_status( $workflow_status, $status ),
				$workflow_available && $exact && $status->eligible() && 'branch' === $status->source(),
				$exact && $this->published_releases_working( $status ),
				$observation_kind,
				$workflow_available
			),
			'readiness'              => array(
				'repository'         => $repository,
				'relationship_count' => $guard['relationship_count'],
				'package'            => $readiness,
				'provider_supported' => $this->release_provider_supported( $provider_code ),
			),
			'show_automation'        => null !== $this->workflow_provider( $provider_code ) || null !== $this->workflow_capability( $provider_code ),
			'automation'             => $automation,
			'automation_unavailable' => ! $shared && ! $conflicted && true === ( $view['unavailable'] ?? false ),
			'automation_notice'      => ! $shared && ! $conflicted,
			'provider_workflow_url'  => 'existing_automation_detected' === $observation_kind ? ( $workflow_status?->provider_workflow_url() ?? '' ) : '',
			'show_result_notice'     => null !== $result_observation && ( '' === $result_observation || ! hash_equals( $result_observation, $observation_kind ) ),
			'workflow_view'          => $view,
		);
	}

	/** @return array{allowed:bool,code:string,relationship_count:int,release_count:int,owner_type:?int,owner_package:?string} */
	private function repository_source_guard( string $provider_code, string $repository_id, string $type, string $identifier, PackageSource $source ): array {
		$type_id = 'plugin' === $type ? 1 : ( 'theme' === $type ? 2 : 0 );

		return $this->request_boundary(
			fn (): array => $this->source_guard->assess( $provider_code, $repository_id, $type_id, $identifier, $source ),
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

	/** @return list<array{label:string,message:string,state:string}> */
	private function repository_lifecycle_projection( bool $package_ready, bool $tracking_ready, bool $workflow_recorded, bool $workflow_ready_to_assess, bool $published_releases_working, string $observation_kind, bool $workflow_available ): array {
		$automation_ready = $workflow_recorded || in_array( $observation_kind, array( 'existing_automation_detected', 'booster_setup_verified' ), true );
		$automation_label = $workflow_recorded ? __( 'Setup pull request recorded; check its outcome.', 'ran-booster' ) : match ( $observation_kind ) {
			'existing_automation_detected' => __( 'Existing workflow found.', 'ran-booster' ),
			'booster_setup_verified'       => __( 'Compatible workflow configuration verified.', 'ran-booster' ),
			'no_recognisable_automation'   => __( 'No workflow found; setup is available.', 'ran-booster' ),
			default                        => $workflow_ready_to_assess ? __( 'Ready to assess.', 'ran-booster' ) : ( $published_releases_working ? __( 'Releases are available; workflow not assessed.', 'ran-booster' ) : __( 'Workflow setup needs attention.', 'ran-booster' ) ),
		};
		$items = array(
			array(
				'label'   => __( 'Prepare package', 'ran-booster' ),
				'message' => $package_ready ? __( 'This package is ready for published-release tracking.', 'ran-booster' ) : __( 'This package needs attention before using published releases.', 'ran-booster' ),
				'state'   => $package_ready ? 'is-ok' : 'is-warning',
			),
			array(
				'label'   => __( 'Track releases', 'ran-booster' ),
				'message' => $tracking_ready ? __( 'Published releases are selected.', 'ran-booster' ) : __( 'Published releases are not selected.', 'ran-booster' ),
				'state'   => $tracking_ready ? 'is-ok' : 'is-pending',
			),
		);
		if ( $workflow_available ) {
			$items[] = array(
				'label'   => __( 'Release workflow — optional', 'ran-booster' ),
				'message' => $automation_label,
				'state'   => $automation_ready ? 'is-ok' : ( $published_releases_working ? 'is-pending' : 'is-warning' ),
			);
		}
		return $items;
	}

	/** @return array{label:string,tone:string,message:string,notice_tone:string,provenance:string} */
	private function repository_release_automation_state( string $workflow_owner, bool $workflow_ready_to_assess, bool $published_releases_working, bool $record_occupied, string $observation_kind, bool $unavailable = false ): array {
		$state   = __( 'Needs attention', 'ran-booster' );
		$tone    = 'ran-booster-badge--error';
		$message = __( 'Release workflow status is unavailable.', 'ran-booster' );
		$notice  = 'notice-warning';
		$origin  = __( 'Booster setup: Not recorded.', 'ran-booster' );
		if ( $unavailable ) {
			$state   = __( 'Unavailable', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'Release workflow is unavailable for this repository.', 'ran-booster' );
			$notice  = 'notice-info';
		} elseif ( '' !== $workflow_owner ) {
			$state   = __( 'Setup recorded', 'ran-booster' );
			$tone    = 'ran-booster-badge--warning';
			$message = sprintf( /* translators: %s: exact package type and name. */ __( 'A setup pull request is recorded for %s. Check its outcome before relying on the workflow.', 'ran-booster' ), $workflow_owner );
			$origin  = __( 'Booster setup: Draft pull request recorded.', 'ran-booster' );
		} elseif ( 'existing_automation_detected' === $observation_kind ) {
			$state   = __( 'Existing workflow found', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'An existing release workflow was found in this repository. Booster will not overwrite it.', 'ran-booster' );
			$notice  = 'notice-info';
		} elseif ( 'booster_setup_verified' === $observation_kind ) {
			$state   = __( 'Compatible workflow verified', 'ran-booster' );
			$tone    = 'ran-booster-badge--success';
			$message = __( 'A Booster-compatible workflow configuration was verified. Execution has not been checked.', 'ran-booster' );
			$notice  = 'notice-success';
			$origin  = __( 'Booster setup: No local setup pull-request record.', 'ran-booster' );
		} elseif ( 'mixed_observations' === $observation_kind ) {
			$state   = __( 'Multiple assessments', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'Workflow assessments differ. Review each package below.', 'ran-booster' );
			$notice  = 'notice-info';
		} elseif ( 'no_recognisable_automation' === $observation_kind ) {
			$state   = __( 'No workflow found', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'No recognizable release workflow was found. Booster can prepare a setup pull request.', 'ran-booster' );
			$notice  = 'notice-info';
		} elseif ( $workflow_ready_to_assess ) {
			$state   = __( 'Ready to assess', 'ran-booster' );
			$tone    = 'ran-booster-badge--success';
			$message = __( 'Assess this repository before preparing a setup pull request.', 'ran-booster' );
		} elseif ( $record_occupied ) {
			$state   = __( 'Blocked', 'ran-booster' );
			$message = __( 'A local workflow record is occupied by a different package or revision. Review it before setup.', 'ran-booster' );
		} elseif ( $published_releases_working ) {
			$state   = __( 'Not assessed', 'ran-booster' );
			$tone    = 'ran-booster-badge--info';
			$message = __( 'Releases are available; their publishing method has not been assessed.', 'ran-booster' );
			$notice  = 'notice-info';
		}
		return array(
			'label'       => $state,
			'tone'        => $tone,
			'message'     => $message,
			'notice_tone' => $notice,
			'provenance'  => $origin,
		);
	}

	private function repository_package_readiness_message( ReleaseTrackingStatus $status ): string {
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

	/**
	 * @return array{allowed:bool,code:string,relationship_count:int,release_count:int,owner_type:?int,owner_package:?string}
	 */
	private function workflow_source_guard( string $type, string $identifier, object $package ): array {
		if ( ! is_callable( array( $package, 'get_provider_code' ) )
			|| ! is_callable( array( $package, 'get_provider_repository_id' ) )
			|| ! is_string( $package->get_provider_repository_id() ) ) {
			return array(
				'allowed'            => false,
				'code'               => 'repository_source_unavailable',
				'relationship_count' => 0,
				'release_count'      => 0,
				'owner_type'         => null,
				'owner_package'      => null,
			);
		}

		return $this->repository_source_guard(
			$package->get_provider_code(),
			$package->get_provider_repository_id(),
			$type,
			$identifier,
			PackageSource::RELEASE_ASSET
		);
	}

	private function workflow_package( string $type, string $identifier, int $revision ): ?object {
		$package = $this->local_package( $type, $identifier );
		return null !== $package && $revision === $package->get_source_revision()
			&& is_string( $package->get_provider_repository_id() ) && '' !== $package->get_provider_repository_id()
			? $package : null;
	}
	private function package_matches_status( object $package, ReleaseTrackingStatus $status ): bool {
		return $status->provider_repository_id() === $package->get_provider_repository_id()
			&& $status->source_revision() === $package->get_source_revision();
	}
	private function anonymous_workflow_inspection_allowed( object $package ): bool {
		return is_callable( array( $package, 'is_private' ) )
			&& false === $this->request_boundary( fn (): mixed => $package->is_private(), null );
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed> $summary
	 * @return array{detail:array{label:string,value:string,tone:string},action:array<string,mixed>}|null
	 */
	private function repository_release_automation_projection( array $row, array $summary, bool $multiple ): ?array {
		$type             = is_string( $summary['type'] ?? null ) ? $summary['type'] : '';
		$reference        = is_string( $summary['identifier'] ?? null ) ? $summary['identifier'] : '';
		$summary_source   = is_string( $summary['source'] ?? null ) ? $summary['source'] : '';
		$summary_revision = is_int( $summary['source_revision'] ?? null ) ? $summary['source_revision'] : 0;
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || '' === $reference
			|| ! in_array( $summary_source, array( 'branch', 'release_asset' ), true ) || 1 > $summary_revision ) {
			return null;
		}
		$package       = $this->local_package( $type, $reference );
		$status        = null;
		$provider_code = is_string( $row['provider_code'] ?? null ) ? $row['provider_code'] : '';
		$repository    = is_string( $row['repository_id'] ?? null ) ? $row['repository_id'] : '';
		$locator       = is_string( $row['repository'] ?? null ) ? $row['repository'] : '';
		$exact         = null !== $this->workflow_provider( $provider_code ) && null !== $package && '' !== $repository
			&& is_callable( array( $package, 'get_identifier' ) )
			&& is_callable( array( $package, 'get_provider_repository_id' ) )
			&& is_callable( array( $package, 'get_repository' ) )
			&& is_callable( array( $package, 'get_source_revision' ) )
			&& is_string( $package->get_identifier() )
			&& hash_equals( $reference, $package->get_identifier() )
			&& hash_equals( $provider_code, (string) $package->get_provider_code() )
			&& is_string( $package->get_provider_repository_id() )
			&& hash_equals( $repository, $package->get_provider_repository_id() )
			&& 0 === strcasecmp( $locator, (string) $package->get_repository() )
			&& $summary_revision === $package->get_source_revision();
		if ( $exact ) {
			$status = $this->request_boundary(
				fn (): ?ReleaseTrackingStatus => $this->tracking->status( $type, $reference, $summary_revision ),
				null
			);
			$exact  = $status instanceof ReleaseTrackingStatus
				&& hash_equals( $repository, $status->provider_repository_id() )
				&& hash_equals( $type, $status->type() )
				&& hash_equals( $reference, $status->identifier() )
				&& $summary_revision === $status->source_revision()
				&& hash_equals( $summary_source, $status->source() );
		}

		$value = __( 'Unavailable', 'ran-booster' );
		$tone  = 'warning';
		if ( $exact && $status instanceof ReleaseTrackingStatus ) {
			$workflow_status  = $this->workflow_provider_status( $status );
			$observation_kind = $workflow_status?->observation_kind() ?? '';
			if ( $this->record_matches_status( $workflow_status, $status ) ) {
				$value = __( 'Setup recorded', 'ran-booster' );
				$tone  = 'pending';
			} elseif ( 'existing_automation_detected' === $observation_kind ) {
				$value = __( 'Existing workflow found', 'ran-booster' );
				$tone  = 'info';
			} elseif ( 'booster_setup_verified' === $observation_kind ) {
				$value = __( 'Compatible workflow verified', 'ran-booster' );
				$tone  = 'ok';
			} elseif ( $this->published_releases_working( $status ) ) {
				$value = __( 'Published releases working', 'ran-booster' );
				$tone  = 'ok';
			} elseif ( in_array( $status->failure_code(), array( 'release_repository_conflict', 'repository_release_owner_exists' ), true ) ) {
				$value = __( 'Blocked', 'ran-booster' );
				$tone  = 'warning';
			} elseif ( ! ( $workflow_status?->record_occupied() ?? true ) && $status->eligible() && '' === $status->failure_code() ) {
				$value = 'branch' === $status->source()
					? __( 'Ready to assess', 'ran-booster' )
					: __( 'Published releases selected', 'ran-booster' );
				$tone  = 'branch' === $status->source() ? 'ok' : 'pending';
			}
		}

		$settings_url = $this->repository_release_url( $repository, $provider_code );
		$label        = $multiple
			? sprintf(
				/* translators: %s is a managed plugin file or theme stylesheet. */
				__( 'Release workflow: %s', 'ran-booster' ),
				$this->bounded_reference( $reference, 74 )
			)
			: __( 'Release workflow', 'ran-booster' );
		$detail_label = $multiple
			? sprintf(
				/* translators: %s is a managed plugin file or theme stylesheet. */
				__( 'Release workflow — %s', 'ran-booster' ),
				$this->bounded_reference( $reference, 70 )
			)
			: __( 'Release workflow', 'ran-booster' );
		$key = 'core:release-workflow-' . substr( hash( 'sha256', $provider_code . '|' . $type . '|' . $reference ), 0, 16 );

		return array(
			'detail' => array(
				'key'            => $key,
				'label'          => $detail_label,
				'value'          => $value,
				'tone'           => $tone,
				'category'       => 'release_workflow',
				'review_summary' => $exact && $status instanceof ReleaseTrackingStatus && null !== $this->workflow_provider_status( $status ),
			),
			'action' => array(
				'key'           => $key,
				'label'         => $label,
				'type'          => 'link',
				'url'           => $settings_url,
				'hidden'        => array(),
				'disabled'      => false,
				'external'      => false,
				'described_by'  => '',
				'screen_reader' => $reference,
			),
		);
	}

	private function local_package( string $type, string $identifier ): ?object {
		return $this->request_boundary(
			fn (): object => 'plugin' === $type
				? $this->plugins->booster_plugin_from_file( $identifier )
				: $this->themes->booster_theme_from_stylesheet( $identifier ),
			null
		);
	}

	private function record_matches_status( ?\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus $record, ReleaseTrackingStatus $status ): bool {
		return $record instanceof \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus
			&& $record->record_exact()
			&& hash_equals( $status->provider_repository_id(), $record->repository_id() )
			&& hash_equals( $status->type(), $record->package_type() )
			&& hash_equals( $status->identifier(), $record->package_identifier() )
			&& $status->source_revision() === $record->source_revision();
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

	private function bounded_reference( string $reference, int $maximum ): string {
		return strlen( $reference ) <= $maximum
			? $reference
			: substr( $reference, 0, $maximum - 3 ) . '...';
	}

	/** @return array<string,mixed>|null */
	private function workflow_view_for( string $type, string $identifier, int $revision, string $code, bool $successful, string $preview_key, string $channel, string $stage = '', string $diagnostic = '', bool $diagnostic_available = false, string $reference = '', string $message = '', string $remediation = '' ): ?array {
		$status  = $this->workflow_display_status( $type, $identifier, $revision );
		$package = $this->workflow_package( $type, $identifier, $revision );
		if ( null === $status || null === $package || ! $this->package_matches_status( $package, $status ) ) {
			return $this->unavailable_workflow_view( __( 'Booster could not confirm this package. Reload its settings and try again.', 'ran-booster' ) );
		}
		$provider_code = (string) $package->get_provider_code();
		if ( null === $this->workflow_capability( $provider_code ) ) {
			return null;
		}
		$provider       = $this->workflow_provider( $provider_code );
		$state          = null === $provider ? null : $this->workflow_provider_status( $status );
		$anonymous      = $this->anonymous_workflow_inspection_allowed( $package );
		$source_guard   = $this->workflow_source_guard( $type, $identifier, $package );
		$metadata       = $this->providers->metadata()[ $provider_code ] ?? null;
		$write_guidance = $state?->write_guidance() ?? '';
		if ( '' === trim( $write_guidance ) ) {
			$write_guidance = __( 'Choose a saved credential that can manage release workflows and open pull requests. Its secret is never stored with this setup.', 'ran-booster' );
		}
		$extra  = array(
			'provider_label'        => $metadata->label ?? $provider_code,
			'documentation_links'   => $state?->documentation_links() ?? array(),
			'provider_workflow_url' => $state?->provider_workflow_url() ?? '',
			'write_guidance'        => $write_guidance,
		);
		$reason = null === $provider
			? __( 'This provider claims release workflow management but does not implement all required release capabilities. Update or correct the provider plugin; no operation is available.', 'ran-booster' )
			: ( null === $state ? __( 'The provider could not supply local workflow status. Retry after checking the provider plugin.', 'ran-booster' ) : '' );
		if ( '' === $reason && ! $status->eligible() ) {
			$reason = $this->workflow_unavailable_reason( $status );
		}
		if ( '' === $reason && ! $source_guard['allowed'] ) {
			$reason = 'repository_source_unavailable' === ( $source_guard['code'] ?? '' )
				? __( 'Booster could not safely read this package\'s repository source relationship. Check package storage and retry.', 'ran-booster' )
				: __( 'Releases require a repository used by only one managed package. Review the repository package list.', 'ran-booster' );
		}
		if ( '' === $reason && $state->record_occupied() && ! $this->record_matches_package_status( $state, $status ) ) {
			$reason = __( 'A workflow record belongs to a different package. Review the recorded repository state before setup.', 'ran-booster' );
		}
		$credentials = $state?->credential_choices() ?? array();
		$channel     = 'stable';
		$preview     = null;
		if ( '' === $reason && '' !== $preview_key ) {
			$preview = $this->request_boundary( fn () => $provider->workflow_preview( ReleaseWorkflowProviderProjection::target( $status ), $preview_key ), null );
			if ( null !== $preview && ( $preview->key() !== $preview_key || $preview->provider_code() !== $provider_code || $preview->repository_id() !== $status->provider_repository_id() ) ) {
				$preview = null;
			}
		}
		$forms = array( 'inspect' => $this->workflow_form( 'inspect', $status, '', '', $channel, $credentials, $anonymous ) );
		if ( '' !== $reason || null === $forms['inspect'] ) {
			$forms                           = $this->unavailable_workflow_view( $reason, anonymous_inspection: $anonymous )['forms'];
			$forms['inspect']['credentials'] = $credentials;
		}
		if ( null !== $preview ) {
			$forms['setup'] = $this->workflow_form( 'setup', $status, $preview_key, $preview->confirmation(), $preview->channel(), $credentials, $anonymous );
		}
		if ( '' === $reason && $this->record_matches_package_status( $state, $status ) ) {
			$forms['outcome'] = $this->workflow_form( 'outcome', $status, credentials: $credentials, anonymous_inspection: $anonymous );
		}
		foreach ( $forms as &$form ) {
			if ( is_array( $form ) ) {
				$form['provider_label']  = $extra['provider_label'];
				$form['write_guidance']  = $extra['write_guidance'];
				$form['credentials_url'] = add_query_arg(
					array(
						'page' => 'ran-booster',
						'tab'  => $provider_code,
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
			'diagnostic_available'   => $diagnostic_available,
			'correlation_reference'  => $reference,
			'result_message'         => $message,
			'result_remediation'     => $remediation,
			'unavailable'            => '' !== $reason,
			'unavailable_reason'     => $reason,
			'preview'                => null === $preview ? null : $preview->summary() + array(
				'kind'    => $preview->kind(),
				'changes' => $preview->changed_paths(),
			),
			'record'                 => $this->record_matches_package_status( $state, $status ) ? array( 'pull_request_url' => $state->pull_request_url() ) : null,
			'legacy'                 => true === $state?->record_occupied() && ! $this->record_matches_package_status( $state, $status ) ? array( 'unsupported' => true ) : null,
			'failure_history'        => $state?->failure_history() ?? array(),
			'assessment_observation' => null !== $state && '' !== $state->observation_kind() ? array(
				'kind'        => $state->observation_kind(),
				'recorded_at' => $state->observed_at(),
			) : null,
			'automation_state'       => '' !== $reason ? 'blocked' : ( $this->record_matches_package_status( $state, $status ) ? 'setup_recorded' : ( null !== $preview ? 'preview' : 'ready' ) ),
			'forms'                  => array_filter( $forms, 'is_array' ),
		);
	}
	/** @return array<string,mixed> */
	private function unavailable_workflow_view( string $reason, string $code = '', bool $successful = false, string $automation_state = 'blocked', bool $anonymous_inspection = false ): array {
		return array(
			'result_code'        => $code,
			'result_successful'  => $successful,
			'unavailable'        => true,
			'unavailable_reason' => $reason,
			'preview'            => null,
			'record'             => null,
			'legacy'             => null,
			'automation_state'   => $automation_state,
			'forms'              => array(
				'inspect' => array(
					'operation'            => 'inspect',
					'action'               => admin_url( 'admin-post.php' ),
					'fields'               => array(),
					'credentials'          => array(),
					'anonymous_inspection' => $anonymous_inspection,
					'credentials_url'      => admin_url( 'admin.php?page=ran-booster' ),
					'disabled'             => true,
				),
			),
		);
	}
	/** Render-only identity check. POST requests continue through workflow_status(). */
	private function workflow_display_status( string $type, string $identifier, int $revision ): ?ReleaseTrackingStatus {
		$status = $this->releases->status( $type, $identifier );
		if ( $revision !== $status->source_revision()
			|| ! hash_equals( $type, $status->type() ) || ! hash_equals( $identifier, $status->identifier() ) ) {
			return null;
		}

		return $status;
	}

	private function workflow_unavailable_reason( ReleaseTrackingStatus $status ): string {
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

	/**
	 * @return array<string,mixed>|null
	 * @param list<array{id:string,label:string}> $credentials
	 */
	private function workflow_form(
		string $operation,
		ReleaseTrackingStatus $status,
		string $preview = '',
		string $confirmation = '',
		string $channel = '',
		array $credentials = array(),
		bool $anonymous_inspection = false
	): ?array {
		$preflight = '';
		if ( in_array( $operation, array( 'inspect', 'setup' ), true ) ) {
			if ( 'stable' !== $channel ) {
				return null;
			}
			$action = $this->releases->nonce_action( 'assessment_preflight', $status->type(), $status->identifier(), $status->source_revision(), $channel );
			if ( '' === $action ) {
				return null;
			}
			$preflight = wp_create_nonce( $action );
		}
		$fields = array(
			'action'                   => 'ran_booster_release_workflow',
			'workflow_operation'       => $operation,
			'expected_provider'        => $this->workflow_provider_code( $status ),
			'expected_repository_id'   => $status->provider_repository_id(),
			'_wpnonce'                 => wp_create_nonce( $this->requests->workflow_nonce_action( $operation, $status, $preview ) ),
			'expected_type'            => $status->type(),
			'expected_identifier'      => $status->identifier(),
			'expected_source_revision' => (string) $status->source_revision(),
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
			'anonymous_inspection' => $anonymous_inspection,
			'credentials_url'      => add_query_arg(
				array(
					'page' => 'ran-booster',
					'tab'  => $this->workflow_provider_code( $status ),
					'view' => 'credentials',
				),
				admin_url( 'admin.php' )
			),
		);
	}

	/** Resolve the exact provider capability, retaining unavailable-provider fallback. */
	private function workflow_capability( string $provider_code ): ?RepositoryReleaseWorkflowManagementV3 {
		try {
			return $this->providers->require_capability( $provider_code, RepositoryReleaseWorkflowManagementV3::class );
		} catch ( Throwable ) {
			return null;
		}
	}

	private function workflow_provider( string $provider_code ): ?RepositoryReleaseWorkflowManagementV3 {
		$provider = $this->workflow_capability( $provider_code );
		return null !== $provider && 3 === $provider::RELEASE_WORKFLOW_API_VERSION && null !== ( ( $this->providers->metadata()[ $provider_code ] ?? null )->admin ?? null ) && $this->release_provider_supported( $provider_code ) ? $provider : null;
	}

	private function release_provider_supported( string $provider_code ): bool {
		try {
			$provider = $this->providers->get( $provider_code );
			return $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseMetadata
				&& $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseCandidateListing
				&& $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseInspector
				&& $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseAcquirer
				&& $provider instanceof \RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
		} catch ( Throwable ) {
			return false;
		}
	}

	private function workflow_provider_status( ReleaseTrackingStatus $status ): ?\RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus {
		$provider_code = $this->workflow_provider_code( $status );
		$provider      = $this->workflow_provider( $provider_code );
		if ( null === $provider ) {
			return null;
		}
		$key = hash( 'sha256', (string) wp_json_encode( array( $provider_code, $status->provider_repository_id(), $status->type(), $status->identifier(), $status->source_revision() ) ) );
		if ( ! array_key_exists( $key, $this->workflow_statuses ) ) {
			$value = $this->request_boundary( fn () => $provider->workflow_status( ReleaseWorkflowProviderProjection::target( $status ) ), null );
			if ( null !== $value && ( $value->provider_code() !== $provider_code
				|| $value->repository_id() !== $status->provider_repository_id()
				|| ( $value->record_exact() && ( $value->package_type() !== $status->type() || $value->package_identifier() !== $status->identifier() || $value->source_revision() !== $status->source_revision() ) ) ) ) {
				$value = null;
			}
			$this->workflow_statuses[ $key ] = $value;
		}
		return $this->workflow_statuses[ $key ];
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
}
