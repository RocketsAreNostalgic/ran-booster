<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RAN\ManagedRepository;
use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive as ProviderPreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\StaleDeployment;
use RAN\Storage\PackageStorageFailure;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\WordPressUpdaterLock;
use RAN\WPBranchUpdater\V1\Archive\ArchiveOffer;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchive;
use RAN\WPBranchUpdater\V1\Archive\PreparedArchiveArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchDurabilityFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockReleaseFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentLockStorageFailure;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RAN\WPBranchUpdater\V1\WordPress\WordPressCorePackageExecutor;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Translate one already-admitted Booster attempt into the branch-updater host contracts.
 *
 * This object deliberately owns no deployment sequencing. The external admitted runner
 * is the sole authority for ordering, mutation fencing, postconditions and terminal codes.
 *
 * @internal Branch deployment composition only.
 */
final class AdmittedBranchHostAdapter implements AdmittedAttemptJournal, AdmittedArchiveSource, AdmittedTargetFacts, AdmittedPackageExecutor, MutationLock {

	private const DOWNLOAD_TIMEOUT  = 120;
	private const DOWNLOAD_ATTEMPTS = 2;
	private const EXPANDED_RATIO    = 4;

	private ?ProviderPreparedArchive $providerArchive = null;

	public function __construct(
		private DeploymentAttempt $attempt,
		private readonly DeploymentAttemptRepository $attempts,
		private readonly PluginRepository $plugins,
		private readonly ThemeRepository $themes,
		private readonly ProviderRegistry $providers,
		private readonly RepositorySourceGuard $sourceGuard,
		private readonly WordPressUpdaterLock $updaterLock,
		private readonly WordPressCorePackageExecutor $executor,
		private readonly string $maintenancePath
	) {
		if ( '' === trim( $maintenancePath ) ) {
			throw new RuntimeException( 'The WordPress maintenance path is invalid.' );
		}
	}

	/** Construct the immutable package declaration from Booster's admitted snapshot. */
	public function declaration(): BranchDeploymentDeclaration {
		$data       = $this->attempt->safeData();
		$request    = $this->attempt->getRequest();
		$identifier = null;

		if ( PackageSource::BRANCH->value !== ( $data['package_source'] ?? null ) ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_RELEASE_SOURCE_BLOCKED );
		}

		if ( 'update' === $data['operation'] ) {
			try {
				$identifier = (string) $this->packageBySlug( (string) $data['package_type'], $request->packageSlug )->getIdentifier();
			} catch ( PackageStorageFailure $failure ) {
				$this->stagePackageStorageFailure( $failure );
			} catch ( Throwable ) {
				$this->stage( DeploymentOutcome::CODE_POLICY_BLOCKED );
			}
			if ( '' === $identifier ) {
				$this->stage( DeploymentOutcome::CODE_POLICY_BLOCKED );
			}
			if ( 'plugin' === $data['package_type'] && ! str_contains( $identifier, '/' ) ) {
				$this->stage( DeploymentOutcome::CODE_PACKAGE_SINGLE_FILE_UNSUPPORTED );
			}
		}

