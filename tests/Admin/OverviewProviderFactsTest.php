<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\RepositoryProvider\GitHubProviderSelection;
use RAN\RepositoryProvider\GitHubReplacementDeclaration;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Secrets\SecretsFile;
use RAN\Storage\CredentialUsageReader;
use RAN\Storage\GitHubSelectionStore;
use RAN\Tests\RepositoryProvider\SelectionContextDatabase;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once __DIR__ . '/../Deployment/AttemptRepositoryDatabase.php';

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

	public function test_passive_facts_report_admission_and_real_selection_without_reading_secrets(): void {
		$database               = new SelectionContextDatabase();
		$database->active_bytes = 'a:1:{i:0;s:9:"index.php";}';
		$declaration            = new GitHubReplacementDeclaration(
			array(
				'plugin_basename'        => 'index.php',
				'implementation_id'      => 'overview-test',
				'compatibility_revision' => '1',
				'provider_api_version'   => 15,
				'api_credential_formats' => array(),
				'webhook_signing_format' => null,
				'facets'                 => array(),
			)
		);
		$store                  = new GitHubSelectionStore( $database, 'wp_options' );
		$store->initialize_bundled( $declaration );
		$selection = new GitHubProviderSelection( $store, dirname( __DIR__ ) . '/WordPress/fixtures/ran-booster-managed-active', $declaration );
		$registry  = new ProviderRegistry( array( new ExternalFixtureProvider( 'gh' ), new ExternalFixtureProvider( 'bb' ) ) );
		$selection->bind_registry( $registry );
		$selection->complete_collection();
		$registry->seal();
		$secrets = $this->createMock( SecretsFile::class );
		$secrets->expects( self::never() )->method( 'with_github_adoption_inventory' )->willThrowException( new \RuntimeException( 'Unexpected secrets access.' ) );
		$secrets->expects( self::never() )->method( 'credentials_for' )->willThrowException( new \RuntimeException( 'Unexpected secrets access.' ) );
		$secrets->expects( self::never() )->method( 'credential_profiles' )->willThrowException( new \RuntimeException( 'Unexpected secrets access.' ) );
		$secrets->expects( self::never() )->method( 'credential_material' )->willThrowException( new \RuntimeException( 'Unexpected secrets access.' ) );
		$presenter = new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader(), github_selection: $selection );
		self::assertSame(
			array(
				'admitted'             => true,
				'selection'            => 'available',
				'source_kind'          => 'bundled',
				'source_name'          => null,
				'replacement_detected' => false,
			),
			$presenter->overview_provider_facts()['gh']
		);
		self::assertSame(
			array(
				'admitted'    => true,
				'selection'   => null,
				'source_kind' => null,
				'source_name' => null,
			),
			$presenter->overview_provider_facts()['bb']
		);
		$database->active_bytes = 'a:3:{i:0;s:9:"index.php";i:1;s:11:"missing.php";i:2;s:12:"missing2.php";}';
		self::assertSame( 'conflict', $presenter->overview_provider_facts()['gh']['selection'] );
		self::assertNull( $presenter->overview_provider_facts()['gh']['source_kind'] );
		$database->active_bytes   = 'a:1:{i:0;s:9:"index.php";}';
		$state                    = $store->read();
		$state['native_mutation'] = array(
			'selection_generation' => 0,
			'owner_tag'            => str_repeat( 'a', 32 ),
			'target_type'          => 'plugin',
			'installed_identity'   => 'target/main.php',
			'phase'                => 'started',
		);
		self::assertTrue( $store->compare_and_swap( $store->read(), $state ) );
		self::assertSame( 'recovery_required', $presenter->overview_provider_facts()['gh']['selection'] );
		unset( $state['native_mutation'] );
		self::assertTrue( $store->compare_and_swap( $store->read(), $state ) );
		$database->active_bytes = 'a:0:{}';
		self::assertSame( 'missing', $presenter->overview_provider_facts()['gh']['selection'] );
		self::assertNull( $presenter->overview_provider_facts()['gh']['source_kind'] );
		$database->fail_active = true;
		self::assertSame( 'storage_unavailable', $presenter->overview_provider_facts()['gh']['selection'] );
		$unadmitted = new ProviderSettingsPresenter( new ProviderRegistry(), $secrets, new CredentialUsageReader(), github_selection: $selection );
		self::assertSame(
			array(
				'admitted'             => false,
				'selection'            => 'storage_unavailable',
				'source_kind'          => null,
				'source_name'          => null,
				'replacement_detected' => false,
			),
			$unadmitted->overview_provider_facts()['gh']
		);
		$no_selection = new ProviderSettingsPresenter( new ProviderRegistry(), $secrets, new CredentialUsageReader() );
		self::assertSame( array(), $no_selection->overview_provider_facts() );
		$no_selection_admitted = new ProviderSettingsPresenter( $registry, $secrets, new CredentialUsageReader() );
		self::assertSame(
			array(
				'admitted'    => true,
				'selection'   => null,
				'source_kind' => null,
				'source_name' => null,
			),
			$no_selection_admitted->overview_provider_facts()['gh']
		);
	}
}
