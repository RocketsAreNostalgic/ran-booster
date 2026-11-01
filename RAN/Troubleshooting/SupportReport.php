<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use Closure;
use RAN\Activity\ActivityStore;
use RAN\Deployment\DeploymentOutcome;
use RAN\Logging\TemporaryDebugCapture;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Throwable;

/** One fixed export boundary; captured text has no persistence or transmission path. */
final class SupportReport {

	public const MAX_BYTES     = 1048576;
	private const OPERATIONS   = array( 'install', 'update', 'diagnostics.run', 'credential.validate', 'branch.check', 'release.check', 'workflow.assessment', 'webhook.install', 'webhook.update', 'webhook.delete', 'webhook.reconcile', 'deployment.manual', 'deployment.webhook', 'deployment.batch' );
	private const PARTIAL      = array( 'local_incomplete', 'deadline_exhausted', 'remote_calls_exhausted', 'provider_unavailable', 'provider_results_invalid', 'result_limit_exhausted' );
	private const LOG_MESSAGES = array( 'attempt admitted (manual)', 'attempt queued (manual batch)', 'attempt queued (webhook)', 'attempt claimed from queue', 'deployment execution started', 'attempt finished', 'attempt reconciled as stopped', 'mutation guard blocked deployment' );
	/** @var array<string,array<string,string>> */
	private array $identities = array();

	public function __construct(
		private readonly InstallationFacts $facts,
		private readonly ?DiagnosticRunStore $runs = null,
		private readonly ?ActivityStore $activity = null,
		private readonly ?PluginRepository $plugins = null,
		private readonly ?ThemeRepository $themes = null,
		private readonly ?TemporaryDebugCapture $capture = null,
		private readonly ?Closure $clock = null
	) {
	}

	/**
	 * @param array<string,mixed> $storage
	 * @param array{state?:string,local?:list<array{status:string,code:string,message:string,remediation:string}>,providers?:array<string,list<array{status:string,code:string,message:string,remediation:string}>>,provider_states?:array<string,string>,omitted?:int} $fresh_diagnostics
	 * @return array{text:string,filename:string}
	 */
	public function generate( array $storage, array $fresh_diagnostics = array() ): array {
		$this->identities = array();
		$started          = $this->now();
		$sections         = array();
		// Fixed private composition, not an extension/collector registry.
		foreach ( array(
			'environment'      => fn(): array => array( 'Included', self::environment_keys( $this->facts->environment() ) ),
			'installation'     => fn(): array => $this->installation(),
			'storage'          => fn(): array => $this->storage( $storage ),
			'managed_packages' => fn(): array => $this->packages(),
			'diagnostics'      => fn(): array => $this->diagnostics( $fresh_diagnostics, $this->now() - $started < 5.0 ),
			'activity'         => fn(): array => $this->activity(),
			'logging'          => fn(): array => $this->logging(),
		) as $name => $collect ) {
			$at = $this->now();
			try {
				// Fresh diagnostics were already completed before passive serialization;
				// their safe projection must not be discarded by earlier collectors.
				[$state, $data] = $at - $started >= 5.0 && ( 'diagnostics' !== $name || array() === $fresh_diagnostics ) ? array( 'Not included', array( 'reason' => 'collection_budget_exhausted' ) ) : $collect();
			} catch ( Throwable ) {
				[$state, $data] = array( 'Unavailable', array( 'reason' => 'local_evidence_unavailable' ) );
			}
			$sections[ $name ] = array(
				'state'       => $state,
				'observed_at' => gmdate( 'c', (int) $at ),
				'data'        => $data,
			);
		}
		$ended = $this->now();
		$text  = self::serialize( $sections, $started, $ended );
		return array(
			'text'     => $text,
			'filename' => 'ran-booster-support-' . gmdate( 'Ymd-His', (int) $started ) . '.json',
		);
	}

