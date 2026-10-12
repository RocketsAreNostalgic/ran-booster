<?php

declare(strict_types=1);

namespace RAN\PackageRemoval;

use RAN\Admin\RepositoryBranchCheckEvidenceStore;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\PackageMutationGuard;
use RAN\Logging\BoosterLogger;
use RAN\Package;
use RAN\AdoptionIdentity;
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
		private WordPressUpdaterLock $updater_lock,
		private ?RepositoryBranchCheckEvidenceStore $branch_check_evidence = null
	) {
	}

	/** @return array<string, string> */
	public function recovery_targets( string $type ): array {
		RuntimeSupport::assert_managed_operations_allowed();
		return $this->wordpress->installed_targets( $type );
	}

	/** @return array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string,installed_digest:string} */
	public function recovery_confirmation( string $type, string $identifier ): array {
		RuntimeSupport::assert_managed_operations_allowed();
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			throw new \InvalidArgumentException( 'The package type is invalid.' );
		}
		if ( 'plugin' === $type ) {
			PackageMutationGuard::assert_plugin_file_allowed( $identifier );
		}
		$record                     = ( 'plugin' === $type ? $this->plugins : $this->themes )->recovery_record( $identifier );
		$record['installed_digest'] = $this->wordpress->installed_fingerprint( $type, $identifier );
		return $record;
	}

	public function recover( string $type, string $identifier, string $token, string $digest, string $installed_digest ): void {
		RuntimeSupport::assert_managed_operations_allowed();
		PackageMutationGuard::assert_admin_action_allowed( 'unlink-' . $type, array( 'file' => $identifier ) );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || null === $this->attempts ) {
			throw new \RuntimeException( 'The recovery operation state is unavailable.' );
		}
		$this->updater_lock->run(
			function () use ( $type, $identifier, $token, $digest, $installed_digest ): void {
				$this->recover_locked( $type, $identifier, $token, $digest, $installed_digest );
			}
		);
	}

	private function recover_locked( string $type, string $identifier, string $token, string $digest, string $installed_digest, ?int $source_revision = null ): void {
		( 'plugin' === $type ? $this->plugins : $this->themes )->recover_unlink(
			$identifier,
			$token,
			$digest,
			function () use ( $type, $identifier, $token, $installed_digest, $source_revision ): void {
				if ( ! hash_equals( $installed_digest, $this->wordpress->installed_fingerprint( $type, $identifier ) ) ) {
					throw new \RuntimeException( 'The installed target changed after confirmation.' );
				}
				if ( null !== $source_revision && $this->find( $type, $identifier )->get_source_revision() !== $source_revision ) {
					throw new \RuntimeException( 'The managed source changed after confirmation.' );
				}
				if ( null === $this->attempts || $this->attempts->has_unresolved_target_attempt( $type, $identifier ) ) {
					throw new \RuntimeException( 'Unresolved deployment work prevents recovery.' );
				}
				$this->clear_observations( $type, $identifier, $token );
			}
		)->require_success();
	}

	private function clear_observations( string $type, string $identifier, string $token ): void {
		foreach ( array(
			'branch_evidence' => fn () => $this->branch_check_evidence?->clear_identity( $type, $identifier, $token ),
			'native_cache'    => fn () => $this->wordpress->invalidate_native_target( $type, $identifier ),
		) as $step => $cleanup ) {
			try {
				$cleanup();
			} catch ( Throwable $failure ) {
				// Lifetime checks independently reject departed work; observation failures must not block recovery.
				$this->log_failure( $failure, 'recovery_cleanup_' . $step );
			}
		}
	}

	public function execute( PackageOperation $operation ): PackageRemovalResult {
		RuntimeSupport::assert_managed_operations_allowed();

		if ( ! in_array( $operation->operation, array( 'unlink', 'unlink-and-delete' ), true ) ) {
			throw new \LogicException( 'The package removal operation is invalid.' );
		}

		$identifier = $operation->identifier ?? throw new \RuntimeException( 'The package identity is unavailable.' );
		if ( 'unlink-and-delete' === $operation->operation ) {
			PackageMutationGuard::assert_filesystem_mutation_allowed();
		}
		try {

			$lock_token = $this->updater_lock->acquire();
		} catch ( Throwable $failure ) {
			$this->log_failure( $failure, 'package_removal_lock_acquire' );
			return PackageRemovalResult::failed( 'operation_in_progress' );
		}

		$result = PackageRemovalResult::failed( 'management_state_uncertain' );
		try {
			$package = $this->find( $operation->package_type, $identifier );
			if ( null === $operation->get_expected_adoption_token()
				|| AdoptionIdentity::of( $package ) !== $operation->get_expected_adoption_token()
				|| $package->get_source_revision() !== $operation->get_expected_source_revision() ) {
				$result = PackageRemovalResult::failed( 'stale' );
			} elseif ( 'unlink' === $operation->operation ) {
				$confirmation = $this->recovery_confirmation( $operation->package_type, $identifier );
				$this->recover_locked( $operation->package_type, $identifier, $operation->get_expected_adoption_token(), $confirmation['digest'], $confirmation['installed_digest'], $operation->get_expected_source_revision() );
				$result = PackageRemovalResult::unlinked();
			} elseif ( null === $this->attempts
				|| $this->attempts->has_unresolved_target_attempt( $operation->package_type, $identifier ) ) {
				$result = PackageRemovalResult::failed( 'operation_in_progress' );
			} else {
				$blocker = $this->deletion_blocker( $operation->package_type, $identifier );
				if ( null !== $blocker ) {
					$result = PackageRemovalResult::failed( $blocker );
				} else {
					$this->disable( $package );
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

				if ( ! $this->updater_lock->release( $lock_token ) ) {
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

	private function disable( \RAN\Plugin|\RAN\Theme $package ): void {
		$result = $package instanceof \RAN\Plugin
			? $this->plugins->disable_plugin_for_removal( $package )
			: $this->themes->disable_theme_for_removal( $package );
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

	/** @return \RAN\Plugin|\RAN\Theme */
	private function find( string $type, string $identifier ): Package {
		return 'plugin' === $type
			? $this->plugins->booster_plugin_from_file( $identifier )
			: $this->themes->booster_theme_from_stylesheet( $identifier );
	}

	private function unlink( string $type, string $identifier, Package $package ): void {
		$token      = AdoptionIdentity::of( $package ) ?? throw new \RuntimeException( 'The adoption identity is unavailable.' );
		$repository = 'plugin' === $type ? $this->plugins : $this->themes;
		$record     = $repository->recovery_record( $identifier );
		$repository->recover_unlink(
			$identifier,
			$token,
			$record['digest'],
			function () use ( $type, $identifier, $token ): void {
				if ( $this->is_installed( $type, $identifier ) || null === $this->attempts
				|| $this->attempts->has_unresolved_target_attempt( $type, $identifier ) ) {
					throw new \RuntimeException( 'The deleted target or operation state could not be confirmed.' );
				}
				$this->clear_observations( $type, $identifier, $token );
			}
		)->require_success();
	}

	private function is_installed( string $type, string $identifier ): bool {
		return 'plugin' === $type
			? $this->plugins->is_installed( $identifier )
			: $this->themes->is_installed( $identifier );
	}

	private function log_failure( Throwable $failure, string $step ): void {
		BoosterLogger::log_exception(
			'package removal failed',
			$failure,
			array(
				'event' => 'package_removal_failed',
				'step'  => $step,
			)
		);
	}
}
