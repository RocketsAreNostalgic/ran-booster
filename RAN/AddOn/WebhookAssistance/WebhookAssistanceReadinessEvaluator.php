<?php

declare(strict_types=1);

namespace RAN\AddOn\WebhookAssistance;

use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\RepositoryLocator;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

/** Builds one display-safe readiness projection for Core and add-on consumers. */
final class WebhookAssistanceReadinessEvaluator {

	/** @var \Closure(): bool */
	private \Closure $can_manage;

	/** @param callable(): bool|null $can_manage */
	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes,
		private SecretsFile $secrets,
		private Database $database,
		?callable $can_manage = null
	) {
		$this->can_manage = null === $can_manage
			? static fn (): bool => current_user_can( 'manage_options' )
			: \Closure::fromCallable( $can_manage );
	}

	public function evaluate( string $provider, string $callback_url ): AssistanceReadiness {
		if ( ! ( $this->can_manage )() ) {
			return new AssistanceReadiness( array( 'managed_packages_unavailable' ), $callback_url, array() );
		}

		$site_reasons   = array();
		$database_ready = true;
		try {
			$this->database->require_ready();
		} catch ( \Throwable ) {
			$database_ready = false;
			$site_reasons[] = 'database_unavailable';
		}

		$profiles = null;
		try {
			$this->secrets->assert_managed_storage_ready();
			$profiles = $this->secrets->webhook_profiles( $provider );
		} catch ( \Throwable ) {
			$site_reasons[] = 'secrets_storage_unavailable';
		}

		if ( ! $this->is_structurally_public_https( $callback_url ) ) {
			$site_reasons[] = 'callback_requires_public_https';
		}

		$repositories = array();
		if ( $database_ready ) {
			try {
				$repositories = $this->repository_readiness( $provider, $site_reasons, $profiles );
			} catch ( \Throwable ) {
				$site_reasons[] = 'managed_packages_unavailable';
			}
		}

		return new AssistanceReadiness( $site_reasons, $callback_url, $repositories );
	}

	public function managed_storage_available(): bool {
		try {
			$this->database->require_ready();
			$this->secrets->assert_managed_storage_ready();

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
	public function cleanup_target( string $provider, string $repository_id, string $callback_url ): ?AssistanceTarget {
		if ( ! ( $this->can_manage )() || ! $this->valid_repository_id( $repository_id ) ) {
			return null;
		}

		try {
			$this->database->require_ready();
			$packages = array_merge(
				$this->plugins->all_deployment_plugins(),
				$this->themes->all_deployment_themes()
			);
		} catch ( \Throwable ) {
			return null;
		}

		$release_packages = array();
		$release_locator  = null;
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package || $provider !== $package->get_provider_code() ) {
				continue;
			}

			$locator          = (string) $package->get_repository();
			$normalized       = strtolower( trim( $locator, '/' ) );
			$package_identity = $package->get_provider_repository_id();
			$identity_matches = is_string( $package_identity ) && hash_equals( $repository_id, $package_identity );
			$locator_matches  = null !== $release_locator && hash_equals( $release_locator, $normalized );

			if ( PackageSource::RELEASE_ASSET === $package->get_source() && $identity_matches ) {
				if ( ! $this->safe_repository( $locator )
					|| ( null !== $release_locator && ! hash_equals( $release_locator, $normalized ) ) ) {
					return null;
				}
				$release_locator    = $normalized;
				$release_packages[] = $package;
				continue;
			}

			if ( PackageSource::BRANCH === $package->get_source() && ( $identity_matches || $locator_matches ) ) {
				return null;
			}
		}

		if ( array() === $release_packages || null === $release_locator ) {
			return null;
		}

		// A branch package may have appeared before the release locator was known.
		foreach ( $packages as $package ) {
			if ( $package instanceof Package
				&& $provider === $package->get_provider_code()
				&& PackageSource::BRANCH === $package->get_source()
				&& hash_equals( $release_locator, strtolower( trim( (string) $package->get_repository(), '/' ) ) )
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
		foreach ( $release_packages as $package ) {
			$references[] = (string) $package->get_identifier();
			++$policies[ $package->get_deployment_policy()->value ];
		}
		sort( $references, SORT_STRING );
		$repository = (string) $release_packages[0]->get_repository();

		return new AssistanceTarget(
			$provider,
			$repository_id,
			$repository,
			$repository,
			$references,
			$policies,
			$callback_url
		);
	}

	/**
	 * @param list<string>                              $site_reasons
	 * @param array<string, array<string, mixed>>|null $profiles
	 * @return list<array<string, mixed>>
	 */
	private function repository_readiness( string $provider, array $site_reasons, ?array $profiles ): array {
		$repositories = array();
		foreach ( array_merge( $this->plugins->all_deployment_plugins(), $this->themes->all_deployment_themes() ) as $package ) {
			if ( ! $package instanceof Package
				|| PackageSource::BRANCH !== $package->get_source()
				|| $provider !== $package->get_provider_code() ) {
				continue;
			}

			$repository            = (string) $package->get_repository();
			$key                   = strtolower( trim( $repository, '/' ) );
			$entry                 = $repositories[ $key ] ?? array(
				'repository' => $repository,
				'packages'   => array(),
				'identities' => array(),
				'automatic'  => 0,
				'manual'     => 0,
				'disabled'   => 0,
			);
			$repository_id         = $package->get_provider_repository_id();
			$entry['identities'][] = is_string( $repository_id ) && $this->valid_repository_id( $repository_id )
				? $repository_id
				: null;
			$entry['packages'][]   = (string) $package->get_identifier();
			++$entry[ $package->get_deployment_policy()->value ];
			$repositories[ $key ] = $entry;
		}

		$identity_owners = array();
		foreach ( $repositories as $key => $entry ) {
			foreach ( array_unique( array_filter( $entry['identities'], 'is_string' ) ) as $identity ) {
				$identity_owners[ $identity ][ $key ] = true;
			}
		}

		ksort( $repositories, SORT_NATURAL | SORT_FLAG_CASE );
		$readiness = array();
		foreach ( $repositories as $entry ) {
			$reasons       = array();
			$identities    = array_values( array_unique( $entry['identities'], SORT_REGULAR ) );
			$valid_ids     = array_values( array_filter( $identities, 'is_string' ) );
			$repository_id = 1 === count( $valid_ids ) ? $valid_ids[0] : null;

			if ( ! $this->safe_repository( $entry['repository'] ) ) {
				$reasons[] = 'repository_locator_invalid';
			}
			if ( array() === $valid_ids ) {
				$reasons[] = 'repository_identity_unavailable';
			} elseif ( 1 !== count( $identities )
				|| 1 !== count( $valid_ids )
				|| 1 < count( $identity_owners[ $repository_id ] ?? array() )
			) {
				$reasons[]     = 'repository_identity_conflict';
				$repository_id = null;
			}

			sort( $entry['packages'], SORT_STRING );
			$eligible    = array() === $site_reasons && array() === $reasons;
			$readiness[] = array(
				'provider_code'         => $provider,
				'repository_id'         => $repository_id,
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
				'local_secret_coverage' => $this->local_secret_coverage( $entry['repository'], $repository_id, $profiles ),
				'eligible'              => $eligible,
			);
		}

		return $readiness;
	}

	/** @param array<string, array<string, mixed>>|null $profiles */
	private function local_secret_coverage( string $repository, ?string $repository_id, ?array $profiles ): string {
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
				&& null !== $repository_id
				&& is_string( $profile['authority_id'] ?? null )
				&& hash_equals( $repository_id, $profile['authority_id'] )
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

	private function valid_repository_id( string $repository_id ): bool {
		return '' !== trim( $repository_id ) && strlen( $repository_id ) <= 191 && 1 !== preg_match( '/[\x00-\x1F\x7F]/', $repository_id );
	}

	private function safe_repository( string $repository ): bool {
		try {
			RepositoryLocator::require_valid( $repository );

			return true;
		} catch ( \InvalidArgumentException ) {
			return false;
		}
	}

	private function is_structurally_public_https( string $callback_url ): bool {
		$parts = wp_parse_url( $callback_url );

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

		$host    = strtolower( $parts['host'] );
		$ip_host = trim( $host, '[]' );

		return ! in_array( $ip_host, array( 'localhost', '::1' ), true )
			&& ! str_ends_with( $host, '.local' )
			&& ( ! filter_var( $ip_host, FILTER_VALIDATE_IP ) || false !== filter_var( $ip_host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) );
	}
}