	/**
	 * Never retain provider prose, even when it is safe for local display.
	 * @param list<array{status:string,code:string,message:string,remediation:string}> $results
	 * @return list<array{status:string,code:string,message:string,remediation:string}>
	 */
	public static function safe_diagnostic_results( array $results ): array {
		$safe   = array();
		$local  = array(
			'runtime'             => array( 'ready', 'unsupported', 'multisite_unsupported' ),
			'database'            => array( 'unsupported', 'schema_unavailable' ),
			'filesystem'          => array( 'ready', 'unavailable', 'modifications_disabled', 'direct_unavailable' ),
			'destinations'        => array( 'ready', 'unavailable' ),
			'deployment_attempts' => array( 'ready', 'unavailable', 'retention_unavailable', 'retention_configuration_invalid', 'attention_required' ),
			'deployment_worker'   => array( 'ready', 'unavailable', 'wakeup_missing' ),
		);
		$remote = array(
			'credential' => array( 'valid', 'invalid', 'not_configured', 'unavailable', 'rate_limited', 'budget_exhausted' ),
			'repository' => array( 'reachable', 'denied', 'not_found', 'not_configured', 'unavailable', 'rate_limited', 'budget_exhausted' ),
			'webhook'    => array( 'configuration_unavailable', 'delivery_authenticated', 'delivery_authenticated_unmatched', 'delivery_evidence_unavailable', 'delivery_not_observed', 'not_configured' ),
		);
		foreach ( array_slice( $results, 0, 8 ) as $result ) {
			$segments                   = explode( '.', $result['code'] );
			[$owner, $family, $outcome] = array_pad( $segments, 3, '' );
			$known                      = 3 === count( $segments ) && ( ( 'local' === $owner && in_array( $outcome, $local[ $family ] ?? array(), true ) )
				|| ( in_array( $owner, array( 'gh', 'bb' ), true ) && in_array( $outcome, $remote[ $family ] ?? array(), true ) ) );
			$status                     = self::choice( $result['status'], array( 'pass', 'warning', 'fail', 'not_configured' ) );
			$safe[]                     = array(
				'status'      => $status,
				'code'        => $known ? $result['code'] : 'provider.check',
				'message'     => $known ? str_replace( '_', ' ', $family . ': ' . $outcome ) : 'Provider result details omitted by the sharing policy.',
				'remediation' => 'pass' === $status ? 'No action indicated by this result.' : 'Review the immediate diagnostic results locally.',
			);
		}
		return $safe;
	}

	/** @return array{string,array<string,mixed>} */
	private function installation(): array {
		$core      = $this->facts->core();
		$selection = $this->facts->github_selection();
		$data      = array(
			'version'          => InstallationFacts::version( $core['installed_version'] ?? null ),
			'configuration'    => $this->facts->core_configuration(),
			'github_selection' => array(
				'state'                  => self::choice( $selection['state'], array( 'storage_unavailable', 'recovery_required', 'conflict', 'incompatible', 'missing', 'admitted', 'decision_required' ) ),
				'admitted'               => $selection['admitted'],
				'selected'               => self::choice( $selection['selected'], array( 'bundled', 'external', 'unknown' ) ),
				'version'                => InstallationFacts::version( $selection['version'] ),
				'provider_api'           => $selection['provider_api'] > 0 && $selection['provider_api'] < 1000 ? $selection['provider_api'] : null,
				'compatibility_revision' => InstallationFacts::version( $selection['compatibility_revision'] ),
			),
			'update'           => self::core_update_lines( $core ),
			'components'       => array(),
		);
		foreach ( $this->facts->components() as $component ) {
			$name = $component['Name'] ?? '';
			$row  = array( 'name' => in_array( $name, array( 'GitHub', 'Bitbucket Cloud', 'WP Pusher Migrator' ), true ) ? $name : $this->identity( 'Component', $name ) );
			foreach ( array( 'Installed', 'Active', 'Admission' ) as $key ) {
				if ( isset( $component[ $key ] ) ) {
					$row[ strtolower( $key ) ] = self::choice( $component[ $key ], array( 'Yes', 'No', 'Admitted' ) );
				}
			}
			if ( isset( $component['Version'] ) ) {
				$row['version'] = InstallationFacts::version( $component['Version'] );
			}
			if ( isset( $component['Selection'] ) ) {
				$row['selection'] = self::choice( $component['Selection'], array( 'Selected locally', 'Not applicable' ) );
			}
			if ( isset( $component['Availability'] ) ) {
				$row['availability'] = self::choice( $component['Availability'], array( 'Available locally; remote access not checked' ) );
			}
			if ( isset( $component['Provider'] ) ) {
				$row['provider']       = $this->provider( $component['Provider'] );
				$row['implementation'] = $this->identity( 'Implementation', $component['Implementation'] ?? '' );
				$row['archive_api']    = self::choice( $component['Archive API'] ?? null, array( '1', '2' ) );
			}
			$data['components'][] = $row;
		}
		return array( $this->facts->components_truncated() ? 'Truncated' : 'Included', $data );
	}

