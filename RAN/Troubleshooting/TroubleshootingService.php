<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use Closure;
use RAN\Activity\ActivityRequest;
use RAN\Activity\ActivityStore;
use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticBudgetExceeded;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnosticResult;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;

/**
 * Runs one bounded, selected-provider troubleshooting operation.
 */
final class TroubleshootingService {

	private const MAX_RESULTS = 8;

	private const PARTIAL_PRIORITY = array(
		'local_incomplete'         => 0,
		'deadline_exhausted'       => 1,
		'remote_calls_exhausted'   => 2,
		'provider_unavailable'     => 3,
		'provider_results_invalid' => 4,
		'result_limit_exhausted'   => 5,
	);

	public function __construct(
		private readonly LocalTroubleshootingService $local,
		private readonly ProviderRegistry $providers,
		private readonly ?Closure $clock = null,
		private readonly ?SecretsFile $secrets = null,
		private readonly ?CoreSelfUpdateStatus $core_self_update = null,
		private readonly ?ActivityStore $activity = null,
		private readonly ?DiagnosticRunStore $retained_runs = null,
		private readonly ?SupportReport $support_report = null,
		private readonly ?InstallationFacts $installation_facts = null
	) {
	}

	/**
	 * Standing facts never enumerate credential documents or execute checks.
	 * @param array<string,mixed>|null $selection
	 * @param array<string,mixed> $storage
	 * @return array<string,mixed>
	 */
	public function installation_payload( ?array $selection, array $storage ): array {
		$facts   = $this->installation_facts ?? new InstallationFacts( $this->providers, $this->core_self_update );
		$payload = array(
			'providers'              => array(),
			'provider_locator_hints' => array(),
			'credentials'            => array(),
			'selected_provider'      => '',
			'credential_id'          => '',
			'repository'             => '',
			'ran'                    => false,
			'results'                => array(),
			'partial'                => false,
			'partial_reason'         => null,
			'report'                 => '',
			'core_self_update'       => $facts->core(),
		);
		$payload = $this->with_installation_context( $payload, $selection );
		unset( $payload['installation_details'][ __( 'Diagnostic Git provider', 'ran-booster' ) ] );
		$payload['environment']          = $facts->environment();
		$payload['storage']              = $storage;
		$payload['components']           = $facts->components();
		$payload['components_truncated'] = $facts->components_truncated();
		return $payload;
	}

	/**
	 * @param array<string,mixed> $storage
	 * @return array{text:string,filename:string}
	 */
	public function generate_support_report( array $storage ): array {
		if ( null === $this->support_report ) {
			return array(
				'text'     => __( 'Support collection is unavailable. No diagnostics were run.', 'ran-booster' ),
				'filename' => 'ran-booster-support-' . gmdate( 'Ymd-His' ) . '.txt',
			);
		}
		return $this->support_report->generate( $storage, $this->fresh_support_diagnostics() );
	}

