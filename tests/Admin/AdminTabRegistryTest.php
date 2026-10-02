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
use RAN\RepositoryProvider\Admin\ProviderNavigationPlacement;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;

final class AdminTabRegistryTest extends TestCase {

	public function test_registry_combines_provider_metadata_with_fixed_page_definitions(): void {
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
			array_map( static fn ( AdminTab $tab ): string => $tab->get_key(), $registry->all() )
		);
		self::assertSame(
			array( 'Overview', 'GitHub', 'Bitbucket', 'Transporter', 'Documentation', 'Troubleshooting' ),
			array_map( static fn ( AdminTab $tab ): string => $tab->get_label(), $registry->all() )
		);
		self::assertSame( 'overview', $registry->get_default()->get_key() );
		self::assertSame( 'onboarding.php', $registry->resolve( 'overview' )->get_view() );
		self::assertSame( AdminTabKind::PROVIDER, $registry->resolve( 'bb' )->get_kind() );
		self::assertTrue( $registry->resolve( 'bb' )->get_provider()->equals( 'bb' ) );
		self::assertSame( 'documentation.php', $registry->resolve( 'documentation' )->get_view() );
		self::assertSame( 'portability.php', $registry->resolve( 'portability' )->get_view() );
		self::assertSame( AdminTabKind::PAGE, $registry->resolve( 'documentation' )->get_kind() );
	}

	public function test_provider_tabs_use_deterministic_host_order_instead_of_registration_order(): void {
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
			array_map( static fn ( AdminTab $tab ): string => $tab->get_key(), $registry->all() )
		);
	}

	/** @return list<array{mixed}> */
	public static function invalid_requested_tab_provider(): array {
		return array(
			array( null ),
			array( '' ),
			array( 'unknown' ),
			array( array( 'gh' ) ),
			array( 123 ),
		);
	}

	#[DataProvider( 'invalid_requested_tab_provider' )]
	public function test_invalid_requested_tabs_use_the_shipped_default_without_filename_derivation( mixed $requested ): void {
		$registry = new AdminTabRegistry(
			new ProviderRegistry( array( $this->provider( ProviderCode::parse( 'gh' ), 'GitHub' ) ) )
		);

		$resolved = $registry->resolve( $requested );

		self::assertSame( 'overview', $resolved->get_key() );
		self::assertSame( 'onboarding.php', $resolved->get_view() );
	}

	public function test_metadata_only_providers_do_not_become_settings_tabs(): void {
		$metadata_only = new class() implements RepositoryProvider {

			use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata( ProviderCode::parse( 'gh' ), 'Metadata only', 'https://example.test/', 'Owner' );
			}
		};
		$registry     = new AdminTabRegistry( new ProviderRegistry( array( $metadata_only ) ) );

		self::assertSame(
			array( 'overview', 'portability', 'documentation', 'troubleshooting' ),
			array_map( static fn ( AdminTab $tab ): string => $tab->get_key(), $registry->all() )
		);
		self::assertSame( 'overview', $registry->get_default()->get_key() );
	}

	public function test_page_definitions_reject_views_outside_the_allowlist(): void {
		$this->expectException( InvalidArgumentException::class );

		AdminTab::page( 'unsafe', 'Unsafe', '../../unsafe.php' );
	}

	public function test_deleted_log_view_cannot_be_registered(): void {
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

			public function get_metadata(): ProviderMetadata {
				return new ProviderMetadata(
					$this->code,
					$this->label,
					'https://example.test/',
					'Owner',
					new ProviderAdminMetadata(
						array(),
						array(),
						navigation: new ProviderNavigationPlacement(
							'fixture' === $this->code->value ? ProviderNavigationPlacement::OTHER_PROVIDER : ProviderNavigationPlacement::GIT_HOST,
							match ( $this->code->value ) {
								'gh' => 100,
								'bb' => 200,
								default => 300,
							}
						)
					)
				);
			}
		};
	}
}
