<?php

declare(strict_types=1);

namespace RAN;

use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\PackageMutationGuard;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\Storage\PackageMutationResult;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use RuntimeException;

/**
 * Executes the four explicit administrator package operations.
 */
final readonly class PackageOperationService {

	private RepositorySourceGuard $source_guard;

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private DeploymentCoordinator $deployments,
		private PackageRemovalService $removals,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		private WordPressUpdaterLock $updaterLock,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		?RepositorySourceGuard $sourceGuard = null
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public named parameters and their uses retain the existing caller contract.
		$this->source_guard = $sourceGuard ?? new RepositorySourceGuard();
	}

	/** @return array{status: string, package?: Package, correlation_id?: string, outcome_code?: string} */
	public function execute( PackageOperation $operation ): array {
		PackageMutationGuard::assert_package_mutation_allowed();
		if ( $operation->is_deployment() ) {
			return $this->deploy( $operation );
		}

		return match ( $operation->operation ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor properties retain the existing public named-parameter contract.
			'install'                         => $this->updaterLock->run(
				fn (): array => $this->link_installed( $operation ),
				'Another package operation is in progress.',
				'The package operation lock could not be released.'
			),
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor properties retain the existing public named-parameter contract.
			'edit'                            => $this->updaterLock->run(
				fn (): array => $this->edit( $operation ),
				'Another package operation is in progress.',
				'The package operation lock could not be released.'
			),
			'unlink', 'unlink-and-delete'     => $this->remove( $operation ),
			default                           => throw new RuntimeException( 'The package operation is unsupported.' ),
		};
	}

	/** @return array{status: 'succeeded'|'already-managed'|'failed', package?: Package, correlation_id: string, outcome_code: string} */
	private function deploy( PackageOperation $operation ): array {
		$result         = $this->deployments->executeManual( $operation );
		$status         = $result['status'] ?? null;
		$correlation_id = $result['correlation_id'] ?? null;
		$outcome_code   = $result['outcome_code'] ?? null;
		if ( ! in_array( $status, array( 'succeeded', 'failed' ), true )
			|| ! is_string( $correlation_id )
			|| preg_match( '/^[a-f0-9]{32}$/D', $correlation_id ) !== 1
			|| ! is_string( $outcome_code )
		) {
			throw new RuntimeException( 'The manual deployment result is invalid.' );
		}
		$outcome = DeploymentOutcome::from_code( $outcome_code );
		if ( ( 'succeeded' === $status ) !== ( 'succeeded' === $outcome->get_state()->value ) ) {
			throw new RuntimeException( 'The manual deployment result is inconsistent.' );
		}

		$safe = array(
			'status'         => $status,
			'correlation_id' => $correlation_id,
			'outcome_code'   => $outcome_code,
		);
		if ( 'failed' === $status ) {
			return $safe;
		}

		$safe['package'] = $this->deployed_package( $operation );
		if ( 'install' === $operation->operation && DeploymentOutcome::CODE_ALREADY_MANAGED === $outcome_code ) {
			$safe['status'] = 'already-managed';
		}

		return $safe;
	}

	private function deployed_package( PackageOperation $operation ): Package {
		if ( 'update' === $operation->operation ) {
			return $this->find(
				$operation->package_type,
				$operation->identifier ?? throw new RuntimeException( 'The package identity is unavailable.' )
			);
		}

		$slug       = $operation->package_slug ?? throw new RuntimeException( 'The package slug is unavailable.' );
		$installed  = 'plugin' === $operation->package_type
			? $this->plugins->from_slug( $slug )
			: $this->themes->from_slug( $slug );
		$identifier = $installed->get_identifier();
		if ( ! is_string( $identifier ) || '' === $identifier ) {
			throw new RuntimeException( 'The installed package identity is unavailable.' );
		}

		return $this->find( $operation->package_type, $identifier );
	}

	/** @return array{status: string, package: Package} */
	private function link_installed( PackageOperation $operation ): array {
		$slug    = $operation->package_slug ?? throw new RuntimeException( 'The package slug is unavailable.' );
		$package = 'plugin' === $operation->package_type
			? ( null === $operation->identifier ? $this->plugins->from_slug( $slug ) : $this->plugins->installed_plugin_from_file( $operation->identifier ) )
			: ( null === $operation->identifier ? $this->themes->from_slug( $slug ) : $this->themes->installed_theme_from_stylesheet( $operation->identifier ) );
		if ( $package instanceof Plugin ) {
			PackageMutationGuard::assert_plugin_file_allowed( $package->get_identifier() );
		}
		$this->apply_repository( $package, $operation );
		$this->source_guard->assertAllowed( (string) $package->get_provider_code(), (string) $package->get_provider_repository_id(), 'plugin' === $operation->package_type ? 1 : 2, (string) $package->get_identifier(), PackageSource::BRANCH );
		$adoption = $this->adopt( $operation->package_type, $package );
		if ( 'ran_booster_storage_adoption_conflict' === $adoption->get_diagnostic_id() ) {
			$existing = $this->matching_existing_target( $operation, $package );
			if ( $existing instanceof Package ) {
				return array(
					'status'  => 'already-managed',
					'package' => $existing,
				);
			}
		}
		$adoption->require_success();
		$identifier = $package->get_identifier();
		if ( ! is_string( $identifier ) || '' === $identifier ) {
			throw new RuntimeException( 'The installed package identity is unavailable.' );
		}

		return array(
			'status'  => 'linked',
			'package' => $this->find( $operation->package_type, $identifier ),
		);
	}

	private function matching_existing_target( PackageOperation $operation, Package $requested ): ?Package {
		$identifier = $requested->get_identifier();
		if ( ! is_string( $identifier ) || '' === $identifier ) {
			return null;
		}
		try {
			$existing = $this->find( $operation->package_type, $identifier );
		} catch ( \Throwable ) {
			return null;
		}

		return hash_equals( $identifier, (string) $existing->get_identifier() )
			&& $existing->get_provider_code() === $operation->provider_code
			&& hash_equals( (string) $existing->get_provider_repository_id(), (string) $operation->provider_repository_id )
			&& hash_equals( (string) $existing->get_repository(), (string) $operation->repository )
			&& hash_equals( (string) $existing->get_subdirectory(), (string) $operation->subdirectory )
			&& hash_equals( (string) $existing->get_slug(), (string) $operation->package_slug )
			? $existing
			: null;
	}

	/** @return array{status: 'edited'|'conflict', package: Package} */
	private function edit( PackageOperation $operation ): array {
		$identifier = $operation->identifier ?? throw new RuntimeException( 'The package identity is unavailable.' );
		$existing   = $this->find( $operation->package_type, $identifier );
		if ( ! $this->matches_expected_package( $operation, $existing ) ) {
			return array(
				'status'  => 'conflict',
				'package' => $existing,
			);
		}
		$release_managed = PackageSource::RELEASE_ASSET === $existing->get_source();
		if ( $release_managed && null !== $existing->get_subdirectory() ) {
			throw new RuntimeException( 'Published release packages with a repository subdirectory must return to Branch first.' );
		}
		$repository = $release_managed
			? new ManagedRepository(
				$existing->get_provider_code(),
				(string) $existing->get_repository(),
				(string) $existing->get_provider_repository_id(),
				(string) $existing->get_branch(),
				(bool) $existing->get_private(),
				'' === (string) $operation->credential_id ? null : $operation->credential_id
			)
			: $this->repository( $operation, $this->provider_repository_id_for_edit( $operation, $existing ) );
		$this->source_guard->assertAllowed( $repository->provider->value, $repository->reference->providerRepositoryId, 'plugin' === $operation->package_type ? 1 : 2, $identifier, $existing->get_source() );
		$result = 'plugin' === $operation->package_type
			? $this->plugins->edit_plugin( $identifier, $this->edit_input( $operation, $repository, $existing, $release_managed ) )
			: $this->themes->edit_theme( $identifier, $this->edit_input( $operation, $repository, $existing, $release_managed ) );
		$result->require_success();

		return array(
			'status'  => 'edited',
			'package' => $this->find( $operation->package_type, $identifier ),
		);
	}

	/** @return array{status: string, outcome_code?: string} */
	private function remove( PackageOperation $operation ): array {
		$result = $this->removals->execute( $operation );
		$safe   = array( 'status' => $result->status );
		if ( '' !== $result->outcome_code ) {
			$safe['outcome_code'] = $result->outcome_code;
		}

		return $safe;
	}

	private function apply_repository( Package $package, PackageOperation $operation ): void {
		$package->set_repository( $this->repository( $operation, $operation->provider_repository_id ) );
		$package->set_deployment_policy( $operation->deployment_policy );
		$package->set_subdirectory( $operation->subdirectory );
	}

	private function repository( PackageOperation $operation, ?string $provider_repository_id ): ManagedRepository {
		if ( null === $operation->provider_code || null === $operation->repository || null === $operation->branch || null === $provider_repository_id ) {
			throw new RuntimeException( 'The package repository is unavailable.' );
		}

		return new ManagedRepository(
			$operation->provider_code,
			$operation->repository,
			$provider_repository_id,
			$operation->branch,
			$operation->is_private,
			$operation->credential_id
		);
	}

	private function provider_repository_id_for_edit( PackageOperation $operation, Package $existing ): ?string {
		if ( in_array( $operation->provider_repository_identity_source, array( 'picker', 'resolved' ), true ) ) {
			return $operation->provider_repository_id;
		}
		if ( (string) $existing->get_repository() !== $operation->repository || $existing->get_provider_code() !== $operation->provider_code ) {
			return null;
		}

		return $existing->get_provider_repository_id();
	}

	/** @return array<string, mixed> */
	private function edit_input( PackageOperation $operation, ManagedRepository $repository, Package $existing, bool $release_managed ): array {
		$expected_source          = $operation->expected_package['source'] ?? null;
		$expected_source_revision = $operation->get_expected_source_revision();
		if ( ! $expected_source instanceof PackageSource || null === $expected_source_revision ) {
			throw new RuntimeException( 'The expected package source is unavailable.' );
		}

		return array(
			'repository'               => $repository,
			'branch'                   => $repository->branch,
			'deployment_policy'        => $operation->deployment_policy->value,
			'subdirectory'             => $release_managed ? $existing->get_subdirectory() : $operation->subdirectory,
			'private'                  => $repository->reference->private,
			'credential_id'            => $repository->reference->credentialId ?? '',
			'provider'                 => $repository->provider->value,
			'provider_repository_id'   => $repository->reference->providerRepositoryId,
			'expected_source'          => $expected_source->value,
			'expected_source_revision' => $expected_source_revision,
		);
	}

	private function matches_expected_package( PackageOperation $operation, Package $package ): bool {
		$expected = $operation->expected_package;

		return $operation->has_expected_package()
			&& $package->get_provider_code() === $expected['provider']
			&& hash_equals( (string) $package->get_provider_repository_id(), (string) $expected['provider_repository_id'] )
			&& hash_equals( (string) $package->get_repository(), (string) $expected['repository'] )
			&& hash_equals( (string) $package->get_branch(), (string) $expected['branch'] )
			&& hash_equals( $package->get_credential_id(), (string) $expected['credential_id'] )
			&& hash_equals( (string) $package->get_subdirectory(), (string) $expected['subdirectory'] )
			&& (bool) $package->get_private() === $expected['private']
			&& hash_equals( (string) $package->get_slug(), (string) $expected['package_slug'] )
			&& $package->get_deployment_policy() === $expected['deployment_policy']
			&& $package->get_source() === $expected['source']
			&& $package->get_source_revision() === $expected['source_revision'];
	}

	private function find( string $type, string $identifier ): Package {
		return 'plugin' === $type
			? $this->plugins->booster_plugin_from_file( $identifier )
			: $this->themes->booster_theme_from_stylesheet( $identifier );
	}

	private function adopt( string $type, Package $package ): PackageMutationResult {
		if ( 'plugin' === $type ) {
			if ( ! $package instanceof Plugin ) {
				throw new RuntimeException( 'The installed plugin is unavailable.' );
			}

			return $this->plugins->adopt( $package );
		}
		if ( ! $package instanceof Theme ) {
			throw new RuntimeException( 'The installed theme is unavailable.' );
		}

		return $this->themes->adopt( $package );
	}
}