		return new BranchDeploymentDeclaration(
			(string) $this->attempt->getId(),
			(string) $data['package_type'],
			$request->packageSlug,
			$request->repository,
			(string) $data['provider_repository_id'],
			$request->configuredBranch,
			'webhook' === $data['source'] ? (string) $data['requested_ref'] : null,
			(string) $data['operation'],
			$request->subdirectory,
			$identifier
		);
	}

	public function recordResolvedRef( string $ref ): void {
		try {
			$this->attempt = $this->attempts->recordResolvedRef( $this->attempt->getId(), $ref );
		} catch ( DeploymentStorageFailure $failure ) {
			throw new AdmittedBranchDurabilityFailure( previous: $failure );
		}
	}

	public function markMutationStarted(): void {
		try {
			$this->attempt = $this->attempts->markMutationStarted( $this->attempt->getId() );
		} catch ( DeploymentStorageFailure $failure ) {
			throw new AdmittedBranchDurabilityFailure( previous: $failure );
		}
	}

	public function finish( string $code ): void {
		try {
			$this->attempt = $this->attempts->finish( $this->attempt->getId(), DeploymentOutcome::fromCode( $code ) );
		} catch ( DeploymentStorageFailure $failure ) {
			throw new AdmittedBranchDurabilityFailure( previous: $failure );
		}
	}

	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		$this->assertDeclaration( $deployment );
		if ( null !== $this->providerArchive ) {
			throw new RuntimeException( 'The admitted archive source is already consumed.' );
		}

		$data                 = $this->attempt->safeData();
		$request              = $this->attempt->getRequest();
		$maximumArtifactBytes = $request->maximumArtifactBytes;
		$provider             = ProviderCode::parse( (string) $data['provider'] );
		$reference            = new RepositoryReference(
			$request->repository,
			(string) $data['provider_repository_id'],
			$request->private,
			$request->credentialId
		);

		try {
			$this->providerArchive = $this->providers->get( $provider )->prepareArchive(
				new ArchiveRequest(
					$reference,
					(string) $data['requested_ref'],
					'webhook' === $data['source'] ? $request->configuredBranch : null
				)
			);
		} catch ( Throwable $failure ) {
			$this->stage( DeploymentOutcome::fromProviderFailure( $failure )->getCode() );
		}

		$providerArchive = $this->providerArchive;
		$resolvedRef     = $providerArchive->getResolvedRef();
		if ( '' === $resolvedRef || $resolvedRef !== trim( $resolvedRef ) || strlen( $resolvedRef ) > 191 || preg_match( '/[[:cntrl:]]/', $resolvedRef ) === 1 ) {
			$this->cleanupProviderArchive( $providerArchive );
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_REVISION_INVALID );
		}
		if ( null !== $deployment->expectedHead && ! hash_equals( $deployment->expectedHead, $resolvedRef ) ) {
			$this->cleanupProviderArchive( $providerArchive );
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_REVISION_INVALID );
		}

		try {
			$this->assertLocalReadiness( $deployment, $maximumArtifactBytes );
		} catch ( Throwable $failure ) {
			$this->cleanupProviderArchive( $providerArchive );
			throw $failure;
		}

		$offer = new ArchiveOffer(
			$provider->value,
			(string) $data['provider_repository_id'],
			$resolvedRef,
			function ( string $destination, int $providerMaximumArtifactBytes ) use ( $providerArchive, $maximumArtifactBytes ): void {
				if ( $providerMaximumArtifactBytes !== $maximumArtifactBytes ) {
					$this->stage( DeploymentOutcome::CODE_ARCHIVE_LIMIT_INVALID );
				}
				$this->downloadProviderArchive( $providerArchive, $destination, $maximumArtifactBytes );
			},
			function () use ( $providerArchive ): void {
				$this->verifyProviderHead( $providerArchive );
			}
		);

		try {
			$artifact = new PreparedArchiveArtifact(
				PreparedArchive::downloadAndValidate(
					$offer,
					$deployment,
					$this->archiveDirectory(),
					$maximumArtifactBytes
				)
			);
			$this->assertArtifactCapacity( $artifact, $deployment, $maximumArtifactBytes );
			return $artifact;
		} catch ( AdmittedBranchStageFailure $failure ) {
			throw $failure;
		} catch ( Throwable ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED );
		}
	}

	public function verifyCurrentHead(): void {
		if ( null === $this->providerArchive ) {
			$this->stage( DeploymentOutcome::CODE_PROVIDER_FAILED );
		}
		$this->verifyProviderHead( $this->providerArchive );
	}

	public function assertMutationAllowed(): void {
		PackageMutationGuard::assertFilesystemMutationAllowed();
	}

	public function frozenTarget( BranchDeploymentDeclaration $deployment, bool $deferExisting ): ?array {
		$this->assertDeclaration( $deployment );
		$data    = $this->attempt->safeData();
		$request = $this->attempt->getRequest();

		try {
			if ( PackageSource::BRANCH->value !== ( $data['package_source'] ?? null ) ) {
				$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_RELEASE_SOURCE_BLOCKED );
			}
			if ( 'install' === $data['operation'] ) {
				if ( 'plugin' === $data['package_type'] && 'ran-booster' === $request->packageSlug ) {
					$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_SELF_UPDATE_BLOCKED );
				}
				if ( $this->destinationExists( (string) $data['package_type'], $request->packageSlug ) ) {
					if ( $this->existingManagementMatchesInstallTarget() ) {
						if ( $deferExisting ) {
							return null;
						}
						$this->stage( DeploymentOutcome::CODE_ALREADY_MANAGED );
					}
					$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DESTINATION_EXISTS );
				}
				$this->sourceGuard->assertAllowed(
					(string) $data['provider'],
					(string) $data['provider_repository_id'],
					'plugin' === $data['package_type'] ? 1 : 2,
					$request->packageSlug,
					PackageSource::BRANCH
				);
				return null;
			}

			$package = $this->packageBySlug( (string) $data['package_type'], $request->packageSlug );
			$this->assertPackageSnapshot( $package, $data, $request );
			$identifier = (string) $package->getIdentifier();
			if ( null === $deployment->installedIdentifier || ! hash_equals( $deployment->installedIdentifier, $identifier ) ) {
				$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_SNAPSHOT_CHANGED );
			}
			return array( 'identifier' => $identifier ) + $this->packageRuntimeState( $package );
		} catch ( PackageStorageFailure $failure ) {
			$this->stagePackageStorageFailure( $failure );
		}
	}

	public function maintenanceActive(): bool {
		return file_exists( $this->maintenancePath );
	}

	public function recheckManaged( BranchDeploymentDeclaration $deployment ): void {
		$this->assertDeclaration( $deployment );
		$data    = $this->attempt->safeData();
		$request = $this->attempt->getRequest();
		try {
			$this->assertPackageSnapshot( $this->packageBySlug( (string) $data['package_type'], $request->packageSlug ), $data, $request );
		} catch ( PackageStorageFailure $failure ) {
			$this->stagePackageStorageFailure( $failure );
		}
	}

	public function installed( BranchDeploymentDeclaration $deployment ): array {
		$this->assertDeclaration( $deployment );
		$package    = $this->installedPackage( $deployment->packageType, $deployment->slug );
		$identifier = (string) $package->getIdentifier();
		if ( '' === $identifier ) {
			throw new RuntimeException( 'The installed package identity is unavailable.' );
		}
		return array( 'identifier' => $identifier ) + $this->packageRuntimeState( $package );
	}

	public function baselineNow( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		try {
			$current = $this->packageFromIdentifier( $deployment->packageType, $baseline['identifier'] );
			return array( 'identifier' => (string) $current->getIdentifier() ) + $this->packageRuntimeState( $current );
		} catch ( Throwable ) {
			return null;
		}
	}

	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		$this->assertDeclaration( $deployment );
		$installed  = $this->installedPackage( $deployment->packageType, $deployment->slug );
		$request    = $this->attempt->getRequest();
		$data       = $this->attempt->safeData();
		$repository = new ManagedRepository(
			(string) $data['provider'],
			$request->repository,
			(string) $data['provider_repository_id'],
			$request->configuredBranch,
			$request->private,
			$request->credentialId
		);
		$installed->setRepository( $repository );
		$installed->setSubdirectory( $request->subdirectory );
		$installed->setDeploymentPolicy( $request->deploymentPolicy );
		$result = 'plugin' === $deployment->packageType ? $this->plugins->adopt( $installed ) : $this->themes->adopt( $installed );
		if ( $result->isSuccessful() ) {
			return true;
		}
		return 'ran_booster_storage_adoption_conflict' === $result->getDiagnosticId()
			&& $this->existingManagementMatchesInstalledTarget( $installed );
	}

	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		$this->assertDeclaration( $deployment );
		if ( ! $artifact instanceof PreparedArchiveArtifact ) {
			throw new RuntimeException( 'The admitted branch artifact is not a prepared package archive.' );
		}
		$artifact->assertUnchanged();
	}

	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		$this->assertDeclaration( $deployment );
		if ( ! $artifact instanceof PreparedArchiveArtifact ) {
			throw new RuntimeException( 'The admitted branch artifact is not a prepared package archive.' );
		}
		$archive = $artifact->archive();
		if ( 'install' === $deployment->operation ) {
			return 'plugin' === $deployment->packageType
				? $this->executor->installPlugin( $archive, $deployment->slug, $deployment->subdirectory )
				: $this->executor->installTheme( $archive, $deployment->slug, $deployment->subdirectory );
		}
		if ( null === $baseline ) {
			throw new RuntimeException( 'The managed package baseline is unavailable.' );
		}
		return 'plugin' === $deployment->packageType
			? $this->executor->updatePlugin( $archive, $deployment->slug, $deployment->subdirectory, $baseline['identifier'] )
			: $this->executor->updateTheme( $archive, $deployment->slug, $deployment->subdirectory, $baseline['identifier'] );
	}

	public function run( callable $operation ): mixed {
		try {
			$token = $this->updaterLock->acquire();
		} catch ( DeploymentStorageFailure $failure ) {
			throw new BranchDeploymentLockStorageFailure( 'The WordPress updater lock storage is uncertain.', 0, $failure );
		}

		try {
			return $operation();
		} finally {
			try {
				$released = $this->updaterLock->release( $token );
			} catch ( DeploymentStorageFailure $failure ) {
				throw new BranchDeploymentLockStorageFailure( 'The WordPress updater lock storage is uncertain.', 0, $failure );
			}
			if ( ! $released ) {
				throw new BranchDeploymentLockReleaseFailure( 'The WordPress updater lock could not be released.' );
			}
		}
	}

	private function assertDeclaration( BranchDeploymentDeclaration $deployment ): void {
		$expected = $this->declaration();
		if ( (array) $expected !== (array) $deployment ) {
			throw new RuntimeException( 'The admitted deployment declaration changed.' );
		}
	}

	private function downloadProviderArchive( ProviderPreparedArchive $archive, string $destination, int $maximumArtifactBytes ): void {
		$url = $archive->getUrl();
		$this->assertSafeHttpsUrl( $url );
		try {
			for ( $attempt = 1; $attempt <= self::DOWNLOAD_ATTEMPTS; ++$attempt ) {
				$response = wp_safe_remote_get(
					$url,
					array(
						'timeout'             => self::DOWNLOAD_TIMEOUT,
						'redirection'         => 3,
						'reject_unsafe_urls'  => true,
						'stream'              => true,
						'filename'            => $destination,
						'limit_response_size' => $maximumArtifactBytes + 1,
					)
				);
				if ( is_wp_error( $response ) ) {
					$this->stage( DeploymentOutcome::CODE_ARCHIVE_DOWNLOAD_FAILED );
				}
				$status = (int) wp_remote_retrieve_response_code( $response );
				if ( 429 === $status || in_array( $status, array( 502, 503, 504 ), true ) ) {
					if ( $attempt < self::DOWNLOAD_ATTEMPTS ) {
						continue;
					}
					$this->stage( DeploymentOutcome::fromProviderFailure( new RuntimeException( '', $status ) )->getCode() );
				}
				if ( $status < 200 || $status >= 300 ) {
					$this->stage( DeploymentOutcome::fromProviderFailure( new RuntimeException( '', $status ) )->getCode() );
				}
				return;
			}
		} finally {
			$this->cleanupProviderArchive( $archive );
		}
	}

	private function verifyProviderHead( ProviderPreparedArchive $archive ): void {
		try {
			$archive->verifyCurrentHead();
		} catch ( StaleDeployment ) {
			$this->stage( DeploymentOutcome::CODE_STALE_EVENT );
		} catch ( Throwable $failure ) {
			$this->stage( DeploymentOutcome::fromProviderFailure( $failure )->getCode() );
		}
	}

	private function cleanupProviderArchive( ProviderPreparedArchive $archive ): void {
		try {
			$archive->cleanup();
		} catch ( Throwable ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED );
		}
	}

	private function assertLocalReadiness( BranchDeploymentDeclaration $deployment, int $maximumArtifactBytes ): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_ZIP_EXTENSION_MISSING );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_FILESYSTEM_UNSUPPORTED );
		}
		$tempRoot    = $this->canonicalWritableDirectory( get_temp_dir() );
		$contentRoot = defined( 'WP_CONTENT_DIR' ) ? $this->canonicalWritableDirectory( WP_CONTENT_DIR ) : null;
		$destination = $this->canonicalWritableDirectory( $this->destinationRoot( $deployment ) );
		if ( null === $tempRoot || null === $contentRoot || null === $destination ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DIRECTORY_UNWRITABLE );
		}
		$available = disk_free_space( $tempRoot );
		if ( false === $available || $available < $maximumArtifactBytes ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DISK_SPACE_LOW );
		}
	}

	private function assertArtifactCapacity( PreparedArchiveArtifact $artifact, BranchDeploymentDeclaration $deployment, int $maximumArtifactBytes ): void {
		$path = $artifact->archive()->getPath();
		$size = filesize( $path );
		if ( false === $size || $size > $maximumArtifactBytes ) {
			$this->cleanupArtifactAfterHostFailure( $artifact );
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_COMPRESSED_TOO_LARGE );
		}
		$expanded = $this->expandedBytes( $path );
		if ( $expanded > $maximumArtifactBytes * self::EXPANDED_RATIO ) {
			$this->cleanupArtifactAfterHostFailure( $artifact );
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_EXPANDED_TOO_LARGE );
		}
		$required             = intdiv( ( $expanded * 21 ) + 9, 10 );
		$upgradeAvailable     = defined( 'WP_CONTENT_DIR' ) ? disk_free_space( WP_CONTENT_DIR ) : false;
		$destinationAvailable = disk_free_space( $this->destinationRoot( $deployment ) );
		if ( false === $upgradeAvailable || false === $destinationAvailable || $upgradeAvailable < $required || $destinationAvailable < $required ) {
			$this->cleanupArtifactAfterHostFailure( $artifact );
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DISK_SPACE_LOW );
		}
	}

	private function cleanupArtifactAfterHostFailure( PreparedArchiveArtifact $artifact ): void {
		try {
			$artifact->cleanup();
		} catch ( Throwable ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED );
		}
	}

	private function expandedBytes( string $path ): int {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED );
		}
		$total = 0;
		try {
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$stat = $zip->statIndex( $index, ZipArchive::FL_UNCHANGED );
				if ( false === $stat || ! is_int( $stat['size'] ?? null ) || $stat['size'] < 0 || $total > PHP_INT_MAX - $stat['size'] ) {
					$this->stage( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED );
				}
				$total += $stat['size'];
			}
		} finally {
			$zip->close();
		}
		return $total;
	}

	private function assertSafeHttpsUrl( mixed $url ): void {
		if ( ! is_string( $url ) || '' === $url || trim( $url ) !== $url ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_URL_INVALID );
		}
		$parts = parse_url( $url );
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'], $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_URL_INVALID );
		}
	}

	private function archiveDirectory(): string {
		return rtrim( get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'ran-booster-branch-updater';
	}

	private function destinationRoot( BranchDeploymentDeclaration $deployment ): string {
		if ( 'plugin' === $deployment->packageType ) {
			if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
				$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DIRECTORY_UNWRITABLE );
			}
			return WP_PLUGIN_DIR;
		}
		return (string) get_theme_root();
	}

	private function canonicalWritableDirectory( string $path ): ?string {
		$canonical = realpath( $path );
		return false !== $canonical && is_dir( $canonical ) && is_writable( $canonical ) ? $canonical : null;
	}

	private function existingManagementMatchesInstallTarget(): bool {
		try {
			$data       = $this->attempt->safeData();
			$request    = $this->attempt->getRequest();
			$installed  = $this->installedPackage( (string) $data['package_type'], $request->packageSlug );
			$identifier = (string) $installed->getIdentifier();
			if ( '' === $identifier ) {
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

	private function existingManagementMatchesInstalledTarget( Package $installed ): bool {
		$identifier = (string) $installed->getIdentifier();
		if ( '' === $identifier ) {
			return false;
		}
		try {
			$data     = $this->attempt->safeData();
			$request  = $this->attempt->getRequest();
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
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_SNAPSHOT_CHANGED );
		}
	}

	/** @return array{version:string,active:bool} */
	private function packageRuntimeState( Package $package ): array {
		$identifier = (string) $package->getIdentifier();
		$active     = $package instanceof \RAN\Plugin
			? in_array( $identifier, (array) get_option( 'active_plugins', array() ), true )
			: in_array( $identifier, array( (string) get_option( 'stylesheet', '' ), (string) get_option( 'template', '' ) ), true );
		return array( 'version' => $package->getVersion(), 'active' => $active );
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
		return 'plugin' === $type ? $this->plugins->boosterPluginFromFile( $identifier ) : $this->themes->boosterThemeFromStylesheet( $identifier );
	}

	private function destinationExists( string $type, string $slug ): bool {
		$root = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root();
		return file_exists( $root . '/' . $slug ) || is_link( $root . '/' . $slug );
	}

	private function stagePackageStorageFailure( PackageStorageFailure $failure ): never {
		$this->stage(
			'ran_booster_repository_source_conflict' === $failure->getDiagnosticId()
				? DeploymentOutcome::CODE_REPOSITORY_SOURCE_CONFLICT
				: DeploymentOutcome::CODE_REPOSITORY_SOURCE_UNAVAILABLE
		);
	}

	private function stage( string $code ): never {
		throw new AdmittedBranchStageFailure( $code );
	}
}
