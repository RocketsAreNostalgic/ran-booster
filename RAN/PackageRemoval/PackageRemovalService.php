<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\PackageMutationGuard;
use RAN\Logging\BoosterLogger;
use RAN\Package;
use RAN\PackageOperation;
use RAN\Runtime\RuntimeSupport;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use Throwable;

/**
 * Coordinates confirmed unlinking and WordPress-native uninstall/delete flows.
 */
final readonly class PackageRemovalService {

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private PackageRemovalGateway $wordpress,
		private ?DeploymentAttemptRepository $attempts,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		private WordPressUpdaterLock $updaterLock,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		private ?RepositoryBranchCheckEvidenceStore $branchCheckEvidence = null
	) {
	}

	public function execute( PackageOperation $operation ): PackageRemovalResult {
		RuntimeSupport::assertManagedOperationsAllowed();

		if ( ! in_array( $operation->operation, array( 'unlink', 'unlink-and-delete' ), true ) ) {
			throw new \LogicException( 'The package removal operation is invalid.' );
		}

		$identifier = $operation->identifier ?? throw new \RuntimeException( 'The package identity is unavailable.' );
		if ( 'unlink-and-delete' === $operation->operation ) {
			PackageMutationGuard::assert_filesystem_mutation_allowed();
		}
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor properties retain the existing public named-parameter contract.
			$lock_token = $this->updaterLock->acquire();
		} catch ( Throwable $failure ) {
			$this->log_failure( $failure, 'package_removal_lock_acquire' );
			return PackageRemovalResult::failed( 'operation_in_progress' );
		}

		$result = PackageRemovalResult::failed( 'management_state_uncertain' );
		try {
			$package = $this->find( $operation->package_type, $identifier );
			if ( $package->get_source_revision() !== $operation->get_expected_source_revision() ) {
				$result = PackageRemovalResult::failed( 'stale' );
			} elseif ( 'unlink' === $operation->operation ) {
				$this->unlink( $operation->package_type, $identifier, $package );
				$result = PackageRemovalResult::unlinked();
			} elseif ( null !== $this->attempts
				&& $this->attempts->hasUnresolvedPackageAttempt(
					$operation->package_type,
					(string) $package->get_slug()
				) ) {
				$result = PackageRemovalResult::failed( 'operation_in_progress' );
			} else {
				$blocker = $this->deletion_blocker( $operation->package_type, $identifier );
				if ( null !== $blocker ) {
					$result = PackageRemovalResult::failed( $blocker );
				} else {
					$this->disable( $operation->package_type, $package );
					$result = 'plugin' === $operation->package_type
						? $this->delete_plugin( $identifier, $package )
						: $this->delete_theme( $identifier, $package );
				}
			}
		} catch ( Throwable $failure ) {
			if ( 'unlink' === $operation->operation ) {
				throw $failure;
			}
			$this->log_failure( $failure, 'package_removal_state' );
		} finally {
			try {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor properties retain the existing public named-parameter contract.
				if ( ! $this->updaterLock->release( $lock_token ) ) {
					$result = PackageRemovalResult::failed( 'operation_lock_failed' );
				}
			} catch ( Throwable $failure ) {
				$this->log_failure( $failure, 'package_removal_lock_release' );
				$result = PackageRemovalResult::failed( 'operation_lock_failed' );
			}
		}

		return $result;
	}

	private function delete_plugin( string $identifier, Package $package ): PackageRemovalResult {
		if ( $this->wordpress->plugin_is_active( $identifier ) ) {
			try {
				$this->wordpress->deactivate_plugin( $identifier );
			} catch ( Throwable $failure ) {
				$this->log_failure( $failure, 'plugin_deactivation' );
			}
			if ( $this->wordpress->plugin_is_active( $identifier ) ) {
				return PackageRemovalResult::failed( 'deactivation_failed' );
			}
		}

		return $this->delete_files(
			'plugin',
			$identifier,
			$package,
			fn (): bool => $this->wordpress->delete_plugin( $identifier )
		);
	}

	private function delete_theme( string $stylesheet, Package $package ): PackageRemovalResult {
		return $this->delete_files(
			'theme',
			$stylesheet,
			$package,
			fn (): bool => $this->wordpress->delete_theme( $stylesheet )
		);
	}

	/**
	 * @param callable(): bool $delete
	 */
	private function delete_files( string $type, string $identifier, Package $package, callable $delete ): PackageRemovalResult {
		$reported_success = false;
		try {
			$reported_success = $delete();
		} catch ( Throwable $failure ) {
			$this->log_failure( $failure, $type . '_deletion' );
		}

		if ( $this->is_installed( $type, $identifier ) ) {
			return PackageRemovalResult::failed( $reported_success ? 'files_still_present' : 'deletion_failed' );
		}

		try {
			$this->unlink( $type, $identifier, $package );
		} catch ( Throwable $failure ) {
			$this->log_failure( $failure, 'management_unlink_after_deletion' );

			return PackageRemovalResult::failed( 'management_state_uncertain' );
		}

		return PackageRemovalResult::deleted();
	}

	private function disable( string $type, Package $package ): void {
		$result = 'plugin' === $type
			? $this->plugins->disablePluginForRemoval( $package )
			: $this->themes->disableThemeForRemoval( $package );
		$result->require_success();
	}

	private function deletion_blocker( string $type, string $identifier ): ?string {
		if ( 'plugin' === $type ) {
			if ( ! $this->wordpress->plugin_path_is_safe( $identifier ) ) {
				return 'unsafe_path';
			}
			if ( $this->wordpress->plugin_shares_directory( $identifier ) ) {
				return 'shared_plugin_directory';
			}
			if ( $this->wordpress->plugin_has_active_dependents( $identifier ) ) {
				return 'active_dependents';
			}

			return null;
		}

		if ( ! $this->wordpress->theme_path_is_safe( $identifier ) ) {
			return 'unsafe_path';
		}

		return $this->wordpress->theme_deletion_blocker( $identifier );
	}

	private function find( string $type, string $identifier ): Package {
		return 'plugin' === $type
			? $this->plugins->boosterPluginFromFile( $identifier )
			: $this->themes->boosterThemeFromStylesheet( $identifier );
	}

	private function unlink( string $type, string $identifier, Package $package ): void {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor properties retain the existing public named-parameter contract.
		$this->branchCheckEvidence?->clear( $type, $package );
		$result = 'plugin' === $type
			? $this->plugins->unlink( $identifier )
			: $this->themes->unlink( $identifier );
		$result->require_success();
	}

	private function is_installed( string $type, string $identifier ): bool {
		return 'plugin' === $type
			? $this->plugins->isInstalled( $identifier )
			: $this->themes->isInstalled( $identifier );
	}

	private function log_failure( Throwable $failure, string $step ): void {
		BoosterLogger::logException(
			'package removal failed',
			$failure,
			array(
				'event' => 'package_removal_failed',
				'step'  => $step,
			)
		);
	}
}
