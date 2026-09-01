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

	private RepositorySourceGuard $sourceGuard;

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private DeploymentCoordinator $deployments,
		private PackageRemovalService $removals,
		private WordPressUpdaterLock $updaterLock,
		?RepositorySourceGuard $sourceGuard = null
	) {
		$this->sourceGuard = $sourceGuard ?? new RepositorySourceGuard();
	}

	/** @return array{status: string, package?: Package, correlation_id?: string, outcome_code?: string} */
	public function execute( PackageOperation $operation ): array {
		PackageMutationGuard::assertPackageMutationAllowed();
		if ( $operation->isDeployment() ) {
			return $this->deploy( $operation );
		}

		return match ( $operation->operation ) {
			'install'                         => $this->updaterLock->run(
				fn (): array => $this->linkInstalled( $operation ),
				'Another package operation is in progress.',
				'The package operation lock could not be released.'
			),
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
		$result        = $this->deployments->executeManual( $operation );
		$status        = $result['status'] ?? null;
		$correlationId = $result['correlation_id'] ?? null;
		$outcomeCode   = $result['outcome_code'] ?? null;
		if ( ! in_array( $status, array( 'succeeded', 'failed' ), true )
			|| ! is_string( $correlationId )
			|| preg_match( '/^[a-f0-9]{32}$/D', $correlationId ) !== 1
			|| ! is_string( $outcomeCode )
		) {
			throw new RuntimeException( 'The manual deployment result is invalid.' );
		}
		$outcome = DeploymentOutcome::fromCode( $outcomeCode );
		if ( ( 'succeeded' === $status ) !== ( 'succeeded' === $outcome->getState()->value ) ) {
			throw new RuntimeException( 'The manual deployment result is inconsistent.' );
		}

		$safe = array(
			'status'         => $status,
			'correlation_id' => $correlationId,
			'outcome_code'   => $outcomeCode,
		);
		if ( 'failed' === $status ) {
			return $safe;
		}

		$safe['package'] = $this->deployedPackage( $operation );
		if ( 'install' === $operation->operation && DeploymentOutcome::CODE_ALREADY_MANAGED === $outcomeCode ) {
			$safe['status'] = 'already-managed';
		}

		return $safe;
	}

	private function deployedPackage( PackageOperation $operation ): Package {
		if ( 'update' === $operation->operation ) {
			return $this->find(
				$operation->packageType,
				$operation->identifier ?? throw new RuntimeException( 'The package identity is unavailable.' )
			);
		}

		$slug       = $operation->packageSlug ?? throw new RuntimeException( 'The package slug is unavailable.' );
		$installed  = 'plugin' === $operation->packageType
			? $this->plugins->fromSlug( $slug )
			: $this->themes->fromSlug( $slug );
		$identifier = $installed->getIdentifier();
		if ( ! is_string( $identifier ) || '' === $identifier ) {
			throw new RuntimeException( 'The installed package identity is unavailable.' );
		}

		return $this->find( $operation->packageType, $identifier );
	}

	/** @return array{status: string, package: Package} */
	private function linkInstalled( PackageOperation $operation ): array {
		$slug    = $operation->packageSlug ?? throw new RuntimeException( 'The package slug is unavailable.' );
		$package = 'plugin' === $operation->packageType
			? ( null === $operation->identifier ? $this->plugins->fromSlug( $slug ) : $this->plugins->installedPluginFromFile( $operation->identifier ) )
			: ( null === $operation->identifier ? $this->themes->fromSlug( $slug ) : $this->themes->installedThemeFromStylesheet( $operation->identifier ) );
		if ( $package instanceof Plugin ) {
			PackageMutationGuard::assertPluginFileAllowed( $package->getIdentifier() );
		}
		$this->applyRepository( $package, $operation );
		$this->sourceGuard->assertAllowed( (string) $package->getProviderCode(), (string) $package->getProviderRepositoryId(), 'plugin' === $operation->packageType ? 1 : 2, (string) $package->getIdentifier(), PackageSource::BRANCH );
		$adoption = $this->adopt( $operation->packageType, $package );
		if ( 'ran_booster_storage_adoption_conflict' === $adoption->getDiagnosticId() ) {
			$existing = $this->matchingExistingTarget( $operation, $package );
			if ( $existing instanceof Package ) {
				return array(
					'status'  => 'already-managed',
					'package' => $existing,
				);
			}
		}
		$adoption->requireSuccess();
		$identifier = $package->getIdentifier();
		if ( ! is_string( $identifier ) || '' === $identifier ) {
			throw new RuntimeException( 'The installed package identity is unavailable.' );
		}

		return array(
			'status'  => 'linked',
			'package' => $this->find( $operation->packageType, $identifier ),
		);
	}

	private function matchingExistingTarget( PackageOperation $operation, Package $requested ): ?Package {
		$identifier = $requested->getIdentifier();
		if ( ! is_string( $identifier ) || '' === $identifier ) {
			return null;
		}
		try {
			$existing = $this->find( $operation->packageType, $identifier );
		} catch ( \Throwable ) {
			return null;
		}

		return hash_equals( $identifier, (string) $existing->getIdentifier() )
			&& $existing->getProviderCode() === $operation->providerCode
			&& hash_equals( (string) $existing->getProviderRepositoryId(), (string) $operation->providerRepositoryId )
			&& hash_equals( (string) $existing->getRepository(), (string) $operation->repository )
			&& hash_equals( (string) $existing->getSubdirectory(), (string) $operation->subdirectory )
			&& hash_equals( (string) $existing->getSlug(), (string) $operation->packageSlug )
			? $existing
			: null;
	}

	/** @return array{status: 'edited'|'conflict', package: Package} */
	private function edit( PackageOperation $operation ): array {
		$identifier = $operation->identifier ?? throw new RuntimeException( 'The package identity is unavailable.' );
		$existing   = $this->find( $operation->packageType, $identifier );
		if ( ! $this->matchesExpectedPackage( $operation, $existing ) ) {
			return array(
				'status'  => 'conflict',
				'package' => $existing,
			);
		}
		$releaseManaged = PackageSource::RELEASE_ASSET === $existing->getSource();
		if ( $releaseManaged && null !== $existing->getSubdirectory() ) {
			throw new RuntimeException( 'Published release packages with a repository subdirectory must return to Branch first.' );
		}
		$repository = $releaseManaged
			? new ManagedRepository(
				$existing->getProviderCode(),
				(string) $existing->getRepository(),
				(string) $existing->getProviderRepositoryId(),
				(string) $existing->getBranch(),
				(bool) $existing->getPrivate(),
				'' === (string) $operation->credentialId ? null : $operation->credentialId
			)
			: $this->repository( $operation, $this->providerRepositoryIdForEdit( $operation, $existing ) );
		$this->sourceGuard->assertAllowed( $repository->provider->value, $repository->reference->providerRepositoryId, 'plugin' === $operation->packageType ? 1 : 2, $identifier, $existing->getSource() );
		$result = 'plugin' === $operation->packageType
			? $this->plugins->editPlugin( $identifier, $this->editInput( $operation, $repository, $existing, $releaseManaged ) )
			: $this->themes->editTheme( $identifier, $this->editInput( $operation, $repository, $existing, $releaseManaged ) );
		$result->requireSuccess();

		return array(
			'status'  => 'edited',
			'package' => $this->find( $operation->packageType, $identifier ),
		);
	}

	/** @return array{status: string, outcome_code?: string} */
	private function remove( PackageOperation $operation ): array {
		$result = $this->removals->execute( $operation );
		$safe   = array( 'status' => $result->status );
		if ( '' !== $result->outcomeCode ) {
			$safe['outcome_code'] = $result->outcomeCode;
		}

		return $safe;
	}

	private function applyRepository( Package $package, PackageOperation $operation ): void {
		$package->setRepository( $this->repository( $operation, $operation->providerRepositoryId ) );
		$package->setDeploymentPolicy( $operation->deploymentPolicy );
		$package->setSubdirectory( $operation->subdirectory );
	}

	private function repository( PackageOperation $operation, ?string $providerRepositoryId ): ManagedRepository {
		if ( null === $operation->providerCode || null === $operation->repository || null === $operation->branch || null === $providerRepositoryId ) {
			throw new RuntimeException( 'The package repository is unavailable.' );
		}

		return new ManagedRepository(
			$operation->providerCode,
			$operation->repository,
			$providerRepositoryId,
			$operation->branch,
			$operation->private,
			$operation->credentialId
		);
	}

	private function providerRepositoryIdForEdit( PackageOperation $operation, Package $existing ): ?string {
		if ( in_array( $operation->providerRepositoryIdentitySource, array( 'picker', 'resolved' ), true ) ) {
			return $operation->providerRepositoryId;
		}
		if ( (string) $existing->getRepository() !== $operation->repository || $existing->getProviderCode() !== $operation->providerCode ) {
			return null;
		}

		return $existing->getProviderRepositoryId();
	}

	/** @return array<string, mixed> */
	private function editInput( PackageOperation $operation, ManagedRepository $repository, Package $existing, bool $releaseManaged ): array {
		$expectedSource         = $operation->expectedPackage['source'] ?? null;
		$expectedSourceRevision = $operation->getExpectedSourceRevision();
		if ( ! $expectedSource instanceof PackageSource || null === $expectedSourceRevision ) {
			throw new RuntimeException( 'The expected package source is unavailable.' );
		}

		return array(
			'repository'               => $repository,
			'branch'                   => $repository->branch,
			'deployment_policy'        => $operation->deploymentPolicy->value,
			'subdirectory'             => $releaseManaged ? $existing->getSubdirectory() : $operation->subdirectory,
			'private'                  => $repository->reference->private,
			'credential_id'            => $repository->reference->credentialId ?? '',
			'provider'                 => $repository->provider->value,
			'provider_repository_id'   => $repository->reference->providerRepositoryId,
			'expected_source'          => $expectedSource->value,
			'expected_source_revision' => $expectedSourceRevision,
		);
	}

	private function matchesExpectedPackage( PackageOperation $operation, Package $package ): bool {
		$expected = $operation->expectedPackage;

		return $operation->hasExpectedPackage()
			&& $package->getProviderCode() === $expected['provider']
			&& hash_equals( (string) $package->getProviderRepositoryId(), (string) $expected['provider_repository_id'] )
			&& hash_equals( (string) $package->getRepository(), (string) $expected['repository'] )
			&& hash_equals( (string) $package->getBranch(), (string) $expected['branch'] )
			&& hash_equals( $package->getCredentialId(), (string) $expected['credential_id'] )
			&& hash_equals( (string) $package->getSubdirectory(), (string) $expected['subdirectory'] )
			&& (bool) $package->getPrivate() === $expected['private']
			&& hash_equals( (string) $package->getSlug(), (string) $expected['package_slug'] )
			&& $package->getDeploymentPolicy() === $expected['deployment_policy']
			&& $package->getSource() === $expected['source']
			&& $package->getSourceRevision() === $expected['source_revision'];
	}

	private function find( string $type, string $identifier ): Package {
		return 'plugin' === $type
			? $this->plugins->boosterPluginFromFile( $identifier )
			: $this->themes->boosterThemeFromStylesheet( $identifier );
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
