<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentState;
use RAN\Logging\BoosterLogger;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use Throwable;

/**
 * Derives the newest actionable webhook failure for each managed package.
 */
final class BackgroundDeploymentFailureMonitor {

	private const MAX_ATTEMPTS = 100;

	/** @var list<array<string, int|string|null>>|null */
	private ?array $failures = null;

	public function __construct(
		private DeploymentAttemptRepository $attempts,
		private ProviderRegistry $providers
	) {
	}

	/** @return list<array<string, int|string|null>> */
	public function failures(): array {
		if ( null !== $this->failures ) {
			return $this->failures;
		}

		try {
			$attempts = $this->attempts->recentHistory( self::MAX_ATTEMPTS );
		} catch ( Throwable $failure ) {
			BoosterLogger::logException(
				'background deployment failure status unavailable',
				$failure,
				array(
					'source' => 'admin',
					'step'   => 'background_failure_status',
				)
			);
			$this->failures = array();

			return $this->failures;
		}

		$seen     = array();
		$failures = array();
		foreach ( $attempts as $attempt ) {
			if ( ! $attempt instanceof DeploymentAttempt ) {
				continue;
			}
			$data = $attempt->safe_data();
			$key  = (string) $data['package_type'] . "\0" . (string) $data['package_slug'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			if ( 'webhook' !== $data['source']
				|| ! in_array( $attempt->get_state(), array( DeploymentState::FAILED, DeploymentState::NEEDS_ATTENTION ), true )
				|| ( DeploymentState::NEEDS_ATTENTION === $attempt->get_state() && ! $attempt->requires_operator_resolution() )
				|| ! is_string( $data['outcome_code'] )
				|| ! is_string( $data['finished_at'] )
			) {
				continue;
			}

			$request       = $attempt->get_request();
			$provider      = (string) $data['provider'];
			$providerLabel = strtoupper( $provider );
			try {
				$providerLabel = $this->providers->get( ProviderCode::parse( $provider ) )->getMetadata()->label;
			} catch ( Throwable ) {
				$providerLabel = strtoupper( $provider );
			}

			$failures[] = array(
				'attempt_id'     => $attempt->get_id(),
				'correlation_id' => $attempt->get_correlation_id(),
				'package_type'   => (string) $data['package_type'],
				'package_slug'   => (string) $data['package_slug'],
				'provider'       => $provider,
				'provider_label' => $providerLabel,
				'credential_id'  => $request->credential_id,
				'state'          => $attempt->get_state()->value,
				'outcome_code'   => (string) $data['outcome_code'],
				'finished_at'    => (string) $data['finished_at'],
			);
		}

		$this->failures = $failures;

		return $this->failures;
	}

	/** @return array<string, int|string|null>|null */
	public function forPackage( string $packageType, string $packageSlug ): ?array {
		foreach ( $this->failures() as $failure ) {
			if ( $packageType === $failure['package_type'] && hash_equals( $packageSlug, (string) $failure['package_slug'] ) ) {
				return $failure;
			}
		}

		return null;
	}

	/** @param list<array<string, int|string|null>>|null $failures */
	public function fingerprint( ?array $failures = null ): ?string {
		$failures ??= $this->failures();
		if ( array() === $failures ) {
			return null;
		}

		$identity = array_map(
			static fn ( array $failure ): array => array(
				'attempt_id'     => $failure['attempt_id'],
				'correlation_id' => $failure['correlation_id'],
				'state'          => $failure['state'],
				'outcome_code'   => $failure['outcome_code'],
			),
			$failures
		);

		return hash( 'sha256', (string) wp_json_encode( $identity ) );
	}
}
