<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult;
use RuntimeException;
use Tests\Admin\ReleaseManagement\Support\ProspectiveReleaseFacadeDouble;
use Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use Tests\Admin\ReleaseManagement\Support\UnreadSecretCanary;

final class ReleaseManagementProspectiveAdministrationTest extends TestCase {
	#[Before]
	public function resetWordPress(): void {
		ReleaseManagementFixture::resetWordPress();
	}

	public function testListInspectAndFingerprintBoundInstallForwardExactNeutralEvidence(): void {
		$fingerprint                             = 'v1:' . str_repeat( 'b', 64 );
		$prospective                             = new ProspectiveReleaseFacadeDouble();
		$prospective->results['list_candidates'] = ProspectiveReleaseResult::success(
			'release_candidates_available',
			array(
				'channel'    => 'prerelease',
				'candidates' => array(
					array(
						'release_id'           => 42,
						'tag'                  => 'v1.2.3-rc.1',
						'version'              => '1.2.3-rc.1',
						'prerelease'           => true,
						'published_at'         => '2026-07-28T09:00:00Z',
						'expected_asset_names' => array( 'package-1.2.3-rc.1.zip' ),
						'untrusted'            => 'drop-me',
					),
				),
			)
		);
		$prospective->results['inspect']         = ProspectiveReleaseResult::success(
			'release_ready',
			array(
				'release_id'   => 42,
				'tag'          => 'v1.2.3-rc.1',
				'version'      => '1.2.3-rc.1',
				'commit'       => str_repeat( 'a', 40 ),
				'details_url'  => 'https://releases.acme.test/packages/example/v1.2.3-rc.1',
				'package_root' => 'example',
				'main_file'    => 'example.php',
				'fingerprint'  => $fingerprint,
			)
		);
		$prospective->results['install']         = ProspectiveReleaseResult::success(
			'installed',
			array(
				'identifier' => 'example/example.php',
				'version'    => '1.2.3-rc.1',
			)
		);
		$controls                                = ReleaseManagementFixture::controls( prospective: $prospective );
		$request                                 = $this->request( 'list_candidates', 'plugin', 'acme', 'prerelease' );

		$list = $controls->processProspectiveRequest( 'list_candidates', $request );
		self::assertTrue( $list['successful'] );
		self::assertArrayNotHasKey( 'untrusted', $list['data']['candidates'][0] );

		$request['release_id']  = '42';
		$request['release_tag'] = 'v1.2.3-rc.1';
		$request['_wpnonce']    = $this->nonce( 'inspect', 'plugin' );
		$inspect                = $controls->processProspectiveRequest( 'inspect', $request );
		self::assertTrue( $inspect['successful'] );
		self::assertSame( $fingerprint, $inspect['data']['fingerprint'] );
		self::assertSame( 'https://releases.acme.test/packages/example/v1.2.3-rc.1', $inspect['data']['details_url'] );

		$request['ran_booster_release_install_nonce'] = $this->nonce( 'install', 'plugin' );
		$request['release_fingerprint']               = $fingerprint;
		$install                                      = $controls->processProspectiveRequest( 'install', $request );
		self::assertTrue( $install['successful'] );
		self::assertSame( 'example/example.php', $install['identifier'] );

		self::assertSame( 'list_candidates', $prospective->calls[0][0] );
		self::assertSame(
			array(
				'provider'      => 'acme',
				'repository'    => 'workspace/example',
				'credential_id' => 'profile_1',
			),
			$prospective->calls[0][2]
		);
		self::assertSame( array( 'inspect', 'plugin', $prospective->calls[0][2], 42, 'v1.2.3-rc.1', 'prerelease', $this->nonce( 'inspect', 'plugin' ) ), $prospective->calls[1] );
		self::assertSame( array( 'install', 'plugin', $prospective->calls[0][2], 42, 'v1.2.3-rc.1', $fingerprint, 'prerelease', $this->nonce( 'install', 'plugin' ) ), $prospective->calls[2] );
	}

