<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

use Closure;
use LogicException;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;

/** Maps a managed GitHub package onto the selected provider-neutral runtime. */
final class GitHubReleaseNativeTarget implements RepositoryReleaseNativeTarget {

	private ?object $updater = null;

	/** @var string|callable|null */
	private string|Closure|null $accessToken;

	/** @param string|callable|null $accessToken */
	public function __construct(
		private string $packageType,
		private string $metadataFile,
		private string $repository,
		private string $providerRepositoryId,
		private string $packageRoot,
		private string $installedIdentifier,
		string|callable|null $accessToken,
		private string $channel,
		private string $deploymentPolicy
	) {
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true )
			|| ! in_array( $deploymentPolicy, array( 'disabled', 'forced-off', 'manual', 'automatic' ), true ) ) {
			throw new LogicException( 'The GitHub release native target is incompatible.' );
		}
		$this->accessToken = is_string( $accessToken ) || null === $accessToken
			? $accessToken
			: Closure::fromCallable( $accessToken );
	}

	public function register(): bool {
		if ( null !== $this->updater ) {
			return true;
		}

		try {
			$binding     = \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::create( $this->bindingFacts() );
			$credentials = $this->credentials();
			global $wpdb;
			if ( ! is_object( $wpdb ) ) {
				return false;
			}
			$updater = \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter::registerFromConfiguration(
				$this->configuration(),
				$binding,
				$credentials,
				$wpdb,
				$this->archivePolicy()
			);
		} catch ( \Throwable ) {
			return false;
		}
		if ( ! is_object( $updater ) ) {
			return false;
		}
		$this->updater = $updater;

		return true;
	}

	public function status(): RepositoryReleaseNativeTargetStatus {
		if ( null === $this->updater ) {
			return new RepositoryReleaseNativeTargetStatus( false );
		}
		if ( ! is_callable( array( $this->updater, 'status' ) ) ) {
			return new RepositoryReleaseNativeTargetStatus( true, failureCode: 'github_updater_status_unavailable' );
		}

		try {
			$status = $this->updater->status();
			if ( ! is_array( $status )
				|| array_keys( $status ) !== array(
					'candidate_tag',
					'candidate_validation_code',
					'candidate_version',
					'candidate_header_version',
					'failure_code',
					'installed_version',
					'last_check',
					'offered_version',
					'relationship',
				) ) {
				throw new LogicException( 'The neutral updater status is incompatible.' );
			}
			if ( ! $this->validUpdaterStatus( $status ) ) {
				throw new LogicException( 'The neutral updater status is incompatible.' );
			}

			$candidateCode          = $this->candidateCode( $status['candidate_validation_code'] );
			$candidateTag           = $this->statusText( $status['candidate_tag'], 100 );
			$candidateVersion       = $this->statusVersion( $status['candidate_version'] );
			$candidateHeaderVersion = $this->statusVersion( $status['candidate_header_version'] );
			if ( '' === $candidateCode || '' === $candidateTag || '' === $candidateVersion ) {
				$candidateCode          = '';
				$candidateTag           = '';
				$candidateVersion       = '';
				$candidateHeaderVersion = '';
			}

			return new RepositoryReleaseNativeTargetStatus(
				true,
				$this->statusVersion( $status['offered_version'] ),
				$this->statusRelationship( $status['relationship'] ),
				is_int( $status['last_check'] ) && 0 < $status['last_check'] ? $status['last_check'] : null,
				null,
				$this->statusCode( $status['failure_code'] ),
				$candidateCode,
				$candidateTag,
				$candidateVersion,
				$candidateHeaderVersion
			);
		} catch ( \Throwable ) {
			return new RepositoryReleaseNativeTargetStatus( true, failureCode: 'github_updater_status_unavailable' );
		}
	}

	public function refresh(): bool {
		if ( null === $this->updater || ! is_callable( array( $this->updater, 'refresh' ) ) ) {
			return false;
		}

		try {
			$this->updater->refresh();
		} catch ( \Throwable ) {
			return false;
		}

		return true;
	}

	/** @return array<string, string> */
	private function bindingFacts(): array {
		$wordpressVersion = $this->wordpressVersion();
		$uri              = 'https://github.com/' . $this->repository;

		return array(
			'canonical_repository_locator' => $this->repository,
			'canonical_update_uri'         => $uri,
			'installed_package_identity'   => $this->installedIdentifier,
			'php_runtime_version'          => PHP_VERSION,
			'provider_code'                => 'github',
			'release_channel'              => $this->channel,
			'stable_repository_identity'   => $this->providerRepositoryId,
			'target_type'                  => $this->packageType,
			'update_policy'                => $this->deploymentPolicy,
			'wordpress_runtime_version'    => $wordpressVersion,
		);
	}

	/** @return array<string, mixed> */
	private function configuration(): array {
		$headers = $this->headers();
		$uri     = 'https://github.com/' . $this->repository;

		return array(
			'headers'                    => $headers,
			'installed_package_identity' => $this->installedIdentifier,
			'policy'                     => $this->deploymentPolicy,
			'target_type'                => $this->packageType,
			'update_uri'                 => $uri,
		);
	}

	/** @return array<string, string> */
	private function archivePolicy(): array {
		$headers          = $this->headers();
		$uri              = 'https://github.com/' . $this->repository;
		$wordpressVersion = $this->wordpressVersion();

		return array(
			'archive_root'               => $this->packageRoot,
			'configuration_update_uri'   => $uri,
			'header_file'                => basename( $this->metadataFile ),
			'installed_package_identity' => $this->installedIdentifier,
			'metadata_name'              => $headers['Name'],
			'offer_update_uri'           => $uri,
			'php_runtime_version'        => PHP_VERSION,
			'provider_code'              => 'github',
			'repository_identity'        => $this->providerRepositoryId,
			'repository_locator'         => $this->repository,
			'staged_package_update_uri'  => $uri,
			'target_type'                => $this->packageType,
			'wordpress_runtime_version'  => $wordpressVersion,
		);
	}

	private function credentials(): ?\RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubCredentialResolver {
		if ( is_callable( $this->accessToken ) ) {
			return new \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubCredentialResolver( $this->accessToken );
		}
		if ( is_string( $this->accessToken ) ) {
			$accessToken = $this->accessToken;

			return new \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubCredentialResolver( static fn (): string => $accessToken );
		}

		return null;
	}

	/** @return array<string, string> */
	private function headers(): array {
		if ( ! function_exists( 'get_file_data' ) ) {
			throw new LogicException( 'The GitHub release native target metadata is unavailable.' );
		}
		$values = get_file_data(
			$this->metadataFile,
			array(
				'Author'      => 'Author',
				'Description' => 'Description',
				'Name'        => 'theme' === $this->packageType ? 'Theme Name' : 'Plugin Name',
				'PluginURI'   => 'theme' === $this->packageType ? 'Theme URI' : 'Plugin URI',
				'RequiresPHP' => 'Requires PHP',
				'RequiresWP'  => 'Requires at least',
				'UpdateURI'   => 'Update URI',
				'Version'     => 'Version',
			),
			$this->packageType
		);
		if ( ! is_array( $values ) || 8 !== count( $values ) ) {
			throw new LogicException( 'The GitHub release native target metadata is incompatible.' );
		}

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				throw new LogicException( 'The GitHub release native target metadata is incompatible.' );
			}
		}

		/** @var array<string, string> $values */
		return $values;
	}

	private function wordpressVersion(): string {
		global $wp_version;
		if ( ! is_string( $wp_version ) || '' === $wp_version ) {
			throw new LogicException( 'The GitHub release native target runtime is unavailable.' );
		}

		return $wp_version;
	}

	private function candidateCode( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return match ( $value ) {
			'archive_identity_verified' => 'release_identity_verified',
			'archive_version_mismatch' => 'release_version_mismatch',
			'archive_header_missing' => 'package_header_missing',
			'archive_header_duplicate' => 'package_header_ambiguous',
			'archive_metadata_identity_mismatch' => 'package_header_invalid',
			'archive_unreadable', 'archive_entry_unreadable', 'archive_header_unreadable' => 'package_archive_unreadable',
			'archive_zip_extension_unavailable' => 'package_zip_extension_unavailable',
			'archive_size_limit' => 'package_archive_too_large',
			'archive_path_unsafe', 'archive_file_identity_mismatch', 'archive_target_policy_invalid' => 'package_archive_path_unsafe',
			'archive_path_duplicate' => 'package_archive_path_duplicate',
			'archive_root_mismatch' => 'package_archive_root_invalid',
			'archive_entry_limit' => 'package_archive_entry_limit',
			'archive_update_uri_mismatch' => 'package_update_uri_invalid',
			'archive_php_requirement_incompatible', 'archive_wordpress_requirement_incompatible' => 'package_compatibility_invalid',
			default => 'github_updater_release_incompatible',
		};
	}

	/** @param array<string, mixed> $status */
	private function validUpdaterStatus( array $status ): bool {
		return ( null === $status['candidate_tag'] || '' !== $this->statusText( $status['candidate_tag'], 100 ) )
			&& ( null === $status['candidate_validation_code'] || '' !== $this->statusCode( $status['candidate_validation_code'] ) )
			&& ( null === $status['candidate_version'] || '' !== $this->statusVersion( $status['candidate_version'] ) )
			&& ( null === $status['candidate_header_version'] || '' !== $this->statusVersion( $status['candidate_header_version'] ) )
			&& ( null === $status['failure_code'] || '' !== $this->statusCode( $status['failure_code'] ) )
			&& ( null === $status['installed_version'] || '' !== $this->statusVersion( $status['installed_version'] ) )
			&& ( null === $status['last_check'] || ( is_int( $status['last_check'] ) && 0 < $status['last_check'] ) )
			&& ( null === $status['offered_version'] || '' !== $this->statusVersion( $status['offered_version'] ) )
			&& ( null === $status['relationship'] || '' !== $this->statusRelationship( $status['relationship'] ) );
	}

	private function statusCode( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/D', $value ) ? $value : '';
	}

	private function statusRelationship( mixed $value ): string {
		return is_string( $value ) && in_array( $value, array( 'newer', 'same', 'older', 'invalid' ), true ) ? $value : '';
	}

	private function statusText( mixed $value, int $maximumLength ): string {
		return is_string( $value ) && strlen( $value ) <= $maximumLength && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ? $value : '';
	}

	private function statusVersion( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $value ) ? $value : '';
	}
}
