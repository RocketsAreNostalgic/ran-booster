<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\Logging\BoosterLogger;
use RAN\Package;
use RAN\PackageArtifactLimit;
use RAN\PackageOperation;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Runtime\RuntimeSupport;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use RuntimeException;
use Throwable;

/**
 * Booster admission/history around the normalized branch-updater execution boundary.
 */
class DeploymentCoordinator {

	private WordPressCorePackageExecutor $branchExecutor;

	public function __construct(
		private DeploymentAttemptRepository $attempts,
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ProviderRegistry $providers,
		private WordPressWorkerWakeup $wakeup,
		private string $maintenancePath,
		private WordPressUpdaterLock $updaterLock,
		private ?DeploymentFailureNotifier $failureNotifier = null,
		private ?RepositorySourceGuard $sourceGuard = null,
		?WordPressCorePackageExecutor $branchExecutor = null
	) {
		$this->branchExecutor = $branchExecutor ?? new WordPressCorePackageExecutor();
		$this->sourceGuard  ??= new RepositorySourceGuard();
		if ( '' === trim( $maintenancePath ) ) {
			throw new RuntimeException( 'The WordPress maintenance path is invalid.' );
		}
	}

	/** @return array{status: 'succeeded'|'failed', correlation_id: string, outcome_code: string} */
	public function executeManual( PackageOperation $command ): array {
		PackageMutationGuard::assertFilesystemMutationAllowed();
		$userId = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( 'install' === $command->operation ) {
			$type       = $command->packageType;
			$providerId = $command->providerRepositoryId;
			// The administrator's install request is the one-time authority; this
			// policy governs the package only after installation.
			if ( null === $providerId ) {
				throw new RuntimeException( 'The package request is not eligible for deployment.' );
			}
			if ( null === $command->providerCode || null === $command->repository || null === $command->branch || null === $command->packageSlug ) {
				throw new RuntimeException( 'The package request is incomplete.' );
			}
			$this->sourceGuard->assertAllowed( $command->providerCode, $providerId, 'plugin' === $type ? 1 : 2, $command->identifier ?? $command->packageSlug, PackageSource::BRANCH );
			$this->providers->get( ProviderCode::parse( $command->providerCode ) );
			$request = new DeploymentRequest(
				$command->repository,
				$command->credentialId,
				$command->private,
				$command->branch,
				$command->packageSlug,
				$command->subdirectory,
				$command->deploymentPolicy,
				$userId > 0 ? $userId : null
			);
			$attempt = $this->attempts->admitAndClaimManual(
				'install',
				$type,
				$command->providerCode,
				$providerId,
				$request,
				(string) $command->branch,
				PackageSource::BRANCH->value,
				0
			);
		} elseif ( 'update' === $command->operation ) {
			$type       = $command->packageType;
			$identifier = $command->identifier ?? throw new RuntimeException( 'The package identity is unavailable.' );
			$package    = $this->packageFromIdentifier( $type, $identifier );
			$this->assertSubmittedSnapshot( $command, $package );
			$this->assertBranchSource( $package );
			if ( ! $package->getDeploymentPolicy()->allowsManualMutation() ) {
				throw new RuntimeException( 'The package is disabled for Booster deployments.' );
			}
			$request      = $this->requestFromPackage( $package, $userId > 0 ? $userId : null );
			$requestedRef = null !== $command->ref ? $command->ref : $request->configuredBranch;
			$attempt      = $this->attempts->admitAndClaimManual(
				'update',
				$type,
				(string) $package->getProviderCode(),
				(string) $package->getProviderRepositoryId(),
				$request,
				$requestedRef,
				$package->getSource()->value,
				$package->getSourceRevision()
			);
		} else {
			throw new RuntimeException( 'The package request is not a deployment.' );
		}

		$outcome = $this->executeRunning( $attempt );

		return array(
			'status'         => DeploymentState::SUCCEEDED === $outcome->getState() ? 'succeeded' : 'failed',
			'correlation_id' => $attempt->getCorrelationId(),
			'outcome_code'   => $outcome->getCode(),
		);
	}

