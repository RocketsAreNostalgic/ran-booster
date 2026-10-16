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
use RAN\RepositoryProvider\GitHubProviderSelection;
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
		private ?RepositoryBranchCheckEvidenceStore $branch_check_evidence = null,
		private ?GitHubProviderSelection $github_selection = null
	) {
	}

	/** @return array{digest:string,type:string,identifier:string,managed:bool} No credential or native owner disclosure. */
	public function native_recovery_confirmation(): array {
		$snapshot = $this->native_recovery_snapshot();
		return array(
			'digest'     => $snapshot['digest'],
			'type'       => $snapshot['type'],
			'identifier' => $snapshot['identifier'],
			'managed'    => null !== $snapshot['record'],
		);
	}

	/**
	 * Confirm an absent target only; this never deletes files, clears an arbitrary lock,
	 * certifies a retained installation, or claims interrupted temporary-file cleanup.
	 *
	 * @param callable():bool $authorized Host administrative permissions, outside owned SQL transactions.
	 */
	public function recover_native_removal( string $digest, bool $confirmed_stopped, bool $confirmed_removal, callable $authorized ): void {
		RuntimeSupport::assert_managed_operations_allowed();
		global $wpdb;
		if ( ! $confirmed_stopped || ! $confirmed_removal || true !== $this->recovery_authorized( $authorized ) || null === $this->github_selection || null === $this->attempts || ! $this->github_selection->uses_connection( $wpdb ) ) {
			throw new \RuntimeException( 'Explicit native recovery authority is required.' );
		}
		$token = $this->updater_lock->try_acquire_without_reclaim();
		if ( null === $token ) {
			throw new \RuntimeException( 'The updater lock requires recovery before continuing.' );
		}
		try {
			$snapshot = $this->native_recovery_snapshot();
			if ( ! hash_equals( $snapshot['digest'], $digest ) ) {
				throw new \RuntimeException( 'The native recovery review changed.' );
			}
			$type       = $snapshot['type'];
			$identifier = $snapshot['identifier'];
			if ( 'plugin' === $type ) {
				PackageMutationGuard::assert_plugin_file_allowed( $identifier );
			}
			$absent     = $this->wordpress->deletion_postcondition( $type, $identifier, true );
			$verify     = function () use ( $type, $identifier, $absent, $token ): void {
				if ( ! $absent() || null === $this->attempts || $this->attempts->has_unresolved_target_attempt( $type, $identifier, true ) || $this->updater_lock->current_token() !== $token ) {
					throw new \RuntimeException( 'Native target absence or operation ownership could not be verified.' );
				}
			};
			$repository = 'plugin' === $type ? $this->plugins : $this->themes;
			$record     = $snapshot['record'];
			$this->attempts->assert_selection_transition_idle( $this->github_selection );
			if ( ! $absent() || true !== $this->recovery_authorized( $authorized ) || $this->github_selection->native_recovery_state() !== $snapshot['state'] ) {
				throw new \RuntimeException( 'The native recovery authority changed.' );
			}
			if ( null !== $record ) {
				// This operation owns its transaction. Keep the native marker until final verification.
				$repository->recover_unlink( $identifier, $record['adoption_token'], $record['digest'], $verify )->require_success();
			}
			// Unlike ordinary removal's best-effort observations, this failure must retain the marker.
			$this->wordpress->invalidate_native_target( $type, $identifier );
			if ( true !== $this->recovery_authorized( $authorized ) ) {
				throw new \RuntimeException( 'Native recovery permission changed.' );
			}
			$this->github_selection->recover_native_removal(
				$snapshot['state'],
				function () use ( $repository, $identifier, $verify ): void {
					if ( null !== $repository->native_recovery_record( $identifier, true ) ) {
						throw new \RuntimeException( 'The removed target is still managed.' );
					}
					$this->attempts?->assert_selection_transition_idle( $this->github_selection );
					$verify();
				}
			);
		} finally {
			if ( ! $this->updater_lock->release( $token ) ) {
				throw new \RuntimeException( 'Native recovery may have completed; updater lock release requires recovery.' );
			}
		}
	}

	/**
	 * @param callable():bool $authorized
	 * @phpstan-impure Administrative authority can change between callbacks.
	 */
	private function recovery_authorized( callable $authorized ): bool {
		return $authorized();
	}

	/** @return array{digest:string,type:string,identifier:string,state:array<string,mixed>,record:?array{id:string,package:string,type:int,adoption_token:string,source:string,digest:string}} */
	private function native_recovery_snapshot(): array {
		RuntimeSupport::assert_managed_operations_allowed();
		$state  = ( $this->github_selection ?? throw new \RuntimeException( 'Native recovery is unavailable.' ) )->native_recovery_state();
		$marker = $state['native_mutation'];
		if ( ! is_array( $marker ) || ! is_string( $marker['target_type'] ?? null ) || ! is_string( $marker['installed_identity'] ?? null ) ) {
			throw new \RuntimeException( 'Native recovery identity is unavailable.' );
		}
		$type       = $marker['target_type'];
		$identifier = $marker['installed_identity'];
		$record     = ( 'plugin' === $type ? $this->plugins : $this->themes )->native_recovery_record( $identifier );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Hash only validated local authority, never credentials or filesystem paths.
		$digest = hash( 'sha256', json_encode( array( 'native-removal-v1', $state, $record ), JSON_THROW_ON_ERROR ) );
		return array(
			'digest'     => $digest,
			'type'       => $type,
			'identifier' => $identifier,
			'state'      => $state,
			'record'     => $record,
		);
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
					$absent = $this->wordpress->deletion_postcondition( $operation->package_type, $identifier );
					$this->disable( $package );
					$result = 'plugin' === $operation->package_type
						? $this->delete_plugin( $identifier, $package, $absent )
						: $this->delete_theme( $identifier, $package, $absent );
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

	/** @param \Closure(): bool $absent */
	private function delete_plugin( string $identifier, Package $package, \Closure $absent ): PackageRemovalResult {
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
			fn (): bool => $this->wordpress->delete_plugin( $identifier ),
			$absent
		);
	}

	/** @param \Closure(): bool $absent */
	private function delete_theme( string $stylesheet, Package $package, \Closure $absent ): PackageRemovalResult {
		return $this->delete_files(
			'theme',
			$stylesheet,
			$package,
			fn (): bool => $this->wordpress->delete_theme( $stylesheet ),
			$absent
		);
	}

	/**
	 * @param callable(): bool $delete
	 * @param \Closure(): bool $absent
	 */
	private function delete_files( string $type, string $identifier, Package $package, callable $delete, \Closure $absent ): PackageRemovalResult {
		$reported_success = false;
		try {
			$reported_success = $delete();
		} catch ( Throwable $failure ) {
			$this->log_failure( $failure, $type . '_deletion' );
		}

		if ( ! $absent() ) {
			return PackageRemovalResult::failed( $reported_success ? 'files_still_present' : 'deletion_failed' );
		}

		try {
			$this->unlink( $type, $identifier, $package, $absent );
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

	/** @param \Closure(): bool $absent */
	private function unlink( string $type, string $identifier, Package $package, \Closure $absent ): void {
		$token      = AdoptionIdentity::of( $package ) ?? throw new \RuntimeException( 'The adoption identity is unavailable.' );
		$repository = 'plugin' === $type ? $this->plugins : $this->themes;
		$record     = $repository->recovery_record( $identifier );
		$repository->recover_unlink(
			$identifier,
			$token,
			$record['digest'],
			function () use ( $type, $identifier, $token, $absent ): void {
				if ( ! $absent() || null === $this->attempts
				|| $this->attempts->has_unresolved_target_attempt( $type, $identifier ) ) {
					throw new \RuntimeException( 'The deleted target or operation state could not be confirmed.' );
				}
				$this->clear_observations( $type, $identifier, $token );
			}
		)->require_success();
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
