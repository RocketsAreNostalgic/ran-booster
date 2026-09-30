<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

use Closure;
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

	private const MAX_RESULTS   = 8;
	private const LOCAL_RESULTS = 5;

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
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
		private readonly ?CoreSelfUpdateStatus $coreSelfUpdate = null
	) {
	}

	/**
	 * Build the read-only GET payload from provider metadata and safe credential labels.
	 *
	 * @return array<string, mixed>
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected methods retain the existing caller and override contracts.
	public function formPayload(): array {
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
	 * Run local checks and exactly one selected provider in the current request.
	 *
	 * @return array<string, mixed>
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
	public function diagnose( string $provider, ?string $credentialId, ?string $repository ): array {
		$input_invalid = false;
		try {
			$request = new ProviderDiagnosticRequest(
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
				$credentialId,
				$repository,
				ProviderDiagnosticRequest::MAX_REMOTE_CALLS,
				ProviderDiagnosticRequest::MAX_SECONDS,
				$this->clock
			);
		} catch ( \Throwable ) {
			$request = new ProviderDiagnosticRequest( null, null, ProviderDiagnosticRequest::MAX_REMOTE_CALLS, ProviderDiagnosticRequest::MAX_SECONDS, $this->clock );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			$credentialId  = null;
			$repository    = null;
			$input_invalid = true;
		}

		$options       = $this->provider_options();
		$local_payload = $this->local->diagnose();
		$results       = $this->valid_local_results( $local_payload['results'] ?? array() );
		$partial       = null;

		if ( ! empty( $local_payload['partial'] )
			|| self::LOCAL_RESULTS !== count( $results )
			|| count( $results ) !== count( $local_payload['results'] ?? array() )
		) {
			$partial = 'local_incomplete';
		}

		if ( null !== $partial || count( $results ) >= self::MAX_RESULTS ) {
			$safe_provider = isset( $options[ $provider ] ) ? $provider : '';

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			return $this->payload( $safe_provider, $credentialId, $repository, $results, $partial ?? 'local_incomplete', true, $options );
		}

		if ( $request->remainingSeconds() <= 0.0 ) {
			$safe_provider = isset( $options[ $provider ] ) ? $provider : '';

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			return $this->payload( $safe_provider, $credentialId, $repository, $results, 'deadline_exhausted', true, $options );
		}

		if ( $input_invalid ) {
			$safe_provider = isset( $options[ $provider ] ) ? $provider : '';

			return $this->payload( $safe_provider, null, null, $results, 'provider_results_invalid', true, $options );
		}

		try {
			$provider_code = ProviderCode::parse( $provider );
			$aggregate     = $this->providers->get( $provider_code );
		} catch ( \Throwable ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			return $this->payload( '', $credentialId, $repository, $results, 'provider_unavailable', true, $options );
		}

		try {
			$provider_results = $aggregate->getProviderDiagnostics()->diagnose( $request );
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			$reason = ProviderDiagnosticBudgetExceeded::DEADLINE === $exception->getReason()
				? 'deadline_exhausted'
				: 'remote_calls_exhausted';

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			return $this->payload( $provider_code->value, $credentialId, $repository, $results, $reason, true, $options );
		} catch ( \Throwable $exception ) {
			BoosterLogger::logException(
				'provider diagnostic operation failed',
				$exception,
				array(
					'provider' => $provider_code->value,
					'step'     => 'provider_diagnostics',
				)
			);
			$reason = $this->budget_partial_reason( $request ) ?? 'provider_unavailable';

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			return $this->payload( $provider_code->value, $credentialId, $repository, $results, $reason, true, $options );
		}

		if ( $request->remainingSeconds() <= 0.0 ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			return $this->payload( $provider_code->value, $credentialId, $repository, $results, 'deadline_exhausted', true, $options );
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
			if ( $request->remainingSeconds() <= 0.0 ) {
				$partial = $this->higher_priority( $partial, 'deadline_exhausted' );
			} else {
				try {
					$readiness = $aggregate->diagnoseWebhookReadiness();
					if ( $this->valid_provider_result( $readiness, $provider_code, $seen ) ) {
						$this->record_provider_failure( $readiness, $provider_code, 'provider_webhook_readiness' );
						$results[] = $readiness;
					} else {
						$partial = $this->higher_priority( $partial, 'provider_results_invalid' );
					}
				} catch ( \Throwable $exception ) {
					BoosterLogger::logException(
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

		if ( $request->remainingSeconds() <= 0.0 ) {
			$partial = $this->higher_priority( $partial, 'deadline_exhausted' );
		}

		return $this->payload(
			$provider_code->value,
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			$credentialId,
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

		BoosterLogger::logException(
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

		foreach ( $this->providers->orderedMetadata() as $metadata ) {
			$options[ $metadata->code->value ] = $metadata->label;
		}

		return $options;
	}

	/** @return array<string, string> */
	private function provider_locator_hints(): array {
		$hints = array();
		foreach ( $this->providers->orderedMetadata() as $metadata ) {
			$hints[ $metadata->code->value ] = $metadata->admin?->repositoryLocatorHint ?? '';
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
				$profiles = $this->secrets->credentialProfiles( $provider );
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

	/**
	 * @param mixed $results Untrusted local payload boundary.
	 * @return list<ProviderDiagnosticResult>
	 */
	private function valid_local_results( mixed $results ): array {
		if ( ! is_array( $results ) ) {
			return array();
		}

		$valid = array();
		$seen  = array();
		foreach ( $results as $result ) {
			if ( count( $valid ) >= self::LOCAL_RESULTS
				|| ! $result instanceof ProviderDiagnosticResult
				|| ! str_starts_with( $result->code, 'local.' )
				|| isset( $seen[ $result->code ] )
			) {
				break;
			}

			$seen[ $result->code ] = true;
			$valid[]               = $result;
		}

		return $valid;
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
		return match ( $request->getExhaustionReason() ) {
			ProviderDiagnosticBudgetExceeded::DEADLINE     => 'deadline_exhausted',
			ProviderDiagnosticBudgetExceeded::REMOTE_CALLS => 'remote_calls_exhausted',
			default                                        => null,
		};
	}

	/**
	 * @param list<ProviderDiagnosticResult> $results
	 * @param array<string, string>           $providers
	 * @return array<string, mixed>
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
			static fn( ProviderDiagnosticResult $result ): array => $result->toArray(),
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
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
			'core_self_update'       => null === $this->coreSelfUpdate
				? array()
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Preserve existing public, protected, promoted parameter and foreign object contracts.
				: $this->coreSelfUpdate->diagnostics(),
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
