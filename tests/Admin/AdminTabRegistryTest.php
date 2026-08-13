<?php

declare(strict_types=1);

namespace Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\AdminTab;
use RAN\Admin\AdminTabKind;
use RAN\Admin\AdminTabRegistry;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;

final class AdminTabRegistryTest extends TestCase {

	public function testRegistryCombinesProviderMetadataWithFixedPageDefinitions(): void {
		$registry = new AdminTabRegistry(
			new ProviderRegistry(
				array(
					$this->provider( ProviderCode::parse( 'gh' ), 'GitHub' ),
					$this->provider( ProviderCode::parse( 'bb' ), 'Bitbucket' ),
				)
			)
		);

		self::assertSame(
			array( 'overview', 'gh', 'bb', 'portability', 'documentation', 'troubleshooting' ),
			array_map( static fn ( AdminTab $tab ): string => $tab->getKey(), $registry->all() )
		);
		self::assertSame(
			array( 'Overview', 'GitHub', 'Bitbucket', 'Transporter', 'Documentation', 'Troubleshooting' ),
			array_map( static fn ( AdminTab $tab ): string => $tab->getLabel(), $registry->all() )
		);
		self::assertSame( 'overview', $registry->getDefault()->getKey() );
		self::assertSame( 'onboarding.php', $registry->resolve( 'overview' )->getView() );
		self::assertSame( AdminTabKind::PROVIDER, $registry->resolve( 'bb' )->getKind() );
		self::assertTrue( $registry->resolve( 'bb' )->getProvider()->equals( 'bb' ) );
		self::assertSame( 'documentation.php', $registry->resolve( 'documentation' )->getView() );
		self::assertSame( 'portability.php', $registry->resolve( 'portability' )->getView() );
		self::assertSame( AdminTabKind::PAGE, $registry->resolve( 'documentation' )->getKind() );
	}

	public function testProviderTabsUseDeterministicHostOrderInsteadOfRegistrationOrder(): void {
		$registry = new AdminTabRegistry(
			new ProviderRegistry(
				array(
					$this->provider( ProviderCode::parse( 'fixture' ), 'Fixture' ),
					$this->provider( ProviderCode::parse( 'bb' ), 'Bitbucket' ),
					$this->provider( ProviderCode::parse( 'gh' ), 'GitHub' ),
				)
			)
		);

		self::assertSame(
			array( 'overview', 'gh', 'bb', 'fixture', 'portability', 'documentation', 'troubleshooting' ),
			array_map( static fn ( AdminTab $tab ): string => $tab->getKey(), $registry->all() )
		);
	}

	/** @return list<array{mixed}> */
	public static function invalidRequestedTabProvider(): array {
		return array(
			array( null ),
			array( '' ),
			array( 'unknown' ),
			array( array( 'gh' ) ),
			array( 123 ),
		);
	}

	#[DataProvider( 'invalidRequestedTabProvider' )]
	public function testInvalidRequestedTabsUseTheShippedDefaultWithoutFilenameDerivation( mixed $requested ): void {
		$registry = new AdminTabRegistry(
			new ProviderRegistry( array( $this->provider( ProviderCode::parse( 'gh' ), 'GitHub' ) ) )
		);

		$resolved = $registry->resolve( $requested );

		self::assertSame( 'overview', $resolved->getKey() );
		self::assertSame( 'onboarding.php', $resolved->getView() );
	}

	public function testMetadataOnlyProvidersDoNotBecomeSettingsTabs(): void {
		$metadataOnly = new class() implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'Metadata only', 'https://example.test/', 'Owner' );
			}
		};
		$registry     = new AdminTabRegistry( new ProviderRegistry( array( $metadataOnly ) ) );

		self::assertSame(
			array( 'overview', 'portability', 'documentation', 'troubleshooting' ),
			array_map( static fn ( AdminTab $tab ): string => $tab->getKey(), $registry->all() )
		);
		self::assertSame( 'overview', $registry->getDefault()->getKey() );
	}

	public function testPageDefinitionsRejectViewsOutsideTheAllowlist(): void {
		$this->expectException( InvalidArgumentException::class );

		AdminTab::page( 'unsafe', 'Unsafe', '../../unsafe.php' );
	}

	public function testDeletedLogViewCannotBeRegistered(): void {
		$this->expectException( InvalidArgumentException::class );

		AdminTab::page( 'log', 'Log', 'log.php' );
	}

	private function provider( ProviderCode $code, string $label ): RepositoryProvider {
		return new class( $code, $label ) implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function __construct(
				private ProviderCode $code,
				private string $label
			) {
			}

			public function getMetadata(): ProviderMetadata {
				return new ProviderMetadata(
					$this->code,
					$this->label,
					'https://example.test/',
					'Owner',
					new ProviderAdminMetadata( array(), array() )
				);
			}
		};
	}
}