	/**
	 * @param array<string,mixed> $core
	 * @return array<string,string>
	 */
	private static function core_update_lines( array $core ): array {
		$lines = array();
		foreach ( array( 'requested_mode', 'effective_mode', 'reason', 'updater_state' ) as $key ) {
			$lines[ $key ] = self::choice( $core[ $key ] ?? null, array( 'auto', 'enabled', 'disabled', 'invalid', 'configuration_enabled', 'configuration_disabled', 'configuration_invalid', 'preference_disabled', 'source_checkout', 'verified_release', 'release_marker_missing_or_invalid', 'active', 'inactive', 'unavailable', 'blocked' ) );
		}
		foreach ( array( 'offered_version', 'marker_version' ) as $key ) {
			$lines[ $key ] = InstallationFacts::version( $core[ $key ] ?? null );
		}
		$lines['marker_commit'] = self::hex( $core['marker_commit'] ?? null, 40 );
		$lines['updater_code']  = self::choice( $core['updater_code'] ?? null, array( 'native_discovery_disabled', 'diagnostics_unavailable', 'github_updater_status_unavailable', 'github_updater_runtime_environment_invalid' ) );
		foreach ( array( 'last_check', 'next_check' ) as $key ) {
			$value         = $core[ $key ] ?? null;
			$lines[ $key ] = ( is_int( $value ) && $value > 0 && $value <= 253402300799 ? gmdate( 'c', $value ) : 'Unknown; no new check performed' );
		}
		foreach ( array( 'last_successful_check', 'check_cooldown_until' ) as $key ) {
			$value         = $core[ $key ] ?? null;
			$lines[ $key ] = ( is_int( $value ) && $value > 0 && $value <= 253402300799 ? gmdate( 'c', $value ) : 'Unknown' );
		}
		$lines['last_check_outcome'] = self::choice( $core['last_check_outcome'] ?? null, array( 'success', 'failed', 'unknown', 'rejected', 'skipped' ) );
		$lines['last_check_code']    = self::choice( $core['last_check_code'] ?? null, array( 'native_discovery_disabled', 'refresh_rejected', 'orchestration_failed', 'check_not_observed', 'check_succeeded', 'check_rejected', 'check_failed', 'claim_lost', 'unexpected_failure', 'runtime_identity_invalid', 'policy_disabled', 'policy_forced_off', 'filesystem_unavailable', 'discovery_not_admitted', 'candidate_not_eligible', 'offer_token_unavailable', 'update_available', 'release_list_failed', 'candidate_invalid', 'candidate_rejected', 'candidate_inspection_failed', 'candidate_descriptor_mismatch', 'candidate_validation_failed', 'no_candidates', 'no_newer_candidate', 'cooldown_active', 'claim_unavailable', 'target_unavailable' ) );
		return $lines;
	}

