<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Package;
use RAN\PackageSource;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderWebhookPolicy;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

/**
 * Bind a display locator to the one stable managed-package repository identity.
 */
final readonly class ManagedPackageWebhookAuthorityResolver {

	public function __construct(
		private PluginRepository $plugins,
		private ThemeRepository $themes
	) {
	}

	public function resolve(
		ProviderCode $provider,
		ProviderWebhookPolicy $policy,
		string $target
	): string {
		$matches = array();

		foreach ( array_merge( $this->plugins->allDeploymentPlugins(), $this->themes->allDeploymentThemes() ) as $package ) {
			if ( ! $package instanceof Package
				|| PackageSource::BRANCH !== $package->getSource()
				|| $package->getProviderCode() !== $provider->value
				|| ! $policy->repositoryTargetMatches( $target, (string) $package->getRepository() )
			) {
				continue;
			}

			$authorityId = $package->getProviderRepositoryId();
			if ( ! is_string( $authorityId ) || '' === trim( $authorityId ) ) {
				throw new CredentialRequestException(
					'This managed package does not have a stable repository identity. Re-save its repository settings before creating a repository-scoped webhook secret.'
				);
			}

			$matches[ $authorityId ] = true;
		}

		if ( 1 !== count( $matches ) ) {
			throw new CredentialRequestException(
				'Choose a managed repository with exactly one stable provider identity before creating a repository-scoped webhook secret.'
			);
		}

		return (string) array_key_first( $matches );
	}

	public function resolveOwner(
		ProviderCode $provider,
		string $owner
	): string {
		$owner = strtolower( trim( $owner, " \t\n\r\0\x0B/" ) );
		foreach ( array_merge( $this->plugins->allDeploymentPlugins(), $this->themes->allDeploymentThemes() ) as $package ) {
			if ( ! $package instanceof Package
				|| PackageSource::BRANCH !== $package->getSource()
				|| $package->getProviderCode() !== $provider->value ) {
				continue;
			}

			$repository = trim( (string) $package->getRepository(), " \t\n\r\0\x0B/" );
			$parts      = explode( '/', $repository, 2 );
			if ( 2 === count( $parts )
				&& $owner === strtolower( $parts[0] )
			) {
				return $parts[0];
			}
		}

		throw new CredentialRequestException( 'Choose an account owner from the managed repositories before creating an owner-scoped webhook secret.' );
	}

	/** @return array{provider_code:string,repository_id:string}|null */
	public function forPackage( string $type, string $identifier ): ?array {
		try {
			$package = 'plugin' === $type
				? $this->plugins->boosterPluginFromFile( $identifier )
				: ( 'theme' === $type ? $this->themes->boosterThemeFromStylesheet( $identifier ) : null );
		} catch ( \Throwable ) {
			return null;
		}

		if ( ! $package instanceof Package || PackageSource::BRANCH !== $package->getSource() ) {
			return null;
		}
		$providerCode = $package->getProviderCode();
		$repositoryId = $package->getProviderRepositoryId();
		if ( ! is_string( $providerCode ) || ! is_string( $repositoryId ) || '' === trim( $providerCode ) || '' === trim( $repositoryId ) ) {
			return null;
		}

		return array( 'provider_code' => $providerCode, 'repository_id' => $repositoryId );
	}
}