	public function testProspectivePaneCarriesTypeAndInstallScriptSuppressesBranchDispatcher(): void {
		$controls = ReleaseManagementFixture::controls();
		ob_start();
		$controls->renderAdvancedSourceSection(
			'create',
			'plugin',
			'release_asset',
			null,
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins-create'
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="expected_type" value="plugin"', $html );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Direct local interaction conformance read.
		$script = file_get_contents( dirname( __DIR__, 3 ) . '/assets/ran-booster-release-management.js' );
		self::assertIsString( $script );
		self::assertStringContainsString( "form.elements.namedItem('ran_booster[action]')", $script );
		self::assertStringContainsString( 'branchAction.disabled = true', $script );
	}

	public function testInvalidAuthorityDoesNotTraverseCredentialBearingRepositoryFields(): void {
		$prospective                               = new ProspectiveReleaseFacadeDouble();
		$controls                                  = ReleaseManagementFixture::controls( prospective: $prospective );
		$request                                   = $this->request( 'list_candidates' );
		$request['_wpnonce']                       = 'invalid';
		$request['ran_booster']['credential_id']   = new UnreadSecretCanary();
		$request['ran_booster']['repository']      = new UnreadSecretCanary();
		$request['ran_booster']['private_payload'] = new UnreadSecretCanary();

		$outcome = $controls->processProspectiveRequest( 'list_candidates', $request );

		self::assertFalse( $outcome['successful'] );
		self::assertSame( 'invalid_request', $outcome['code'] );
		self::assertSame( array(), $prospective->calls );
		self::assertStringNotContainsString(
			'secret',
			(string) \RAN\Admin\ReleaseManagement\wp_json_encode( $outcome )
		);
	}

	public function testDeniedCapabilityAndUnavailableNonceAuthorityStopBeforeProviderWork(): void {
		foreach ( array( 'denied', 'empty', 'throw' ) as $mode ) {
			$prospective = new ProspectiveReleaseFacadeDouble();
			if ( 'denied' === $mode ) {
				$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'install_plugins' );
			} else {
				$prospective->nonceFailure = $mode;
			}
			$controls = ReleaseManagementFixture::controls( prospective: $prospective );

			$outcome = $controls->processProspectiveRequest( 'list_candidates', $this->request( 'list_candidates' ) );

			self::assertFalse( $outcome['successful'], $mode );
			self::assertSame( 'denied' === $mode ? 'forbidden' : 'service_unavailable', $outcome['code'], $mode );
			self::assertSame( array(), $prospective->calls, $mode );
			unset( $GLOBALS['ran_booster_release_management_test_denied_capabilities'] );
		}
	}

	public function testInvalidFingerprintAndChannelStopBeforeProviderWork(): void {
		$prospective = new ProspectiveReleaseFacadeDouble();
		$controls    = ReleaseManagementFixture::controls( prospective: $prospective );
		$request     = array_merge(
			$this->request( 'install' ),
			array(
				'release_id'                        => '42',
				'release_tag'                       => 'v1.2.3',
				'release_fingerprint'               => 'invalid',
				'ran_booster_release_install_nonce' => $this->nonce( 'install', 'plugin' ),
			)
		);

		$fingerprintOutcome             = $controls->processProspectiveRequest( 'install', $request );
		$request['release_fingerprint'] = 'v1:' . str_repeat( 'a', 64 );
		$request['release_channel']     = 'nightly';
		$channelOutcome                 = $controls->processProspectiveRequest( 'install', $request );

		self::assertSame( 'invalid_request', $fingerprintOutcome['code'] );
		self::assertSame( 'invalid_request', $channelOutcome['code'] );
		self::assertSame( array(), $prospective->calls );
	}

	public function testCompleteProjectionExcludesPartialProviderAndUnsupportedOutcomeStaysBounded(): void {
		$prospective                             = new ProspectiveReleaseFacadeDouble();
		$prospective->supportedProviders         = array( 'gh', 'acme' );
		$prospective->results['list_candidates'] = ProspectiveReleaseResult::failure( 'unsupported_provider' );
		$controls                                = ReleaseManagementFixture::controls( prospective: $prospective );
		$_GET['page']                            = 'ran-booster-plugins-create'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen fixture.

		$controls->enqueueProspectiveAssets();
		$projection = $GLOBALS['ran_booster_release_management_test_localized']['ran-booster-release-management']['ranBoosterReleaseManagement'] ?? null;
		self::assertIsArray( $projection );
		self::assertSame( array( 'gh', 'acme' ), $projection['supportedProviders'] );
		self::assertNotContains( 'partial', $projection['supportedProviders'] );

		$outcome = $controls->processProspectiveRequest(
			'list_candidates',
			$this->request( 'list_candidates', 'plugin', 'partial' )
		);
		self::assertFalse( $outcome['successful'] );
		self::assertSame( 'unsupported_provider', $outcome['code'] );
		self::assertSame( array(), $outcome['data'] );
	}