	/**
	 * @param array<string,mixed> $storage
	 * @return array{string,array<string,mixed>}
	 */
	private function storage( array $storage ): array {
		return array(
			'Included',
			array(
				'location'             => 'site-owned encrypted credential sidecar; absolute location withheld',
				'state'                => self::choice( $storage['state'] ?? null, array( 'actionable', 'configured', 'unknown', 'unavailable', 'healthy', 'not_configured' ) ),
				'configuration_source' => defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' ) || defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE' ) ? 'constant' : 'none',
				'authenticated_health' => 'Not checked',
				'capacity'             => 'Not included',
			),
		);
	}

	/** @return array{string,array<string,mixed>} */
	private function packages(): array {
		$data      = array(
			'coverage' => 'bounded_recorded_management_rows',
			'types'    => array(),
			'records'  => array(),
		);
		$remaining = 100;
		$complete  = true;
		$truncated = false;
		foreach ( array(
			'plugin' => $this->plugins,
			'theme'  => $this->themes,
		) as $type => $repository ) {
			$snapshot = $repository?->support_snapshot( $remaining );
			if ( null === $snapshot || ! $snapshot['available'] ) {
				$complete               = false;
				$data['types'][ $type ] = array(
					'state'    => 'Unavailable',
					'included' => null,
				);
				continue;
			}
			$truncated              = $truncated || $snapshot['truncated'];
			$data['types'][ $type ] = array(
				'state'    => $snapshot['truncated'] ? 'Truncated' : 'Included',
				'included' => count( $snapshot['records'] ),
			);
			foreach ( $snapshot['records'] as $row ) {
				--$remaining;
				$data['records'][] = array(
					'type'       => $type,
					'package'    => $this->identity( 'Package', $row['package'] ?? '' ),
					'repository' => $this->identity( 'Repository', $row['repository'] ?? '' ),
					'ref'        => $this->identity( 'Ref', $row['branch'] ?? '' ),
					'provider'   => $this->provider( $row['provider'] ?? '' ),
					'policy'     => self::choice( $row['deployment_policy'] ?? null, array( 'disabled', 'manual', 'automatic' ) ),
					'source'     => self::choice( $row['source'] ?? null, array( 'branch', 'release_asset' ) ),
				);
			}
		}
		return array( $truncated ? 'Truncated' : ( $complete ? 'Included' : 'Unavailable' ), $data );
	}

	/**
	 * @param array{state?:string,local?:list<array{status:string,code:string,message:string,remediation:string}>,providers?:array<string,list<array{status:string,code:string,message:string,remediation:string}>>,provider_states?:array<string,string>,omitted?:int} $fresh
	 * @return array{string,array<string,mixed>}
	 */
	private function diagnostics( array $fresh = array(), bool $include_retained = true ): array {
		$fresh_state = self::choice( $fresh['state'] ?? null, array( 'unavailable', 'permission_skipped', 'completed', 'local_incomplete' ) );
		$data        = array(
			'fresh'            => array(
				'state'                   => $fresh_state,
				'local'                   => array(),
				'local_results_observed'  => null,
				'providers'               => array(),
				'omitted_provider_groups' => null,
			),
			'retained'         => array(
				'state' => 'Unavailable',
				'runs'  => array(),
			),
			'coverage_reasons' => array(),
		);
		if ( ! in_array( $fresh_state, array( 'completed', 'local_incomplete' ), true ) ) {
			$data['coverage_reasons'][] = $fresh_state;
		} else {
			$data['fresh']['omitted_provider_groups'] = is_int( $fresh['omitted'] ?? null ) && $fresh['omitted'] >= 0 ? $fresh['omitted'] : null;
			$local                                    = self::fresh_results( $fresh['local'] ?? array() );
			$data['fresh']['local']                   = self::safe_diagnostic_results( $local );
			$data['fresh']['local_results_observed']  = count( $local );
			if ( count( $local ) > 8 ) {
				$data['coverage_reasons'][] = 'local_result_limit_exhausted';
			}
			foreach ( is_array( $fresh['providers'] ?? null ) ? $fresh['providers'] : array() as $provider => $results ) {
				$safe                         = self::fresh_results( $results );
				$state                        = self::choice( $fresh['provider_states'][ $provider ] ?? 'included', array( 'included', 'provider_unavailable', 'provider_results_invalid', 'deadline_exhausted', 'remote_calls_exhausted' ) );
				$data['fresh']['providers'][] = array(
					'provider'          => $this->provider( $provider ),
					'state'             => $state,
					'results'           => self::safe_diagnostic_results( $safe ),
					'results_truncated' => count( $safe ) > 8,
					'results_observed'  => count( $safe ),
				);
				if ( 'included' !== $state ) {
					$data['coverage_reasons'][] = $state;
				}
				if ( count( $safe ) > 8 ) {
					$data['coverage_reasons'][] = 'provider_result_limit_exhausted';
				}
			}
			foreach ( $fresh['provider_states'] ?? array() as $provider_state ) {
				$provider_state = self::choice( $provider_state, array( 'included', 'provider_unavailable', 'provider_results_invalid', 'deadline_exhausted', 'remote_calls_exhausted' ) );
				if ( 'included' !== $provider_state && ! in_array( $provider_state, $data['coverage_reasons'], true ) ) {
					$data['coverage_reasons'][] = $provider_state;
				}
			}
			if ( 'local_incomplete' === $fresh_state ) {
				$data['coverage_reasons'][] = 'local_incomplete';
			}
			if ( is_int( $fresh['omitted'] ?? null ) && $fresh['omitted'] > 0 ) {
				$data['fresh']['omitted_provider_groups'] = $fresh['omitted'];
				$data['coverage_reasons'][]               = 'provider_group_limit_exhausted';
			}
		}
		if ( ! $include_retained ) {
			$data['retained']['state']  = 'Not included';
			$data['coverage_reasons'][] = 'passive_budget_exhausted';
		} else {
			$snapshot = $this->runs?->available();
			if ( null === $snapshot || ! $snapshot['available'] ) {
				$data['coverage_reasons'][] = 'retained_unavailable';
			} elseif ( array() === $snapshot['runs'] ) {
				$data['retained']['state'] = 'Not run';
			} else {
				$data['retained']['state'] = 'Included';
				foreach ( $snapshot['runs'] as $run ) {
					if ( $run['partial'] ) {
						$data['coverage_reasons'][] = self::choice( $run['partial_reason'], self::PARTIAL );
					}
					$data['retained']['runs'][] = array(
						'provider'            => $this->provider( $run['provider'] ),
						'implementation'      => $this->identity( 'Implementation', $run['implementation'] ),
						'completed_at'        => gmdate( 'c', (int) $run['completed_at'] ),
						'expires_at'          => gmdate( 'c', (int) $run['expires_at'] ),
						'credential_selected' => $run['credential_selected'],
						'repository_selected' => $run['repository_selected'],
						'partial'             => $run['partial'],
						'partial_reason'      => $run['partial'] ? self::choice( $run['partial_reason'], self::PARTIAL ) : null,
						'results'             => self::safe_diagnostic_results( $run['results'] ),
					);
				}
			}
		}
		return array( array() === $data['coverage_reasons'] ? 'Included' : 'Partial', $data );
	}

	/**
	 * @return list<array{status:string,code:string,message:string,remediation:string}>
	 */
	private static function fresh_results( mixed $results ): array {
		if ( ! is_array( $results ) || ! array_is_list( $results ) ) {
			return array();
		}
		$safe = array();
		foreach ( $results as $result ) {
			if ( is_array( $result ) && is_string( $result['status'] ?? null ) && is_string( $result['code'] ?? null ) && is_string( $result['message'] ?? null ) && is_string( $result['remediation'] ?? null ) ) {
				$safe[] = array(
					'status'      => $result['status'],
					'code'        => $result['code'],
					'message'     => $result['message'],
					'remediation' => $result['remediation'],
				);
			}
		}
		return $safe;
	}

	/** @return array{string,array<string,mixed>} */
	private function activity(): array {
		$snapshot = $this->activity?->support_snapshot();
		if ( null === $snapshot || ! $snapshot['available'] ) {
			return array( 'Unavailable', array( 'message' => 'Activity history unavailable; unknown count.' ) );
		}
		$policy  = ActivityStore::retention_policy();
		$records = array();
		foreach ( $snapshot['records'] as $row ) {
			$records[] = array(
				'id'           => self::positive( $row['id'] ?? null ),
				'started_at'   => self::timestamp( $row['started_at'] ?? null ),
				'completed_at' => self::timestamp( $row['completed_at'] ?? null ),
				'correlation'  => self::hex( $row['correlation'] ?? null, 32 ),
				'provider'     => $this->provider( $row['provider'] ?? '' ),
				'subject'      => match ( $row['subject_type'] ?? null ) {
					'plugin', 'theme' => $this->identity( 'Package', $row['subject_identity'] ?? '' ),
					'repository' => $this->identity( 'Repository', $row['subject_identity'] ?? '' ),
					'provider' => $this->provider( $row['subject_identity'] ?? '' ),
					default => $this->identity( 'Subject', $row['subject_identity'] ?? '' ),
				},
				'operation'    => self::choice( $row['operation'] ?? null, self::OPERATIONS ),
				'status'       => self::choice( $row['status'] ?? null, array( 'incomplete', 'queued', 'running', 'succeeded', 'failed', 'needs_attention', 'rejected', 'skipped', 'resolved' ) ),
				'stage'        => self::choice( $row['stage'] ?? null, array( 'deployment.queued', 'deployment.running', 'deployment.reference_resolved', 'deployment.mutating', 'deployment.needs_attention', 'deployment.operator_reviewed' ) ),
				'effect'       => self::choice( $row['effect'] ?? null, array( 'none', 'established', 'invalidated' ) ),
				'reason'       => self::reason( $row['reason'] ?? null ),
			);
		}
		return array(
			$snapshot['truncated'] ? 'Truncated' : 'Included',
			array(
				'coverage'         => 'At most 100 recent retained Activity records, including recorded unresolved work; not lifetime totals or a complete deployment journal.',
				'retention_policy' => array(
					'maximum_rows' => $policy['maximum_rows'],
					'maximum_days' => $policy['maximum_days'],
				),
				'included_records' => count( $records ),
				'records'          => $records,
			),
		);
	}

	/** @return array{string,array<string,mixed>} */
	private function logging(): array {
		$snapshot = $this->capture?->snapshot();
		$state    = $snapshot['state'] ?? 'unavailable';
		if ( in_array( $state, array( 'malformed', 'unavailable' ), true ) ) {
			return array( 'Unavailable', array( 'message' => 'Existing Booster capture unavailable; no capture was started.' ) );
		}
		if ( 'inactive' === $state ) {
			return array( 'Not captured', array( 'message' => 'No available capture; never captured, deleted and expired cannot be distinguished. Explicitly start Logging, reproduce, then regenerate; prior events cannot be recovered.' ) );
		}
		$entries = is_array( $snapshot['entries'] ?? null ) ? $snapshot['entries'] : array();
		$records = array();
		$omitted = 0;
		foreach ( array_slice( $entries, 0, 400 ) as $entry ) {
			$line = is_array( $entry ) && is_string( $entry['line'] ?? null ) ? self::safe_log_data( $entry['line'] ) : null;
			if ( null === $line ) {
				++$omitted;
			} else {
				$records[] = array_merge( array( 'timestamp' => self::timestamp( $entry['at'] ?? null ) ), $line );
			}
		}
		return array(
			$omitted > 0 || count( $entries ) >= 400 ? 'Truncated' : 'Included',
			array(
				'coverage'                  => 'Booster events only; existing bounded capture may have dropped earlier events. Maximum 400 entries / 256 KiB; no exact lost count available.',
				'capture_state'             => self::choice( $state, array( 'active', 'retained' ) ),
				'active_until'              => self::timestamp( $snapshot['active_until'] ?? null ),
				'expires_at'                => self::timestamp( $snapshot['expires_at'] ?? null ),
				'entries_observed'          => count( $entries ),
				'omitted_by_sharing_policy' => $omitted,
				'entries'                   => $records,
			),
		);
	}

	public static function safe_log( string $line ): ?string {
		$data = self::safe_log_data( $line );
		if ( null === $data ) {
			return null;
		}
		if ( ! isset( $data['attempt'] ) ) {
			return $data['message'];
		}
		return $data['message'] . '; attempt=' . $data['attempt'] . '; correlation=' . $data['correlation'] . '; outcome=' . $data['outcome'];
	}

	/** @return array{message:string}|array{message:string,attempt:string,correlation:string,outcome:string}|null */
	private static function safe_log_data( string $line ): ?array {
		if ( strlen( $line ) > 4096 || preg_match( '//u', $line ) !== 1 ) {
			return null;
		}
		foreach ( self::LOG_MESSAGES as $message ) {
			$prefix = '[ran-booster] ' . $message;
			if ( $line === $prefix ) {
				return array( 'message' => $message );
			}
			if ( ! str_starts_with( $line, $prefix . ' {' ) ) {
				continue;
			}
			try {
				$context = json_decode( substr( $line, strlen( $prefix ) + 1 ), true, 4, JSON_THROW_ON_ERROR );
			} catch ( \JsonException ) {
				return null;
			}
			if ( ! is_array( $context ) ) {
				return null;
			}
			return array(
				'message'     => $message,
				'attempt'     => self::positive( $context['attempt_id'] ?? null ),
				'correlation' => self::hex( $context['correlation_id'] ?? null, 32 ),
				'outcome'     => self::reason( $context['outcome_code'] ?? null ),
			);
		}
		return null;
	}

	/** @param array<string,array{state:string,observed_at:string,data:array<mixed>}> $sections */
	private static function serialize( array $sections, float $started, float $ended ): string {
		$report = array(
			'schema_version'     => 1,
			'capture_started_at' => gmdate( 'c', (int) $started ),
			'capture_ended_at'   => gmdate( 'c', (int) $ended ),
			'coverage'           => 'Included',
			'observation'        => 'Bounded observation, not an atomic snapshot; existing expiry/security housekeeping may occur.',
			'sections'           => $sections,
		);
		foreach ( $sections as $section ) {
			if ( 'Included' !== $section['state'] ) {
				$report['coverage'] = 'Partial';
			}
		}
		$encode = static fn( array $value ): string => json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The fixed local schema requires deterministic JSON errors and UTF-8 substitution.
		$json   = $encode( $report );
		foreach ( array_reverse( array_keys( $sections ) ) as $name ) {
			if ( strlen( $json ) <= self::MAX_BYTES ) {
				break;
			}
			$report['coverage'] = 'Partial';
			if ( 'diagnostics' === $name && isset( $report['sections'][ $name ]['data']['coverage_reasons'] ) ) {
				$report['sections'][ $name ]['original_coverage_reasons'] = array_slice( array_unique( $report['sections'][ $name ]['data']['coverage_reasons'] ), 0, 32 );
			}
			$report['sections'][ $name ]['original_state'] = $report['sections'][ $name ]['state'];
			$report['sections'][ $name ]['state']          = 'Truncated';
			$report['sections'][ $name ]['data']           = array( 'reason' => 'output_byte_limit_exhausted' );
			$json = $encode( $report );
		}
		return $json;
	}

	/**
	 * @param array<string,string> $rows
	 * @return array<string,string>
	 */
	private static function environment_keys( array $rows ): array {
		$values = array();
		foreach ( $rows as $key => $value ) {
			$values[ strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', $key ) ) ] = $value;
		}
		return $values;
	}

	private function identity( string $kind, mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return $kind . ' unknown';
		}
		$this->identities[ $kind ]           ??= array();
		$this->identities[ $kind ][ $value ] ??= $kind . ' ' . ( count( $this->identities[ $kind ] ) + 1 );
		return $this->identities[ $kind ][ $value ];
	}

	private function provider( mixed $code ): string {
		return match ( $code ) {
			'gh' => 'GitHub',
			'bb' => 'Bitbucket Cloud',
			default => $this->identity( 'Provider', $code ),
		};
	}

	/** @param list<string> $allowed */
	private static function choice( mixed $value, array $allowed ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : 'Unknown';
	}

	private static function positive( mixed $value ): string {
		return ( is_int( $value ) || is_string( $value ) ) && preg_match( '/\A[1-9][0-9]{0,18}\z/', (string) $value ) === 1 ? (string) $value : 'Unknown';
	}

	private static function hex( mixed $value, int $length ): string {
		return is_string( $value ) && strlen( $value ) === $length && ctype_xdigit( $value ) ? $value : 'Unknown';
	}

	private static function timestamp( mixed $value ): string {
		return is_string( $value ) && preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}Z?\z/', $value ) === 1 ? $value . ( str_ends_with( $value, 'Z' ) ? '' : ' UTC' ) : 'Unknown';
	}

	private static function reason( mixed $value ): string {
		$codes = array_values( array_filter( ( new \ReflectionClass( DeploymentOutcome::class ) )->getConstants(), 'is_string' ) );
		return self::choice( $value, array_merge( $codes, array( 'diagnostics.passed', 'diagnostics.failed', 'diagnostics.partial', 'diagnostics.warning', 'diagnostics.not_configured', 'webhook.operation_succeeded', 'webhook.operation_failed', 'webhook.remote_unconfirmed', 'webhook.local_persistence_conflict', 'webhook.local_persistence_unconfirmed' ) ) );
	}

	private function now(): float {
		return null === $this->clock ? microtime( true ) : (float) ( $this->clock )();
	}
}
