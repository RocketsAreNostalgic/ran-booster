<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\Package;
use RAN\Secrets\SecretsFile;

/**
 * Runs the shared updater's bounded, read-only artifact verification before a
 * managed package is allowed to adopt published releases.
 */
final class ManagedReleasePreflight {

	public function __construct( private SecretsFile $secrets ) {
	}

	public function __invoke(
		string $type,
		Package $package,
		string $packageRoot,
		string $headerFile,
		bool $force = false,
		string $channel = 'stable'
	): ReleaseTrackingPreflight {
		try {
			$preflightClass = 'RAN\\WPGitHubReleaseUpdater\\V1\\WordPress\\ReleaseCandidatePreflight';
			if ( ! class_exists( $preflightClass ) ) {
				return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $packageRoot );
			}

			$target    = array(
				'repository'           => (string) $package->getRepository(),
				'providerRepositoryId' => (string) $package->getProviderRepositoryId(),
				'pluginSlug'           => $packageRoot,
				'mainFile'             => $headerFile,
				'channel'              => $channel,
				'accessToken'          => $this->accessToken( $package ),
				'packageType'          => $type,
				'themeRoot'            => 'theme' === $type ? $packageRoot : null,
			);
			$candidate = $preflightClass::fromTarget( $target );
			if ( $candidate instanceof \WP_Error ) {
				return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS, $packageRoot );
			}
			$candidate = $candidate->check( $force );
			if ( $candidate instanceof \WP_Error ) {
				return new ReleaseTrackingPreflight(
					in_array( $candidate->get_error_code(), array( 'github_updater_no_eligible_release', 'github_updater_release_incompatible' ), true )
						? ReleaseTrackingPreflight::RELEASE_UNAVAILABLE
						: ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE,
					$packageRoot
				);
			}

			$code = match ( $candidate->code() ) {
				'release_version_mismatch' => ReleaseTrackingPreflight::RELEASE_VERSION_MISMATCH,
				'package_header_missing' => ReleaseTrackingPreflight::RELEASE_HEADER_MISSING,
				'package_header_invalid' => ReleaseTrackingPreflight::RELEASE_HEADER_INVALID,
				'package_archive_unreadable' => ReleaseTrackingPreflight::RELEASE_ARCHIVE_UNREADABLE,
				'release_identity_verified' => ReleaseTrackingPreflight::READY,
				default => ReleaseTrackingPreflight::INVALID_RELEASE_ASSETS,
			};

