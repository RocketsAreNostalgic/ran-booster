<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RAN\Secrets\SecretsFile;

/**
 * Retains the legacy shared-updater discovery adapter for prospective releases.
 */
final class ManagedReleasePreflight {

	public function __construct( private SecretsFile $secrets ) {
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
			'release_id' => $discovery->releaseId(),
			'tag'        => $discovery->tag(),
			'version'    => $discovery->version(),
			'channel'    => $channel,
		);
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
			&& is_string( $repository['private'] ?? null );
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
