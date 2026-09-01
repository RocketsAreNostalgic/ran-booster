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
use RAN\Admin\ReleaseManagement\ReleaseWorkflowRequestController;
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

final class ReleaseWorkflowRequestControllerTest extends TestCase {
	#[Before]
	public function resetWordPress(): void {
		ReleaseManagementFixture::resetWordPress(); }

	public function testNonGitHubFixtureCompletesAllFiveOperationsThroughTheSingleNeutralRoute(): void {
		foreach ( array( 'inspect', 'setup', 'outcome', 'update_inspect', 'update_setup' ) as $operation ) {
			$preview  = in_array( $operation, array( 'setup', 'update_setup' ), true ) ? str_repeat( 'a', 32 ) : '';
			$provider = $this->providerFor( $operation, $preview );
			$url      = $this->controller( provider: $provider )->processWorkflowRequest( $this->request( $operation, $preview ) );
			self::assertStringContainsString( 'ran_booster_release_workflow_result=workflow_' . $operation . '_complete', $url );
			$call = $provider->calls[ array_key_last( $provider->calls ) ];
			self::assertSame( $operation, $call['operation'] );
			self::assertSame( 'credential_1', $call['credential_id'] );
			self::assertSame( 'fixture', $provider->getMetadata()->code->value );
		}
	}

	public function testWorkflowProviderExceptionBecomesASignedUnavailableResultWithoutAWorkflowOperation(): void {
		$provider                   = new RepositoryReleaseWorkflowProviderDouble();
		$provider->throwOnOperation = true;
		$url                        = $this->controller( provider: $provider )->processWorkflowRequest( $this->request( 'inspect' ) );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verifies the immediately preceding signed PRG result.
		$result = $this->controller( provider: $provider )->requestedResult();

		self::assertSame( 'workflow_remote_unavailable', $result['code'] );
		self::assertSame( 'unexpected', $result['failure_stage'] );
		self::assertSame( 'unexpected_runtime_failure', $result['diagnostic_code'] );
		self::assertSame( array(), $provider->calls );
	}

	public function testWorkflowResultFallsBackToThePackageReleaseAssetSettingsWhenTheRepositoryCannotBeResolved(): void {
		$request                           = $this->request( 'inspect' );
		$request['expected_repository_id'] = 'missing-repository';
		$url                               = $this->controller()->processWorkflowRequest( $request );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $query );

