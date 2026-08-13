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
		private readonly ?CoreSelfUpdateStatus $coreSelfUpdate = null
	) {
	}

	/**
	 * Build the read-only GET payload from provider metadata and safe credential labels.
	 *
	 * @return array<string, mixed>
	 */
	public function formPayload(): array {
		$options = $this->providerOptions();

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
	public function diagnose( string $provider, ?string $credentialId, ?string $repository ): array {
		$inputInvalid = false;
		try {
			$request = new ProviderDiagnosticRequest(
				$credentialId,
				$repository,
				ProviderDiagnosticRequest::MAX_REMOTE_CALLS,
				ProviderDiagnosticRequest::MAX_SECONDS,
				$this->clock
			);
		} catch ( \Throwable ) {
			$request      = new ProviderDiagnosticRequest( null, null, ProviderDiagnosticRequest::MAX_REMOTE_CALLS, ProviderDiagnosticRequest::MAX_SECONDS, $this->clock );
			$credentialId = null;
			$repository   = null;
			$inputInvalid = true;
		}

		$options      = $this->providerOptions();
		$localPayload = $this->local->diagnose();
		$results      = $this->validLocalResults( $localPayload['results'] ?? array() );
		$partial      = null;

		if ( ! empty( $localPayload['partial'] )
			|| self::LOCAL_RESULTS !== count( $results )
			|| count( $results ) !== count( $localPayload['results'] ?? array() )
		) {
			$partial = 'local_incomplete';
		}

		if ( null !== $partial || count( $results ) >= self::MAX_RESULTS ) {
			$safeProvider = isset( $options[ $provider ] ) ? $provider : '';

			return $this->payload( $safeProvider, $credentialId, $repository, $results, $partial ?? 'local_incomplete', true, $options );
		}

		if ( $request->remainingSeconds() <= 0.0 ) {
			$safeProvider = isset( $options[ $provider ] ) ? $provider : '';

			return $this->payload( $safeProvider, $credentialId, $repository, $results, 'deadline_exhausted', true, $options );
		}

		if ( $inputInvalid ) {
			$safeProvider = isset( $options[ $provider ] ) ? $provider : '';

			return $this->payload( $safeProvider, null, null, $results, 'provider_results_invalid', true, $options );
		}

		try {
			$providerCode = ProviderCode::parse( $provider );
			$aggregate    = $this->providers->get( $providerCode );
		} catch ( \Throwable ) {
			return $this->payload( '', $credentialId, $repository, $results, 'provider_unavailable', true, $options );
		}

		try {
			$providerResults = $aggregate->getProviderDiagnostics()->diagnose( $request );
		} catch ( ProviderDiagnosticBudgetExceeded $exception ) {
			$reason = ProviderDiagnosticBudgetExceeded::DEADLINE === $exception->getReason()
				? 'deadline_exhausted'
				: 'remote_calls_exhausted';

			return $this->payload( $providerCode->value, $credentialId, $repository, $results, $reason, true, $options );
		} catch ( \Throwable $exception ) {
			BoosterLogger::logException(
				'provider diagnostic operation failed',
				$exception,
				array(
					'provider' => $providerCode->value,
					'step'     => 'provider_diagnostics',
				)
			);
			$reason = $this->budgetPartialReason( $request ) ?? 'provider_unavailable';

			return $this->payload( $providerCode->value, $credentialId, $repository, $results, $reason, true, $options );
		}

		if ( $request->remainingSeconds() <= 0.0 ) {
			return $this->payload( $providerCode->value, $credentialId, $repository, $results, 'deadline_exhausted', true, $options );
		}

		$budgetPartial = $this->budgetPartialReason( $request );
		if ( null !== $budgetPartial ) {
			$partial = $this->higherPriority( $partial, $budgetPartial );
		}

		$remaining = self::MAX_RESULTS - count( $results );
		if ( count( $providerResults ) > $remaining ) {
			$partial = $this->higherPriority( $partial, 'result_limit_exhausted' );
		}

		$seen = array_fill_keys(
			array_map( static fn( ProviderDiagnosticResult $result ): string => $result->code, $results ),
			true
		);
		foreach ( $providerResults as $result ) {
			if ( count( $results ) >= self::MAX_RESULTS ) {
				break;
			}

			if ( ! $this->validProviderResult( $result, $providerCode, $seen ) ) {
				$partial = $this->higherPriority( $partial, 'provider_results_invalid' );
				break;
			}
			$this->recordProviderFailure( $result, $providerCode, 'provider_diagnostics' );

			$seen[ $result->code ] = true;
			$results[]             = $result;
		}

		if ( count( $results ) < self::MAX_RESULTS
			&& $aggregate instanceof WebhookNormalizer
			&& ! in_array( $partial, array( 'provider_unavailable', 'provider_results_invalid' ), true )
		) {
			if ( $request->remainingSeconds() <= 0.0 ) {
				$partial = $this->higherPriority( $partial, 'deadline_exhausted' );
			} else {
				try {
					$readiness = $aggregate->diagnoseWebhookReadiness();
					if ( $this->validProviderResult( $readiness, $providerCode, $seen ) ) {
						$this->recordProviderFailure( $readiness, $providerCode, 'provider_webhook_readiness' );
						$results[] = $readiness;
					} else {
						$partial = $this->higherPriority( $partial, 'provider_results_invalid' );
					}
				} catch ( \Throwable $exception ) {
					BoosterLogger::logException(
						'provider diagnostic operation failed',
						$exception,
						array(
							'provider' => $providerCode->value,
							'step'     => 'provider_webhook_readiness',
						)
					);
					$partial = $this->higherPriority( $partial, 'provider_unavailable' );
				}
			}
		}

		if ( $request->remainingSeconds() <= 0.0 ) {
			$partial = $this->higherPriority( $partial, 'deadline_exhausted' );
		}

		return $this->payload(
			$providerCode->value,
			$credentialId,
			$repository,
			$results,
			$partial,
			true,
			$options
		);
	}

	private function recordProviderFailure( ProviderDiagnosticResult $result, ProviderCode $provider, string $step ): void {
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
	private function providerOptions(): array {
		$options = array();

		foreach ( $this->providers->orderedMetadata() as $metadata ) {
			$options[ $metadata->code->value ] = $metadata->label;
		}

		return $options;
	}

	/** @return array<string, string> */
	private function providerLocatorHints(): array {
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
	private function credentialChoices( array $providers ): array {
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
	private function validLocalResults( mixed $results ): array {
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
	private function validProviderResult( mixed $result, ProviderCode $provider, array $seen ): bool {
		return $result instanceof ProviderDiagnosticResult
			&& str_starts_with( $result->code, $provider->value . '.' )
			&& ! isset( $seen[ $result->code ] );
	}

	private function higherPriority( ?string $current, string $candidate ): string {
		if ( null === $current ) {
			return $candidate;
		}

		return self::PARTIAL_PRIORITY[ $candidate ] < self::PARTIAL_PRIORITY[ $current ]
			? $candidate
			: $current;
	}

	private function budgetPartialReason( ProviderDiagnosticRequest $request ): ?string {
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
		?string $credentialId,
		?string $repository,
		array $results,
		?string $partialReason,
		bool $ran,
		array $providers
	): array {
		$displayResults = array_map(
			static fn( ProviderDiagnosticResult $result ): array => $result->toArray(),
			$results
		);
		$partial        = null !== $partialReason;

		return array(
			'providers'              => $providers,
			'provider_locator_hints' => $this->providerLocatorHints(),
			'credentials'            => $this->credentialChoices( $providers ),
			'selected_provider'      => $provider,
			'credential_id'          => $credentialId ?? '',
			'repository'             => $repository ?? '',
			'ran'                    => $ran,
			'results'                => $displayResults,
			'partial'                => $partial,
			'partial_reason'         => $partialReason,
			'report'                 => $ran ? $this->report( $displayResults, $partial, $partialReason ) : '',
			'core_self_update'       => null === $this->coreSelfUpdate
				? array()
				: $this->coreSelfUpdate->diagnostics(),
		);
	}

	/**
	 * @param list<array{status: string, code: string, message: string, remediation: string}> $results
	 */
	private function report( array $results, bool $partial, ?string $partialReason ): string {
		$lines = array(
			'RAN Booster troubleshooting report',
			'Partial: ' . ( $partial ? 'yes' : 'no' ),
			'Partial reason: ' . ( $partialReason ?? 'none' ),
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
