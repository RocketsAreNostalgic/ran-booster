<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once __DIR__ . '/../Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/../Support/InMemoryPublicRepositoryLookupProfileStore.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Activity\ActivityProgress;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\ManagedRepository;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderFailure;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\ProviderRequestFailed;
use RAN\RepositoryProvider\RepositoryProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class PackageBranchCheckFailureTest extends TestCase {

	#[DataProvider( 'provider_failures' )]
	public function test_branch_check_preserves_only_typed_location_distinctions( string $provider_code, string $reason, string $expected ): void {
		$provider = $this->provider( $provider_code );
		$failure  = 'legacy' === $reason ? new \RuntimeException( 'ref_not_found redirect_blocked canary', 404 ) : new ProviderRequestFailed( new ProviderFailure( $reason ) );
		$provider->method( 'prepare_archive' )->willThrowException( $failure );
		self::assertSame( $expected, $this->check( $provider, $provider_code ) );
	}

	/** @return iterable<string,array{string,string,string}> */
	public static function provider_failures(): iterable {
		foreach ( array( 'gh', 'bb' ) as $provider ) {
			foreach ( array(
				'ref_not_found'                     => 'branch_unavailable',
				'redirect_blocked'                  => 'branch_redirected',
				'repository_not_found_or_no_access' => 'unable_to_check',
				'credential_rejected'               => 'unable_to_check',
				'rate_limited'                      => 'unable_to_check',
				'http_timeout'                      => 'unable_to_check',
				'legacy'                            => 'unable_to_check',
			) as $reason => $outcome ) {
				yield $provider . ' ' . $reason => array( $provider, $reason, $outcome );
			}
		}
	}

	public function test_read_failure_is_typed_and_preparation_is_always_cleaned_without_acquiring_an_archive(): void {
		$archive = $this->createMock( PreparedArchive::class );
		$archive->expects( self::never() )->method( 'get_url' );
		$archive->method( 'get_resolved_ref' )->willThrowException( new ProviderRequestFailed( new ProviderFailure( 'ref_not_found' ) ) );
		$archive->expects( self::once() )->method( 'cleanup' );
		$provider = $this->provider( 'bb' );
		$provider->method( 'prepare_archive' )->willReturn( $archive );
		self::assertSame( 'branch_unavailable', $this->check( $provider, 'bb' ) );
	}

	public function test_the_default_lookup_profile_checks_the_branch_only_with_secret_authority(): void {
		$profiles           = new \RAN\Tests\Support\InMemoryPublicRepositoryLookupProfileStore();
		$profiles->profiles = array( 'gh' => 'public-profile' );
		$presenter          = ( new ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( ProviderSettingsPresenter::class, 'public_lookup_profiles' ) )->setValue( $presenter, $profiles );
		$profile = new ReflectionMethod( ProviderSettingsPresenter::class, 'effective_branch_check_profile' );
		$package = static function ( bool $is_private ): AbstractPackage {
			$package = new class() extends AbstractPackage {
				public function get_identifier(): mixed {
					return 'example/example.php';
				}

				protected function runtime_slug(): string {
					return 'example';
				}
			};
			$package->set_repository( new ManagedRepository( 'gh', 'owner/repository', 'repo-42', 'main', $is_private, $is_private ? 'own-profile' : null ) );
			return $package;
		};
		try {
			foreach ( array(
				true  => array( 'public-profile', 'own-profile' ),
				false => array( null, 'own-profile' ),
			) as $authority => $expected ) {
				$GLOBALS['ran_booster_test_capabilities'] = array(
					'manage_options'   => true,
					'activate_plugins' => (bool) $authority,
				);

				self::assertSame( $expected, array( $profile->invoke( $presenter, $package( false ) ), $profile->invoke( $presenter, $package( true ) ) ) );
			}
		} finally {
			unset( $GLOBALS['ran_booster_test_capabilities'], $GLOBALS['ran_booster_test_capability_checks'] );
		}
	}

	/** @return RepositoryProvider&\PHPUnit\Framework\MockObject\MockObject */
	private function provider( string $code ): RepositoryProvider {
		$provider = $this->createMock( RepositoryProvider::class );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( $code ), 'Fixture', 'https://example.test/', 'Owner' ) );
		$provider->method( 'get_provider_diagnostics' )->willReturn( $this->createStub( ProviderDiagnostics::class ) );
		return $provider;
	}

	private function check( RepositoryProvider $provider, string $code ): string {
		$presenter = ( new ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor();
		( new ReflectionProperty( ProviderSettingsPresenter::class, 'providers' ) )->setValue( $presenter, new ProviderRegistry( array( $provider ) ) );
		$package = $this->createStub( AbstractPackage::class );
		$package->method( 'get_provider_code' )->willReturn( $code );
		$package->method( 'get_repository' )->willReturn( new ManagedRepository( $code, 'owner/repository', 'repo-42', 'feature/ref', true, 'fixture-profile' ) );
		$package->method( 'get_branch' )->willReturn( 'feature/ref' );
		return (string) ( new ReflectionMethod( ProviderSettingsPresenter::class, 'check_package_repository_branch_while_locked' ) )->invoke( $presenter, $package, null, $this->createStub( ActivityProgress::class ) );
	}
}
