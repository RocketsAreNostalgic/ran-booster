<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\PackageMutationGuard;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\UnknownProvider;
use RAN\RepositoryProvider\UnsupportedProviderCapability;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginNotFound;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeNotFound;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use Throwable;

/** Coordinates validated bulk admin intent without performing synchronous package mutations. */
final readonly class BulkPackageActionService {

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private ProviderRegistry $providers,
		private SecretsFile $secrets,
		private DeploymentCoordinator $deployments,
		private WordPressUpdaterLock $updater_lock
	) {
	}

	public function execute( BulkPackageAction $action ): BulkPackageResult {
		PackageMutationGuard::assert_bulk_admin_allowed( $action->package_type, $action->identifiers );

		if ( $action->is_update_queue() ) {
			return $this->queue_updates( $action );
		}

		return $this->with_updater_lock(
			$action,
			fn (): BulkPackageResult => $action->is_plugin_activation()
				? $this->change_plugin_activation( $action )
				: $this->change_policy(
					$action,
					$action->deployment_policy() ?? throw new \LogicException( 'The bulk package policy is unavailable.' )
				)
		);
	}

	/**
	 * @param callable(): BulkPackageResult $mutation
	 */
	private function with_updater_lock( BulkPackageAction $action, callable $mutation ): BulkPackageResult {
		try {
			$token = $this->updater_lock->acquire();
		} catch ( Throwable ) {
			return BulkPackageResult::error( $action->operation, count( $action->identifiers ), 'unavailable' );
		}

		$result  = null;
		$failure = null;
		try {
			$result = $mutation();
		} catch ( Throwable $caught ) {
			$failure = $caught;
		}

		try {
			$released = $this->updater_lock->release( $token );
		} catch ( Throwable ) {
			$released = false;
		}
		if ( ! $released ) {
			return BulkPackageResult::error( $action->operation, count( $action->identifiers ), 'unavailable' );
		}
		if ( null !== $failure ) {
			throw $failure;
		}

		return $result ?? BulkPackageResult::error( $action->operation, count( $action->identifiers ), 'unavailable' );
	}

	private function change_plugin_activation( BulkPackageAction $action ): BulkPackageResult {
		if ( 'plugin' !== $action->package_type ) {
			throw new \LogicException( 'Plugin activation is unavailable for themes.' );
		}

		$activate  = BulkPackageAction::ACTIVATE_PLUGINS === $action->operation;
		$changed   = 0;
		$unchanged = 0;
		$skipped   = array();

		if ( ! $activate ) {
			\WP_Plugin_Dependencies::initialize();
		}

		foreach ( $action->identifiers as $identifier ) {
			if ( ! $activate && PackageMutationGuard::is_booster_plugin_file( $identifier ) ) {
				$this->increment( $skipped, 'self_deactivation' );
				continue;
			}

			try {
				$this->find( 'plugin', $identifier );
			} catch ( BulkPackageActionFailure $failure ) {
				$this->increment( $skipped, $failure->reason );
				continue;
			}

			$is_active = is_plugin_active( $identifier );
			if ( $activate === $is_active ) {
				++$unchanged;
				continue;
			}
			if ( ! $activate && \WP_Plugin_Dependencies::has_active_dependents( $identifier ) ) {
				$this->increment( $skipped, 'active_dependents' );
				continue;
			}

			$meta_capability = $activate ? 'activate_plugin' : 'deactivate_plugin';
			if ( ! current_user_can( $meta_capability, $identifier ) ) {
				$this->increment( $skipped, 'permission' );
				continue;
			}

			if ( $activate ) {
				try {
					activate_plugin(
						$identifier,
						admin_url( 'plugins.php?error=true&plugin=' . rawurlencode( $identifier ) )
					);
				} catch ( \Throwable ) {
					if ( ! is_plugin_active( $identifier ) ) {
						$this->increment( $skipped, 'activation_failed' );
						continue;
					}
				}
				if ( ! is_plugin_active( $identifier ) ) {
					$this->increment( $skipped, 'activation_failed' );
					continue;
				}
			} else {
				try {
					deactivate_plugins( $identifier, false, false );
				} catch ( \Throwable ) {
					if ( is_plugin_active( $identifier ) ) {
						$this->increment( $skipped, 'deactivation_failed' );
						continue;
					}
				}
				if ( is_plugin_active( $identifier ) ) {
					$this->increment( $skipped, 'deactivation_failed' );
					continue;
				}
			}

			++$changed;
		}

		return BulkPackageResult::plugin_activation(
			$action->operation,
			count( $action->identifiers ),
			$changed,
			$unchanged,
			$skipped
		);
	}

	private function change_policy( BulkPackageAction $action, DeploymentPolicy $policy ): BulkPackageResult {
		$snapshots = array();
		foreach ( $action->identifiers as $identifier ) {
			if ( 'plugin' === $action->package_type ) {
				PackageMutationGuard::assert_plugin_file_allowed( $identifier );
			}
			$package = $this->find( $action->package_type, $identifier );
			if ( DeploymentPolicy::DISABLED !== $policy ) {
				$this->assert_ready(
					$package,
					DeploymentPolicy::AUTOMATIC === $policy
						&& PackageSource::BRANCH === $package->getSource()
				);
			}
			$snapshots[] = $this->snapshot( $package );
		}

		$result = 'plugin' === $action->package_type
			? $this->plugins->setPluginDeploymentPolicies( $snapshots, $policy )
			: $this->themes->setThemeDeploymentPolicies( $snapshots, $policy );

		return BulkPackageResult::policy( $action->operation, $result );
	}

	private function queue_updates( BulkPackageAction $action ): BulkPackageResult {
		$targets = array();
		$skipped = array();
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		foreach ( $action->identifiers as $identifier ) {
			if ( 'plugin' === $action->package_type && PackageMutationGuard::is_booster_plugin_file( $identifier ) ) {
				$this->increment( $skipped, 'self_update' );
				continue;
			}
			try {
				$package = $this->find( $action->package_type, $identifier );
			} catch ( BulkPackageActionFailure $failure ) {
				$this->increment( $skipped, $failure->reason );
				continue;
			}
			if ( ! $package->getDeploymentPolicy()->allows_manual_mutation() ) {
				$this->increment( $skipped, 'disabled' );
				continue;
			}
			if ( PackageSource::BRANCH !== $package->getSource() ) {
				$this->increment( $skipped, 'release_source' );
				continue;
			}
			try {
				$this->assert_ready( $package, false );
			} catch ( BulkPackageActionFailure $failure ) {
				$this->increment( $skipped, $failure->reason );
				continue;
			}

			$provider_code = (string) $package->getProviderCode();
			$request       = new DeploymentRequest(
				(string) $package->getRepository(),
				'' === $package->getCredentialId() ? null : $package->getCredentialId(),
				(bool) $package->getPrivate(),
				(string) $package->getBranch(),
				(string) $package->getSlug(),
				is_string( $package->getSubdirectory() ) ? $package->getSubdirectory() : null,
				$package->getDeploymentPolicy(),
				$user_id > 0 ? $user_id : null
			);
			$targets[]     = array(
				'package_type'            => $action->package_type,
				'provider'                => $provider_code,
				'provider_repository_id'  => (string) $package->getProviderRepositoryId(),
				'requested_ref'           => $request->configured_branch,
				'package_source'          => $package->getSource()->value,
				'package_source_revision' => $package->getSourceRevision(),
				'request'                 => $request,
			);
		}

		if ( array() === $targets ) {
			return BulkPackageResult::queue( count( $action->identifiers ), 0, $skipped, 'not_required' );
		}

		$admission = $this->deployments->queueManualUpdates( $targets );
		if ( $admission['busy'] > 0 ) {
			$skipped['busy'] = ( $skipped['busy'] ?? 0 ) + $admission['busy'];
		}

		return BulkPackageResult::queue(
			count( $action->identifiers ),
			$admission['queued'],
			$skipped,
			$admission['runner_status']
		);
	}

	private function find( string $package_type, string $identifier ): Package {
		try {
			return 'plugin' === $package_type
				? $this->plugins->boosterPluginFromFile( $identifier )
				: $this->themes->boosterThemeFromStylesheet( $identifier );
		} catch ( PluginNotFound | ThemeNotFound ) {
			throw BulkPackageActionFailure::stale_selection();
		} catch ( PackageStorageFailure $failure ) {
			throw $failure;
		}
	}

	private function assert_ready( Package $package, bool $webhook_required ): void {
		$provider_code = $package->getProviderCode();
		if ( null === $provider_code || null === $package->getProviderRepositoryId() ) {
			throw BulkPackageActionFailure::unavailable_provider();
		}

		try {
			$this->providers->get( $provider_code );
		} catch ( UnknownProvider ) {
			throw BulkPackageActionFailure::unavailable_provider();
		}

		if ( $webhook_required ) {
			try {
				$this->providers->requireCapability( $provider_code, WebhookNormalizer::class );
			} catch ( UnsupportedProviderCapability ) {
				throw BulkPackageActionFailure::unavailable_webhook();
			}
		}

		$credential_id = $package->getCredentialId();
		if ( ( $package->getPrivate() || '' !== $credential_id )
			&& null === $this->secrets->credentialMaterial( $provider_code, '' === $credential_id ? null : $credential_id ) ) {
			throw BulkPackageActionFailure::unavailable_credential();
		}
	}

	/** @return array<string, mixed> */
	private function snapshot( Package $package ): array {
		return array(
			'package'                => (string) $package->getIdentifier(),
			'repository'             => (string) $package->getRepository(),
			'branch'                 => (string) $package->getBranch(),
			'deployment_policy'      => $package->getDeploymentPolicy()->value,
			'provider'               => (string) $package->getProviderCode(),
			'provider_repository_id' => (string) $package->getProviderRepositoryId(),
			'private'                => $package->getPrivate() ? 1 : 0,
			'credential_id'          => '' === $package->getCredentialId() ? null : $package->getCredentialId(),
			'subdirectory'           => $package->getSubdirectory(),
			'source'                 => $package->getSource()->value,
			'source_revision'        => $package->getSourceRevision(),
		);
	}

	/** @param array<string, int> $counts */
	private function increment( array &$counts, string $reason ): void {
		$counts[ $reason ] = ( $counts[ $reason ] ?? 0 ) + 1;
	}
}
