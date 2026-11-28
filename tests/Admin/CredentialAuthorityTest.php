<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use RAN\Admin\CredentialAuthority;

/**
 * @phpstan-import-type PackageProviderSettings from \RAN\Admin\ProviderSettingsPresenter
 */
final class CredentialAuthorityTest extends TestCase {

	#[After]
	public function forget_capabilities(): void {
		unset( $GLOBALS['ran_booster_test_capabilities'], $GLOBALS['ran_booster_test_capability_checks'] );
	}

	public function test_secret_authority_needs_both_capabilities(): void {
		foreach ( array( array( true, true, true ), array( true, false, false ), array( false, true, false ) ) as list( $manage, $activate, $held ) ) {
			$this->capabilities( $manage, $activate );

			self::assertSame( $held, CredentialAuthority::held() );
		}
	}

	public function test_a_user_with_authority_gets_the_package_choices_back_byte_for_byte(): void {
		$this->capabilities( true, true );
		$settings = $this->settings();

		// Strict sameness compares every key, value, type and order.
		self::assertSame( $settings, CredentialAuthority::package_choices( $settings, 'gh', 'credential_1' ) );
		self::assertSame( $settings, CredentialAuthority::package_choices( $settings ) );
	}

	public function test_a_package_only_user_keeps_only_the_exact_own_credential_on_its_stored_provider(): void {
		$this->capabilities( true, false );
		$settings = $this->settings();

		$create = CredentialAuthority::package_choices( $settings );
		$edit   = CredentialAuthority::package_choices( $settings, 'gh', 'credential_2' );
		$gap    = CredentialAuthority::package_choices( $settings, 'gh', 'credential_2 ' );

		foreach ( array( $create, $edit, $gap ) as $choices ) {
			self::assertSame( array( 'default_provider', 'providers', 'restore_args' ), array_keys( $choices ) );
			self::assertSame( 'gh', $choices['default_provider'] );
			self::assertSame( array( 'page' => 'ran-booster' ), $choices['restore_args'] ?? null );
			foreach ( $choices['providers'] as $index => $provider ) {
				self::assertSame( '', $provider['default_credential_id'] );
				self::assertNull( $provider['public_lookup'] );
				self::assertSame( '', $provider['credentials_url'] );
				self::assertSame( $settings['providers'][ $index ]['label'], $provider['label'] );
				self::assertSame( array_keys( $settings['providers'][ $index ] ), array_keys( $provider ) );
			}
		}
		self::assertSame( array( array(), array() ), array_column( $create['providers'], 'credential_profiles' ) );
		self::assertSame( array( array(), array() ), array_column( $gap['providers'], 'credential_profiles' ) );
		self::assertSame( array( array( 'credential_2' ), array() ), array_map( static fn ( array $provider ): array => array_column( $provider['credential_profiles'], 'id' ), $edit['providers'] ) );
	}

	private function capabilities( bool $manage, bool $activate ): void {
		$GLOBALS['ran_booster_test_capabilities'] = array(
			'manage_options'   => $manage,
			'activate_plugins' => $activate,
		);
	}

	/** @return PackageProviderSettings */
	private function settings(): array {
		$profile  = static fn ( string $id ): array => array(
			'id'         => $id,
			'label'      => $id,
			'kind'       => 'pat',
			'kind_label' => 'Token',
			'detail'     => '',
			'source'     => 'sidecar',
			'configured' => true,
		);
		$provider = static fn ( string $code, string $label ): array => array(
			'code'                                   => $code,
			'label'                                  => $label,
			'owner_label'                            => 'Owner',
			'repository_url_base'                    => 'https://example.test/',
			'credentials_url'                        => 'admin.php?page=ran-booster&tab=' . $code . '&view=credentials',
			'available'                              => true,
			'browse'                                 => true,
			'credentialed_public_browse'             => true,
			'provider_default_public_lookup_profile' => true,
			'deploy'                                 => true,
			'webhooks'                               => true,
			'default_credential_id'                  => 'credential_1',
			'credential_profiles'                    => array( $profile( 'credential_1' ), $profile( 'credential_2' ) ),
			'public_lookup'                          => array(
				'supports_default' => true,
				'configured_id'    => 'credential_1',
				'configured_label' => 'Lookup',
				'stale'            => false,
			),
		);

		return array(
			'default_provider' => 'gh',
			'providers'        => array( $provider( 'gh', 'GitHub' ), $provider( 'bb', 'Bitbucket' ) ),
			'restore_args'     => array( 'page' => 'ran-booster' ),
		);
	}
}
