<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use RAN\Package;
use RAN\PackageSource;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

/** Builds one display-safe readiness projection for Core and add-on consumers. */
final class WebhookAssistanceReadinessEvaluator {

	/** @var \Closure(): bool */
	private \Closure $canManage;

	/** @param callable(): bool|null $canManage */
	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private SecretsFile $secrets,
		private Database $database,
		?callable $canManage = null
	) {
		$this->canManage = null === $canManage
			? static fn (): bool => current_user_can( 'manage_options' )
			: \Closure::fromCallable( $canManage );
	}

	public function evaluate( string $provider, string $callbackUrl ): AssistanceReadiness {
		if ( ! ( $this->canManage )() ) {
			return new AssistanceReadiness( array( 'managed_packages_unavailable' ), $callbackUrl, array() );
		}

		$siteReasons   = array();
		$databaseReady = true;
		try {
			$this->database->requireReady();
		} catch ( \Throwable ) {
			$databaseReady = false;
			$siteReasons[] = 'database_unavailable';
		}

		$profiles = null;
		try {
			$this->secrets->assertManagedStorageReady();
			$profiles = $this->secrets->webhookProfiles( $provider );
		} catch ( \Throwable ) {
			$siteReasons[] = 'secrets_storage_unavailable';
		}

		if ( ! $this->isStructurallyPublicHttps( $callbackUrl ) ) {
			$siteReasons[] = 'callback_requires_public_https';
		}

		$repositories = array();
		if ( $databaseReady ) {
			try {
				$repositories = $this->repositoryReadiness( $provider, $siteReasons, $profiles );
			} catch ( \Throwable ) {
				$siteReasons[] = 'managed_packages_unavailable';
			}
		}

		return new AssistanceReadiness( $siteReasons, $callbackUrl, $repositories );
	}

	public function managedStorageAvailable(): bool {
		try {
			$this->database->requireReady();
			$this->secrets->assertManagedStorageReady();

			return true;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Resolve cleanup authority for one release-managed repository.
	 *
	 * Cleanup is unavailable when a branch-managed package shares either the
	 * stable provider identity or normalized repository locator. This prevents
	 * optional release-package hygiene from removing setup that Branch still
	 * consumes.
	 */
	public function cleanupTarget( string $provider, string $repositoryId, string $callbackUrl ): ?AssistanceTarget {
		if ( ! ( $this->canManage )() || ! $this->validRepositoryId( $repositoryId ) ) {
			return null;
		}

		try {
			$this->database->requireReady();
			$packages = array_merge(
				$this->plugins->allDeploymentPlugins(),
				$this->themes->allDeploymentThemes()
			);
		} catch ( \Throwable ) {
			return null;
		}

		$releasePackages = array();
		$releaseLocator  = null;
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package || $provider !== $package->getProviderCode() ) {
				continue;
			}

			$locator         = (string) $package->getRepository();
			$normalized      = strtolower( trim( $locator, '/' ) );
			$packageIdentity = $package->getProviderRepositoryId();
			$identityMatches = is_string( $packageIdentity ) && hash_equals( $repositoryId, $packageIdentity );
			$locatorMatches  = null !== $releaseLocator && hash_equals( $releaseLocator, $normalized );

			if ( PackageSource::RELEASE_ASSET === $package->getSource() && $identityMatches ) {
				if ( ! $this->safeRepository( $locator )
					|| ( null !== $releaseLocator && ! hash_equals( $releaseLocator, $normalized ) ) ) {
					return null;
				}
				$releaseLocator    = $normalized;
				$releasePackages[] = $package;
				continue;
			}

			if ( PackageSource::BRANCH === $package->getSource() && ( $identityMatches || $locatorMatches ) ) {
				return null;
			}
		}

		if ( array() === $releasePackages || null === $releaseLocator ) {
			return null;
		}

		// A branch package may have appeared before the release locator was known.
		foreach ( $packages as $package ) {
			if ( $package instanceof Package
				&& $provider === $package->getProviderCode()
				&& PackageSource::BRANCH === $package->getSource()
				&& hash_equals( $releaseLocator, strtolower( trim( (string) $package->getRepository(), '/' ) ) )
			) {
				return null;
			}
		}

		$references = array();
		$policies   = array(
			'automatic' => 0,
			'manual'    => 0,
			'disabled'  => 0,
		);
		foreach ( $releasePackages as $package ) {
			$references[] = (string) $package->getIdentifier();
			++$policies[ $package->getDeploymentPolicy()->value ];
		}
		sort( $references, SORT_STRING );
		$repository = (string) $releasePackages[0]->getRepository();

		return new AssistanceTarget(
			$provider,
			$repositoryId,
			$repository,
			$repository,
			$references,
			$policies,
			$callbackUrl
		);
	}

	/**
	 * @param list<string>                              $siteReasons
	 * @param array<string, array<string, mixed>>|null $profiles
	 * @return list<array<string, mixed>>
	 */
	private function repositoryReadiness( string $provider, array $siteReasons, ?array $profiles ): array {
		$repositories = array();
		foreach ( array_merge( $this->plugins->allDeploymentPlugins(), $this->themes->allDeploymentThemes() ) as $package ) {
			if ( ! $package instanceof Package
				|| PackageSource::BRANCH !== $package->getSource()
				|| $provider !== $package->getProviderCode() ) {
				continue;
			}

			$repository            = (string) $package->getRepository();
			$key                   = strtolower( trim( $repository, '/' ) );
			$entry                 = $repositories[ $key ] ?? array(
				'repository' => $repository,
				'packages'   => array(),
				'identities' => array(),
				'automatic'  => 0,
				'manual'     => 0,
				'disabled'   => 0,
			);
			$repositoryId          = $package->getProviderRepositoryId();
			$entry['identities'][] = is_string( $repositoryId ) && $this->validRepositoryId( $repositoryId )
				? $repositoryId
				: null;
			$entry['packages'][]   = (string) $package->getIdentifier();
			++$entry[ $package->getDeploymentPolicy()->value ];
			$repositories[ $key ] = $entry;
		}

		$identityOwners = array();
		foreach ( $repositories as $key => $entry ) {
			foreach ( array_unique( array_filter( $entry['identities'], 'is_string' ) ) as $identity ) {
				$identityOwners[ $identity ][ $key ] = true;
			}
		}

		ksort( $repositories, SORT_NATURAL | SORT_FLAG_CASE );
		$readiness = array();
		foreach ( $repositories as $entry ) {
			$reasons      = array();
			$identities   = array_values( array_unique( $entry['identities'], SORT_REGULAR ) );
			$validIds     = array_values( array_filter( $identities, 'is_string' ) );
			$repositoryId = 1 === count( $validIds ) ? $validIds[0] : null;

			if ( ! $this->safeRepository( $entry['repository'] ) ) {
				$reasons[] = 'repository_locator_invalid';
			}
			if ( array() === $validIds ) {
				$reasons[] = 'repository_identity_unavailable';
			} elseif ( 1 !== count( $identities )
				|| 1 !== count( $validIds )
				|| 1 < count( $identityOwners[ $repositoryId ] ?? array() )
			) {
				$reasons[]    = 'repository_identity_conflict';
				$repositoryId = null;
			}

			sort( $entry['packages'], SORT_STRING );
			$eligible    = array() === $siteReasons && array() === $reasons;
			$readiness[] = array(
				'provider_code'         => $provider,
				'repository_id'         => $repositoryId,
				'repository'            => $entry['repository'],
				'label'                 => $entry['repository'],
				'package_references'    => $entry['packages'],
				'deployment_policies'   => array(
					'automatic' => $entry['automatic'],
					'manual'    => $entry['manual'],
					'disabled'  => $entry['disabled'],
				),
				'status'                => $eligible ? AssistanceReadiness::READY : AssistanceReadiness::BLOCKED,
				'reason_codes'          => $reasons,
				'local_secret_coverage' => $this->localSecretCoverage( $entry['repository'], $repositoryId, $profiles ),
				'eligible'              => $eligible,
			);
		}

		return $readiness;
	}

	/** @param array<string, array<string, mixed>>|null $profiles */
	private function localSecretCoverage( string $repository, ?string $repositoryId, ?array $profiles ): string {
		if ( null === $profiles ) {
			return AssistanceReadiness::SECRET_UNKNOWN;
		}

		$owner  = strtolower( explode( '/', trim( $repository, '/' ), 2 )[0] );
		$shared = false;
		foreach ( $profiles as $profile ) {
			if ( false === ( $profile['configured'] ?? true ) ) {
				continue;
			}
			$scope = strtolower( trim( (string) ( $profile['scope'] ?? '' ) ) );
			if ( 'repository' === $scope
				&& null !== $repositoryId
				&& is_string( $profile['authority_id'] ?? null )
				&& hash_equals( $repositoryId, $profile['authority_id'] )
			) {
				return AssistanceReadiness::SECRET_REPOSITORY;
			}
			$target = strtolower( trim( (string) ( $profile['target'] ?? '' ), " \t\n\r\0\x0B/" ) );
			if ( 'owner' === $scope && '' !== $owner && $owner === $target ) {
				$shared = true;
			}
		}

		return $shared ? AssistanceReadiness::SECRET_SHARED : AssistanceReadiness::SECRET_NONE;
	}

	private function validRepositoryId( string $repositoryId ): bool {
		return '' !== trim( $repositoryId ) && strlen( $repositoryId ) <= 191 && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $repositoryId );
	}

	private function safeRepository( string $repository ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_.-]{0,99})\/[A-Za-z0-9](?:[A-Za-z0-9_.-]{0,99})$/', $repository );
	}

	private function isStructurallyPublicHttps( string $callbackUrl ): bool {
		$parts = wp_parse_url( $callbackUrl );

		if ( ! is_array( $parts )
			|| 'https' !== ( $parts['scheme'] ?? null )
			|| ! is_string( $parts['host'] ?? null )
			|| '' === $parts['host']
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}

		$host   = strtolower( $parts['host'] );
		$ipHost = trim( $host, '[]' );

		return ! in_array( $ipHost, array( 'localhost', '::1' ), true )
			&& ! str_ends_with( $host, '.local' )
			&& ( ! filter_var( $ipHost, FILTER_VALIDATE_IP ) || false !== filter_var( $ipHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) );
	}
}
