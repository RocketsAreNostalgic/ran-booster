<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SetupRecordStore;

final class SetupRecordStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_release_deployments_test_options']        = array();
		$GLOBALS['ran_booster_release_deployments_test_option_updates'] = array();
		unset( $GLOBALS['ran_booster_release_deployments_test_option_override'] );
	}
	public function testSchemaTwoIsExactBoundedAndNonAutoloaded(): void {
		$store  = new SetupRecordStore();
		$record = $this->record();
		self::assertTrue( $store->save( $record ) );
		self::assertSame( $record, $store->find( '123456789' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact ordered scalar-array bytes are the compatibility subject under test.
		self::assertSame( hash( 'sha256', serialize( $record ) ), hash( 'sha256', serialize( $store->find( '123456789' ) ) ) );
		self::assertFalse( $GLOBALS['ran_booster_release_deployments_test_option_updates'][0][2] );
		self::assertFalse( $store->save( $record + array( 'github_token' => 'secret' ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'consumer_api' => 1 ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'setup_branch' => 'ran-booster/release-setup-v1-old' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_repo_name' => 'attacker/templates' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_repo_id' => '1' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_asset_name' => 'other.zip' ) ) ) );
		self::assertFalse( $store->save( array_replace( $record, array( 'template_asset_size' => 2097153 ) ) ) );
	}
	public function testExistingUnknownRowsOccupyTheirKeyWithoutByteChanges(): void {
		foreach ( array(
			'legacy'    => array(
				'repo_id'        => '123456789',
				'schema_version' => 1,
			),
			'future'    => array(
				'schema_version' => 3,
				'opaque'         => "future\0bytes",
			),
			'non_array' => 'opaque-row',
			null        => null,
		) as $name => $existing ) {
			$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] = array( '123456789' => $existing );
			$GLOBALS['ran_booster_release_deployments_test_option_updates'] = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			$before = serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] );
			$store  = new SetupRecordStore();

			self::assertTrue( $store->occupied( '123456789' ), $name );
			self::assertFalse( $store->save( $this->record() ), $name );
			self::assertSame( array(), $GLOBALS['ran_booster_release_deployments_test_option_updates'], $name );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exact raw scalar value bytes are the compatibility subject under test.
			self::assertSame( $before, serialize( $GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] ), $name );
		}
	}
	public function testSchemaOneIsDisplayOnlyAndNeverCurrentAuthority(): void {
		$legacy                 = array_intersect_key( $this->record(), array_flip( array( 'repo_id', 'repository', 'package_type', 'package_identifier', 'source_revision', 'default_branch', 'setup_branch', 'head_sha', 'pr_number' ) ) );
		$legacy['setup_branch'] = 'ran-booster/release-setup-v1-aaaaaaaaaaaa-deadbeef';
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records']['123456789'] = $legacy;
		$store = new SetupRecordStore();
		self::assertNull( $store->find( '123456789' ) );
		self::assertSame(
			array(
				'schema_version' => 1,
				'repository'     => $legacy['repository'],
				'setup_branch'   => $legacy['setup_branch'],
				'pr_number'      => 42,
			),
			$store->legacyEvidence( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 )
		);
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records']['123456789']['token'] = 'secret';
		$unsupported = array(
			'schema_version' => 1,
			'unsupported'    => 1,
		);
		self::assertSame( $unsupported, $store->legacyEvidence( '123456789', 'plugin', 'example-plugin/example-plugin.php', 3 ) );
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records']['123456789'] = $legacy;
		self::assertSame( $unsupported, $store->legacyEvidence( '123456789', 'theme', 'example-plugin/example-plugin.php', 3 ) );
		self::assertSame( $unsupported, $store->legacyEvidence( '123456789', 'plugin', 'other/example.php', 3 ) );
		self::assertSame( $unsupported, $store->legacyEvidence( '123456789', 'plugin', 'example-plugin/example-plugin.php', 4 ) );
	}
	public function testReadbackAndRecordCapFailClosed(): void {
		$GLOBALS['ran_booster_release_deployments_test_option_override'] = array();
		self::assertFalse( ( new SetupRecordStore() )->save( $this->record() ) );
		unset( $GLOBALS['ran_booster_release_deployments_test_option_override'] );
		$records = array();
		for ( $index = 1; $index <= 100; ++$index ) {
			$record                     = array_replace( $this->record(), array( 'repo_id' => (string) $index ) );
			$records[ (string) $index ] = $record;
		}
		$GLOBALS['ran_booster_release_deployments_test_options']['ran_booster_release_deployments_setup_records'] = $records;
		self::assertTrue( ( new SetupRecordStore() )->occupied( '100' ) );
		self::assertFalse( ( new SetupRecordStore() )->save( array_replace( $this->record(), array( 'repo_id' => '101' ) ) ) );
		self::assertTrue( ( new SetupRecordStore() )->save( array_replace( $this->record(), array( 'repo_id' => '100' ) ) ) );
	}
	/** @return array<string,int|string> */
	private function record(): array {
		return array(
			'schema_version'        => 2,
			'operation'             => 'bootstrap',
			'repo_id'               => '123456789',
			'repository'            => 'RocketsAreNostalgic/example-plugin',
			'package_type'          => 'plugin',
			'package_identifier'    => 'example-plugin/example-plugin.php',
			'source_revision'       => 3,
			'default_branch'        => 'main',
			'base_sha'              => str_repeat( 'a', 40 ),
			'setup_branch'          => 'ran-booster/release-setup-v2-aaaaaaaaaaaa-deadbeef',
			'head_sha'              => str_repeat( 'b', 40 ),
			'pr_number'             => 42,
			'profile_id'            => 'source-ready-wordpress-plugin/2',
			'template_repo_name'    => 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates',
			'template_repo_id'      => '1322743261',
			'template_release_id'   => 41,
			'template_tag'          => 'v1.2.3',
			'template_commit'       => str_repeat( 'c', 40 ),
			'template_asset_id'     => 73,
			'template_asset_name'   => 'ran-booster-release-bootstrap-templates.zip',
			'template_asset_size'   => 1000,
			'template_asset_digest' => str_repeat( 'd', 64 ),
			'manifest_digest'       => str_repeat( 'e', 64 ),
			'receipt_digest'        => str_repeat( 'f', 64 ),
			'consumer_api'          => 2,
			'pack_version'          => '1.2.3',
			'bundle_hash'           => str_repeat( '1', 64 ),
			'changed_path_hash'     => str_repeat( '2', 64 ),
		);
	}
}