		self::assertSame( 'ran-booster-plugins', $query['page'] );
		self::assertSame( 'example/example.php', $query['package'] );
		self::assertSame( 'release_asset', $query['source_view'] );
		self::assertSame( '1', $query['ran_booster_open_advanced'] );
		self::assertArrayNotHasKey( 'repository_view', $query );
		self::assertSame( 'ran-booster-advanced-source-settings', \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_FRAGMENT ) );
	}

	public function testWorkflowResultReturnsToTheExactRepositoryReleaseView(): void {
		$url = $this->controller()->processWorkflowRequest( $this->request( 'inspect' ) );
		parse_str( (string) \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_QUERY ), $query );

		self::assertSame( 'repositories', $query['panel'] );
		self::assertSame( '101', $query['repository'] );
		self::assertSame( 'releases', $query['repository_view'] );
		self::assertArrayNotHasKey( 'source_view', $query );
		self::assertArrayNotHasKey( 'ran_booster_open_advanced', $query );
		self::assertSame( 'ran-booster-repository-release-workflows', \RAN\Admin\ReleaseManagement\wp_parse_url( $url, PHP_URL_FRAGMENT ) );
	}

	public function testMissingAggregateAndWrongAuthorityFailClosedWithoutProviderCalls(): void {
		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$url      = $this->controller( provider: $provider, registered: false )->processWorkflowRequest( $this->request( 'inspect' ) );
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
			$url               = $this->controller( provider: $provider )->processWorkflowRequest( $request );
			self::assertStringContainsString( 'workflow_invalid_request', $url );
			self::assertSame( array(), $provider->calls );
		}
	}

	public function testPartialWorkflowDependencyIsRejectedBeforeAnyWorkflowOperation(): void {
		$provider                     = new PartialRepositoryReleaseWorkflowProviderDouble();
		$request                      = $this->request( 'inspect' );
		$request['expected_provider'] = 'partial';
		$request['_wpnonce']          = 'nonce-for-ran-booster-release-workflow-inspect-' . hash( 'sha256', (string) \RAN\Admin\ReleaseManagement\wp_json_encode( array( 'partial', '101', 'plugin', 'example/example.php', 3, '' ) ) );
		$url                          = $this->controller( provider: $provider )->processWorkflowRequest( $request );

		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertStringContainsString( 'provider_unavailable', $url );
	}

	public function testRegisteredWorkflowProviderWithoutMetadataFailsClosedWithoutWarningOrOutput(): void {
		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$output   = '';
		ob_start();
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only handler promotes missing-metadata warnings to exceptions.
		set_error_handler(
			static function ( int $severity, string $message ): never {
				throw new \ErrorException( $message, 0, $severity );
			}
		);
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler, WordPress.Security.EscapeOutput.ExceptionNotEscaped
		try {
			$url = $this->controller( provider: $provider, providers: $this->registryWithoutMetadata( $provider ) )->processWorkflowRequest( $this->request( 'inspect' ) );
		} finally {
			$output = (string) ob_get_clean();
			restore_error_handler();
		}

		self::assertStringContainsString( 'workflow_invalid_request', $url );
		self::assertStringContainsString( 'provider_unavailable', $url );
		self::assertSame( array(), $provider->calls );
		self::assertSame( '', $output );
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
			$this->controller( provider: $provider )->processWorkflowRequest( $request );
			self::assertSame( array(), $provider->calls, $case[0] );
		}

		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$GLOBALS['ran_booster_release_management_test_nonce_age'] = 2;
		$this->controller( provider: $provider )->processWorkflowRequest( $this->request( 'inspect' ) );
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
		$this->controller( provider: $provider )->processWorkflowRequest( $this->request( 'setup', $key ) );

		self::assertSame( array( 'preview' ), array_column( $provider->calls, 'operation' ) );
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
			$this->controller( provider: $provider )->processWorkflowRequest( $request );

			self::assertSame( array(), $provider->calls, $case );
			self::assertSame( 0, $provider->statusReads, $case );
		}
	}

	public function testWrongCredentialAndPreviewTupleRefuseBeforeAnyWorkflowRemoteOperation(): void {
		$key                              = str_repeat( 'a', 32 );
		$provider                         = new RepositoryReleaseWorkflowProviderDouble();
		$request                          = $this->request( 'setup', $key );
		$request['booster_credential_id'] = 'not-a-saved-credential';
		$url                              = $this->controller( provider: $provider )->processWorkflowRequest( $request );

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
		$this->controller( provider: $provider )->processWorkflowRequest( $this->request( 'setup', $key ) );

		self::assertSame( array( 'preview' ), array_column( $provider->calls, 'operation' ) );
	}

	public function testAnonymousPublicInspectionPassesNullCredentialAndPermissionsAndNonceDoNotReachProvider(): void {
		$provider                         = new RepositoryReleaseWorkflowProviderDouble();
		$request                          = $this->request( 'inspect' );
		$request['booster_credential_id'] = '';
		$this->controller( provider: $provider )->processWorkflowRequest( $request );
		self::assertSame( null, $provider->calls[0]['credential_id'] );

		ReleaseManagementFixture::resetWordPress();
		$provider = new RepositoryReleaseWorkflowProviderDouble();
		$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'manage_options' );
		$this->controller( provider: $provider )->processWorkflowRequest( $this->request( 'inspect' ) );
		self::assertSame( array(), $provider->calls );

		ReleaseManagementFixture::resetWordPress();
		$request             = $this->request( 'inspect' );
		$request['_wpnonce'] = 'wrong';
		$this->controller( provider: $provider )->processWorkflowRequest( $request );
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
		$this->controller( tracking: $tracking, provider: $provider )->processWorkflowRequest( $request );

		self::assertSame( array( 'assessment_preflight', 'plugin', 'example/example.php', 3, 'prerelease', 'preflight-prerelease' ), $tracking->calls[0] );
		self::assertSame( 'setup', $provider->calls[1]['operation'] );
	}

	public function testSamePackageIdentityCanReconcileAnOccupiedWorkflowRecordAfterTheSourceRevisionAdvances(): void {
		$record   = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
			'fixture',
			'101',
			false,
			true,
			'https://fixture.example/pull/1',
			'plugin',
			'example/example.php',
			2,
			credentialChoices: array(
				array(
					'id'    => 'credential_1',
					'label' => 'Fixture credential',
				),
			)
		);
		$provider = new RepositoryReleaseWorkflowProviderDouble( status: $record );

		$this->controller( provider: $provider )->processWorkflowRequest( $this->request( 'outcome' ) );

		self::assertSame( array( 'outcome' ), array_column( $provider->calls, 'operation' ) );
	}

	public function testDifferentPackageIdentityCannotReconcileAnOccupiedWorkflowRecord(): void {
		$record   = new \RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus(
			'fixture',
			'101',
			false,
			true,
			'https://fixture.example/pull/1',
			'plugin',
			'other/other.php',
			2,
			credentialChoices: array(
				array(
					'id'    => 'credential_1',
					'label' => 'Fixture credential',
				),
			)
		);
		$provider = new RepositoryReleaseWorkflowProviderDouble( status: $record );

		$this->controller( provider: $provider )->processWorkflowRequest( $this->request( 'outcome' ) );

		self::assertSame( array(), $provider->calls );
	}

	private function controller( ?ReleaseTrackingFacadeDouble $tracking = null, ?RepositoryProvider $provider = null, bool $registered = true, ?RepositorySourceGuard $sourceGuard = null, ?ProviderRegistry $providers = null ): ReleaseWorkflowRequestController {
		$provider ??= new RepositoryReleaseWorkflowProviderDouble();
		return new ReleaseWorkflowRequestController( $tracking ?? new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status() ), new PluginRepositoryDouble( providerCode: $provider->getMetadata()->code->value ), new ThemeRepositoryDouble(), $providers ?? new ProviderRegistry( $registered ? array( $provider ) : array() ), $sourceGuard ?? $this->sourceGuard() );
	}

	private function registryWithoutMetadata( RepositoryProvider $provider ): ProviderRegistry {
		$providers = new ProviderRegistry( array( $provider ) );
		( new \ReflectionProperty( ProviderRegistry::class, 'providerMetadata' ) )->setValue( $providers, array() );
		return $providers;
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

	private function sourceGuard( string $providerCode = 'fixture' ): RepositorySourceGuard {
		$database  = new class( $providerCode ) { public string $last_error = '';
			public function __construct( private string $providerCode ) {}
			public function prepare( string $query, mixed ...$arguments ): string {
				return $query;
			} public function get_results( string $query ): array {
				return array(
					(object) array(
						'type'                   => 1,
						'package'                => 'example/example.php',
						'source'                 => 'branch',
						'provider'               => $this->providerCode,
						'provider_repository_id' => '101',
					),
				);
			} };
		$lifecycle = new class() extends Database { public function requireReady(): void {} };
		return new RepositorySourceGuard( $database, $lifecycle );
	}
}
