<?php

declare(strict_types=1);

namespace RAN\Admin;

/**
 * @internal The one rule for using or choosing a saved repository credential (#1236, #1329).
 *
 * @phpstan-import-type PackageProviderSettings from ProviderSettingsPresenter
 */
final class CredentialAuthority {

	/** Whether the current user may use a saved credential: both capabilities, as for secure storage. */
	public static function held(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' );
	}

	/**
	 * @internal Package form and picker choices the current user can save (#1350). With authority the settings are unchanged;
	 * without it each provider keeps only the package's exact own credential on its stored provider, and no default or lookup profile.
	 *
	 * @param PackageProviderSettings $settings
	 * @return PackageProviderSettings
	 */
	public static function package_choices( array $settings, string $own_provider = '', string $own_credential_id = '' ): array {
		if ( self::held() ) {
			return $settings;
		}
		foreach ( $settings['providers'] as $index => $provider ) {
			$own                               = '' !== $own_credential_id && $provider['code'] === $own_provider;
			$provider['credential_profiles']   = array_values( array_filter( $provider['credential_profiles'], static fn ( array $profile ): bool => $own && $profile['id'] === $own_credential_id ) );
			$provider['default_credential_id'] = '';
			$provider['public_lookup']         = null;
			$provider['credentials_url']       = '';
			$settings['providers'][ $index ]   = $provider;
		}

		return $settings;
	}
}