	public function testListReaderCallableRunsOnceAndRejectsThrownOrUnknownResults(): void {
		$prospective = new ProspectiveReleaseFacadeDouble();
		$calls       = 0;
		$controls    = ReleaseManagementFixture::controls(
			prospective: $prospective,
			readCandidates: static function ( string $type, array $repository, string $channel ) use ( &$calls ): ProspectiveReleaseResult {
				++$calls;
				self::assertSame( 'plugin', $type );
				self::assertSame( 'gh', $repository['provider'] );
				self::assertSame( 'stable', $channel );

				return ProspectiveReleaseResult::failure( 'unexpected_reader_code' );
			}
		);

		$outcome = $controls->processProspectiveRequest( 'list_candidates', $this->request( 'list_candidates' ) );

		self::assertSame( 1, $calls );
		self::assertSame( 'operation_failed', $outcome['code'] );
		self::assertSame( array(), $prospective->calls );

		$controls = ReleaseManagementFixture::controls(
			readCandidates: static function ( string $type, array $repository, string $channel ): ProspectiveReleaseResult {
				unset( $type, $repository, $channel );
				throw new RuntimeException( 'reader-failure' );
			}
		);
		$outcome  = $controls->processProspectiveRequest( 'list_candidates', $this->request( 'list_candidates' ) );

		self::assertSame( 'unable_to_check', $outcome['code'] );
		self::assertFalse( $outcome['successful'] );
	}

	#[DataProvider( 'installTypes' )]
	public function testFingerprintBoundInstallHasPluginThemeParity( string $type, string $identifier ): void {
		$fingerprint                     = 'v1:' . str_repeat( 'c', 64 );
		$prospective                     = new ProspectiveReleaseFacadeDouble();
		$prospective->results['install'] = ProspectiveReleaseResult::success(
			'installed',
			array(
				'identifier' => $identifier,
				'version'    => '2.0.0',
			)
		);
		$controls                        = ReleaseManagementFixture::controls( prospective: $prospective );
		$request                         = array_merge(
			$this->request( 'install', $type ),
			array(
				'release_id'                        => '84',
				'release_tag'                       => 'v2.0.0',
				'release_fingerprint'               => $fingerprint,
				'ran_booster_release_install_nonce' => $this->nonce( 'install', $type ),
			)
		);

		$outcome = $controls->processProspectiveRequest( 'install', $request );

		self::assertTrue( $outcome['successful'] );
		self::assertSame( $identifier, $outcome['identifier'] );
		self::assertSame( array( 'install', $type, $request['ran_booster'], 84, 'v2.0.0', $fingerprint, 'stable', $this->nonce( 'install', $type ) ), $prospective->calls[0] );
	}

	/** @return iterable<string, array{string,string}> */
	public static function installTypes(): iterable {
		yield 'plugin' => array( 'plugin', 'example/example.php' );
		yield 'theme' => array( 'theme', 'example-theme' );
	}

	public function testAjaxHandlerEmitsOnlyTheBoundedProductionEnvelope(): void {
		$prospective                             = new ProspectiveReleaseFacadeDouble();
		$prospective->results['list_candidates'] = ProspectiveReleaseResult::success(
			'release_candidates_available',
			array(
				'candidates' => array(
					array(
						'release_id'           => 42,
						'tag'                  => 'v1.2.3',
						'version'              => '1.2.3',
						'prerelease'           => false,
						'published_at'         => '2026-07-28T09:00:00Z',
						'expected_asset_names' => array( 'package.zip' ),
					),
				),
			)
		);
		$controls                                = ReleaseManagementFixture::controls( prospective: $prospective );
		$_POST                                   = $this->request( 'list_candidates' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Production callback fixture.

		try {
			$controls->handleProspectiveListCandidates();
			self::fail( 'AJAX transport must terminate.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'json-response', $error->getMessage() );
		}
		$response = $GLOBALS['ran_booster_release_management_test_json']['response'] ?? null;
		self::assertIsArray( $response );
		self::assertSame( array( 'successful', 'code', 'data' ), array_keys( $response ) );
		self::assertTrue( $response['successful'] );
	}

	/** @return array<string, mixed> */
	private function request(
		string $operation,
		string $type = 'plugin',
		string $provider = 'gh',
		string $channel = 'stable'
	): array {
		return array(
			'expected_type'   => $type,
			'_wpnonce'        => $this->nonce( $operation, $type ),
			'release_channel' => $channel,
			'ran_booster'     => array(
				'provider'      => $provider,
				'repository'    => 'workspace/example',
				'credential_id' => 'profile_1',
			),
		);
	}

	private function nonce( string $operation, string $type ): string {
		return 'nonce-for-prospective-release-' . $operation . '-' . $type;
	}
}
