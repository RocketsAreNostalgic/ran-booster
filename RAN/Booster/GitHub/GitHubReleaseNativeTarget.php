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
		$this->accessToken = is_callable( $accessToken ) ? Closure::fromCallable( $accessToken ) : $accessToken;
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
		return new RepositoryReleaseNativeTargetStatus( null !== $this->updater );
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
}
