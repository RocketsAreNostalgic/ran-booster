<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

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
		private WordPressUpdaterLock $updaterLock
	) {
	}

	public function execute( PackageOperation $operation ): PackageRemovalResult {
		RuntimeSupport::assertManagedOperationsAllowed();

		if ( ! in_array( $operation->operation, array( 'unlink', 'unlink-and-delete' ), true ) ) {
			throw new \LogicException( 'The package removal operation is invalid.' );
		}

		$identifier = $operation->identifier ?? throw new \RuntimeException( 'The package identity is unavailable.' );
		if ( 'unlink-and-delete' === $operation->operation ) {
			PackageMutationGuard::assertFilesystemMutationAllowed();
		}
		try {
			$lockToken = $this->updaterLock->acquire();
		} catch ( Throwable $failure ) {
			$this->logFailure( $failure, 'package_removal_lock_acquire' );
			return PackageRemovalResult::failed( 'operation_in_progress' );
		}

		$result = PackageRemovalResult::failed( 'management_state_uncertain' );
		try {
			$package = $this->find( $operation->packageType, $identifier );
			if ( $package->getSourceRevision() !== $operation->getExpectedSourceRevision() ) {
				$result = PackageRemovalResult::failed( 'stale' );
			} elseif ( 'unlink' === $operation->operation ) {
				$this->unlink( $operation->packageType, $identifier );
				$result = PackageRemovalResult::unlinked();
			} elseif ( null !== $this->attempts
				&& $this->attempts->hasUnresolvedPackageAttempt(
					$operation->packageType,
					(string) $package->getSlug()
				) ) {
				$result = PackageRemovalResult::failed( 'operation_in_progress' );
			} else {
				$blocker = $this->deletionBlocker( $operation->packageType, $identifier );
				if ( null !== $blocker ) {
					$result = PackageRemovalResult::failed( $blocker );
				} else {
					$this->disable( $operation->packageType, $package );
					$result = 'plugin' === $operation->packageType
						? $this->deletePlugin( $identifier )
						: $this->deleteTheme( $identifier );
				}
			}
		} catch ( Throwable $failure ) {
			if ( 'unlink' === $operation->operation ) {
				throw $failure;
			}
			$this->logFailure( $failure, 'package_removal_state' );
		} finally {
			try {
				if ( ! $this->updaterLock->release( $lockToken ) ) {
					$result = PackageRemovalResult::failed( 'operation_lock_failed' );
				}
			} catch ( Throwable $failure ) {
				$this->logFailure( $failure, 'package_removal_lock_release' );
				$result = PackageRemovalResult::failed( 'operation_lock_failed' );
			}
		}

		return $result;
	}

	private function deletePlugin( string $identifier ): PackageRemovalResult {
		if ( $this->wordpress->pluginIsActive( $identifier ) ) {
			try {
				$this->wordpress->deactivatePlugin( $identifier );
			} catch ( Throwable $failure ) {
				$this->logFailure( $failure, 'plugin_deactivation' );
			}
			if ( $this->wordpress->pluginIsActive( $identifier ) ) {
				return PackageRemovalResult::failed( 'deactivation_failed' );
			}
		}

		return $this->deleteFiles(
			'plugin',
			$identifier,
			fn (): bool => $this->wordpress->deletePlugin( $identifier )
		);
	}

	private function deleteTheme( string $stylesheet ): PackageRemovalResult {
		return $this->deleteFiles(
			'theme',
			$stylesheet,
			fn (): bool => $this->wordpress->deleteTheme( $stylesheet )
		);
	}

	/**
	 * @param callable(): bool $delete
	 */
	private function deleteFiles( string $type, string $identifier, callable $delete ): PackageRemovalResult {
		$reportedSuccess = false;
		try {
			$reportedSuccess = $delete();
		} catch ( Throwable $failure ) {
			$this->logFailure( $failure, $type . '_deletion' );
		}

		if ( $this->isInstalled( $type, $identifier ) ) {
			return PackageRemovalResult::failed( $reportedSuccess ? 'files_still_present' : 'deletion_failed' );
		}

		try {
			$this->unlink( $type, $identifier );
		} catch ( Throwable $failure ) {
			$this->logFailure( $failure, 'management_unlink_after_deletion' );

			return PackageRemovalResult::failed( 'management_state_uncertain' );
		}

		return PackageRemovalResult::deleted();
	}

	private function disable( string $type, Package $package ): void {
		$result = 'plugin' === $type
			? $this->plugins->disablePluginForRemoval( $package )
			: $this->themes->disableThemeForRemoval( $package );
		$result->requireSuccess();
	}

	private function deletionBlocker( string $type, string $identifier ): ?string {
		if ( 'plugin' === $type ) {
			if ( ! $this->wordpress->pluginPathIsSafe( $identifier ) ) {
				return 'unsafe_path';
			}
			if ( $this->wordpress->pluginSharesDirectory( $identifier ) ) {
				return 'shared_plugin_directory';
			}
			if ( $this->wordpress->pluginHasActiveDependents( $identifier ) ) {
				return 'active_dependents';
			}

			return null;
		}

		if ( ! $this->wordpress->themePathIsSafe( $identifier ) ) {
			return 'unsafe_path';
		}

		return $this->wordpress->themeDeletionBlocker( $identifier );
	}

	private function find( string $type, string $identifier ): Package {
		return 'plugin' === $type
			? $this->plugins->boosterPluginFromFile( $identifier )
			: $this->themes->boosterThemeFromStylesheet( $identifier );
	}

	private function unlink( string $type, string $identifier ): void {
		$result = 'plugin' === $type
			? $this->plugins->unlink( $identifier )
			: $this->themes->unlink( $identifier );
		$result->requireSuccess();
	}

	private function isInstalled( string $type, string $identifier ): bool {
		return 'plugin' === $type
			? $this->plugins->isInstalled( $identifier )
			: $this->themes->isInstalled( $identifier );
	}

	private function logFailure( Throwable $failure, string $step ): void {
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