			return new ReleaseTrackingPreflight(
				$code,
				$packageRoot,
				$candidate->releaseVersion(),
				$this->releaseUrl( (string) $package->getRepository(), $candidate->releaseTag() ),
				$candidate->releaseTag(),
				$candidate->packageHeaderVersion() ?? ''
			);
		} catch ( \Throwable ) {
			return new ReleaseTrackingPreflight( ReleaseTrackingPreflight::PREFLIGHT_UNAVAILABLE, $packageRoot );
		}
	}

	/**
	 * Discover one release from the requested channel without downloading its ZIP.
	 *
	 * @param array<string, mixed> $repository
	 * @return array<string, bool|int|string>|\WP_Error
	 */
	public function discoverProspective(
		string $type,
		array $repository,
		string $channel
	): array|\WP_Error {
		$candidate = $this->prospectiveCandidate( $type, $repository, $channel );
		if ( $candidate instanceof \WP_Error ) {
			return $candidate;
		}
		$discovery = $candidate->discover();
		if ( $discovery instanceof \WP_Error ) {
			return $discovery;
		}

		return array(
			'release_id'         => $discovery->releaseId(),
			'tag'                => $discovery->tag(),
			'version'            => $discovery->version(),
			'channel'            => $channel,
			'default_branch'     => (string) $repository['repository_default_branch'],
			'selected_branch'    => (string) $repository['branch'],
			'non_default_branch' => ! hash_equals(
				(string) $repository['repository_default_branch'],
				(string) $repository['branch']
			),
		);
	}

	/**
	 * List the bounded published releases allowed by the requested channel
	 * without downloading ZIP archives.
	 *
	 * @param array<string, mixed> $repository
	 * @return array{candidates: list<array{release_id: int, tag: string, version: string, prerelease: bool, published_at: string, expected_asset_names: list<string>}>, channel: string}|\WP_Error
	 */
	public function listProspective(
		string $type,
		array $repository,
		string $channel
	): array|\WP_Error {
		$candidate = $this->prospectiveCandidate( $type, $repository, $channel );
		if ( $candidate instanceof \WP_Error ) {
			return $candidate;
		}
		$releases = $candidate->listCandidates();
		if ( $releases instanceof \WP_Error ) {
			return $releases;
		}

		$summaries = array();
		foreach ( $releases as $release ) {
			$summaries[] = array(
				'release_id'           => $release->releaseId(),
				'tag'                  => $release->tag(),
				'version'              => $release->version(),
				'prerelease'           => $release->isPrerelease(),
				'published_at'         => $release->publishedAt(),
				'expected_asset_names' => $release->expectedAssetNames(),
			);
		}

		return array(
			'candidates' => $summaries,
			'channel'    => $channel,
		);
	}

	/**
	 * Inspect and discard one exact release ZIP from the requested channel.
	 *
	 * @param array<string, mixed> $repository
	 * @return array<string, bool|int|string>|\WP_Error
	 */
	public function inspectProspective(
		string $type,
		array $repository,
		int $releaseId,
		string $tag,
		string $channel
	): array|\WP_Error {
		$candidate = $this->prospectiveCandidate( $type, $repository, $channel );
		if ( $candidate instanceof \WP_Error ) {
			return $candidate;
		}
		$inspection = $candidate->inspectExact(
			$releaseId,
			$tag,
			(string) $repository['repository_default_branch']
		);
		if ( $inspection instanceof \WP_Error ) {
			return $inspection;
		}

		return $this->prospectiveInspectionData( $inspection, $repository, $channel );
	}

	/**
	 * Revalidate and acquire the exact archive selected by the administrator.
	 *
	 * @param array<string, mixed> $repository
	 * @return ProspectiveReleaseArtifact|\WP_Error
	 */
	public function acquireProspective(
		string $type,
		array $repository,
		int $releaseId,
		string $tag,
		string $expectedFingerprint,
		string $channel
	): ProspectiveReleaseArtifact|\WP_Error {
		$candidate = $this->prospectiveCandidate( $type, $repository, $channel );
		if ( $candidate instanceof \WP_Error ) {
			return $candidate;
		}
		$fingerprintClass = 'RAN\\WPGitHubReleaseUpdater\\V1\\WordPress\\ReleaseFingerprint';
		$fingerprint      = $fingerprintClass::fromString( $expectedFingerprint );
		if ( $fingerprint instanceof \WP_Error ) {
			return $fingerprint;
		}
		$validated = $candidate->acquireExact(
			$releaseId,
			$tag,
			(string) $repository['repository_default_branch'],
			$fingerprint
		);
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$inspection = $validated->inspection();

		return new ProspectiveReleaseArtifact(
			$validated,
			$inspection->releaseId(),
			$inspection->tag(),
			$inspection->version(),
			$inspection->commit(),
			$inspection->detailsUrl(),
			$inspection->packageRoot(),
			$inspection->mainFile()
		);
	}

	private function releaseUrl( string $repository, string $tag ): string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}\z/D', $repository )
			|| '' === $tag || strlen( $tag ) > 100 ) {
			return '';
		}

		return 'https://github.com/' . $repository . '/releases/tag/' . rawurlencode( $tag );
	}

	/**
	 * @param array<string, mixed> $repository
	 * @return object|\WP_Error
	 */
	private function prospectiveCandidate(
		string $type,
		array $repository,
		string $channel
	): object {
		$preflightClass = 'RAN\\WPGitHubReleaseUpdater\\V1\\WordPress\\ReleaseCandidatePreflight';
		if ( ! class_exists( $preflightClass )
			|| ! defined( $preflightClass . '::PROSPECTIVE_API_VERSION' )
			|| GitHubReleaseUpdaterBootstrap::UPDATER_PROSPECTIVE_API_VERSION
			!== constant( $preflightClass . '::PROSPECTIVE_API_VERSION' )
			|| ! method_exists( $preflightClass, 'fromProspectiveTarget' )
			|| ! method_exists( $preflightClass, 'listCandidates' )
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true )
			|| ! $this->validProspectiveRepository( $type, $repository ) ) {
			return new \WP_Error(
				'github_updater_release_preflight_unavailable',
				'Published release validation is unavailable.'
			);
		}

		$candidate = $preflightClass::fromProspectiveTarget(
			array(
				'repository'           => (string) $repository['repository'],
				'providerRepositoryId' => (string) $repository['provider_repository_id'],
				'channel'              => $channel,
				'accessToken'          => $this->accessTokenForCredential(
					(string) $repository['credential_id'],
					'1' === (string) $repository['private']
				),
				'packageType'          => $type,
			)
		);

		return is_object( $candidate )
			? $candidate
			: new \WP_Error(
				'github_updater_invalid_preflight_target',
				'The release preflight target is invalid.'
			);
	}

	/** @param array<string, mixed> $repository */
	private function validProspectiveRepository( string $type, array $repository ): bool {
		return in_array( $type, array( 'plugin', 'theme' ), true )
			&& 'gh' === ( $repository['provider'] ?? null )
			&& is_string( $repository['repository'] ?? null )
			&& is_string( $repository['provider_repository_id'] ?? null )
			&& 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/D', $repository['provider_repository_id'] )
			&& is_string( $repository['credential_id'] ?? null )
			&& is_string( $repository['private'] ?? null )
			&& is_string( $repository['repository_default_branch'] ?? null )
			&& '' !== $repository['repository_default_branch']
			&& is_string( $repository['branch'] ?? null )
			&& '' !== $repository['branch'];
	}

	/**
	 * @param object               $inspection
	 * @param array<string, mixed> $repository
	 * @return array<string, bool|int|string>
	 */
	private function prospectiveInspectionData(
		object $inspection,
		array $repository,
		string $channel
	): array {
		return array(
			'release_id'         => $inspection->releaseId(),
			'tag'                => $inspection->tag(),
			'version'            => $inspection->version(),
			'commit'             => $inspection->commit(),
			'details_url'        => $inspection->detailsUrl(),
			'package_root'       => $inspection->packageRoot(),
			'main_file'          => $inspection->mainFile(),
			'fingerprint'        => $inspection->fingerprint()->value(),
			'channel'            => $channel,
			'default_branch'     => (string) $repository['repository_default_branch'],
			'selected_branch'    => (string) $repository['branch'],
			'non_default_branch' => ! hash_equals(
				(string) $repository['repository_default_branch'],
				(string) $repository['branch']
			),
		);
	}

	private function accessToken( Package $package ): ?callable {
		$credentialId = $package->getCredentialId();
		if ( ! $package->getPrivate() && '' === $credentialId ) {
			return null;
		}

		return $this->accessTokenForCredential( $credentialId, (bool) $package->getPrivate() );
	}

	private function accessTokenForCredential( string $credentialId, bool $private ): ?callable {
		if ( ! $private && '' === $credentialId ) {
			return null;
		}

		return function () use ( $credentialId ): ?string {
			$credential = $this->secrets->credentialMaterial( 'gh', '' === $credentialId ? null : $credentialId );
			$secret     = is_array( $credential ) ? ( $credential['secret'] ?? null ) : null;

			return is_string( $secret ) && '' !== $secret ? $secret : null;
		};
	}
}
