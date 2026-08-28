<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';
require_once __DIR__ . '/Support/ReleaseTrackingFacadeDouble.php';
require_once __DIR__ . '/Support/RepositoryReleaseWorkflowProviderDouble.php';
require_once __DIR__ . '/Support/PartialRepositoryReleaseWorkflowProviderDouble.php';
require_once __DIR__ . '/GitHub/Support/PluginRepositoryDouble.php';
require_once __DIR__ . '/GitHub/Support/ThemeRepositoryDouble.php';
require_once __DIR__ . '/../../Storage/StorageTestEnvironment.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowControls;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\Storage\Database;
use RAN\Storage\RepositorySourceGuard;
use Tests\Admin\ReleaseManagement\GitHub\Support\PluginRepositoryDouble;
use Tests\Admin\ReleaseManagement\GitHub\Support\ThemeRepositoryDouble;
use Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;
use Tests\Admin\ReleaseManagement\Support\RepositoryReleaseWorkflowProviderDouble;
use Tests\Admin\ReleaseManagement\Support\PartialRepositoryReleaseWorkflowProviderDouble;

final class ReleaseWorkflowControlsTest extends TestCase {
	#[Before]
	public function resetWordPress(): void {
		ReleaseManagementFixture::resetWordPress(); }

	public function testRegistersOneNeutralPostRouteAndTheExistingReadOnlyHooks(): void {
		$controls = $this->controls();
		$controls->register();

		self::assertArrayHasKey( 'ran_booster_admin_package_source_choices', $GLOBALS['ran_booster_release_management_test_filters'] );
		self::assertArrayHasKey( 'ran_booster_provider_repository_rows', $GLOBALS['ran_booster_release_management_test_filters'] );
		self::assertArrayHasKey( 'ran_booster_admin_package_release_readiness_actions', $GLOBALS['ran_booster_release_management_test_actions'] );
		self::assertArrayHasKey( 'admin_post_ran_booster_release_workflow', $GLOBALS['ran_booster_release_management_test_actions'] );
		self::assertCount( 1, $GLOBALS['ran_booster_release_management_test_actions']['admin_post_ran_booster_release_workflow'] );
	}

