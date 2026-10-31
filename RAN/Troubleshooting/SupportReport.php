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
			'Environment'      => fn(): array => array( 'Included', $this->pairs( $this->facts->environment() ) ),
			'Installation'     => fn(): array => $this->installation(),
			'Storage'          => fn(): array => $this->storage( $storage ),
			'Managed packages' => fn(): array => $this->packages(),
			'Diagnostics'      => fn(): array => $this->diagnostics( $fresh_diagnostics, $this->now() - $started < 5.0 ),
			'Activity'         => fn(): array => $this->activity(),
			'Logging'          => fn(): array => $this->logging(),
		) as $name => $collect ) {
			$at = $this->now();
			try {
				// Fresh diagnostics were already completed before passive serialization;
				// their safe projection must not be discarded by earlier collectors.
				[$state, $lines] = $at - $started >= 5.0 && ( 'Diagnostics' !== $name || array() === $fresh_diagnostics ) ? array( 'Not included', array( 'Collection budget exhausted; no operation was started.' ) ) : $collect();
			} catch ( Throwable ) {
				[$state, $lines] = array( 'Unavailable', array( 'Local evidence could not be read; no repair was attempted.' ) );
			}
			$sections[ $name ] = array(
				'state' => $state,
				'at'    => gmdate( 'c', (int) $at ),
				'lines' => $lines,
			);
		}
		$ended = $this->now();
		$text  = self::serialize( $sections, $started, $ended );
		return array(
			'text'     => $text,
			'filename' => 'ran-booster-support-' . gmdate( 'Ymd-His', (int) $started ) . '.txt',
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

	/** @return array{string,list<string>} */
	private function installation(): array {
		$core  = $this->facts->core();
		$lines = array( 'Booster: ' . InstallationFacts::version( $core['installed_version'] ?? null ), 'Local declarations do not verify publisher identity or remote access.' );
		foreach ( $this->facts->core_configuration() as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}
		$selection = $this->facts->github_selection();
		$lines[]   = 'GitHub selection at request bootstrap: ' . self::choice( $selection['state'], array( 'storage_unavailable', 'recovery_required', 'conflict', 'incompatible', 'missing', 'admitted', 'decision_required' ) );
		$lines[]   = 'GitHub admitted=' . ( $selection['admitted'] ? 'Yes' : 'No' ) . '; selected=' . self::choice( $selection['selected'], array( 'bundled', 'external', 'unknown' ) ) . '; version=' . InstallationFacts::version( $selection['version'] ) . '; provider API=' . ( $selection['provider_api'] > 0 && $selection['provider_api'] < 1000 ? (string) $selection['provider_api'] : 'Unknown' );
		$lines[]   = 'GitHub compatibility revision: ' . InstallationFacts::version( $selection['compatibility_revision'] );
		$lines     = array_merge( $lines, self::core_update_lines( $core ) );
		foreach ( $this->facts->components() as $component ) {
			$name  = $component['Name'] ?? '';
			$label = in_array( $name, array( 'GitHub', 'Bitbucket Cloud', 'WP Pusher Migrator' ), true ) ? $name : $this->identity( 'Component', $name );
			$parts = array( $label );
			foreach ( array( 'Installed', 'Active', 'Admission' ) as $key ) {
				if ( isset( $component[ $key ] ) ) {
					$parts[] = $key . '=' . self::choice( $component[ $key ], array( 'Yes', 'No', 'Admitted' ) );
				}
			}
			if ( isset( $component['Version'] ) ) {
				$parts[] = 'Version=' . InstallationFacts::version( $component['Version'] );
			}
			if ( isset( $component['Selection'] ) ) {
				$parts[] = 'Selection=' . self::choice( $component['Selection'], array( 'Selected locally', 'Not applicable' ) );
			}
			if ( isset( $component['Availability'] ) ) {
				$parts[] = self::choice( $component['Availability'], array( 'Available locally; remote access not checked' ) );
			}
			if ( isset( $component['Provider'] ) ) {
				$parts[] = $this->provider( $component['Provider'] );
				$parts[] = $this->identity( 'Implementation', $component['Implementation'] ?? '' );
				$parts[] = 'Archive API=' . self::choice( $component['Archive API'] ?? null, array( '1', '2' ) );
			}
			$lines[] = implode( '; ', $parts );
		}
		$truncated = $this->facts->components_truncated();
		if ( $truncated ) {
			$lines[] = 'Provider and add-on declarations beyond the first 32 are not included.';
		}
		return array( $truncated ? 'Truncated' : 'Included', $lines );
	}

	/**
	 * @param array<string,mixed> $core
	 * @return list<string>
	 */
	private static function core_update_lines( array $core ): array {
		$lines = array();
		foreach ( array( 'requested_mode', 'effective_mode', 'reason', 'updater_state' ) as $key ) {
			$lines[] = $key . ': ' . self::choice( $core[ $key ] ?? null, array( 'auto', 'enabled', 'disabled', 'invalid', 'configuration_enabled', 'configuration_disabled', 'configuration_invalid', 'preference_disabled', 'source_checkout', 'verified_release', 'release_marker_missing_or_invalid', 'active', 'inactive', 'unavailable', 'blocked' ) );
		}
		foreach ( array( 'offered_version', 'marker_version' ) as $key ) {
			$lines[] = $key . ': ' . InstallationFacts::version( $core[ $key ] ?? null );
		}
		$lines[] = 'marker_commit: ' . self::hex( $core['marker_commit'] ?? null, 40 );
		$lines[] = 'updater_code: ' . self::choice( $core['updater_code'] ?? null, array( 'native_discovery_disabled', 'diagnostics_unavailable', 'github_updater_status_unavailable', 'github_updater_runtime_environment_invalid' ) );
		foreach ( array( 'last_check', 'next_check' ) as $key ) {
			$value   = $core[ $key ] ?? null;
			$lines[] = $key . ': ' . ( is_int( $value ) && $value > 0 && $value <= 253402300799 ? gmdate( 'c', $value ) : 'Unknown; no new check performed' );
		}
		$lines[] = 'Recorded operator results and cooldown are separate from target last_check and next_check; no new check was performed.';
		foreach ( array( 'last_successful_check', 'check_cooldown_until' ) as $key ) {
			$value   = $core[ $key ] ?? null;
			$lines[] = $key . ': ' . ( is_int( $value ) && $value > 0 && $value <= 253402300799 ? gmdate( 'c', $value ) : 'Unknown' );
		}
		$lines[] = 'last_check_outcome: ' . self::choice( $core['last_check_outcome'] ?? null, array( 'success', 'failed', 'unknown', 'rejected', 'skipped' ) );
		$lines[] = 'last_check_code: ' . self::choice( $core['last_check_code'] ?? null, array( 'native_discovery_disabled', 'refresh_rejected', 'orchestration_failed', 'check_not_observed', 'check_succeeded', 'check_rejected', 'check_failed', 'claim_lost', 'unexpected_failure', 'runtime_identity_invalid', 'policy_disabled', 'policy_forced_off', 'filesystem_unavailable', 'discovery_not_admitted', 'candidate_not_eligible', 'offer_token_unavailable', 'update_available', 'release_list_failed', 'candidate_invalid', 'candidate_rejected', 'candidate_inspection_failed', 'candidate_descriptor_mismatch', 'candidate_validation_failed', 'no_candidates', 'no_newer_candidate', 'cooldown_active', 'claim_unavailable', 'target_unavailable' ) );
		return $lines;
	}

	/**
	 * @param array<string,mixed> $storage
	 * @return array{string,list<string>}
	 */
	private function storage( array $storage ): array {
		return array(
			'Included',
			array(
				'Location: site-owned encrypted credential sidecar; absolute location withheld',
				'State: ' . self::choice( $storage['state'] ?? null, array( 'actionable', 'configured', 'unknown', 'unavailable', 'healthy', 'not_configured' ) ),
				'Configuration source: ' . ( defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' ) || defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE' ) ? 'constant' : 'none' ),
				'Authenticated storage health: Not checked; no recovery or write probe performed',
				'Capacity: Not included; no filesystem scan performed',
			),
		);
	}

	/** @return array{string,list<string>} */
	private function packages(): array {
		$lines     = array( 'Coverage: bounded recorded management rows; counts describe this window, not the whole installation.' );
		$remaining = 100;
		$counts    = array();
		$complete  = true;
		$truncated = false;
		foreach ( array(
			'plugin' => $this->plugins,
			'theme'  => $this->themes,
		) as $type => $repository ) {
			$snapshot = $repository?->support_snapshot( $remaining );
			if ( null === $snapshot || ! $snapshot['available'] ) {
				$complete = false;
				$lines[]  = $type . ': Unavailable; unknown count';
				continue;
			}
			$truncated = $truncated || $snapshot['truncated'];
			$lines[]   = $type . ' included rows: ' . count( $snapshot['records'] );
			foreach ( $snapshot['records'] as $row ) {
				--$remaining;
				$provider         = $this->provider( $row['provider'] ?? '' );
				$policy           = self::choice( $row['deployment_policy'] ?? null, array( 'disabled', 'manual', 'automatic' ) );
				$group            = $type . '; ' . $provider . '; policy=' . $policy;
				$counts[ $group ] = ( $counts[ $group ] ?? 0 ) + 1;
				$lines[]          = implode(
					'; ',
					array(
						$type,
						$this->identity( 'Package', $row['package'] ?? '' ),
						$this->identity( 'Repository', $row['repository'] ?? '' ),
						$this->identity( 'Ref', $row['branch'] ?? '' ),
						$provider,
						'policy=' . $policy,
						'source=' . self::choice( $row['source'] ?? null, array( 'branch', 'release_asset' ) ),
					)
				);
			}
		}
		foreach ( $counts as $group => $count ) {
			$lines[] = 'Included count: ' . $group . '; rows=' . $count;
		}
		return array( $truncated ? 'Truncated' : ( $complete ? 'Included' : 'Unavailable' ), $lines );
	}

	/**
	 * @param array{state?:string,local?:list<array{status:string,code:string,message:string,remediation:string}>,providers?:array<string,list<array{status:string,code:string,message:string,remediation:string}>>,provider_states?:array<string,string>,omitted?:int} $fresh
	 * @return array{string,list<string>}
	 */
	private function diagnostics( array $fresh = array(), bool $include_retained = true ): array {
		$fresh_state = $fresh['state'] ?? 'unavailable';
		$fresh_coverage = 'completed' === $fresh_state ? 'Included' : ( 'unavailable' === $fresh_state ? 'Unavailable' : 'Partial' );
		$fresh_lines = array();
		if ( 'permission_skipped' === $fresh_state ) {
			$fresh_lines[] = 'Fresh basic checks not run: this administrator lacks one or more diagnostics capabilities.';
		} elseif ( in_array( $fresh_state, array( 'completed', 'local_incomplete' ), true ) ) {
			$fresh_lines[] = 'Fresh general checks: credential and repository selections were not used; provider prose is omitted.';
			foreach ( array( 'local' => $fresh['local'] ?? array() ) as $provider => $results ) {
				$fresh_lines[] = 'Group: local';
				$safe_results  = self::fresh_results( $results );
				$fresh_lines[] = 'Group state: included';
				foreach ( self::safe_diagnostic_results( $safe_results ) as $result ) {
					$fresh_lines[] = implode( ' | ', $result );
				}
			}
			foreach ( is_array( $fresh['providers'] ?? null ) ? $fresh['providers'] : array() as $provider => $results ) {
				$fresh_lines[] = 'Group: ' . ( 'local' === $provider ? 'local' : $this->provider( $provider ) );
				$safe_results  = self::fresh_results( $results );
				$state         = is_string( $fresh['provider_states'][ $provider ] ?? null ) ? $fresh['provider_states'][ $provider ] : 'included';
				$fresh_lines[] = 'Group state: ' . self::choice( $state, array( 'included', 'provider_unavailable', 'provider_results_invalid', 'deadline_exhausted', 'remote_calls_exhausted' ) );
				if ( array() === $safe_results && 'included' === $state ) {
					$fresh_lines[] = 'No diagnostic result was exported by this included group.';
				}
				foreach ( self::safe_diagnostic_results( $safe_results ) as $result ) {
					$fresh_lines[] = implode( ' | ', $result );
				}
				if ( count( $safe_results ) > 8 ) {
					$fresh_lines[] = 'Group state: Truncated after 8 reviewed results.';
				}
			}
			if ( 'local_incomplete' === $fresh_state ) {
				$fresh_lines[] = 'Provider checks not run because the fresh local observation was partial.';
			}
			if ( is_int( $fresh['omitted'] ?? null ) && $fresh['omitted'] > 0 ) {
				$fresh_lines[] = 'Provider groups not included because the report limit is 32: ' . $fresh['omitted'];
				$fresh_coverage = 'Truncated';
			}
			foreach ( $fresh['provider_states'] ?? array() as $state ) {
				if ( 'included' !== $state ) {
					$fresh_coverage = 'Partial';
				}
			}
		}
		if ( ! $include_retained ) {
			return array( 'Partial', array_merge( $fresh_lines, array( 'Earlier explicit diagnostic inputs were not read because the passive collection budget was exhausted.' ) ) );
		}
		$snapshot = $this->runs?->available();
		if ( null === $snapshot || ! $snapshot['available'] ) {
			return array( array() === $fresh_lines ? 'Unavailable' : $fresh_coverage, array_merge( $fresh_lines, array( 'Earlier explicit diagnostic inputs unavailable.' ) ) );
		}
		if ( array() === $snapshot['runs'] ) {
			return array( array() === $fresh_lines ? 'Not run' : $fresh_coverage, array_merge( $fresh_lines, array( 'No available unexpired earlier explicit run for this administrator and site.' ) ) );
		}
		$lines = array_merge( $fresh_lines, array( 'Earlier explicit checks, not newly executed tests. Provider prose is replaced by reviewed result-code descriptions.', 'Implementation labels identify recorded local observations, not verified continuity or checks of a current replacement.' ) );
		foreach ( $snapshot['runs'] as $run ) {
			$lines[] = $this->provider( $run['provider'] ) . '; ' . $this->identity( 'Implementation', $run['implementation'] ) . '; completed=' . gmdate( 'c', (int) $run['completed_at'] ) . '; expires=' . gmdate( 'c', (int) $run['expires_at'] );
			$lines[] = 'Scope: ' . ( $run['credential_selected'] ? 'one saved credential selected; identity withheld' : 'no saved credential selected' ) . '; ' . ( $run['repository_selected'] ? 'one repository selected; identity withheld' : 'no repository selected' );
			$lines[] = $run['partial'] ? 'Partial: ' . self::choice( $run['partial_reason'], self::PARTIAL ) : 'Completed; individual outcomes follow';
			foreach ( self::safe_diagnostic_results( $run['results'] ) as $result ) {
				$lines[] = implode( ' | ', $result );
			}
		}
		return array( array() === $fresh_lines ? 'Included' : $fresh_coverage, $lines );
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

	/** @return array{string,list<string>} */
	private function activity(): array {
		$snapshot = $this->activity?->support_snapshot();
		if ( null === $snapshot || ! $snapshot['available'] ) {
			return array( 'Unavailable', array( 'Activity history unavailable; unknown count.' ) );
		}
		$policy = ActivityStore::retention_policy();
		$lines  = array( 'Coverage: at most 100 recent retained Activity records, including recorded unresolved work; not lifetime totals or a complete deployment journal.', 'History policy rows=' . $policy['maximum_rows'] . '; days=' . $policy['maximum_days'], 'Included records: ' . count( $snapshot['records'] ) );
		foreach ( $snapshot['records'] as $row ) {
			$lines[] = implode(
				'; ',
				array(
					'ID=' . self::positive( $row['id'] ?? null ),
					self::timestamp( $row['started_at'] ?? null ),
					'completed=' . self::timestamp( $row['completed_at'] ?? null ),
					'correlation=' . self::hex( $row['correlation'] ?? null, 32 ),
					$this->provider( $row['provider'] ?? '' ),
					match ( $row['subject_type'] ?? null ) {
						'plugin', 'theme' => $this->identity( 'Package', $row['subject_identity'] ?? '' ),
						'repository' => $this->identity( 'Repository', $row['subject_identity'] ?? '' ),
						'provider' => $this->provider( $row['subject_identity'] ?? '' ),
						default => $this->identity( 'Subject', $row['subject_identity'] ?? '' ),
					},
					self::choice( $row['operation'] ?? null, self::OPERATIONS ),
					self::choice( $row['status'] ?? null, array( 'incomplete', 'queued', 'running', 'succeeded', 'failed', 'needs_attention', 'rejected', 'skipped', 'resolved' ) ),
					'stage=' . self::choice( $row['stage'] ?? null, array( 'deployment.queued', 'deployment.running', 'deployment.reference_resolved', 'deployment.mutating', 'deployment.needs_attention', 'deployment.operator_reviewed' ) ),
					'effect=' . self::choice( $row['effect'] ?? null, array( 'none', 'established', 'invalidated' ) ),
					self::reason( $row['reason'] ?? null ),
				)
			);
		}
		return array( $snapshot['truncated'] ? 'Truncated' : 'Included', $lines );
	}

	/** @return array{string,list<string>} */
	private function logging(): array {
		$snapshot = $this->capture?->snapshot();
		$state    = $snapshot['state'] ?? 'unavailable';
		if ( in_array( $state, array( 'malformed', 'unavailable' ), true ) ) {
			return array( 'Unavailable', array( 'Existing Booster capture unavailable; no capture was started.' ) );
		}
		if ( 'inactive' === $state ) {
			return array( 'Not captured', array( 'No available capture; never captured, deleted and expired cannot be distinguished. Explicitly start Logging, reproduce, then regenerate; prior events cannot be recovered.' ) );
		}
		$entries = is_array( $snapshot['entries'] ?? null ) ? $snapshot['entries'] : array();
		$lines   = array( 'Booster events only; existing bounded capture may have dropped earlier events. Maximum 400 entries / 256 KiB; no exact lost count available.', 'Capture state: ' . self::choice( $state, array( 'active', 'retained' ) ), 'Active until: ' . self::timestamp( $snapshot['active_until'] ?? null ), 'Expires: ' . self::timestamp( $snapshot['expires_at'] ?? null ) );
		$omitted = 0;
		foreach ( array_slice( $entries, 0, 400 ) as $entry ) {
			$line = is_array( $entry ) && is_string( $entry['line'] ?? null ) ? self::safe_log( $entry['line'] ) : null;
			if ( null === $line ) {
				++$omitted;
			} else {
				$lines[] = self::timestamp( $entry['at'] ?? null ) . ' ' . $line;
			}
		}
		$lines[] = 'Captured entries observed=' . count( $entries ) . '; omitted by sharing policy=' . $omitted;
		return array( $omitted > 0 || count( $entries ) >= 400 ? 'Truncated' : 'Included', $lines );
	}

	public static function safe_log( string $line ): ?string {
		if ( strlen( $line ) > 4096 || preg_match( '//u', $line ) !== 1 ) {
			return null;
		}
		foreach ( self::LOG_MESSAGES as $message ) {
			$prefix = '[ran-booster] ' . $message;
			if ( $line === $prefix ) {
				return $message;
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
			return $message . '; attempt=' . self::positive( $context['attempt_id'] ?? null ) . '; correlation=' . self::hex( $context['correlation_id'] ?? null, 32 ) . '; outcome=' . self::reason( $context['outcome_code'] ?? null );
		}
		return null;
	}

	/** @param array<string,array{state:string,at:string,lines:list<string>}> $sections */
	private static function serialize( array $sections, float $started, float $ended ): string {
		$manifest   = array( 'RAN Booster support report; format=1', 'Capture started UTC: ' . gmdate( 'c', (int) $started ), 'Capture ended UTC: ' . gmdate( 'c', (int) $ended ), 'Bounded observation, not an atomic snapshot. Existing expiry/security housekeeping may occur.' );
		$manifest[] = 'Core version/provenance coverage: ' . $sections['Installation']['state'];
		foreach ( $sections['Installation']['lines'] as $line ) {
			if ( str_starts_with( $line, 'Booster: ' ) || str_starts_with( $line, 'reason: ' ) || str_starts_with( $line, 'marker_commit: ' ) ) {
				$manifest[] = $line;
			}
		}
		$partial = false;
		$budget  = self::MAX_BYTES - 8192;
		$body    = '';
		foreach ( $sections as $name => $section ) {
			$value = implode( "\n", $section['lines'] );
			if ( strlen( $value ) > $budget ) {
				$value = substr( $value, 0, max( 0, $budget - 64 ) );
				while ( '' !== $value && preg_match( '//u', $value ) !== 1 ) {
					$value = substr( $value, 0, -1 );
				}
				$value           .= "\nOutput ceiling reached; remaining details omitted.";
				$section['state'] = 'Truncated';
			}
			$budget     = max( 0, $budget - strlen( $value ) - 128 );
			$partial    = $partial || 'Included' !== $section['state'];
			$manifest[] = $name . ': ' . $section['state'] . '; observed=' . $section['at'];
			$body      .= "\n\n" . $name . ' [' . $section['state'] . "]\n" . $value;
		}
		$manifest[] = 'Coverage: ' . ( $partial ? 'Partial' : 'Included bounded sections' );
		return implode( "\n", $manifest ) . $body;
	}

	/**
	 * @param array<string,string> $rows
	 * @return list<string>
	 */
	private function pairs( array $rows ): array {
		$lines = array();
		foreach ( $rows as $key => $value ) {
			$lines[] = $key . ': ' . $value;
		}
		return $lines;
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
