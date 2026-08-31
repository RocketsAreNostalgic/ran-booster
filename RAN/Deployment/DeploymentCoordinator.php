<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\Logging\BoosterLogger;
use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageOperation;
use RAN\PackageSource;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\StaleDeployment;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Runtime\RuntimeSupport;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\CorePackageExecutionFailure;
use RAN\WordPress\CorePackageExecutionResult;
use RAN\WordPress\CorePackageExecutor;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;
use Throwable;

/**
 * The one production path from durable admission to one WordPress core update.
 */
class DeploymentCoordinator {

	public function __construct(
		private DeploymentAttemptRepository $attempts,
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ProviderRegistry $providers,
		private DeploymentArchivePreflight $preflight,
		private CorePackageExecutor $executor,
		private WordPressWorkerWakeup $wakeup,
		private string $maintenancePath,
		private WordPressUpdaterLock $updaterLock,
		private ?DeploymentFailureNotifier $failureNotifier = null
	) {
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
			$requestedRef = null !== $command->ref
				? $command->ref
				: $request->configuredBranch;
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
			if ( ! $event instanceof PushEvent
				|| $event->provider->value !== $provider
				|| $event->deliveryId !== $deliveryId ) {
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
		$status = count( array_filter( $attempts, static fn ( DeploymentAttempt $attempt ): bool => DeploymentState::QUEUED === $attempt->getState() ) ) > 0
			? 'accepted'
			: 'duplicate';

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

	/** Execute one durably running row; WordPress's updater lock guards mutation. */
	private function executeRunning( DeploymentAttempt $attempt ): DeploymentOutcome {
		if ( DeploymentState::RUNNING !== $attempt->getState() ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		$artifact       = null;
		$coreLock       = null;
		$mutation       = false;
		$outcome        = DeploymentOutcome::fromCode( DeploymentOutcome::CODE_PREFLIGHT_FAILED );
		$baseline       = null;
		$baselineState  = null;
		$failureCode    = DeploymentOutcome::CODE_POLICY_BLOCKED;
		$storageFailure = null;
		$context        = $attempt->logContext();

		try {
			BoosterLogger::log( 'deployment execution started', $context + array( 'step' => 'execute_running' ) );
			PackageMutationGuard::assertFilesystemMutationAllowed();
			$baseline = $this->assertFrozenTarget( $attempt, true );
			BoosterLogger::log( 'target snapshot verified', $context + array( 'step' => 'target_verified' ) );
			$baselineState = null === $baseline ? null : $this->packageRuntimeState( $baseline );
			$failureCode   = DeploymentOutcome::CODE_PROVIDER_FAILED;
			$archive       = $this->prepareArchive( $attempt );
			BoosterLogger::log( 'archive prepared for download', $context + array( 'step' => 'archive_prepared' ) );
			$failureCode = DeploymentOutcome::CODE_PREFLIGHT_FAILED;
			$artifact    = $this->preflight->prepare(
				$attempt,
				$archive,
				null === $baseline ? null : (string) $baseline->getIdentifier()
			);
			$failureCode = DeploymentOutcome::CODE_DOWNGRADE_BLOCKED;
			$this->assertNotDowngrade( $baseline, $artifact );
			BoosterLogger::log(
				'preflight completed',
				$context + array(
					'step'         => 'preflight_completed',
					'resolved_ref' => $artifact->getResolvedRef(),
				)
			);
			$attempt = $this->attempts->recordResolvedRef( $attempt->getId(), $artifact->getResolvedRef() );
			$context = $attempt->logContext();
			BoosterLogger::log( 'resolved ref recorded', $context + array( 'step' => 'resolved_ref_recorded' ) );
			$failureCode = DeploymentOutcome::CODE_LOCK_UNAVAILABLE;
			$coreLock    = $this->acquireCoreLock();
			BoosterLogger::log( 'core lock acquired', $context + array( 'step' => 'core_lock_acquired' ) );

			$failureCode = DeploymentOutcome::CODE_POLICY_BLOCKED;
			$this->assertFrozenTarget( $attempt );
			BoosterLogger::log( 'target snapshot re-verified post-lock', $context + array( 'step' => 'target_reverified' ) );
			$archive->verifyCurrentHead();
			BoosterLogger::log( 'provider head verified', $context + array( 'step' => 'provider_head_verified' ) );
			$artifact->assertUnchanged();
			BoosterLogger::log( 'artifact integrity re-verified', $context + array( 'step' => 'artifact_verified' ) );
			if ( file_exists( $this->maintenancePath ) ) {
				throw new RuntimeException( 'WordPress maintenance mode is already active.' );
			}

			PackageMutationGuard::assertFilesystemMutationAllowed();
			BoosterLogger::log( 'filesystem mutation starting', $context + array( 'step' => 'mutation_starting' ) );
			$this->attempts->markMutationStarted( $attempt->getId() );
			$mutation = true;
			$result   = $this->executeCore( $attempt, $artifact, $baseline );
			BoosterLogger::log( 'core execution completed', $context + array( 'step' => 'core_execution_completed' ) );
			$outcome = $this->verifyCoreResult( $attempt, $artifact, $baseline, $baselineState, $result );
			BoosterLogger::log(
				'deployment outcome determined',
				$context + array(
					'step'         => 'outcome_determined',
					'outcome_code' => $outcome->getCode(),
				)
			);
		} catch ( ExistingManagedDestination ) {
			$outcome = DeploymentOutcome::fromCode( DeploymentOutcome::CODE_ALREADY_MANAGED );
			BoosterLogger::log(
				'install skipped because the destination is already managed',
				$context + array(
					'step'         => 'existing_managed_destination',
					'outcome_code' => $outcome->getCode(),
				)
			);
		} catch ( StaleDeployment ) {
			$outcome = DeploymentOutcome::fromCode( $mutation ? DeploymentOutcome::CODE_INTERRUPTED : DeploymentOutcome::CODE_STALE_EVENT );
			BoosterLogger::log(
				'deployment marked stale',
				$context + array(
					'step'         => 'stale_deployment',
					'outcome_code' => $outcome->getCode(),
				)
			);
		} catch ( DeploymentArchiveLimitFailure $failure ) {
			$outcome = DeploymentOutcome::fromCode( $mutation ? DeploymentOutcome::CODE_INTERRUPTED : $failure->outcomeCode );
			BoosterLogger::logException(
				'deployment archive limit failed',
				$failure,
				$context + array(
					'step'         => 'archive_limit_failed',
					'outcome_code' => $outcome->getCode(),
				)
			);
		} catch ( DeploymentStorageFailure $failure ) {
			$storageFailure = $failure;
			BoosterLogger::logException( 'deployment storage failure', $failure, $context + array( 'step' => 'storage_failure' ) );
		} catch ( Throwable $exception ) {
			$outcome = $mutation
				? DeploymentOutcome::fromCode( DeploymentOutcome::CODE_INTERRUPTED )
				: ( DeploymentOutcome::CODE_PROVIDER_FAILED === $failureCode
					? DeploymentOutcome::fromProviderFailure( $exception )
					: DeploymentOutcome::fromCode( $failureCode ) );
			BoosterLogger::logException(
				'deployment execution failed',
				$exception,
				$context + array(
					'step'         => 'execution_failed',
					'outcome_code' => $outcome->getCode(),
				)
			);
		} finally {
			if ( null !== $artifact ) {
				try {
					$artifact->cleanup();
					BoosterLogger::log( 'artifact cleanup completed', $context + array( 'step' => 'artifact_cleanup_completed' ) );
				} catch ( Throwable $exception ) {
					$outcome = DeploymentOutcome::fromCode( $mutation ? DeploymentOutcome::CODE_INTERRUPTED : DeploymentOutcome::CODE_PREFLIGHT_FAILED );
					BoosterLogger::logException(
						'artifact cleanup failed',
						$exception,
						$context + array(
							'step'         => 'artifact_cleanup_failed',
							'outcome_code' => $outcome->getCode(),
						)
					);
				}
			}
		}

		if ( null !== $coreLock && ! $this->releaseCoreLock( $coreLock ) ) {
			BoosterLogger::log( 'core lock release failed consistency check', $context + array( 'step' => 'core_lock_release_failed' ) );
			throw DeploymentStorageFailure::inconsistent();
		}
		if ( null !== $coreLock ) {
			BoosterLogger::log( 'core lock released', $context + array( 'step' => 'core_lock_released' ) );
		}
		if ( null !== $storageFailure ) {
			throw $storageFailure;
		}
		$finished = $this->attempts->finish( $attempt->getId(), $outcome );
		BoosterLogger::log(
			'attempt finished',
			$finished->logContext() + array(
				'step'         => 'attempt_finished',
				'outcome_code' => $outcome->getCode(),
			)
		);
		$finishedData = $finished->safeData();
		if ( null !== $this->failureNotifier
			&& 'webhook' === $finishedData['source']
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

		return $finished->getOutcome() ?? throw DeploymentStorageFailure::inconsistent();
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
			$userId
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

	private function prepareArchive( DeploymentAttempt $attempt ): \RAN\RepositoryProvider\PreparedArchive {
		$data      = $attempt->safeData();
		$request   = $attempt->getRequest();
		$provider  = ProviderCode::parse( (string) $data['provider'] );
		$reference = new RepositoryReference(
			$request->repository,
			(string) $data['provider_repository_id'],
			$request->private,
			$request->credentialId
		);
		$preparer  = $this->providers->get( $provider );

		return $preparer->prepareArchive(
			new ArchiveRequest(
				$reference,
				(string) $data['requested_ref'],
				'webhook' === $data['source'] ? $request->configuredBranch : null
			)
		);
	}

	/** Return the current installed package for an update, or null for a new install. */
	private function assertFrozenTarget( DeploymentAttempt $attempt, bool $deferMatchingManagedDestination = false ): ?Package {
		$data          = $attempt->safeData();
		$request       = $attempt->getRequest();
		$packageSource = $data['package_source'] ?? null;
		if ( PackageSource::BRANCH->value !== $packageSource ) {
			throw new RuntimeException( 'The deployment package source is unavailable.' );
		}
		if ( 'install' === $data['operation'] ) {
			if ( 'plugin' === $data['package_type'] && 'ran-booster' === $request->packageSlug ) {
				throw new RuntimeException( 'RAN Booster cannot replace its own plugin files.' );
			}
			if ( $this->destinationExists( (string) $data['package_type'], $request->packageSlug ) ) {
				if ( $this->existingManagementMatchesInstallTarget( $attempt ) ) {
					if ( $deferMatchingManagedDestination ) {
						return null;
					}
					throw new ExistingManagedDestination();
				}
				throw new RuntimeException( 'The package destination already exists.' );
			}

			return null;
		}

		$package = $this->packageBySlug( (string) $data['package_type'], $request->packageSlug );
		$this->assertPackageSnapshot( $package, $data, $request );

		return $package;
	}

	private function existingManagementMatchesInstallTarget( DeploymentAttempt $attempt ): bool {
		try {
			$data       = $attempt->safeData();
			$request    = $attempt->getRequest();
			$installed  = $this->installedPackage( (string) $data['package_type'], $request->packageSlug );
			$identifier = $installed->getIdentifier();
			if ( ! is_string( $identifier ) || '' === $identifier ) {
				return false;
			}
			$existing = $this->packageFromIdentifier( (string) $data['package_type'], $identifier );

			return hash_equals( $identifier, (string) $existing->getIdentifier() )
				&& $existing->getProviderCode() === $data['provider']
				&& hash_equals( (string) $existing->getProviderRepositoryId(), (string) $data['provider_repository_id'] )
				&& hash_equals( (string) $existing->getRepository(), $request->repository )
				&& hash_equals( (string) $existing->getSubdirectory(), (string) $request->subdirectory )
				&& hash_equals( (string) $existing->getSlug(), $request->packageSlug );
		} catch ( Throwable ) {
			return false;
		}
	}

	private function assertPackageSnapshot( Package $package, array $data, DeploymentRequest $request ): void {
		if ( $package->getProviderCode() !== $data['provider']
			|| ! hash_equals( (string) $package->getProviderRepositoryId(), (string) $data['provider_repository_id'] )
			|| ! hash_equals( (string) $package->getRepository(), $request->repository )
			|| ! hash_equals( (string) $package->getBranch(), $request->configuredBranch )
			|| ! hash_equals( $package->getCredentialId(), (string) $request->credentialId )
			|| ! hash_equals( (string) $package->getSubdirectory(), (string) $request->subdirectory )
			|| (bool) $package->getPrivate() !== $request->private
			|| ! hash_equals( (string) $package->getSlug(), $request->packageSlug )
			|| $package->getDeploymentPolicy() !== $request->deploymentPolicy
			|| $package->getSource()->value !== ( $data['package_source'] ?? null )
			|| $package->getSourceRevision() !== ( $data['package_source_revision'] ?? null ) ) {
			throw new RuntimeException( 'The managed package changed before deployment.' );
		}
	}

	private function assertBranchSource( Package $package ): void {
		if ( PackageSource::BRANCH !== $package->getSource() ) {
			throw new RuntimeException( 'Branch deployment is unavailable for a release-managed package.' );
		}
	}

	private function assertNotDowngrade( ?Package $baseline, PreparedArtifact $artifact ): void {
		if ( null !== $baseline
			&& version_compare( $artifact->getExpectedVersion(), $baseline->getVersion(), '<' ) ) {
			throw new RuntimeException( 'An older branch package requires an explicit recovery workflow.' );
		}
	}

	private function executeCore( DeploymentAttempt $attempt, PreparedArtifact $artifact, ?Package $baseline ): CorePackageExecutionResult {
		$data    = $attempt->safeData();
		$request = $attempt->getRequest();
		if ( 'install' === $data['operation'] ) {
			return 'plugin' === $data['package_type']
				? $this->executor->installPlugin( $artifact, $request->packageSlug, $request->subdirectory )
				: $this->executor->installTheme( $artifact, $request->packageSlug, $request->subdirectory );
		}
		if ( null === $baseline ) {
			throw new RuntimeException( 'The managed package is unavailable.' );
		}

		return 'plugin' === $data['package_type']
			? $this->executor->updatePlugin( $artifact, $request->packageSlug, $request->subdirectory, (string) $baseline->getIdentifier() )
			: $this->executor->updateTheme( $artifact, $request->packageSlug, $request->subdirectory, (string) $baseline->getIdentifier() );
	}

	private function verifyCoreResult(
		DeploymentAttempt $attempt,
		PreparedArtifact $artifact,
		?Package $baseline,
		?array $baselineState,
		CorePackageExecutionResult $result
	): DeploymentOutcome {
		$data = $attempt->safeData();
		if ( file_exists( $this->maintenancePath ) ) {
			return DeploymentOutcome::fromCode( DeploymentOutcome::CODE_MAINTENANCE_REMAINING );
		}
		if ( $result->isSuccessful() ) {
			if ( 'update' === $data['operation'] ) {
				$this->assertPackageSnapshot(
					$this->packageBySlug( (string) $data['package_type'], $attempt->getRequest()->packageSlug ),
					$data,
					$attempt->getRequest()
				);
			}
			$installed = $this->installedPackage( (string) $data['package_type'], $attempt->getRequest()->packageSlug );
			if ( ! hash_equals( $artifact->getExpectedVersion(), $installed->getVersion() ) ) {
				return DeploymentOutcome::fromCode( DeploymentOutcome::CODE_INSTALLED_VERSION_MISMATCH );
			}
			if ( null !== $baselineState && $this->packageRuntimeState( $installed )['active'] !== $baselineState['active'] ) {
				return DeploymentOutcome::fromCode( DeploymentOutcome::CODE_ACTIVATION_STATE_CHANGED );
			}
			if ( 'install' === $data['operation'] && ! $this->storeInstalledPackage( $attempt, $installed ) ) {
				return DeploymentOutcome::fromCode( DeploymentOutcome::CODE_PERSISTENCE_UNCERTAIN );
			}

			return DeploymentOutcome::fromCode( DeploymentOutcome::CODE_DEPLOYED );
		}

		$failure = $result->getFailure();
		if ( CorePackageExecutionFailure::WORDPRESS_RESTORED === $failure && null !== $baseline && null !== $baselineState && $this->baselineIntact( $baseline, $baselineState ) ) {
			return DeploymentOutcome::fromCode( DeploymentOutcome::CODE_ACTIVATION_FAILED );
		}
		if ( null !== $baseline && null !== $baselineState && $this->baselineIntact( $baseline, $baselineState )
			&& in_array( $failure, array( CorePackageExecutionFailure::WORDPRESS_REFUSED, CorePackageExecutionFailure::WORDPRESS_FAILED ), true ) ) {
			return DeploymentOutcome::fromCode( DeploymentOutcome::CODE_UPGRADER_FAILED );
		}

		return DeploymentOutcome::fromCode( DeploymentOutcome::CODE_RESTORATION_UNCERTAIN );
	}

	private function storeInstalledPackage( DeploymentAttempt $attempt, Package $package ): bool {
		$request    = $attempt->getRequest();
		$repository = new ManagedRepository(
			(string) $attempt->safeData()['provider'],
			$request->repository,
			(string) $attempt->safeData()['provider_repository_id'],
			$request->configuredBranch,
			$request->private,
			$request->credentialId
		);
		$package->setRepository( $repository );
		$package->setSubdirectory( $request->subdirectory );
		$package->setDeploymentPolicy( $request->deploymentPolicy );
		$result = 'plugin' === $attempt->safeData()['package_type']
			? $this->plugins->adopt( $package )
			: $this->themes->adopt( $package );

		if ( $result->isSuccessful() ) {
			return true;
		}

		return 'ran_booster_storage_adoption_conflict' === $result->getDiagnosticId()
			&& $this->existingManagementMatchesInstalledTarget( $attempt, $package );
	}

	private function existingManagementMatchesInstalledTarget( DeploymentAttempt $attempt, Package $installed ): bool {
		$identifier = $installed->getIdentifier();
		if ( ! is_string( $identifier ) || '' === $identifier ) {
			return false;
		}

		try {
			$data     = $attempt->safeData();
			$request  = $attempt->getRequest();
			$existing = $this->packageFromIdentifier( (string) $data['package_type'], $identifier );

			return hash_equals( $identifier, (string) $existing->getIdentifier() )
				&& PackageSource::BRANCH === $existing->getSource()
				&& $existing->getProviderCode() === $data['provider']
				&& hash_equals( (string) $existing->getProviderRepositoryId(), (string) $data['provider_repository_id'] )
				&& hash_equals( (string) $existing->getRepository(), $request->repository )
				&& hash_equals( (string) $existing->getBranch(), $request->configuredBranch )
				&& hash_equals( $existing->getCredentialId(), (string) $request->credentialId )
				&& hash_equals( (string) $existing->getSubdirectory(), (string) $request->subdirectory )
				&& (bool) $existing->getPrivate() === $request->private
				&& hash_equals( (string) $existing->getSlug(), $request->packageSlug )
				&& $existing->getDeploymentPolicy() === $request->deploymentPolicy;
		} catch ( Throwable ) {
			return false;
		}
	}

	/** @param array{version: string, active: bool} $baselineState */
	private function baselineIntact( Package $baseline, array $baselineState ): bool {
		try {
			$current = $this->packageFromIdentifier(
				$baseline instanceof \RAN\Plugin ? 'plugin' : 'theme',
				(string) $baseline->getIdentifier()
			);

			return $this->packageRuntimeState( $current ) === $baselineState;
		} catch ( Throwable ) {
			return false;
		}
	}

	/** @return array{version: string, active: bool} */
	private function packageRuntimeState( Package $package ): array {
		$identifier = (string) $package->getIdentifier();
		$active     = $package instanceof \RAN\Plugin
			? in_array( $identifier, (array) get_option( 'active_plugins', array() ), true )
			: in_array( $identifier, array( (string) get_option( 'stylesheet', '' ), (string) get_option( 'template', '' ) ), true );

		return array(
			'version' => $package->getVersion(),
			'active'  => $active,
		);
	}

	private function installedPackage( string $type, string $slug ): Package {
		return 'plugin' === $type ? $this->plugins->fromSlug( $slug ) : $this->themes->fromSlug( $slug );
	}

	private function packageBySlug( string $type, string $slug ): Package {
		$matches = array_filter(
			'plugin' === $type ? $this->plugins->allDeploymentPlugins() : $this->themes->allDeploymentThemes(),
			static fn ( Package $package ): bool => (string) $package->getSlug() === $slug
		);
		if ( 1 !== count( $matches ) ) {
			throw new RuntimeException( 'The managed package identity is unavailable or ambiguous.' );
		}

		return reset( $matches );
	}

	private function packageFromIdentifier( string $type, string $identifier ): Package {
		return 'plugin' === $type
			? $this->plugins->boosterPluginFromFile( $identifier )
			: $this->themes->boosterThemeFromStylesheet( $identifier );
	}

	private function destinationExists( string $type, string $slug ): bool {
		$root = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root();

		return file_exists( $root . '/' . $slug ) || is_link( $root . '/' . $slug );
	}

	protected function acquireCoreLock(): string {
		return $this->updaterLock->acquire();
	}

	private function releaseCoreLock( string $token ): bool {
		return $this->updaterLock->release( $token );
	}
}