	public function testNonGitHubFixtureCompletesAllFiveOperationsThroughTheSingleNeutralRoute(): void {
		foreach ( array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ) as $operation ) {
			$preview  = in_array( $operation, array( 'setup', 'update_setup' ), true ) ? str_repeat( 'a', 32 ) : '';
			$provider = $this->providerFor( $operation, $preview );
			$url      = $this->controls( provider: $provider )->processWorkflowRequest( $this->request( $operation, $preview ) );
			self::assertStringContainsString( 'ran_booster_release_workflow_result=workflow_' . $operation . '_complete', $url );
			$call = $provider->calls[ array_key_last( $provider->calls ) ];
			self::assertSame( $operation, $call['operation'] );
			self::assertSame( 'credential_1', $call['credential_id'] );
			self::assertSame( 'fixture', $provider->getMetadata()->code->value );
		}
	}

	public function testMissingAggregateAndWrongAuthorityFailClosedWithoutProviderCalls(): void {
		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$url      = $this->controls( provider: $provider, registered: false )->processWorkflowRequest( $this->request( 'inspect' ) );
		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertSame( array(), $provider->calls );

		foreach ( array(
			'expected_provider'        => 'other',
			'expected_repository_id'   => 'other',
			'expected_source_revision' => '4',
		) as $field => $value ) {
			$provider          = new RepositoryReleaseWorkflowProviderDouble();
			$request           = $this->request( 'inspect' );
			$request[ $field ] = $value;
			$url               = $this->controls( provider: $provider )->processWorkflowRequest( $request );
			self::assertStringContainsString( 'workflow_invalid_request', $url );
			self::assertSame( array(), $provider->calls );
		}
	}

	public function testPartialWorkflowDependencyIsRejectedBeforeAnyWorkflowOperation(): void {
		$provider                     = new PartialRepositoryReleaseWorkflowProviderDouble();
		$request                      = $this->request( 'inspect' );
		$request['expected_provider'] = 'partial';
		$request['_wpnonce']          = 'nonce-for-ran-booster-release-workflow-inspect-' . hash( 'sha256', (string) \RAN\Admin\ReleaseManagement\wp_json_encode( array( 'partial', '101', 'plugin', 'example/example.php', 3, '' ) ) );
		$url                          = $this->controls( provider: $provider )->processWorkflowRequest( $request );

		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertStringContainsString( 'provider_unavailable', $url );
	}

	public function testMalformedAuthorityFieldsAndExpiredNonceDoNotReachAProviderOperation(): void {
		$cases = array(
			'operation'  => array( 'workflow_operation', 'retired_operation' ),
			'type'       => array( 'expected_type', 'theme' ),
			'identifier' => array( 'expected_identifier', 'other/other.php' ),
			'preview'    => array( 'preview_key', 'not-a-preview-key' ),
		);
		foreach ( $cases as $case ) {
			$provider            = new RepositoryReleaseWorkflowProviderDouble();
			$request             = $this->request( 'inspect' );
			$request[ $case[0] ] = $case[1];
			$this->controls( provider: $provider )->processWorkflowRequest( $request );
			self::assertSame( array(), $provider->calls, $case[0] );
		}

		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$GLOBALS['ran_booster_release_management_test_nonce_age'] = 2;
		$this->controls( provider: $provider )->processWorkflowRequest( $this->request( 'inspect' ) );
		self::assertSame( array(), $provider->calls );
	}

	public function testRejectedPreviewAndPreflightDoNotInvokeAWriteOperation(): void {
		$key      = str_repeat( 'a', 32 );
		$provider = new RepositoryReleaseWorkflowProviderDouble(
			preview: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
				$key,
				'fixture',
				'101',
				'bootstrap',
				'prerelease',
				'other/repository',
				array(
					'repository'       => 'example/example',
					'default_branch'   => 'main',
					'base_sha'         => str_repeat( 'b', 40 ),
					'pack_version'     => '1.0.0',
					'template_digest'  => str_repeat( 'c', 64 ),
					'old_template_tag' => '',
					'new_template_tag' => 'v1.0.0',
				),
				array()
			)
		);
		$this->controls( provider: $provider )->processWorkflowRequest( $this->request( 'setup', $key ) );

		self::assertSame( array( 'preview' ), array_column( $provider->calls, 'operation' ) );
	}

	public function testPassiveRowsRemainUntouchedWhenTheProviderHasNoCompleteWorkflowAggregate(): void {
		$rows     = array(
			'101' => array(
				'provider_code'     => 'partial',
				'repository_id'     => '101',
				'repository'        => 'example/example',
				'package_summaries' => array(),
				'details'           => array(),
				'actions'           => array(),
			),
		);
		$controls = $this->controls( provider: new PartialRepositoryReleaseWorkflowProviderDouble() );

		self::assertSame( $rows, $controls->enrichRepositoryRows( $rows, 'partial', array(), 'https://example.test/return' ) );
	}

	public function testReleaseWorkflowRepositoryActionUsesTheCoreNamespacedActionContract(): void {
		$rows   = $this->controls()->enrichRepositoryRows(
			array(
				'101' => array(
					'provider_code'     => 'fixture',
					'repository_id'     => '101',
					'repository'        => 'example/example',
					'historical'        => false,
					'package_summaries' => array(
						array(
							'type'            => 'plugin',
							'identifier'      => 'example/example.php',
							'source'          => 'branch',
							'source_revision' => 3,
						),
					),
					'details'           => array(),
					'actions'           => array(),
				),
			),
			'fixture',
			array(),
			'https://example.test/repositories'
		);
		$action = array_values( $rows['101']['actions'] )[0];

		self::assertMatchesRegularExpression( '/\Acore:release-workflow-[a-f0-9]{16}\z/', $action['key'] );
		self::assertSame( $action['key'], $rows['101']['details'][0]['key'] );
	}

	public function testOptionalPackageHelperRendersNothingForMissingOrIncompleteWorkflowProviders(): void {
		$package = new class() {
			public function providerCode(): string {
				return 'partial'; }
			public function type(): string {
				return 'plugin'; }
			public function identifier(): string {
				return 'example/example.php'; }
			public function sourceRevision(): int {
				return 3; }
		};

		foreach ( array(
			'missing'    => $this->controls( registered: false ),
			'incomplete' => $this->controls( provider: new PartialRepositoryReleaseWorkflowProviderDouble() ),
		) as $case => $controls ) {
			ob_start();
			$controls->renderPackageReleaseAutomationLink( $package, ReleaseManagementFixture::status() );
			$html = (string) ob_get_clean();

			self::assertSame( '', $html, $case );
		}
	}

	public function testWrongTupleAndNonceRefuseBeforeProviderStatusOrWorkflowCalls(): void {
		foreach ( array(
			'provider'   => array( 'expected_provider', 'other' ),
			'repository' => array( 'expected_repository_id', 'other' ),
			'revision'   => array( 'expected_source_revision', '4' ),
			'nonce'      => array( '_wpnonce', 'wrong' ),
		) as $case => $change ) {
			$provider              = new RepositoryReleaseWorkflowProviderDouble();
			$request               = $this->request( 'inspect' );
			$request[ $change[0] ] = $change[1];
			$this->controls( provider: $provider )->processWorkflowRequest( $request );

			self::assertSame( array(), $provider->calls, $case );
			self::assertSame( 0, $provider->statusReads, $case );
		}
	}

	public function testWrongCredentialAndPreviewTupleRefuseBeforeAnyWorkflowRemoteOperation(): void {
		$key                              = str_repeat( 'a', 32 );
		$provider                         = new RepositoryReleaseWorkflowProviderDouble();
		$request                          = $this->request( 'setup', $key );
		$request['booster_credential_id'] = 'not-a-saved-credential';
		$url                              = $this->controls( provider: $provider )->processWorkflowRequest( $request );

		self::assertStringContainsString( 'workflow_unauthorised', $url );
		self::assertSame( 1, $provider->statusReads );
		self::assertSame( array(), $provider->calls );

		$provider = new RepositoryReleaseWorkflowProviderDouble(
			preview: new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
				$key,
				'other',
				'101',
				'bootstrap',
				'prerelease',
				'example/example',
				array(
					'repository'       => 'example/example',
					'default_branch'   => 'main',
					'base_sha'         => str_repeat( 'b', 40 ),
					'pack_version'     => '1.0.0',
					'template_digest'  => str_repeat( 'c', 64 ),
					'old_template_tag' => '',
					'new_template_tag' => 'v1.0.0',
				),
				array()
			)
		);
		$this->controls( provider: $provider )->processWorkflowRequest( $this->request( 'setup', $key ) );

		self::assertSame( array( 'preview' ), array_column( $provider->calls, 'operation' ) );
	}

	public function testAnonymousPublicInspectionPassesNullCredentialAndPermissionsAndNonceDoNotReachProvider(): void {
		$provider                         = new RepositoryReleaseWorkflowProviderDouble();
		$request                          = $this->request( 'inspect' );
		$request['booster_credential_id'] = '';
		$this->controls( provider: $provider )->processWorkflowRequest( $request );
		self::assertSame( null, $provider->calls[0]['credential_id'] );

		ReleaseManagementFixture::resetWordPress();
		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'manage_options' );
		$this->controls( provider: $provider )->processWorkflowRequest( $this->request( 'inspect' ) );
		self::assertSame( array(), $provider->calls );

		ReleaseManagementFixture::resetWordPress();
		$request             = $this->request( 'inspect' );
		$request['_wpnonce'] = 'wrong';
		$this->controls( provider: $provider )->processWorkflowRequest( $request );
		self::assertSame( array(), $provider->calls );
	}

	public function testSetupUsesThePreviewChannelForCorePreflight(): void {
		$key                                        = str_repeat( 'a', 32 );
		$preview                                    = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
			$key,
			'fixture',
			'101',
			'bootstrap',
			'prerelease',
			'example/example',
			array(
				'repository'       => 'example/example',
				'default_branch'   => 'main',
				'base_sha'         => str_repeat( 'b', 40 ),
				'pack_version'     => '1.0.0',
				'template_digest'  => str_repeat( 'c', 64 ),
				'old_template_tag' => '',
				'new_template_tag' => 'v1.0.0',
			),
			array()
		);
		$provider                                   = new RepositoryReleaseWorkflowProviderDouble( preview: $preview );
		$tracking                                   = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() );
		$request                                    = $this->request( 'setup', $key );
		$request['core_preflight_nonce_prerelease'] = 'preflight-prerelease';
		$this->controls( tracking: $tracking, provider: $provider )->processWorkflowRequest( $request );

		self::assertSame( array( 'assessment_preflight', 'plugin', 'example/example.php', 3, 'prerelease', 'preflight-prerelease' ), $tracking->calls[0] );
		self::assertSame( 'setup', $provider->calls[1]['operation'] );
	}

	private function controls( ?ReleaseTrackingFacadeDouble $tracking = null, ?RepositoryProvider $provider = null, bool $registered = true ): ReleaseWorkflowControls {
		$provider ??= new RepositoryReleaseWorkflowProviderDouble();
		return new ReleaseWorkflowControls( $tracking ?? new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ), new PluginRepositoryDouble( providerCode: $provider->getMetadata()->code->value ), new ThemeRepositoryDouble(), new ProviderRegistry( $registered ? array( $provider ) : array() ), $this->sourceGuard() );
	}

	private function providerFor( string $operation, string $previewKey ): RepositoryReleaseWorkflowProviderDouble {
		$record  = in_array( $operation, array( 'outcome', 'update_inspect', 'update_setup' ), true )
			? new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
				'fixture',
				'101',
				true,
				true,
				'https://fixture.example/pull/1',
				'plugin',
				'example/example.php',
				3,
				credentialChoices: array(
					array(
						'id'    => 'credential_1',
						'label' => 'Fixture credential',
					),
				)
			) : null;
		$preview = '' !== $previewKey
			? new \RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview(
				$previewKey,
				'fixture',
				'101',
				'setup' === $operation ? 'bootstrap' : 'template_update',
				'setup' === $operation ? 'prerelease' : '',
				'example/example',
				array(
					'repository'       => 'example/example',
					'default_branch'   => 'main',
					'base_sha'         => str_repeat( 'b', 40 ),
					'pack_version'     => '1.0.0',
					'template_digest'  => str_repeat( 'c', 64 ),
					'old_template_tag' => 'setup' === $operation ? '' : 'v0.9.0',
					'new_template_tag' => 'v1.0.0',
				),
				array()
			) : null;
		return new RepositoryReleaseWorkflowProviderDouble( preview: $preview, status: $record );
	}

	/** @return array<string,string> */
	private function request( string $operation, string $preview = '' ): array {
		$request = array(
			'workflow_operation'       => $operation,
			'expected_provider'        => 'fixture',
			'expected_repository_id'   => '101',
			'expected_type'            => 'plugin',
			'expected_identifier'      => 'example/example.php',
			'expected_source_revision' => '3',
			'booster_credential_id'    => 'credential_1',
			'confirm_repository'       => 'example/example',
			'preview_key'              => $preview,
		);
		if ( 'inspect' === $operation ) {
			$request['release_channel']             = 'stable';
			$request['core_preflight_nonce_stable'] = 'preflight-stable'; }
		if ( 'setup' === $operation ) {
			$request['core_preflight_nonce_prerelease'] = 'preflight-prerelease'; }
		$request['_wpnonce'] = 'nonce-for-ran-booster-release-workflow-' . $operation . '-' . hash( 'sha256', (string) \RAN\Admin\ReleaseManagement\wp_json_encode( array( 'fixture', '101', 'plugin', 'example/example.php', 3, $preview ) ) );
		return $request;
	}

	private function sourceGuard(): RepositorySourceGuard {
		$database  = new class() { public string $last_error = '';
			public function prepare( string $query, mixed ...$arguments ): string {
				return $query;
			} public function get_results( string $query ): array {
				return array(
					(object) array(
						'type'                   => 1,
						'package'                => 'example/example.php',
						'source'                 => 'branch',
						'provider'               => 'fixture',
						'provider_repository_id' => '101',
					),
				);
			} };
		$lifecycle = new class() extends Database { public function requireReady(): void {} };
		return new RepositorySourceGuard( $database, $lifecycle );
	}
}
