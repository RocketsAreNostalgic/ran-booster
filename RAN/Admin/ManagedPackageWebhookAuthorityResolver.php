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

		foreach ( array_merge( $this->plugins->all_deployment_plugins(), $this->themes->all_deployment_themes() ) as $package ) {
			if ( ! $package instanceof Package
				|| PackageSource::BRANCH !== $package->get_source()
				|| $package->get_provider_code() !== $provider->value
				|| ! $policy->repository_target_matches( $target, (string) $package->get_repository() )
			) {
				continue;
			}

			$authorityId = $package->get_provider_repository_id();
			if ( ! is_string( $authorityId ) || '' === trim( $authorityId ) ) {
				throw new CredentialRequestException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception remains a plain administrator message.
					__( 'This managed package does not have a stable repository identity. Re-save its repository settings before creating a repository-scoped webhook secret.', 'ran-booster' )
				);
			}

			$matches[ $authorityId ] = true;
		}

		if ( 1 !== count( $matches ) ) {
			throw new CredentialRequestException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception remains a plain administrator message.
				__( 'Choose a managed repository with exactly one stable provider identity before creating a repository-scoped webhook secret.', 'ran-booster' )
			);
		}

		return (string) array_key_first( $matches );
	}

	public function resolveOwner(
		ProviderCode $provider,
		string $owner
	): string {
		$owner = strtolower( trim( $owner, " \t\n\r\0\x0B/" ) );
		foreach ( array_merge( $this->plugins->all_deployment_plugins(), $this->themes->all_deployment_themes() ) as $package ) {
			if ( ! $package instanceof Package
				|| PackageSource::BRANCH !== $package->get_source()
				|| $package->get_provider_code() !== $provider->value ) {
				continue;
			}

			$repository = trim( (string) $package->get_repository(), " \t\n\r\0\x0B/" );
			$parts      = explode( '/', $repository, 2 );
			if ( 2 === count( $parts )
				&& strtolower( $parts[0] ) === $owner
			) {
				return $parts[0];
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception remains a plain administrator message.
		throw new CredentialRequestException( __( 'Choose an account owner from the managed repositories before creating an owner-scoped webhook secret.', 'ran-booster' ) );
	}

	/** @return array{provider_code:string,repository_id:string}|null */
	public function forPackage( string $type, string $identifier ): ?array {
		try {
			$package = 'plugin' === $type
				? $this->plugins->booster_plugin_from_file( $identifier )
				: ( 'theme' === $type ? $this->themes->booster_theme_from_stylesheet( $identifier ) : null );
		} catch ( \Throwable ) {
			return null;
		}

		if ( ! $package instanceof Package || PackageSource::BRANCH !== $package->get_source() ) {
			return null;
		}
		$providerCode = $package->get_provider_code();
		$repositoryId = $package->get_provider_repository_id();
		if ( ! is_string( $providerCode ) || ! is_string( $repositoryId ) || '' === trim( $providerCode ) || '' === trim( $repositoryId ) ) {
			return null;
		}

		return array(
			'provider_code' => $providerCode,
			'repository_id' => $repositoryId,
		);
	}
}
