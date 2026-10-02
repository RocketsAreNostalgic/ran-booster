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

	private WordPressCorePackageExecutor $branch_executor;

	public function __construct(
		private DeploymentAttemptRepository $attempts,
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ProviderRegistry $providers,
		private WordPressWorkerWakeup $wakeup,
		private string $maintenance_path,
		private WordPressUpdaterLock $updater_lock,
		private ?DeploymentFailureNotifier $failure_notifier = null,
		private ?RepositorySourceGuard $source_guard = null,
		?WordPressCorePackageExecutor $branch_executor = null
	) {
		$this->branch_executor = $branch_executor ?? new WordPressCorePackageExecutor();
		$this->source_guard ??= new RepositorySourceGuard();
		if ( '' === trim( $maintenance_path ) ) {
			throw new RuntimeException( 'The WordPress maintenance path is invalid.' );
		}
	}

	/** @return array{status: 'succeeded'|'failed', correlation_id: string, outcome_code: string} */
	public function execute_manual( PackageOperation $command ): array {
		PackageMutationGuard::assert_filesystem_mutation_allowed();
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( 'install' === $command->operation ) {
			$type        = $command->package_type;
			$provider_id = $command->provider_repository_id;
			// The administrator's install request is the one-time authority; this
			// policy governs the package only after installation.
			if ( null === $provider_id ) {
				throw new RuntimeException( 'The package request is not eligible for deployment.' );
			}
			if ( null === $command->provider_code || null === $command->repository || null === $command->branch || null === $command->package_slug ) {
				throw new RuntimeException( 'The package request is incomplete.' );
			}
			$this->source_guard->assert_allowed( $command->provider_code, $provider_id, 'plugin' === $type ? 1 : 2, $command->identifier ?? $command->package_slug, PackageSource::BRANCH );
			$this->providers->get( ProviderCode::parse( $command->provider_code ) );
			$request = new DeploymentRequest(
				$command->repository,
				$command->credential_id,
				$command->is_private,
				$command->branch,
				$command->package_slug,
				$command->subdirectory,
				$command->deployment_policy,
				$user_id > 0 ? $user_id : null
			);
			$attempt = $this->attempts->admit_and_claim_manual(
				'install',
				$type,
				$command->provider_code,
				$provider_id,
				$request,
				(string) $command->branch,
				PackageSource::BRANCH->value,
				0
			);
		} elseif ( 'update' === $command->operation ) {
			$type       = $command->package_type;
			$identifier = $command->identifier ?? throw new RuntimeException( 'The package identity is unavailable.' );
			$package    = $this->package_from_identifier( $type, $identifier );
			$this->assert_submitted_snapshot( $command, $package );
			$this->assert_branch_source( $package );
			if ( ! $package->get_deployment_policy()->allows_manual_mutation() ) {
				throw new RuntimeException( 'The package is disabled for Booster deployments.' );
			}
			$request       = $this->request_from_package( $package, $user_id > 0 ? $user_id : null );
			$requested_ref = null !== $command->ref ? $command->ref : $request->configured_branch;
			$attempt       = $this->attempts->admit_and_claim_manual(
				'update',
				$type,
				(string) $package->get_provider_code(),
				(string) $package->get_provider_repository_id(),
				$request,
				$requested_ref,
				$package->get_source()->value,
				$package->get_source_revision()
			);
		} else {
			throw new RuntimeException( 'The package request is not a deployment.' );
		}

		$outcome = $this->execute_running( $attempt );

		return array(
			'status'         => DeploymentState::SUCCEEDED === $outcome->get_state() ? 'succeeded' : 'failed',
			'correlation_id' => $attempt->get_correlation_id(),
			'outcome_code'   => $outcome->get_code(),
		);
	}

	/**
	 * Persist one validated administrator selection for sequential cron execution.
	 *
	 * @param list<array{package_type: string, provider: string, provider_repository_id: string, requested_ref: string, request: DeploymentRequest}> $targets
	 * @return array{queued: int, busy: int, runner_status: string}
	 */
	public function queue_manual_updates( array $targets ): array {
		RuntimeSupport::assert_managed_operations_allowed();
		$admission = $this->attempts->admit_manual_batch( $targets );
		$queued    = count( $admission['admitted'] );

		return array(
			'queued'        => $queued,
			'busy'          => count( $admission['busy'] ),
			'runner_status' => $queued > 0 ? $this->request_worker() : 'not_required',
		);
	}

	private function assert_submitted_snapshot( PackageOperation $command, Package $package ): void {
		$expected = $command->expected_package;
		if ( ! $command->has_expected_package()
			|| $package->get_provider_code() !== $expected['provider']
			|| ! hash_equals( (string) $package->get_provider_repository_id(), (string) $expected['provider_repository_id'] )
			|| ! hash_equals( (string) $package->get_repository(), (string) $expected['repository'] )
			|| ! hash_equals( (string) $package->get_branch(), (string) $expected['branch'] )
			|| ! hash_equals( $package->get_credential_id(), (string) $expected['credential_id'] )
			|| ! hash_equals( (string) $package->get_subdirectory(), (string) $expected['subdirectory'] )
			|| (bool) $package->get_private() !== $expected['private']
			|| ! hash_equals( (string) $package->get_slug(), (string) $expected['package_slug'] )
			|| $package->get_deployment_policy() !== $expected['deployment_policy']
			|| $package->get_source() !== $expected['source']
			|| $package->get_source_revision() !== $expected['source_revision'] ) {
			throw new RuntimeException( 'The managed package changed after this form was opened.' );
		}
	}

	/**
	 * @param list<PushEvent> $events
	 * @return array{status: string, correlation_id: string, accepted_targets: int, runner_status: string}
	 */
	public function accept_webhook( array $events, string $authenticated_body_digest ): array {
		PackageMutationGuard::assert_webhook_dispatch_allowed();
		if ( array() === $events || preg_match( '/^[a-f0-9]{64}$/D', $authenticated_body_digest ) !== 1 ) {
			throw new RuntimeException( 'The authenticated webhook delivery is invalid.' );
		}
		$first    = $events[0];
		$provider = $first->provider->value;
		$delivery_id = $first->delivery_id;
		$targets     = array();

		foreach ( $events as $event ) {
			if ( ! $event instanceof PushEvent || $event->provider->value !== $provider || $event->delivery_id !== $delivery_id ) {
				throw new RuntimeException( 'One webhook request must identify one provider delivery.' );
			}
			try {
				$matches = $this->matching_packages( $event );
			} catch ( PackageStorageFailure $failure ) {
				if ( $failure->is_database_unsupported() ) {
					throw DeploymentStorageFailure::unsupported_database();
				}
				throw $failure;
			}
			foreach ( $matches as $match ) {
				$key = $match['type'] . "\0" . $match['package']->get_slug();
				if ( isset( $targets[ $key ] ) && $targets[ $key ]['requested_ref'] !== $event->commit ) {
					throw new RuntimeException( 'Conflicting webhook events target one package.' );
				}
				$targets[ $key ] = array(
					'operation'               => 'update',
					'package_type'            => $match['type'],
					'provider_repository_id'  => $event->provider_repository_id,
					'requested_ref'           => $event->commit,
					'package_source'          => $match['package']->get_source()->value,
					'package_source_revision' => $match['package']->get_source_revision(),
					'request'                 => $this->request_from_package( $match['package'], null ),
				);
			}
		}
		PackageMutationGuard::assert_deployment_target_count( count( $targets ) );
		if ( array() === $targets ) {
			$this->attempts->admit_webhook_batch( $provider, $delivery_id, $authenticated_body_digest, array() );
			return $this->admission( 'accepted', substr( hash( 'sha256', $provider . "\0" . $delivery_id ), 0, 32 ), 0, 'not_required' );
		}
		$attempts = $this->attempts->admit_webhook_batch( $provider, $delivery_id, $authenticated_body_digest, array_values( $targets ) );
		if ( array() === $attempts ) {
			return $this->admission( 'duplicate', substr( hash( 'sha256', $provider . "\0" . $delivery_id ), 0, 32 ), 0, 'not_required' );
		}
		$status = count( array_filter( $attempts, static fn ( DeploymentAttempt $attempt ): bool => DeploymentState::QUEUED === $attempt->get_state() ) ) > 0 ? 'accepted' : 'duplicate';
		return $this->admission( $status, $attempts[0]->get_correlation_id(), count( $attempts ), $this->request_worker() );
	}

	/** Execute one queued row claimed by the real WordPress cron worker. */
	public function execute_claimed( DeploymentAttempt $attempt ): DeploymentOutcome {
		RuntimeSupport::assert_managed_operations_allowed();
		if ( ! wp_doing_cron() ) {
			throw new RuntimeException( 'The deployment worker is unavailable outside WordPress cron.' );
		}
		return $this->execute_running( $attempt );
	}

	/** Execute one durably running row through the normalized admitted branch runner. */
	private function execute_running( DeploymentAttempt $attempt ): DeploymentOutcome {
		if ( DeploymentState::RUNNING !== $attempt->get_state() ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		$context = $attempt->log_context();
		BoosterLogger::log( 'deployment execution started', $context + array( 'step' => 'execute_running' ) );
		$host = new AdmittedBranchHostAdapter(
			$attempt,
			$this->attempts,
			$this->plugins,
			$this->themes,
			$this->providers,
			$this->source_guard,
			$this->updater_lock,
			$this->branch_executor,
			$this->maintenance_path
		);

		try {
			$declaration = $host->declaration();
		} catch ( AdmittedBranchStageFailure $failure ) {
			$host->finish( $failure->outcome_code );
			return $this->finished_outcome( $host->terminal_attempt() );
		}

		$updater    = BranchUpdater::for_admitted_attempt( $declaration, $host, $host, $host, $host, $host );
		$deployment = 'plugin' === $declaration->package_type
			? $updater->plugin(
				$declaration->repository,
				$declaration->repository_id,
				$declaration->branch,
				null,
				$declaration->slug,
				$declaration->subdirectory
			)
			: $updater->theme(
				$declaration->repository,
				$declaration->repository_id,
				$declaration->branch,
				$declaration->slug,
				$declaration->subdirectory
			);
		$code       = $deployment->deploy();
		$outcome    = $this->finished_outcome( $host->terminal_attempt() );
		if ( ! hash_equals( $code, $outcome->get_code() ) ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		return $outcome;
	}

	private function finished_outcome( DeploymentAttempt $finished ): DeploymentOutcome {
		if ( ! $finished->get_state()->is_terminal() ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$outcome = $finished->get_outcome() ?? throw DeploymentStorageFailure::inconsistent();
		BoosterLogger::log(
			'attempt finished',
			$finished->log_context() + array(
				'step'         => 'attempt_finished',
				'outcome_code' => $outcome->get_code(),
			)
		);
		$data = $finished->safe_data();
		if ( null !== $this->failure_notifier
			&& 'webhook' === $data['source']
			&& in_array( $finished->get_state(), array( DeploymentState::FAILED, DeploymentState::NEEDS_ATTENTION ), true )
		) {
			try {
				$this->failure_notifier->notify( $finished );
			} catch ( Throwable $exception ) {
				BoosterLogger::log_exception(
					'background deployment failure notification unavailable',
					$exception,
					$finished->log_context() + array( 'step' => 'background_failure_notification' )
				);
			}
		}
		return $outcome;
	}

	/** Reconcile only after the protected controller confirms the worker stopped. */
	public function reconcile_confirmed_stopped( int $attempt_id, string $correlation_id ): DeploymentAttempt {
		$attempt = $this->attempts->find_exact( $attempt_id );
		if ( null === $attempt || ! hash_equals( $attempt->get_correlation_id(), $correlation_id ) ) {
			throw DeploymentStorageFailure::not_found();
		}
		if ( DeploymentState::RUNNING !== $attempt->get_state() ) {
			throw DeploymentStorageFailure::inconsistent();
		}
		$result = $this->attempts->reconcile_confirmed_stopped( $attempt_id );
		$this->request_worker();
		return $result;
	}

	/** Protected admin seam for re-prompting the one-shot runner. */
	public function request_runner(): string {
		return $this->request_worker( true );
	}

	/** @return array{status: string, correlation_id: string, accepted_targets: int, runner_status: string} */
	private function admission( string $status, string $correlation_id, int $targets, string $runner ): array {
		if ( ! in_array( $status, array( 'accepted', 'duplicate' ), true )
			|| preg_match( '/^[a-f0-9]{32}$/D', $correlation_id ) !== 1
			|| $targets < 0 || $targets > PackageMutationGuard::MAX_DEPLOYMENT_TARGETS
			|| ! in_array( $runner, array( 'scheduled', 'already_scheduled', 'unavailable', 'not_required' ), true ) ) {
			throw new RuntimeException( 'The deployment admission result is invalid.' );
		}
		return array(
			'status'           => $status,
			'correlation_id'   => $correlation_id,
			'accepted_targets' => $targets,
			'runner_status'    => $runner,
		);
	}

	private function request_worker( bool $spawn = false ): string {
		$status = $this->wakeup->request();
		if ( $spawn && 'unavailable' !== $status && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return $status;
	}

	private function request_from_package( Package $package, ?int $user_id ): DeploymentRequest {
		$this->assert_branch_source( $package );
		$provider = $package->get_provider_code();
		if ( null === $provider || null === $package->get_provider_repository_id() ) {
			throw new RuntimeException( 'The managed package provider identity is incomplete.' );
		}
		$this->providers->get( ProviderCode::parse( $provider ) );
		return new DeploymentRequest(
			(string) $package->get_repository(),
			'' === $package->get_credential_id() ? null : $package->get_credential_id(),
			(bool) $package->get_private(),
			(string) $package->get_branch(),
			(string) $package->get_slug(),
			is_string( $package->get_subdirectory() ) ? $package->get_subdirectory() : null,
			$package->get_deployment_policy(),
			$user_id,
			PackageArtifactLimit::resolve( null )
		);
	}

	/** @return list<array{type: string, package: Package}> */
	private function matching_packages( PushEvent $event ): array {
		$matches    = array();
		$normalizer = $this->providers->require_capability( $event->provider, WebhookNormalizer::class );
		$policy     = $normalizer->get_webhook_policy();
		foreach ( array(
			'plugin' => $this->plugins->all_deployment_plugins(),
			'theme'  => $this->themes->all_deployment_themes(),
		) as $type => $packages ) {
			foreach ( $packages as $package ) {
				if ( PackageSource::BRANCH === $package->get_source()
					&& $package->get_deployment_policy()->allows_webhook_mutation()
					&& ! PackageMutationGuard::is_booster_plugin_file( $package->get_identifier() )
					&& $package->get_provider_code() === $event->provider->value
					&& $policy->repository_target_matches( $event->repository, (string) $package->get_repository() )
					&& (string) $package->get_branch() === $event->branch
					&& null !== $package->get_provider_repository_id()
					&& hash_equals( (string) $package->get_provider_repository_id(), $event->provider_repository_id ) ) {
					$matches[] = array(
						'type'    => $type,
						'package' => $package,
					);
				}
			}
		}
		return $matches;
	}

	private function assert_branch_source( Package $package ): void {
		if ( PackageSource::BRANCH !== $package->get_source() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal domain exception; presentation owns escaping.
			throw new DeploymentCheckFailure( DeploymentOutcome::CODE_DEPLOYMENT_RELEASE_SOURCE_BLOCKED, 'Branch deployment is unavailable for a release-managed package.' );
		}
	}

	private function package_from_identifier( string $type, string $identifier ): Package {
		return 'plugin' === $type ? $this->plugins->booster_plugin_from_file( $identifier ) : $this->themes->booster_theme_from_stylesheet( $identifier );
	}
}