	/**
	 * Report generation may make one bounded, credential-free diagnostic sweep.
	 * It has no Activity or retained-run side effects.
	 *
	 * @return array{state:string,local:list<array{status:string,code:string,message:string,remediation:string}>,providers:array<string,list<array{status:string,code:string,message:string,remediation:string}>>,provider_states:array<string,string>,omitted:int}
	 */
	private function fresh_support_diagnostics(): array {
		if ( ! $this->can_generate_active_support_diagnostics() ) {
			return array(
				'state'           => 'permission_skipped',
				'local'           => array(),
				'providers'       => array(),
				'provider_states' => array(),
				'omitted'         => 0,
			);
		}
		$started       = $this->now();
		$local         = $this->local->diagnose_for_support_report();
		$local_results = array_map( static fn( ProviderDiagnosticResult $result ): array => $result->to_array(), $local['results'] );
		if ( $local['partial'] ) {
			return array(
				'state'           => 'local_incomplete',
				'local'           => $local_results,
				'providers'       => array(),
				'provider_states' => array(),
				'omitted'         => 0,
			);
		}
		$claims        = 0;
		$groups        = array();
		$states        = array();
		$metadata_rows = $this->providers->ordered_metadata();
		foreach ( array_slice( $metadata_rows, 0, 32 ) as $metadata ) {
			if ( $claims >= 20 || $this->now() - $started >= 20.0 ) {
				$groups[ $metadata->code->value ] = array();
				$states[ $metadata->code->value ] = $claims >= 20 ? 'remote_calls_exhausted' : 'deadline_exhausted';
				continue;
			}
			$request = null;
			try {
				$request                          = new ProviderDiagnosticRequest( null, null, min( ProviderDiagnosticRequest::MAX_REMOTE_CALLS, 20 - $claims ), min( ProviderDiagnosticRequest::MAX_SECONDS, max( 0.001, 20.0 - ( $this->now() - $started ) ) ), $this->clock );
				$results                          = $this->providers->get( $metadata->code )->get_provider_diagnostics()->diagnose( $request );
				$valid                            = array_values( array_filter( $results, static fn( mixed $result ): bool => $result instanceof ProviderDiagnosticResult ) );
				$groups[ $metadata->code->value ] = array_map( static fn( ProviderDiagnosticResult $result ): array => $result->to_array(), $valid );
				$states[ $metadata->code->value ] = $this->now() - $started >= 20.0 ? 'deadline_exhausted' : ( count( $valid ) === count( $results ) ? 'included' : 'provider_results_invalid' );
			} catch ( \Throwable ) {
				$groups[ $metadata->code->value ] = array();
				$states[ $metadata->code->value ] = 'provider_unavailable';
			} finally {
				$claims += $request?->get_remote_calls() ?? 0;
			}
		}
		return array(
			'state'           => 'completed',
			'local'           => $local_results,
			'providers'       => $groups,
			'provider_states' => $states,
			'omitted'         => max( 0, count( $metadata_rows ) - 32 ),
		);
	}

