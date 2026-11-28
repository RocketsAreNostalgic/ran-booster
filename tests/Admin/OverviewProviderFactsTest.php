<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\GitHubSupplierNotice;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use RAN\Tests\Admin\Support\GitHubSupplierState;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class OverviewProviderFactsTest extends TestCase {
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_extension_origin_requires_matching_active_registered_implementation(): void {
		require_once dirname( __DIR__ ) . '/Troubleshooting/OfficialBitbucketProviderFixture.php';
		$root = sys_get_temp_dir() . '/ran-overview-provider-' . bin2hex( random_bytes( 6 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated plugin fixture.
		mkdir( $root . '/ran-booster-bitbucket', 0700, true );
		$file = $root . '/ran-booster-bitbucket/ran-booster-bitbucket.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated catalogue file fixture.
		file_put_contents( $file, '<?php' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Emulate the WordPress-owned path in a separate process.
		define( 'WP_PLUGIN_DIR', $root );
		try {
			$secrets                                    = $this->createMock( SecretsFile::class );
			$registry                                   = new ProviderRegistry( array( new \RAN\Booster\Bitbucket\BitbucketProvider() ) );
			$presenter                                  = new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader() );
			$GLOBALS['ran_booster_bulk_active_plugins'] = array();
			self::assertNull( $presenter->overview_provider_facts()['bb']['source_name'] );
			$GLOBALS['ran_booster_bulk_active_plugins'] = array( 'ran-booster-bitbucket/ran-booster-bitbucket.php' );
			self::assertSame( 'Bitbucket Cloud', $presenter->overview_provider_facts()['bb']['source_name'] );
			$wrong = new ProviderSettingsPresenter( new ProviderRegistry( array( new ExternalFixtureProvider( 'bb' ) ) ), $secrets, new CredentialUsageReader() );
			self::assertNull( $wrong->overview_provider_facts()['bb']['source_name'] );
		} finally {
			unset( $GLOBALS['ran_booster_bulk_active_plugins'] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated plugin fixture cleanup.
			unlink( $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated plugin fixture cleanup.
			rmdir( dirname( $file ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated plugin fixture cleanup.
			rmdir( $root );
		}
	}

	public function test_passive_facts_report_admission_and_the_github_supplier_without_reading_secrets(): void {
		$secrets = $this->createMock( SecretsFile::class );
		foreach ( array( 'credentials_for', 'credential_profiles', 'credential_material' ) as $method ) {
			$secrets->expects( self::never() )->method( $method );
		}
		$facts = static fn ( ProviderRegistry $registry, bool $notice = true ): array => ( new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader(), github_supplier: $notice ? new GitHubSupplierNotice( $registry ) : null ) )->overview_provider_facts();
		$both  = static fn (): ProviderRegistry => new ProviderRegistry( array( new ExternalFixtureProvider( 'gh' ), new ExternalFixtureProvider( 'bb' ) ) );

		$bundled = $facts( GitHubSupplierState::apply( $both(), ProviderRegistry::GITHUB_BUNDLED ) );
		self::assertSame(
			array(
				'admitted'    => true,
				'message'     => null,
				'source_kind' => 'bundled',
				'source_name' => null,
			),
			$bundled['gh']
		);
		self::assertSame(
			array(
				'admitted'    => true,
				'message'     => null,
				'source_kind' => null,
				'source_name' => null,
			),
			$bundled['bb']
		);
		self::assertSame(
			array(
				'admitted'    => true,
				'message'     => null,
				'source_kind' => 'replacement',
				'source_name' => 'supplier-fixture-a',
			),
			$facts( GitHubSupplierState::apply( $both(), 'supplier-fixture-a', array( 'supplier-fixture-a' ) ) )['gh']
		);
		$bb_only = static fn (): ProviderRegistry => new ProviderRegistry( array( new ExternalFixtureProvider( 'bb' ) ) );
		self::assertSame(
			array(
				'admitted'    => false,
				'message'     => 'GitHub is unavailable because more than one plugin provides it: supplier-fixture-a, supplier-fixture-b.',
				'source_kind' => null,
				'source_name' => null,
			),
			$facts( GitHubSupplierState::apply( $bb_only(), ProviderRegistry::GITHUB_SUPERSEDE_CONFLICT, array( 'supplier-fixture-a', 'supplier-fixture-b' ) ) )['gh']
		);
		// Without the notice service the reason is still stated, generically.
		self::assertSame( 'GitHub could not load.', $facts( GitHubSupplierState::apply( $bb_only(), ProviderRegistry::GITHUB_SUPERSEDE_FAILED ), false )['gh']['message'] );
		// A registry that does not own GitHub reports only what is registered.
		self::assertSame( array( 'bb' ), array_keys( $facts( $bb_only() ) ) );
		self::assertNull( $facts( $both() )['gh']['source_kind'] );
	}
}