	/**
	 * Persist one validated administrator selection for sequential cron execution.
	 *
	 * @param list<array{package_type: string, provider: string, provider_repository_id: string, requested_ref: string, request: DeploymentRequest}> $targets
	 * @return array{queued: int, busy: int, runner_status: string}
	 */
	public function queueManualUpdates( array $targets ): array {
		RuntimeSupport::assertManagedOperationsAllowed();
		$admission = $this->attempts->admitManualBatch( $targets );
		$queued    = count( $admission['admitted'] );

		return array(
			'queued'        => $queued,
			'busy'          => count( $admission['busy'] ),
			'runner_status' => $queued > 0 ? $this->requestWorker() : 'not_required',
		);
	}

	private function assertSubmittedSnapshot( PackageOperation $command, Package $package ): void {
		$expected = $command->expectedPackage;
		if ( ! $command->hasExpectedPackage()
			|| $package->getProviderCode() !== $expected['provider']
			|| ! hash_equals( (string) $package->getProviderRepositoryId(), (string) $expected['provider_repository_id'] )
			|| ! hash_equals( (string) $package->getRepository(), (string) $expected['repository'] )
			|| ! hash_equals( (string) $package->getBranch(), (string) $expected['branch'] )
			|| ! hash_equals( $package->getCredentialId(), (string) $expected['credential_id'] )
			|| ! hash_equals( (string) $package->getSubdirectory(), (string) $expected['subdirectory'] )
			|| (bool) $package->getPrivate() !== $expected['private']
			|| ! hash_equals( (string) $package->getSlug(), (string) $expected['package_slug'] )
			|| $package->getDeploymentPolicy() !== $expected['deployment_policy']
			|| $package->getSource() !== $expected['source']
			|| $package->getSourceRevision() !== $expected['source_revision'] ) {
			throw new RuntimeException( 'The managed package changed after this form was opened.' );
		}
	}

	/**
	 * @param list<PushEvent> $events
	 * @return array{status: string, correlation_id: string, accepted_targets: int, runner_status: string}
	 */
	public function acceptWebhook( array $events, string $authenticatedBodyDigest ): array {
		PackageMutationGuard::assertWebhookDispatchAllowed();
		if ( array() === $events || preg_match( '/^[a-f0-9]{64}$/D', $authenticatedBodyDigest ) !== 1 ) {
			throw new RuntimeException( 'The authenticated webhook delivery is invalid.' );
		}
		$first      = $events[0];
		$provider   = $first->provider->value;
		$deliveryId = $first->deliveryId;
		$targets    = array();

		foreach ( $events as $event ) {
			if ( ! $event instanceof PushEvent || $event->provider->value !== $provider || $event->deliveryId !== $deliveryId ) {
				throw new RuntimeException( 'One webhook request must identify one provider delivery.' );
			}
			try {
				$matches = $this->matchingPackages( $event );
			} catch ( PackageStorageFailure $failure ) {
				if ( $failure->isDatabaseUnsupported() ) {
					throw DeploymentStorageFailure::unsupportedDatabase();
				}
				throw $failure;
			}
			foreach ( $matches as $match ) {
				$key = $match['type'] . "\0" . $match['package']->getSlug();
				if ( isset( $targets[ $key ] ) && $targets[ $key ]['requested_ref'] !== $event->commit ) {
					throw new RuntimeException( 'Conflicting webhook events target one package.' );
				}
				$targets[ $key ] = array(
					'operation'               => 'update',
					'package_type'            => $match['type'],
					'provider_repository_id'  => $event->providerRepositoryId,
					'requested_ref'           => $event->commit,
					'package_source'          => $match['package']->getSource()->value,
					'package_source_revision' => $match['package']->getSourceRevision(),
					'request'                 => $this->requestFromPackage( $match['package'], null ),
				);
			}
		}
		PackageMutationGuard::assertDeploymentTargetCount( count( $targets ) );
		if ( array() === $targets ) {
			$this->attempts->admitWebhookBatch( $provider, $deliveryId, $authenticatedBodyDigest, array() );
			return $this->admission( 'accepted', substr( hash( 'sha256', $provider . "\0" . $deliveryId ), 0, 32 ), 0, 'not_required' );
		}

		$attempts = $this->attempts->admitWebhookBatch( $provider, $deliveryId, $authenticatedBodyDigest, array_values( $targets ) );
		if ( array() === $attempts ) {
			return $this->admission( 'duplicate', substr( hash( 'sha256', $provider . "\0" . $deliveryId ), 0, 32 ), 0, 'not_required' );
		}
		$status = count( array_filter( $attempts, static fn ( DeploymentAttempt $attempt ): bool => DeploymentState::QUEUED === $attempt->getState() ) ) > 0 ? 'accepted' : 'duplicate';
		return $this->admission( $status, $attempts[0]->getCorrelationId(), count( $attempts ), $this->requestWorker() );
	}