	private function can_generate_active_support_diagnostics(): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}
		foreach ( array( 'manage_options', 'install_plugins', 'update_plugins', 'install_themes', 'update_themes' ) as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return false;
			}
		}
		return true;
	}

	private function now(): float {
		return null === $this->clock ? hrtime( true ) / 1_000_000_000 : ( $this->clock )();
	}

	/**
	 * Build the read-only GET payload from provider metadata and safe credential labels.
	 *
	 * @return array{providers: array<string, string>, provider_locator_hints: array<string, string>, credentials: array<string, list<array{id: string, label: string}>>, selected_provider: string, credential_id: string, repository: string, ran: bool, results: list<array{status: string, code: string, message: string, remediation: string}>, partial: bool, partial_reason: string|null, report: string, core_self_update: array<string, mixed>, retention_available?: bool}
	 */

	public function form_payload(): array {
		$options = $this->provider_options();

		return $this->payload(
			array_key_first( $options ) ?? '',
			null,
			null,
			array(),
			null,
			false,
			$options
		);
	}

	/**
	 * Add one captured, passive installation snapshot to both display and support report.
	 *
	 * @param array{providers:array<string,string>,provider_locator_hints:array<string,string>,credentials:array<string,list<array{id:string,label:string}>>,selected_provider:string,credential_id:string,repository:string,ran:bool,results:list<array{status:string,code:string,message:string,remediation:string}>,partial:bool,partial_reason:?string,report:string,core_self_update:array<string,mixed>,retention_available?:bool} $payload
	 * @param array<string,mixed>|null $github_selection
	 * @return array{providers:array<string,string>,provider_locator_hints:array<string,string>,credentials:array<string,list<array{id:string,label:string}>>,selected_provider:string,credential_id:string,repository:string,ran:bool,results:list<array{status:string,code:string,message:string,remediation:string}>,partial:bool,partial_reason:?string,report:string,core_self_update:array<string,mixed>,installation_details:array<string,array<string,string>>}
	 */
	public function with_installation_context( array $payload, ?array $github_selection ): array {
		$selected = $payload['selected_provider'];
		$current  = is_array( $github_selection['current'] ?? null ) ? $github_selection['current'] : array();
		$source   = match ( true ) {
			'bundled' === ( $current['selected'] ?? null ) => __( 'Bundled with Booster', 'ran-booster' ),
			is_array( $current['declaration'] ?? null ) => __( 'External replacement', 'ran-booster' ),
			default => __( 'Unknown', 'ran-booster' ),
		};
		$sections = array(
			__( 'Diagnostic Git provider', 'ran-booster' ) => array(
				__( 'Git provider', 'ran-booster' )      => $this->installation_text( $payload['providers'][ $selected ] ?? null ),
				__( 'Git provider code', 'ran-booster' ) => $this->installation_text( $selected ),
			),
			__( 'GitHub provider', 'ran-booster' )         => array(
				__( 'Source', 'ran-booster' )          => $source,
				__( 'Selection state', 'ran-booster' ) => $this->installation_text( $github_selection['state'] ?? null ),
				__( 'Operational availability', 'ran-booster' ) => match ( $github_selection['available'] ?? null ) {
					true => __( 'Available', 'ran-booster' ),
					false => __( 'Unavailable', 'ran-booster' ),
					default => __( 'Unknown', 'ran-booster' ),
				},
			) + $this->installation_declaration( $current['declaration'] ?? null ),
		);
		$candidates = is_array( $github_selection['candidates'] ?? null ) ? $github_selection['candidates'] : array();
		foreach ( array_values( array_slice( $candidates, 0, 4 ) ) as $index => $candidate ) {
			$sections[ sprintf( /* translators: %d: enabled replacement candidate number. */ __( 'GitHub candidate %d', 'ran-booster' ), $index + 1 ) ] = $this->installation_declaration( $candidate );
		}
		$sections[ __( 'GitHub provider', 'ran-booster' ) ][ __( 'Enabled candidates', 'ran-booster' ) ] = (string) count( $candidates );
		$core        = $payload['core_self_update'];
		$core_fields = array(
			'installed_version' => __( 'Installed version', 'ran-booster' ),
			'requested_mode'    => __( 'Requested mode', 'ran-booster' ),
			'effective_mode'    => __( 'Effective mode', 'ran-booster' ),
			'reason'            => __( 'Policy reason', 'ran-booster' ),
			'updater_state'     => __( 'Updater state', 'ran-booster' ),
			'updater_code'      => __( 'Updater code', 'ran-booster' ),
			'offered_version'   => __( 'Offered version', 'ran-booster' ),
			'marker_version'    => __( 'Marker version', 'ran-booster' ),
			'marker_commit'     => __( 'Marker commit', 'ran-booster' ),
		);
		foreach ( $core_fields as $key => $label ) {
			$sections[ __( 'Booster Core updates', 'ran-booster' ) ][ $label ] = $this->installation_text( $core[ $key ] ?? null );
		}
		foreach ( array(
			'last_check' => __( 'Last check (UTC)', 'ran-booster' ),
			'next_check' => __( 'Next check (UTC)', 'ran-booster' ),
		) as $key => $label ) {
			$timestamp = $core[ $key ] ?? null;
			$sections[ __( 'Booster Core updates', 'ran-booster' ) ][ $label ] = is_int( $timestamp ) && $timestamp >= 0 && $timestamp <= 253402300799 ? gmdate( 'c', $timestamp ) : __( 'Unavailable', 'ran-booster' );
		}
		foreach ( array(
			'last_successful_check' => __( 'Last successful operator check (UTC)', 'ran-booster' ),
			'check_cooldown_until'  => __( 'Operator check cooldown until (UTC)', 'ran-booster' ),
		) as $key => $label ) {
			$timestamp = $core[ $key ] ?? null;
			$sections[ __( 'Booster Core updates', 'ran-booster' ) ][ $label ] = is_int( $timestamp ) && $timestamp > 0 && $timestamp <= 253402300799 ? gmdate( 'c', $timestamp ) : __( 'Unavailable', 'ran-booster' );
		}
		$outcome = $core['last_check_outcome'] ?? null;
		$code    = $core['last_check_code'] ?? null;
		$sections[ __( 'Booster Core updates', 'ran-booster' ) ][ __( 'Last operator check outcome', 'ran-booster' ) ] = is_string( $outcome ) && in_array( $outcome, array( 'success', 'failed', 'unknown', 'rejected', 'skipped' ), true ) ? $outcome : __( 'Unavailable', 'ran-booster' );
		$sections[ __( 'Booster Core updates', 'ran-booster' ) ][ __( 'Last operator check code', 'ran-booster' ) ]    = is_string( $code ) && strlen( $code ) <= 64 && preg_match( '/\A[a-z]+(?:_[a-z]+)*\z/', $code ) === 1 ? $code : __( 'Unavailable', 'ran-booster' );
		$payload['installation_details'] = $sections;
		$lines                           = array( 'RAN Booster installation context', __( 'Local declarations only; these claims do not verify publisher identity or remote access.', 'ran-booster' ) );
		foreach ( $sections as $heading => $rows ) {
			$lines[] = '';
			$lines[] = $heading;
			foreach ( $rows as $label => $value ) {
				$lines[] = $label . ': ' . $value;
			}
		}
		$lines[]           = '';
		$lines[]           = __( 'Diagnostic checks', 'ran-booster' );
		$lines[]           = $payload['ran'] ? $this->report( $payload['results'], $payload['partial'], $payload['partial_reason'] ) : __( 'Not run', 'ran-booster' );
		$payload['report'] = implode( "\n", $lines );
		return $payload;
	}

	/** @return array<string,string> */
	private function installation_declaration( mixed $declaration ): array {
		$declaration = is_array( $declaration ) ? $declaration : array();
		$rows        = array();
		foreach ( array(
			'display_name'           => __( 'Name', 'ran-booster' ),
			'display_version'        => __( 'Version', 'ran-booster' ),
			'implementation_id'      => __( 'Implementation', 'ran-booster' ),
			'provider_api_version'   => __( 'Provider API', 'ran-booster' ),
			'compatibility_revision' => __( 'Compatibility revision', 'ran-booster' ),
			'webhook_signing_format' => __( 'Webhook signing format', 'ran-booster' ),
		) as $key => $label ) {
			$value          = $declaration[ $key ] ?? null;
			$rows[ $label ] = $this->installation_text( 'provider_api_version' === $key ? ( is_int( $value ) && $value >= 0 && $value <= 999 ? (string) $value : null ) : $value );
		}
		foreach ( array(
			/* translators: %d: locally declared capability number. */
			'facets'                 => __( 'Capability %d', 'ran-booster' ),
			/* translators: %d: locally declared credential format number. */
			'api_credential_formats' => __( 'API credential format %d', 'ran-booster' ),
		) as $key => $label ) {
			$values = $declaration[ $key ] ?? null;
			if ( is_array( $values ) && array_is_list( $values ) && count( $values ) <= 32 ) {
				foreach ( $values as $index => $value ) {
					$rows[ sprintf( $label, $index + 1 ) ] = $this->installation_text( $value );
				}
			}
		}
		return $rows;
	}

	private function installation_text( mixed $value ): string {
		if ( is_string( $value ) && strlen( $value ) <= 256 ) {
			try {
				return ( new ProviderDiagnosticResult( 'pass', 'local.installation', $value, 'No action required.' ) )->message;
			} catch ( \InvalidArgumentException ) {
				return __( 'Unavailable', 'ran-booster' );
			}
		}
		return __( 'Unavailable', 'ran-booster' );
	}

	/**
	 * Run local checks and exactly one selected provider in the current request.
	 *
	 * @return array{providers: array<string, string>, provider_locator_hints: array<string, string>, credentials: array<string, list<array{id: string, label: string}>>, selected_provider: string, credential_id: string, repository: string, ran: bool, results: list<array{status: string, code: string, message: string, remediation: string}>, partial: bool, partial_reason: string|null, report: string, core_self_update: array<string, mixed>, retention_available?: bool}
	 */

	public function diagnose( string $provider, ?string $credential_id, ?string $repository ): array {
		$retention_scope = $this->retained_runs?->scope_key();
		$id              = null;
		$resolved        = null;
		if ( null !== $this->activity ) {
			try {
				$resolved = $this->providers->metadata()[ ProviderCode::parse( $provider )->value ] ?? null;
			} catch ( \InvalidArgumentException ) {
				$resolved = null;
			}
		}
		if ( null !== $this->activity && null !== $resolved ) {
			$code = $resolved->code->value;
			$id   = $this->activity->begin(
				$code,
				new ActivityRequest( 'diagnostics.run' ),
				array(
					'subject_type'     => 'provider',
					'subject_identity' => $code,
					'adoption_token'   => null,
					'context'          => '',
					'purpose'          => null,
					'provenance'       => 'core',
					'actor'            => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				)
			);
		}
		$implementation = null;
		if ( null !== $this->retained_runs && isset( $this->providers->metadata()[ $provider ] ) ) {
			try {
				$implementation = ( $this->installation_facts ?? new InstallationFacts( $this->providers ) )->implementation( $provider );
			} catch ( \Throwable ) {
				$implementation = null;
			}
		}
		$payload   = $this->run_diagnostics( $provider, $credential_id, $repository );
		$completed = microtime( true );
		if ( null !== $this->retained_runs && isset( $this->providers->metadata()[ $provider ] ) ) {
			try {
				$retained = null !== $implementation && null !== $retention_scope && $this->retained_runs->save(
					$provider,
					array(
						'completed_at'        => $completed,
						'implementation'      => $implementation,
						'credential_selected' => null !== $credential_id,
						'repository_selected' => null !== $repository,
						'partial'             => $payload['partial'],
						'partial_reason'      => $payload['partial_reason'],
						'results'             => SupportReport::safe_diagnostic_results( $payload['results'] ),
					),
					$retention_scope
				);
			} catch ( \Throwable ) {
				$retained = false;
			}
			$payload['retention_available'] = $retained;
		}
		$this->record_outcome( $id, $payload );
		return $payload;
	}

	/** @param array{results: list<array{status: string}>, partial_reason: string|null} $payload */
	private function record_outcome( ?int $id, array $payload ): void {
		if ( null === $this->activity || null === $id ) {
			return;
		}
		$counts = array(
			'pass'           => 0,
			'warning'        => 0,
			'fail'           => 0,
			'not_configured' => 0,
		);
		foreach ( $payload['results'] as $result ) {
			if ( isset( $counts[ $result['status'] ] ) ) {
				++$counts[ $result['status'] ];
			}
		}
		$partial           = $payload['partial_reason'];
		[$status, $reason] = match ( true ) {
			null !== $partial => array( 'failed', 'diagnostics.partial' ),
			$counts['fail'] > 0 => array( 'failed', 'diagnostics.failed' ),
			$counts['warning'] > 0 => array( 'rejected', 'diagnostics.warning' ),
			$counts['not_configured'] > 0 => array( 'skipped', 'diagnostics.not_configured' ),
			default => array( 'succeeded', 'diagnostics.passed' ),
		};
		$this->activity->complete(
			$id,
			$status,
			$reason,
			'',
			'',
			array(
				'diagnostic_passed'         => $counts['pass'],
				'diagnostic_warning'        => $counts['warning'],
				'diagnostic_failed'         => $counts['fail'],
				'diagnostic_not_configured' => $counts['not_configured'],
				'partial_reason'            => $partial,
			)
		);
	}

	/** @return array{providers: array<string, string>, provider_locator_hints: array<string, string>, credentials: array<string, list<array{id: string, label: string}>>, selected_provider: string, credential_id: string, repository: string, ran: bool, results: list<array{status: string, code: string, message: string, remediation: string}>, partial: bool, partial_reason: string|null, report: string, core_self_update: array<string, mixed>, retention_available?: bool} */
	private function run_diagnostics( string $provider, ?string $credential_id, ?string $repository ): array {
		$input_invalid = false;
		try {
			$request = new ProviderDiagnosticRequest(
				$credential_id,
				$repository,
				ProviderDiagnosticRequest::MAX_REMOTE_CALLS,
				ProviderDiagnosticRequest::MAX_SECONDS,
				$this->clock
			);
		} catch ( \Throwable ) {
			$request = new ProviderDiagnosticRequest( null, null, ProviderDiagnosticRequest::MAX_REMOTE_CALLS, ProviderDiagnosticRequest::MAX_SECONDS, $this->clock );

			$credential_id = null;
			$repository    = null;
			$input_invalid = true;
		}

		$options       = $this->provider_options();
		$local_payload = $this->local->diagnose();
		$results       = $local_payload['results'];
		$partial       = null;

		if ( $local_payload['partial'] ) {
			$safe_provider = isset( $options[ $provider ] ) ? $provider : '';

			return $this->payload( $safe_provider, $credential_id, $repository, $results, 'local_incomplete', true, $options );
		}

		if ( $request->remaining_seconds() <= 0.0 ) {
			$safe_provider = isset( $options[ $provider ] ) ? $provider : '';

			return $this->payload( $safe_provider, $credential_id, $repository, $results, 'deadline_exhausted', true, $options );
		}

		if ( $input_invalid ) {
			$safe_provider = isset( $options[ $provider ] ) ? $provider : '';

			return $this->payload( $safe_provider, null, null, $results, 'provider_results_invalid', true, $options );
		}

		try {
			$provider_code = ProviderCode::parse( $provider );
			$aggregate     = $this->providers->get( $provider_code );
		} catch ( \Throwable ) {

			return $this->payload( '', $credential_id, $repository, $results, 'provider_unavailable', true, $options );
		}

		try {
			$provider_results = $aggregate->get_provider_diagnostics()->diagnose( $request );
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			$reason = ProviderDiagnosticBudgetExceeded::DEADLINE === $exception->get_reason()
				? 'deadline_exhausted'
				: 'remote_calls_exhausted';

			return $this->payload( $provider_code->value, $credential_id, $repository, $results, $reason, true, $options );
		} catch ( \Throwable $exception ) {
			BoosterLogger::log_exception(
				'provider diagnostic operation failed',
				$exception,
				array(
					'provider' => $provider_code->value,
					'step'     => 'provider_diagnostics',
				)
			);
			$reason = $this->budget_partial_reason( $request ) ?? 'provider_unavailable';

			return $this->payload( $provider_code->value, $credential_id, $repository, $results, $reason, true, $options );
		}

		if ( $request->remaining_seconds() <= 0.0 ) {

			return $this->payload( $provider_code->value, $credential_id, $repository, $results, 'deadline_exhausted', true, $options );
		}

		$budget_partial = $this->budget_partial_reason( $request );
		if ( null !== $budget_partial ) {
			$partial = $this->higher_priority( $partial, $budget_partial );
		}

		$remaining = self::MAX_RESULTS - count( $results );
		if ( count( $provider_results ) > $remaining ) {
			$partial = $this->higher_priority( $partial, 'result_limit_exhausted' );
		}

		$seen = array_fill_keys(
			array_map( static fn( ProviderDiagnosticResult $result ): string => $result->code, $results ),
			true
		);
		foreach ( $provider_results as $result ) {
			if ( count( $results ) >= self::MAX_RESULTS ) {
				break;
			}

			if ( ! $this->valid_provider_result( $result, $provider_code, $seen ) ) {
				$partial = $this->higher_priority( $partial, 'provider_results_invalid' );
				break;
			}
			$this->record_provider_failure( $result, $provider_code, 'provider_diagnostics' );

			$seen[ $result->code ] = true;
			$results[]             = $result;
		}

		if ( count( $results ) < self::MAX_RESULTS
			&& $aggregate instanceof WebhookNormalizer
			&& ! in_array( $partial, array( 'provider_unavailable', 'provider_results_invalid' ), true )
		) {
			if ( $request->remaining_seconds() <= 0.0 ) {
				$partial = $this->higher_priority( $partial, 'deadline_exhausted' );
			} else {
				try {
					$readiness = $aggregate->diagnose_webhook_readiness();
					if ( $this->valid_provider_result( $readiness, $provider_code, $seen ) ) {
						$this->record_provider_failure( $readiness, $provider_code, 'provider_webhook_readiness' );
						$results[] = $readiness;
					} else {
						$partial = $this->higher_priority( $partial, 'provider_results_invalid' );
					}
				} catch ( \Throwable $exception ) {
					BoosterLogger::log_exception(
						'provider diagnostic operation failed',
						$exception,
						array(
							'provider' => $provider_code->value,
							'step'     => 'provider_webhook_readiness',
						)
					);
					$partial = $this->higher_priority( $partial, 'provider_unavailable' );
				}
			}
		}

		if ( $request->remaining_seconds() <= 0.0 ) {
			$partial = $this->higher_priority( $partial, 'deadline_exhausted' );
		}

		return $this->payload(
			$provider_code->value,
			$credential_id,
			$repository,
			$results,
			$partial,
			true,
			$options
		);
	}

	private function record_provider_failure( ProviderDiagnosticResult $result, ProviderCode $provider, string $step ): void {
		if ( null === $result->failure ) {
			return;
		}

		BoosterLogger::log_exception(
			'provider diagnostic operation failed',
			$result->failure,
			array(
				'provider' => $provider->value,
				'step'     => $step,
			)
		);
	}

	/** @return array<string, string> */
	private function provider_options(): array {
		$options = array();

		foreach ( $this->providers->ordered_metadata() as $metadata ) {
			$options[ $metadata->code->value ] = $metadata->label;
		}

		return $options;
	}

	/** @return array<string, string> */
	private function provider_locator_hints(): array {
		$hints = array();
		foreach ( $this->providers->ordered_metadata() as $metadata ) {
			$hints[ $metadata->code->value ] = $metadata->admin->repository_locator_hint ?? '';
		}

		return $hints;
	}

	/**
	 * Return only the saved credential details that are safe to render in a form.
	 *
	 * Reading these labels lets an administrator select a credential without
	 * entering its internal identifier. Configuration and secret material never
	 * enter the troubleshooting payload.
	 *
	 * @param array<string, string> $providers
	 * @return array<string, list<array{id: string, label: string}>>
	 */
	private function credential_choices( array $providers ): array {
		$choices = array();

		foreach ( array_keys( $providers ) as $provider ) {
			$choices[ $provider ] = array();
			if ( null === $this->secrets ) {
				continue;
			}

			try {
				$profiles = $this->secrets->credential_profiles( $provider );
			} catch ( \Throwable ) {
				continue;
			}

			foreach ( $profiles as $profile ) {
				$id    = is_string( $profile['id'] ?? null ) ? $profile['id'] : '';
				$label = is_string( $profile['label'] ?? null ) ? $profile['label'] : '';
				if ( '' === $id || '' === $label ) {
					continue;
				}

				$choices[ $provider ][] = array(
					'id'    => $id,
					'label' => $label,
				);
			}
		}

		return $choices;
	}

	/** @param array<string, bool> $seen */
	private function valid_provider_result( mixed $result, ProviderCode $provider, array $seen ): bool {
		return $result instanceof ProviderDiagnosticResult
			&& str_starts_with( $result->code, $provider->value . '.' )
			&& ! isset( $seen[ $result->code ] );
	}

	private function higher_priority( ?string $current, string $candidate ): string {
		if ( null === $current ) {
			return $candidate;
		}

		return self::PARTIAL_PRIORITY[ $candidate ] < self::PARTIAL_PRIORITY[ $current ]
			? $candidate
			: $current;
	}

	private function budget_partial_reason( ProviderDiagnosticRequest $request ): ?string {
		return match ( $request->get_exhaustion_reason() ) {
			ProviderDiagnosticBudgetExceeded::DEADLINE     => 'deadline_exhausted',
			ProviderDiagnosticBudgetExceeded::REMOTE_CALLS => 'remote_calls_exhausted',
			default                                        => null,
		};
	}

	/**
	 * @param list<ProviderDiagnosticResult> $results
	 * @param array<string, string>           $providers
	 * @return array{providers: array<string, string>, provider_locator_hints: array<string, string>, credentials: array<string, list<array{id: string, label: string}>>, selected_provider: string, credential_id: string, repository: string, ran: bool, results: list<array{status: string, code: string, message: string, remediation: string}>, partial: bool, partial_reason: string|null, report: string, core_self_update: array<string, mixed>, retention_available?: bool}
	 */
	private function payload(
		string $provider,
		?string $credential_id,
		?string $repository,
		array $results,
		?string $partial_reason,
		bool $ran,
		array $providers
	): array {
		$display_results = array_map(
			static fn( ProviderDiagnosticResult $result ): array => $result->to_array(),
			$results
		);
		$partial         = null !== $partial_reason;

		return array(
			'providers'              => $providers,
			'provider_locator_hints' => $this->provider_locator_hints(),
			'credentials'            => $this->credential_choices( $providers ),
			'selected_provider'      => $provider,
			'credential_id'          => $credential_id ?? '',
			'repository'             => $repository ?? '',
			'ran'                    => $ran,
			'results'                => $display_results,
			'partial'                => $partial,
			'partial_reason'         => $partial_reason,
			'report'                 => $ran ? $this->report( $display_results, $partial, $partial_reason ) : '',

			'core_self_update'       => null === $this->core_self_update
				? array()

				: $this->core_self_update->diagnostics(),
		);
	}

	/**
	 * @param list<array{status: string, code: string, message: string, remediation: string}> $results
	 */
	private function report( array $results, bool $partial, ?string $partial_reason ): string {
		$lines = array(
			'RAN Booster troubleshooting report',
			'Partial: ' . ( $partial ? 'yes' : 'no' ),
			'Partial reason: ' . ( $partial_reason ?? 'none' ),
		);

		foreach ( $results as $result ) {
			$lines[] = sprintf(
				'[%s] %s | %s | %s',
				$result['status'],
				$result['code'],
				$result['message'],
				$result['remediation']
			);
		}

		return implode( "\n", $lines );
	}
}
