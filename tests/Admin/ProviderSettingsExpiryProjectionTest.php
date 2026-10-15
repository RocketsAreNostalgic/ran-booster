<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider;
use RAN\Tests\Support\CredentialUsageDatabase;
use RAN\Tests\Support\InMemoryCredentialExpiryObservationStore;
use RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class ProviderSettingsExpiryProjectionTest extends TestCase {

	/** @return iterable<string, array{int}> */
	public static function profile_counts(): iterable {
		yield 'empty' => array( 0 );
		yield 'single' => array( 1 );
		yield 'many' => array( 128 );
	}

	#[DataProvider( 'profile_counts' )]
	public function test_package_choices_skip_discarded_expiry_work_and_remain_fresh( int $count ): void {
		$profiles = array();
		for ( $index = 0; $index < $count; ++$index ) {
			$profiles[] = array(
				'id'            => 'saved_' . $index,
				'label'         => 'Saved ' . $index,
				'kind'          => 'api-key',
				'configuration' => array( 'tenant' => 'Example' ),
				'source'        => 0 === $index % 2 ? 'file' : 'constant',
				'configured'    => true,
			);
		}
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::exactly( 4 ) )->method( 'credential_profiles' )->willReturnCallback(
			static function () use ( &$profiles ): array {
				return $profiles;
			}
		);
		$secrets->expects( self::never() )->method( 'credential_material' );
		$observations = new InMemoryCredentialExpiryObservationStore();
		$database     = new CredentialUsageDatabase();
		$presenter    = $this->presenter( $secrets, $observations, $database );
		$choices      = $presenter->build_package_list();
		self::assertSame( 0, $observations->read_count );
		self::assertSame( array(), $database->prepared );
		foreach ( $choices as $provider ) {
			self::assertCount( $count, $provider['credential_profiles'] );
			foreach ( $provider['credential_profiles'] as $index => $profile ) {
				self::assertSame(
					array(
						'id'         => 'saved_' . $index,
						'label'      => 'Saved ' . $index,
						'kind'       => 'api-key',
						'kind_label' => 'Fixture API key',
						'detail'     => 'Tenant: Example',
						'source'     => $profiles[ $index ]['source'],
						'configured' => true,
					),
					$profile
				);
			}
		}
		$profiles = array();
		foreach ( $presenter->build_package_list() as $provider ) {
			self::assertSame( array(), $provider['credential_profiles'] );
		}
		self::assertSame( 0, $observations->read_count );
	}

	public function test_settings_keep_expiry_details_and_refresh_observations(): void {
		$secrets = $this->createStub( SecretsFile::class );
		$secrets->method( 'credential_profiles' )->willReturn(
			array(
				array(
					'id'         => 'saved',
					'kind'       => 'api-key',
					'label'      => 'Saved',
					'configured' => true,
				),
			)
		);
		$observations           = new InMemoryCredentialExpiryObservationStore();
		$observations->document = array(
			'version'  => 1,
			'profiles' => array( 'fixture' => array( 'saved' => array( 'manual_expires_on' => '2020-01-01' ) ) ),
		);
		$presenter              = $this->presenter( $secrets, $observations, new CredentialUsageDatabase() );
		$first                  = $presenter->build( 'fixture' )['credential_profiles'][0];
		self::assertSame( 2, $observations->read_count );
		self::assertSame( '2020-01-01', $first['expiry']['manual_expires_on'] );
		self::assertSame( 'expired', $first['expiry_status']['stage'] );
		$observations->document = array();
		$second                 = $presenter->build( 'fixture' )['credential_profiles'][0];
		self::assertSame( 4, $observations->read_count );
		self::assertSame( array(), $second['expiry'] );
		self::assertSame( 'unknown', $second['expiry_status']['stage'] );
	}

	private function presenter( SecretsFile $secrets, InMemoryCredentialExpiryObservationStore $observations, CredentialUsageDatabase $database ): ProviderSettingsPresenter {
		return new ProviderSettingsPresenter(
			new ProviderRegistry( array( new ExternalFixtureProvider( 'fixture' ), new ExternalFixtureProvider( 'second' ) ) ),
			$secrets,
			new CredentialUsageReader( $database, 'wp_ran_booster_packages' ),
			new InMemoryPublicRepositoryLookupProfileStore(),
			$observations
		);
	}
}