	/** Execute one queued row claimed by the real WordPress cron worker. */
	public function executeClaimed( DeploymentAttempt $attempt ): DeploymentOutcome {
		RuntimeSupport::assertManagedOperationsAllowed();
		if ( ! wp_doing_cron() ) {
			throw new RuntimeException( 'The deployment worker is unavailable outside WordPress cron.' );
		}
		return $this->executeRunning( $attempt );
	}

	/** Execute one durably running row through the normalized admitted branch runner. */
	private function executeRunning( DeploymentAttempt $attempt ): DeploymentOutcome {
		if ( DeploymentState::RUNNING !== $attempt->getState() ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		$context = $attempt->logContext();
		BoosterLogger::log( 'deployment execution started', $context + array( 'step' => 'execute_running' ) );
		$host = new AdmittedBranchHostAdapter(
			$attempt,
			$this->attempts,
			$this->plugins,
			$this->themes,
			$this->providers,
			$this->sourceGuard,
			$this->updaterLock,
			$this->branchExecutor,
			$this->maintenancePath
		);

		try {
			$declaration = $host->declaration();
		} catch ( AdmittedBranchStageFailure $failure ) {
			$host->finish( $failure->outcomeCode );
			return $this->finishedOutcome( $host->terminalAttempt() );
		}

		$updater    = BranchUpdater::forAdmittedAttempt( $declaration, $host, $host, $host, $host, $host );
		$deployment = 'plugin' === $declaration->packageType
			? $updater->plugin(
				$declaration->repository,
				$declaration->repositoryId,
				$declaration->branch,
				null,
				$declaration->slug,
				$declaration->subdirectory
			)
			: $updater->theme(
				$declaration->repository,
				$declaration->repositoryId,
				$declaration->branch,
				$declaration->slug,
				$declaration->subdirectory
			);
		$code       = $deployment->deploy();
		$outcome    = $this->finishedOutcome( $host->terminalAttempt() );
		if ( ! hash_equals( $code, $outcome->getCode() ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		return $outcome;
	}

	private function finishedOutcome( DeploymentAttempt $finished ): DeploymentOutcome {
		if ( ! $finished->getState()->isTerminal() ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$outcome = $finished->getOutcome() ?? throw DeploymentStorageFailure::inconsistent();
		BoosterLogger::log(
			'attempt finished',
			$finished->logContext() + array(
				'step'         => 'attempt_finished',
				'outcome_code' => $outcome->getCode(),
			)
		);
		$data = $finished->safeData();
		if ( null !== $this->failureNotifier
			&& 'webhook' === $data['source']
			&& in_array( $finished->getState(), array( DeploymentState::FAILED, DeploymentState::NEEDS_ATTENTION ), true )
		) {
			try {
				$this->failureNotifier->notify( $finished );
			} catch ( Throwable $exception ) {
				BoosterLogger::logException(
					'background deployment failure notification unavailable',
					$exception,
					$finished->logContext() + array( 'step' => 'background_failure_notification' )
				);
			}
		}
		return $outcome;
	}

	/** Reconcile only after the protected controller confirms the worker stopped. */
	public function reconcileConfirmedStopped( int $attemptId, string $correlationId ): DeploymentAttempt {
		$attempt = $this->attempts->findExact( $attemptId );
		if ( null === $attempt || ! hash_equals( $attempt->getCorrelationId(), $correlationId ) ) {
			throw DeploymentStorageFailure::notFound();
		}
		if ( DeploymentState::RUNNING !== $attempt->getState() ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$result = $this->attempts->reconcileConfirmedStopped( $attemptId );
		$this->requestWorker();
		return $result;
	}

	/** Protected admin seam for re-prompting the one-shot runner. */
	public function requestRunner(): string {
		return $this->requestWorker( true );
	}

	/** @return array{status: string, correlation_id: string, accepted_targets: int, runner_status: string} */
	private function admission( string $status, string $correlationId, int $targets, string $runner ): array {
		if ( ! in_array( $status, array( 'accepted', 'duplicate' ), true )
			|| preg_match( '/^[a-f0-9]{32}$/D', $correlationId ) !== 1
			|| $targets < 0 || $targets > PackageMutationGuard::MAX_DEPLOYMENT_TARGETS
			|| ! in_array( $runner, array( 'scheduled', 'already_scheduled', 'unavailable', 'not_required' ), true ) ) {
			throw new RuntimeException( 'The deployment admission result is invalid.' );
		}
		return array(
			'status'           => $status,
			'correlation_id'   => $correlationId,
			'accepted_targets' => $targets,
			'runner_status'    => $runner,
		);
	}

	private function requestWorker( bool $spawn = false ): string {
		$status = $this->wakeup->request();
		if ( $spawn && 'unavailable' !== $status && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return $status;
	}

	private function requestFromPackage( Package $package, ?int $userId ): DeploymentRequest {
		$this->assertBranchSource( $package );
		$provider = $package->getProviderCode();
		if ( null === $provider || null === $package->getProviderRepositoryId() ) {
			throw new RuntimeException( 'The managed package provider identity is incomplete.' );
		}
		$this->providers->get( ProviderCode::parse( $provider ) );
		return new DeploymentRequest(
			(string) $package->getRepository(),
			'' === $package->getCredentialId() ? null : $package->getCredentialId(),
			(bool) $package->getPrivate(),
			(string) $package->getBranch(),
			(string) $package->getSlug(),
			is_string( $package->getSubdirectory() ) ? $package->getSubdirectory() : null,
			$package->getDeploymentPolicy(),
			$userId,
			PackageArtifactLimit::resolve( null )
		);
	}

	/** @return list<array{type: string, package: Package}> */
	private function matchingPackages( PushEvent $event ): array {
		$matches    = array();
		$normalizer = $this->providers->requireCapability( $event->provider, WebhookNormalizer::class );
		$policy     = $normalizer->getWebhookPolicy();
		foreach ( array(
			'plugin' => $this->plugins->allDeploymentPlugins(),
			'theme'  => $this->themes->allDeploymentThemes(),
		) as $type => $packages ) {
			foreach ( $packages as $package ) {
				if ( PackageSource::BRANCH === $package->getSource()
					&& $package->getDeploymentPolicy()->allowsWebhookMutation()
					&& ! PackageMutationGuard::isBoosterPluginFile( $package->getIdentifier() )
					&& $package->getProviderCode() === $event->provider->value
					&& $policy->repositoryTargetMatches( $event->repository, (string) $package->getRepository() )
					&& (string) $package->getBranch() === $event->branch
					&& null !== $package->getProviderRepositoryId()
					&& hash_equals( (string) $package->getProviderRepositoryId(), $event->providerRepositoryId ) ) {
					$matches[] = array(
						'type'    => $type,
						'package' => $package,
					);
				}
			}
		}
		return $matches;
	}

	private function assertBranchSource( Package $package ): void {
		if ( PackageSource::BRANCH !== $package->getSource() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal domain exception; presentation owns escaping.
			throw new DeploymentCheckFailure( DeploymentOutcome::CODE_DEPLOYMENT_RELEASE_SOURCE_BLOCKED, 'Branch deployment is unavailable for a release-managed package.' );
		}
	}

	private function packageFromIdentifier( string $type, string $identifier ): Package {
		return 'plugin' === $type ? $this->plugins->boosterPluginFromFile( $identifier ) : $this->themes->boosterThemeFromStylesheet( $identifier );
	}
}
