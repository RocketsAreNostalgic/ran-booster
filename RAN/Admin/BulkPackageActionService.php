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
		private WordPressUpdaterLock $updaterLock
	) {
	}

	public function execute( BulkPackageAction $action ): BulkPackageResult {
		PackageMutationGuard::assertBulkAdminAllowed( $action->packageType, $action->identifiers );

		if ( $action->isUpdateQueue() ) {
			return $this->queueUpdates( $action );
		}

		return $this->withUpdaterLock(
			$action,
			fn (): BulkPackageResult => $action->isPluginActivation()
				? $this->changePluginActivation( $action )
				: $this->changePolicy(
					$action,
					$action->deploymentPolicy() ?? throw new \LogicException( 'The bulk package policy is unavailable.' )
				)
		);
	}

	/**
	 * @param callable(): BulkPackageResult $mutation
	 */
	private function withUpdaterLock( BulkPackageAction $action, callable $mutation ): BulkPackageResult {
		try {
			$token = $this->updaterLock->acquire();
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
			$released = $this->updaterLock->release( $token );
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

	private function changePluginActivation( BulkPackageAction $action ): BulkPackageResult {
		if ( 'plugin' !== $action->packageType ) {
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
			if ( ! $activate && PackageMutationGuard::isBoosterPluginFile( $identifier ) ) {
				$this->increment( $skipped, 'self_deactivation' );
				continue;
			}

			try {
				$this->find( 'plugin', $identifier );
			} catch ( BulkPackageActionFailure $failure ) {
				$this->increment( $skipped, $failure->reason );
				continue;
			}

			$isActive = is_plugin_active( $identifier );
			if ( $activate === $isActive ) {
				++$unchanged;
				continue;
			}
			if ( ! $activate && \WP_Plugin_Dependencies::has_active_dependents( $identifier ) ) {
				$this->increment( $skipped, 'active_dependents' );
				continue;
			}

			$metaCapability = $activate ? 'activate_plugin' : 'deactivate_plugin';
			if ( ! current_user_can( $metaCapability, $identifier ) ) {
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

		return BulkPackageResult::pluginActivation(
			$action->operation,
			count( $action->identifiers ),
			$changed,
			$unchanged,
			$skipped
		);
	}

	private function changePolicy( BulkPackageAction $action, DeploymentPolicy $policy ): BulkPackageResult {
		$snapshots = array();
		foreach ( $action->identifiers as $identifier ) {
			if ( 'plugin' === $action->packageType ) {
				PackageMutationGuard::assertPluginFileAllowed( $identifier );
			}
			$package = $this->find( $action->packageType, $identifier );
			if ( DeploymentPolicy::DISABLED !== $policy ) {
				$this->assertReady(
					$package,
					DeploymentPolicy::AUTOMATIC === $policy
						&& PackageSource::BRANCH === $package->getSource()
				);
			}
			$snapshots[] = $this->snapshot( $package );
		}

		$result = 'plugin' === $action->packageType
			? $this->plugins->setPluginDeploymentPolicies( $snapshots, $policy )
			: $this->themes->setThemeDeploymentPolicies( $snapshots, $policy );

		return BulkPackageResult::policy( $action->operation, $result );
	}

	private function queueUpdates( BulkPackageAction $action ): BulkPackageResult {
		$targets = array();
		$skipped = array();
		$userId  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		foreach ( $action->identifiers as $identifier ) {
			if ( 'plugin' === $action->packageType && PackageMutationGuard::isBoosterPluginFile( $identifier ) ) {
				$this->increment( $skipped, 'self_update' );
				continue;
			}
			try {
				$package = $this->find( $action->packageType, $identifier );
			} catch ( BulkPackageActionFailure $failure ) {
				$this->increment( $skipped, $failure->reason );
				continue;
			}
			if ( ! $package->getDeploymentPolicy()->allowsManualMutation() ) {
				$this->increment( $skipped, 'disabled' );
				continue;
			}
			if ( PackageSource::BRANCH !== $package->getSource() ) {
				$this->increment( $skipped, 'release_source' );
				continue;
			}
			try {
				$this->assertReady( $package, false );
			} catch ( BulkPackageActionFailure $failure ) {
				$this->increment( $skipped, $failure->reason );
				continue;
			}

			$providerCode = (string) $package->getProviderCode();
			$request      = new DeploymentRequest(
				(string) $package->getRepository(),
				'' === $package->getCredentialId() ? null : $package->getCredentialId(),
				(bool) $package->getPrivate(),
				(string) $package->getBranch(),
				(string) $package->getSlug(),
				is_string( $package->getSubdirectory() ) ? $package->getSubdirectory() : null,
				$package->getDeploymentPolicy(),
				$userId > 0 ? $userId : null
			);
			$targets[]    = array(
				'package_type'            => $action->packageType,
				'provider'                => $providerCode,
				'provider_repository_id'  => (string) $package->getProviderRepositoryId(),
				'requested_ref'           => $request->configuredBranch,
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

	private function find( string $packageType, string $identifier ): Package {
		try {
			return 'plugin' === $packageType
				? $this->plugins->boosterPluginFromFile( $identifier )
				: $this->themes->boosterThemeFromStylesheet( $identifier );
		} catch ( PluginNotFound | ThemeNotFound ) {
			throw BulkPackageActionFailure::staleSelection();
		} catch ( PackageStorageFailure $failure ) {
			throw $failure;
		}
	}

	private function assertReady( Package $package, bool $webhookRequired ): void {
		$providerCode = $package->getProviderCode();
		if ( null === $providerCode || null === $package->getProviderRepositoryId() ) {
			throw BulkPackageActionFailure::unavailableProvider();
		}

		try {
			$this->providers->get( $providerCode );
		} catch ( UnknownProvider ) {
			throw BulkPackageActionFailure::unavailableProvider();
		}

		if ( $webhookRequired ) {
			try {
				$this->providers->requireCapability( $providerCode, WebhookNormalizer::class );
			} catch ( UnsupportedProviderCapability ) {
				throw BulkPackageActionFailure::unavailableWebhook();
			}
		}

		$credentialId = $package->getCredentialId();
		if ( ( $package->getPrivate() || '' !== $credentialId )
			&& null === $this->secrets->credentialMaterial( $providerCode, '' === $credentialId ? null : $credentialId ) ) {
			throw BulkPackageActionFailure::unavailableCredential();
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
