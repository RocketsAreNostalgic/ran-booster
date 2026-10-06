<?php

declare(strict_types=1);

// Internal exception values are control-flow/diagnostic transport, not rendered output.
// Trust-boundary URL parsing and local writability probes intentionally use PHP primitives.

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

	private ?ProviderPreparedArchive $provider_archive = null;
	private bool $provider_archive_cleaned             = false;

	public function __construct(
		private DeploymentAttempt $attempt,
		private readonly DeploymentAttemptRepository $attempts,
		private readonly PluginRepository $plugins,
		private readonly ThemeRepository $themes,
		private readonly ProviderRegistry $providers,
		private readonly RepositorySourceGuard $source_guard,
		private readonly WordPressUpdaterLock $updater_lock,
		private readonly WordPressCorePackageExecutor $executor,
		private readonly string $maintenance_path
	) {
		if ( '' === trim( $maintenance_path ) ) {
			throw new RuntimeException( 'The WordPress maintenance path is invalid.' );
		}
	}

	/** Construct the immutable package declaration from Booster's admitted snapshot. */
	public function declaration(): BranchDeploymentDeclaration {
		$data       = $this->attempt->safe_data();
		$request    = $this->attempt->get_request();
		$identifier = null;

		if ( PackageSource::BRANCH->value !== ( $data['package_source'] ?? null ) ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_RELEASE_SOURCE_BLOCKED );
		}

		if ( 'update' === $data['operation'] ) {
			try {
				$identifier = (string) $this->package_by_slug( (string) $data['package_type'], $request->package_slug )->get_identifier();
			} catch ( PackageStorageFailure $failure ) {
				$this->stage_package_storage_failure( $failure );
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
			(string) $this->attempt->get_id(),
			(string) $data['package_type'],
			$request->package_slug,
			$request->repository,
			(string) $data['provider_repository_id'],
			$request->configured_branch,
			'webhook' === $data['source'] ? (string) $data['requested_ref'] : null,
			(string) $data['operation'],
			$request->subdirectory,
			$identifier
		);
	}

	public function record_resolved_ref( string $ref ): void {
		try {
			$this->attempt = $this->attempts->record_resolved_ref( $this->attempt->get_id(), $ref );
		} catch ( DeploymentStorageFailure $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed failure text and chained exceptions preserve durable failure mapping; they are not output.
			throw new AdmittedBranchDurabilityFailure( previous: $failure );
		}
	}

	public function mark_mutation_started(): void {
		try {
			$this->attempt = $this->attempts->mark_mutation_started( $this->attempt->get_id() );
		} catch ( DeploymentStorageFailure $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed failure text and chained exceptions preserve durable failure mapping; they are not output.
			throw new AdmittedBranchDurabilityFailure( previous: $failure );
		}
	}

	public function finish( string $code ): void {
		try {
			$this->attempt = $this->attempts->finish( $this->attempt->get_id(), DeploymentOutcome::from_code( $code ) );
		} catch ( DeploymentStorageFailure $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed failure text and chained exceptions preserve durable failure mapping; they are not output.
			throw new AdmittedBranchDurabilityFailure( previous: $failure );
		}
	}
	public function terminal_attempt(): DeploymentAttempt {
		if ( ! $this->attempt->get_state()->is_terminal() || null === $this->attempt->get_outcome() ) {
			throw DeploymentStorageFailure::inconsistent();
		}

		return $this->attempt;
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInImplementedInterfaceAfterLastUsed -- AdmittedArchiveSource requires the baseline parameter; this host prepares from its already-admitted deployment attempt.
	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		$this->assert_declaration( $deployment );
		if ( null !== $this->provider_archive ) {
			throw new RuntimeException( 'The admitted archive source is already consumed.' );
		}

		$data                   = $this->attempt->safe_data();
		$request                = $this->attempt->get_request();
		$maximum_artifact_bytes = $request->maximum_artifact_bytes;
		$provider               = ProviderCode::parse( (string) $data['provider'] );
		$reference              = new RepositoryReference(
			$request->repository,
			(string) $data['provider_repository_id'],
			$request->is_private,
			$request->credential_id
		);

		try {
			$this->provider_archive = $this->providers->get( $provider )->prepare_archive(
				new ArchiveRequest(
					$reference,
					(string) $data['requested_ref'],
					'webhook' === $data['source'] ? $request->configured_branch : null
				)
			);
		} catch ( Throwable $failure ) {
			$this->stage( DeploymentOutcome::from_provider_failure( $failure )->get_code() );
		}

		$provider_archive = $this->provider_archive;
		try {
			$resolved_ref = $provider_archive->get_resolved_ref();
			if ( '' === $resolved_ref || trim( $resolved_ref ) !== $resolved_ref || strlen( $resolved_ref ) > 191 || preg_match( '/[[:cntrl:]]/', $resolved_ref ) === 1 ) {
				$this->stage( DeploymentOutcome::CODE_ARCHIVE_REVISION_INVALID );
			}
			if ( null !== $deployment->expected_head && ! hash_equals( $deployment->expected_head, $resolved_ref ) ) {
				$this->stage( DeploymentOutcome::CODE_ARCHIVE_REVISION_INVALID );
			}
		} catch ( AdmittedBranchStageFailure $failure ) {
			$this->cleanup_provider_archive( $provider_archive );
			throw $failure;
		} catch ( Throwable $failure ) {
			$this->cleanup_provider_archive( $provider_archive );
			$this->stage( DeploymentOutcome::from_provider_failure( $failure )->get_code() );
		}

		try {
			$this->assert_local_readiness( $deployment, $maximum_artifact_bytes );
		} catch ( Throwable $failure ) {
			$this->cleanup_provider_archive( $provider_archive );
			throw $failure;
		}

		$offer = new ArchiveOffer(
			$provider->value,
			(string) $data['provider_repository_id'],
			$resolved_ref,
			function ( string $destination, int $provider_maximum_artifact_bytes ) use ( $provider_archive, $maximum_artifact_bytes ): void {
				if ( $provider_maximum_artifact_bytes !== $maximum_artifact_bytes ) {
					$this->stage( DeploymentOutcome::CODE_ARCHIVE_LIMIT_INVALID );
				}
				$this->download_provider_archive( $provider_archive, $destination, $maximum_artifact_bytes );
			},
			function () use ( $provider_archive ): void {
				$this->verify_provider_head( $provider_archive );
			}
		);

		$artifact = null;
		try {
			$artifact = new PreparedArchiveArtifact(
				PreparedArchive::download_and_validate(
					$offer,
					$deployment,
					$this->archive_directory(),
					$maximum_artifact_bytes
				)
			);
			$this->assert_artifact_capacity( $artifact, $deployment );
			return $artifact;
		} catch ( AdmittedBranchStageFailure $failure ) {
			$this->cleanup_provider_archive( $provider_archive );
			if ( $artifact instanceof PreparedArchiveArtifact ) {
				$this->cleanup_artifact_after_host_failure( $artifact );
			}
			throw $failure;
		} catch ( Throwable ) {
			$this->cleanup_provider_archive( $provider_archive );
			if ( $artifact instanceof PreparedArchiveArtifact ) {
				$this->cleanup_artifact_after_host_failure( $artifact );
			}
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_INTEGRITY_FAILED );
		}
	}

	public function verify_current_head(): void {
		if ( null === $this->provider_archive ) {
			$this->stage( DeploymentOutcome::CODE_PROVIDER_FAILED );
		}
		$this->verify_provider_head( $this->provider_archive );
	}

	public function assert_mutation_allowed(): void {
		PackageMutationGuard::assert_filesystem_mutation_allowed();
	}

	public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array {
		$this->assert_declaration( $deployment );
		$data    = $this->attempt->safe_data();
		$request = $this->attempt->get_request();

		try {
			if ( PackageSource::BRANCH->value !== ( $data['package_source'] ?? null ) ) {
				$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_RELEASE_SOURCE_BLOCKED );
			}
			if ( 'install' === $data['operation'] ) {
				if ( 'plugin' === $data['package_type'] && 'ran-booster' === $request->package_slug ) {
					$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_SELF_UPDATE_BLOCKED );
				}
				if ( $this->destination_exists( (string) $data['package_type'], $request->package_slug ) ) {
					if ( $this->existing_management_matches_install_target() ) {
						if ( $defer_existing ) {
							return null;
						}
						$this->stage( DeploymentOutcome::CODE_ALREADY_MANAGED );
					}
					$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DESTINATION_EXISTS );
				}
				$this->source_guard->assert_allowed(
					(string) $data['provider'],
					(string) $data['provider_repository_id'],
					'plugin' === $data['package_type'] ? 1 : 2,
					$request->package_slug,
					PackageSource::BRANCH
				);
				return null;
			}

			$package = $this->package_by_slug( (string) $data['package_type'], $request->package_slug );
			$this->assert_package_snapshot( $package, $data, $request );
			$identifier = (string) $package->get_identifier();
			if ( null === $deployment->installed_identifier || ! hash_equals( $deployment->installed_identifier, $identifier ) ) {
				$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_SNAPSHOT_CHANGED );
			}
			return array( 'identifier' => $identifier ) + $this->package_runtime_state( $package );
		} catch ( PackageStorageFailure $failure ) {
			$this->stage_package_storage_failure( $failure );
		}
	}

	public function maintenance_active(): bool {
		return file_exists( $this->maintenance_path );
	}

	public function recheck_managed( BranchDeploymentDeclaration $deployment ): void {
		$this->assert_declaration( $deployment );
		$data    = $this->attempt->safe_data();
		$request = $this->attempt->get_request();
		try {
			$this->assert_package_snapshot( $this->package_by_slug( (string) $data['package_type'], $request->package_slug ), $data, $request );
		} catch ( PackageStorageFailure $failure ) {
			$this->stage_package_storage_failure( $failure );
		}
	}

	public function installed( BranchDeploymentDeclaration $deployment ): array {
		$this->assert_declaration( $deployment );
		$package    = $this->installed_package( $deployment->package_type, $deployment->slug );
		$identifier = (string) $package->get_identifier();
		if ( '' === $identifier ) {
			throw new RuntimeException( 'The installed package identity is unavailable.' );
		}
		return array( 'identifier' => $identifier ) + $this->package_runtime_state( $package );
	}

	public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		try {
			$current = $this->package_from_identifier( $deployment->package_type, $baseline['identifier'] );
			return array( 'identifier' => (string) $current->get_identifier() ) + $this->package_runtime_state( $current );
		} catch ( Throwable ) {
			return null;
		}
	}

	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		$this->assert_declaration( $deployment );
		$installed  = $this->installed_package( $deployment->package_type, $deployment->slug );
		$request    = $this->attempt->get_request();
		$data       = $this->attempt->safe_data();
		$repository = new ManagedRepository(
			(string) $data['provider'],
			$request->repository,
			(string) $data['provider_repository_id'],
			$request->configured_branch,
			$request->is_private,
			$request->credential_id
		);
		$installed->set_repository( $repository );
		$installed->set_subdirectory( $request->subdirectory );
		$installed->set_deployment_policy( $request->deployment_policy );
		$result = $installed instanceof \RAN\Plugin ? $this->plugins->adopt( $installed ) : $this->themes->adopt( $installed );
		if ( $result->is_successful() ) {
			return true;
		}
		return 'ran_booster_storage_adoption_conflict' === $result->get_diagnostic_id()
			&& $this->existing_management_matches_installed_target( $installed );
	}

	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		$this->assert_declaration( $deployment );
		if ( ! $artifact instanceof PreparedArchiveArtifact ) {
			throw new RuntimeException( 'The admitted branch artifact is not a prepared package archive.' );
		}
		$artifact->assert_unchanged();
	}

	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		$this->assert_declaration( $deployment );
		if ( ! $artifact instanceof PreparedArchiveArtifact ) {
			throw new RuntimeException( 'The admitted branch artifact is not a prepared package archive.' );
		}
		$archive = $artifact->archive();
		if ( 'install' === $deployment->operation ) {
			return 'plugin' === $deployment->package_type
				? $this->executor->install_plugin( $archive, $deployment->slug, $deployment->subdirectory )
				: $this->executor->install_theme( $archive, $deployment->slug, $deployment->subdirectory );
		}
		if ( null === $baseline ) {
			throw new RuntimeException( 'The managed package baseline is unavailable.' );
		}
		return 'plugin' === $deployment->package_type
			? $this->executor->update_plugin( $archive, $deployment->slug, $deployment->subdirectory, $baseline['identifier'] )
			: $this->executor->update_theme( $archive, $deployment->slug, $deployment->subdirectory, $baseline['identifier'] );
	}

	public function run( callable $operation ): mixed {
		try {
			$token = $this->updater_lock->acquire();
		} catch ( DeploymentStorageFailure $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed failure text and chained exceptions preserve durable failure mapping; they are not output.
			throw new BranchDeploymentLockStorageFailure( 'The WordPress updater lock storage is uncertain.', 0, $failure );
		}

		try {
			return $operation();
		} finally {
			try {
				$released = $this->updater_lock->release( $token );
			} catch ( DeploymentStorageFailure $failure ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed failure text and chained exceptions preserve durable failure mapping; they are not output.
				throw new BranchDeploymentLockStorageFailure( 'The WordPress updater lock storage is uncertain.', 0, $failure );
			}
			if ( ! $released ) {
				throw new BranchDeploymentLockReleaseFailure( 'The WordPress updater lock could not be released.' );
			}
		}
	}

	private function assert_declaration( BranchDeploymentDeclaration $deployment ): void {
		$expected = $this->declaration();
		if ( (array) $expected !== (array) $deployment ) {
			throw new RuntimeException( 'The admitted deployment declaration changed.' );
		}
	}

	private function download_provider_archive( ProviderPreparedArchive $archive, string $destination, int $maximum_artifact_bytes ): void {
		try {
			$url = $archive->get_url();
			$this->assert_safe_https_url( $url );
			for ( $attempt = 1; $attempt <= self::DOWNLOAD_ATTEMPTS; ++$attempt ) {
				$response = wp_safe_remote_get(
					$url,
					array(
						'timeout'             => self::DOWNLOAD_TIMEOUT,
						'redirection'         => 3,
						'reject_unsafe_urls'  => true,
						'stream'              => true,
						'filename'            => $destination,
						'limit_response_size' => $maximum_artifact_bytes + 1,
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
					$this->stage( DeploymentOutcome::from_provider_failure( new RuntimeException( '', $status ) )->get_code() );
				}
				if ( $status < 200 || $status >= 300 ) {
					$this->stage( DeploymentOutcome::from_provider_failure( new RuntimeException( '', $status ) )->get_code() );
				}
				return;
			}
		} finally {
			$this->cleanup_provider_archive( $archive );
		}
	}

	private function verify_provider_head( ProviderPreparedArchive $archive ): void {
		try {
			$archive->verify_current_head();
		} catch ( StaleDeployment ) {
			$this->stage( DeploymentOutcome::CODE_STALE_EVENT );
		} catch ( Throwable $failure ) {
			$this->stage( DeploymentOutcome::from_provider_failure( $failure )->get_code() );
		}
	}

	private function cleanup_provider_archive( ProviderPreparedArchive $archive ): void {
		if ( $this->provider_archive_cleaned ) {
			return;
		}
		$this->provider_archive_cleaned = true;
		try {
			$archive->cleanup();
		} catch ( Throwable ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED );
		}
	}

	private function assert_local_readiness( BranchDeploymentDeclaration $deployment, int $maximum_artifact_bytes ): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_ZIP_EXTENSION_MISSING );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_FILESYSTEM_UNSUPPORTED );
		}
		$temp_root    = $this->canonical_writable_directory( get_temp_dir() );
		$content_root = defined( 'WP_CONTENT_DIR' ) ? $this->canonical_writable_directory( WP_CONTENT_DIR ) : null;
		$destination  = $this->canonical_writable_directory( $this->destination_root( $deployment ) );
		if ( null === $temp_root || null === $content_root || null === $destination ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DIRECTORY_UNWRITABLE );
		}
		$available = disk_free_space( $temp_root );
		if ( false === $available || $available < $maximum_artifact_bytes ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DISK_SPACE_LOW );
		}
	}

	private function assert_artifact_capacity( PreparedArchiveArtifact $artifact, BranchDeploymentDeclaration $deployment ): void {
		$expanded = $artifact->archive()->expanded_bytes();
		$overhead = intdiv( $expanded, 10 ) + ( 0 === $expanded % 10 ? 0 : 1 );
		if ( $expanded > intdiv( PHP_INT_MAX - $overhead, 2 ) ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DISK_SPACE_LOW );
		}
		$required              = ( $expanded * 2 ) + $overhead;
		$upgrade_available     = defined( 'WP_CONTENT_DIR' ) ? disk_free_space( WP_CONTENT_DIR ) : false;
		$destination_available = disk_free_space( $this->destination_root( $deployment ) );
		if ( false === $upgrade_available || false === $destination_available || $upgrade_available < $required || $destination_available < $required ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DISK_SPACE_LOW );
		}
	}

	private function cleanup_artifact_after_host_failure( PreparedArchiveArtifact $artifact ): void {
		try {
			$artifact->cleanup();
		} catch ( Throwable ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_CLEANUP_FAILED );
		}
	}

	private function assert_safe_https_url( mixed $url ): void {
		if ( ! is_string( $url ) || '' === $url || trim( $url ) !== $url ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_URL_INVALID );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Trust-boundary validation rejects malformed URLs using native parse_url semantics before admitting HTTPS artifacts.
		$parts = parse_url( $url );
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			$this->stage( DeploymentOutcome::CODE_ARCHIVE_URL_INVALID );
		}
	}

	private function archive_directory(): string {
		return rtrim( get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'ran-booster-branch-updater';
	}

	private function destination_root( BranchDeploymentDeclaration $deployment ): string {
		if ( 'plugin' === $deployment->package_type ) {
			if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
				$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_DIRECTORY_UNWRITABLE );
			}
			return WP_PLUGIN_DIR;
		}
		return (string) get_theme_root();
	}

	private function canonical_writable_directory( string $path ): ?string {
		$canonical = realpath( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Admission requires a real canonical directory writable by the local PHP process before WordPress installation.
		return false !== $canonical && is_dir( $canonical ) && is_writable( $canonical ) ? $canonical : null;
	}

	private function existing_management_matches_install_target(): bool {
		try {
			$data       = $this->attempt->safe_data();
			$request    = $this->attempt->get_request();
			$installed  = $this->installed_package( (string) $data['package_type'], $request->package_slug );
			$identifier = (string) $installed->get_identifier();
			if ( '' === $identifier ) {
				return false;
			}
			$existing = $this->package_from_identifier( (string) $data['package_type'], $identifier );
			return hash_equals( $identifier, (string) $existing->get_identifier() )
				&& $existing->get_provider_code() === $data['provider']
				&& hash_equals( (string) $existing->get_provider_repository_id(), (string) $data['provider_repository_id'] )
				&& hash_equals( (string) $existing->get_repository(), $request->repository )
				&& hash_equals( (string) $existing->get_subdirectory(), (string) $request->subdirectory )
				&& hash_equals( (string) $existing->get_slug(), $request->package_slug );
		} catch ( Throwable ) {
			return false;
		}
	}

	private function existing_management_matches_installed_target( Package $installed ): bool {
		$identifier = (string) $installed->get_identifier();
		if ( '' === $identifier ) {
			return false;
		}
		try {
			$data     = $this->attempt->safe_data();
			$request  = $this->attempt->get_request();
			$existing = $this->package_from_identifier( (string) $data['package_type'], $identifier );
			return hash_equals( $identifier, (string) $existing->get_identifier() )
				&& PackageSource::BRANCH === $existing->get_source()
				&& $existing->get_provider_code() === $data['provider']
				&& hash_equals( (string) $existing->get_provider_repository_id(), (string) $data['provider_repository_id'] )
				&& hash_equals( (string) $existing->get_repository(), $request->repository )
				&& hash_equals( (string) $existing->get_branch(), $request->configured_branch )
				&& hash_equals( $existing->get_credential_id(), (string) $request->credential_id )
				&& hash_equals( (string) $existing->get_subdirectory(), (string) $request->subdirectory )
				&& (bool) $existing->get_private() === $request->is_private
				&& hash_equals( (string) $existing->get_slug(), $request->package_slug )
				&& $existing->get_deployment_policy() === $request->deployment_policy;
		} catch ( Throwable ) {
			return false;
		}
	}

	private function assert_package_snapshot( Package $package, array $data, DeploymentRequest $request ): void {
		if ( $package->get_provider_code() !== $data['provider']
			|| ! hash_equals( (string) $package->get_provider_repository_id(), (string) $data['provider_repository_id'] )
			|| ! hash_equals( (string) $package->get_repository(), $request->repository )
			|| ! hash_equals( (string) $package->get_branch(), $request->configured_branch )
			|| ! hash_equals( $package->get_credential_id(), (string) $request->credential_id )
			|| ! hash_equals( (string) $package->get_subdirectory(), (string) $request->subdirectory )
			|| (bool) $package->get_private() !== $request->is_private
			|| ! hash_equals( (string) $package->get_slug(), $request->package_slug )
			|| $package->get_deployment_policy() !== $request->deployment_policy
			|| $package->get_source()->value !== ( $data['package_source'] ?? null )
			|| $package->get_source_revision() !== ( $data['package_source_revision'] ?? null ) ) {
			$this->stage( DeploymentOutcome::CODE_DEPLOYMENT_SNAPSHOT_CHANGED );
		}
	}

	/** @return array{version:string,active:bool} */
	private function package_runtime_state( Package $package ): array {
		$identifier = (string) $package->get_identifier();
		$active     = $package instanceof \RAN\Plugin
			? in_array( $identifier, (array) get_option( 'active_plugins', array() ), true )
			: in_array( $identifier, array( (string) get_option( 'stylesheet', '' ), (string) get_option( 'template', '' ) ), true );
		return array(
			'version' => $package->get_version(),
			'active'  => $active,
		);
	}

	/** @return \RAN\Plugin|\RAN\Theme */
	private function installed_package( string $type, string $slug ): Package {
		return 'plugin' === $type ? $this->plugins->from_slug( $slug ) : $this->themes->from_slug( $slug );
	}

	private function package_by_slug( string $type, string $slug ): Package {
		$matches = array_filter(
			'plugin' === $type ? $this->plugins->all_deployment_plugins() : $this->themes->all_deployment_themes(),
			static fn ( Package $package ): bool => (string) $package->get_slug() === $slug
		);
		if ( 1 !== count( $matches ) ) {
			throw new RuntimeException( 'The managed package identity is unavailable or ambiguous.' );
		}
		return reset( $matches );
	}

	private function package_from_identifier( string $type, string $identifier ): Package {
		return 'plugin' === $type ? $this->plugins->booster_plugin_from_file( $identifier ) : $this->themes->booster_theme_from_stylesheet( $identifier );
	}

	private function destination_exists( string $type, string $slug ): bool {
		$root = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root();
		return file_exists( $root . '/' . $slug ) || is_link( $root . '/' . $slug );
	}

	private function stage_package_storage_failure( PackageStorageFailure $failure ): never {
		$this->stage(
			'ran_booster_repository_source_conflict' === $failure->get_diagnostic_id()
				? DeploymentOutcome::CODE_REPOSITORY_SOURCE_CONFLICT
				: DeploymentOutcome::CODE_REPOSITORY_SOURCE_UNAVAILABLE
		);
	}

	private function stage( string $code ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Closed failure text and chained exceptions preserve durable failure mapping; they are not output.
		throw new AdmittedBranchStageFailure( $code );
	}
}
